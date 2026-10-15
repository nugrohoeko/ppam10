<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Money is always whole rupiah; allocations never recreate prior receivables. */
class PaymentService
{
    public function pay(User $actor, array $data): Payment
    {
        $actor->requirePermission('payments.create');
        $data['allocation_mode'] ??= SettingService::get('payment_allocation_mode', 'oldest');
        $data = Validator::make($data, [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:9000000000000'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'allocation_mode' => ['required', Rule::in(['oldest', 'manual'])],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'integer', 'min:0', 'max:9000000000000'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'reference' => ['nullable', 'string', 'max:150'],
            'proof_path' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $customer = Customer::withTrashed()->findOrFail($data['customer_id']);
        abort_unless($actor->canAccessCustomer($customer), 403);
        $requested = [];
        foreach ($data['allocations'] ?? [] as $invoiceId => $amount) {
            if (! ctype_digit((string) $invoiceId) || (int) $invoiceId < 1) {
                throw ValidationException::withMessages(['allocations' => 'Identitas tagihan tidak sah.']);
            }
            if ((int) $amount > 0) {
                $requested[(int) $invoiceId] = (int) $amount;
            }
        }
        ksort($requested);
        $fingerprint = hash('sha256', json_encode([
            'customer_id' => (int) $data['customer_id'], 'account_id' => (int) $data['account_id'],
            'payment_method_id' => (int) $data['payment_method_id'], 'amount' => (int) $data['amount'],
            'paid_on' => $data['paid_on'], 'allocation_mode' => $data['allocation_mode'],
            'allocations' => $data['allocation_mode'] === 'manual' ? $requested : [],
            'reference' => $data['reference'] ?? null,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $data, $requested, $fingerprint) {
                // All money writers acquire period locks first, then customers/accounts/invoices.
                // This also serializes period closure with a payment being posted.
                $periods = Period::orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $customer = Customer::withTrashed()->whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();
                abort_unless($actor->canAccessCustomer($customer), 403);
                $existing = Payment::where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
                if ($existing) {
                    return $this->existing($actor, $existing, $fingerprint);
                }
                $this->assertDateOpen($periods, $data['paid_on']);
                $account = Account::whereKey($data['account_id'])->where('active', true)->lockForUpdate()->first();
                if (! $account || ! PaymentMethod::whereKey($data['payment_method_id'])->where('active', true)->lockForUpdate()->first()) {
                    throw ValidationException::withMessages(['account_id' => 'Akun kas/bank atau metode pembayaran tidak aktif.']);
                }
                $invoices = Invoice::where('customer_id', $customer->id)
                    ->whereIn('status', ['issued', 'partial'])
                    ->orderBy(Period::select('starts_on')->whereColumn('periods.id', 'invoices.period_id'))
                    ->orderBy('id')->lockForUpdate()->get();
                $allocations = [];
                $remaining = (int) $data['amount'];
                if ($data['allocation_mode'] === 'manual') {
                    if ($requested === [] || array_sum($requested) !== $remaining) {
                        throw ValidationException::withMessages(['allocations' => 'Jumlah alokasi manual harus sama dengan nominal pembayaran.']);
                    }
                    foreach ($requested as $id => $amount) {
                        $invoice = $invoices->firstWhere('id', $id);
                        if (! $invoice || $amount > ((int) $invoice->total - (int) $invoice->paid_amount)) {
                            throw ValidationException::withMessages(['allocations' => 'Alokasi harus menuju tagihan pelanggan ini dan tidak melebihi sisanya.']);
                        }
                        $allocations[$id] = $amount;
                    }
                } else {
                    foreach ($invoices as $invoice) {
                        if ($remaining === 0) {
                            break;
                        }
                        $outstanding = (int) $invoice->total - (int) $invoice->paid_amount;
                        if ($outstanding <= 0) {
                            continue;
                        }
                        $amount = min($remaining, $outstanding);
                        $allocations[$invoice->id] = $amount;
                        $remaining -= $amount;
                    }
                    if ($remaining > 0) {
                        throw ValidationException::withMessages(['amount' => 'Pembayaran melebihi sisa tagihan. Saldo titipan belum diaktifkan.']);
                    }
                }
                $payment = Payment::create([
                    'number' => NumberService::next('receipt'), 'customer_id' => $customer->id,
                    'account_id' => $account->id, 'payment_method_id' => $data['payment_method_id'],
                    'amount' => (int) $data['amount'], 'paid_on' => $data['paid_on'], 'user_id' => $actor->id,
                    'reference' => $data['reference'] ?? null, 'proof_path' => $data['proof_path'] ?? null,
                    'idempotency_key' => $data['idempotency_key'], 'request_hash' => $fingerprint, 'status' => 'posted',
                ]);
                foreach ($allocations as $id => $amount) {
                    $invoice = $invoices->firstWhere('id', $id);
                    $payment->allocations()->create(['invoice_id' => $id, 'amount' => $amount]);
                    $paid = (int) $invoice->paid_amount + $amount;
                    $invoice->update(['paid_amount' => $paid, 'status' => $paid === (int) $invoice->total ? 'paid' : 'partial']);
                }
                CashEntry::create([
                    'account_id' => $account->id, 'payment_id' => $payment->id, 'user_id' => $actor->id,
                    'entry_date' => $data['paid_on'], 'direction' => 'in', 'amount' => (int) $data['amount'],
                    'description' => 'Pembayaran rekening air '.$payment->number, 'reference' => $data['reference'] ?? null,
                    'status' => 'posted', 'approved_by' => $actor->id, 'party' => $customer->name,
                ]);
                AuditService::record('payment.posted', $payment, [], $payment->toArray(), null, $actor);

                return $payment->load(['customer.rt', 'account', 'method', 'allocations.invoice.period', 'user']);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Payment::where('idempotency_key', $data['idempotency_key'])->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->existing($actor, $existing, $fingerprint);
        }
    }

    public function reverse(User $actor, Payment $payment, string $reason): Payment
    {
        $actor->requirePermission('payments.cancel');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib 5–1.000 karakter.']);
        }

        return DB::transaction(function () use ($actor, $payment, $reason) {
            $periods = Period::orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->canAccessCustomer($payment->customer), 403);
            if ($payment->status === 'reversed') {
                return $payment;
            }
            $this->assertDateOpen($periods, today()->format('Y-m-d'));
            Account::whereKey($payment->account_id)->lockForUpdate()->firstOrFail();
            $allocations = $payment->allocations()->orderBy('invoice_id')->get();
            $invoices = Invoice::whereIn('id', $allocations->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $before = $payment->toArray();
            foreach ($allocations as $allocation) {
                $invoice = $invoices[$allocation->invoice_id];
                $paid = (int) $invoice->paid_amount - (int) $allocation->amount;
                if ($paid < 0 || $invoice->status === 'cancelled') {
                    throw ValidationException::withMessages(['payment' => 'Saldo alokasi tidak konsisten; periksa audit sebelum membalik pembayaran.']);
                }
                $invoice->update(['paid_amount' => $paid, 'status' => $paid === 0 ? 'issued' : ($paid === (int) $invoice->total ? 'paid' : 'partial')]);
            }
            $originals = CashEntry::where('payment_id', $payment->id)->whereNull('reversal_of')->where('status', 'posted')->lockForUpdate()->get();
            foreach ($originals as $entry) {
                CashEntry::create([
                    'account_id' => $entry->account_id, 'payment_id' => $payment->id, 'user_id' => $actor->id,
                    'entry_date' => today()->format('Y-m-d'), 'direction' => $entry->direction === 'in' ? 'out' : 'in',
                    'amount' => $entry->amount, 'description' => 'Pembalik '.$payment->number.': '.$reason,
                    'reference' => $payment->number, 'status' => 'posted', 'approved_by' => $actor->id,
                    'reversal_of' => $entry->id, 'party' => $payment->customer->name,
                ]);
            }
            $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $actor->id, 'reason' => $reason]);
            AuditService::record('payment.reversed', $payment, $before, $payment->toArray(), $reason, $actor);

            return $payment->refresh();
        }, 3);
    }

    private function existing(User $actor, Payment $payment, string $fingerprint): Payment
    {
        abort_unless($actor->canAccessCustomer($payment->customer), 403);
        if (! hash_equals((string) $payment->request_hash, $fingerprint)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Formulir ini sudah dipakai untuk data berbeda. Muat ulang formulir pembayaran.']);
        }

        return $payment->load(['customer.rt', 'account', 'method', 'allocations.invoice.period', 'user']);
    }

    private function assertDateOpen(Collection $periods, string $date): void
    {
        foreach ($periods as $period) {
            if ($period->starts_on->format('Y-m-d') <= $date && $period->ends_on->format('Y-m-d') >= $date && $period->locked_at) {
                throw ValidationException::withMessages(['paid_on' => 'Periode transaksi '.$period->name.' telah ditutup. Buka kembali dengan alasan sebelum melakukan transaksi.']);
            }
        }
    }
}
