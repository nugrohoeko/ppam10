<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Period;
use App\Models\Rt;
use App\Models\WhatsAppBatch;
use App\Models\WhatsAppReminder;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Services\AuditService;
use App\Services\WhatsApp\DeliveryService;
use App\Services\WhatsApp\RecipientService;
use App\Services\WhatsApp\TemplateService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppController extends Controller
{
    public function index(Request $request, RecipientService $recipients)
    {
        $request->user()->fresh(['role'])->requirePermission('whatsapp.view');
        $filters = $this->filters($request);
        if (! empty($filters['customer_id'])) {
            $customer = Customer::findOrFail($filters['customer_id']);
            abort_unless($request->user()->canAccessCustomer($customer), 403);
        }
        $customers = $recipients->eligible($request->user(), $filters);
        $page = max(1, (int) $request->query('page', 1));
        $customers = new LengthAwarePaginator($customers->forPage($page, 50)->values(), $customers->count(), 50, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);

        return view('whatsapp.index', [
            'customers' => $customers, 'filters' => $filters,
            'templates' => WhatsAppTemplate::where('active', true)->orderBy('name')->get(),
            'setting' => WhatsAppSetting::current(),
            'rts' => $request->user()->role?->slug === 'operator' ? $request->user()->rts()->orderBy('code')->get() : Rt::orderBy('code')->get(),
            'periods' => Period::orderByDesc('starts_on')->get(),
        ]);
    }

    public function preview(Request $request, RecipientService $recipients)
    {
        $recipients->assertActor($request->user());
        $data = $request->validate([
            'all_filtered' => ['nullable', 'boolean'],
            'customer_ids' => ['required_unless:all_filtered,1', 'array', 'min:1', 'max:500'],
            'customer_ids.*' => ['required', 'integer', 'distinct'],
            'template_id' => ['required', 'integer', 'exists:whatsapp_templates,id'],
        ]);
        $template = WhatsAppTemplate::findOrFail($data['template_id']);
        $filters = $this->filters($request);
        $allFiltered = $request->boolean('all_filtered');
        $customers = $allFiltered
            ? $recipients->eligible($request->user(), $filters)->keyBy('id')
            : Customer::whereIn('id', $data['customer_ids'])->with('rt')->get()->keyBy('id');
        $ids = $allFiltered ? $customers->keys()->all() : $data['customer_ids'];
        if (count($ids) < 1 || count($ids) > 500) {
            throw ValidationException::withMessages(['customer_ids' => count($ids) > 500
                ? 'Hasil filter melebihi 500 pelanggan. Persempit RT, periode, status, atau lama tunggakan sebelum membuat pratinjau.'
                : 'Tidak ada pelanggan belum lunas yang sesuai filter saat ini.']);
        }
        // Authorize every selected ID. Never silently drop a customer from another RT.
        foreach ($ids as $id) {
            abort_unless(isset($customers[$id]) && $request->user()->canAccessCustomer($customers[$id]), 403);
        }
        $previews = collect($ids)->map(fn ($id) => $recipients->preview($request->user(), $customers[$id], $template));
        $ready = $previews->filter(fn ($preview) => empty($preview['errors']));
        $batch = WhatsAppBatch::create([
            'uuid' => (string) Str::uuid(), 'actor_id' => $request->user()->id, 'template_id' => $template->id,
            'template_hash' => app(TemplateService::class)->bodyHash($template),
            'customer_ids' => $ready->map(fn ($preview) => $preview['customer']->id)->values()->all(),
            'filters' => $filters, 'expires_at' => now()->addMinutes(30),
        ]);

        return view('whatsapp.preview', compact('previews', 'ready', 'batch', 'template'));
    }

    public function send(Request $request, RecipientService $recipients, DeliveryService $delivery)
    {
        $recipients->assertActor($request->user());
        $data = $request->validate(['batch_uuid' => ['required', 'uuid'], 'confirm' => ['accepted']]);
        $count = DB::transaction(function () use ($request, $data, $delivery) {
            $batch = WhatsAppBatch::where('uuid', $data['batch_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $batch->actor_id === (int) $request->user()->id, 403);
            if ($batch->confirmed_at) {
                return null;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['batch_uuid' => 'Pratinjau sudah kedaluwarsa. Buat pratinjau baru sebelum mengirim.']);
            }
            $template = WhatsAppTemplate::findOrFail($batch->template_id);
            if (! hash_equals($batch->template_hash, app(TemplateService::class)->bodyHash($template))) {
                throw ValidationException::withMessages(['template_id' => 'Template telah berubah. Tinjau ulang pesan sebelum mengirim.']);
            }
            if (empty($batch->customer_ids)) {
                throw ValidationException::withMessages(['customer_ids' => 'Tidak ada penerima yang memenuhi syarat untuk dikirim.']);
            }
            $messages = $delivery->enqueue($request->user(), $template, $batch->customer_ids, $batch->uuid);
            $batch->update(['confirmed_at' => now()]);

            return $messages->where('status', 'queued')->count();
        });

        return redirect()->route('whatsapp.history')->with('success', $count === null
            ? 'Pengiriman ini sudah dikonfirmasi. Periksa status pada riwayat.'
            : $count.' pengingat masuk antrean. Status dikirim akan diperbarui setelah penyedia memproses pesan; pelanggan yang sudah lunas dilewati.');
    }

    public function history(Request $request)
    {
        $request->user()->fresh(['role'])->requirePermission('whatsapp.view');
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:queued,processing,submitted,sent,delivered,read,failed,skipped,unknown'],
        ]);
        $scope = $request->user()->scopeCustomers(Customer::withTrashed())->select('customers.id');
        $query = WhatsAppReminder::whereIn('customer_id', $scope)->with(['customer', 'actor', 'template']);
        if (! empty($data['customer_id'])) {
            $customer = Customer::withTrashed()->findOrFail($data['customer_id']);
            abort_unless($request->user()->canAccessCustomer($customer), 403);
            $query->where('customer_id', $customer->id);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return view('whatsapp.history', ['reminders' => $query->latest('id')->paginate(30)->withQueryString()]);
    }

    public function consent(Request $request, Customer $customer, RecipientService $recipients)
    {
        $recipients->assertActor($request->user(), 'whatsapp.consent', $customer);
        $data = $request->validate([
            'consent' => ['required', 'boolean'],
            'source' => ['nullable', 'required_if:consent,1', 'string', 'min:5', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $customer, $data) {
            $locked = Customer::lockForUpdate()->findOrFail($customer->id);
            abort_unless($request->user()->fresh(['role'])->canAccessCustomer($locked), 403);
            $phone = PhoneNumber::normalize($locked->phone);
            if ($data['consent'] && ! $phone) {
                throw ValidationException::withMessages(['consent' => 'Lengkapi nomor WhatsApp Indonesia yang valid sebelum mencatat persetujuan.']);
            }
            $before = $locked->only(['whatsapp_consent_at', 'whatsapp_consent_source', 'whatsapp_consent_phone', 'whatsapp_consent_by']);
            $locked->update([
                'whatsapp_consent_at' => $data['consent'] ? now() : null,
                'whatsapp_consent_source' => $data['consent'] ? trim($data['source']) : null,
                'whatsapp_consent_phone' => $data['consent'] ? $phone : null,
                'whatsapp_consent_by' => $data['consent'] ? $request->user()->id : null,
            ]);
            AuditService::record('whatsapp.consent.updated', $locked, $before,
                $locked->only(['whatsapp_consent_at', 'whatsapp_consent_source', 'whatsapp_consent_phone', 'whatsapp_consent_by']),
                $data['consent'] ? 'Persetujuan pengingat dicatat' : 'Persetujuan pengingat dicabut', $request->user());
        });

        return back()->with('success', $data['consent'] ? 'Persetujuan pengingat WhatsApp dicatat untuk nomor pelanggan saat ini.' : 'Persetujuan pengingat WhatsApp dicabut.');
    }

    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'rt_id' => ['nullable', 'integer', 'exists:rts,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'status' => ['nullable', 'in:all,unpaid,partial'],
            'min_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'customer_id' => ['nullable', 'integer'],
        ]);
        if (! empty($filters['rt_id']) && $request->user()->role?->slug === 'operator') {
            abort_unless($request->user()->rts()->where('rts.id', $filters['rt_id'])->exists(), 403);
        }
        if (! empty($filters['customer_id'])) {
            $customer = Customer::findOrFail($filters['customer_id']);
            abort_unless($request->user()->canAccessCustomer($customer), 403);
        }

        return $filters;
    }
}
