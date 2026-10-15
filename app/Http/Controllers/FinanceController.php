<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashEntry;
use App\Models\FinanceCategory;
use App\Models\PaymentMethod;
use App\Models\Reconciliation;
use App\Services\AuditService;
use App\Services\FinanceService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    public function index(Request $request, FinanceService $service)
    {
        $request->user()->requirePermission('finance.view');
        $request->validate([
            'account_id' => ['nullable', 'integer'], 'direction' => ['nullable', 'in:in,out'],
            'status' => ['nullable', 'in:posted,pending,reversed'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $operator = $request->user()->role?->slug === 'operator';
        $entries = CashEntry::with(['account', 'category', 'user', 'reversalOf', 'payment']);
        if ($operator) {
            $entries->whereHas('payment.customer', fn ($query) => $request->user()->scopeCustomers($query));
        }
        if ($request->filled('account_id')) {
            $entries->where('account_id', $request->integer('account_id'));
        }
        foreach (['direction', 'status'] as $field) {
            if ($request->filled($field)) {
                $entries->where($field, $request->input($field));
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operatorSymbol) {
            if ($request->filled($field)) {
                $entries->whereDate('entry_date', $operatorSymbol, $request->input($field));
            }
        }
        $entries = $entries->orderByDesc('entry_date')->orderByDesc('id')->paginate(25)->withQueryString();
        $reversedIds = CashEntry::whereIn('reversal_of', $entries->pluck('id'))->pluck('reversal_of')->all();
        $accounts = $operator ? collect() : Account::orderBy('active', 'desc')->orderBy('name')->get();
        $balances = $accounts->mapWithKeys(fn ($account) => [$account->id => $service->balance($account)]);

        return view('finance.index', [
            'entries' => $entries, 'reversedIds' => $reversedIds, 'accounts' => $accounts, 'balances' => $balances,
            'categories' => FinanceCategory::orderBy('type')->orderBy('name')->get(),
            'methods' => PaymentMethod::orderBy('name')->get(),
            'reconciliations' => $operator ? collect() : Reconciliation::with(['account', 'user'])->orderByDesc('id')->limit(30)->get(),
            'approvalRequired' => (bool) SettingService::get('expense_approval_required', false),
            'globalFinance' => ! $operator,
        ]);
    }

    public function store(Request $request, FinanceService $service)
    {
        $request->user()->requirePermission('finance.create');
        $request->validate(['attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $path = $request->file('attachment')?->store('finance-proofs', 'local');
        try {
            $data = $request->except(['_token', 'attachment']);
            $data['attachment_path'] = $path;
            $service->record($request->user(), $data);
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'Transaksi dicatat. Pengeluaran mengikuti pengaturan persetujuan.');
    }

    public function storeAccount(Request $request, FinanceService $service)
    {
        $service->createAccount($request->user(), $request->all());

        return back()->with('success', 'Akun kas/bank dan saldo awal disimpan.');
    }

    public function updateAccount(Request $request, Account $account, FinanceService $service)
    {
        $service->updateAccount($request->user(), $account, $request->all());

        return back()->with('success', 'Akun kas/bank diperbarui.');
    }

    public function storeCategory(Request $request)
    {
        $request->user()->requirePermission('finance.create');
        abort_if($request->user()->role?->slug === 'operator', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:finance_categories,name'],
            'type' => ['required', Rule::in(['income', 'expense'])],
        ]);
        $category = FinanceCategory::create($data + ['active' => true]);
        AuditService::record('finance.category.created', $category, [], $category->toArray());

        return back()->with('success', 'Kategori keuangan ditambahkan.');
    }

    public function storeMethod(Request $request)
    {
        $request->user()->requirePermission('settings.update');
        $data = $request->validate(['name' => ['required', 'string', 'max:120', 'unique:payment_methods,name']]);
        $method = PaymentMethod::create($data + ['active' => true]);
        AuditService::record('payment.method.created', $method, [], $method->toArray());

        return back()->with('success', 'Metode pembayaran ditambahkan.');
    }

    public function updateCategory(Request $request, FinanceCategory $category)
    {
        $request->user()->requirePermission('finance.create');
        abort_if($request->user()->role?->slug === 'operator', 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('finance_categories', 'name')->ignore($category->id)],
            'type' => ['required', Rule::in(['income', 'expense'])], 'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        DB::transaction(function () use ($category, $data) {
            $category = FinanceCategory::whereKey($category->id)->lockForUpdate()->firstOrFail();
            if ($data['type'] !== $category->type && CashEntry::where('category_id', $category->id)->exists()) {
                throw ValidationException::withMessages(['type' => 'Jenis kategori yang sudah memiliki transaksi tidak dapat diubah. Tambahkan kategori baru.']);
            }
            $before = $category->toArray();
            $category->update(collect($data)->except('reason')->all());
            AuditService::record('finance.category.updated', $category, $before, $category->toArray(), $data['reason']);
        });

        return back()->with('success', 'Kategori diperbarui; riwayat transaksi tetap tersimpan.');
    }

    public function updateMethod(Request $request, PaymentMethod $method)
    {
        $request->user()->requirePermission('settings.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('payment_methods', 'name')->ignore($method->id)],
            'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        DB::transaction(function () use ($method, $data) {
            $method = PaymentMethod::whereKey($method->id)->lockForUpdate()->firstOrFail();
            $before = $method->toArray();
            $method->update(collect($data)->except('reason')->all());
            AuditService::record('payment.method.updated', $method, $before, $method->toArray(), $data['reason']);
        });

        return back()->with('success', 'Metode pembayaran diperbarui.');
    }

    public function approve(Request $request, CashEntry $cashEntry, FinanceService $service)
    {
        $service->approve($request->user(), $cashEntry);

        return back()->with('success', 'Transaksi disetujui dan dibukukan ke kas/bank.');
    }

    public function reverse(Request $request, CashEntry $cashEntry, FinanceService $service)
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $service->reverse($request->user(), $cashEntry, $request->string('reason')->toString());

        return back()->with('success', 'Pembatalan dicatat. Transaksi yang sudah dibukukan memiliki entri pembalik.');
    }

    public function reconcile(Request $request, FinanceService $service)
    {
        $service->reconcile($request->user(), $request->all());

        return back()->with('success', 'Hasil rekonsiliasi tersimpan, termasuk selisih dan catatan.');
    }

    public function attachment(Request $request, CashEntry $cashEntry)
    {
        $request->user()->requirePermission('finance.view');
        if ($request->user()->role?->slug === 'operator') {
            abort_unless($cashEntry->payment && $request->user()->canAccessCustomer($cashEntry->payment->customer), 403);
        }
        abort_unless($cashEntry->attachment_path && Storage::disk('local')->exists($cashEntry->attachment_path), 404);

        return Storage::disk('local')->response($cashEntry->attachment_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
