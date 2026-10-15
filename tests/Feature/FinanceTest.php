<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\FinanceCategory;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\FinanceService;
use App\Services\PaymentService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    private Account $account;

    private PaymentService $payments;

    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->seed();
        $this->actor = User::create(['name' => 'Admin Keuangan', 'email' => 'finance-test@example.test', 'password' => 'PasswordAman123!', 'role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        $this->actingAs($this->actor);
        $this->customer = $this->customer('PAM10-990001', Rt::where('code', '01')->first());
        $this->account = Account::first();
        $this->payments = app(PaymentService::class);
        $this->finance = app(FinanceService::class);
        $this->period('2026-10');
    }

    public function test_partial_payment_allocates_across_oldest_periods_and_reversal_preserves_history(): void
    {
        $old = $this->invoice('2026-08', 45000);
        $new = $this->invoice('2026-09', 75000);
        $payment = $this->payments->pay($this->actor, $this->paymentData(100000));
        $this->assertSame('paid', $old->fresh()->status);
        $this->assertSame(45000, $old->fresh()->paid_amount);
        $this->assertSame('partial', $new->fresh()->status);
        $this->assertSame(55000, $new->fresh()->paid_amount);
        $this->assertSame(100000, $this->finance->balance($this->account));
        $this->assertDatabaseCount('payment_allocations', 2);

        $this->payments->reverse($this->actor, $payment, 'Salah memilih pelanggan saat penerimaan');
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('issued', $old->fresh()->status);
        $this->assertSame('issued', $new->fresh()->status);
        $this->assertSame(0, $old->fresh()->paid_amount + $new->fresh()->paid_amount);
        $this->assertSame(0, $this->finance->balance($this->account));
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseHas('cash_entries', ['payment_id' => $payment->id, 'direction' => 'out', 'amount' => 100000, 'status' => 'posted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reversed', 'user_id' => $this->actor->id, 'reason' => 'Salah memilih pelanggan saat penerimaan']);
        $this->payments->reverse($this->actor, $payment, 'Klik ulang pembatalan pembayaran');
        $this->assertDatabaseCount('cash_entries', 2);
    }

    public function test_idempotency_returns_original_receipt_without_reallocating_and_rejects_changed_payload(): void
    {
        $invoice = $this->invoice('2026-09', 45000);
        $data = $this->paymentData(20000);
        $first = $this->payments->pay($this->actor, $data);
        $retry = $this->payments->pay($this->actor, $data);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(20000, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('cash_entries', 1);
        try {
            $this->payments->pay($this->actor, array_replace($data, ['amount' => 21000]));
            $this->fail('A reused key with another amount must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        $this->assertSame(20000, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_overpayment_rolls_back_entire_financial_transaction(): void
    {
        $invoice = $this->invoice('2026-09', 45000);
        try {
            $this->payments->pay($this->actor, $this->paymentData(45001));
            $this->fail('Overpayment must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame(0, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_manual_allocation_can_pay_a_specific_invoice_and_rejects_mismatched_totals(): void
    {
        $old = $this->invoice('2026-08', 45000);
        $new = $this->invoice('2026-09', 75000);
        $this->payments->pay($this->actor, $this->paymentData(15000) + ['allocation_mode' => 'manual', 'allocations' => [$new->id => 15000]]);
        $this->assertSame(0, $old->fresh()->paid_amount);
        $this->assertSame(15000, $new->fresh()->paid_amount);
        $this->expectException(ValidationException::class);
        $this->payments->pay($this->actor, $this->paymentData(10000) + ['allocation_mode' => 'manual', 'allocations' => [$old->id => 9000]]);
    }

    public function test_closed_invoice_receivables_can_be_collected_in_an_open_cash_month(): void
    {
        $invoice = $this->invoice('2026-09', 45000);
        $invoice->period->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        $payment = $this->payments->pay($this->actor, $this->paymentData(45000));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->payments->reverse($this->actor, $payment, 'Pembayaran masuk ke pelanggan yang salah');
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertNotNull($invoice->period->fresh()->locked_at);
    }

    public function test_a_closed_cash_month_rejects_new_payment_without_mutation(): void
    {
        $invoice = $this->invoice('2026-09', 45000);
        $this->period('2026-10')->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        try {
            $this->payments->pay($this->actor, $this->paymentData(45000));
            $this->fail('The cash month is closed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paid_on', $exception->errors());
        }
        $this->assertSame(0, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_reversal_uses_current_date_and_keeps_original_closed_month_receipt(): void
    {
        $invoice = $this->invoice('2026-08', 45000);
        $this->period('2026-09');
        $payment = $this->payments->pay($this->actor, array_replace($this->paymentData(45000), ['paid_on' => '2026-09-15']));
        $this->period('2026-09')->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        $this->payments->reverse($this->actor, $payment, 'Bukti transfer dinyatakan tidak sah');
        $this->assertSame('2026-09-15', $payment->fresh()->paid_on->format('Y-m-d'));
        $this->assertTrue(CashEntry::where('payment_id', $payment->id)->where('direction', 'out')->whereDate('entry_date', '2026-10-15')->exists());
        $this->assertSame(0, $invoice->fresh()->paid_amount);
    }

    public function test_closed_current_cash_month_blocks_reversal_and_preserves_allocations(): void
    {
        $invoice = $this->invoice('2026-09', 45000);
        $payment = $this->payments->pay($this->actor, $this->paymentData(45000));
        $this->period('2026-10')->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        try {
            $this->payments->reverse($this->actor, $payment, 'Pembayaran harus diperiksa kembali');
            $this->fail('Reversal cannot post to a closed cash month.');
        } catch (ValidationException) {
            $this->assertSame('posted', $payment->fresh()->status);
        }
        $this->assertSame(45000, $invoice->fresh()->paid_amount);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_operator_with_payment_permission_cannot_collect_another_rt(): void
    {
        $role = Role::where('slug', 'operator')->first();
        $role->update(['permissions' => ['payments.view', 'payments.create', 'payments.print', 'payments.cancel']]);
        $operator = User::create(['name' => 'Operator RT02', 'email' => 'operator-finance@example.test', 'password' => 'PasswordAman123!', 'role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach(Rt::where('code', '02')->value('id'));
        $this->invoice('2026-09', 45000);
        $this->actingAs($operator)->get(route('payments.index', ['customer_id' => $this->customer->id]))->assertNotFound();
        $this->actingAs($operator)->post(route('payments.store'), $this->paymentData(45000))->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_operator_without_special_permission_cannot_record_payment(): void
    {
        $operator = User::create(['name' => 'Operator', 'email' => 'no-pay@example.test', 'password' => 'PasswordAman123!', 'role_id' => Role::where('slug', 'operator')->value('id'), 'active' => true]);
        $operator->rts()->attach($this->customer->rt_id);
        $this->invoice('2026-09', 45000);
        $this->actingAs($operator)->post(route('payments.store'), $this->paymentData(10000))->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_receipts_and_private_payment_proofs_are_scoped_to_operator_territory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('payment-proofs/private-proof.pdf', '%PDF-test-private-proof');
        $this->invoice('2026-09', 45000);
        $payment = $this->payments->pay($this->actor, $this->paymentData(10000) + ['proof_path' => 'payment-proofs/private-proof.pdf']);
        $this->get(route('payments.proof', $payment))->assertOk();
        $role = Role::where('slug', 'operator')->first();
        $role->update(['permissions' => ['payments.view', 'payments.print', 'payments.cancel']]);
        $operator = User::create(['name' => 'Operator luar wilayah', 'email' => 'private-finance@example.test', 'password' => 'PasswordAman123!', 'role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach(Rt::where('code', '02')->value('id'));
        $this->actingAs($operator)->get(route('payments.receipt', $payment))->assertForbidden();
        $this->get(route('payments.proof', $payment))->assertForbidden();
        $this->post(route('payments.cancel', $payment), ['reason' => 'Percobaan pembatalan di luar wilayah'])->assertForbidden();
        $this->assertSame('posted', $payment->fresh()->status);
        $this->assertDatabaseCount('cash_entries', 1);
    }

    public function test_client_cannot_point_a_finance_attachment_at_an_arbitrary_private_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private-document.txt', 'Berkas lain yang tidak berhak dijadikan bukti');
        $response = $this->post(route('finance.store'), ['kind' => 'income', 'account_id' => $this->account->id, 'category_id' => FinanceCategory::where('type', 'income')->value('id'), 'entry_date' => '2026-10-15', 'amount' => 10000, 'description' => 'Pemasukan dengan parameter path palsu', 'attachment_path' => 'private-document.txt']);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $entry = CashEntry::firstOrFail();
        $this->assertNull($entry->attachment_path);
        $this->get(route('finance.attachment', $entry))->assertNotFound();
    }

    public function test_transfer_and_its_reversal_preserve_total_cash_without_creating_income(): void
    {
        $this->account->update(['opening_balance' => 100000]);
        $target = $this->finance->createAccount($this->actor, ['name' => 'Bank RW10', 'type' => 'bank', 'opening_balance' => 0]);
        $transfer = $this->finance->record($this->actor, ['kind' => 'transfer', 'account_id' => $this->account->id, 'target_account_id' => $target->id, 'entry_date' => '2026-10-15', 'amount' => 30000, 'description' => 'Setor kas ke rekening bank']);
        $this->assertSame(70000, $this->finance->balance($this->account));
        $this->assertSame(30000, $this->finance->balance($target));
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertSame(0, CashEntry::whereNotNull('category_id')->count());
        $this->finance->reverse($this->actor, $transfer, 'Penyetoran bank dibatalkan oleh petugas');
        $this->assertSame(100000, $this->finance->balance($this->account));
        $this->assertSame(0, $this->finance->balance($target));
        $this->assertDatabaseCount('cash_entries', 4);
    }

    public function test_expense_approval_does_not_change_cash_until_approved_and_reversal_is_additive(): void
    {
        SettingService::set('expense_approval_required', true);
        $this->account->update(['opening_balance' => 100000]);
        $entry = $this->finance->record($this->actor, ['kind' => 'expense', 'account_id' => $this->account->id, 'category_id' => FinanceCategory::where('type', 'expense')->value('id'), 'entry_date' => '2026-10-15', 'amount' => 25000, 'description' => 'Bayar listrik pompa air']);
        $this->assertSame('pending', $entry->status);
        $this->assertSame(100000, $this->finance->balance($this->account));
        $this->finance->approve($this->actor, $entry);
        $this->assertSame(75000, $this->finance->balance($this->account));
        $this->finance->reverse($this->actor, $entry, 'Pengeluaran dibatalkan karena pembayaran ganda');
        $this->assertSame(100000, $this->finance->balance($this->account));
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertDatabaseCount('cash_entries', 2);
        $this->assertDatabaseHas('cash_entries', ['reversal_of' => $entry->id, 'direction' => 'in', 'amount' => 25000]);
    }

    public function test_reconciliation_records_real_book_balance_and_requires_explanation_for_difference(): void
    {
        $this->account->update(['opening_balance' => 50000]);
        $record = $this->finance->reconcile($this->actor, ['account_id' => $this->account->id, 'reconciled_on' => '2026-10-15', 'statement_balance' => 49000, 'notes' => 'Selisih sedang ditelusuri pada bukti kas']);
        $this->assertSame(50000, $record->book_balance);
        $this->assertSame(-1000, $record->difference);
        $this->assertSame(50000, $this->finance->balance($this->account));
        $this->expectException(ValidationException::class);
        $this->finance->reconcile($this->actor, ['account_id' => $this->account->id, 'reconciled_on' => '2026-10-15', 'statement_balance' => 48000]);
    }

    public function test_used_account_opening_balance_cannot_be_rewritten(): void
    {
        $this->invoice('2026-09', 45000);
        $this->payments->pay($this->actor, $this->paymentData(10000));
        $this->expectException(ValidationException::class);
        $this->finance->updateAccount($this->actor, $this->account, ['name' => $this->account->name, 'type' => 'cash', 'opening_balance' => 90000, 'active' => true, 'reason' => 'Coba mengubah saldo awal setelah transaksi']);
    }

    public function test_dashboard_preserves_receipt_and_expense_history_when_reversals_post_next_month(): void
    {
        $september = $this->period('2026-09');
        $this->invoice('2026-08', 45000);
        $payment = $this->payments->pay($this->actor, array_replace($this->paymentData(45000), ['paid_on' => '2026-09-15']));
        $expense = $this->finance->record($this->actor, ['kind' => 'expense', 'account_id' => $this->account->id, 'category_id' => FinanceCategory::where('type', 'expense')->value('id'), 'entry_date' => '2026-09-20', 'amount' => 10000, 'description' => 'Pengeluaran pompa bulan September']);
        $september->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        $this->payments->reverse($this->actor, $payment, 'Bukti pembayaran perlu dibatalkan pada Oktober');
        $this->finance->reverse($this->actor, $expense, 'Pengeluaran September dikembalikan pada Oktober');
        $dashboard = app(DashboardService::class);
        $old = $dashboard->build($this->actor, $september->fresh(), null);
        $current = $dashboard->build($this->actor, $this->period('2026-10'), null);
        $this->assertSame(45000, $old['stats']['receipts']);
        $this->assertSame(10000, $old['stats']['expense']);
        $this->assertSame(35000, $old['stats']['balance']);
        $this->assertSame(-45000, $current['stats']['receipts']);
        $this->assertSame(-10000, $current['stats']['expense']);
        $this->assertSame(0, $current['stats']['balance']);
        $this->assertSame([45000, -45000], array_slice($current['chart']['income'], -2));
        $this->assertSame([10000, -10000], array_slice($current['chart']['expense'], -2));
    }

    public function test_dashboard_rt_filter_attributes_receipts_by_invoice_snapshot_and_hides_shared_cash(): void
    {
        $old = $this->invoice('2026-08', 45000);
        $new = $this->invoice('2026-09', 75000);
        $rt2 = Rt::where('code', '02')->first();
        $new->update(['customer_snapshot' => array_replace($new->customer_snapshot, ['rt_id' => $rt2->id])]);
        $this->payments->pay($this->actor, $this->paymentData(100000));
        $this->finance->record($this->actor, ['kind' => 'expense', 'account_id' => $this->account->id, 'category_id' => FinanceCategory::where('type', 'expense')->value('id'), 'entry_date' => '2026-10-15', 'amount' => 10000, 'description' => 'Biaya bersama seluruh lingkungan RW10']);
        $dashboard = app(DashboardService::class);
        $rt1 = $dashboard->build($this->actor, $this->period('2026-10'), $this->customer->rt_id);
        $rt2Result = $dashboard->build($this->actor, $this->period('2026-10'), $rt2->id);
        $this->assertSame(45000, $rt1['stats']['receipts']);
        $this->assertSame(55000, $rt2Result['stats']['receipts']);
        $this->assertNull($rt1['stats']['balance']);
        $this->assertSame(0, $rt1['stats']['expense']);
        $this->assertFalse($rt1['showGlobalFinance']);
        $this->assertFalse($rt1['chart']['showExpense']);
        $this->assertSame([0, 0, 0, 0, 0, 0], $rt1['chart']['expense']);
        $this->assertSame(45000, $old->fresh()->paid_amount);
    }

    public function test_operator_dashboard_cannot_reveal_global_account_balance_with_special_finance_permission(): void
    {
        $this->invoice('2026-09', 45000);
        $this->payments->pay($this->actor, $this->paymentData(10000));
        $this->account->update(['opening_balance' => 999999]);
        $role = Role::where('slug', 'operator')->first();
        $role->update(['permissions' => ['dashboard.view', 'payments.view', 'finance.view']]);
        $operator = User::create(['name' => 'Operator berizin khusus', 'email' => 'dashboard-operator@example.test', 'password' => 'PasswordAman123!', 'role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach($this->customer->rt_id);
        $dashboard = app(DashboardService::class)->build($operator, $this->period('2026-10'), null);
        $this->assertSame(10000, $dashboard['stats']['receipts']);
        $this->assertNull($dashboard['stats']['balance']);
        $this->assertSame(0, $dashboard['stats']['expense']);
        $this->assertFalse($dashboard['showGlobalFinance']);
        $this->assertFalse($dashboard['chart']['showExpense']);
    }

    public function test_finance_pages_and_receipt_pdf_render_with_database_transactions(): void
    {
        $this->invoice('2026-09', 45000);
        $payment = $this->payments->pay($this->actor, $this->paymentData(15000));
        $this->get(route('payments.index', ['customer_id' => $this->customer->id]))->assertOk()->assertSee('PAM10-990001');
        $this->get(route('payments.receipt', $payment))->assertOk()->assertSee($payment->number);
        $this->get(route('payments.receipt', ['payment' => $payment->id, 'pdf' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('finance.index'))->assertOk()->assertSee('Pembayaran rekening air');
    }

    public function test_new_nonzero_opening_balance_cannot_change_closed_history(): void
    {
        $this->period('2026-09')->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        $before = Account::count();
        try {
            $this->finance->createAccount($this->actor, ['name' => 'Bank Baru', 'type' => 'bank', 'opening_balance' => 100000]);
            $this->fail('Saldo awal baru seharusnya ditolak setelah tutup buku.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('opening_balance', $exception->errors());
        }
        $this->assertDatabaseCount('accounts', $before);
        $account = $this->finance->createAccount($this->actor, ['name' => 'Bank Baru', 'type' => 'bank', 'opening_balance' => 0]);
        $this->assertSame(0, (int) $account->opening_balance);
    }

    public function test_unused_account_opening_balance_is_frozen_after_period_close(): void
    {
        $this->period('2026-09')->update(['locked_at' => now(), 'locked_by' => $this->actor->id]);
        $data = ['name' => $this->account->name, 'type' => $this->account->type, 'number' => null, 'active' => 1, 'opening_balance' => 50000, 'reason' => 'Mengubah saldo awal setelah tutup buku'];
        try {
            $this->finance->updateAccount($this->actor, $this->account, $data);
            $this->fail('Perubahan saldo awal seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('opening_balance', $exception->errors());
        }
        $this->assertSame(0, (int) $this->account->fresh()->opening_balance);
        $data['opening_balance'] = 0;
        $data['name'] = 'Kas PAM diperbarui';
        $this->assertSame('Kas PAM diperbarui', $this->finance->updateAccount($this->actor, $this->account, $data)->name);
    }

    private function paymentData(int $amount): array
    {
        return ['customer_id' => $this->customer->id, 'account_id' => $this->account->id, 'payment_method_id' => PaymentMethod::first()->id, 'amount' => $amount, 'paid_on' => '2026-10-15', 'idempotency_key' => (string) Str::uuid()];
    }

    private function period(string $name): Period
    {
        $date = Carbon::createFromFormat('Y-m-d', $name.'-01');

        return Period::firstOrCreate(['name' => $name], ['starts_on' => $date->format('Y-m-d'), 'ends_on' => $date->copy()->endOfMonth()->format('Y-m-d'), 'due_date' => $date->copy()->day(20)->format('Y-m-d')]);
    }

    private function customer(string $number, Rt $rt): Customer
    {
        $customer = Customer::create(['number' => $number, 'name' => 'Pelanggan '.$number, 'address' => 'Jalan Air RW.10', 'rt_id' => $rt->id, 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => '2026-01-01']);
        Meter::create(['customer_id' => $customer->id, 'serial' => 'MTR-'.$number, 'initial_reading' => '0.000', 'installed_on' => '2026-01-01', 'active' => true]);

        return $customer;
    }

    private function invoice(string $month, int $amount, ?Customer $customer = null): Invoice
    {
        $customer ??= $this->customer;
        $period = $this->period($month);
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $customer->meters()->first()->id, 'period_id' => $period->id, 'user_id' => $this->actor->id, 'previous_reading' => '0.000', 'current_reading' => '20.000', 'usage' => '20.000', 'status' => 'locked', 'customer_snapshot' => ['number' => $customer->number, 'name' => $customer->name, 'address' => $customer->address, 'rt_id' => $customer->rt_id]]);

        return Invoice::create(['number' => 'TEST-'.$customer->id.'-'.$month, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::where('category_id', $customer->category_id)->value('id'), 'customer_snapshot' => $reading->customer_snapshot, 'tariff_snapshot' => [], 'lines' => [], 'usage' => '20.000', 'water_amount' => $amount, 'total' => $amount, 'paid_amount' => 0, 'status' => 'issued', 'due_date' => $period->due_date, 'issued_at' => now(), 'user_id' => $this->actor->id]);
    }
}
