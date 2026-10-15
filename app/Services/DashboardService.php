<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function build(User $actor, ?Period $period, ?int $rt): array
    {
        $actor->requirePermission('dashboard.view');
        $allowedCustomers = $actor->scopeCustomers(Customer::withTrashed())->select('id');
        $customers = $actor->scopeCustomers(Customer::query())->when($rt, fn ($query) => $query->where('rt_id', $rt));
        $readingBase = Reading::whereIn('customer_id', $allowedCustomers)
            ->when($rt, fn ($query) => $query->where('customer_snapshot->rt_id', $rt));
        $readings = (clone $readingBase)->when($period, fn ($query) => $query->where('period_id', $period->id));
        $invoices = Invoice::whereIn('customer_id', $allowedCustomers)
            ->when($rt, fn ($query) => $query->where('customer_snapshot->rt_id', $rt))
            ->whereNotIn('status', ['draft', 'cancelled']);
        $current = (clone $invoices)->when($period, fn ($query) => $query->where('period_id', $period->id));
        $showGlobalFinance = $actor->hasPermission('finance.view') && ! $rt && $actor->role?->slug !== 'operator';
        $stats = [
            'active' => (clone $customers)->where('status', 'active')->count(),
            'done' => (clone $readings)->count(), 'usage' => (string) (clone $readings)->sum('usage'),
            'total' => 0, 'receipts' => 0, 'arrears' => 0, 'expense' => 0, 'balance' => null,
            'paid' => 0, 'partial' => 0, 'unpaid' => 0,
        ];
        if ($actor->hasPermission('invoices.view')) {
            $stats['total'] = (int) (clone $current)->sum('total');
            $stats['arrears'] = (int) (clone $invoices)->sum(DB::raw('total-paid_amount'));
            foreach (['paid', 'partial', 'issued'] as $status) {
                $stats[$status === 'issued' ? 'unpaid' : $status] = (clone $current)->where('status', $status)->count();
            }
        }
        if ($actor->hasPermission('payments.view')) {
            $stats['receipts'] = $this->receiptTotal($actor, $rt, $period?->starts_on->toDateString(), $period?->ends_on->toDateString());
        }
        if ($showGlobalFinance) {
            $stats['expense'] = $this->expenseTotal($period?->starts_on->toDateString(), $period?->ends_on->toDateString());
            $cash = CashEntry::where('status', 'posted')->when($period, fn ($query) => $query->whereDate('entry_date', '<=', $period->ends_on->toDateString()));
            $stats['balance'] = (int) Account::sum('opening_balance') + (int) $cash
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
                ->value('net');
        }
        $byRt = Rt::when($actor->role?->slug === 'operator', fn ($query) => $query->whereIn('id', $actor->rts()->select('rts.id')))
            ->when($rt, fn ($query) => $query->whereKey($rt))->orderBy('code')->get()
            ->map(function ($area) use ($customers, $readings) {
                $area->customer_count = (clone $customers)->where('rt_id', $area->id)->where('status', 'active')->count();
                $area->done_count = (clone $readings)->where('customer_snapshot->rt_id', $area->id)->count();
                $area->usage_total = (string) (clone $readings)->where('customer_snapshot->rt_id', $area->id)->sum('usage');

                return $area;
            });

        $chart = ['labels' => [], 'usage' => [], 'income' => [], 'expense' => [], 'showExpense' => $showGlobalFinance];
        $anchor = $period?->starts_on ?? now()->startOfMonth();
        for ($index = 5; $index >= 0; $index--) {
            $month = $anchor->copy()->startOfMonth()->subMonths($index);
            $from = $month->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();
            $chart['labels'][] = $month->translatedFormat('M Y');
            $chart['usage'][] = (string) (clone $readingBase)->whereHas('period', fn ($query) => $query->whereDate('starts_on', '>=', $from)->whereDate('starts_on', '<=', $to))->sum('usage');
            $chart['income'][] = $actor->hasPermission('payments.view') ? $this->receiptTotal($actor, $rt, $from, $to) : 0;
            $chart['expense'][] = $showGlobalFinance ? $this->expenseTotal($from, $to) : 0;
        }
        $overdue = $actor->hasPermission('invoices.view')
            ? (clone $invoices)->with('period')->whereIn('status', ['issued', 'partial'])->whereDate('due_date', '<', today()->toDateString())->orderBy('due_date')->limit(7)->get()
            : collect();

        $byCategory = (clone $customers)->where('status', 'active')->with('category')->get()->groupBy(fn ($customer) => $customer->category->name)->map->count();

        return compact('stats', 'byRt', 'byCategory', 'chart', 'overdue', 'showGlobalFinance');
    }

    private function receiptTotal(User $actor, ?int $rt, ?string $from, ?string $to): int
    {
        $query = $this->dates(CashEntry::where('status', 'posted')->whereNotNull('payment_id')
            ->whereHas('payment.customer', fn ($customers) => $actor->scopeCustomers($customers)), $from, $to);
        if (! $rt) {
            return (int) $query->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")->value('net');
        }
        // One receipt may settle invoices whose historical RT snapshots differ.
        // Attribute only matching allocations rather than the entire payment.
        $entries = $query->whereHas('payment.allocations.invoice', fn ($invoices) => $invoices->where('customer_snapshot->rt_id', $rt))
            ->with('payment.allocations.invoice')->get();

        return $entries->reduce(function (int $total, CashEntry $entry) use ($rt) {
            $amount = (int) $entry->payment->allocations->filter(fn ($allocation) => (int) ($allocation->invoice->customer_snapshot['rt_id'] ?? 0) === $rt)->sum('amount');

            return $total + ($entry->direction === 'in' ? $amount : -$amount);
        }, 0);
    }

    private function expenseTotal(?string $from, ?string $to): int
    {
        return (int) $this->dates(CashEntry::where('status', 'posted')->whereNull('payment_id')->whereNull('transfer_key')
            ->whereHas('category', fn ($categories) => $categories->where('type', 'expense')), $from, $to)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE -amount END), 0) AS net")->value('net');
    }

    private function dates(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query->when($from, fn ($query) => $query->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('entry_date', '<=', $to));
    }
}
