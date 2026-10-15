<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Rt;
use App\Services\MeterService;
use App\Services\SettingService;
use App\Services\TariffService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReadingController extends Controller
{
    public function index(Request $r)
    {
        $r->user()->requirePermission('readings.view');
        $periods = Period::orderByDesc('starts_on')->get();
        $period = $periods->firstWhere('id', (int) $r->input('period_id')) ?? $periods->first();
        $base = $r->user()->scopeCustomers(Customer::query());
        if (SettingService::get('inactive_policy', 'block') === 'block') {
            $base->where('status', 'active');
        }if ($r->filled('rt_id')) {
            $base->where('rt_id', $r->rt_id);
        }$total = (clone $base)->count();
        $done = $period ? (clone $base)->whereHas('readings', fn ($q) => $q->where('period_id', $period->id))->count() : 0;
        $selected = null;
        $previous = '0.000';
        $existing = null;
        $estimateScheme = null;
        if ($r->filled('customer')) {
            $selected = (clone $base)->where(fn ($q) => $q->where('number', $r->customer)->orWhere('id', ctype_digit((string) $r->customer) ? $r->customer : 0))->with(['rt', 'category', 'activeMeter'])->first();
            if (! $selected) {
                return back()->withErrors(['customer' => 'Pelanggan tidak ditemukan pada wilayah Anda.']);
            }if ($period) {
                $existing = $selected->readings()->where('period_id', $period->id)->first();
                $last = $selected->readings()->where('meter_id', $selected->activeMeter?->id)->whereHas('period', fn ($q) => $q->where('starts_on', '<', $period->starts_on))->whereIn('status', ['verified', 'locked'])->latest('period_id')->first();
                $previous = $existing?->previous_reading ?? $last?->current_reading ?? $selected->activeMeter?->initial_reading ?? '0.000';
                try {
                    $estimateScheme = app(TariffService::class)->forCategory($selected->category_id, $period->starts_on->format('Y-m-d'))->load('tiers');
                } catch (ValidationException $e) {
                }
            }
        }
        if ($r->input('recorded') === 'no' && $period) {
            $base->whereDoesntHave('readings', fn ($q) => $q->where('period_id', $period->id));
        }if ($r->filled('q')) {
            $base->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('number', 'like', '%'.$r->q.'%'));
        }
        $customers = $base->with(['rt', 'activeMeter', 'readings' => fn ($q) => $q->where('period_id', $period?->id)])->orderBy('rt_id')->orderBy('name')->paginate(20)->withQueryString();
        $rts = $r->user()->role?->slug === 'operator' ? $r->user()->rts()->get() : Rt::orderBy('code')->get();
        $pending = Reading::whereIn('customer_id', $r->user()->scopeCustomers(Customer::query())->select('id'))->when($period, fn ($q) => $q->where('period_id', $period->id))->where('status', 'submitted')->with(['customer.rt', 'user'])->get();

        return view('readings.index', compact('periods', 'period', 'total', 'done', 'selected', 'previous', 'existing', 'customers', 'rts', 'pending', 'estimateScheme'));
    }

    public function store(Request $r, MeterService $s)
    {
        $r->user()->requirePermission('readings.create');
        $r->validate(['customer_id' => 'required|exists:customers,id', 'period_id' => 'required|exists:periods,id', 'status' => 'required|in:draft,submitted', 'photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120']);
        $customer = Customer::findOrFail($r->customer_id);
        abort_unless($r->user()->canAccessCustomer($customer), 403);
        if ($r->status === 'submitted') {
            $r->user()->requirePermission('readings.submit');
        }
        if ($customer->readings()->where('period_id', $r->period_id)->exists()) {
            $r->user()->requirePermission('readings.update');
        }
        $period = Period::findOrFail($r->period_id);
        $data = $r->except(['photo_path', 'proof_path', 'attachment_path']);
        $uploaded = null;
        try {
            if ($r->hasFile('photo')) {
                $uploaded = $r->file('photo')->store('meter-photos', 'local');
                $data['photo_path'] = $uploaded;
            }
            $s->save($r->user(), $customer, $period, $data);
        } catch (\Throwable $exception) {
            if ($uploaded) {
                Storage::disk('local')->delete($uploaded);
            }throw $exception;
        }

        return redirect()->route('readings.index', ['period_id' => $r->period_id, 'rt_id' => $r->rt_id])->with('success', 'Pencatatan meter berhasil disimpan.');
    }

    public function verify(Request $r, Reading $reading, MeterService $s)
    {
        $r->validate(['decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:2000']);
        $s->verify($r->user(), $reading, $r->decision === 'approve', $r->reason);

        return back()->with('success', 'Status verifikasi diperbarui.');
    }

    public function correct(Request $r, Reading $reading, MeterService $s)
    {
        $s->correct($r->user(), $reading, $r->except(['photo_path', 'proof_path', 'attachment_path']));

        return back()->with('success', 'Koreksi tercatat.');
    }

    public function estimate(Request $r, TariffService $s)
    {
        $r->user()->requirePermission('readings.view');
        $data = $r->validate(['customer_id' => 'required|exists:customers,id', 'period_id' => 'required|exists:periods,id', 'usage' => 'required|regex:/^\d{1,10}(\.\d{1,3})?$/']);
        $customer = Customer::findOrFail($data['customer_id']);
        abort_unless($r->user()->canAccessCustomer($customer), 403);
        $period = Period::findOrFail($data['period_id']);

        return response()->json($s->calculate($s->forCategory($customer->category_id,$period->starts_on->format('Y-m-d')),$data['usage']));
    }
}
