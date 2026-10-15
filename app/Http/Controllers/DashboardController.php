<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Models\Rt;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $r, DashboardService $s)
    {
        $periods = Period::orderByDesc('starts_on')->get();
        $period = $periods->firstWhere('id', (int) $r->period_id) ?? $periods->first();
        $rts = $r->user()->role?->slug === 'operator' ? $r->user()->rts()->get() : Rt::orderBy('code')->get();

        return view('dashboard', [...$s->build($r->user(), $period, $r->filled('rt_id') ? (int) $r->rt_id : null), 'periods' => $periods, 'period' => $period, 'rts' => $rts]);
    }
}
