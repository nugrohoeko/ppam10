<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Services\BillingService;
use App\Services\DashboardService;
use App\Services\MeterService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeletedCustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Period $period;

    private Customer $customer;

    private Invoice $invoice;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->seed();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        $this->actingAs($this->admin);
        SettingService::set('reading_verification', false);
        $this->period = Period::create(['name' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'due_date' => '2026-10-20']);
        [$this->customer, $this->invoice, $this->payment] = $this->paidCustomer('PAM10-980001', Rt::where('code', '01')->first());
    }

    public function test_archiving_keeps_historical_report_totals_and_dashboard_finances(): void
    {
        $reports = app(ReportService::class);
        $dashboard = app(DashboardService::class);
        $filters = ['period_id' => $this->period->id, 'rt_ids' => [$this->customer->rt_id], 'q' => $this->customer->number];
        $before = [];
        foreach (['readings', 'invoices', 'paid', 'receipts', 'cashbook', 'summary'] as $type) {
            $before[$type] = $reports->build($this->admin, $filters + ['type' => $type]);
        }
        $beforeDashboard = $dashboard->build($this->admin, $this->period, $this->customer->rt_id);
        $beforeGlobal = $dashboard->build($this->admin, $this->period, null);

        $this->customer->delete();

        foreach ($before as $type => $expected) {
            $actual = $reports->build($this->admin, $filters + ['type' => $type]);
            $this->assertSame($expected['rows'], $actual['rows'], $type.' rows must survive customer deletion');
            $this->assertSame($expected['totals'], $actual['totals'], $type.' totals must survive customer deletion');
        }
        $after = $dashboard->build($this->admin, $this->period, $this->customer->rt_id);
        $this->assertSame($beforeDashboard['stats']['active'] - 1, $after['stats']['active']);
        foreach (['done', 'usage', 'total', 'receipts', 'arrears', 'paid', 'partial', 'unpaid'] as $key) {
            $this->assertSame($beforeDashboard['stats'][$key], $after['stats'][$key], $key);
        }
        $this->assertSame($beforeDashboard['chart'], $after['chart']);
        $afterGlobal = $dashboard->build($this->admin, $this->period, null);
        $this->assertSame($beforeGlobal['stats']['receipts'], $afterGlobal['stats']['receipts']);
        $this->assertSame($beforeGlobal['stats']['balance'], $afterGlobal['stats']['balance']);
        $this->assertCount(0, $reports->build($this->admin, $filters + ['type' => 'customers'])['rows']);
        $this->assertTrue($this->invoice->fresh()->customer->trashed());
        $this->assertTrue($this->invoice->reading->fresh()->customer->trashed());
        $this->assertTrue($this->invoice->reading->meter->customer->trashed());
        $this->assertTrue($this->payment->fresh()->customer->trashed());
    }

    public function test_archived_receipts_invoice_prints_attachments_and_reversal_remain_available(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('payment-proofs/archived.pdf', 'payment evidence');
        Storage::disk('local')->put('readings/archived.jpg', 'meter evidence');
        Storage::disk('local')->put('customers/archived.jpg', 'customer evidence');
        $this->payment->update(['proof_path' => 'payment-proofs/archived.pdf']);
        $this->invoice->reading->update(['photo_path' => 'readings/archived.jpg']);
        $this->customer->update(['photo_path' => 'customers/archived.jpg']);
        $this->customer->delete();

        $this->get(route('invoices.index', ['q' => $this->customer->number]))->assertOk()->assertSee($this->invoice->number);
        $this->get(route('invoices.print', ['ids' => [$this->invoice->id]]))->assertOk()->assertSee($this->invoice->number)->assertSee($this->customer->name);
        $this->get(route('payments.index', ['customer_id' => $this->customer->id]))
            ->assertOk()->assertSee($this->payment->number)
            ->assertViewHas('customer', null)
            ->assertViewHas('customers', fn ($customers) => ! $customers->contains('id', $this->customer->id));
        $this->get(route('payments.receipt', $this->payment))->assertOk()->assertSee($this->customer->number);
        $this->get(route('payments.receipt', ['payment' => $this->payment->id, 'pdf' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('payments.proof', $this->payment))->assertOk();
        $this->get(route('private.show', ['type' => 'reading', 'id' => $this->invoice->reading_id]))->assertOk();
        $this->get(route('private.show', ['type' => 'customer', 'id' => $this->customer->id]))->assertOk();

        $this->post(route('payments.cancel', $this->payment), ['reason' => 'Koreksi penerimaan pelanggan yang telah diarsipkan'])->assertRedirect();
        $this->assertSame('reversed', $this->payment->fresh()->status);
        $this->assertSame(48000, $this->invoice->fresh()->remaining);
        $this->assertDatabaseHas('cash_entries', ['payment_id' => $this->payment->id, 'direction' => 'out', 'amount' => 48000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reversed', 'subject_id' => $this->payment->id, 'user_id' => $this->admin->id]);
        $receiptReport = app(ReportService::class)->build($this->admin, ['type' => 'receipts', 'rt_ids' => [$this->customer->rt_id]]);
        $this->assertCount(2, $receiptReport['rows']);
        $this->assertSame(0, $receiptReport['totals']['amount']);
        $this->assertTrue(Customer::withTrashed()->findOrFail($this->customer->id)->trashed());

        $this->get(route('payments.index', ['customer_id' => $this->customer->id]))
            ->assertOk()->assertViewHas('customer', fn ($customer) => $customer->id === $this->customer->id && $customer->trashed())
            ->assertViewHas('invoices', fn ($invoices) => $invoices->count() === 1 && $invoices->first()->id === $this->invoice->id);
        $this->post(route('payments.store'), ['customer_id' => $this->customer->id, 'account_id' => Account::first()->id, 'payment_method_id' => PaymentMethod::first()->id, 'amount' => 48000, 'paid_on' => '2026-10-15', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(48000, $this->invoice->fresh()->paid_amount);
        $this->assertTrue(Customer::withTrashed()->findOrFail($this->customer->id)->trashed());
    }

    public function test_archived_history_still_obeys_operator_territory_for_reports_and_direct_requests(): void
    {
        [$outside, $outsideInvoice, $outsidePayment] = $this->paidCustomer('PAM10-980002', Rt::where('code', '02')->first());
        $this->customer->delete();
        $outside->delete();
        $role = Role::where('slug', 'operator')->first();
        $role->update(['permissions' => ['dashboard.view', 'customers.view', 'readings.view', 'invoices.view', 'invoices.print', 'payments.view', 'payments.print', 'payments.cancel', 'finance.view', 'reports.view']]);
        $operator = User::factory()->create(['role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach($this->customer->rt_id);
        $this->actingAs($operator);

        foreach (['invoices', 'readings', 'receipts'] as $type) {
            $report = app(ReportService::class)->build($operator, ['type' => $type]);
            $this->assertCount(1, $report['rows'], $type);
            $this->assertSame($this->customer->number, $report['rows'][0][$type === 'receipts' ? 'customer_number' : 'number']);
            $outsideReport = app(ReportService::class)->build($operator, ['type' => $type, 'rt_ids' => [$outside->rt_id]]);
            $this->assertCount(0, $outsideReport['rows'], $type);
        }
        $dashboard = app(DashboardService::class)->build($operator, $this->period, null);
        $this->assertSame(0, $dashboard['stats']['active']);
        $this->assertSame(1, $dashboard['stats']['done']);
        $this->assertSame(48000, $dashboard['stats']['total']);
        $this->assertSame(48000, $dashboard['stats']['receipts']);
        $this->assertNull($dashboard['stats']['balance']);
        $this->get(route('payments.index'))->assertOk()->assertSee($this->payment->number)->assertDontSee($outsidePayment->number);
        $this->get(route('finance.index'))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 1 && $entries->first()->payment_id === $this->payment->id);
        $this->get(route('payments.receipt', $this->payment))->assertOk();
        $this->get(route('payments.receipt', $outsidePayment))->assertForbidden();
        $this->get(route('payments.proof', $outsidePayment))->assertForbidden();
        $this->get(route('private.show', ['type' => 'reading', 'id' => $outsideInvoice->reading_id]))->assertForbidden();
        $this->get(route('private.show', ['type' => 'customer', 'id' => $outside->id]))->assertForbidden();
        $this->get(route('payments.index', ['customer_id' => $outside->id]))->assertNotFound();
        $this->get(route('invoices.print', ['ids' => [$this->invoice->id, $outsideInvoice->id]]))->assertOk()->assertSee($this->invoice->number)->assertDontSee($outsideInvoice->number);
        $this->post(route('payments.cancel', $outsidePayment), ['reason' => 'Permintaan langsung di luar wilayah'])->assertForbidden();
        $this->assertSame('posted', $outsidePayment->fresh()->status);
    }

    public function test_archived_draft_cannot_be_issued_but_can_be_cancelled_before_period_close(): void
    {
        $customer = $this->newCustomer('PAM10-980003', Rt::where('code', '01')->first());
        $reading = app(MeterService::class)->save($this->admin, $customer, $this->period, ['current_reading' => '21.000', 'status' => 'submitted']);
        $draft = app(BillingService::class)->generate($this->admin, $reading, false);
        $customer->delete();

        $this->post(route('invoices.issue', $draft))->assertRedirect()->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->post(route('invoices.cancel', $draft), ['reason' => 'Batalkan draft milik pelanggan yang diarsipkan'])->assertRedirect();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->post(route('periods.close', $this->period), ['reason' => 'Tutup buku setelah penyelesaian draft arsip'])->assertRedirect();
        $this->assertNotNull($this->period->fresh()->locked_at);
    }

    public function test_archived_meter_replacement_history_and_export_scope_keep_assigned_customers(): void
    {
        $operator = User::factory()->create(['role_id' => Role::where('slug', 'operator')->value('id'), 'active' => true]);
        $operator->rts()->attach($this->customer->rt_id);
        $beforeScope = GenerateReportExport::scopeFingerprint($operator);
        $newMeter = app(MeterService::class)->replace($this->admin, $this->customer, ['serial' => 'M-ARCHIVED-REPLACEMENT', 'old_final' => '21.000', 'new_initial' => '0.000', 'replaced_on' => '2026-11-01', 'reason' => 'Ganti meter sebelum pelanggan diarsipkan']);
        $this->customer->delete();

        $this->assertSame($beforeScope, GenerateReportExport::scopeFingerprint($operator->fresh()));
        $this->actingAs($operator)->get(route('meters.index'))->assertOk()
            ->assertViewHas('customers', fn ($customers) => ! $customers->contains('id', $this->customer->id))
            ->assertViewHas('replacements', fn ($replacements) => $replacements->count() === 1 && $replacements->first()->new_meter_id === $newMeter->id && $replacements->first()->customer->trashed());
        $operator->rts()->sync([Rt::where('code', '02')->value('id')]);
        $this->assertNotSame($beforeScope, GenerateReportExport::scopeFingerprint($operator->fresh()));
        $this->get(route('meters.index'))->assertOk()->assertViewHas('replacements', fn ($replacements) => $replacements->isEmpty());
    }

    private function newCustomer(string $number, Rt $rt): Customer
    {
        $customer = Customer::create(['number' => $number, 'name' => 'Pelanggan '.$number, 'address' => 'Jalan Air RW.10', 'rt_id' => $rt->id, 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => '2026-10-01']);
        Meter::create(['customer_id' => $customer->id, 'serial' => 'M-'.$number, 'initial_reading' => '0.000', 'installed_on' => '2026-10-01', 'active' => true]);

        return $customer;
    }

    private function paidCustomer(string $number, Rt $rt): array
    {
        $customer = $this->newCustomer($number, $rt);
        $reading = app(MeterService::class)->save($this->admin, $customer, $this->period, ['current_reading' => '21.000', 'status' => 'submitted']);
        $invoice = app(BillingService::class)->generate($this->admin, $reading);
        $payment = app(PaymentService::class)->pay($this->admin, ['customer_id' => $customer->id, 'account_id' => Account::first()->id, 'payment_method_id' => PaymentMethod::first()->id, 'amount' => $invoice->total, 'paid_on' => '2026-10-15', 'idempotency_key' => (string) Str::uuid()]);

        return [$customer, $invoice, $payment];
    }
}
