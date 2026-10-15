<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashEntry;
use App\Models\FinanceCategory;
use App\Models\Period;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function balance(Account $account, ?string $through = null): int
    {
        $query = CashEntry::where('account_id', $account->id)->where('status', 'posted');
        if ($through !== null) {
            $query->whereDate('entry_date', '<=', $through);
        }

        return (int) $account->opening_balance + (int) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS balance")
            ->value('balance');
    }

    public function createAccount(User $actor, array $data): Account
    {
        $actor->requirePermission('accounts.create');
        $this->requireGlobalScope($actor);
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['cash', 'bank'])],
            'number' => ['nullable', 'string', 'max:100'],
            'opening_balance' => ['required', 'integer', 'min:0', 'max:9000000000000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $periods = Period::orderBy('id')->lockForUpdate()->get();
            if ((int) $data['opening_balance'] !== 0 && $periods->contains(fn ($period) => $period->locked_at !== null)) {
                throw ValidationException::withMessages(['opening_balance' => 'Saldo awal berlaku sejak awal riwayat dan hanya dapat ditetapkan sebelum tutup buku pertama. Buat akun bersaldo awal 0, lalu catat transfer atau penerimaan yang sah pada periode terbuka.']);
            }
            $account = Account::create($data + ['active' => true]);
            AuditService::record('account.created', $account, [], $account->toArray(), 'Pencatatan saldo awal kas/bank', $actor);

            return $account;
        });
    }

    public function updateAccount(User $actor, Account $account, array $data): Account
    {
        $actor->requirePermission('accounts.update');
        $this->requireGlobalScope($actor);
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['cash', 'bank'])],
            'number' => ['nullable', 'string', 'max:100'], 'active' => ['required', 'boolean'],
            'opening_balance' => ['required', 'integer', 'min:0', 'max:9000000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $account, $data) {
            $periods = Period::orderBy('id')->lockForUpdate()->get();
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['opening_balance'] !== (int) $account->opening_balance && $periods->contains(fn ($period) => $period->locked_at !== null)) {
                throw ValidationException::withMessages(['opening_balance' => 'Saldo awal tidak dapat diubah setelah ada periode tertutup karena akan mengubah saldo historis. Gunakan transaksi beralasan di periode terbuka.']);
            }
            if ((int) $data['opening_balance'] !== (int) $account->opening_balance &&
                (CashEntry::where('account_id', $account->id)->exists() || Reconciliation::where('account_id', $account->id)->exists())) {
                throw ValidationException::withMessages(['opening_balance' => 'Saldo awal yang sudah memiliki transaksi atau rekonsiliasi tidak dapat diubah. Gunakan transaksi penyesuaian beralasan.']);
            }
            if (! (bool) $data['active'] && CashEntry::where('account_id', $account->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['active' => 'Selesaikan transaksi menunggu persetujuan sebelum menonaktifkan akun.']);
            }
            $before = $account->toArray();
            $account->update(collect($data)->except('reason')->all());
            AuditService::record('account.updated', $account, $before, $account->toArray(), $data['reason'], $actor);

            return $account;
        });
    }

    /** A transfer posts two linked entries and has no income/expense category. */
    public function record(User $actor, array $data): CashEntry
    {
        $actor->requirePermission('finance.create');
        $this->requireGlobalScope($actor);
        $data = Validator::make($data, [
            'kind' => ['required', Rule::in(['income', 'expense', 'transfer'])],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'target_account_id' => ['required_if:kind,transfer', 'nullable', 'integer', 'exists:accounts,id', 'different:account_id'],
            'category_id' => ['required_unless:kind,transfer', 'nullable', 'integer', 'exists:finance_categories,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:9000000000000'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:150'], 'party' => ['nullable', 'string', 'max:150'],
            'attachment_path' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $this->lockOpenDate($data['entry_date']);
            $ids = [$data['account_id']];
            if ($data['kind'] === 'transfer') {
                $ids[] = $data['target_account_id'];
            }
            $accounts = Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($ids as $id) {
                if (! $accounts->get($id)?->active) {
                    throw ValidationException::withMessages(['account_id' => 'Seluruh akun transaksi harus aktif.']);
                }
            }
            if ($data['kind'] !== 'transfer' && ! FinanceCategory::whereKey($data['category_id'])->where('type', $data['kind'])->where('active', true)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['category_id' => 'Kategori harus aktif dan sesuai jenis transaksi.']);
            }
            $pending = $data['kind'] === 'expense' && (bool) SettingService::get('expense_approval_required', false);
            $base = [
                'user_id' => $actor->id, 'entry_date' => $data['entry_date'], 'amount' => (int) $data['amount'],
                'description' => $data['description'], 'reference' => $data['reference'] ?? null,
                'party' => $data['party'] ?? null, 'attachment_path' => $data['attachment_path'] ?? null,
                'status' => $pending ? 'pending' : 'posted', 'approved_by' => $pending ? null : $actor->id,
            ];
            $entry = CashEntry::create($base + [
                'account_id' => $data['account_id'], 'direction' => $data['kind'] === 'income' ? 'in' : 'out',
                'category_id' => $data['kind'] === 'transfer' ? null : $data['category_id'],
                'transfer_key' => $data['kind'] === 'transfer' ? (string) Str::uuid() : null,
            ]);
            if ($data['kind'] === 'transfer') {
                CashEntry::create($base + ['account_id' => $data['target_account_id'], 'direction' => 'in', 'transfer_key' => $entry->transfer_key]);
            }
            AuditService::record('finance.'.($pending ? 'submitted' : 'posted'), $entry, [], $entry->toArray(), null, $actor);

            return $entry;
        }, 3);
    }

    public function approve(User $actor, CashEntry $entry): CashEntry
    {
        $actor->requirePermission('finance.approve');
        $this->requireGlobalScope($actor);

        return DB::transaction(function () use ($actor, $entry) {
            $this->lockOpenDate($entry->entry_date->format('Y-m-d'));
            Account::whereKey($entry->account_id)->lockForUpdate()->firstOrFail();
            $entry = CashEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status !== 'pending') {
                throw ValidationException::withMessages(['entry' => 'Hanya transaksi menunggu persetujuan yang dapat disetujui.']);
            }
            $before = $entry->toArray();
            $entry->update(['status' => 'posted', 'approved_by' => $actor->id]);
            AuditService::record('finance.approved', $entry, $before, $entry->toArray(), null, $actor);

            return $entry;
        }, 3);
    }

    public function reverse(User $actor, CashEntry $entry, string $reason): CashEntry
    {
        $actor->requirePermission('finance.approve');
        $this->requireGlobalScope($actor);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:1000']])->validate();

        return DB::transaction(function () use ($actor, $entry, $reason) {
            $this->lockOpenDate(today()->format('Y-m-d'));
            $entries = CashEntry::where(function ($query) use ($entry) {
                $query->whereKey($entry->id);
                if ($entry->transfer_key) {
                    $query->orWhere('transfer_key', $entry->transfer_key);
                }
            })->whereNull('reversal_of')->orderBy('id')->get();
            Account::whereIn('id', $entries->pluck('account_id'))->orderBy('id')->lockForUpdate()->get();
            $entries = CashEntry::whereIn('id', $entries->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $result = null;
            foreach ($entries as $original) {
                if ($original->payment_id || $original->reversal_of) {
                    throw ValidationException::withMessages(['entry' => 'Pembayaran air harus dibatalkan melalui halaman kuitansi. Transaksi pembalik tidak dapat dibalik lagi.']);
                }
                if ($original->status === 'pending') {
                    $this->lockOpenDate($original->entry_date->format('Y-m-d'));
                    $before = $original->toArray();
                    $original->update(['status' => 'reversed']);
                    AuditService::record('finance.rejected', $original, $before, $original->toArray(), $reason, $actor);
                    $result ??= $original;

                    continue;
                }
                if ($original->status !== 'posted') {
                    throw ValidationException::withMessages(['entry' => 'Transaksi sudah dibatalkan.']);
                }
                if (CashEntry::where('reversal_of', $original->id)->exists()) {
                    throw ValidationException::withMessages(['entry' => 'Transaksi sudah memiliki transaksi pembalik.']);
                }
                $reversal = CashEntry::create([
                    'account_id' => $original->account_id, 'category_id' => $original->category_id, 'user_id' => $actor->id,
                    'entry_date' => today()->format('Y-m-d'), 'direction' => $original->direction === 'in' ? 'out' : 'in',
                    'amount' => $original->amount, 'description' => 'Pembalik #'.$original->id.': '.$reason,
                    'reference' => $original->reference, 'party' => $original->party,
                    'transfer_key' => $original->transfer_key, 'status' => 'posted', 'approved_by' => $actor->id,
                    'reversal_of' => $original->id,
                ]);
                AuditService::record('finance.reversed', $original, $original->toArray(), $reversal->toArray(), $reason, $actor);
                $result ??= $reversal;
            }

            return $result ?? throw ValidationException::withMessages(['entry' => 'Transaksi tidak dapat dibalik.']);
        }, 3);
    }

    public function reconcile(User $actor, array $data): Reconciliation
    {
        $actor->requirePermission('accounts.reconcile');
        $this->requireGlobalScope($actor);
        $data = Validator::make($data, [
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'reconciled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'statement_balance' => ['required', 'integer', 'min:-9000000000000', 'max:9000000000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $this->lockOpenDate($data['reconciled_on']);
            $account = Account::whereKey($data['account_id'])->lockForUpdate()->firstOrFail();
            $book = $this->balance($account, $data['reconciled_on']);
            $difference = (int) $data['statement_balance'] - $book;
            if ($difference !== 0 && mb_strlen(trim($data['notes'] ?? '')) < 5) {
                throw ValidationException::withMessages(['notes' => 'Jelaskan selisih rekonsiliasi (minimal 5 karakter).']);
            }
            $record = Reconciliation::create([
                'account_id' => $account->id, 'user_id' => $actor->id, 'reconciled_on' => $data['reconciled_on'],
                'statement_balance' => (int) $data['statement_balance'], 'book_balance' => $book,
                'difference' => $difference, 'notes' => $data['notes'] ?? null,
            ]);
            AuditService::record('account.reconciled', $record, [], $record->toArray(), $data['notes'] ?? null, $actor);

            return $record;
        }, 3);
    }

    private function lockOpenDate(string $date): void
    {
        $periods = Period::orderBy('id')->lockForUpdate()->get();
        foreach ($periods as $period) {
            if ($period->starts_on->format('Y-m-d') <= $date && $period->ends_on->format('Y-m-d') >= $date && $period->locked_at) {
                throw ValidationException::withMessages(['entry_date' => 'Periode '.$period->name.' telah ditutup. Buka kembali dengan alasan sebelum mengubah transaksi.']);
            }
        }
    }

    private function requireGlobalScope(User $actor): void
    {
        // General cash entries have no RT owner, so cannot be assigned to an operator's territory.
        abort_if($actor->role?->slug === 'operator', 403, 'Transaksi kas umum memerlukan akses keuangan seluruh RW.');
    }
}
