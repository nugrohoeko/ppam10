<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\TariffScheme;
use App\Services\AuditService;
use App\Services\TariffService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TariffController extends Controller
{
    public function index(Request $r)
    {
        $r->user()->requirePermission('tariffs.view');

        return view('tariffs.index', ['categories' => Category::orderBy('name')->get(), 'schemes' => TariffScheme::with(['category', 'tiers'])->orderByDesc('effective_from')->get(), 'editing' => $r->filled('edit') ? TariffScheme::with('tiers')->findOrFail($r->edit) : null]);
    }

    public function category(Request $r)
    {
        $r->user()->requirePermission('tariffs.create');
        $data = $r->validate(['name' => 'required|string|max:120|unique:categories,name']);
        $category = Category::create([...$data, 'active' => true]);
        AuditService::record('category.created', $category, [], $category->toArray());

        return back()->with('success', 'Kategori pelanggan ditambahkan.');
    }

    public function categoryUpdate(Request $r, Category $category)
    {
        $r->user()->requirePermission('tariffs.update');
        $data = $r->validate(['name' => 'required|string|max:120|unique:categories,name,'.$category->id, 'active' => 'required|boolean']);
        $before = $category->toArray();
        $category->update($data);
        AuditService::record('category.updated', $category, $before, $category->toArray());

        return back()->with('success', 'Kategori diperbarui.');
    }

    public function store(Request $r, TariffService $s)
    {
        return $this->save($r, $s);
    }

    public function update(Request $r, TariffScheme $tariff, TariffService $s)
    {
        return $this->save($r, $s, $tariff);
    }

    private function save(Request $r, TariffService $s, ?TariffScheme $tariff = null)
    {
        $r->user()->requirePermission($tariff ? 'tariffs.update' : 'tariffs.create');
        $data = $r->validate(['category_id' => 'required|exists:categories,id', 'name' => 'required|string|max:160', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'method' => 'required|in:progressive,single', 'base_mode' => 'required|in:included,additive,minimum', 'base_amount' => 'required|integer|min:0|max:999999999999', 'included_usage' => 'required|regex:/^\d{1,10}(\.\d{1,3})?$/', 'admin_fee' => 'required|integer|min:0', 'additional_fee' => 'required|integer|min:0', 'discount_type' => 'required|in:nominal,percent', 'discount_value' => 'required|numeric|min:0', 'penalty_amount' => 'required|integer|min:0', 'grace_days' => 'required|integer|min:0|max:365', 'rounding_unit' => 'required|integer|min:1|max:10000', 'rounding_mode' => 'required|in:half_up,up,down', 'tiers' => 'required|array|min:1', 'tiers.*.lower_bound' => 'required|regex:/^\d{1,10}(\.\d{1,3})?$/', 'tiers.*.upper_bound' => 'nullable|regex:/^\d{1,10}(\.\d{1,3})?$/', 'tiers.*.rate' => 'required|integer|min:0|max:999999999999']);
        DB::transaction(function () use ($r, $s, $data, $tariff) {
            Category::whereKey($data['category_id'])->lockForUpdate()->firstOrFail();
            if ($tariff) {
                $tariff = TariffScheme::whereKey($tariff->id)->lockForUpdate()->firstOrFail();
                if ($tariff->locked_at || $tariff->invoices()->exists()) {
                    throw ValidationException::withMessages(['tariff' => 'Versi sudah dipakai. Buat versi baru untuk mengubah tarif.']);
                }
            }
            $tiers = $data['tiers'];
            unset($data['tiers']);
            $s->validate($data, $tiers);
            $overlap = TariffScheme::where('category_id', $data['category_id'])->when($tariff, fn ($q) => $q->where('id', '!=', $tariff->id))->where('effective_from', '<=', $data['effective_until'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $data['effective_from']))->lockForUpdate()->get();
            if ($overlap->isNotEmpty()) {
                if (! $tariff && $r->boolean('supersede') && $overlap->count() === 1 && $overlap->first()->effective_from->format('Y-m-d') < $data['effective_from']) {
                    $old = $overlap->first();
                    $before = $old->toArray();
                    $old->effective_until = Carbon::parse($data['effective_from'])->subDay();
                    $old->save();
                    AuditService::record('tariff.validity_closed', $old, $before, $old->toArray(), 'Digantikan versi baru');
                } else {
                    throw ValidationException::withMessages(['effective_from' => 'Tanggal berlaku bertumpang tindih dengan versi lain. Gunakan penggantian versi atau rentang berbeda.']);
                }
            }
            $before = $tariff?->toArray() ?? [];
            if ($tariff) {
                $tariff->update($data);
                $tariff->tiers()->delete();
            } else {
                $data['version'] = (int) TariffScheme::where('category_id', $data['category_id'])->max('version') + 1;
                $tariff = TariffScheme::create($data);
            }
            foreach (array_values($tiers) as $i => $tier) {
                $tariff->tiers()->create([...$tier, 'upper_bound' => $tier['upper_bound'] ?: null, 'position' => $i + 1]);
            }AuditService::record('tariff.saved', $tariff, $before, $tariff->load('tiers')->toArray());
        });

        return redirect()->route('tariffs.index')->with('success', 'Versi tarif tersimpan. Tagihan historis tetap memakai snapshot.');
    }

    public function simulate(Request $r, TariffService $s)
    {
        $r->user()->requirePermission('tariffs.view');
        $result = null;
        $scheme = null;
        if ($r->filled('scheme_id')) {
            $r->validate(['scheme_id' => 'required|exists:tariff_schemes,id', 'usage' => 'required|regex:/^\d{1,10}(\.\d{1,3})?$/']);
            $scheme = TariffScheme::with('tiers')->findOrFail($r->scheme_id);
            $result = $s->calculate($scheme, $r->usage);
        }

return view('tariffs.simulate',['schemes' => TariffScheme::with('category')->orderByDesc('effective_from')->get(), 'result' => $result, 'scheme' => $scheme]);
    }
}
