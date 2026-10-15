<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Services\NumberService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Support\Permissions;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user(Role::where('slug', 'admin')->firstOrFail());
    }

    public function test_admin_can_manage_the_two_permissions_independently(): void
    {
        $this->assertArrayHasKey('pelanggan.create', Permissions::all());
        $this->assertArrayHasKey('pelanggan.delete', Permissions::all());
        $role = $this->role(['customers.view']);
        $this->actingAs($this->admin)->get(route('roles.index'))->assertOk()
            ->assertSee('value="pelanggan.create"', false)->assertSee('value="pelanggan.delete"', false);
        $this->put(route('roles.update', $role), ['name' => $role->name, 'permissions' => ['customers.view', 'pelanggan.create']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['customers.view', 'pelanggan.create'], $role->fresh()->permissions);
        $this->put(route('roles.update', $role), ['name' => $role->name, 'permissions' => ['customers.view', 'pelanggan.delete']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['customers.view', 'pelanggan.delete'], $role->fresh()->permissions);
    }

    public function test_create_permission_controls_both_form_and_post_and_is_separate_from_delete(): void
    {
        $actor = $this->user($this->role(['customers.view', 'pelanggan.create']));
        $this->actingAs($actor)->get(route('customers.index'))->assertOk()->assertSee(route('customers.create'), false);
        $this->get(route('customers.create'))->assertOk();
        $this->post(route('customers.store'), $this->customerData())->assertRedirect()->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertDatabaseHas('meters', ['customer_id' => $customer->id, 'serial' => 'METER-NEW-CUSTOMER']);
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee('action="'.route('customers.destroy', $customer).'"', false);
        $this->delete(route('customers.destroy', $customer), ['reason' => 'Berhenti berlangganan atas permintaan pelanggan'])->assertForbidden();
        $this->assertNotSoftDeleted($customer);
    }

    public function test_legacy_create_permission_cannot_bypass_canonical_permission(): void
    {
        $actor = $this->user($this->role(['customers.view', 'customers.create']));
        $this->actingAs($actor)->get(route('customers.index'))->assertOk()->assertDontSee(route('customers.create'), false);
        $this->get(route('customers.create'))->assertForbidden();
        $this->post(route('customers.store'), $this->customerData())->assertForbidden();
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('meters', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.created']);
    }

    public function test_delete_permission_does_not_grant_create_and_confirmation_identifies_customer(): void
    {
        $customer = $this->customer(['name' => 'Nama Pelanggan Konfirmasi']);
        $actor = $this->user($this->role(['customers.view', 'pelanggan.delete']));
        $this->actingAs($actor)->get(route('customers.index'))->assertOk()->assertDontSee(route('customers.create'), false);
        $this->get(route('customers.create'))->assertForbidden();
        $this->post(route('customers.store'), $this->customerData())->assertForbidden();
        $response = $this->get(route('customers.show', $customer))->assertOk()
            ->assertSee('action="'.route('customers.destroy', $customer).'"', false)
            ->assertSee($customer->number)->assertSee($customer->name)
            ->assertSee('name="reason"', false)->assertSee('value="DELETE"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $confirmation = (new \DOMXPath($dom))->query('//*[@id="delete-customer-'.$customer->id.'"]')->item(0);
        $this->assertNotNull($confirmation);
        $this->assertStringContainsString($customer->number, $confirmation->textContent);
        $this->assertStringContainsString($customer->name, $confirmation->textContent);
        $this->delete(route('customers.destroy', $customer), ['reason' => 'Berhenti berlangganan atas permintaan pelanggan'])
            ->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($customer);
    }

    public function test_permission_revocation_takes_effect_on_the_next_request(): void
    {
        $role = $this->role(['customers.view', 'pelanggan.create', 'pelanggan.delete']);
        $actor = $this->user($role);
        $customer = $this->customer();
        $this->actingAs($actor)->get(route('customers.create'))->assertOk();
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('action="'.route('customers.destroy', $customer).'"', false);
        $role->update(['permissions' => ['customers.view']]);
        $this->get(route('customers.create'))->assertForbidden();
        $this->post(route('customers.store'), $this->customerData())->assertForbidden();
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee('action="'.route('customers.destroy', $customer).'"', false);
        $this->delete(route('customers.destroy', $customer), ['reason' => 'Tindakan setelah permission dicabut'])->assertForbidden();
        $this->assertNotSoftDeleted($customer);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.deleted']);
    }

    public function test_an_admin_role_without_delete_permission_cannot_delete(): void
    {
        $customer = $this->customer();
        $role = $this->admin->role;
        $role->update(['permissions' => array_values(array_diff($role->permissions, ['pelanggan.delete']))]);
        $this->actingAs($this->admin->fresh())->get(route('customers.show', $customer))->assertOk()
            ->assertDontSee('action="'.route('customers.destroy', $customer).'"', false);
        $this->delete(route('customers.destroy', $customer), ['reason' => 'Admin tidak diberi permission hapus'])->assertForbidden();
        $this->assertNotSoftDeleted($customer);
    }

    public function test_operator_delete_permission_still_obeys_assigned_territory(): void
    {
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => ['customers.view', 'pelanggan.delete']]);
        $operator = $this->user($role);
        $inside = $this->customer();
        $outside = $this->customer(['rt_id' => Rt::where('code', '02')->value('id')]);
        $operator->rts()->sync([$inside->rt_id]);
        $this->actingAs($operator)->delete(route('customers.destroy', $outside), ['reason' => 'Pelanggan di luar wilayah operator'])->assertForbidden();
        $this->assertNotSoftDeleted($outside);
        $this->delete(route('customers.destroy', $inside), ['reason' => 'Pelanggan di wilayah operator berhenti'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted($inside);
        $this->assertSame(1, AuditLog::where('action', 'customer.deleted')->count());
    }

    public function test_unpaid_and_partially_paid_invoices_block_deletion_and_report_total_debt(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer, '2026-07', 45000);
        $new = $this->invoice($customer, '2026-08', 75000);
        $this->pay($customer, 20000, ['allocation_mode' => 'manual', 'allocations' => [$new->id => 20000]]);
        $this->actingAs($this->admin)->from(route('customers.show', $customer))
            ->delete(route('customers.destroy', $customer), ['reason' => 'Permintaan berhenti dengan tagihan tersisa'])
            ->assertRedirect(route('customers.show', $customer))->assertSessionHasErrors('customer_deletion');
        $message = session('errors')->first('customer_deletion');
        $this->assertStringContainsString('100.000', $message);
        $this->assertStringContainsString('Rp', $message);
        $this->assertNotSoftDeleted($customer);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(0, $old->fresh()->paid_amount);
        $this->assertSame(20000, $new->fresh()->paid_amount);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.deleted']);
    }

    public function test_deletion_requires_a_meaningful_reason_and_failed_requests_leave_no_audit(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin);
        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => 'abc'], ['reason' => str_repeat('x', 2001)]] as $data) {
            $this->delete(route('customers.destroy', $customer), $data)->assertRedirect()->assertSessionHasErrors('reason');
            $this->assertNotSoftDeleted($customer);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'customer.deleted']);
    }

    public function test_paid_customer_is_soft_deleted_with_audit_and_all_history_is_available(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-07', 45000);
        $payment = $this->pay($customer, 45000);
        $reading = $invoice->reading;
        $meter = $customer->activeMeter;
        $snapshot = $invoice->customer_snapshot;
        $reason = 'Pelanggan pindah dan menghentikan sambungan';
        $this->actingAs($this->admin)->delete(route('customers.destroy', $customer), ['reason' => $reason])
            ->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertNull(Customer::find($customer->id));
        $archived = Customer::withTrashed()->findOrFail($customer->id);
        $this->assertSame('inactive', $archived->status);
        $this->assertSame($meter->id, $archived->meters()->firstOrFail()->id);
        $this->assertSame($reading->id, $archived->readings()->firstOrFail()->id);
        $this->assertSame($snapshot, $invoice->fresh()->customer_snapshot);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($customer->id, $invoice->fresh()->customer->id);
        $this->assertSame($customer->id, $reading->fresh()->customer->id);
        $this->assertSame($customer->id, $payment->fresh()->customer->id);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'invoice_id' => $invoice->id, 'amount' => 45000]);
        $this->assertSame(45000, (int) CashEntry::where('payment_id', $payment->id)->sum('amount'));
        $audit = AuditLog::where('action', 'customer.deleted')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(Customer::class, $audit->subject_type);
        $this->assertSame($customer->id, $audit->subject_id);
        $this->assertSame($reason, $audit->reason);
        $this->assertSame($customer->number, $audit->before['number']);
        $this->assertSame($customer->name, $audit->before['name']);
        $this->assertSame($customer->number, $audit->after['number']);
        $this->assertNotNull($audit->after['deleted_at']);
        $this->assertTrue($audit->created_at->equalTo(now()));
        $this->get(route('customers.index'))->assertOk()->assertViewHas('customers', fn ($customers) => $customers->total() === 0);
        $this->get(route('customers.index', ['status' => 'inactive']))->assertOk()->assertViewHas('customers', fn ($customers) => $customers->total() === 0);
        $this->get(route('customers.show', $customer))->assertNotFound();
        $this->get(route('payments.receipt', $payment))->assertOk()->assertSee($customer->number)->assertSee($payment->number);
        $this->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        foreach (['readings', 'invoices', 'receipts'] as $type) {
            $report = app(ReportService::class)->build($this->admin->fresh(), ['type' => $type]);
            $this->assertContains($customer->number, array_column($report['rows'], $type === 'receipts' ? 'customer_number' : 'number'), 'Historical '.$type.' report excludes deleted customer.');
        }
        $this->delete(route('customers.destroy', $customer), ['reason' => $reason])->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'customer.deleted')->count());
    }

    public function test_draft_and_cancelled_invoices_do_not_count_as_outstanding_debt(): void
    {
        $customer = $this->customer();
        $draft = $this->invoice($customer, '2026-07', 45000);
        $draft->update(['status' => 'draft', 'issued_at' => null]);
        $cancelled = $this->invoice($customer, '2026-08', 75000);
        $cancelled->update(['status' => 'cancelled', 'active_slot' => null, 'cancelled_at' => now(), 'cancellation_reason' => 'Pembatalan tagihan uji']);
        $this->actingAs($this->admin)->delete(route('customers.destroy', $customer), ['reason' => 'Pelanggan berhenti tanpa tagihan terutang'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSoftDeleted($customer);
        $this->assertDatabaseHas('invoices', ['id' => $draft->id, 'status' => 'draft', 'total' => 45000]);
        $this->assertDatabaseHas('invoices', ['id' => $cancelled->id, 'status' => 'cancelled', 'total' => 75000]);
    }

    public function test_new_customer_cannot_reuse_deleted_customer_identity_even_when_supplied_in_request(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin)->delete(route('customers.destroy', $customer), ['reason' => 'Pelanggan lama mengakhiri langganan'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('customers.store'), $this->customerData(['number' => $customer->number, 'id' => $customer->id]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $new = Customer::sole();
        $this->assertNotSame($customer->number, $new->number);
        $this->assertGreaterThan($customer->id, $new->id);
        $this->assertSame(2, Customer::withTrashed()->count());
        $duplicate = Customer::withTrashed()->findOrFail($customer->id)->replicate();
        $duplicate->deleted_at = null;
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_import_permission_cannot_bypass_customer_creation_policy(): void
    {
        $actor = $this->user($this->role(['customers.view', 'customers.import']));
        $before = Customer::withTrashed()->count();
        $this->actingAs($actor)->get(route('imports.template'))->assertOk();
        $this->post(route('imports.commit'), ['token' => (string) Str::uuid()])->assertForbidden();
        $this->assertSame($before, Customer::withTrashed()->count());
    }

    private function role(array $permissions): Role
    {
        return Role::create(['slug' => 'customer-test-'.Role::count(), 'name' => 'Role pelanggan uji', 'permissions' => $permissions]);
    }

    private function user(Role $role): User
    {
        return User::create(['name' => 'Pengguna '.$role->name, 'email' => 'customer-user-'.User::count().'@example.test', 'password' => 'Test-password-unique', 'role_id' => $role->id, 'active' => true]);
    }

    private function customerData(array $overrides = []): array
    {
        return array_replace(['name' => 'Pelanggan Baru Uji', 'address' => 'Jl. Air RW.10', 'rt_id' => Rt::where('code', '01')->value('id'), 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => '2026-01-01', 'serial' => 'METER-NEW-CUSTOMER', 'initial_reading' => '0', 'installed_on' => '2026-01-01'], $overrides);
    }

    private function customer(array $overrides = []): Customer
    {
        $data = $this->customerData($overrides);
        $customer = Customer::create(collect($data)->except(['serial', 'initial_reading', 'installed_on'])->all() + ['number' => NumberService::next('customer')]);
        $customer->meters()->create(['serial' => 'METER-'.$customer->number, 'initial_reading' => 0, 'installed_on' => '2026-01-01', 'active' => true]);

        return $customer;
    }

    private function invoice(Customer $customer, string $month, int $amount): Invoice
    {
        $date = Carbon::parse($month.'-01');
        $period = Period::firstOrCreate(['name' => $month], ['starts_on' => $date->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString(), 'due_date' => $date->copy()->addMonth()->day(20)->toDateString()]);
        $snapshot = $customer->only('number', 'name', 'address', 'rt_id', 'category_id') + ['rt_name' => $customer->rt->name, 'category_name' => $customer->category->name];
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $customer->activeMeter->id, 'period_id' => $period->id, 'user_id' => $this->admin->id, 'previous_reading' => '0.000', 'current_reading' => '20.000', 'usage' => '20.000', 'status' => 'locked', 'customer_snapshot' => $snapshot]);

        return Invoice::create(['number' => 'TEST-'.$customer->id.'-'.$month, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::where('category_id', $customer->category_id)->value('id'), 'customer_snapshot' => $snapshot, 'tariff_snapshot' => [], 'lines' => [], 'usage' => '20.000', 'water_amount' => $amount, 'total' => $amount, 'paid_amount' => 0, 'status' => 'issued', 'due_date' => $period->due_date, 'issued_at' => now(), 'user_id' => $this->admin->id]);
    }

    private function pay(Customer $customer, int $amount, array $overrides = [])
    {
        return app(PaymentService::class)->pay($this->admin, array_replace(['customer_id' => $customer->id, 'account_id' => Account::firstOrFail()->id, 'payment_method_id' => PaymentMethod::firstOrFail()->id, 'amount' => $amount, 'paid_on' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid()], $overrides));
    }
}
