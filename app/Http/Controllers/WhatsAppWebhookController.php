<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $settings = WhatsAppSetting::find(1);
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless($settings?->verify_token && is_string($token) && hash_equals($settings->verify_token, $token) && $mode === 'subscribe' && is_string($challenge) && preg_match('/^[0-9]{1,100}$/', $challenge), 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        $settings = WhatsAppSetting::find(1);
        $signature = $request->header('X-Hub-Signature-256');
        abort_unless($settings?->app_secret && is_string($signature) && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $settings->app_secret), $signature), 403);
        $payload = $request->json()->all();
        abort_unless(($payload['object'] ?? '') === 'whatsapp_business_account', 422);
        foreach (array_slice(is_array($payload['entry'] ?? null) ? $payload['entry'] : [], 0, 100) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((string) ($entry['id'] ?? '') !== $settings->waba_id) {
                continue;
            }
            foreach (array_slice(is_array($entry['changes'] ?? null) ? $entry['changes'] : [], 0, 100) as $change) {
                if (! is_array($change) || ! is_array($change['value'] ?? null)) {
                    continue;
                }
                $value = $change['value'];
                if (($change['field'] ?? '') === 'message_template_status_update') {
                    $this->templateStatus($value);

                    continue;
                }
                if (($change['field'] ?? '') !== 'messages' || (string) data_get($value, 'metadata.phone_number_id', '') !== $settings->phone_number_id) {
                    continue;
                }
                foreach (array_slice(is_array($value['statuses'] ?? null) ? $value['statuses'] : [], 0, 100) as $status) {
                    if (is_array($status)) {
                        $this->messageStatus($status);
                    }
                }
                foreach (array_slice(is_array($value['messages'] ?? null) ? $value['messages'] : [], 0, 100) as $message) {
                    if (is_array($message)) {
                        $this->optOut($message);
                    }
                }
            }
        }

        return response()->json(['received' => true]);
    }

    private function messageStatus(array $event): void
    {
        $id = $event['id'] ?? null;
        $state = $event['status'] ?? '';
        if (! is_string($id) || strlen($id) > 255 || ! in_array($state, ['sent', 'delivered', 'read', 'failed'], true) || ! ctype_digit((string) ($event['timestamp'] ?? ''))) {
            return;
        }
        $timestamp = (int) $event['timestamp'];
        if ($timestamp < 946684800 || $timestamp > now()->timestamp + 86400) {
            return;
        }
        DB::transaction(function () use ($event, $id, $state, $timestamp) {
            $reminder = WhatsAppReminder::where('provider_message_id', $id)->lockForUpdate()->first();
            if (! $reminder && Str::isUuid($event['biz_opaque_callback_data'] ?? '')) {
                $reminder = WhatsAppReminder::where('uuid', $event['biz_opaque_callback_data'])->whereIn('status', ['processing', 'unknown', 'submitted', 'sent', 'delivered', 'read', 'failed'])->lockForUpdate()->first();
            }
            if (! $reminder || (string) ($event['recipient_id'] ?? '') !== $reminder->phone || ($reminder->provider_message_id && $reminder->provider_message_id !== $id)) {
                return;
            }
            if ($reminder->last_status_at && $timestamp < $reminder->last_status_at->timestamp && ($state === 'failed' || $reminder->status === 'failed')) {
                return;
            }
            $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
            if (($state === 'failed' && ($rank[$reminder->status] ?? 0) >= 2) || ($state !== 'failed' && ($rank[$reminder->status] ?? 0) >= $rank[$state])) {
                return;
            }
            $at = Carbon::createFromTimestamp($timestamp, config('app.timezone'));
            $data = ['status' => $state, 'provider_message_id' => $id, 'last_status_at' => $at, 'active_key' => null];
            if ($state === 'failed') {
                $code = data_get($event, 'errors.0.code');
                $data += ['error_code' => is_numeric($code) ? (string) (int) $code : 'provider_failed', 'error_message' => 'Meta melaporkan pengiriman gagal.'];
            } else {
                $data += ['error_code' => null, 'error_message' => null, 'sent_at' => $reminder->sent_at ?? $at];
                if (in_array($state, ['delivered', 'read'], true)) {
                    $data['delivered_at'] = $reminder->delivered_at ?? $at;
                }
                if ($state === 'read') {
                    $data['read_at'] = $reminder->read_at ?? $at;
                }
            }
            $reminder->update($data);
        });
    }

    private function templateStatus(array $value): void
    {
        $status = strtoupper((string) ($value['event'] ?? ''));
        if (! in_array($status, ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL', 'PENDING_DELETION', 'DELETED', 'LIMIT_EXCEEDED'], true)) {
            return;
        }
        // Even a signed APPROVED event does not prove that the approved body
        // matches the local version. Read-back sync remains mandatory.
        WhatsAppTemplate::where('provider_template_id', (string) ($value['message_template_id'] ?? ''))->update(['status' => $status, 'synced_body_hash' => null, 'last_synced_at' => now()]);
    }

    private function optOut(array $message): void
    {
        $text = mb_strtoupper(trim((string) data_get($message, 'text.body', '')));
        $phone = (string) ($message['from'] ?? '');
        if (! in_array($text, ['STOP', 'BERHENTI', 'STOP WHATSAPP', 'BERHENTI WHATSAPP'], true) || ! preg_match('/^628[0-9]{7,11}$/', $phone)) {
            return;
        }
        foreach (Customer::withTrashed()->where('whatsapp_consent_phone', $phone)->whereNotNull('whatsapp_consent_at')->get() as $customer) {
            $customer->update(['whatsapp_consent_at' => null, 'whatsapp_consent_phone' => null, 'whatsapp_consent_by' => null, 'whatsapp_consent_source' => 'Dicabut melalui WhatsApp STOP/BERHENTI']);
            AuditService::record('whatsapp.consent.revoked', $customer, [], ['customer_number' => $customer->number, 'source' => 'inbound_opt_out']);
        }
    }
}
