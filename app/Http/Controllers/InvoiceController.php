<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Period;
use App\Models\Reading;
use App\Models\Rt;
use App\Services\BillingService;
use App\Services\SettingService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $r, BillingService $s)
    {
        $r->user()->requirePermission('invoices.view');
        $q = Invoice::with(['period', 'customer']);
        $ids = $r->user()->scopeCustomers(Customer::withTrashed())->select('id');
        $q->whereIn('customer_id', $ids);
        if ($r->filled('rt_id')) {
            $q->where('customer_snapshot->rt_id', (int) $r->rt_id);
        }if ($r->filled('period_id')) {
            $q->where('period_id', $r->period_id);
        }if ($r->filled('status')) {
            $q->where('status', $r->status);
        }if ($r->boolean('arrears')) {
            $q->whereIn('status', ['issued', 'partial'])->where('due_date', '<', today());
        }if ($r->filled('q')) {
            $q->where(fn ($q) => $q->where('number', 'like', '%'.$r->q.'%')->orWhere('customer_snapshot->name', 'like', '%'.$r->q.'%')->orWhere('customer_snapshot->number', 'like', '%'.$r->q.'%'));
        }
        $eligible = Reading::whereIn('customer_id', $r->user()->scopeCustomers(Customer::query())->select('id'))->where('status', 'verified')->whereDoesntHave('invoices', fn ($q) => $q->where('status', '!=', 'cancelled'))->when($r->filled('period_id'), fn ($q) => $q->where('period_id', $r->period_id))->when($r->filled('rt_id'), fn ($q) => $q->where('customer_snapshot->rt_id', (int) $r->rt_id))->with(['customer', 'period'])->get();
        $previews = [];
        foreach ($eligible as $reading) {
            try {
                $previews[$reading->id] = $s->preview($reading);
            } catch (ValidationException $e) {
                $previews[$reading->id] = ['error' => collect($e->errors())->flatten()->join(' ')];
            }
        }

        return view('invoices.index', ['invoices' => $q->orderByDesc('id')->paginate(25)->withQueryString(), 'periods' => Period::orderByDesc('starts_on')->get(), 'rts' => Rt::orderBy('code')->get(), 'eligible' => $eligible, 'previews' => $previews]);
    }

    public function store(Request $r, BillingService $s)
    {
        $r->user()->requirePermission('invoices.create');
        $data = $r->validate(['reading_ids' => 'required|array|min:1|max:1000', 'reading_ids.*' => 'required|exists:readings,id', 'action' => 'required|in:draft,issue']);
        DB::transaction(function () use ($r, $s, $data) {
            foreach (array_unique($data['reading_ids']) as $id) {
                $s->generate($r->user(), Reading::findOrFail($id), $data['action'] === 'issue');
            }
        });

        return back()->with('success', 'Tagihan berhasil diproses.');
    }

    public function issue(Request $r, Invoice $invoice, BillingService $s)
    {
        $s->issue($r->user(), $invoice);

        return back()->with('success', 'Tagihan diterbitkan.');
    }

    public function cancel(Request $r, Invoice $invoice, BillingService $s)
    {
        $r->validate(['reason' => 'required|string|min:5|max:2000']);
        $s->cancel($r->user(), $invoice, $r->reason);

        return back()->with('success', 'Tagihan dibatalkan dengan jejak audit.');
    }

    public function print(Request $r)
    {
        $r->user()->requirePermission('invoices.print');
        $q = Invoice::whereIn('customer_id', $r->user()->scopeCustomers(Customer::withTrashed())->select('id'))->with('period');
        if ($r->filled('ids')) {
            $ids = (array) $r->input('ids');
            $q->whereIn('id', $ids);
        } else {
            if ($r->filled('period_id')) {
                $q->where('period_id', $r->period_id);
            }if ($r->filled('rt_id')) {
                $q->where('customer_snapshot->rt_id', (int) $r->rt_id);
            }if ($r->filled('status')) {
                $q->where('status', $r->status);
            }if ($r->boolean('arrears')) {
                $q->whereIn('status', ['issued', 'partial'])->where('due_date', '<', today());
            }
        } if ($r->filled('q')) {
            $q->where(fn ($query) => $query->where('number', 'like', '%'.$r->q.'%')->orWhere('customer_snapshot->name', 'like', '%'.$r->q.'%')->orWhere('customer_snapshot->number', 'like', '%'.$r->q.'%'));
        }$invoices = $q->orderBy('id')->get();
        abort_if($invoices->count() > 1000, 422, 'Maksimal 1.000 dokumen sekali cetak. Gunakan filter periode dan RT.');
        $settings = ['name' => SettingService::get('pam_name', 'PAM RW.10'), 'address' => SettingService::get('pam_address', ''), 'contact' => SettingService::get('pam_contact', ''), 'bank' => SettingService::get('payment_info', ''), 'note' => SettingService::get('invoice_note', '')];
        $html = view('invoices.print', compact('invoices', 'settings'))->render();
        if ($r->boolean('pdf')) {
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml($html);
            $pdf->setPaper('A4');
            $pdf->render();
            $pdf->getCanvas()->page_text(30, 820, 'Halaman {PAGE_NUM} / {PAGE_COUNT}', null, 8, [0.4, 0.4, 0.4]);

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="tagihan-pam.pdf"']);
        }

        return response($html);
    }
}
