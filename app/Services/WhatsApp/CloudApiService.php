<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CloudApiService
{
    public function request(WhatsAppSetting $settings): PendingRequest
    {
        if ($settings->provider !== 'meta' || ! preg_match('/^v[0-9]+\.[0-9]+$/', $settings->graph_version ?? '') || ! preg_match('/^[0-9]+$/', $settings->phone_number_id ?? '') || ! preg_match('/^[0-9]+$/', $settings->waba_id ?? '') || ! $settings->access_token) {
            throw ValidationException::withMessages(['whatsapp' => 'Belum Terhubung: lengkapi konfigurasi resmi Meta Cloud API.']);
        }

        return Http::baseUrl('https://graph.facebook.com/'.$settings->graph_version)
            ->withToken($settings->access_token)->acceptJson()->asJson()
            ->connectTimeout(10)->timeout(30)->withoutRedirecting();
    }

    public function checked(Response $response): array
    {
        if (! $response->successful()) {
            // Never include provider response bodies or credentials in exceptions.
            $code = $response->json('error.code');
            $safeCode = is_numeric($code) ? (string) (int) $code : (string) $response->status();
            throw new RuntimeException('Meta Cloud API menolak permintaan (kode '.$safeCode.').');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Respons Meta Cloud API tidak valid.');
        }

        return $json;
    }

    public function send(WhatsAppSetting $settings, WhatsAppTemplate $template, string $phone, array $parameters, string $uuid): string
    {
        if (! $settings->connected_at || ! $settings->app_secret || ! $settings->verify_token) {
            throw ValidationException::withMessages(['whatsapp' => 'Belum Terhubung: uji koneksi dan lengkapi kredensial webhook terlebih dahulu.']);
        }
        $templates = app(TemplateService::class);
        if (! $template->active || $template->status !== 'APPROVED' || ! $template->synced_body_hash || ! hash_equals($templates->bodyHash($template), $template->synced_body_hash)) {
            throw ValidationException::withMessages(['template_id' => 'Template belum disetujui Meta atau isinya berubah. Sinkronkan ulang sebelum mengirim.']);
        }
        if (! preg_match('/^628[0-9]{7,11}$/', $phone) || count($parameters) !== count($templates->variables($template->body))) {
            throw ValidationException::withMessages(['whatsapp' => 'Nomor tujuan atau parameter template tidak valid.']);
        }
        foreach ($parameters as $parameter) {
            if (! is_string($parameter) || trim($parameter) === '' || preg_match('/[\r\n\t]/', $parameter)) {
                throw ValidationException::withMessages(['whatsapp' => 'Parameter template kosong atau tidak valid.']);
            }
        }
        $payload = [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $phone,
            'type' => 'template', 'biz_opaque_callback_data' => $uuid,
            'template' => ['name' => $template->provider_name, 'language' => ['code' => $template->language]],
        ];
        if ($parameters) {
            $payload['template']['components'] = [['type' => 'body', 'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => $text], $parameters)]];
        }
        // Intentionally no retry: a timeout may follow provider acceptance.
        $response = $this->request($settings)->post('/'.$settings->phone_number_id.'/messages', $payload);
        if ($response->serverError() || $response->status() === 408 || ($response->successful() && ! is_array($response->json()))) {
            throw new ConnectionException('Respons pengiriman Meta belum dapat dipastikan. Periksa status sebelum mengirim ulang.');
        }
        $result = $this->checked($response);
        $id = data_get($result, 'messages.0.id');
        if (! is_string($id) || $id === '' || strlen($id) > 255) {
            throw new ConnectionException('Meta tidak mengembalikan ID pesan. Status pengiriman perlu diperiksa sebelum mengirim ulang.');
        }

        return $id;
    }
}
