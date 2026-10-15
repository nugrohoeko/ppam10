<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Rt;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function index(Request $r)
    {
        $r->user()->requirePermission('customers.view');
        $q = $r->user()->scopeCustomers(Customer::query())->with(['rt', 'category', 'activeMeter']);
        if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('number', 'like', '%'.$r->q.'%')->orWhere('address', 'like', '%'.$r->q.'%'));
        } foreach (['rt_id', 'category_id', 'status'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        }

        return view('customers.index', ['customers' => $q->orderBy('number')->paginate(20)->withQueryString(), ...$this->options($r)]);
    }

    private function options(Request $r)
    {
        return ['rts' => $r->user()->role?->slug === 'operator' ? $r->user()->rts()->get() : Rt::orderBy('code')->get(), 'categories' => Category::orderBy('name')->get()];
    }

    public function create(Request $r)
    {
        Gate::forUser($r->user())->authorize('create', Customer::class);

        return view('customers.form', ['customer' => new Customer, ...$this->options($r)]);
    }

    public function store(Request $r, CustomerService $s)
    {
        Gate::forUser($r->user())->authorize('create', Customer::class);
        $r->validate(['rt_id' => 'required|exists:rts,id', 'photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120']);
        if ($r->user()->role?->slug === 'operator') {
            abort_unless($r->user()->rts()->where('rts.id', $r->rt_id)->exists(), 403);
        }
        $data = $r->except(['photo_path', 'proof_path', 'attachment_path']);
        $uploaded = null;
        try {
            if ($r->hasFile('photo')) {
                $uploaded = $r->file('photo')->store('customer-photos', 'local');
                $data['photo_path'] = $uploaded;
            }
            $customer = $s->save($r->user(), $data);
        } catch (\Throwable $exception) {
            if ($uploaded) {
                Storage::disk('local')->delete($uploaded);
            }throw $exception;
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Pelanggan berhasil didaftarkan.');
    }

    public function show(Request $r, Customer $customer)
    {
        $r->user()->requirePermission('customers.view');
        abort_unless($r->user()->canAccessCustomer($customer), 403);
        $customer->load(['rt', 'category', 'meters', 'readings.period', 'invoices.period', 'payments', 'histories']);

        return view('customers.show', compact('customer'));
    }

    public function edit(Request $r, Customer $customer)
    {
        $r->user()->requirePermission('customers.update');
        abort_unless($r->user()->canAccessCustomer($customer), 403);

        return view('customers.form', ['customer' => $customer, ...$this->options($r)]);
    }

    public function update(Request $r, Customer $customer, CustomerService $s)
    {
        $r->user()->requirePermission('customers.update');
        abort_unless($r->user()->canAccessCustomer($customer), 403);
        $r->validate(['rt_id' => 'required|exists:rts,id', 'status' => 'required|in:active,inactive,disconnected', 'photo' => 'nullable|image|mimes:jpeg,png,webp|max:5120']);
        if ($customer->status !== $r->status) {
            $r->user()->requirePermission('customers.deactivate');
        }
        if ($r->user()->role?->slug === 'operator') {
            abort_unless($r->user()->rts()->where('rts.id', $r->rt_id)->exists(), 403);
        }
        $data = $r->except(['photo_path', 'proof_path', 'attachment_path']);
        $uploaded = null;
        try {
            if ($r->hasFile('photo')) {
                $uploaded = $r->file('photo')->store('customer-photos', 'local');
                $data['photo_path'] = $uploaded;
            }
            $s->save($r->user(), $data, $customer);
        } catch (\Throwable $exception) {
            if ($uploaded) {
                Storage::disk('local')->delete($uploaded);
            }throw $exception;
        }

        return redirect()->route('customers.show', $customer)->with('success', 'Data pelanggan diperbarui; riwayat tersimpan.');
    }

    public function destroy(Request $r, Customer $customer, CustomerService $service)
    {
        Gate::forUser($r->user())->authorize('delete', $customer);
        $data = $r->validate(['reason' => 'required|string|min:5|max:2000']);
        $number = $customer->number;
        $name = $customer->name;
        $service->delete($r->user(), $customer, $data['reason']);

        return redirect()->route('customers.index')->with('success', 'Pelanggan '.$number.' — '.$name.' berhasil dihapus dari daftar. Riwayat transaksi tetap tersimpan.');
    }
}
