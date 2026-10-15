<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Services\BillingService;
use App\Services\MeterService;
use App\Services\NumberService;
use App\Services\SettingService;
use App\Services\TariffService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoreDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'core@example.test', 'password' => 'test-password-unique', 'role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        SettingService::set('reading_verification', false);
    }

    private function customer(): Customer
    {
        $customer = Customer::create(['number' => NumberService::next('customer'), 'name' => 'Pelanggan Uji', 'address' => 'Jl. Uji 1', 'rt_id' => Rt::first()->id, 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => '2026-06-01']);
        $customer->meters()->create(['serial' => 'M-'.$customer->number, 'initial_reading' => '100.000', 'installed_on' => '2026-06-30', 'active' => true]);

        return $customer;
    }

    private function period(string $month = '2026-07'): Period
    {
        $date = Carbon::parse($month.'-01');

        return Period::firstOrCreate(['name' => $month], ['starts_on' => $date->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString(), 'due_date' => $date->copy()->addMonth()->day(20)->toDateString()]);
    }

    private function reading(?Customer $customer = null, string $month = '2026-07', string $current = '120'): Reading
    {
        return app(MeterService::class)->save($this->admin, $customer ?? $this->customer(), $this->period($month), ['current_reading' => $current, 'status' => 'submitted']);
    }

    public function test_all_required_tariff_examples_and_decimal_rounding(): void
    {
        $service = app(TariffService::class);
        $house = TariffScheme::whereHas('category', fn ($q) => $q->where('name', 'Rumah'))->firstOrFail();
        foreach (['0' => 45000, '10' => 45000, '20' => 45000, '21' => 48000, '30' => 75000, '40' => 105000, '41' => 108500, '50' => 140000, '20.001' => 45003, '40.001' => 105004] as $usage => $amount) {
            $this->assertSame($amount, $service->calculate($house, (string) $usage)['total'], 'Rumah '.$usage);
        }
        $public = TariffScheme::whereHas('category', fn ($q) => $q->where('name', 'Fasilitas Umum'))->firstOrFail();
        foreach (['0' => 0, '10' => 15000, '20' => 30000, '50' => 75000, '10.001' => 15002] as $usage => $amount) {
            $this->assertSame($amount, $service->calculate($public, (string) $usage)['total']);
        }
        $house->rounding_unit = 100;
        $this->assertSame(105000, $service->calculate($house, '40.001')['total']);
        $house->rounding_mode = 'up';
        $this->assertSame(105100, $service->calculate($house, '40.001')['total']);
        $house->rounding_mode = 'down';
        $this->assertSame(105000, $service->calculate($house, '40.001')['total']);
    }

    public function test_additive_and_minimum_modes_do_not_double_charge(): void
    {
        $scheme = TariffScheme::whereHas('category', fn ($q) => $q->where('name', 'Fasilitas Umum'))->firstOrFail();
        $scheme->base_amount = 20000;
        $scheme->base_mode = 'additive';
        $this->assertSame(35000, app(TariffService::class)->calculate($scheme, '10')['total']);
        $scheme->base_mode = 'minimum';
        $this->assertSame(20000, app(TariffService::class)->calculate($scheme, '10')['total']);
        $this->assertSame(30000, app(TariffService::class)->calculate($scheme, '20')['total']);
    }

    public function test_tariff_layers_reject_gaps_and_overlap(): void
    {
        $scheme = TariffScheme::first()->toArray();
        $scheme['id'] = TariffScheme::first()->id;
        $this->expectException(ValidationException::class);
        app(TariffService::class)->validate($scheme, [['lower_bound' => '20', 'upper_bound' => '40', 'rate' => 3000], ['lower_bound' => '41', 'upper_bound' => null, 'rate' => 3500]]);
    }

    public function test_issued_invoice_is_locked_and_historical_tariff_snapshot_survives_new_version(): void
    {
        $reading = $this->reading();
        $invoice = app(BillingService::class)->generate($this->admin, $reading);
        $original = $invoice->tariff_snapshot;
        $old = $invoice->tariffScheme;
        $old->update(['effective_until' => '2026-07-31']);
        $new = $old->replicate(['locked_at', 'effective_until']);
        $new->version = 2;
        $new->effective_from = '2026-08-01';
        $new->base_amount = 60000;
        $new->save();
        foreach ($old->tiers as $tier) {
            $new->tiers()->create($tier->only('lower_bound', 'upper_bound', 'rate', 'position'));
        }
        $this->assertSame(45000, $invoice->fresh()->total);
        $this->assertSame($original, $invoice->fresh()->tariff_snapshot);
        $this->assertSame('locked', $reading->fresh()->status);
        $this->expectException(ValidationException::class);
        $old->update(['base_amount' => 1]);
    }

    public function test_duplicate_readings_are_prevented_by_database(): void
    {
        $reading = $this->reading();
        $this->expectException(QueryException::class);
        $reading->replicate()->save();
    }

    public function test_duplicate_active_invoices_are_prevented_by_database(): void
    {
        $invoice = app(BillingService::class)->generate($this->admin, $this->reading());
        $duplicate = $invoice->replicate();
        $duplicate->number = 'TAG-DUPLICATE';
        $duplicate->revision = 2;
        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_meter_replacement_combines_old_and_new_meter_consumption(): void
    {
        $customer = $this->customer();
        $this->reading($customer);
        $new = app(MeterService::class)->replace($this->admin, $customer, ['serial' => 'NEW-METER', 'old_final' => '127', 'new_initial' => '10', 'replaced_on' => '2026-08-15', 'reason' => 'Meter lama rusak']);
        $reading = $this->reading($customer, '2026-08', '15');
        $this->assertSame($new->id, $reading->meter_id);
        $this->assertSame('12.000', $reading->usage);
        $this->assertCount(2, $reading->customer_snapshot['meter_segments']);
        $this->assertDatabaseHas('meter_replacements', ['old_final' => 127, 'new_initial' => 10]);
    }

    public function test_gap_periods_are_not_charged_as_a_single_month(): void
    {
        $customer = $this->customer();
        $this->reading($customer);
        $this->expectException(ValidationException::class);
        $this->reading($customer, '2026-09', '150');
    }

    public function test_meter_numbers_cannot_decrease(): void
    {
        $this->expectException(ValidationException::class);
        $this->reading(null, '2026-07', '99');
    }

    public function test_operator_must_stay_in_assigned_rt(): void
    {
        $operator = User::create(['name' => 'Operator', 'email' => 'op@example.test', 'password' => 'operator-password', 'role_id' => Role::where('slug', 'operator')->value('id'), 'active' => true]);
        $customer = $this->customer();
        $operator->rts()->sync([Rt::where('id', '!=', $customer->rt_id)->first()->id]);
        $this->assertFalse($operator->canAccessCustomer($customer));
        $this->assertSame(0, $operator->scopeCustomers(Customer::query())->count());
        try {
            app(MeterService::class)->save($operator, $customer, $this->period(), ['current_reading' => '120', 'status' => 'submitted']);
            $this->fail('Operator lintas wilayah harus ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $operator->rts()->sync([$customer->rt_id]);
        $reading = app(MeterService::class)->save($operator, $customer, $this->period(), ['current_reading' => '120', 'status' => 'submitted']);
        $this->assertSame('verified', $reading->status);
        $this->assertFalse($operator->hasPermission('tariffs.update'));
    }

    public function test_verification_rejection_draft_revision_and_resubmit(): void
    {
        SettingService::set('reading_verification', true);
        $reading = $this->reading();
        $this->assertSame('submitted', $reading->status);
        app(MeterService::class)->verify($this->admin, $reading, false, 'Foto meter belum terbaca');
        $reading = app(MeterService::class)->save($this->admin, $reading->customer, $reading->period, ['current_reading' => '121', 'status' => 'submitted']);
        $reading = app(MeterService::class)->verify($this->admin, $reading, true);
        $this->assertSame('verified', $reading->status);
        $this->assertSame('21.000', $reading->usage);
    }

    public function test_invoice_correction_preserves_cancelled_revision_and_audit(): void
    {
        $reading = $this->reading();
        $invoice = app(BillingService::class)->generate($this->admin, $reading);
        app(BillingService::class)->cancel($this->admin, $invoice, 'Angka meter perlu diperbaiki');
        $reading = app(MeterService::class)->correct($this->admin, $reading, ['current_reading' => '125', 'reason' => 'Foto asli menunjukkan angka 125']);
        $replacement = app(BillingService::class)->generate($this->admin, $reading);
        $this->assertSame(60000, $replacement->total);
        $this->assertSame(2, $replacement->revision);
        $this->assertSame($invoice->id, $replacement->replacement_for);
        $this->assertSame(45000, $invoice->fresh()->total);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reading.corrected', 'user_id' => $this->admin->id]);
    }

    public function test_period_close_and_reopen_require_reason_and_block_writes(): void
    {
        $reading = $this->reading();
        $invoice = app(BillingService::class)->generate($this->admin, $reading);
        $period = $reading->period;
        app(BillingService::class)->closePeriod($this->admin, $period, 'Tutup buku telah diperiksa');
        $this->assertNotNull($period->fresh()->locked_at);
        try {
            app(BillingService::class)->cancel($this->admin, $invoice, 'Koreksi setelah tutup buku');
            $this->fail('Periode tertutup harus menolak koreksi.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('ditutup', $exception->getMessage());
        }
        app(BillingService::class)->reopenPeriod($this->admin, $period, 'Pembukaan kembali disetujui');
        app(BillingService::class)->cancel($this->admin, $invoice, 'Koreksi setelah periode dibuka');
        $this->assertSame('cancelled', $invoice->fresh()->status);
    }

    public function test_precision_zero_and_prorata_fixed_fees_are_enforced(): void
    {
        $customer = $this->customer();
        $customer->update(['joined_on' => '2026-07-16']);
        $customer->activeMeter->update(['installed_on' => '2026-07-16']);
        SettingService::set('meter_precision', 0);
        SettingService::set('midperiod_policy', 'prorate');
        $reading = $this->reading($customer, '2026-07', '120.000');
        $invoice = app(BillingService::class)->generate($this->admin, $reading);
        $this->assertSame(23226, $invoice->total);
        $this->assertSame(16, $invoice->tariff_snapshot['calculation_policy']['active_days']);
        $this->assertSame('20.000', $invoice->tariff_snapshot['included_usage']);
    }

    public function test_demo_seed_runs_real_workflows_and_remains_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('customers', 27);
        $this->assertDatabaseCount('invoices', 81);
        $this->assertGreaterThan(0, Payment::count());
        $this->assertGreaterThan(0,CashEntry::where('direction','out')->count());
        $this->assertDatabaseHas('invoices',['status' => 'partial']);
        $this->assertDatabaseHas('invoices',['status' => 'paid']);
        $this->assertDatabaseHas('invoices',['status' => 'issued']);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('invoices',81);
    }
}
