<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendWhatsAppReminder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A durable outbox. An uncertain POST is never automatically repeated. */
class DeliveryService
{
    private const COUNTED_STATUSES = ['queued', 'processing', 'submitted', 'sent', 'delivered', 'read', 'unknown'];

    public function __construct(private RecipientService $recipients, private CloudApiService $cloud, private TemplateService $templates) {}

    public function enqueue(User $actor, WhatsAppTemplate $template, array $customerIds, string $dedupPrefix, string $trigger = 'manual', ?string $scheduleKey = null): Collection
    {
        $actor = $this->recipients->assertActor($actor);
        if ($trigger === 'automatic') {
            $this->recipients->assertActor($actor, 'whatsapp.schedule');
        }
        if (! in_array($trigger, ['manual', 'automatic'], true) || $dedupPrefix === '') {
            throw ValidationException::withMessages(['whatsapp' => 'Identitas pengiriman tidak sah.']);
        }
        $this->assertReady(WhatsAppSetting::query()->find(1), $template->fresh());
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        // Authorize the entire selection before creating any outbox entry, including service callers outside HTTP.
        foreach ($customerIds as $customerId) {
            $this->recipients->assertActor($actor, 'whatsapp.send', Customer::findOrFail($customerId));
        }

        return DB::transaction(function () use ($actor, $template, $customerIds, $dedupPrefix, $trigger, $scheduleKey) {
            $result = collect();
            foreach ($customerIds as $customerId) {
                $result->push(DB::transaction(function () use ($actor, $template, $customerId, $dedupPrefix, $trigger, $scheduleKey) {
                    // Every enqueue takes this lock before a customer lock. It also reserves API spacing across batches.
                    $settings = WhatsAppSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
                    $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
                    $this->recipients->assertActor($actor, 'whatsapp.send', $customer);
                    $dedupKey = hash('sha256', $dedupPrefix.'|'.$customerId);
                    if ($existing = WhatsAppReminder::query()->where('dedup_key', $dedupKey)->first()) {
                        return $existing;
                    }
                    $template = $template->fresh();
                    $this->assertReady($settings, $template);
                    $preview = $this->recipients->preview($actor, $customer, $template);
                    $errors = $preview['errors'];
                    $activeKey = 'customer:'.$customer->id;
                    if (WhatsAppReminder::query()->where('active_key', $activeKey)->exists()) {
                        $errors[] = 'Masih ada pengiriman pelanggan ini dalam antrean, diproses, atau menunggu kepastian penyedia.';
                    }
                    $policyError = $this->limitError($customer->id, $preview['invoice_ids'], $settings);
                    if ($policyError) {
                        $errors[] = $policyError;
                    }
                    $slot = now();
                    if ($settings->next_send_at && $settings->next_send_at->isFuture()) {
                        $slot = $settings->next_send_at->copy();
                    }
                    $reminder = WhatsAppReminder::create([
                        'uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'template_id' => $template->id,
                        'actor_id' => $actor->id, 'trigger' => $trigger, 'schedule_key' => $scheduleKey,
                        'dedup_key' => $dedupKey, 'active_key' => $errors ? null : $activeKey,
                        'status' => $errors ? 'skipped' : 'queued', 'phone' => $preview['phone'] ?? '',
                        'body' => $preview['body'], 'invoice_ids' => $preview['invoice_ids'], 'balance' => $preview['balance'],
                        'customer_snapshot' => ['id' => $customer->id, 'number' => $customer->number, 'name' => $customer->name, 'rt_id' => $customer->rt_id, 'rt_name' => $customer->rt?->name, 'rt' => $customer->rt?->code],
                        'template_snapshot' => ['body_hash' => $this->templates->bodyHash($template), 'provider_name' => $template->provider_name, 'language' => $template->language, 'parameters' => $preview['parameters'], 'customer_number' => $customer->number, 'customer_name' => $customer->name],
                        'error_code' => $errors ? 'not_eligible' : null, 'error_message' => $errors ? implode(' ', array_unique($errors)) : null,
                        'queued_at' => now(), 'processed_at' => $errors ? now() : null,
                    ]);
                    if (! $errors) {
                        $settings->update(['next_send_at' => $slot->copy()->addSeconds(max(1, (int) $settings->interval_seconds))]);
                        SendWhatsAppReminder::dispatch($reminder->id)->delay($slot)->afterCommit();
                    }
                    AuditService::record('whatsapp.'.$reminder->status, $reminder, null, ['customer_id' => $customer->id, 'customer_number' => $customer->number, 'status' => $reminder->status, 'trigger' => $trigger, 'invoice_ids' => $preview['invoice_ids']], null, $actor);

                    return $reminder;
                }));
            }

            return $result;
        });
    }

    public function process(int $reminderId): ?int
    {
        // Commit the claim before performing I/O. A crashed worker leaves an inspectable claim, never a retryable POST.
        $claimed = DB::transaction(function () use ($reminderId) {
            $settings = WhatsAppSetting::query()->whereKey(1)->lockForUpdate()->first();
            $reminder = WhatsAppReminder::query()->whereKey($reminderId)->lockForUpdate()->first();
            if (! $reminder || $reminder->status !== 'queued') {
                return ['claimed' => false, 'delay' => null];
            }
            if ($settings?->last_attempt_at) {
                $earliest = $settings->last_attempt_at->copy()->addSeconds(max(1, (int) $settings->interval_seconds));
                if ($earliest->isFuture()) {
                    return ['claimed' => false, 'delay' => max(1, (int) ceil(now()->diffInSeconds($earliest)))];
                }
            }
            $reminder->update(['status' => 'processing', 'processed_at' => now()]);

            return ['claimed' => true, 'delay' => null];
        });
        if (! $claimed['claimed']) {
            return $claimed['delay'];
        }

        $postAttempted = false;
        $deferDelay = null;
        try {
            DB::transaction(function () use ($reminderId, &$postAttempted, &$deferDelay) {
                // Serialize the actual sender, including workers that waited on a payment's customer lock.
                // This order matches enqueue: settings, reminder/customer, invoices.
                $settings = WhatsAppSetting::query()->whereKey(1)->lockForUpdate()->first();
                $reminder = WhatsAppReminder::query()->whereKey($reminderId)->lockForUpdate()->firstOrFail();
                if ($reminder->status !== 'processing') {
                    return;
                }
                // Payments also lock this customer before altering invoices. The final balance stays current until this decision commits.
                $customer = Customer::query()->whereKey($reminder->customer_id)->lockForUpdate()->first();
                if (! $customer) {
                    $this->finish($reminder, 'skipped', 'customer_unavailable', 'Pelanggan tidak lagi aktif dalam data pelanggan.');

                    return;
                }
                Invoice::query()->where('customer_id', $customer->id)->orderBy('id')->lockForUpdate()->get();
                $actor = User::query()->find($reminder->actor_id);
                if (! $actor) {
                    $this->finish($reminder, 'skipped', 'permission_changed', 'Pengguna pemicu pengiriman tidak tersedia.');

                    return;
                }
                $actor = $this->recipients->assertActor($actor, 'whatsapp.send', $customer);
                if ($reminder->trigger === 'automatic') {
                    $this->recipients->assertActor($actor, 'whatsapp.schedule', $customer);
                    if (! $settings?->auto_enabled || (int) $settings->run_as_user_id !== $actor->id) {
                        $this->finish($reminder, 'skipped', 'automation_disabled', 'Pengingat otomatis atau pengguna pemicunya telah diubah.');

                        return;
                    }
                }
                $template = WhatsAppTemplate::query()->find($reminder->template_id);
                $this->assertReady($settings, $template);
                if (! hash_equals((string) ($reminder->template_snapshot['body_hash'] ?? ''), $this->templates->bodyHash($template))) {
                    $this->finish($reminder, 'skipped', 'template_changed', 'Template berubah setelah konfirmasi. Buat pratinjau baru.');

                    return;
                }
                $preview = $this->recipients->preview($actor, $customer, $template);
                if ($preview['errors']) {
                    $this->finish($reminder, 'skipped', 'not_eligible', implode(' ', $preview['errors']));

                    return;
                }
                $error = $this->limitError($customer->id, $preview['invoice_ids'], $settings, $reminder->id);
                if ($error) {
                    $this->finish($reminder, 'skipped', 'reminder_limit', $error);

                    return;
                }
                if ($settings->last_attempt_at) {
                    $earliest = $settings->last_attempt_at->copy()->addSeconds(max(1, (int) $settings->interval_seconds));
                    if ($earliest->isFuture()) {
                        $deferDelay = max(1, (int) ceil(now()->diffInSeconds($earliest)));
                        $reminder->update(['status' => 'queued', 'processed_at' => null]);

                        return;
                    }
                }
                // Render again: a partial payment after confirmation must reduce the amount in the actual sent message.
                $reminder->update(['phone' => $preview['phone'], 'body' => $preview['body'], 'invoice_ids' => $preview['invoice_ids'], 'balance' => $preview['balance'],
                    'customer_snapshot' => ['id' => $customer->id, 'number' => $customer->number, 'name' => $customer->name, 'rt_id' => $customer->rt_id, 'rt_name' => $customer->rt?->name, 'rt' => $customer->rt?->code],
                    'template_snapshot' => array_merge($reminder->template_snapshot, ['parameters' => $preview['parameters']])]);
                try {
                    $settings->update(['last_attempt_at' => now()]);
                    $postAttempted = true;
                    $providerId = $this->cloud->send($settings, $template, $preview['phone'], $preview['parameters'], $reminder->uuid);
                } catch (ConnectionException $exception) {
                    $this->finish($reminder, 'unknown', 'provider_uncertain', 'Penyedia tidak memberikan kepastian penerimaan. Pengiriman tidak diulang otomatis untuk mencegah pesan ganda.');

                    return;
                } catch (Throwable $exception) {
                    // Provider exception strings may contain tokens or request contents. Never persist or log them.
                    $this->finish($reminder, 'failed', 'provider_rejected', 'Penyedia menolak permintaan atau integrasi tidak dapat digunakan. Periksa koneksi dan status template.');

                    return;
                }
                if ($providerId === '') {
                    $this->finish($reminder, 'unknown', 'provider_uncertain', 'Penyedia belum memberikan ID pesan. Tidak ada pengulangan otomatis.');

                    return;
                }
                $reminder->update(['status' => 'submitted', 'provider_message_id' => $providerId, 'active_key' => null, 'error_code' => null, 'error_message' => null]);
                AuditService::record('whatsapp.submitted', $reminder, null, ['status' => 'submitted', 'customer_id' => $customer->id, 'provider_message_id' => $providerId, 'invoice_ids' => $preview['invoice_ids']], null, $actor);
            }, 1);
        } catch (Throwable $exception) {
            $reminder = WhatsAppReminder::find($reminderId);
            if ($reminder && $reminder->status === 'processing') {
                if ($postAttempted) {
                    $this->finish($reminder, 'unknown', 'provider_uncertain', 'Hasil permintaan penyedia belum dapat dipastikan. Pesan tidak diulang otomatis.');
                } else {
                    $this->finish($reminder, 'skipped', 'eligibility_changed', 'Izin, wilayah, persetujuan, atau konfigurasi pengiriman tidak lagi memenuhi ketentuan.');
                }
            }
        }

        return $deferDelay;
    }

    public function markInterrupted(int $reminderId): void
    {
        DB::transaction(function () use ($reminderId) {
            $reminder = WhatsAppReminder::query()->whereKey($reminderId)->lockForUpdate()->first();
            if ($reminder?->status === 'processing') {
                $this->finish($reminder, 'unknown', 'worker_interrupted', 'Proses terhenti tanpa kepastian hasil penyedia. Periksa di penyedia; pesan tidak diulang otomatis.');
            } elseif ($reminder?->status === 'queued') {
                $this->finish($reminder, 'failed', 'queue_failed', 'Antrean gagal sebelum permintaan dikirim.');
            }
        });
    }

    public function dispatchScheduled(): int
    {
        // A process kill may bypass the job's failed callback. Recover only its status, never its POST.
        WhatsAppReminder::query()->where('status', 'processing')->where('processed_at', '<', now()->subMinutes(10))->pluck('id')->each(fn ($id) => $this->markInterrupted($id));
        $settings = WhatsAppSetting::query()->find(1);
        if (! $settings?->auto_enabled) {
            return 0;
        }
        $now = CarbonImmutable::now('Asia/Jakarta');
        if ($now->format('H:i') !== substr((string) $settings->send_time, 0, 5)) {
            return 0;
        }
        $actor = User::find($settings->run_as_user_id);
        $template = WhatsAppTemplate::find($settings->template_id);
        if (! $actor || ! $template) {
            return 0;
        }
        try {
            $actor = $this->recipients->assertActor($actor, 'whatsapp.schedule');
            $this->recipients->assertActor($actor);
            $this->assertReady($settings, $template);
        } catch (Throwable $exception) {
            return 0;
        }
        $ids = [];
        foreach ($this->recipients->eligible($actor) as $customer) {
            $invoices = Invoice::query()->where('customer_id', $customer->id)->whereIn('status', ['issued', 'partial'])->whereColumn('total', '>', 'paid_amount')->get();
            $eligible = $invoices->contains(function (Invoice $invoice) use ($now, $settings) {
                if (! $invoice->due_date) {
                    return false;
                }
                $days = (int) $now->startOfDay()->diffInDays(CarbonImmutable::parse($invoice->due_date)->startOfDay(), false);

                return ($days > 0 && $days <= (int) $settings->before_days)
                    || ($days === 0 && $settings->on_due)
                    || ($days < 0 && (int) $settings->after_days > 0 && abs($days) >= (int) $settings->after_days);
            });
            if ($eligible && ! $this->limitError($customer->id, $invoices->pluck('id')->all(), $settings)) {
                $ids[] = $customer->id;
            }
        }
        $key = 'automatic:'.$now->toDateString().':'.$template->id;
        // A daily immutable dispatch key makes repeated scheduler invocations harmless.
        $result = $this->enqueue($actor, $template, $ids, $key, 'automatic', $key);

        return $result->filter(fn ($reminder) => $reminder->wasRecentlyCreated && $reminder->status === 'queued')->count();
    }

    private function assertReady(?WhatsAppSetting $settings, ?WhatsAppTemplate $template): void
    {
        if (! $settings?->connected_at || ! $settings->access_token || ! $settings->phone_number_id || ! $settings->waba_id || ! $settings->app_secret || ! $settings->verify_token) {
            throw ValidationException::withMessages(['whatsapp' => 'WhatsApp Belum Terhubung. Lengkapi konfigurasi dan periksa koneksi terlebih dahulu.']);
        }
        if (! $template?->active || $template->status !== 'APPROVED' || ! $template->provider_name || ! $template->last_synced_at
            || ! hash_equals((string) $template->synced_body_hash, $this->templates->bodyHash($template))) {
            throw ValidationException::withMessages(['whatsapp' => 'Template belum aktif dan disetujui penyedia atau telah berubah. Sinkronkan status template sebelum mengirim.']);
        }
    }

    private function limitError(int $customerId, array $invoiceIds, WhatsAppSetting $settings, ?int $exceptId = null): ?string
    {
        if (! $invoiceIds) {
            return null;
        }
        $previous = WhatsAppReminder::query()->where('customer_id', $customerId)->whereIn('status', self::COUNTED_STATUSES)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->latest('queued_at')->get()
            ->filter(fn ($reminder) => count(array_intersect($invoiceIds, $reminder->invoice_ids ?? [])) > 0);
        if ($previous->count() >= max(1, (int) $settings->max_reminders)) {
            return 'Batas pengingat untuk tagihan yang masih terutang telah tercapai.';
        }
        $last = $previous->first();
        if ($last && $last->queued_at?->gt(now()->subDays(max(1, (int) $settings->repeat_days)))) {
            return 'Jeda pengulangan pengingat pelanggan belum terpenuhi.';
        }

        return null;
    }

    private function finish(WhatsAppReminder $reminder, string $status, string $code, string $message): void
    {
        $reminder->update(['status' => $status, 'active_key' => $status === 'unknown' ? 'customer:'.$reminder->customer_id : null,
            'error_code' => $code, 'error_message' => $message, 'processed_at' => $reminder->processed_at ?? now()]);
        AuditService::record('whatsapp.'.$status, $reminder, null, ['status' => $status, 'customer_id' => $reminder->customer_id, 'reason_code' => $code], null, $reminder->actor);
    }
}
