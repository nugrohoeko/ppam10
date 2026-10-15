<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\CloudApiService;
use App\Services\WhatsApp\TemplateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = $this->user(Role::where('slug', 'admin')->firstOrFail());
        Http::preventStrayRequests();
    }

    public function test_templates_reject_unknown_and_malformed_variables_and_reset_approval_after_edits(): void
    {
        $this->actingAs($this->admin);
        foreach (['Halo {nomor_rahasia}', 'Halo {{nama_pelanggan}}', 'Halo {nama_pelanggan'] as $body) {
            $this->post(route('whatsapp.templates.store'), $this->templateData(['body' => $body]))->assertSessionHasErrors('body');
        }
        $this->assertDatabaseCount('whatsapp_templates', 0);
        $template = $this->template();
        $this->put(route('whatsapp.templates.update', $template), $this->templateData(['body' => 'Yth {id_pelanggan}, total {total_tunggakan}.']))->assertRedirect()->assertSessionHasNoErrors();
        $template->refresh();
        $this->assertSame('DRAFT', $template->status);
        $this->assertNull($template->synced_body_hash);
        $this->get(route('whatsapp.templates'))->assertOk()->assertSee('Status persetujuan tidak dapat diubah manual.');
    }

    public function test_rendering_preserves_parameter_order_and_flags_missing_data(): void
    {
        $template = new WhatsAppTemplate(['provider_name' => 'billing', 'language' => 'id', 'body' => 'Yth {nama_pelanggan}, {rincian_tunggakan}. Total {total_tunggakan}, {nama_pelanggan}.']);
        $rendered = app(TemplateService::class)->render($template, ['nama_pelanggan' => 'Budi', 'rincian_tunggakan' => "Juli: Rp45.000;\nAgustus: Rp50.000", 'total_tunggakan' => 'Rp95.000']);
        $this->assertSame(['Budi', 'Juli: Rp45.000; Agustus: Rp50.000', 'Rp95.000', 'Budi'], $rendered['parameters']);
        $this->assertSame([], $rendered['errors']);
        $this->assertStringContainsString('Total Rp95.000, Budi.', $rendered['body']);
        $this->assertSame('Yth {{1}}, {{2}}. Total {{3}}, {{4}}.', app(TemplateService::class)->providerBody($template->body));
        $missing = app(TemplateService::class)->render($template, ['nama_pelanggan' => 'Budi']);
        $this->assertCount(2, $missing['errors']);
        $this->assertStringContainsString('[DATA BELUM TERSEDIA]', $missing['body']);
        $hash = TemplateService::bodyHash($template);
        $template->body = str_replace('{nama_pelanggan}', '{id_pelanggan}', $template->body);
        $this->assertNotSame($hash, TemplateService::bodyHash($template));
    }

    public function test_approval_is_synced_only_when_provider_body_exactly_matches(): void
    {
        $settings = $this->settings();
        $template = $this->template(['status' => 'DRAFT', 'synced_body_hash' => null]);
        Http::fakeSequence()->push(['data' => [['id' => '55', 'name' => $template->provider_name, 'language' => 'id', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Wrong provider body']]]]])->push(['data' => [['id' => '55', 'name' => $template->provider_name, 'language' => 'id', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => app(TemplateService::class)->providerBody($template->body)]]]]]);
        $this->actingAs($this->admin)->post(route('whatsapp.templates.sync', $template), ['action' => 'sync'])->assertSessionHasErrors('template');
        $this->assertSame('MISMATCH', $template->fresh()->status);
        $this->assertNull($template->fresh()->synced_body_hash);
        $this->post(route('whatsapp.templates.sync', $template), ['action' => 'sync'])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $template->fresh()->status);
        $this->assertSame(TemplateService::bodyHash($template), $template->fresh()->synced_body_hash);
    }

    public function test_direct_cloud_api_uses_approved_template_and_returns_provider_id_without_retry(): void
    {
        $settings = $this->settings();
        $template = $this->template();
        $uuid = (string) Str::uuid();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test-accepted']]])]);
        $this->assertSame('wamid.test-accepted', app(CloudApiService::class)->send($settings, $template, '6281234567890', ['Budi', 'Rp45.000'], $uuid));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://graph.facebook.com/v25.0/123/messages' && $request['type'] === 'template' && $request['biz_opaque_callback_data'] === $uuid && $request['template']['components'][0]['parameters'][1]['text'] === 'Rp45.000');
        Http::assertSentCount(1);
    }

    public function test_submission_uses_positional_utility_body_and_sync_failure_revokes_approval(): void
    {
        $settings = $this->settings();
        $template = $this->template(['status' => 'DRAFT', 'synced_body_hash' => null]);
        Http::fakeSequence()->push(['id' => '991', 'status' => 'APPROVED'])->push(['error' => ['code' => 190, 'message' => 'sensitive-details']], 401);
        $this->actingAs($this->admin)->post(route('whatsapp.templates.sync', $template), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('PENDING', $template->fresh()->status);
        $this->assertNull($template->fresh()->synced_body_hash);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['category'] === 'UTILITY' && $request['parameter_format'] === 'POSITIONAL' && $request['components'][0]['text'] === 'Yth {{1}}, total {{2}}.' && $request['components'][0]['example']['body_text'][0] === ['Budi', 'Rp45.000']);
        $template->update(['status' => 'APPROVED', 'synced_body_hash' => TemplateService::bodyHash($template)]);
        $this->post(route('whatsapp.templates.sync', $template), ['action' => 'sync'])->assertSessionHasErrors('template');
        $this->assertSame('UNKNOWN', $template->fresh()->status);
        $this->assertNull($template->fresh()->synced_body_hash);
        $this->assertStringNotContainsString('sensitive-details', json_encode(session('errors')->all()));
    }

    public function test_unconfigured_and_modified_templates_are_rejected_before_any_api_call(): void
    {
        Http::fake();
        $settings = $this->settings(['connected_at' => null]);
        $template = $this->template();
        try {
            app(CloudApiService::class)->send($settings, $template, '6281234567890', ['Budi', 'Rp45.000'], (string) Str::uuid());
            $this->fail('An unconfigured integration must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Belum Terhubung', $exception->getMessage());
        }
        $settings->connected_at = now();
        $template->body = 'Yth {id_pelanggan}, total {total_tunggakan}.';
        try {
            app(CloudApiService::class)->send($settings, $template, '6281234567890', ['PAM10-1', 'Rp45.000'], (string) Str::uuid());
            $this->fail('A template changed after approval must be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Sinkronkan ulang', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_message_id_and_server_error_are_uncertain_and_never_automatically_retried(): void
    {
        foreach ([[200, ['success' => true]], [500, ['error' => ['message' => 'sensitive-provider-detail']]]] as [$status, $payload]) {
            Http::fake(['graph.facebook.com/*' => Http::response($payload, $status)]);
            try {
                app(CloudApiService::class)->send($this->settings(), $this->template(), '6281234567890', ['Budi', 'Rp45.000'], (string) Str::uuid());
                $this->fail('Uncertain provider acceptance must throw.');
            } catch (ConnectionException $exception) {
                $this->assertStringNotContainsString('sensitive-provider-detail', $exception->getMessage());
            }
            Http::assertSentCount(1);
        }
    }

    public function test_settings_encrypt_and_hide_secrets_and_invalidate_old_account_approval(): void
    {
        $settings = $this->settings();
        $template = $this->template();
        $this->assertNotSame('provider-token-test-only', DB::table('whatsapp_settings')->value('access_token'));
        $this->assertArrayNotHasKey('access_token', $settings->toArray());
        $this->actingAs($this->admin)->get(route('whatsapp.settings'))->assertOk()->assertDontSee('provider-token-test-only')->assertDontSee('app-secret-test-only');
        $this->put(route('whatsapp.settings.update'), ['provider' => 'meta', 'graph_version' => 'v25.0', 'phone_number_id' => '124', 'waba_id' => '456', 'access_token' => '', 'app_secret' => '', 'verify_token' => '', 'pam_name' => 'PAM RW.10'])->assertSessionHasNoErrors();
        $this->assertNull($settings->fresh()->connected_at);
        $this->assertSame('provider-token-test-only', $settings->fresh()->access_token);
        $this->assertFalse($settings->fresh()->auto_enabled);
        $this->assertSame('DRAFT', $template->fresh()->status);
        $this->assertNull($template->fresh()->synced_body_hash);
        $this->assertStringNotContainsString('provider-token-test-only', AuditLog::get()->toJson());
        $this->put(route('whatsapp.settings.update'), ['provider' => 'bad', 'access_token' => 'NEVER-FLASH-THIS-TOKEN', 'app_secret' => 'NEVER-FLASH-THIS-SECRET'])->assertSessionHasErrors();
        $this->assertStringNotContainsString('NEVER-FLASH', json_encode(session()->getOldInput()));
    }

    public function test_schedule_and_configuration_permissions_are_independent_and_checked_on_server(): void
    {
        $configureRole = Role::create(['slug' => 'wa-config', 'name' => 'Konfigurasi', 'permissions' => ['whatsapp.configure']]);
        $configure = $this->user($configureRole);
        $this->actingAs($configure)->get(route('whatsapp.settings'))->assertOk()->assertSee('name="access_token"', false)->assertDontSee('name="auto_enabled"', false);
        $this->put(route('whatsapp.settings.update'), $this->scheduleData())->assertForbidden();
        $scheduleRole = Role::create(['slug' => 'wa-schedule', 'name' => 'Jadwal', 'permissions' => ['whatsapp.schedule']]);
        $schedule = $this->user($scheduleRole);
        $this->actingAs($schedule)->get(route('whatsapp.settings'))->assertOk()->assertSee('name="auto_enabled"', false)->assertDontSee('name="access_token"', false);
        $this->put(route('whatsapp.settings.update'), $this->scheduleData())->assertSessionHasNoErrors();
        $this->put(route('whatsapp.settings.update'), ['provider' => 'meta'])->assertForbidden();
        $this->post(route('whatsapp.settings.connect'))->assertForbidden();
        $this->post(route('whatsapp.templates.store'), $this->templateData())->assertForbidden();
        $scheduleRole->update(['permissions' => []]);
        $this->get(route('whatsapp.settings'))->assertForbidden();
    }

    public function test_connection_verification_checks_number_membership_and_never_sends_a_message(): void
    {
        $settings = $this->settings(['connected_at' => null]);
        Http::fake(['*/123?*' => Http::response(['id' => '123', 'verified_name' => 'PAM Resmi']), '*/456/phone_numbers*' => Http::response(['data' => [['id' => '123']]])]);
        $this->actingAs($this->admin)->post(route('whatsapp.settings.connect'))->assertSessionHasNoErrors();
        $this->assertNotNull($settings->fresh()->connected_at);
        $this->assertSame('PAM Resmi', $settings->fresh()->sender_name);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_webhook_requires_valid_signature_and_updates_status_monotonically(): void
    {
        $settings = $this->settings();
        $reminder = $this->reminder();
        $payload = $this->statusPayload($reminder, 'read');
        $this->postJson(route('whatsapp.webhook.receive'), $payload)->assertForbidden();
        $this->signed($payload)->assertOk();
        $this->assertSame('read', $reminder->fresh()->status);
        $this->assertSame((int) $payload['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp'], $reminder->fresh()->last_status_at->timestamp);
        $this->assertNotNull($reminder->fresh()->delivered_at);
        $this->assertNull($reminder->fresh()->active_key);
        $this->signed($this->statusPayload($reminder, 'sent'))->assertOk();
        $this->assertSame('read', $reminder->fresh()->status);
        $this->signed($this->statusPayload($reminder, 'failed'))->assertOk();
        $this->assertSame('read', $reminder->fresh()->status);
    }

    public function test_signed_webhook_correlates_uncertain_uuid_and_ignores_wrong_sender(): void
    {
        $this->settings();
        $reminder = $this->reminder(['provider_message_id' => null, 'status' => 'unknown']);
        $payload = $this->statusPayload($reminder, 'delivered');
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = 'other';
        $this->signed($payload)->assertOk();
        $this->assertSame('unknown', $reminder->fresh()->status);
        $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] = '123';
        $this->signed($payload)->assertOk();
        $this->assertSame('delivered', $reminder->fresh()->status);
        $this->assertSame('wamid.webhook-test', $reminder->fresh()->provider_message_id);
        $this->assertNull($reminder->fresh()->active_key);
    }

    public function test_webhook_verification_and_customer_optout(): void
    {
        $this->settings();
        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-token-test-only&hub.challenge=12345')->assertOk()->assertContent('12345');
        $this->get('/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')->assertForbidden();
        $customer = $this->customer();
        $this->signed(['object' => 'whatsapp_business_account', 'entry' => [['id' => '456', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], 'messages' => [['from' => '6281234567890', 'type' => 'text', 'text' => ['body' => 'BERHENTI']]]]]]]]])->assertOk();
        $this->assertNull($customer->fresh()->whatsapp_consent_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.consent.revoked', 'subject_id' => $customer->id]);
    }

    public function test_template_webhook_requires_readback_and_stale_sent_cannot_resurrect_failed_message(): void
    {
        $this->settings();
        $template = $this->template(['provider_template_id' => '555']);
        $this->signed(['object' => 'whatsapp_business_account', 'entry' => [['id' => '456', 'changes' => [['field' => 'message_template_status_update', 'value' => ['event' => 'APPROVED', 'message_template_id' => '555']]]]]])->assertOk();
        $this->assertSame('APPROVED', $template->fresh()->status);
        $this->assertNull($template->fresh()->synced_body_hash);
        $reminder = $this->reminder(['status' => 'failed', 'last_status_at' => now(), 'active_key' => null]);
        $payload = $this->statusPayload($reminder, 'sent');
        $payload['entry'][0]['changes'][0]['value']['statuses'][0]['timestamp'] = (string) now()->subMinute()->timestamp;
        $this->signed($payload)->assertOk();
        $this->assertSame('failed', $reminder->fresh()->status);
    }

    private function settings(array $override = []): WhatsAppSetting
    {
        $settings = WhatsAppSetting::current();
        $settings->update(array_replace(['provider' => 'meta', 'graph_version' => 'v25.0', 'phone_number_id' => '123', 'waba_id' => '456', 'access_token' => 'provider-token-test-only', 'app_secret' => 'app-secret-test-only', 'verify_token' => 'verify-token-test-only', 'connected_at' => now(), 'pam_name' => 'PAM RW.10'], $override));

        return $settings;
    }

    private function template(array $override = []): WhatsAppTemplate
    {
        $template = WhatsAppTemplate::create(array_replace($this->templateData(), ['provider_name' => 'pengingat_tagihan_'.WhatsAppTemplate::count(), 'variables' => ['nama_pelanggan', 'total_tunggakan'], 'status' => 'APPROVED', 'last_synced_at' => now()], $override));
        if (! array_key_exists('synced_body_hash', $override)) {
            $template->update(['synced_body_hash' => TemplateService::bodyHash($template)]);
        }

        return $template;
    }

    private function templateData(array $override = []): array
    {
        return array_replace(['name' => 'Pengingat tagihan', 'provider_name' => 'pengingat_tagihan_v1', 'language' => 'id', 'body' => 'Yth {nama_pelanggan}, total {total_tunggakan}.', 'active' => 1], $override);
    }

    private function scheduleData(): array
    {
        return ['auto_enabled' => 0, 'send_time' => '09:00', 'before_days' => 3, 'on_due' => 1, 'after_days' => 3, 'repeat_days' => 7, 'max_reminders' => 3, 'interval_seconds' => 30];
    }

    private function user(Role $role): User
    {
        return User::create(['name' => 'Pengguna WA', 'email' => 'wa-provider-'.User::count().'@example.test', 'password' => Str::random(32), 'role_id' => $role->id, 'active' => true]);
    }

    private function customer(): Customer
    {
        return Customer::create(['number' => 'WA-'.Str::random(10), 'name' => 'Pelanggan WA', 'address' => 'RW.10', 'rt_id' => Rt::firstOrFail()->id, 'category_id' => Category::firstOrFail()->id, 'status' => 'active', 'joined_on' => today(), 'phone' => '081234567890', 'whatsapp_consent_phone' => '6281234567890', 'whatsapp_consent_at' => now(), 'whatsapp_consent_source' => 'Form pelanggan']);
    }

    private function reminder(array $override = []): WhatsAppReminder
    {
        $customer = $this->customer();

        return WhatsAppReminder::create(array_replace(['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'template_id' => $this->template()->id, 'actor_id' => $this->admin->id, 'trigger' => 'manual', 'dedup_key' => hash('sha256', Str::random()), 'active_key' => 'customer:'.$customer->id, 'status' => 'submitted', 'phone' => '6281234567890', 'body' => 'Pengingat tagihan', 'invoice_ids' => [], 'template_snapshot' => [], 'provider_message_id' => 'wamid.webhook-test', 'queued_at' => now()], $override));
    }

    private function statusPayload(WhatsAppReminder $reminder, string $status): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '456', 'changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '123'], 'statuses' => [['id' => 'wamid.webhook-test', 'status' => $status, 'timestamp' => (string) now()->timestamp, 'recipient_id' => '6281234567890', 'biz_opaque_callback_data' => $reminder->uuid]]]]]]]];
    }

    private function signed(array $payload)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret-test-only')], $body);
    }
}
