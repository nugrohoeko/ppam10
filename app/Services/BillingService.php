<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\TariffScheme;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(private TariffService $tariffs) {}

    public function preview(Reading $reading): array
    {
        $reading->loadMissing('period', 'customer');
        $scheme = $this->tariffs->forCategory((int) ($reading->customer_snapshot['category_id'] ?? $reading->customer->category_id), $reading->period->starts_on->toDateString());
        $calculationScheme = clone $scheme;
        $calculationPolicy = ['midperiod_policy' => SettingService::get('midperiod_policy', 'full')];
        $joined = Carbon::parse($reading->customer_snapshot['joined_on'] ?? $reading->customer->joined_on);
        if ($calculationPolicy['midperiod_policy'] === 'prorate' && $joined->gt($reading->period->starts_on) && $joined->lte($reading->period->ends_on)) {
            $days = (int) $joined->diffInDays($reading->period->ends_on) + 1;
            $periodDays = (int) $reading->period->starts_on->diffInDays($reading->period->ends_on) + 1;
            foreach (['base_amount', 'admin_fee', 'additional_fee'] as $field) {
                $calculationScheme->$field = Decimal::money(bcdiv(Decimal::mul($scheme->$field, $days), (string) $periodDays, 6));
            }
            $calculationPolicy += ['active_days' => $days, 'period_days' => $periodDays, 'rule' => 'Biaya tetap prorata harian; kuota dan tarif pemakaian tetap.'];
        }
        $result = $this->tariffs->calculate($calculationScheme, $reading->usage);
        if (isset($calculationPolicy['active_days'])) {
            array_unshift($result['lines'], ['description' => 'Prorata biaya tetap '.$calculationPolicy['active_days'].'/'.$calculationPolicy['period_days'].' hari (kuota tetap)', 'quantity' => '0', 'rate' => 0, 'amount' => '0']);
        }
        $penalty = now()->startOfDay()->gt($reading->period->due_date->copy()->addDays($scheme->grace_days)) ? (int) $scheme->penalty_amount : 0;
        if ($penalty > 0) {
            $result['penalty_amount'] = $penalty;
            $result['total'] += $penalty;
            $result['lines'][] = ['description' => 'Denda melewati masa tenggang pada penerbitan', 'quantity' => '1', 'rate' => $penalty, 'amount' => (string) $penalty];
        }
        $result['tariff_scheme_id'] = $scheme->id;
        $result['tariff_snapshot'] = array_merge($scheme->toArray(), ['calculation_policy' => $calculationPolicy]);
        $result['customer_snapshot'] = $reading->customer_snapshot;
        $result['usage'] = $reading->usage;
        $result['previous_arrears'] = (int) Invoice::where('customer_id', $reading->customer_id)->whereIn('status', ['issued', 'partial'])->where('period_id', '!=', $reading->period_id)->selectRaw('COALESCE(SUM(total-paid_amount),0) AS arrears')->value('arrears');

        return $result;
    }

    public function generate(User $actor, Reading $reading, bool $issue = true): Invoice
    {
        $actor->requirePermission('invoices.create');
        abort_unless($actor->canAccessCustomer($reading->customer), 403);
        if ($issue) {
            $actor->requirePermission('invoices.issue');
        }

        return DB::transaction(function () use ($actor, $reading, $issue) {
            $period = Period::whereKey($reading->period_id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            $customer = Customer::whereKey($reading->customer_id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canAccessCustomer($customer), 403);
            $reading = Reading::whereKey($reading->id)->lockForUpdate()->firstOrFail();
            if ($reading->status !== 'verified') {
                $this->error('Tagihan hanya dapat dibuat dari pencatatan yang telah diverifikasi.');
            }
            if (Invoice::where('customer_id', $reading->customer_id)->where('period_id', $reading->period_id)->where('status', '!=', 'cancelled')->exists()) {
                $this->error('Tagihan aktif untuk pelanggan dan periode ini sudah ada.');
            }
            // Tariff administration takes the same category lock before modifying
            // any version or tier. Calculation and snapshot therefore see one version.
            Category::whereKey($reading->customer_snapshot['category_id'] ?? $customer->category_id)->lockForUpdate()->firstOrFail();
            $calculation = $this->preview($reading);
            $last = Invoice::where('customer_id', $reading->customer_id)->where('period_id', $reading->period_id)->orderByDesc('revision')->first();
            $scheme = TariffScheme::whereKey($calculation['tariff_scheme_id'])->lockForUpdate()->firstOrFail();
            $invoice = Invoice::create([
                'number' => NumberService::next('invoice'), 'customer_id' => $reading->customer_id, 'reading_id' => $reading->id, 'period_id' => $reading->period_id,
                'tariff_scheme_id' => $scheme->id, 'revision' => ($last?->revision ?? 0) + 1, 'replacement_for' => $last?->id,
                'customer_snapshot' => $calculation['customer_snapshot'], 'tariff_snapshot' => $calculation['tariff_snapshot'], 'lines' => $calculation['lines'],
                'usage' => $reading->usage, 'water_amount' => $calculation['water_amount'], 'additional_amount' => $calculation['additional_amount'],
                'discount_amount' => $calculation['discount_amount'], 'penalty_amount' => $calculation['penalty_amount'], 'total' => $calculation['total'],
                'paid_amount' => 0, 'status' => 'draft', 'due_date' => $period->due_date, 'user_id' => $actor->id,
            ]);
            if (! $scheme->locked_at) {
                $scheme->update(['locked_at' => now()]);
            }
            AuditService::record('invoice.created', $invoice, [], $invoice->toArray(), null, $actor);

            return $issue ? $this->issue($actor, $invoice) : $invoice;
        }, 3);
    }

    public function issue(User $actor, Invoice $invoice): Invoice
    {
        $actor->requirePermission('invoices.issue');
        abort_unless($actor->canAccessCustomer($invoice->customer), 403);

        return DB::transaction(function () use ($actor, $invoice) {
            $period = Period::whereKey($invoice->period_id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            $customer = Customer::withTrashed()->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            if ($customer->trashed()) {
                $this->error('Pelanggan telah dihapus. Tagihan draft tidak dapat diterbitkan.');
            }
            abort_unless($actor->canAccessCustomer($customer), 403);
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $reading = Reading::whereKey($invoice->reading_id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== 'draft') {
                $this->error('Hanya tagihan draft yang dapat diterbitkan.');
            }
            if ($reading->status !== 'verified' || $reading->usage !== $invoice->usage) {
                $this->error('Pencatatan berubah atau belum sah; batalkan dan buat ulang draft tagihan.');
            }
            $before = $invoice->toArray();
            $invoice->update(['status' => $invoice->total === 0 ? 'paid' : 'issued', 'issued_at' => now()]);
            $reading->update(['status' => 'locked']);
            AuditService::record('invoice.issued', $invoice, $before, $invoice->toArray(), null, $actor);

            return $invoice;
        }, 3);
    }

    public function cancel(User $actor, Invoice $invoice, string $reason): Invoice
    {
        $actor->requirePermission('invoices.cancel');
        abort_unless($actor->canAccessCustomer($invoice->customer), 403);
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:5000'])->validate();

        return DB::transaction(function () use ($actor, $invoice, $reason) {
            $period = Period::whereKey($invoice->period_id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            Customer::withTrashed()->whereKey($invoice->customer_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'cancelled') {
                $this->error('Tagihan sudah dibatalkan.');
            }
            if ($invoice->paid_amount !== 0 || $invoice->allocations()->whereHas('payment', fn ($q) => $q->where('status', 'posted'))->exists()) {
                $this->error('Batalkan pembayaran yang dialokasikan sebelum membatalkan tagihan.');
            }
            $before = $invoice->toArray();
            $invoice->update(['status' => 'cancelled', 'active_slot' => null, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $invoice->reading()->update(['status' => 'verified']);
            AuditService::record('invoice.cancelled', $invoice, $before, $invoice->toArray(), $reason, $actor);

            return $invoice;
        }, 3);
    }

    public function closePeriod(User $actor, Period $period, string $reason): void
    {
        $actor->requirePermission('periods.close');
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $period, $reason) {
            $period = Period::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            if ($period->readings()->whereHas('customer', fn ($query) => $query->whereNull('customers.deleted_at'))->whereIn('status', ['draft', 'submitted', 'rejected'])->exists()) {
                $this->error('Masih ada pencatatan yang belum disahkan.');
            }
            if ($period->readings()->whereHas('customer', fn ($query) => $query->whereNull('customers.deleted_at'))->where('status', 'verified')->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', 'cancelled'))->exists()) {
                $this->error('Masih ada pencatatan sah yang belum diterbitkan tagihannya.');
            }
            if ($period->invoices()->where('status', 'draft')->exists()) {
                $this->error('Masih ada draft tagihan.');
            }
            if (CashEntry::whereDate('entry_date', '>=', $period->starts_on->toDateString())->whereDate('entry_date', '<=', $period->ends_on->toDateString())->where('status', 'pending')->exists()) {
                $this->error('Masih ada transaksi kas menunggu persetujuan.');
            }
            $before = $period->toArray();
            $period->update(['locked_at' => now(), 'locked_by' => $actor->id]);
            AuditService::record('period.closed', $period, $before, $period->toArray(), $reason, $actor);
        }, 3);
    }

    public function reopenPeriod(User $actor, Period $period, string $reason): void
    {
        $actor->requirePermission('periods.reopen');
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $period, $reason) {
            $period = Period::whereKey($period->id)->lockForUpdate()->firstOrFail();
            if (! $period->locked_at) {
                $this->error('Periode belum ditutup.');
            }
            $before = $period->toArray();
            $period->update(['locked_at' => null, 'locked_by' => null]);
            AuditService::record('period.reopened', $period, $before, $period->toArray(), $reason, $actor);
        }, 3);
    }

    private function open(Period $period): void
    {
        if ($period->locked_at) {
            $this->error('Periode sudah ditutup.');
        }
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
