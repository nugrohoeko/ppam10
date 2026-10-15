<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Services\NumberService;
use App\Services\SettingService;
use App\Services\TariffService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessAndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user('admin', 'admin-access@example.test');
    }

    private function user(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => 'a-unique-test-password', 'role_id' => Role::where('slug', $role)->value('id'), 'active' => true]);
    }

    private function customerData(array $overrides = []): array
    {
        return array_replace(['name' => 'Pelanggan Wilayah Satu', 'address' => 'Jl. Uji RW.10', 'rt_id' => Rt::where('code', '01')->value('id'), 'category_id' => Category::where('name', 'Rumah')->value('id'), 'phone' => '08123456789', 'status' => 'active', 'joined_on' => '2026-07-01', 'serial' => 'METER-'.Customer::count(), 'initial_reading' => '100', 'installed_on' => '2026-07-01'], $overrides);
    }

    private function customer(array $overrides = []): Customer
    {
        $data = $this->customerData($overrides);
        $customer = Customer::create(collect($data)->except(['serial', 'initial_reading', 'installed_on'])->all() + ['number' => NumberService::next('customer')]);
        $customer->meters()->create(['serial' => $data['serial'], 'initial_reading' => $data['initial_reading'], 'installed_on' => $data['installed_on'], 'active' => true]);

        return $customer;
    }

    private function period(): Period
    {
        return Period::firstOrCreate(['name' => '2026-07'], ['starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'due_date' => '2026-08-20']);
    }

    private function settingsData(): array
    {
        return ['pam_name' => 'PAM RW.10 Diperbarui', 'pam_address' => 'Alamat PAM', 'pam_contact' => '081234567890', 'pam_management' => 'Pengurus PAM', 'payment_info' => 'Bank PAM', 'invoice_note' => 'Terima kasih', 'receipt_note' => 'Simpan kuitansi ini',
            'meter_precision' => 3, 'reading_verification' => 1, 'expense_approval_required' => 0, 'usage_spike_percent' => 100, 'usage_drop_percent' => 70, 'payment_allocation_mode' => 'oldest', 'inactive_policy' => 'block', 'midperiod_policy' => 'full', 'default_due_day' => 20,
            'label_width' => 62, 'label_height' => 35, 'label_margin' => 8, 'label_columns' => 3, 'label_paper' => 'A4', 'label_copies' => 2,
            'number' => ['customer' => ['prefix' => 'PAM10-', 'digits' => 6], 'invoice' => ['prefix' => 'TAG-{YYYY}{MM}-', 'digits' => 7], 'receipt' => ['prefix' => 'KWT-{YYYY}-{MM}-', 'digits' => 7]]];
    }

    public function test_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $payload = ['name' => $this->admin->name, 'email' => $this->admin->email, 'role_id' => $this->admin->role_id, 'active' => 0];
        $this->actingAs($this->admin)->put(route('users.update', $this->admin), $payload)->assertRedirect()->assertSessionHasErrors('admin');
        $this->assertTrue($this->admin->fresh()->active);
        $payload['active'] = 1;
        $payload['role_id'] = Role::where('slug', 'ketua')->value('id');
        $this->actingAs($this->admin->fresh())->put(route('users.update', $this->admin), $payload)->assertRedirect()->assertSessionHasErrors('admin');
        $this->assertSame('admin', $this->admin->fresh()->role->slug);
    }

    public function test_role_edits_cannot_remove_all_admin_access_and_roll_back(): void
    {
        $role = $this->admin->role;
        $before = $role->permissions;
        $this->actingAs($this->admin)->put(route('roles.update', $role), ['name' => 'Admin', 'permissions' => ['dashboard.view']])->assertRedirect()->assertSessionHasErrors('admin');
        $this->assertSame($before, $role->fresh()->permissions);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'role.updated']);
    }

    public function test_another_active_admin_allows_authorized_account_deactivation(): void
    {
        $second = $this->user('admin', 'second-access@example.test');
        $this->actingAs($this->admin)->put(route('users.update', $second), ['name' => $second->name, 'email' => $second->email, 'role_id' => $second->role_id, 'active' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($second->fresh()->active);
        $this->assertTrue($this->admin->fresh()->active);
    }

    public function test_login_is_rate_limited_and_recovers_after_limiter_is_cleared(): void
    {
        $key = 'login:'.$this->admin->email.'|127.0.0.1';
        RateLimiter::clear($key);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'wrong-password'])->assertRedirect()->assertSessionHasErrors('email');
        }
        $this->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'a-unique-test-password'])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Terlalu banyak', session('errors')->first('email'));
        $this->assertGuest();
        RateLimiter::clear($key);
        $this->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'a-unique-test-password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_inactive_accounts_cannot_login_and_existing_session_is_revoked(): void
    {
        $this->admin->update(['active' => false]);
        $this->post(route('login.store'), ['email' => $this->admin->email, 'password' => 'a-unique-test-password'])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($this->admin)->get(route('customers.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_operator_direct_urls_and_estimate_enforce_rt_and_permissions(): void
    {
        $operator = $this->user('operator', 'operator-access@example.test');
        $operator->rts()->sync([Rt::where('code', '01')->value('id')]);
        $outside = $this->customer(['rt_id' => Rt::where('code', '02')->value('id')]);
        $this->actingAs($operator)->get(route('customers.show', $outside))->assertForbidden();
        $this->get(route('customers.edit', $outside))->assertForbidden();
        $this->get(route('readings.estimate', ['customer_id' => $outside->id, 'period_id' => $this->period()->id, 'usage' => '21']))->assertForbidden();
        $this->post(route('readings.store'), ['customer_id' => $outside->id, 'period_id' => $this->period()->id, 'current_reading' => '121', 'status' => 'submitted'])->assertForbidden();
        $this->post(route('tariffs.store'), [])->assertForbidden();
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_http_export_cannot_expand_operator_territory_with_rt_filter(): void
    {
        Storage::fake('local');
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => array_merge($role->permissions, ['reports.view', 'reports.export'])]);
        $operator = $this->user('operator', 'export-access@example.test');
        $operator->rts()->sync([Rt::where('code', '01')->value('id')]);
        $inside = $this->customer(['name' => 'NAMA WILAYAH DIIZINKAN']);
        $outside = $this->customer(['name' => 'NAMA WILAYAH RAHASIA', 'rt_id' => Rt::where('code', '02')->value('id')]);
        $response = $this->actingAs($operator)->get(route('reports.export', ['type' => 'customers', 'format' => 'xlsx', 'rt_ids' => [$inside->rt_id, $outside->rt_id]]));
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = Storage::disk('local')->path('scope-export.xlsx');
        file_put_contents($path, $response->streamedContent());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $strings = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('NAMA WILAYAH DIIZINKAN', $strings);
        $this->assertStringNotContainsString('NAMA WILAYAH RAHASIA', $strings);
        Storage::disk('local')->delete('scope-export.xlsx');
    }

    public function test_customer_update_permission_does_not_include_deactivation(): void
    {
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => array_merge($role->permissions, ['customers.update'])]);
        $operator = $this->user('operator', 'edit-only@example.test');
        $customer = $this->customer();
        $operator->rts()->sync([$customer->rt_id]);
        $this->actingAs($operator)->put(route('customers.update', $customer), $this->customerData(['phone' => '08111111111', 'reason' => 'Memperbarui kontak pelanggan']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('08111111111', $customer->fresh()->phone);
        $this->put(route('customers.update', $customer), $this->customerData(['status' => 'inactive', 'reason' => 'Permintaan menonaktifkan pelanggan']))->assertForbidden();
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_operator_with_update_permission_cannot_move_customer_outside_assigned_rt(): void
    {
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => array_merge($role->permissions, ['customers.update'])]);
        $operator = $this->user('operator', 'move-denied@example.test');
        $customer = $this->customer();
        $original = $customer->rt_id;
        $operator->rts()->sync([$original]);
        $this->actingAs($operator)->put(route('customers.update', $customer), $this->customerData(['rt_id' => Rt::where('code', '02')->value('id'), 'reason' => 'Memindahkan wilayah pelanggan']))->assertForbidden();
        $this->assertSame($original, $customer->fresh()->rt_id);
        $this->assertDatabaseCount('customer_histories', 0);
    }

    public function test_http_meter_verification_invoice_and_customer_history_preserve_snapshots(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), $this->customerData())->assertRedirect()->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $number = $customer->number;
        $operator = $this->user('operator', 'workflow-access@example.test');
        $operator->rts()->sync([$customer->rt_id]);
        $this->actingAs($operator)->post(route('readings.store'), ['customer_id' => $customer->id, 'period_id' => $this->period()->id, 'current_reading' => '121', 'status' => 'submitted'])->assertRedirect()->assertSessionHasNoErrors();
        $reading = Reading::firstOrFail();
        $this->assertSame('submitted', $reading->status);
        $this->post(route('readings.verify', $reading), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('readings.verify', $reading), ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), ['reading_ids' => [$reading->id], 'action' => 'draft'])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->post(route('invoices.issue', $invoice))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(48000, $invoice->fresh()->total);
        $this->assertSame('locked', $reading->fresh()->status);
        $this->actingAs($operator)->post(route('readings.store'), ['customer_id' => $customer->id, 'period_id' => $this->period()->id, 'current_reading' => '122', 'status' => 'draft'])->assertRedirect()->assertSessionHasErrors('reading');
        $snapshot = $invoice->fresh()->customer_snapshot;
        $this->actingAs($this->admin)->put(route('customers.update', $customer), $this->customerData(['name' => 'Nama Baru', 'rt_id' => Rt::where('code', '02')->value('id'), 'category_id' => Category::where('name', 'Fasilitas Umum')->value('id'), 'number' => 'ATTACK-CHANGED', 'reason' => 'Pindah alamat dan kategori']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($number, $customer->fresh()->number);
        $this->assertSame('Nama Baru', $customer->fresh()->name);
        $this->assertSame($snapshot, $invoice->fresh()->customer_snapshot);
        $this->assertSame($snapshot, $reading->fresh()->customer_snapshot);
        $this->assertDatabaseCount('customer_histories', 2);
    }

    public function test_tariff_form_supports_single_rate_and_safe_version_supersession(): void
    {
        $category = Category::create(['name' => 'Kategori Uji Tunggal', 'active' => true]);
        $data = ['category_id' => $category->id, 'name' => 'Tarif tunggal awal', 'effective_from' => '2026-01-01', 'effective_until' => null, 'method' => 'single', 'base_mode' => 'additive', 'base_amount' => 0, 'included_usage' => '0', 'admin_fee' => 0, 'additional_fee' => 0, 'discount_type' => 'nominal', 'discount_value' => '0', 'penalty_amount' => 0, 'grace_days' => 0, 'rounding_unit' => 1, 'rounding_mode' => 'half_up', 'tiers' => [['lower_bound' => '0', 'upper_bound' => '', 'rate' => 1750]]];
        $this->actingAs($this->admin)->post(route('tariffs.store'), $data)->assertRedirect(route('tariffs.index'))->assertSessionHasNoErrors();
        $original = TariffScheme::where('category_id', $category->id)->firstOrFail();
        $data['effective_from'] = '2026-10-01';
        $data['tiers'][0]['rate'] = 2000;
        $this->post(route('tariffs.store'), $data)->assertRedirect()->assertSessionHasErrors('effective_from');
        $this->assertDatabaseCount('tariff_schemes', 3);
        $this->post(route('tariffs.store'), $data + ['supersede' => 1])->assertRedirect(route('tariffs.index'))->assertSessionHasNoErrors();
        $this->assertSame('2026-09-30', $original->fresh()->effective_until->toDateString());
        $scheme = app(TariffService::class)->forCategory($category->id, '2026-10-01');
        $this->assertSame(2, $scheme->version);
        $this->assertSame(20000, app(TariffService::class)->calculate($scheme, '10')['total']);
        $data['effective_from'] = '2027-01-01';
        $data['tiers'] = [['lower_bound' => '0', 'upper_bound' => '10', 'rate' => 1], ['lower_bound' => '10', 'upper_bound' => '', 'rate' => 2]];
        $this->post(route('tariffs.store'), $data + ['supersede' => 1])->assertRedirect()->assertSessionHasErrors('tiers');
    }

    public function test_private_customer_and_meter_photos_require_login_and_territory(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('customer-photos/customer.jpg', 'private customer image');
        Storage::disk('local')->put('meter-photos/meter.jpg', 'private meter image');
        $customer = $this->customer(['photo_path' => 'customer-photos/customer.jpg']);
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $customer->activeMeter->id, 'period_id' => $this->period()->id, 'user_id' => $this->admin->id, 'previous_reading' => '100', 'current_reading' => '120', 'usage' => '20', 'status' => 'draft', 'customer_snapshot' => [], 'photo_path' => 'meter-photos/meter.jpg']);
        $this->get(route('private.show', ['type' => 'customer', 'id' => $customer->id]))->assertRedirect(route('login'));
        $operator = $this->user('operator', 'photo-access@example.test');
        $operator->rts()->sync([$customer->rt_id]);
        $response = $this->actingAs($operator)->get(route('private.show', ['type' => 'customer', 'id' => $customer->id]));
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('private.show', ['type' => 'reading', 'id' => $reading->id]))->assertOk();
        $operator->rts()->sync([Rt::where('code', '02')->value('id')]);
        $this->get(route('private.show', ['type' => 'customer', 'id' => $customer->id]))->assertForbidden();
        $this->get(route('private.show', ['type' => 'reading', 'id' => $reading->id]))->assertForbidden();
        $this->get(route('private.show', ['type' => 'unknown', 'id' => $reading->id]))->assertNotFound();
    }

    public function test_http_input_cannot_attach_arbitrary_private_paths(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('meter-photos/secret.jpg', 'private evidence');
        $this->actingAs($this->admin)->post(route('customers.store'), $this->customerData(['photo_path' => 'meter-photos/secret.jpg']))->assertRedirect()->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertNull($customer->photo_path);
        $this->post(route('readings.store'), ['customer_id' => $customer->id, 'period_id' => $this->period()->id, 'current_reading' => '120', 'status' => 'draft', 'photo_path' => 'meter-photos/secret.jpg'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Reading::firstOrFail()->photo_path);
    }

    public function test_configured_initial_meter_precision_is_validated_before_any_rows_are_written(): void
    {
        SettingService::set('meter_precision', 0);
        $this->actingAs($this->admin)->post(route('customers.store'), $this->customerData(['initial_reading' => '100.5']))->assertRedirect()->assertSessionHasErrors('value');
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('meters', 0);
        $this->assertDatabaseCount('customer_histories', 0);
    }

    public function test_settings_require_all_number_formats_paper_and_copies(): void
    {
        $data = $this->settingsData();
        unset($data['number']['receipt'],$data['label_paper'],$data['label_copies']);
        $this->actingAs($this->admin)->put(route('settings.update'), $data)->assertRedirect()->assertSessionHasErrors(['number.receipt', 'label_paper', 'label_copies']);
        $this->assertSame('PAM RW.10', SettingService::get('pam_name'));
        $data = $this->settingsData();
        unset($data['number']['customer']['prefix'],$data['number']['invoice']['digits']);
        $this->put(route('settings.update'), $data)->assertRedirect()->assertSessionHasErrors(['number.customer.prefix', 'number.invoice.digits']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_settings_reject_unknown_or_malformed_number_tokens(): void
    {
        $this->actingAs($this->admin);
        foreach (['TAG-{DD}-', 'TAG-{YYYY-', 'TAG-{mm}-', 'TAG-{}-'] as $prefix) {
            $data = $this->settingsData();
            $data['number']['invoice']['prefix'] = $prefix;
            $this->put(route('settings.update'), $data)->assertRedirect()->assertSessionHasErrors('number.invoice.prefix');
        }
        $this->assertSame('TAG-{YYYY}{MM}-', SettingService::get('number.invoice.prefix'));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
    }

    public function test_settings_label_grid_must_fit_and_valid_scalar_settings_are_mapped(): void
    {
        $data = $this->settingsData();
        $data['label_width'] = 64;
        $data['label_margin'] = 10;
        $this->actingAs($this->admin)->put(route('settings.update'), $data)->assertRedirect()->assertSessionHasErrors('label_width');
        $this->assertSame(62, SettingService::get('label_width_mm'));
        $data['label_paper'] = 'Letter';
        $this->put(route('settings.update'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(64, SettingService::get('label_width_mm'));
        $this->assertSame(35, SettingService::get('label_height_mm'));
        $this->assertSame(10, SettingService::get('label_margin_mm'));
        $this->assertSame('Letter', SettingService::get('label_paper'));
        $this->assertSame(2, SettingService::get('label_copies'));
        $this->assertSame('TAG-{YYYY}{MM}-', SettingService::get('number.invoice.prefix'));
        $this->assertSame(7, SettingService::get('number.invoice.digits'));
        $this->assertSame('PAM RW.10 Diperbarui', SettingService::get('pam_name'));
        $this->assertDatabaseMissing('settings', ['key' => 'label_width']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated', 'user_id' => $this->admin->id]);
    }

    public function test_failed_customer_create_and_update_clean_new_uploads_and_keep_existing_photo(): void
    {
        Storage::fake('local');
        $data = $this->customerData(['name' => '', 'photo' => UploadedFile::fake()->image('new.jpg')]);
        $this->actingAs($this->admin)->post(route('customers.store'), $data)->assertRedirect()->assertSessionHasErrors('name');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('customers', 0);
        Storage::disk('local')->put('customer-photos/existing.jpg', 'original photo');
        $customer = $this->customer(['photo_path' => 'customer-photos/existing.jpg']);
        $this->put(route('customers.update', $customer), $this->customerData(['reason' => '', 'photo' => UploadedFile::fake()->image('replacement.jpg')]))->assertRedirect()->assertSessionHasErrors('reason');
        $this->assertSame(['customer-photos/existing.jpg'], Storage::disk('local')->allFiles());
        $this->assertSame('customer-photos/existing.jpg', $customer->fresh()->photo_path);
        $this->assertDatabaseCount('customer_histories', 0);
    }

    public function test_failed_meter_submissions_remove_new_upload_and_keep_previous_draft_photo(): void
    {
        Storage::fake('local');
        $customer = $this->customer();
        $period = $this->period();
        $data = ['customer_id' => $customer->id, 'period_id' => $period->id, 'current_reading' => '99', 'status' => 'draft', 'photo' => UploadedFile::fake()->image('invalid.jpg')];
        $this->actingAs($this->admin)->post(route('readings.store'), $data)->assertRedirect()->assertSessionHasErrors('reading');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('readings', 0);
        $data['current_reading'] = '120';
        $data['photo'] = UploadedFile::fake()->image('valid.jpg');
        $this->post(route('readings.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $reading = Reading::firstOrFail();
        $original = $reading->photo_path;
        $this->assertNotNull($original);
        Storage::disk('local')->assertExists($original);
        $data['current_reading'] = '99';
        $data['photo'] = UploadedFile::fake()->image('bad-revision.jpg');
        $this->post(route('readings.store'), $data)->assertRedirect()->assertSessionHasErrors('reading');
        $this->assertSame([$original], Storage::disk('local')->allFiles());
        $this->assertSame($original, $reading->fresh()->photo_path);
        $this->assertSame('120.000', $reading->fresh()->current_reading);
    }

    public function test_forbidden_photo_uploads_leave_no_files(): void
    {
        Storage::fake('local');
        $operator = $this->user('operator', 'upload-forbidden@example.test');
        $operator->rts()->sync([Rt::where('code', '01')->value('id')]);
        $this->actingAs($operator)->post(route('customers.store'), $this->customerData(['photo' => UploadedFile::fake()->image('customer.jpg')]))->assertForbidden();
        $outside = $this->customer(['rt_id' => Rt::where('code', '02')->value('id')]);
        $this->post(route('readings.store'), ['customer_id' => $outside->id, 'period_id' => $this->period()->id, 'current_reading' => '120', 'status' => 'submitted', 'photo' => UploadedFile::fake()->image('meter.jpg')])->assertForbidden();
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('readings', 0);
    }

    public function test_allow_inactive_policy_exposes_customer_in_reading_flow_and_permits_submission(): void
    {
        $customer = $this->customer(['status' => 'inactive']);
        $period = $this->period();
        $this->actingAs($this->admin)->get(route('readings.index', ['period_id' => $period->id, 'customer' => $customer->number]))->assertRedirect()->assertSessionHasErrors('customer');
        SettingService::set('inactive_policy', 'allow');
        $this->get(route('readings.index', ['period_id' => $period->id, 'customer' => $customer->number]))->assertOk()->assertViewHas('selected', fn ($selected) => $selected->id === $customer->id)->assertViewHas('total', 1);
        $this->post(route('readings.store'), ['customer_id' => $customer->id, 'period_id' => $period->id, 'current_reading' => '120', 'status' => 'submitted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('readings', ['customer_id' => $customer->id, 'status' => 'submitted']);
    }

    public function test_archived_existing_rt_and_category_can_be_retained_without_changing_invoice_history(): void
    {
        $customer = $this->customer();
        $this->actingAs($this->admin)->post(route('readings.store'), ['customer_id' => $customer->id, 'period_id' => $this->period()->id, 'current_reading' => '121', 'status' => 'submitted'])->assertRedirect()->assertSessionHasNoErrors();
        $reading = Reading::firstOrFail();
        $this->post(route('readings.verify', $reading), ['decision' => 'approve'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), ['reading_ids' => [$reading->id], 'action' => 'issue'])->assertRedirect()->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $snapshot = $invoice->customer_snapshot;
        $customer->rt->update(['active' => false]);
        $customer->category->update(['active' => false]);
        $this->put(route('customers.update', $customer), $this->customerData(['rt_id' => $customer->rt_id, 'category_id' => $customer->category_id, 'phone' => '08199999999', 'status' => 'inactive', 'reason' => 'Perubahan kontak dan penghentian langganan']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('08199999999', $customer->fresh()->phone);
        $this->assertSame('inactive', $customer->fresh()->status);
        $this->assertSame($customer->rt_id, $customer->fresh()->rt_id);
        $this->assertSame($customer->category_id, $customer->fresh()->category_id);
        $this->assertSame($snapshot, $invoice->fresh()->customer_snapshot);
        $this->assertSame($snapshot, $reading->fresh()->customer_snapshot);
        $this->assertSame(48000, $invoice->fresh()->total);
        $this->assertDatabaseCount('customer_histories', 1);
    }

    public function test_customer_reassignment_to_new_archived_rt_or_category_is_rejected(): void
    {
        $customer = $this->customer();
        $originalRt = $customer->rt_id;
        $originalCategory = $customer->category_id;
        $archivedRt = Rt::where('code', '02')->firstOrFail();
        $archivedRt->update(['active' => false]);
        $archivedCategory = Category::where('name', 'Fasilitas Umum')->firstOrFail();
        $archivedCategory->update(['active' => false]);
        $this->actingAs($this->admin)->put(route('customers.update', $customer), $this->customerData(['rt_id' => $archivedRt->id, 'reason' => 'Pindah ke RT yang sudah diarsipkan']))->assertRedirect()->assertSessionHasErrors('rt_id');
        $this->put(route('customers.update', $customer), $this->customerData(['category_id' => $archivedCategory->id, 'reason' => 'Pindah ke kategori yang sudah diarsipkan']))->assertRedirect()->assertSessionHasErrors('category_id');
        $this->assertSame($originalRt, $customer->fresh()->rt_id);
        $this->assertSame($originalCategory, $customer->fresh()->category_id);
        $this->assertDatabaseCount('customer_histories',0);
    }

    public function test_new_customer_cannot_use_archived_rt_or_category(): void
    {
        $rt = Rt::where('code','01')->firstOrFail();
        $rt->update(['active' => false]);
        $this->actingAs($this->admin)->post(route('customers.store'),$this->customerData())->assertRedirect()->assertSessionHasErrors('rt_id');
        $rt->update(['active' => true]);
        Category::where('name','Rumah')->update(['active' => false]);
        $this->post(route('customers.store'),$this->customerData())->assertRedirect()->assertSessionHasErrors('category_id');
        $this->assertDatabaseCount('customers',0);
        $this->assertDatabaseCount('meters',0);
        $this->assertDatabaseCount('customer_histories',0);
    }
}
