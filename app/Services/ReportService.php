<?php

namespace App\Services;

use App\Jobs\GenerateReportExport;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Period;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    public const TYPES = [
        'customers' => 'Data pelanggan dan rekening per RT',
        'readings' => 'Pencatatan dan pemakaian air',
        'unrecorded' => 'Pelanggan belum dicatat',
        'invoices' => 'Tagihan',
        'paid' => 'Pelanggan lunas',
        'unpaid' => 'Pelanggan belum membayar',
        'partial' => 'Pelanggan dibayar sebagian',
        'payment_status' => 'Seluruh status pembayaran',
        'arrears' => 'Tunggakan dan umur piutang',
        'receipts' => 'Penerimaan pembayaran',
        'expenses' => 'Pengeluaran',
        'cashbook' => 'Buku kas dan saldo kas/bank',
        'summary' => 'Rekap bulanan dan tahunan',
        'operators' => 'Kinerja pencatatan operator',
        'audit' => 'Koreksi, pembatalan, dan audit aktivitas',
    ];

    public static function canViewType(User $actor, string $type): bool
    {
        $required = match ($type) {
            'customers' => ['customers.view'],
            'readings', 'unrecorded', 'operators' => ['readings.view'],
            'invoices', 'paid', 'unpaid', 'partial', 'payment_status', 'arrears' => ['invoices.view'],
            'receipts' => ['payments.view'],
            'expenses', 'cashbook' => ['finance.view'],
            'summary' => ['invoices.view', 'payments.view', 'finance.view'],
            'audit' => ['audit.view'],
            default => [],
        };

        return collect($required)->every(fn ($permission) => $actor->hasPermission($permission));
    }

    public function build(User $actor, array $filters): array
    {
        $type = $filters['type'] ?? 'customers';
        if (! $actor->hasPermission('reports.view')) {
            GenerateReportExport::requireExportPermission($actor, $filters);
        }
        if (! empty($filters['rt_ids'])) {
            $filters['rt_ids'] = array_map('intval', $filters['rt_ids']);
        }
        abort_unless(isset(self::TYPES[$type]), 422, 'Jenis laporan tidak dikenal.');
        abort_unless(self::canViewType($actor, $type), 403, 'Anda tidak memiliki izin untuk data laporan ini.');
        if ($type === 'audit') {
            $actor->requirePermission('audit.view');
        }
        if (in_array($type, ['expenses', 'cashbook'])) {
            $actor->requirePermission('finance.view');
        }
        [$columns, $rows] = match ($type) {
            'customers' => $this->customers($actor, $filters),
            'readings' => $this->readings($actor, $filters),
            'unrecorded' => $this->unrecorded($actor, $filters),
            'invoices', 'paid', 'unpaid', 'partial', 'payment_status', 'arrears' => $this->invoices($actor, $filters, $type),
            'receipts' => $this->receipts($actor, $filters),
            'expenses', 'cashbook' => $this->cashbook($actor, $filters, $type),
            'summary' => $this->summary($actor, $filters),
            'operators' => $this->operators($actor, $filters),
            'audit' => $this->audit($actor, $filters),
        };
        $rows = collect($rows)->values()->all();
        $rtTotals = [];
        foreach (collect($rows)->groupBy('rt') as $rt => $group) {
            if ($rt !== '') {
                $rtTotals[$rt] = $this->totals($columns, $group);
            }
        }
        $filterLabels = $filters;
        if (! empty($filters['period_id'])) {
            $filterLabels['periode tagihan'] = Period::find($filters['period_id'])?->name;
            unset($filterLabels['period_id']);
        }
        unset($filterLabels['type']);

        return [
            'title' => self::TYPES[$type], 'columns' => $columns, 'rows' => $rows,
            'totals' => $this->totals($columns, collect($rows)), 'rtTotals' => $rtTotals,
            'filters' => $filterLabels, 'identity' => [
                'name' => SettingService::get('pam_name', 'PAM RW.10'),
                'address' => SettingService::get('pam_address', ''),
                'contact' => SettingService::get('pam_contact', ''),
            ],
        ];
    }

    private function columns(array $definitions): array
    {
        return collect($definitions)->map(fn ($v, $key) => ['key' => $key, 'label' => $v[0], 'type' => $v[1] ?? 'text'])->values()->all();
    }

    private function customerQuery(User $actor, array $f, bool $includeDeleted = false): Builder
    {
        $query = $includeDeleted ? Customer::withTrashed() : Customer::query();

        return $actor->scopeCustomers($query)
            ->when(! empty($f['rt_ids']), fn ($q) => $q->whereIn('rt_id', $f['rt_ids']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('category_id', $f['category_id']))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$f['q'].'%')->orWhere('number', 'like', '%'.$f['q'].'%')->orWhere('address', 'like', '%'.$f['q'].'%')))
            ->when(! empty($f['customer_status']), fn ($q) => $q->where('status', $f['customer_status']));
    }

    private function transactionQuery(string $model, User $actor, array $f): Builder
    {
        // Territory always follows the currently assigned customer. Historical filters
        // follow the immutable snapshot so a move does not rewrite a prior RT report.
        $q = $model::query()->whereIn('customer_id', $actor->scopeCustomers(Customer::withTrashed())->select('id'));
        $q->when(! empty($f['q']), fn ($q) => $q->whereHas('customer', fn ($c) => $c->where(fn ($c) => $c->where('name', 'like', '%'.$f['q'].'%')->orWhere('number', 'like', '%'.$f['q'].'%')->orWhere('address', 'like', '%'.$f['q'].'%'))));
        $q->when(! empty($f['customer_status']), fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('status', $f['customer_status'])));
        $q->when(! empty($f['rt_ids']), fn ($q) => $q->whereIn('customer_snapshot->rt_id', $f['rt_ids']));
        $q->when(! empty($f['category_id']), fn ($q) => $q->where('customer_snapshot->category_id', (int) $f['category_id']));
        $q->when(! empty($f['period_id']), fn ($q) => $q->where('period_id', $f['period_id']));
        if ($model === Reading::class) {
            $q->when(! empty($f['operator_id']), fn ($q) => $q->where('user_id', $f['operator_id']));
            $q->when(! empty($f['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $f['date_from']));
            $q->when(! empty($f['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $f['date_to']));
        } else {
            $q->when(! empty($f['operator_id']), fn ($q) => $q->whereHas('reading', fn ($r) => $r->where('user_id', $f['operator_id'])));
            if (! empty($f['account_id']) || ! empty($f['payment_method_id']) || ! empty($f['date_from']) || ! empty($f['date_to'])) {
                $q->whereHas('allocations.payment', fn ($p) => $this->paymentFilters($p, $f));
            }
            $q->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']));
        }

        return $q;
    }

    private function paymentFilters(Builder $q, array $f): Builder
    {
        return $q->where('status', 'posted')
            ->when(! empty($f['date_from']), fn ($q) => $q->whereDate('paid_on', '>=', $f['date_from']))
            ->when(! empty($f['date_to']), fn ($q) => $q->whereDate('paid_on', '<=', $f['date_to']))
            ->when(! empty($f['account_id']), fn ($q) => $q->where('account_id', $f['account_id']))
            ->when(! empty($f['payment_method_id']), fn ($q) => $q->where('payment_method_id', $f['payment_method_id']));
    }

    private function snapshot($record): array
    {
        $s = $record->customer_snapshot ?? [];
        $c = $record->customer;

        return [
            'number' => $s['number'] ?? $c->number, 'name' => $s['name'] ?? $c->name,
            'address' => $s['address'] ?? $c->address,
            'rt' => $s['rt_name'] ?? ($s['rt']['name'] ?? $c->rt?->name ?? '-'),
            'category' => $s['category_name'] ?? ($s['category']['name'] ?? $c->category?->name ?? '-'),
        ];
    }

    private function invoiceColumns(bool $age = false): array
    {
        return $this->columns([
            'number' => ['ID pelanggan'], 'name' => ['Nama'], 'rt' => ['RT'], 'category' => ['Kategori'],
            'invoice' => ['No. tagihan'], 'period' => ['Periode'], 'usage' => ['Pemakaian m³', 'decimal'],
            'total' => ['Tagihan Rp', 'money'], 'paid' => ['Pembayaran terpilih Rp', 'money'],
            'balance' => ['Sisa kini Rp', 'money'], 'payment_dates' => ['Tanggal pembayaran'], 'status' => ['Status'],
        ] + ($age ? ['due_date' => ['Jatuh tempo'], 'age_days' => ['Umur hari', 'age'], 'age_bucket' => ['Kelompok umur']] : []));
    }

    private function invoiceRows(User $actor, array $f, string $type = 'invoices'): Collection
    {
        $q = $this->transactionQuery(Invoice::class, $actor, $f)
            ->when(empty($f['status']), fn ($q) => $q->whereNotIn('status', ['cancelled', 'draft']))
            ->with(['customer.rt', 'customer.category', 'period', 'allocations.payment']);
        if (in_array($type, ['paid', 'unpaid', 'partial'])) {
            $q->where('status', ['paid' => 'paid', 'unpaid' => 'issued', 'partial' => 'partial'][$type]);
        }
        if ($type === 'arrears') {
            $q->whereColumn('total', '>', 'paid_amount')->whereDate('due_date', '<', today()->toDateString());
        }

        return $q->orderBy('period_id')->orderBy('number')->get()->map(function ($invoice) use ($f) {
            $allocations = $invoice->allocations->filter(fn ($a) => $this->paymentMatches($a->payment, $f));
            $days = max(0, (int) $invoice->due_date->startOfDay()->diffInDays(today(), false));

            return $this->snapshot($invoice) + [
                'customer_id' => $invoice->customer_id, 'invoice' => $invoice->number, 'period' => $invoice->period->name,
                'usage' => (string) $invoice->usage, 'total' => (int) $invoice->total,
                'paid' => (int) $allocations->sum('amount'), 'balance' => in_array($invoice->status, ['draft', 'cancelled']) ? 0 : (int) $invoice->total - (int) $invoice->paid_amount,
                'payment_dates' => $allocations->map(fn ($a) => $a->payment->paid_on->format('d/m/Y'))->unique()->implode(', '),
                'status' => $this->statusLabel($invoice->status), 'due_date' => $invoice->due_date->format('d/m/Y'),
                'age_days' => $days, 'age_bucket' => $days <= 30 ? '1–30 hari' : ($days <= 60 ? '31–60 hari' : ($days <= 90 ? '61–90 hari' : '>90 hari')),
            ];
        });
    }

    private function paymentMatches($p, array $f): bool
    {
        if (! $p || $p->status !== 'posted') {
            return false;
        }
        $date = $p->paid_on->format('Y-m-d');

        return (empty($f['date_from']) || $date >= $f['date_from'])
            && (empty($f['date_to']) || $date <= $f['date_to'])
            && (empty($f['account_id']) || (int) $p->account_id === (int) $f['account_id'])
            && (empty($f['payment_method_id']) || (int) $p->payment_method_id === (int) $f['payment_method_id']);
    }

    private function invoices(User $actor, array $f, string $type): array
    {
        return [$this->invoiceColumns($type === 'arrears'), $this->invoiceRows($actor, $f, $type)];
    }

    private function customers(User $actor, array $f): array
    {
        $showFinance = $actor->hasPermission('invoices.view');
        $invoices = $showFinance ? $this->invoiceRows($actor, $f)->groupBy('customer_id') : collect();
        $rows = $this->customerQuery($actor, $f)->with(['rt', 'category'])->orderBy('rt_id')->orderBy('number')->get()->map(function ($c) use ($invoices) {
            $bills = $invoices->get($c->id, collect());
            $usage = $bills->reduce(fn ($sum, $i) => bcadd($sum, $i['usage'], 3), '0.000');

            return [
                'number' => $c->number, 'name' => $c->name, 'rt' => $c->rt->name, 'category' => $c->category->name,
                'address' => $c->address, 'phone' => $c->phone, 'usage' => $usage,
                'total' => $bills->sum('total'), 'paid' => $bills->sum('paid'), 'balance' => $bills->sum('balance'),
                'payment_dates' => $bills->pluck('payment_dates')->filter()->implode('; '),
                'status' => $bills->isEmpty() ? 'Belum ada tagihan' : ($bills->sum('balance') === 0 ? 'Lunas' : ($bills->sum('paid') > 0 ? 'Sebagian' : 'Belum bayar')),
                'customer_status' => $this->statusLabel($c->status),
            ];
        });

        $columns = $this->columns([
            'number' => ['ID pelanggan'], 'name' => ['Nama'], 'rt' => ['RT'], 'category' => ['Kategori'],
            'address' => ['Alamat'], 'phone' => ['Telepon'], 'usage' => ['Pemakaian m³', 'decimal'],
            'total' => ['Tagihan Rp', 'money'], 'paid' => ['Pembayaran terpilih Rp', 'money'], 'balance' => ['Sisa kini Rp', 'money'],
            'payment_dates' => ['Tanggal pembayaran'], 'status' => ['Status pembayaran'], 'customer_status' => ['Status pelanggan'],
        ]);
        if (! $showFinance) {
            $columns = array_values(array_filter($columns, fn ($column) => ! in_array($column['key'], ['usage', 'total', 'paid', 'balance', 'payment_dates', 'status'])));
        }

        return [$columns, $rows];
    }

    private function readings(User $actor, array $f): array
    {
        $rows = $this->transactionQuery(Reading::class, $actor, $f)->with(['customer.rt', 'customer.category', 'period', 'meter', 'user'])->orderBy('period_id')->get()->map(fn ($r) => $this->snapshot($r) + [
            'period' => $r->period->name, 'serial' => $r->meter->serial, 'previous' => $r->previous_reading,
            'current' => $r->current_reading, 'usage' => $r->usage, 'operator' => $r->user->name,
            'recorded_at' => $r->created_at->format('d/m/Y H:i'), 'status' => $this->statusLabel($r->status), 'warning' => $r->warning,
        ]);

        return [$this->columns(['number' => ['ID pelanggan'], 'name' => ['Nama'], 'rt' => ['RT'], 'category' => ['Kategori'], 'period' => ['Periode'], 'serial' => ['Meter'], 'previous' => ['Meter lalu', 'reading'], 'current' => ['Meter kini', 'reading'], 'usage' => ['Pemakaian m³', 'decimal'], 'operator' => ['Operator'], 'recorded_at' => ['Dicatat'], 'status' => ['Status'], 'warning' => ['Peringatan']]), $rows];
    }

    private function unrecorded(User $actor, array $f): array
    {
        // A meaningful missing-reading report requires one billing period.
        $period = ! empty($f['period_id']) ? Period::find($f['period_id']) : Period::orderByDesc('starts_on')->first();
        $q = $this->customerQuery($actor, $f)->where('status', 'active')->with(['rt', 'category', 'activeMeter']);
        if ($period) {
            $q->whereDate('joined_on', '<=', $period->ends_on)->whereDoesntHave('readings', fn ($q) => $q->where('period_id', $period->id));
        }
        $rows = $period ? $q->get()->map(fn ($c) => ['number' => $c->number, 'name' => $c->name, 'rt' => $c->rt->name, 'category' => $c->category->name, 'address' => $c->address, 'period' => $period->name, 'serial' => $c->activeMeter?->serial, 'status' => 'Belum dicatat']) : collect();

        return [$this->columns(['number' => ['ID pelanggan'], 'name' => ['Nama'], 'rt' => ['RT'], 'category' => ['Kategori'], 'address' => ['Alamat'], 'period' => ['Periode'], 'serial' => ['Meter'], 'status' => ['Status']]), $rows];
    }

    private function paymentQuery(User $actor, array $f): Builder
    {
        $q = $this->paymentFilters(Payment::query(), $f)->whereIn('customer_id', $this->customerQuery($actor, array_diff_key($f, array_flip(['rt_ids', 'category_id'])), true)->select('id'));
        if (! empty($f['period_id']) || ! empty($f['operator_id']) || ! empty($f['rt_ids']) || ! empty($f['category_id']) || ! empty($f['status'])) {
            $q->whereHas('allocations.invoice', fn ($i) => $i
                ->when(! empty($f['period_id']), fn ($i) => $i->where('period_id', $f['period_id']))
                ->when(! empty($f['rt_ids']), fn ($i) => $i->whereIn('customer_snapshot->rt_id', $f['rt_ids']))
                ->when(! empty($f['category_id']), fn ($i) => $i->where('customer_snapshot->category_id', (int) $f['category_id']))
                ->when(! empty($f['status']), fn ($i) => $i->where('status', $f['status']))
                ->when(! empty($f['operator_id']), fn ($i) => $i->whereHas('reading', fn ($r) => $r->where('user_id', $f['operator_id']))));
        }

        return $q;
    }

    private function receipts(User $actor, array $f): array
    {
        // Cash dates remain immutable after reversal: the original receipt is kept
        // in its month and the refund is negative on the reversal entry date.
        $rows = $this->cashQuery($actor, $f)->whereNotNull('payment_id')
            ->with(['payment.customer.rt', 'payment.allocations.invoice.period', 'payment.allocations.invoice.reading', 'payment.method', 'account', 'user'])
            ->orderBy('entry_date')->orderBy('id')->get()->flatMap(function ($entry) use ($f) {
                $p = $entry->payment;
                $allocations = $p->allocations->filter(function ($a) use ($f) {
                    $invoice = $a->invoice;
                    $snapshot = $invoice->customer_snapshot;

                    return (empty($f['period_id']) || (int) $invoice->period_id === (int) $f['period_id'])
                        && (empty($f['rt_ids']) || in_array($snapshot['rt_id'] ?? null, $f['rt_ids']))
                        && (empty($f['category_id']) || (int) ($snapshot['category_id'] ?? 0) === (int) $f['category_id'])
                        && (empty($f['status']) || $invoice->status === $f['status'])
                        && (empty($f['operator_id']) || (int) $invoice->reading->user_id === (int) $f['operator_id']);
                });

                return $allocations->groupBy(fn ($a) => $a->invoice->customer_snapshot['rt_id'] ?? $p->customer->rt_id)->map(function ($group) use ($p, $entry) {
                    $snapshot = $group->first()->invoice->customer_snapshot;

                    return ['number' => $p->number, 'date' => $entry->entry_date->format('Y-m-d'), 'customer_number' => $snapshot['number'] ?? $p->customer->number, 'name' => $snapshot['name'] ?? $p->customer->name, 'rt' => $snapshot['rt_name'] ?? $p->customer->rt->name, 'periods' => $group->map(fn ($a) => $a->invoice->period->name)->unique()->implode(', '), 'amount' => (int) $group->sum('amount') * ($entry->direction === 'in' ? 1 : -1), 'kind' => $entry->reversal_of ? 'Pembatalan / pengembalian' : 'Penerimaan', 'method' => $p->method->name, 'account' => $entry->account->name, 'user' => $entry->user->name, 'reference' => $entry->reference];
                })->values();
            });

        return [$this->columns(['number' => ['Kuitansi'], 'date' => ['Tanggal kas'], 'customer_number' => ['ID pelanggan'], 'name' => ['Nama'], 'rt' => ['RT'], 'periods' => ['Periode dialokasikan'], 'amount' => ['Penerimaan neto Rp', 'money'], 'kind' => ['Jenis'], 'method' => ['Metode'], 'account' => ['Akun'], 'user' => ['Pencatat'], 'reference' => ['Referensi']]), $rows];
    }

    private function cashQuery(User $actor, array $f, bool $dates = true): Builder
    {
        $q = CashEntry::query()->where('status', 'posted')->when(! empty($f['account_id']), fn ($q) => $q->where('account_id', $f['account_id']));
        $scopeRequired = $actor->role?->slug === 'operator' || ! empty($f['rt_ids']) || ! empty($f['category_id']) || ! empty($f['period_id']) || ! empty($f['payment_method_id']) || ! empty($f['customer_status']) || ! empty($f['operator_id']) || ! empty($f['status']) || ! empty($f['q']);
        if ($scopeRequired) {
            // Shared costs have no RT allocation; never invent one in filtered ledgers.
            $q->whereHas('payment', function ($p) use ($actor, $f) {
                $p->whereIn('customer_id', $this->customerQuery($actor, array_diff_key($f, array_flip(['rt_ids', 'category_id'])), true)->select('id'));
                $p->when(! empty($f['payment_method_id']), fn ($p) => $p->where('payment_method_id', $f['payment_method_id']));
                $p->whereHas('allocations.invoice', fn ($i) => $i
                    ->when(! empty($f['period_id']), fn ($i) => $i->where('period_id', $f['period_id']))
                    ->when(! empty($f['rt_ids']), fn ($i) => $i->whereIn('customer_snapshot->rt_id', $f['rt_ids']))
                    ->when(! empty($f['category_id']), fn ($i) => $i->where('customer_snapshot->category_id', (int) $f['category_id']))
                    ->when(! empty($f['status']), fn ($i) => $i->where('status', $f['status']))
                    ->when(! empty($f['operator_id']), fn ($i) => $i->whereHas('reading', fn ($r) => $r->where('user_id', $f['operator_id']))));
            });
        }
        if ($dates) {
            $q->when(! empty($f['date_from']), fn ($q) => $q->whereDate('entry_date', '>=', $f['date_from']));
            $q->when(! empty($f['date_to']), fn ($q) => $q->whereDate('entry_date', '<=', $f['date_to']));
        }

        return $q;
    }

    private function cashbook(User $actor, array $f, string $type): array
    {
        $q = $this->cashQuery($actor, $f)->with(['account', 'category', 'payment.customer.rt', 'payment.allocations.invoice', 'user']);
        if ($type === 'expenses') {
            $q->whereNull('payment_id')->whereNull('transfer_key')->whereHas('category', fn ($c) => $c->where('type', 'expense'));
        }
        $balances = [];
        if ($type === 'cashbook') {
            foreach (Account::query()->when(! empty($f['account_id']), fn ($q) => $q->whereKey($f['account_id']))->get() as $account) {
                // Opening balances belong to the full account, not a filtered RT slice.
                $scoped = $actor->role?->slug === 'operator' || ! empty($f['rt_ids']) || ! empty($f['category_id']) || ! empty($f['period_id']) || ! empty($f['payment_method_id']) || ! empty($f['customer_status']) || ! empty($f['operator_id']) || ! empty($f['status']) || ! empty($f['q']);
                $balance = $scoped ? 0 : (int) $account->opening_balance;
                if (! empty($f['date_from'])) {
                    foreach ($this->cashQuery($actor, $f, false)->with('payment.allocations.invoice')->where('account_id', $account->id)->whereDate('entry_date', '<', $f['date_from'])->get() as $entry) {
                        $amount = array_sum(array_column($this->cashParts($entry, $f), 'amount'));
                        $balance += $entry->direction === 'in' ? $amount : -$amount;
                    }
                }
                $balances[$account->id] = $balance;
            }
        }
        $openingRows = [];
        if ($type === 'cashbook') {
            foreach ($balances as $accountId => $balance) {
                $openingRows[] = ['number' => 'SALDO AWAL', 'date' => $f['date_from'] ?? '', 'account' => Account::find($accountId)->name, 'category' => '', 'description' => 'Saldo awal rentang laporan', 'rt' => '', 'incoming' => 0, 'outgoing' => 0, 'balance' => $balance, 'user' => '', 'reversal' => ''];
            }
        }
        $rows = $q->orderBy('entry_date')->orderBy('id')->get()->flatMap(function ($e) use (&$balances, $f) {
            $rows = [];
            foreach ($this->cashParts($e, $f) as $part) {
                $incoming = $e->direction === 'in' ? $part['amount'] : 0;
                $outgoing = $e->direction === 'out' ? $part['amount'] : 0;
                $balances[$e->account_id] = ($balances[$e->account_id] ?? 0) + $incoming - $outgoing;
                $rows[] = ['number' => $e->reference ?: '#'.$e->id, 'date' => $e->entry_date->format('Y-m-d'), 'account' => $e->account->name, 'category' => $e->category?->name ?? ($e->transfer_key ? 'Transfer antar akun' : 'Pembayaran air'), 'description' => $e->description, 'rt' => $part['rt'], 'incoming' => $incoming, 'outgoing' => $outgoing, 'expense' => $outgoing - $incoming, 'balance' => $balances[$e->account_id], 'user' => $e->user->name, 'reversal' => $e->reversal_of ? 'Pembalik #'.$e->reversal_of : ''];
            }

            return $rows;
        });
        $rows = collect($openingRows)->concat($rows);

        return [$this->columns(['number' => ['No. bukti'], 'date' => ['Tanggal transaksi'], 'account' => ['Akun'], 'category' => ['Kategori'], 'description' => ['Uraian'], 'rt' => ['Wilayah'], ...($type === 'expenses' ? ['expense' => ['Pengeluaran neto Rp', 'money']] : ['incoming' => ['Masuk Rp', 'money'], 'outgoing' => ['Keluar Rp', 'money'], 'balance' => ['Saldo berjalan Rp', 'balance']]), 'user' => ['Pencatat'], 'reversal' => ['Pembalik']]), $rows];
    }

    private function cashParts(CashEntry $entry, array $f): array
    {
        if (! $entry->payment) {
            return [['amount' => (int) $entry->amount, 'rt' => 'Kas umum']];
        }

        return $entry->payment->allocations->filter(function ($a) use ($f) {
            $invoice = $a->invoice;
            $snapshot = $invoice->customer_snapshot;

            return (empty($f['period_id']) || (int) $invoice->period_id === (int) $f['period_id'])
                && (empty($f['rt_ids']) || in_array($snapshot['rt_id'] ?? null, $f['rt_ids']))
                && (empty($f['category_id']) || (int) ($snapshot['category_id'] ?? 0) === (int) $f['category_id'])
                && (empty($f['status']) || $invoice->status === $f['status'])
                && (empty($f['operator_id']) || (int) $invoice->reading->user_id === (int) $f['operator_id']);
        })->groupBy(fn ($a) => $a->invoice->customer_snapshot['rt_id'] ?? 'unknown')->map(fn ($group) => [
            'amount' => (int) $group->sum('amount'), 'rt' => $group->first()->invoice->customer_snapshot['rt_name'] ?? 'Kas umum',
        ])->values()->all();
    }

    private function summary(User $actor, array $f): array
    {
        $buckets = [];
        $yearly = ($f['group_by'] ?? 'month') === 'year';
        $keyFor = fn ($date) => substr($date, 0, $yearly ? 4 : 7);
        $ensure = function ($key) use (&$buckets) {
            $buckets[$key] ??= ['period' => $key, 'usage' => '0.000', 'invoiced' => 0, 'receipts' => 0, 'other_income' => 0, 'expenses' => 0, 'net' => 0];
        };
        $bills = $this->transactionQuery(Invoice::class, $actor, array_diff_key($f, array_flip(['date_from', 'date_to', 'account_id', 'payment_method_id'])))->whereNotIn('status', ['draft', 'cancelled'])->with('period')->get();
        foreach ($bills as $bill) {
            $key = $keyFor($bill->period->starts_on->format('Y-m-d'));
            $ensure($key);
            $buckets[$key]['usage'] = bcadd($buckets[$key]['usage'], $bill->usage, 3);
            $buckets[$key]['invoiced'] += $bill->total;
        }
        [, $payments] = $this->receipts($actor, $f);
        foreach ($payments as $p) {
            $key = $keyFor($p['date']);
            $ensure($key);
            $buckets[$key]['receipts'] += $p['amount'];
        }
        [, $expenses] = $this->cashbook($actor, $f, 'expenses');
        foreach ($expenses as $e) {
            $key = $keyFor($e['date']);
            $ensure($key);
            $buckets[$key]['expenses'] += $e['expense'];
        }
        foreach ($this->cashQuery($actor, $f)->whereNull('payment_id')->whereNull('transfer_key')->whereHas('category', fn ($c) => $c->where('type', 'income'))->get() as $entry) {
            $key = $keyFor($entry->entry_date->format('Y-m-d'));
            $ensure($key);
            $buckets[$key]['other_income'] += $entry->direction === 'in' ? $entry->amount : -$entry->amount;
        }
        foreach ($buckets as &$bucket) {
            $bucket['net'] = $bucket['receipts'] + $bucket['other_income'] - $bucket['expenses'];
        }
        ksort($buckets);

        return [$this->columns(['period' => [$yearly ? 'Tahun' : 'Bulan'], 'usage' => ['Pemakaian m³', 'decimal'], 'invoiced' => ['Tagihan Rp', 'money'], 'receipts' => ['Penerimaan air Rp', 'money'], 'other_income' => ['Pemasukan lain neto Rp', 'money'], 'expenses' => ['Pengeluaran neto Rp', 'money'], 'net' => ['Arus kas operasional neto Rp', 'money']]), array_values($buckets)];
    }

    private function operators(User $actor, array $f): array
    {
        $rows = $this->transactionQuery(Reading::class, $actor, $f)->with(['user', 'period', 'customer.rt'])->get()->groupBy(fn ($r) => $r->user_id.'-'.$r->period_id.'-'.$r->customer->rt_id)->map(function ($group) use ($actor, $f) {
            $first = $group->first();
            $assigned = $first->user->rts()->where('rts.id', $first->customer->rt_id)->exists();
            $eligible = $this->customerQuery($actor, $f)->where('rt_id', $first->customer->rt_id)->where('status', 'active')->whereDate('joined_on', '<=', $first->period->ends_on)->count();

            return ['operator' => $first->user->name, 'rt' => $first->customer->rt->name, 'period' => $first->period->name, 'assigned' => $assigned ? 'Ditugaskan' : 'Akses khusus', 'eligible' => $eligible, 'recorded' => $group->count(), 'verified' => $group->whereIn('status', ['verified', 'locked'])->count(), 'draft' => $group->where('status', 'draft')->count(), 'rejected' => $group->where('status', 'rejected')->count(), 'usage' => $group->reduce(fn ($sum, $r) => bcadd($sum, $r->usage, 3), '0.000')];
        });

        $periods = Period::query()->when(! empty($f['period_id']), fn ($q) => $q->whereKey($f['period_id']))->get();
        $operators = User::with('rts')->where('active', true)->whereHas('role', fn ($q) => $q->where('slug', 'operator'))
            ->when(! empty($f['operator_id']), fn ($q) => $q->whereKey($f['operator_id']))->get();
        $allowedRts = $actor->role?->slug === 'operator' ? $actor->rts()->pluck('rts.id')->all() : null;
        foreach ($operators as $operator) {
            foreach ($operator->rts as $rt) {
                if (($allowedRts !== null && ! in_array($rt->id, $allowedRts)) || (! empty($f['rt_ids']) && ! in_array($rt->id, $f['rt_ids']))) {
                    continue;
                }
                foreach ($periods as $period) {
                    $key = $operator->id.'-'.$period->id.'-'.$rt->id;
                    if (! $rows->has($key)) {
                        $rows->put($key, ['operator' => $operator->name, 'rt' => $rt->name, 'period' => $period->name, 'assigned' => 'Ditugaskan', 'eligible' => $this->customerQuery($actor, $f)->where('rt_id', $rt->id)->where('status', 'active')->whereDate('joined_on', '<=', $period->ends_on)->count(), 'recorded' => 0, 'verified' => 0, 'draft' => 0, 'rejected' => 0, 'usage' => '0.000']);
                    }
                }
            }
        }

        return [$this->columns(['operator' => ['Operator'], 'rt' => ['RT'], 'period' => ['Periode'], 'assigned' => ['Penugasan'], 'eligible' => ['Pelanggan RT', 'number'], 'recorded' => ['Dicatat', 'number'], 'verified' => ['Sah', 'number'], 'draft' => ['Draft', 'number'], 'rejected' => ['Ditolak', 'number'], 'usage' => ['Pemakaian m³', 'decimal']]), $rows];
    }

    private function audit(User $actor, array $f): array
    {
        $q = AuditLog::query()->with('user')
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($q) => $q->where('action', 'like', '%'.$f['q'].'%')->orWhere('reason', 'like', '%'.$f['q'].'%')->orWhere('subject_type', 'like', '%'.$f['q'].'%')->orWhere('before', 'like', '%'.$f['q'].'%')->orWhere('after', 'like', '%'.$f['q'].'%')))
            ->when(! empty($f['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $f['date_from']))
            ->when(! empty($f['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $f['date_to']))
            ->when(! empty($f['operator_id']), fn ($q) => $q->where('user_id', $f['operator_id']));
        // Audit subjects may be unrelated to a customer. Restrict operator views to
        // their own actions and customer-related subjects in assigned territory.
        if ($actor->role?->slug === 'operator') {
            $q->where('user_id', $actor->id)->where(function ($q) use ($actor) {
                $q->where(function ($q) use ($actor) {
                    $q->where('subject_type', Customer::class)->whereIn('subject_id', $actor->scopeCustomers(Customer::withTrashed())->select('id'));
                })->orWhere(function ($q) use ($actor) {
                    $q->where('subject_type', Reading::class)->whereIn('subject_id', Reading::whereIn('customer_id', $actor->scopeCustomers(Customer::withTrashed())->select('id'))->select('id'));
                });
            });
        }
        if (! empty($f['rt_ids']) || ! empty($f['category_id']) || ! empty($f['period_id'])) {
            $q->where(function ($q) use ($actor, $f) {
                $q->where(fn ($q) => $q->where('subject_type', Customer::class)->whereIn('subject_id', $this->customerQuery($actor, $f, true)->select('id')))
                    ->orWhere(fn ($q) => $q->where('subject_type', Reading::class)->whereIn('subject_id', $this->transactionQuery(Reading::class, $actor, $f)->select('id')))
                    ->orWhere(fn ($q) => $q->where('subject_type', Invoice::class)->whereIn('subject_id', $this->transactionQuery(Invoice::class, $actor, $f)->select('id')));
            });
        }
        $rows = $q->orderByDesc('id')->get()->map(fn ($a) => ['at' => $a->created_at->format('d/m/Y H:i:s'), 'user' => $a->user?->name ?? 'Sistem', 'action' => $a->action, 'subject' => class_basename($a->subject_type ?? '').' #'.$a->subject_id, 'reason' => $a->reason, 'before' => json_encode($a->before, JSON_UNESCAPED_UNICODE), 'after' => json_encode($a->after, JSON_UNESCAPED_UNICODE)]);

        return [$this->columns(['at' => ['Waktu WIB'], 'user' => ['Pelaksana'], 'action' => ['Aktivitas'], 'subject' => ['Objek'], 'reason' => ['Alasan'], 'before' => ['Sebelum'], 'after' => ['Sesudah']]), $rows];
    }

    private function totals(array $columns, Collection $rows): array
    {
        $totals = [];
        foreach ($columns as $c) {
            if ($c['type'] === 'decimal') {
                $totals[$c['key']] = $rows->reduce(fn ($sum, $r) => bcadd($sum, (string) ($r[$c['key']] ?? 0), 3), '0.000');
            } elseif (in_array($c['type'], ['money', 'number'])) {
                $totals[$c['key']] = $rows->sum($c['key']);
            }
        }

        return $totals;
    }

    private function statusLabel(string $status): string
    {
        return ['active' => 'Aktif', 'inactive' => 'Nonaktif', 'disconnected' => 'Diputus sementara', 'draft' => 'Draft', 'submitted' => 'Diajukan', 'verified' => 'Diverifikasi', 'rejected' => 'Ditolak', 'locked' => 'Dikunci', 'issued' => 'Belum bayar', 'partial' => 'Sebagian', 'paid' => 'Lunas', 'cancelled' => 'Dibatalkan'][$status] ?? $status;
    }
}
