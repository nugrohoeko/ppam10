<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Rt;
use App\Services\AuditService;
use App\Services\ExportService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Picqer\Barcode\BarcodeGeneratorPNG;

class BarcodeController extends Controller
{
    public function index(Request $request)
    {
        $request->user()->requirePermission('barcodes.view');
        $data = $this->data($request);

        return view('barcodes.index', $data + [
            'rts' => Rt::query()->when($request->user()->role?->slug === 'operator', fn ($q) => $q->whereIn('id', $request->user()->rts()->pluck('rts.id')))->orderBy('code')->get(),
            'categories' => Category::where('active', true)->get(),
        ]);
    }

    public function pdf(Request $request, ExportService $exports)
    {
        $request->user()->requirePermission('barcodes.print');
        $request->user()->requirePermission('barcodes.export');
        $data = $this->data($request);
        abort_if($data['customers']->isEmpty(), 422, 'Tidak ada pelanggan sesuai pilihan.');
        AuditService::record('barcode.print', null, [], ['customers' => $data['customers']->pluck('id')->all(), 'settings' => $data['print']]);
        $html = view('barcodes.labels', $data)->render();

        return response($exports->pdfBytes($html, strtolower($data['print']['paper'])))
            ->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'inline; filename="label-pelanggan.pdf"');
    }

    private function data(Request $request): array
    {
        $f = $request->validate([
            'ids' => ['nullable', 'array', 'max:1000'], 'ids.*' => ['integer', 'exists:customers,id'],
            'rt_ids' => ['nullable', 'array'], 'rt_ids.*' => ['integer', 'exists:rts,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'disconnected'])],
            'label_width' => ['nullable', 'numeric', 'min:35', 'max:200'], 'label_height' => ['nullable', 'numeric', 'min:25', 'max:120'],
            'paper' => ['nullable', Rule::in(['A4', 'Letter'])], 'margin' => ['nullable', 'numeric', 'min:3', 'max:25'],
            'columns' => ['nullable', 'integer', 'min:1', 'max:5'], 'copies' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
        $defaults = [
            'label_width' => SettingService::get('label_width_mm', 62),
            'label_height' => SettingService::get('label_height_mm', 35),
            'columns' => SettingService::get('label_columns', 3),
            'margin' => SettingService::get('label_margin_mm', 8),
            'paper' => SettingService::get('label_paper', 'A4'),
            'copies' => SettingService::get('label_copies', 1),
        ];
        $print = [];
        foreach (['label_width' => 62, 'label_height' => 36, 'paper' => 'A4', 'margin' => 8, 'columns' => 3, 'copies' => 1] as $key => $default) {
            $print[$key] = $f[$key] ?? ($defaults[$key] ?? $default);
        }
        $paperWidth = $print['paper'] === 'Letter' ? 215.9 : 210;
        $paperHeight = $print['paper'] === 'Letter' ? 279.4 : 297;
        abort_if($paperWidth < $print['label_width'] * $print['columns'] + 2 * $print['margin'] || $paperHeight < $print['label_height'] + 2 * $print['margin'], 422, 'Ukuran label dan jumlah kolom melebihi area kertas.');
        $customers = $request->user()->scopeCustomers(Customer::query())->with(['rt', 'category'])
            ->when(! empty($f['ids']), fn ($q) => $q->whereIn('id', $f['ids']))
            ->when(! empty($f['rt_ids']), fn ($q) => $q->whereIn('rt_id', $f['rt_ids']))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('category_id', $f['category_id']))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$f['q'].'%')->orWhere('name', 'like', '%'.$f['q'].'%')))
            ->orderBy('rt_id')->orderBy('number')->get();
        $generator = new BarcodeGeneratorPNG;
        $labels = [];
        foreach ($customers as $customer) {
            $barcode = 'data:image/png;base64,'.base64_encode($generator->getBarcode($customer->number, $generator::TYPE_CODE_128, 2, 48));
            for ($copy = 0; $copy < $print['copies']; $copy++) {
                $labels[] = ['customer' => $customer, 'barcode' => $barcode];
            }
        }

        return compact('customers', 'labels', 'print', 'f') + ['pamName' => SettingService::get('pam_name', 'PAM RW.10')];
    }
}
