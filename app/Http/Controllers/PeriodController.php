<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Services\AuditService;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodController extends Controller
{
    public function index(Request $r)
    {
        $r->user()->requirePermission('periods.view');

        return view('periods.index', ['periods' => Period::withCount(['readings', 'invoices'])->orderByDesc('starts_on')->paginate(24)]);
    }

    public function store(Request $r)
    {
        $r->user()->requirePermission('periods.create');
        $data = $r->validate(['month' => 'required|date_format:Y-m', 'due_date' => 'required|date']);
        $start = Carbon::createFromFormat('Y-m-d', $data['month'].'-01')->startOfMonth();
        DB::transaction(function () use ($start, $data) {
            if (Period::where('name', $data['month'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['month' => 'Periode sudah ada.']);
            }$p = Period::create(['name' => $data['month'], 'starts_on' => $start, 'ends_on' => $start->copy()->endOfMonth(), 'due_date' => $data['due_date']]);
            AuditService::record('period.created', $p, [], $p->toArray());
        });

        return back()->with('success', 'Periode bulanan ditambahkan.');
    }

    public function close(Request $r, Period $period, BillingService $s)
    {
        $r->validate(['reason' => 'required|string|min:5']);
        $s->closePeriod($r->user(), $period, $r->reason);

        return back()->with('success', 'Periode dikunci.');
    }

    public function reopen(Request $r, Period $period, BillingService $s)
    {
        $r->validate(['reason' => 'required|string|min:5']);
        $s->reopenPeriod($r->user(), $period, $r->reason);

        return back()->with('success','Periode dibuka kembali dengan audit.');
    }
}
