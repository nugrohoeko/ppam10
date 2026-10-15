<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Meter;
use App\Models\MeterReplacement;
use App\Models\Period;
use App\Models\Reading;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MeterService
{
    public function save(User $actor, Customer $customer, Period $period, array $data): Reading
    {
        $actor->requirePermission('readings.create');
        abort_unless($actor->canAccessCustomer($customer), 403);
        validator($data, ['current_reading' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'], 'status' => 'required|in:draft,submitted', 'notes' => 'nullable|string|max:5000', 'photo_path' => 'nullable|string|max:255'])->validate();
        if ($data['status'] === 'submitted') {
            $actor->requirePermission('readings.submit');
        }

        return DB::transaction(function () use ($actor, $customer, $period, $data) {
            $period = Period::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($customer->status !== 'active' && SettingService::get('inactive_policy', 'block') === 'block') {
                $this->error('Pelanggan tidak aktif; aktifkan dahulu atau ubah kebijakan pelanggan nonaktif.');
            }
            if ($customer->joined_on->gt($period->ends_on)) {
                $this->error('Pelanggan belum berlangganan pada periode ini.');
            }
            $existing = Reading::where('customer_id', $customer->id)->where('period_id', $period->id)->lockForUpdate()->first();
            if ($existing) {
                $actor->requirePermission('readings.update');
                if (! in_array($existing->status, ['draft', 'rejected'], true)) {
                    $this->error('Pencatatan sudah diajukan atau dikunci. Gunakan prosedur koreksi.');
                }
            }
            $meter = $customer->activeMeter()->lockForUpdate()->first();
            if (! $meter) {
                $this->error('Pelanggan belum memiliki meter aktif.');
            }
            if ($meter->installed_on->gt($period->ends_on)) {
                $this->error('Meter belum terpasang pada periode ini.');
            }
            if (Reading::where('customer_id', $customer->id)->whereHas('period', fn ($q) => $q->whereDate('starts_on', '>', $period->starts_on))->whereIn('status', ['submitted', 'verified', 'locked'])->exists()) {
                $this->error('Periode berikutnya sudah dicatat. Selesaikan koreksi secara berurutan.');
            }
            $previous = Reading::with('period')->where('customer_id', $customer->id)->whereHas('period', fn ($q) => $q->whereDate('starts_on', '<', $period->starts_on))
                ->join('periods', 'readings.period_id', '=', 'periods.id')->select('readings.*')->orderByDesc('periods.starts_on')->first();
            if ($previous && ! in_array($previous->status, ['verified', 'locked'], true)) {
                $this->error('Pencatatan periode sebelumnya harus disahkan dahulu.');
            }
            $expectedPreviousMonth = $period->starts_on->copy()->subMonthNoOverflow()->format('Y-m');
            if ($previous && $previous->period->starts_on->format('Y-m') !== $expectedPreviousMonth) {
                $this->error('Ada periode terlewat. Catat dan sahkan periode yang terlewat sebelum melanjutkan; selisih tidak boleh dibebankan ke satu bulan.');
            }
            $firstMeter = $customer->meters()->orderBy('installed_on')->orderBy('id')->first();
            if (! $previous && $firstMeter->installed_on->lt($period->starts_on->copy()->subDay()) && $customer->joined_on->lt($period->starts_on)) {
                $this->error('Belum ada angka sah periode sebelumnya. Catat periode sejak pemasangan meter atau masukkan baseline migrasi pada akhir bulan sebelumnya; jangan gabungkan beberapa bulan.');
            }
            $current = Decimal::normalize((string) $data['current_reading'], (int) SettingService::get('meter_precision', 3));
            $baseline = $previous && $previous->meter_id === $meter->id ? $previous->current_reading : $meter->initial_reading;
            if (Decimal::cmp($current, $baseline) < 0) {
                $this->error('Angka meter sekarang tidak boleh lebih kecil dari angka sebelumnya. Gunakan penggantian meter yang sah.');
            }
            $usage = Decimal::sub($current, $baseline);
            $segments = [];
            $replacementQuery = MeterReplacement::with('oldMeter')->where('customer_id', $customer->id)->whereDate('replaced_on', '<=', $period->ends_on);
            if ($previous) {
                $replacementQuery->whereDate('replaced_on', '>', $previous->period->ends_on);
            } else {
                $replacementQuery->whereDate('replaced_on', '>=', $period->starts_on);
            }
            foreach ($replacementQuery->orderBy('replaced_on')->orderBy('id')->get() as $replacement) {
                $oldBaseline = $previous && $previous->meter_id === $replacement->old_meter_id ? $previous->current_reading : $replacement->oldMeter->initial_reading;
                $oldUsage = Decimal::sub($replacement->old_final, $oldBaseline);
                if (Decimal::cmp($oldUsage, '0') < 0) {
                    $this->error('Riwayat penggantian memiliki angka akhir yang tidak sah.');
                }
                $usage = Decimal::add($usage, $oldUsage);
                $segments[] = ['serial' => $replacement->oldMeter->serial, 'previous' => $oldBaseline, 'current' => $replacement->old_final, 'usage' => $oldUsage];
            }
            $segments[] = ['serial' => $meter->serial, 'previous' => $baseline, 'current' => $current, 'usage' => Decimal::sub($current, $baseline)];
            $warning = null;
            if ($previous && Decimal::cmp($previous->usage, '0') > 0) {
                $change = bcdiv(Decimal::mul(Decimal::sub($usage, $previous->usage), '100'), $previous->usage, 3);
                if (Decimal::cmp($change, (string) SettingService::get('usage_spike_percent', 100)) > 0) {
                    $warning = 'Pemakaian melonjak '.$change.'% dari periode sebelumnya.';
                }
                if (Decimal::cmp($change, '-'.SettingService::get('usage_drop_percent', 70)) < 0) {
                    $warning = 'Pemakaian turun drastis '.$change.'% dari periode sebelumnya.';
                }
            }
            $customer->load('rt', 'category');
            $snapshot = ['number' => $customer->number, 'name' => $customer->name, 'address' => $customer->address, 'phone' => $customer->phone,
                'rt_id' => $customer->rt_id, 'rt_name' => $customer->rt->name, 'rt_code' => $customer->rt->code, 'rw' => $customer->rt->rw,
                'category_id' => $customer->category_id, 'category_name' => $customer->category->name, 'joined_on' => $customer->joined_on->toDateString(), 'meter_segments' => $segments];
            $status = $data['status'];
            if ($status === 'submitted' && ! SettingService::get('reading_verification', true)) {
                $status = 'verified';
            }
            $before = $existing?->toArray() ?? [];
            $reading = $existing ?? new Reading;
            $reading->fill(['customer_id' => $customer->id, 'meter_id' => $meter->id, 'period_id' => $period->id, 'user_id' => $actor->id,
                'previous_reading' => $baseline, 'current_reading' => $current, 'usage' => $usage, 'status' => $status,
                'photo_path' => $data['photo_path'] ?? $existing?->photo_path, 'notes' => $data['notes'] ?? null, 'warning' => $warning, 'customer_snapshot' => $snapshot,
                'verified_by' => $status === 'verified' ? $actor->id : null, 'verified_at' => $status === 'verified' ? now() : null])->save();
            AuditService::record($existing ? 'reading.updated' : 'reading.created', $reading, $before, $reading->toArray(), null, $actor);

            return $reading;
        }, 3);
    }

    public function verify(User $actor, Reading $reading, bool $approved, ?string $reason = null): Reading
    {
        $actor->requirePermission('readings.approve');
        abort_unless($actor->canAccessCustomer($reading->customer), 403);
        if (! $approved && ! trim((string) $reason)) {
            $this->error('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($actor, $reading, $approved, $reason) {
            $period = Period::whereKey($reading->period_id)->lockForUpdate()->firstOrFail();
            $this->open($period);
            $reading = Reading::whereKey($reading->id)->lockForUpdate()->firstOrFail();
            if ($reading->status !== 'submitted') {
                $this->error('Hanya pencatatan yang diajukan dapat diverifikasi.');
            }
            $before = $reading->toArray();
            $reading->update(['status' => $approved ? 'verified' : 'rejected', 'verified_by' => $actor->id, 'verified_at' => now()]);
            AuditService::record($approved ? 'reading.verified' : 'reading.rejected', $reading, $before, $reading->toArray(), $reason, $actor);

            return $reading;
        });
    }

    public function replace(User $actor, Customer $customer, array $data): Meter
    {
        $actor->requirePermission('meters.update');
        abort_unless($actor->canAccessCustomer($customer), 403);
        validator($data, ['serial' => 'required|string|max:100|unique:meters,serial', 'old_final' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'], 'new_initial' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'], 'replaced_on' => 'required|date', 'reason' => 'required|string|min:5|max:5000'])->validate();

        return DB::transaction(function () use ($actor, $customer, $data) {
            $date = Carbon::parse($data['replaced_on']);
            $period = Period::whereDate('starts_on', '<=', $data['replaced_on'])->whereDate('ends_on', '>=', $data['replaced_on'])->lockForUpdate()->first();
            if ($period) {
                $this->open($period);
            }
            Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $old = $customer->activeMeter()->lockForUpdate()->firstOrFail();
            if ($date->lt($old->installed_on)) {
                $this->error('Tanggal penggantian mendahului pemasangan meter lama.');
            }
            $last = $old->readings()->with('period')->whereIn('status', ['submitted', 'verified', 'locked'])->join('periods', 'readings.period_id', '=', 'periods.id')->select('readings.*')->orderByDesc('periods.starts_on')->first();
            if ($last && $last->period->ends_on->gte($date)) {
                $this->error('Sudah ada pencatatan sah pada/sesudah tanggal penggantian. Batalkan tagihan dan koreksi pencatatan dahulu.');
            }
            $priorMonth = $date->copy()->startOfMonth()->subMonth()->format('Y-m');
            if (($last && $last->period->starts_on->format('Y-m') !== $priorMonth) || (! $last && $old->installed_on->lt($date->copy()->startOfMonth()->subDay()))) {
                $this->error('Selesaikan pencatatan periode yang terlewat sebelum mengganti meter agar riwayat pemakaian dapat dihitung.');
            }
            $final = Decimal::normalize((string) $data['old_final']);
            if (Decimal::cmp($final, $last?->current_reading ?? $old->initial_reading) < 0) {
                $this->error('Angka akhir meter lama tidak boleh menurun.');
            }
            $before = $old->toArray();
            $old->update(['active' => false, 'retired_on' => $data['replaced_on'], 'final_reading' => $final]);
            $new = $customer->meters()->create(['serial' => $data['serial'], 'initial_reading' => Decimal::normalize((string) $data['new_initial']), 'installed_on' => $data['replaced_on'], 'active' => true]);
            MeterReplacement::create(['customer_id' => $customer->id, 'old_meter_id' => $old->id, 'new_meter_id' => $new->id, 'old_final' => $final, 'new_initial' => $new->initial_reading, 'replaced_on' => $data['replaced_on'], 'user_id' => $actor->id, 'reason' => $data['reason']]);
            AuditService::record('meter.replaced', $new, $before, $new->toArray(), $data['reason'], $actor);

            return $new;
        }, 3);
    }

    public function correct(User $actor, Reading $reading, array $data): Reading
    {
        $actor->requirePermission('readings.approve');
        abort_unless($actor->canAccessCustomer($reading->customer), 403);
        validator($data, ['reason' => 'required|string|min:5|max:5000'])->validate();

        return DB::transaction(function () use ($actor, $reading, $data) {
            $this->open(Period::whereKey($reading->period_id)->lockForUpdate()->firstOrFail());
            Customer::whereKey($reading->customer_id)->lockForUpdate()->firstOrFail();
            $reading = Reading::whereKey($reading->id)->lockForUpdate()->firstOrFail();
            if ($reading->invoices()->where('status', '!=', 'cancelled')->exists()) {
                $this->error('Batalkan tagihan terlebih dahulu sebelum koreksi pencatatan.');
            }
            $before = $reading->toArray();
            $reading->update(['status' => 'draft', 'verified_by' => null, 'verified_at' => null]);
            $result = $this->save($actor, $reading->customer, $reading->period, array_merge($data, ['status' => 'submitted']));
            if ($result->status === 'submitted') {
                $result = $this->verify($actor, $result, true, $data['reason']);
            }
            AuditService::record('reading.corrected', $result, $before, $result->toArray(), $data['reason'], $actor);

            return $result;
        });
    }

    private function open(Period $period): void
    {
        if ($period->locked_at) {
            $this->error('Periode telah ditutup. Pembukaan kembali wajib dilakukan dengan alasan oleh pihak berwenang.');
        }
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['reading' => $message]);
    }
}
