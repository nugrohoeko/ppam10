<?php

namespace App\Services;

use App\Models\TariffScheme;
use Illuminate\Validation\ValidationException;

class TariffService
{
    public function forCategory(int $categoryId, string $date): TariffScheme
    {
        $matches = TariffScheme::with('tiers')->where('category_id', $categoryId)->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['tariff' => 'Harus ada tepat satu versi tarif yang berlaku untuk kategori dan periode ini.']);
        }

        return $matches->first();
    }

    public function validate(array $data, array $tiers): void
    {
        validator($data, [
            'category_id' => 'required|exists:categories,id', 'name' => 'required|string|max:255', 'version' => 'nullable|integer|min:1',
            'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'method' => 'required|in:progressive,single', 'base_mode' => 'required|in:included,additive,minimum',
            'base_amount' => 'required|integer|min:0|max:1000000000000', 'included_usage' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'],
            'admin_fee' => 'nullable|integer|min:0', 'additional_fee' => 'nullable|integer|min:0', 'discount_type' => 'required|in:nominal,percent',
            'discount_value' => 'required|numeric|min:0', 'penalty_amount' => 'nullable|integer|min:0', 'grace_days' => 'nullable|integer|min:0|max:365',
            'rounding_unit' => 'required|integer|min:1|max:100000', 'rounding_mode' => 'required|in:half_up,up,down',
        ])->validate();
        if ($data['discount_type'] === 'percent' && Decimal::cmp((string) $data['discount_value'], '100') > 0) {
            $this->error('Diskon persentase maksimal 100.');
        }
        if (! count($tiers)) {
            $this->error('Sedikitnya satu lapisan tarif wajib diisi.');
        }
        $cursor = $data['base_mode'] === 'included' ? Decimal::normalize((string) $data['included_usage']) : '0.000';
        if ($data['method'] === 'single' && count($tiers) !== 1) {
            $this->error('Tarif tunggal harus memiliki satu lapisan tanpa batas atas.');
        }
        foreach (array_values($tiers) as $index => $tier) {
            validator($tier, ['lower_bound' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'], 'upper_bound' => ['nullable', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'], 'rate' => 'required|integer|min:0|max:1000000000000'])->validate();
            if (Decimal::cmp((string) $tier['lower_bound'], $cursor) !== 0) {
                $this->error('Lapisan harus berurutan tanpa celah atau tumpang tindih; lapisan awal dimulai pada kuota yang termasuk abonemen (atau 0).');
            }
            $upper = $tier['upper_bound'] ?? null;
            if ($upper === '') {
                $upper = null;
            }
            if ($upper === null && $index !== count($tiers) - 1) {
                $this->error('Hanya lapisan terakhir yang boleh tanpa batas atas.');
            }
            if ($upper !== null && Decimal::cmp((string) $upper, $cursor) <= 0) {
                $this->error('Batas atas harus lebih besar dari batas bawah.');
            }
            if ($index === count($tiers) - 1 && $upper !== null) {
                $this->error('Lapisan terakhir wajib tanpa batas atas.');
            }
            $cursor = $upper === null ? $cursor : (string) $upper;
        }
    }

    public function calculate(TariffScheme $scheme, string $usage): array
    {
        $usage = Decimal::normalize($usage);
        $tiers = $scheme->tiers->sortBy('position');
        $lines = [];
        $variable = '0.000000';
        $base = (int) $scheme->base_amount;
        if ($scheme->base_mode !== 'minimum' && $base > 0) {
            $lines[] = ['description' => $scheme->base_mode === 'included' ? 'Abonemen termasuk '.$scheme->included_usage.' m³' : 'Abonemen', 'quantity' => '1', 'rate' => $base, 'amount' => (string) $base];
        }
        foreach ($tiers as $tier) {
            if (Decimal::cmp($usage, $tier->lower_bound) <= 0) {
                continue;
            }
            $end = $tier->upper_bound !== null && Decimal::cmp($usage, $tier->upper_bound) > 0 ? $tier->upper_bound : $usage;
            $quantity = Decimal::sub($end, $tier->lower_bound);
            $amount = Decimal::mul($quantity, $tier->rate);
            $variable = Decimal::add($variable, $amount, 6);
            $lines[] = ['description' => 'Pemakaian '.$tier->lower_bound.'–'.($tier->upper_bound ?? '∞').' m³', 'quantity' => $quantity, 'rate' => $tier->rate, 'amount' => $amount];
        }
        $rawWater = $scheme->base_mode === 'minimum' ? (Decimal::cmp($variable, $base) < 0 ? (string) $base : $variable) : Decimal::add($base, $variable, 6);
        if ($scheme->base_mode === 'minimum' && Decimal::cmp($variable, $base) < 0) {
            $lines[] = ['description' => 'Penyesuaian minimum tagihan', 'quantity' => '1', 'rate' => $base, 'amount' => Decimal::sub($base, $variable, 6)];
        }
        $additional = (int) $scheme->admin_fee + (int) $scheme->additional_fee;
        if ($additional) {
            $lines[] = ['description' => 'Administrasi dan biaya tambahan', 'quantity' => '1', 'rate' => $additional, 'amount' => (string) $additional];
        }
        $subtotal = Decimal::add($rawWater, $additional, 6);
        $discountRaw = $scheme->discount_type === 'percent' ? bcdiv(Decimal::mul($subtotal, $scheme->discount_value), '100', 6) : (string) $scheme->discount_value;
        if (Decimal::cmp($discountRaw, $subtotal) > 0) {
            $discountRaw = $subtotal;
        }
        $discount = Decimal::money($discountRaw);
        $rawTotal = Decimal::sub($subtotal, $discountRaw, 6);
        $unit = max(1, (int) $scheme->rounding_unit);
        $total = Decimal::money(bcdiv($rawTotal, (string) $unit, 6), $scheme->rounding_mode) * $unit;
        $water = Decimal::money($rawWater);
        $rounding = $total - ($water + $additional - $discount);
        if ($discount) {
            $lines[] = ['description' => 'Diskon / subsidi', 'quantity' => '1', 'rate' => -$discount, 'amount' => (string) -$discount];
        }
        if ($rounding) {
            $lines[] = ['description' => 'Pembulatan', 'quantity' => '1', 'rate' => $rounding, 'amount' => (string) $rounding];
        }

        return ['water_amount' => $water, 'additional_amount' => $additional, 'discount_amount' => $discount, 'penalty_amount' => 0, 'rounding_amount' => $rounding, 'total' => $total, 'lines' => $lines];
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['tiers' => $message]);
    }
}
