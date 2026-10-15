<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppReminder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Role;
use App\Models\Rt;
use App\Models\TariffScheme;
use App\Models\User;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\DeliveryService;
use App\Services\WhatsApp\TemplateService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WhatsAppSetting $settings;

    private WhatsAppTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Jakarta'));
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::create(['name' => 'Admin WhatsApp', 'email' => 'wa-admin@example.test', 'password' => 'Test-only-password', 'role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        $this->settings = WhatsAppSetting::current();
        $this->settings->update(['provider' => 'meta', 'phone_number_id' => '123456789', 'waba_id' => '987654321', 'access_token' => 'TOKEN-SHOULD-NEVER-APPEAR-IN-HISTORY', 'app_secret' => 'secret-for-fake-api', 'verify_token' => 'verify-fake', 'connected_at' => now(), 'pam_name' => 'PAM RW.10', 'payment_account' => 'Bank Uji 123 a.n. PAM', 'treasurer_contact' => '6281234567890']);
        $body = 'Yth {nama_pelanggan}, pelanggan {nama_pam} ID {id_pelanggan} RT {rt}. Rincian {rincian_tunggakan}. Total {total_tunggakan}. Bayar {rekening_pembayaran}. Kontak {kontak_bendahara}.';
        $this->template = WhatsAppTemplate::create(['name' => 'Tagihan uji', 'provider_name' => 'pam10_tagihan_uji', 'language' => 'id', 'body' => $body, 'variables' => app(TemplateService::class)->variables($body), 'active' => true, 'status' => 'APPROVED', 'last_synced_at' => now()]);
        $this->template->update(['synced_body_hash' => app(TemplateService::class)->bodyHash($this->template)]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_one_customer_receives_one_direct_api_template_with_all_current_unpaid_balances(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer, '2026-08', 45000);
        $partial = $this->invoice($customer, '2026-09', 30000, 10000);
        $this->accepted();
        $rows = $this->delivery()->enqueue($this->admin, $this->template, [$customer->id, $customer->id], 'campaign-one');
        $this->assertCount(1, $rows);
        Queue::assertPushed(SendWhatsAppReminder::class, 1);
        $this->assertSame('queued', $rows->first()->status);
        $this->assertStringContainsString('Rp 65.000', $rows->first()->body);
        $this->delivery()->process($rows->first()->id);
        $reminder = $rows->first()->fresh();
        $this->assertSame('submitted', $reminder->status);
        $this->assertNull($reminder->sent_at);
        $this->assertSame([$old->id, $partial->id], $reminder->invoice_ids);
        $this->assertSame($customer->number, $reminder->customer_snapshot['number']);
        $this->assertSame(65000, $reminder->balance);
        $this->assertSame('wamid.fake.accepted', $reminder->provider_message_id);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/123456789/messages') && $request['to'] === '6281234567890'
            && $request['type'] === 'template' && $request['biz_opaque_callback_data'] === $reminder->uuid
            && data_get($request->data(), 'template.components.0.parameters.0.text') === $customer->name
            && data_get($request->data(), 'template.components.0.parameters.5.text') === 'Rp 65.000');
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.submitted', 'subject_id' => $reminder->id, 'user_id' => $this->admin->id]);
    }

    public function test_http_preview_confirmation_queue_api_and_signed_webhook_work_together_with_fresh_balance(): void
    {
        $customer = $this->customer();
        $old = $this->invoice($customer, '2026-08', 45000);
        $partial = $this->invoice($customer, '2026-09', 30000, 10000);
        $paidCustomer = $this->customer();
        $paidInvoice = $this->invoice($paidCustomer, '2026-09', 20000);
        $preview = $this->actingAs($this->admin)->post(route('whatsapp.preview'), [
            'customer_ids' => [$customer->id, $paidCustomer->id], 'template_id' => $this->template->id,
        ])->assertOk()->assertSee($customer->name)->assertSee($paidCustomer->name)->assertSee('Rp 65.000');
        $batch = $preview->viewData('batch');
        $this->assertCount(2, $batch->customer_ids);
        $old->update(['paid_amount' => 30000, 'status' => 'partial']);
        $paidInvoice->update(['paid_amount' => 20000, 'status' => 'paid']);
        $confirmation = ['batch_uuid' => $batch->uuid, 'confirm' => '1'];
        $this->post(route('whatsapp.send'), $confirmation)->assertRedirect(route('whatsapp.history'))->assertSessionHasNoErrors();
        $this->post(route('whatsapp.send'), $confirmation)->assertRedirect(route('whatsapp.history'))->assertSessionHasNoErrors();
        $this->assertSame(1, WhatsAppReminder::where('status', 'queued')->count());
        $this->assertSame(1, WhatsAppReminder::where('status', 'skipped')->count());
        $this->assertSame('skipped', WhatsAppReminder::where('customer_id', $paidCustomer->id)->sole()->status);
        Queue::assertPushed(SendWhatsAppReminder::class, 1);
        $reminder = WhatsAppReminder::where('status', 'queued')->sole();
        $this->assertSame(35000, $reminder->balance);
        $this->accepted('wamid.fullflow');
        (new SendWhatsAppReminder($reminder->id))->handle($this->delivery());
        Http::assertSentCount(1);
        $this->assertSame('submitted', $reminder->fresh()->status);
        $this->assertSame([$old->id, $partial->id], $reminder->fresh()->invoice_ids);
        Http::assertSent(fn (Request $request) => $request['to'] === '6281234567890'
            && data_get($request->data(), 'template.components.0.parameters.5.text') === 'Rp 35.000');
        $event = json_encode([
            'object' => 'whatsapp_business_account', 'entry' => [['id' => $this->settings->waba_id, 'changes' => [['field' => 'messages', 'value' => [
                'metadata' => ['phone_number_id' => $this->settings->phone_number_id],
                'statuses' => [['id' => 'wamid.fullflow', 'status' => 'read', 'recipient_id' => $reminder->phone, 'timestamp' => (string) now()->timestamp, 'biz_opaque_callback_data' => $reminder->uuid]],
            ]]]]],
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $event, $this->settings->app_secret);
        $this->call('POST', route('whatsapp.webhook.receive'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $event)->assertOk()->assertJson(['received' => true]);
        $reminder->refresh();
        $this->assertSame('read', $reminder->status);
        $this->assertNotNull($reminder->sent_at);
        $this->assertNotNull($reminder->delivered_at);
        $this->assertNotNull($reminder->read_at);
        $this->get(route('whatsapp.history'))->assertOk()->assertSee('Dibaca')->assertSee('wamid.fullflow');
    }

    public function test_paid_after_confirmation_is_skipped_when_worker_rechecks_balance(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        $invoice->update(['paid_amount' => 45000, 'status' => 'paid']);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertStringContainsString('lunas', $row->fresh()->error_message);
        $this->assertNull($row->fresh()->active_key);
        Http::assertNothingSent();
    }

    public function test_partial_payment_after_confirmation_changes_actual_message_and_amount(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        $invoice->update(['paid_amount' => 30000, 'status' => 'partial']);
        $this->accepted();
        $this->delivery()->process($row->id);
        $this->assertSame(15000, $row->fresh()->balance);
        $this->assertStringContainsString('Rp 15.000', $row->fresh()->body);
        Http::assertSent(fn (Request $request) => data_get($request->data(), 'template.components.0.parameters.5.text') === 'Rp 15.000');
    }

    public function test_campaign_confirmation_and_duplicate_jobs_never_repeat_api_call(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $first = $this->enqueue($customer, 'same-confirmation');
        $second = $this->enqueue($customer, 'same-confirmation');
        $this->assertSame($first->id, $second->id);
        $other = $this->enqueue($customer, 'another-confirmation');
        $this->assertSame('skipped', $other->status);
        $this->accepted();
        $this->delivery()->process($first->id);
        $this->delivery()->process($first->id);
        Http::assertSentCount(1);
        Queue::assertPushed(SendWhatsAppReminder::class, 1);
    }

    public function test_ambiguous_api_timeout_is_unknown_and_can_never_automatically_retry(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        Http::fake(['graph.facebook.com/*' => Http::failedConnection('TOKEN-SHOULD-NEVER-APPEAR-IN-HISTORY')]);
        $this->delivery()->process($row->id);
        $row->refresh();
        $this->assertSame('unknown', $row->status);
        $this->assertSame('customer:'.$customer->id, $row->active_key);
        $this->assertStringNotContainsString('TOKEN-SHOULD', $row->error_message);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $this->enqueue($customer, 'try-again')->status);
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_api_rejection_is_failed_and_does_not_report_success_or_store_secrets(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'TOKEN-SHOULD-NEVER-APPEAR-IN-HISTORY']], 400)]);
        $this->delivery()->process($row->id);
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertNull($row->fresh()->provider_message_id);
        $this->assertNull($row->fresh()->active_key);
        $this->assertStringNotContainsString('TOKEN-SHOULD', $row->fresh()->error_message);
    }

    public function test_new_unconfigured_installation_cannot_queue_a_fake_success(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $this->settings->update(['connected_at' => null]);
        try {
            $this->enqueue($customer);
            $this->fail('Unconfigured integration must reject sending.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Belum Terhubung', $exception->getMessage());
        }
        $this->assertDatabaseCount('whatsapp_reminders', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_permission_and_rt_scope_after_queueing(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        $role = Role::create(['name' => 'Tanpa hak kirim', 'slug' => 'no-send', 'permissions' => ['whatsapp.view']]);
        $this->admin->update(['role_id' => $role->id]);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $row->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_operator_cannot_queue_customer_outside_assigned_rt(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => ['whatsapp.view', 'whatsapp.send']]);
        $this->admin->update(['role_id' => $role->id]);
        $this->admin->rts()->sync([Rt::where('code', '02')->value('id')]);
        $allowed = $this->customer();
        $allowed->update(['rt_id' => Rt::where('code', '02')->value('id')]);
        $this->invoice($allowed, '2026-09', 30000);
        try {
            $this->delivery()->enqueue($this->admin, $this->template, [$allowed->id, $customer->id], 'mixed-scope-batch');
            $this->fail('Operator must be constrained to assigned RT.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('whatsapp_reminders', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_operator_territory_after_queueing(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $role = Role::where('slug', 'operator')->firstOrFail();
        $role->update(['permissions' => ['whatsapp.view', 'whatsapp.send']]);
        $this->admin->update(['role_id' => $role->id]);
        $this->admin->rts()->sync([$customer->rt_id]);
        $row = $this->enqueue($customer);
        $this->admin->rts()->sync([Rt::where('code', '02')->value('id')]);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $row->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_changing_template_or_revoking_consent_after_preview_blocks_processing(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        $customer->update(['whatsapp_consent_at' => null]);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $row->fresh()->status);
        $this->assertStringContainsString('Persetujuan', $row->fresh()->error_message);
        $this->travel(31)->seconds();
        $customer->update(['whatsapp_consent_at' => now()]);
        $row2 = $this->enqueue($customer, 'template-edited');
        $this->template->update(['body' => $this->template->body.' Salam.']);
        $this->delivery()->process($row2->id);
        $this->assertSame('skipped', $row2->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_actual_worker_spacing_survives_jobs_becoming_available_simultaneously(): void
    {
        $one = $this->customer();
        $two = $this->customer();
        $this->invoice($one, '2026-09', 45000);
        $this->invoice($two, '2026-09', 45000);
        $a = $this->enqueue($one, 'spacing');
        $b = $this->enqueue($two, 'spacing');
        $counter = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$counter) {
            return Http::response(['messages' => [['id' => 'wamid.spacing.'.++$counter]]]);
        }]);
        $this->delivery()->process($a->id);
        $delay = $this->delivery()->process($b->id);
        $this->assertSame(30, $delay);
        $this->assertSame('queued', $b->fresh()->status);
        Http::assertSentCount(1);
        $this->travel(31)->seconds();
        $this->delivery()->process($b->id);
        Http::assertSentCount(2);
    }

    public function test_scheduler_respects_time_deduplication_repetition_limits_and_payment(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer, '2026-09', 45000);
        $this->settings->update(['auto_enabled' => true, 'send_time' => '11:00', 'template_id' => $this->template->id, 'run_as_user_id' => $this->admin->id, 'max_reminders' => 2]);
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->travelTo(Carbon::parse('2026-10-15 11:00:00', 'Asia/Jakarta'));
        $this->assertSame(1, $this->delivery()->dispatchScheduled());
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $first = WhatsAppReminder::where('status', 'queued')->sole();
        $this->assertSame('automatic', $first->trigger);
        $this->assertNotNull($first->schedule_key);
        $counter = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$counter) {
            return Http::response(['messages' => [['id' => 'wamid.schedule.'.++$counter]]]);
        }]);
        $this->delivery()->process($first->id);
        $this->travel(6)->days();
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->travel(1)->days();
        $this->assertSame(1, $this->delivery()->dispatchScheduled());
        $second = WhatsAppReminder::where('status', 'queued')->sole();
        $this->delivery()->process($second->id);
        $this->travel(8)->days();
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $invoice->update(['paid_amount' => 45000, 'status' => 'paid']);
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->assertSame(2, WhatsAppReminder::where('status', 'submitted')->count());
    }

    public function test_scheduler_stops_queued_messages_when_automation_is_disabled(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $this->settings->update(['auto_enabled' => true, 'send_time' => '10:00', 'template_id' => $this->template->id, 'run_as_user_id' => $this->admin->id]);
        $this->assertSame(1, $this->delivery()->dispatchScheduled());
        $row = WhatsAppReminder::where('status', 'queued')->sole();
        $this->settings->update(['auto_enabled' => false]);
        $this->delivery()->process($row->id);
        $this->assertSame('skipped', $row->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_scheduler_only_enqueues_during_configured_minute_and_does_not_send_a_late_catchup(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $this->settings->update(['auto_enabled' => true, 'send_time' => '09:00', 'template_id' => $this->template->id, 'run_as_user_id' => $this->admin->id]);
        $this->travelTo(Carbon::parse('2026-10-15 08:59:00', 'Asia/Jakarta'));
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->travelTo(Carbon::parse('2026-10-15 09:00:00', 'Asia/Jakarta'));
        $this->assertSame(1, $this->delivery()->dispatchScheduled());
        $new = $this->customer();
        $this->invoice($new, '2026-09', 45000);
        $this->travelTo(Carbon::parse('2026-10-15 09:01:00', 'Asia/Jakarta'));
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->travelTo(Carbon::parse('2026-10-15 22:00:00', 'Asia/Jakarta'));
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->assertDatabaseMissing('whatsapp_reminders', ['customer_id' => $new->id]);
        Queue::assertPushed(SendWhatsAppReminder::class, 1);
    }

    public function test_admin_can_disable_all_schedule_phases_including_overdue(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $this->settings->update(['auto_enabled' => true, 'send_time' => '10:00', 'before_days' => 0, 'on_due' => false, 'after_days' => 0, 'template_id' => $this->template->id, 'run_as_user_id' => $this->admin->id]);
        $this->assertSame(0, $this->delivery()->dispatchScheduled());
        $this->assertDatabaseCount('whatsapp_reminders', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_crashed_processing_claim_becomes_unknown_and_is_never_resent(): void
    {
        $customer = $this->customer();
        $this->invoice($customer, '2026-09', 45000);
        $row = $this->enqueue($customer);
        $row->update(['status' => 'processing', 'processed_at' => now()->subMinutes(11)]);
        $this->delivery()->dispatchScheduled();
        $this->assertSame('unknown', $row->fresh()->status);
        $this->delivery()->process($row->id);
        Http::assertNothingSent();
    }

    private function delivery(): DeliveryService
    {
        return app(DeliveryService::class);
    }

    private function enqueue(Customer $customer, ?string $key = null): WhatsAppReminder
    {
        return $this->delivery()->enqueue($this->admin, $this->template, [$customer->id], $key ?? (string) Str::uuid())->sole();
    }

    private function accepted(string $id = 'wamid.fake.accepted'): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => $id]]], 200)]);
    }

    private function customer(): Customer
    {
        $customer = Customer::create(['number' => 'WA-'.(Customer::withTrashed()->count() + 1), 'name' => 'Pelanggan WhatsApp '.(Customer::count() + 1), 'address' => 'Jl Air RT01', 'rt_id' => Rt::where('code', '01')->value('id'), 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'phone' => '0812-3456-7890', 'joined_on' => '2026-01-01', 'whatsapp_consent_at' => now(), 'whatsapp_consent_source' => 'Form persetujuan pengingat tagihan ditandatangani pelanggan', 'whatsapp_consent_phone' => '6281234567890', 'whatsapp_consent_by' => $this->admin->id]);
        $customer->meters()->create(['serial' => 'WA-METER-'.$customer->id, 'initial_reading' => 0, 'installed_on' => '2026-01-01', 'active' => true]);

        return $customer;
    }

    private function invoice(Customer $customer, string $month, int $total, int $paid = 0): Invoice
    {
        $date = Carbon::parse($month.'-01');
        $period = Period::firstOrCreate(['name' => $month], ['starts_on' => $date->toDateString(), 'ends_on' => $date->copy()->endOfMonth()->toDateString(), 'due_date' => $date->copy()->addMonth()->day(1)->toDateString()]);
        $snapshot = $customer->only('number', 'name', 'address', 'rt_id', 'category_id');
        $reading = Reading::create(['customer_id' => $customer->id, 'meter_id' => $customer->activeMeter->id, 'period_id' => $period->id, 'user_id' => $this->admin->id, 'previous_reading' => 0, 'current_reading' => 20, 'usage' => 20, 'status' => 'locked', 'customer_snapshot' => $snapshot]);

        return Invoice::create(['number' => 'WA-INV-'.$customer->id.'-'.$month, 'customer_id' => $customer->id, 'reading_id' => $reading->id, 'period_id' => $period->id, 'tariff_scheme_id' => TariffScheme::where('category_id', $customer->category_id)->value('id'), 'customer_snapshot' => $snapshot, 'tariff_snapshot' => [], 'lines' => [], 'usage' => 20, 'water_amount' => $total, 'total' => $total, 'paid_amount' => $paid, 'status' => $paid > 0 ? 'partial' : 'issued', 'due_date' => $period->due_date, 'issued_at' => now(), 'user_id' => $this->admin->id]);
    }
}
