<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use Illuminate\Validation\ValidationException;

class IntegrationService
{
    public function settings(): WhatsAppSetting
    {
        return WhatsAppSetting::current();
    }

    public function connect(WhatsAppSetting $settings): void
    {
        if (! $settings->app_secret || ! $settings->verify_token) {
            throw ValidationException::withMessages(['whatsapp' => 'App Secret dan Verify Token webhook wajib diisi.']);
        }
        $api = app(CloudApiService::class);
        $phone = $api->checked($api->request($settings)->get('/'.$settings->phone_number_id, ['fields' => 'id,display_phone_number,verified_name']));
        $phones = $api->checked($api->request($settings)->get('/'.$settings->waba_id.'/phone_numbers', ['fields' => 'id', 'limit' => 100]));
        if ((string) ($phone['id'] ?? '') !== $settings->phone_number_id || ! collect($phones['data'] ?? [])->contains(fn ($item) => (string) ($item['id'] ?? '') === $settings->phone_number_id)) {
            throw ValidationException::withMessages(['whatsapp' => 'Identitas nomor pengirim tidak cocok dengan WhatsApp Business Account.']);
        }
        $settings->update(['sender_name' => mb_substr((string) ($phone['verified_name'] ?? $phone['display_phone_number'] ?? ''), 0, 255), 'connected_at' => now()]);
    }

    public function submit(WhatsAppSetting $settings, WhatsAppTemplate $template): void
    {
        $this->requireConnected($settings);
        $templates = app(TemplateService::class);
        $variables = $templates->variables($template->body);
        $component = ['type' => 'BODY', 'text' => $templates->providerBody($template->body)];
        if ($variables) {
            $examples = ['nama_pelanggan' => 'Budi', 'nama_pam' => 'PAM RW.10', 'id_pelanggan' => 'PAM10-000001', 'rt' => '01', 'rincian_tunggakan' => 'September 2026: Rp45.000', 'total_tunggakan' => 'Rp45.000', 'rekening_pembayaran' => 'rekening PAM yang terdaftar', 'kontak_bendahara' => 'nomor Bendahara yang terdaftar'];
            $component['example'] = ['body_text' => [array_map(fn ($variable) => $examples[$variable], $variables)]];
        }
        $api = app(CloudApiService::class);
        $result = $api->checked($api->request($settings)->post('/'.$settings->waba_id.'/message_templates', [
            'name' => $template->provider_name, 'language' => $template->language, 'category' => 'UTILITY',
            'parameter_format' => 'POSITIONAL', 'components' => [$component],
        ]));
        $id = $result['id'] ?? null;
        if (! $id || ! preg_match('/^[0-9]+$/', (string) $id)) {
            throw ValidationException::withMessages(['template' => 'ID pengajuan template tidak valid. Periksa WhatsApp Manager sebelum mengajukan ulang.']);
        }
        $template->update(['provider_template_id' => (string) $id, 'status' => 'PENDING', 'synced_body_hash' => null, 'last_synced_at' => now()]);
    }

    public function sync(WhatsAppSetting $settings, WhatsAppTemplate $template): void
    {
        $template->update(['status' => 'UNKNOWN', 'synced_body_hash' => null]);
        $this->requireConnected($settings);
        $api = app(CloudApiService::class);
        $result = $api->checked($api->request($settings)->get('/'.$settings->waba_id.'/message_templates', ['name' => $template->provider_name, 'fields' => 'id,name,language,status,components', 'limit' => 100]));
        $match = collect($result['data'] ?? [])->first(fn ($item) => ($item['name'] ?? '') === $template->provider_name && ($item['language'] ?? '') === $template->language);
        if (! $match) {
            $template->update(['status' => 'DRAFT', 'synced_body_hash' => null, 'last_synced_at' => now()]);
            throw ValidationException::withMessages(['template' => 'Template belum ditemukan di Meta. Ajukan template atau periksa nama dan bahasa.']);
        }
        $templates = app(TemplateService::class);
        $components = $match['components'] ?? [];
        $compatible = count($components) === 1 && ($components[0]['type'] ?? '') === 'BODY' && ($components[0]['text'] ?? '') === $templates->providerBody($template->body);
        $status = strtoupper((string) ($match['status'] ?? 'UNKNOWN'));
        if (! in_array($status, ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'IN_APPEAL', 'PENDING_DELETION', 'DELETED', 'LIMIT_EXCEEDED'], true)) {
            $status = 'UNKNOWN';
        }
        $template->update(['provider_template_id' => (string) ($match['id'] ?? ''), 'status' => $compatible ? $status : 'MISMATCH', 'synced_body_hash' => $compatible ? $templates->bodyHash($template) : null, 'last_synced_at' => now()]);
        if (! $compatible) {
            throw ValidationException::withMessages(['template' => 'Isi template Meta tidak sama dengan template lokal. Hanya template BODY teks yang didukung; gunakan nama versi baru setelah mengubah isi.']);
        }
    }

    private function requireConnected(WhatsAppSetting $settings): void
    {
        if (! $settings->connected_at) {
            throw ValidationException::withMessages(['whatsapp' => 'Belum Terhubung: uji koneksi Meta terlebih dahulu.']);
        }
    }
}
