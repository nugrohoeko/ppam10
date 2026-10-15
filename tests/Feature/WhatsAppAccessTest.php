<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppReminder;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Models\WhatsAppBatch;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\TemplateService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(10, 0));
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user(Role::where('slug', 'admin')->firstOrFail());
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_permission_denies_direct_requests_and_hides_customer_send_button(): void
    {
        $customer = $this->customer();
        $role = Role::create(['slug' => 'whatsapp-none', 'name' => 'Tanpa WhatsApp', 'permissions' => ['customers.view']]);
        $this->actingAs($this->user($role));
        $this->get(route('whatsapp.index'))->assertForbidden();
        $this->get(route('whatsapp.history'))->assertForbidden();
        $this->post(route('whatsapp.preview'), ['customer_ids' => [$customer->id]])->assertForbidden();
        $this->post(route('whatsapp.send'), ['batch_uuid' => (string) Str::uuid(), 'confirm' => 1])->assertForbidden();
        $this->put(route('whatsapp.consent', $customer), ['consent' => 1, 'source' => 'Formulir pelanggan'])->assertForbidden();
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee('Kirim WhatsApp');
        $this->assertDatabaseCount('whatsapp_reminders', 0);
        Queue::assertNothingPushed();
    }

    public function test_view_permission_does_not_allow_preview_send_or_consent(): void
    {
        $customer = $this->customer();
        $this->invoice($customer);
        $role = Role::create(['slug' => 'whatsapp-reader', 'name' => 'Pembaca', 'permissions' => ['whatsapp.view']]);
        $this->actingAs($this->user($role))->get(route('whatsapp.index'))->assertOk()
            ->assertSee($customer->name)->assertSee('Belum Terhubung')->assertDontSee('name="customer_ids[]"', false);
        $this->post(route('whatsapp.preview'), ['customer_ids' => [$customer->id]])->assertForbidden();
        $this->post(route('whatsapp.send'))->assertForbidden();
        $this->put(route('whatsapp.consent', $customer), ['consent' => 0])->assertForbidden();
    }

    public function test_operator_cannot_select_preview_or_update_consent_outside_assigned_rt(): void
    {
        $operator = $this->operator();
        $own = $this->customer(['name' => 'Pelanggan wilayah sendiri']);
        $other = $this->customer(['name' => 'Pelanggan wilayah lain', 'rt_id' => Rt::where('code', '02')->value('id')]);
        $this->invoice($own);
        $this->invoice($other);
        $template = $this->template();
        $this->actingAs($operator)->get(route('whatsapp.index'))->assertOk()->assertSee($own->name)->assertDontSee($other->name);
        $this->get(route('whatsapp.index', ['customer_id' => $other->id]))->assertForbidden();
        $this->get(route('whatsapp.index', ['rt_id' => $other->rt_id]))->assertForbidden();
        $this->post(route('whatsapp.preview'), ['customer_ids' => [$own->id, $other->id], 'template_id' => $template->id])->assertForbidden();
        $this->post(route('whatsapp.preview'), ['all_filtered' => 1, 'rt_id' => $other->rt_id, 'template_id' => $template->id])->assertForbidden();
        $this->put(route('whatsapp.consent', $other), ['consent' => 1, 'source' => 'Formulir pelanggan'])->assertForbidden();
        $this->assertDatabaseCount('whatsapp_batches', 0);
        $this->assertSame('Formulir persetujuan pelanggan', $other->fresh()->whatsapp_consent_source);
    }

    public function test_customer_consent_is_bound_to_normalized_number_and_can_be_revoked(): void
    {
        $customer = $this->customer(['phone' => '0812-3456-7890']);
        $this->actingAs($this->admin)->from(route('whatsapp.index'))
            ->put(route('whatsapp.consent', $customer), ['consent' => 1, 'source' => 'Formulir persetujuan tanggal 15 Oktober 2026'])
            ->assertRedirect(route('whatsapp.index'))->assertSessionHasNoErrors();
        $this->assertSame('6281234567890', $customer->fresh()->whatsapp_consent_phone);
        $this->assertSame($this->admin->id, $customer->fresh()->whatsapp_consent_by);
        $this->assertNotNull($customer->fresh()->whatsapp_consent_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.consent.updated', 'subject_id' => $customer->id, 'user_id' => $this->admin->id]);
        $this->put(route('whatsapp.consent', $customer), ['consent' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($customer->fresh()->whatsapp_consent_at);
        $this->assertNull($customer->fresh()->whatsapp_consent_phone);
        $this->assertSame(2, AuditLog::where('action', 'whatsapp.consent.updated')->count());
    }

    public function test_consent_requires_valid_phone_and_documented_source(): void
    {
        $customer = $this->customer(['phone' => 'nomor tidak valid', 'whatsapp_consent_at' => null, 'whatsapp_consent_phone' => null, 'whatsapp_consent_source' => null]);
        $this->actingAs($this->admin)->put(route('whatsapp.consent', $customer), ['consent' => 1, 'source' => 'Formulir pelanggan'])->assertSessionHasErrors('consent');
        $customer->update(['phone' => '081234567890']);
        $this->put(route('whatsapp.consent', $customer), ['consent' => 1])->assertSessionHasErrors('source');
        $this->assertNull($customer->fresh()->whatsapp_consent_at);
    }

    public function test_preview_merges_customer_invoices_and_confirmation_is_not_sent_twice(): void
    {
        $this->connected();
        $customer = $this->customer();
        $this->invoice($customer, '2026-08');
        $this->invoice($customer, '2026-09');
        $template = $this->template();
        $this->actingAs($this->admin)->post(route('whatsapp.preview'), ['customer_ids' => [$customer->id], 'template_id' => $template->id])
            ->assertOk()->assertSee('1 dari 1 pelanggan')->assertSee($customer->name)->assertSee('2026-08')->assertSee('2026-09')->assertSee('Rp 90.000');
        $batch = WhatsAppBatch::sole();
        $this->assertSame([$customer->id], $batch->customer_ids);
        $this->assertDatabaseCount('whatsapp_reminders', 0);
        $this->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid])->assertSessionHasErrors('confirm');
        $this->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertRedirect(route('whatsapp.history'))->assertSessionHasNoErrors();
        $this->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertRedirect(route('whatsapp.history'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('whatsapp_reminders', 1);
        $this->assertSame('queued', WhatsAppReminder::sole()->status);
        Queue::assertPushed(SendWhatsAppReminder::class, 1);
        Http::assertNothingSent();
    }

    public function test_paid_customer_is_excluded_when_confirming_existing_preview(): void
    {
        $this->connected();
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        $template = $this->template();
        $this->actingAs($this->admin)->post(route('whatsapp.preview'), ['customer_ids' => [$customer->id], 'template_id' => $template->id])->assertOk();
        $batch = WhatsAppBatch::sole();
        $invoice->update(['paid_amount' => $invoice->total, 'status' => 'paid']);
        $this->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertRedirect(route('whatsapp.history'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('whatsapp_reminders', ['customer_id' => $customer->id, 'status' => 'skipped']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_bulk_preview_includes_all_filter_results_across_pages(): void
    {
        $this->connected();
        $ids = [];
        for ($i = 0; $i < 51; $i++) {
            $customer = $this->customer();
            $ids[] = $customer->id;
            $this->invoice($customer);
        }
        $other = $this->customer(['rt_id' => Rt::where('code', '02')->value('id')]);
        $this->invoice($other);
        $template = $this->template();
        $rtId = Rt::where('code', '01')->value('id');
        $response = $this->actingAs($this->admin)->get(route('whatsapp.index', ['rt_id' => $rtId]))->assertOk()
            ->assertSee('Pratinjau semua hasil filter (51)');
        $this->assertSame(50, substr_count($response->getContent(), 'class="whatsapp-recipient"'));
        $this->post(route('whatsapp.preview'), ['all_filtered' => 1, 'rt_id' => $rtId, 'template_id' => $template->id])
            ->assertOk()->assertSee('51 dari 51 pelanggan');
        $batch = WhatsAppBatch::sole();
        $this->assertCount(51, $batch->customer_ids);
        $this->assertEqualsCanonicalizing($ids, $batch->customer_ids);
        $this->assertNotContains($other->id, $batch->customer_ids);
        Queue::assertNothingPushed();
    }

    public function test_confirmation_rejects_other_actor_expired_batch_and_changed_template(): void
    {
        $this->connected();
        $customer = $this->customer();
        $this->invoice($customer);
        $template = $this->template();
        $this->actingAs($this->admin)->post(route('whatsapp.preview'), ['customer_ids' => [$customer->id], 'template_id' => $template->id])->assertOk();
        $batch = WhatsAppBatch::sole();
        $other = $this->user($this->admin->role);
        $this->actingAs($other)->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertForbidden();
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->admin)->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertSessionHasErrors('batch_uuid');
        $batch->update(['expires_at' => now()->addMinutes(10)]);
        $template->update(['body' => $template->body.' Terima kasih.']);
        $this->post(route('whatsapp.send'), ['batch_uuid' => $batch->uuid, 'confirm' => 1])->assertSessionHasErrors('template_id');
        $this->assertNull($batch->fresh()->confirmed_at);
        $this->assertDatabaseCount('whatsapp_reminders', 0);
    }

    public function test_history_obeys_current_operator_scope_and_preserves_archived_customer_snapshot(): void
    {
        $own = $this->customer(['name' => 'Identitas baru pelanggan']);
        $other = $this->customer(['name' => 'Tidak boleh dilihat', 'rt_id' => Rt::where('code', '02')->value('id')]);
        $template = $this->template();
        foreach ([$own, $other] as $customer) {
            WhatsAppReminder::create([
                'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'actor_id' => $this->admin->id, 'template_id' => $template->id,
                'dedup_key' => hash('sha256', 'history-'.$customer->id), 'status' => 'delivered', 'phone' => '6281234567890',
                'body' => 'Pesan riwayat '.$customer->name, 'customer_snapshot' => ['number' => $customer->number, 'name' => $customer->id === $own->id ? 'Nama pada saat pengiriman' : $customer->name],
                'invoice_ids' => [], 'template_snapshot' => [], 'queued_at' => now(),
            ]);
        }
        $own->delete();
        $this->actingAs($this->operator())->get(route('whatsapp.history'))->assertOk()->assertSee('Nama pada saat pengiriman')->assertDontSee($other->name);
        $this->get(route('whatsapp.history', ['customer_id' => $other->id]))->assertForbidden();
        $this->get(route('whatsapp.history', ['customer_id' => $own->id]))->assertOk();
    }

    private function connected(): void
    {
        WhatsAppSetting::current()->update([
            'phone_number_id' => '123456', 'waba_id' => '654321', 'access_token' => 'test-token-never-sent',
            'app_secret' => 'test-secret', 'verify_token' => 'test-verify', 'connected_at' => now(),
            'pam_name' => 'PAM RW.10', 'payment_account' => 'Bank PAM 1234', 'treasurer_contact' => '081234567890',
        ]);
    }

    private function template(): WhatsAppTemplate
    {
        $body = 'Yth. {nama_pelanggan}, ID {id_pelanggan}, RT {rt}. {rincian_tunggakan} Total {total_tunggakan}. Bayar ke {rekening_pembayaran}; kontak {kontak_bendahara}.';
        $template = WhatsAppTemplate::create(['name' => 'Pengingat uji', 'provider_name' => 'reminder_test', 'language' => 'id', 'body' => $body, 'variables' => app(TemplateService::class)->variables($body), 'active' => true, 'status' => 'APPROVED']);
        $template->update(['synced_body_hash' => app(TemplateService::class)->bodyHash($template), 'last_synced_at' => now(), 'provider_template_id' => '777777']);

        return $template;
    }

    private function user(Role $role): User
    {
        return User::create(['name' => 'Pengguna uji '.User::count(), 'email' => 'whatsapp-user-'.User::count().'@example.test', 'password' => 'Test-password-unique', 'role_id' => $role->id, 'active' => true]);
    }

    private function operator(): User
    {
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => ['whatsapp.view', 'whatsapp.send', 'whatsapp.consent']]);
        $operator = $this->user($role);
        $operator->rts()->sync([Rt::where('code', '01')->value('id')]);

        return $operator;
    }

    private function customer(array $overrides = []): Customer
    {
        $customer = Customer::create(array_replace([
            'number' => 'WA-'.(Customer::withTrashed()->count() + 1), 'name' => 'Pelanggan WhatsApp '.Customer::count(), 'address' => 'RW.10',
            'rt_id' => Rt::where('code', '01')->value('id'), 'category_id' => Category::where('name', 'Rumah')->value('id'),
            'phone' => '081234567890', 'status' => 'active', 'joined_on' => '2026-01-01',
            'whatsapp_consent_at' => now(), 'whatsapp_consent_source' => 'Formulir persetujuan pelanggan', 'whatsapp_consent_phone' => '6281234567890', 'whatsapp_consent_by' => $this->admin->id,
        ], $overrides));
        $customer->meters()->create(['serial' => 'WA-METER-'.$customer->id, 'initial_reading' => 0, 'installed_on' => '2026-01-01', 'active' => true]);

        return $customer;
    }

    private function invoice(Customer $customer, string $month = '2026-09'): Invoice
    {
        $date = Carbon::parse($month.'-01');
        $period = Period::firstOrCreate(['name' => $month], ['starts_on' => $date->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString(), 'due_date' => $date->copy()->day(20)->toDateString()]);
        $snapshot = $customer->only('number', 'name', 'address', 'rt_id', 'category_id') + ['rt_name' => $customer->rt->name, 'category_name' => $customer->category->name];
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $customer->activeMeter->id, 'period_id' => $period->id, 'user_id' => $this->admin->id, 'previous_reading' => 0, 'current_reading' => 20, 'usage' => 20, 'status' => 'locked', 'customer_snapshot' => $snapshot]);

        return Invoice::create(['number' => 'WA-TAG-'.$customer->id.'-'.$month, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::where('category_id', $customer->category_id)->value('id'), 'customer_snapshot' => $snapshot, 'tariff_snapshot' => [], 'lines' => [], 'usage' => 20, 'water_amount' => 45000, 'total' => 45000, 'paid_amount' => 0, 'status' => 'issued', 'due_date' => $period->due_date, 'issued_at' => now(), 'user_id' => $this->admin->id]);
    }
}
