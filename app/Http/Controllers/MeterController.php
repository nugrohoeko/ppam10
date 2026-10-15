<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MeterReplacement;
use App\Services\MeterService;
use Illuminate\Http\Request;

class MeterController extends Controller
{
    public function index(Request $r)
    {
        $r->user()->requirePermission('meters.view');
        $customers = $r->user()->scopeCustomers(Customer::query())->with('activeMeter')->orderBy('name')->get();
        $replacements = MeterReplacement::whereIn('customer_id', $r->user()->scopeCustomers(Customer::withTrashed())->select('id'))->with(['customer', 'oldMeter', 'newMeter'])->latest()->paginate(20);

        return view('meters.index', compact('customers', 'replacements'));
    }

    public function store(Request $r, MeterService $s)
    {
        $r->user()->requirePermission('meters.update');
        $customer = Customer::findOrFail($r->input('customer_id'));
        $s->replace($r->user(), $customer, $r->all());

        return back()->with('success', 'Penggantian meter dicatat.');
    }
}
