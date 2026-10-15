<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateReportExport;
use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Rt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        $request->user()->requirePermission('reports.view');
        $filters = $this->filters($request);
        $report = $reports->build($request->user(), $filters);
        $rows = collect($report['rows'])->forPage(max(1, $request->integer('page', 1)), 50);
        $rts = Rt::query()->when($request->user()->role?->slug === 'operator', fn ($q) => $q->whereIn('id', $request->user()->rts()->pluck('rts.id')))->orderBy('code')->get();

        return view('reports.index', compact('report', 'rows', 'filters', 'rts') + [
            'types' => array_filter(ReportService::TYPES, fn ($key) => ReportService::canViewType($request->user(), $key), ARRAY_FILTER_USE_KEY), 'periods' => Period::orderByDesc('starts_on')->get(),
            'categories' => Category::orderBy('name')->get(), 'accounts' => Account::all(),
            'methods' => PaymentMethod::all(), 'operators' => User::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function export(Request $request, ReportService $reports, ExportService $exports)
    {
        GenerateReportExport::requireExportPermission($request->user(), ['type' => $request->input('type', 'customers')]);
        $filters = $this->filters($request);
        $report = $reports->build($request->user(), $filters);
        AuditService::record('report.export', null, [], ['type' => $filters['type'] ?? 'customers', 'filters' => $filters, 'rows' => count($report['rows'])]);
        if (count($report['rows']) > 2000 || $request->boolean('async')) {
            $token = (string) Str::uuid();
            Cache::put('pam-export:'.$token, ['user_id' => $request->user()->id, 'status' => 'queued', 'created_at' => now()->format('d/m/Y H:i'), 'filters' => $filters], 3600);
            GenerateReportExport::dispatch($request->user()->id, $token, $filters);

            return redirect()->route('reports.exportStatus', $token);
        }
        $filename = 'pam-'.($filters['type'] ?? 'customers').'-'.now()->format('Ymd-His');

        return ($filters['format'] ?? 'xlsx') === 'pdf' ? $exports->pdf($report, $filename) : $exports->xlsx($report, $filename);
    }

    public function exportStatus(Request $request, string $token)
    {
        abort_unless(Str::isUuid($token), 404);
        $state = Cache::get('pam-export:'.$token);
        abort_unless($state && $state['user_id'] === $request->user()->id, 404, 'Ekspor tidak ditemukan atau kedaluwarsa.');
        GenerateReportExport::requireExportPermission($request->user(), $state['filters']);

        return view('exports.status', compact('state', 'token'));
    }

    public function exportDownload(Request $request, string $token)
    {
        abort_unless(Str::isUuid($token), 404);
        $state = Cache::get('pam-export:'.$token);
        abort_unless($state && $state['user_id'] === $request->user()->id && $state['status'] === 'ready', 404, 'Ekspor belum siap atau kedaluwarsa.');
        GenerateReportExport::requireExportPermission($request->user(), $state['filters']);
        abort_unless(hash_equals($state['scope'], GenerateReportExport::scopeFingerprint($request->user())), 403, 'Hak akses berubah. Buat ulang ekspor.');
        abort_unless(Storage::disk('local')->exists($state['path']), 404);

        return Storage::disk('local')->download($state['path'], 'laporan-pam.'.pathinfo($state['path'], PATHINFO_EXTENSION));
    }

    private function filters(Request $request): array
    {
        if (($request->input('type', 'customers')) === 'customers') {
            if (in_array($request->input('status'), ['active', 'inactive', 'disconnected'])) {
                $request->merge(['customer_status' => $request->input('status'), 'status' => null]);
            }
            if ($request->filled('rt_id') && ! $request->filled('rt_ids')) {
                $request->merge(['rt_ids' => [$request->input('rt_id')]]);
            }
        }

        return $request->validate([
            'type' => ['nullable', Rule::in(array_keys(ReportService::TYPES))],
            'q' => ['nullable', 'string', 'max:150'],
            'rt_ids' => ['nullable', 'array'], 'rt_ids.*' => ['integer', 'exists:rts,id'],
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', Rule::in(['draft', 'issued', 'partial', 'paid', 'cancelled'])],
            'customer_status' => ['nullable', Rule::in(['active', 'inactive', 'disconnected'])],
            'operator_id' => ['nullable', 'integer', 'exists:users,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'format' => ['nullable', Rule::in(['pdf', 'xlsx'])], 'group_by' => ['nullable', Rule::in(['month', 'year'])],
            'async' => ['nullable', 'boolean'],
        ]);
    }
}
