<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\AuditService;
use App\Services\WhatsApp\IntegrationService;
use App\Services\WhatsApp\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppSettingsController extends Controller
{
    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user()->fresh(['role']);
        abort_unless($actor, 403);
        $actor->requirePermission($permission);

        return $actor;
    }

    public function index(Request $request, IntegrationService $integration)
    {
        $actor = $request->user()->fresh(['role']);
        abort_unless($actor && ($actor->hasPermission('whatsapp.configure') || $actor->hasPermission('whatsapp.schedule')), 403);

        return view('whatsapp.settings', ['settings' => $integration->settings(), 'templates' => WhatsAppTemplate::orderBy('name')->get(), 'canConfigure' => $actor->hasPermission('whatsapp.configure'), 'canSchedule' => $actor->hasPermission('whatsapp.schedule')]);
    }

    public function update(Request $request, IntegrationService $integration)
    {
        $actor = $request->user()->fresh(['role']);
        abort_unless($actor && ($actor->hasPermission('whatsapp.configure') || $actor->hasPermission('whatsapp.schedule')), 403);
        $settings = $integration->settings();
        $data = [];
        $integrationFields = ['provider', 'graph_version', 'phone_number_id', 'waba_id', 'access_token', 'app_secret', 'verify_token', 'pam_name', 'payment_account', 'treasurer_contact'];
        if ($request->hasAny($integrationFields)) {
            $actor->requirePermission('whatsapp.configure');
            $data = $request->validate([
                'provider' => ['required', Rule::in(['meta'])], 'graph_version' => ['required', 'regex:/^v[0-9]{1,3}\.[0-9]{1,2}$/'],
                'phone_number_id' => ['nullable', 'regex:/^[0-9]{1,40}$/'], 'waba_id' => ['nullable', 'regex:/^[0-9]{1,40}$/'],
                'access_token' => ['nullable', 'string', 'max:5000'], 'app_secret' => ['nullable', 'string', 'max:1000'], 'verify_token' => ['nullable', 'string', 'min:16', 'max:255'],
                'pam_name' => ['required', 'string', 'max:255'], 'payment_account' => ['nullable', 'string', 'max:1000'], 'treasurer_contact' => ['nullable', 'string', 'max:255'],
            ]);
        }
        $connectionChanged = false;
        foreach (['provider', 'graph_version', 'phone_number_id', 'waba_id', 'access_token', 'app_secret', 'verify_token'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if (in_array($field, ['access_token', 'app_secret', 'verify_token'], true) && ! filled($data[$field] ?? null)) {
                unset($data[$field]);

                continue;
            }
            if (($data[$field] ?? null) !== $settings->$field) {
                $connectionChanged = true;
            }
        }
        $scheduleFields = ['auto_enabled', 'send_time', 'before_days', 'on_due', 'after_days', 'repeat_days', 'max_reminders', 'interval_seconds', 'template_id'];
        if ($request->hasAny($scheduleFields)) {
            $actor->requirePermission('whatsapp.schedule');
            $schedule = $request->validate([
                'auto_enabled' => ['required', 'boolean'], 'send_time' => ['required', 'date_format:H:i'],
                'before_days' => ['required', 'integer', 'min:0', 'max:90'], 'on_due' => ['required', 'boolean'],
                'after_days' => ['required', 'integer', 'min:0', 'max:365'], 'repeat_days' => ['required', 'integer', 'min:1', 'max:365'],
                'max_reminders' => ['required', 'integer', 'min:1', 'max:20'], 'interval_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
                'template_id' => ['nullable', 'integer', Rule::exists('whatsapp_templates', 'id')],
            ]);
            if ((bool) $schedule['auto_enabled']) {
                $actor->requirePermission('whatsapp.send');
                $template = WhatsAppTemplate::find($schedule['template_id'] ?? null);
                if ($connectionChanged || ! $settings->connected_at || ! $template?->active || $template->status !== 'APPROVED' || $template->synced_body_hash !== app(TemplateService::class)->bodyHash($template)) {
                    throw ValidationException::withMessages(['auto_enabled' => 'Uji koneksi dan pilih template aktif yang disetujui serta disinkronkan sebelum mengaktifkan jadwal.']);
                }
            }
            $data = array_merge($data, $schedule, ['run_as_user_id' => $actor->id]);
        }
        if ($connectionChanged) {
            $data['connected_at'] = null;
            $data['auto_enabled'] = false;
            $data['sender_name'] = null;
            WhatsAppTemplate::query()->update(['status' => 'DRAFT', 'synced_body_hash' => null, 'last_synced_at' => null, 'provider_template_id' => null]);
        }
        $settings->update($data);
        AuditService::record('whatsapp.settings.updated', $settings, [], ['provider' => $settings->provider, 'auto_enabled' => $settings->auto_enabled, 'credentials_changed' => $connectionChanged], actor: $actor);

        return redirect()->route('whatsapp.settings')->with('success', $connectionChanged ? 'Pengaturan disimpan. Uji koneksi kembali untuk mengaktifkan pengiriman.' : 'Pengaturan WhatsApp disimpan.');
    }

    public function connect(Request $request, IntegrationService $integration)
    {
        $actor = $this->actor($request, 'whatsapp.configure');
        $settings = $integration->settings();
        $settings->update(['connected_at' => null, 'auto_enabled' => false]);
        try {
            $integration->connect($settings);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['whatsapp' => 'Koneksi Meta belum berhasil. Periksa kredensial, ID akun, izin token, dan jaringan.']);
        }
        AuditService::record('whatsapp.connection.verified', $settings, [], ['sender_name' => $settings->sender_name], actor: $actor);

        return redirect()->route('whatsapp.settings')->with('success', 'Koneksi API terverifikasi. Pengiriman menunggu template disetujui dan persetujuan pelanggan.');
    }

    public function templates(Request $request)
    {
        $this->actor($request, 'whatsapp.templates');

        return view('whatsapp.templates', ['templates' => WhatsAppTemplate::orderBy('id')->get(), 'variables' => TemplateService::VARIABLES]);
    }

    public function storeTemplate(Request $request)
    {
        $actor = $this->actor($request, 'whatsapp.templates');
        $data = $this->templateData($request);
        $template = WhatsAppTemplate::create($data + ['status' => 'DRAFT']);
        AuditService::record('whatsapp.template.created', $template, [], ['name' => $template->name, 'status' => $template->status], actor: $actor);

        return redirect()->route('whatsapp.templates')->with('success', 'Template dibuat sebagai DRAFT. Ajukan atau sinkronkan persetujuan Meta sebelum digunakan.');
    }

    public function updateTemplate(Request $request, WhatsAppTemplate $template)
    {
        $actor = $this->actor($request, 'whatsapp.templates');
        $data = $this->templateData($request, $template);
        if ($template->body !== $data['body'] || $template->provider_name !== $data['provider_name'] || $template->language !== $data['language']) {
            $data += ['status' => 'DRAFT', 'synced_body_hash' => null, 'provider_template_id' => null, 'last_synced_at' => null];
        }
        $template->update($data);
        AuditService::record('whatsapp.template.updated', $template, [], ['name' => $template->name, 'active' => $template->active, 'status' => $template->status], actor: $actor);

        return redirect()->route('whatsapp.templates')->with('success', 'Template diperbarui. Perubahan isi memerlukan sinkronisasi persetujuan ulang.');
    }

    public function syncTemplate(Request $request, WhatsAppTemplate $template, IntegrationService $integration)
    {
        $actor = $this->actor($request, 'whatsapp.templates');
        $action = $request->validate(['action' => ['required', Rule::in(['submit', 'sync'])]])['action'];
        try {
            $action === 'submit' ? $integration->submit($integration->settings(), $template) : $integration->sync($integration->settings(), $template);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['template' => 'Permintaan Meta belum berhasil. Periksa WhatsApp Manager, koneksi, dan izin token sebelum mencoba lagi.']);
        }
        AuditService::record('whatsapp.template.'.$action, $template, [], ['name' => $template->name, 'status' => $template->status], actor: $actor);

        return redirect()->route('whatsapp.templates')->with('success', 'Status template: '.$template->status.'.');
    }

    private function templateData(Request $request, ?WhatsAppTemplate $template = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'provider_name' => ['required', 'regex:/^[a-z][a-z0-9_]{0,99}$/', Rule::unique('whatsapp_templates')->where('language', $request->input('language'))->ignore($template?->id)],
            'language' => ['required', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'], 'body' => ['required', 'string', 'max:5000'], 'active' => ['required', 'boolean'],
        ]);
        $data['body'] = str_replace(["\r\n", "\r"], "\n", $data['body']);
        $data['variables'] = app(TemplateService::class)->variables($data['body']);

        return $data;
    }
}
