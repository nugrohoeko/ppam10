<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Models\WhatsAppTemplate;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecipientService
{
    public function assertActor(User $actor, string $permission = 'whatsapp.send', ?Customer $customer = null): User
    {
        $actor = $actor->fresh(['role']);
        abort_unless($actor?->hasPermission($permission), 403, 'Anda tidak memiliki izin WhatsApp untuk tindakan ini.');
        if ($customer) {
            $current = Customer::withTrashed()->findOrFail($customer->id);
            abort_unless($actor->canAccessCustomer($current), 403, 'Pelanggan berada di luar wilayah tugas Anda.');
        }

        return $actor;
    }

    /** Filters select customers; all their outstanding invoices are merged in the preview. */
    public function eligible(User $actor, array $filters = []): Collection
    {
        $actor = $actor->fresh(['role']);
        abort_unless($actor && ($actor->hasPermission('whatsapp.view') || $actor->hasPermission('whatsapp.send') || $actor->hasPermission('whatsapp.schedule')), 403);
        $query = $actor->scopeCustomers(Customer::query())->with(['rt', 'invoices' => function ($query) {
            $this->outstanding($query->getQuery())->with('period')->orderBy('due_date')->orderBy('id');
        }]);
        $query->when(! empty($filters['rt_id']), fn ($query) => $query->where('rt_id', $filters['rt_id']));
        $query->when(! empty($filters['customer_id']), fn ($query) => $query->whereKey($filters['customer_id']));
        $query->whereHas('invoices', function (Builder $query) use ($filters) {
            $this->outstanding($query);
            $query->when(! empty($filters['period_id']), fn ($query) => $query->where('period_id', $filters['period_id']));
            if (($filters['status'] ?? 'all') === 'unpaid') {
                $query->where('paid_amount', 0);
            } elseif (($filters['status'] ?? 'all') === 'partial') {
                $query->where('paid_amount', '>', 0);
            }
            if ((int) ($filters['min_days'] ?? 0) > 0) {
                $query->whereDate('due_date', '<=', now('Asia/Jakarta')->subDays((int) $filters['min_days'])->toDateString());
            }
        });

        return $query->orderBy('number')->get();
    }

    public function preview(User $actor, Customer $customer, WhatsAppTemplate $template): array
    {
        $actor = $this->assertActor($actor, 'whatsapp.send', $customer);
        $customer = Customer::withTrashed()->with('rt')->findOrFail($customer->id);
        $template = $template->fresh();
        $settings = WhatsAppSetting::current();
        $errors = [];
        $phone = PhoneNumber::normalize($customer->phone);
        if ($customer->trashed()) {
            $errors[] = 'Pelanggan sudah diarsipkan.';
        }
        if (! $phone) {
            $errors[] = 'Nomor WhatsApp Indonesia belum tersedia atau tidak valid.';
        }
        if (! $customer->whatsapp_consent_at || ! $phone || $customer->whatsapp_consent_phone !== $phone || ! trim((string) $customer->whatsapp_consent_source)) {
            $errors[] = 'Persetujuan pelanggan untuk menerima pengingat WhatsApp pada nomor ini belum tercatat.';
        }
        if ($settings->provider !== 'meta' || ! $settings->connected_at || ! $settings->access_token || ! $settings->phone_number_id || ! $settings->waba_id || ! $settings->app_secret || ! $settings->verify_token) {
            $errors[] = 'WhatsApp Belum Terhubung. Admin perlu melengkapi dan memverifikasi koneksi Cloud API.';
        }
        if (! $template || ! $template->active || $template->status !== 'APPROVED' || ! $template->synced_body_hash || ! hash_equals($template->synced_body_hash, TemplateService::bodyHash($template))) {
            $errors[] = 'Template belum aktif dan disetujui Meta, atau isinya berubah setelah sinkronisasi.';
        }
        $invoices = $this->outstanding(Invoice::query()->where('customer_id', $customer->id))
            ->with('period')->orderBy('due_date')->orderBy('id')->get();
        $details = $invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id, 'number' => $invoice->number,
            'period' => $invoice->period?->name ?? 'Periode tidak tersedia',
            'due_date' => $invoice->due_date?->format('Y-m-d'),
            'balance' => $invoice->remaining,
        ])->all();
        $balance = (int) $invoices->sum(fn (Invoice $invoice) => $invoice->remaining);
        if ($balance <= 0) {
            $errors[] = 'Tagihan sudah lunas; pelanggan dikeluarkan dari penerima.';
        }
        $money = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
        // Cloud API text parameters cannot contain newlines; the approved body provides layout.
        $data = [
            'nama_pelanggan' => $customer->name,
            'nama_pam' => $settings->pam_name,
            'id_pelanggan' => $customer->number,
            'rt' => $customer->rt?->code,
            'rincian_tunggakan' => implode('; ', array_map(fn ($invoice) => $invoice['period'].' ('.$invoice['number'].', jatuh tempo '.$invoice['due_date'].'): '.$money($invoice['balance']), $details)),
            'total_tunggakan' => $money($balance),
            'rekening_pembayaran' => $settings->payment_account,
            'kontak_bendahara' => $settings->treasurer_contact,
        ];
        $rendered = $template ? app(TemplateService::class)->render($template, $data) : ['body' => '', 'parameters' => [], 'errors' => ['Template tidak tersedia.']];
        $errors = array_values(array_unique(array_merge($errors, $rendered['errors'])));
        $invoiceIds = $invoices->modelKeys();
        $body = $rendered['body'];

        return [
            'customer' => $customer, 'phone' => $phone, 'invoice_ids' => $invoiceIds,
            'invoices' => $details, 'balance' => $balance, 'body' => $body,
            'parameters' => $rendered['parameters'], 'errors' => $errors,
            'fingerprint' => hash('sha256', json_encode([$customer->id, $phone, $invoiceIds, $balance, $body], JSON_THROW_ON_ERROR)),
        ];
    }

    private function outstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['issued', 'partial'])->whereColumn('total', '>', 'paid_amount');
    }
}
