<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Rt;
use App\Services\PaymentService;
use App\Services\SettingService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $user->requirePermission('payments.view');
        $filters = $request->validate([
            'customer_id' => ['nullable', 'integer'], 'rt_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:150'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'payment_method_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:posted,reversed'],
        ]);
        $query = Payment::with(['customer.rt', 'method', 'account', 'user'])
            ->whereHas('customer', fn ($query) => $user->scopeCustomers($query));
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if ($request->filled('rt_id')) {
            $query->whereHas('customer', fn ($query) => $query->where('rt_id', $filters['rt_id']));
        }
        if ($request->filled('search')) {
            $search = $filters['search'];
            $query->where(fn ($query) => $query->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('customer', fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('number', 'like', '%'.$search.'%')));
        }
        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            if ($request->filled($field)) {
                $query->whereDate('paid_on', $operator, $filters[$field]);
            }
        }
        foreach (['payment_method_id', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $filters[$field]);
            }
        }
        $payments = $query->orderByDesc('paid_on')->orderByDesc('id')->paginate(20)->withQueryString();
        $customers = $user->scopeCustomers(Customer::query())->with('rt')->orderBy('number')->get();
        $customer = null;
        $invoices = collect();
        if ($request->filled('customer_id')) {
            $selected = $user->scopeCustomers(Customer::withTrashed())->findOrFail($filters['customer_id']);
            $outstanding = Invoice::with('period')->where('customer_id', $selected->id)
                ->whereIn('status', ['issued', 'partial'])->whereColumn('total', '>', 'paid_amount')
                ->orderBy('due_date')->orderBy('id')->get();
            // Archived customers can settle debt reopened by a payment reversal.
            // They stay out of the selector used for current customers.
            if (! $selected->trashed() || $outstanding->isNotEmpty()) {
                $customer = $selected;
                $invoices = $outstanding;
            }
        }
        $rts = Rt::orderBy('code')->get()->filter(fn ($rt) => $user->role?->slug !== 'operator' || $user->rts->contains('id', $rt->id));

        return view('payments.index', [
            'payments' => $payments, 'customers' => $customers, 'customer' => $customer, 'invoices' => $invoices,
            'rts' => $rts, 'accounts' => Account::where('active', true)->orderBy('name')->get(),
            'methods' => PaymentMethod::where('active', true)->orderBy('name')->get(),
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
            'allocationMode' => SettingService::get('payment_allocation_mode', 'oldest'),
        ]);
    }

    public function store(Request $request, PaymentService $service)
    {
        $request->user()->requirePermission('payments.create');
        $request->validate(['proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $path = $request->file('proof')?->store('payment-proofs', 'local');
        try {
            $data = $request->except(['_token', 'proof']);
            $data['proof_path'] = $path;
            $payment = $service->pay($request->user(), $data);
            if ($path && $payment->proof_path !== $path) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        $redirect = $request->user()->hasPermission('payments.print')
            ? redirect()->route('payments.receipt', $payment)
            : redirect()->route('payments.index', ['customer_id' => $payment->customer_id]);

        return $redirect->with('success', 'Pembayaran tersimpan. Kuitansi dan alokasi tagihan telah diperbarui.');
    }

    public function receipt(Request $request, Payment $payment)
    {
        $request->user()->requirePermission('payments.print');
        abort_unless($request->user()->canAccessCustomer($payment->customer), 403);
        $payment->load(['customer.rt', 'account', 'method', 'user', 'allocations.invoice.period']);
        $html = view('payments.receipt', ['payment' => $payment, 'pdf' => $request->boolean('pdf')])->render();
        if ($request->boolean('pdf')) {
            $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf->loadHtml($html);
            $pdf->setPaper('A5', 'portrait');
            $pdf->render();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="kuitansi-'.preg_replace('/[^a-zA-Z0-9_-]/', '-', $payment->number).'.pdf"',
            ]);
        }

        return response($html);
    }

    public function cancel(Request $request, Payment $payment, PaymentService $service)
    {
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $service->reverse($request->user(), $payment, $request->string('reason')->toString());

        return back()->with('success', 'Pembayaran dibatalkan dengan transaksi pembalik. Riwayat kuitansi tetap tersimpan.');
    }

    public function proof(Request $request, Payment $payment)
    {
        $request->user()->requirePermission('payments.view');
        abort_unless($request->user()->canAccessCustomer($payment->customer), 403);
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path, null, ['X-Content-Type-Options' => 'nosniff']);
    }
}
