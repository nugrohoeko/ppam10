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
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\TemplateService;
use App\Support\PhoneNumber;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppRecipientTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private WhatsAppTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::create(['name' => 'Pengurus uji', 'email' => 'wa-recipient@example.test', 'password' => 'Tests-only-password-2026', 'role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        WhatsAppSetting::current()->update(['phone_number_id' => '123', 'waba_id' => '456', 'access_token' => 'test-token', 'app_secret' => 'test-secret', 'verify_token' => 'test-verify', 'connected_at' => now(), 'payment_account' => 'Bank PAM 1234', 'treasurer_contact' => '081234567890']);
        $body = "Yth. {nama_pelanggan}, pelanggan {nama_pam} ID {id_pelanggan}, RT {rt}.\n{rincian_tunggakan}\nTotal: {total_tunggakan}. Bayar melalui {rekening_pembayaran}; hubungi {kontak_bendahara}.";
        $this->template = WhatsAppTemplate::create(['name' => 'Tagihan uji', 'provider_name' => 'tagihan_uji', 'language' => 'id', 'body' => $body, 'variables' => app(TemplateService::class)->variables($body), 'active' => true, 'status' => 'APPROVED', 'last_synced_at' => now()]);
        $this->template->update(['synced_body_hash' => TemplateService::bodyHash($this->template)]);
    }

    public function test_filters_qualify_customers_and_preview_merges_every_outstanding_period_once(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer, '2026-08', '2026-09-20', 45000, 10000);
        $new = $this->invoice($customer, '2026-09', '2026-10-20', 60000);
        $paid = $this->invoice($customer, '2026-07', '2026-08-20', 45000, 45000);
        $draft = $this->invoice($customer, '2026-06', '2026-07-20', 90000);
        $draft->update(['status' => 'draft']);
        $service = app(RecipientService::class);
        $qualified = $service->eligible($this->admin, ['period_id' => $old->period_id, 'status' => 'partial', 'min_days' => 20]);
        $this->assertSame([$customer->id], $qualified->modelKeys());
        $preview = $service->preview($this->admin, $customer, $this->template);
        $this->assertSame([$old->id, $new->id], $preview['invoice_ids']);
        $this->assertSame(95000, $preview['balance']);
        $this->assertSame([], $preview['errors']);
        $this->assertStringContainsString('Rp 35.000', $preview['body']);
        $this->assertStringContainsString('Rp 60.000', $preview['body']);
        $this->assertStringContainsString('Total: Rp 95.000', $preview['body']);
        $this->assertStringContainsString($customer->number, $preview['body']);
        $this->assertStringNotContainsString($paid->number, $preview['body']);
        $this->assertStringNotContainsString('{', $preview['body']);
        $this->assertCount(8, $preview['parameters']);
        foreach ($preview['parameters'] as $value) {
            $this->assertDoesNotMatchRegularExpression('/[\n\r\t]/', $value);
        }
        $this->assertCount(0, $service->eligible($this->admin, ['rt_id' => Rt::where('code', '02')->value('id')]));
        $this->assertCount(0, $service->eligible($this->admin, ['period_id' => $new->period_id, 'status' => 'partial']));
        $this->assertCount(0, $service->eligible($this->admin, ['min_days' => 26]));
    }

    public function test_preview_reloads_balance_and_excludes_paid_customers_without_using_stale_relations(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-09', '2026-10-10', 50000);
        $customer->load('invoices');
        $invoice->update(['paid_amount' => 50000, 'status' => 'paid']);
        $preview = app(RecipientService::class)->preview($this->admin, $customer, $this->template);
        $this->assertSame(0, $preview['balance']);
        $this->assertSame([], $preview['invoice_ids']);
        $this->assertStringContainsString('sudah lunas', implode(' ', $preview['errors']));
        $this->assertCount(0, app(RecipientService::class)->eligible($this->admin));
    }

    public function test_missing_payment_data_and_changed_phone_consent_are_visible_and_block_sending(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', '2026-10-10', 50000);
        WhatsAppSetting::current()->update(['payment_account' => '', 'treasurer_contact' => null, 'connected_at' => null]);
        $customer->update(['phone' => '081399999999']);
        $preview = app(RecipientService::class)->preview($this->admin, $customer, $this->template);
        $errors = implode(' ', $preview['errors']);
        $this->assertStringContainsString('rekening_pembayaran', $errors);
        $this->assertStringContainsString('kontak_bendahara', $errors);
        $this->assertStringContainsString('Persetujuan', $errors);
        $this->assertStringContainsString('Belum Terhubung', $errors);
        $this->assertStringContainsString('[DATA BELUM TERSEDIA]', $preview['body']);
        $this->assertSame('6281399999999', $preview['phone']);
    }

    public function test_archived_customers_are_excluded_and_previous_grants_cannot_bypass_current_scope(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', '2026-10-10', 50000);
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => ['whatsapp.view', 'whatsapp.send']]);
        $operator = User::create(['name' => 'Operator uji', 'email' => 'wa-scope@example.test', 'password' => 'Tests-only-password-2026', 'role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach($customer->rt_id);
        $this->assertCount(1, app(RecipientService::class)->eligible($operator));
        $operator->rts()->detach();
        $this->assertCount(0, app(RecipientService::class)->eligible($operator));
        $customer->delete();
        $this->assertCount(0, app(RecipientService::class)->eligible($this->admin));
        $preview = app(RecipientService::class)->preview($this->admin, $customer, $this->template);
        $this->assertStringContainsString('diarsipkan', implode(' ', $preview['errors']));
    }

    public function test_phone_normalization_rejects_extensions_non_indonesian_and_malformed_numbers(): void
    {
        foreach (['0812-3456-7890', '+62 812 3456 7890', '6281234567890', '81234567890', '(0812) 3456 7890'] as $value) {
            $this->assertSame('6281234567890', PhoneNumber::normalize($value), $value);
        }
        foreach ([null, '', '123', '+1 202 555 0199', '081234567890 ext 12', '62+81234567890', '++6281234567890', '6280123456789', '628123456789012345'] as $value) {
            $this->assertNull(PhoneNumber::normalize($value));
        }
    }

    private function customer(): Customer
    {
        return Customer::create(['number' => 'WA-CUSTOMER-'.(Customer::count() + 1), 'name' => 'Ibu Pelanggan', 'address' => 'RW.10', 'rt_id' => Rt::where('code', '01')->value('id'), 'category_id' => Category::firstOrFail()->id, 'phone' => '081234567890', 'joined_on' => '2026-01-01', 'status' => 'active', 'whatsapp_consent_at' => now(), 'whatsapp_consent_source' => 'Formulir persetujuan pelanggan tertulis', 'whatsapp_consent_phone' => '6281234567890', 'whatsapp_consent_by' => $this->admin->id]);
    }

    private function invoice(Customer $customer, string $month, string $due, int $total, int $paid = 0): Invoice
    {
        $period = Period::firstOrCreate(['name' => $month], ['starts_on' => $month.'-01', 'ends_on' => $month.'-28', 'due_date' => $due]);
        $meter = $customer->meters()->firstOrCreate(['serial' => 'WA-METER-'.$customer->id], ['initial_reading' => 0, 'installed_on' => '2026-01-01', 'active' => true]);
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $meter->id, 'period_id' => $period->id, 'user_id' => $this->admin->id, 'previous_reading' => 0, 'current_reading' => 20, 'usage' => 20, 'status' => 'locked', 'customer_snapshot' => $customer->only(['number', 'name'])]);

        return Invoice::create(['number' => 'WA-TAG-'.$customer->id.'-'.$month, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::firstOrFail()->id, 'customer_snapshot' => [], 'tariff_snapshot' => [], 'lines' => [], 'usage' => 20, 'water_amount' => $total, 'total' => $total, 'paid_amount' => $paid, 'status' => $paid === $total ? 'paid' : ($paid > 0 ? 'partial' : 'issued'), 'due_date' => $due, 'issued_at' => now(), 'user_id' => $this->admin->id]);
    }
}
