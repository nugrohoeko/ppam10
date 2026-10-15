<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $userId, public string $token, public array $filters) {}

    public static function scopeFingerprint(User $actor): string
    {
        $actor->load('role');
        $territory = $actor->role?->slug === 'operator' ? $actor->scopeCustomers(Customer::withTrashed())->orderBy('id')->pluck('id')->all() : [];

        return hash('sha256', json_encode([$actor->active, $actor->role_id, $actor->role?->permissions, $territory]));
    }

    public static function requireExportPermission(User $actor, array $filters): void
    {
        $sourcePermission = match ($filters['type'] ?? 'customers') {
            'customers' => 'customers.export',
            'invoices', 'paid', 'unpaid', 'partial', 'payment_status', 'arrears' => 'invoices.export',
            'receipts' => 'payments.export',
            'expenses', 'cashbook', 'summary' => 'finance.export',
            'audit' => 'audit.export',
            default => 'reports.export',
        };
        if (! $actor->hasPermission($sourcePermission)) {
            $actor->requirePermission('reports.export');
            $actor->requirePermission('reports.view');
        }
        abort_unless(ReportService::canViewType($actor, $filters['type'] ?? 'customers'), 403);
    }

    public function handle(ReportService $reports, ExportService $exports): void
    {
        $key = 'pam-export:'.$this->token;
        $state = Cache::get($key);
        if (! $state || $state['user_id'] !== $this->userId) {
            return;
        }
        Cache::put($key, array_merge($state, ['status' => 'processing']), 3600);
        $actor = User::findOrFail($this->userId);
        $initialScope = self::scopeFingerprint($actor);
        self::requireExportPermission($actor, $this->filters);
        $report = $reports->build($actor, $this->filters);
        $format = ($this->filters['format'] ?? 'xlsx') === 'pdf' ? 'pdf' : 'xlsx';
        $path = 'exports/'.$this->userId.'/'.$this->token.'.'.$format;
        $disk = Storage::disk('local');
        if ($format === 'pdf') {
            if (! $disk->put($path, $exports->pdfBytes(view('exports.report', compact('report'))->render(), 'a4', 'landscape'))) {
                throw new RuntimeException('Berkas ekspor tidak dapat disimpan.');
            }
        } else {
            $book = $exports->spreadsheet($report);
            $temporaryFile = tmpfile();
            try {
                if ($temporaryFile === false) {
                    throw new RuntimeException('Berkas sementara ekspor tidak dapat dibuat.');
                }
                (new Xlsx($book))->save($temporaryFile);
                rewind($temporaryFile);
                if (! $disk->put($path, $temporaryFile)) {
                    throw new RuntimeException('Berkas ekspor tidak dapat disimpan.');
                }
            } finally {
                if (is_resource($temporaryFile)) {
                    fclose($temporaryFile);
                }
                $book->disconnectWorksheets();
            }
        }
        if (! hash_equals($initialScope, self::scopeFingerprint($actor->fresh()))) {
            Storage::disk('local')->delete($path);
            throw new AuthorizationException('Hak akses berubah saat laporan diproses. Buat ulang ekspor.');
        }
        Cache::put($key, array_merge($state, ['status' => 'ready', 'path' => $path, 'scope' => $initialScope, 'rows' => count($report['rows'])]), 3600);
    }

    public function failed(?Throwable $exception): void
    {
        $key = 'pam-export:'.$this->token;
        if ($state = Cache::get($key)) {
            Cache::put($key, array_merge($state, ['status' => 'failed']), 3600);
        }
    }
}
