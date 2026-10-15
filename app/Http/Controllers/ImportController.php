<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Meter;
use App\Models\Rt;
use App\Services\AuditService;
use App\Services\ExportService;
use App\Services\NumberService;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ImportController extends Controller
{
    private const HEADERS = ['name', 'address', 'rt_code', 'category', 'phone', 'status', 'joined_on', 'meter_serial', 'initial_reading', 'installed_on', 'location', 'notes'];

    public function index(Request $request)
    {
        $request->user()->requirePermission('customers.import');

        return view('imports.index', ['preview' => $request->session()->get('customer_import')]);
    }

    public function template(Request $request, ExportService $exports)
    {
        $request->user()->requirePermission('customers.import');
        // The import header is intentionally the first row, without report metadata.
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Pelanggan');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray(['Nama Pelanggan', 'Alamat rumah', '01', 'Rumah', '081234567890', 'active', now()->format('Y-m-d'), 'GANTI-NOMOR-METER', '0.000', now()->format('Y-m-d'), '', 'Hapus baris contoh ini sebelum impor'], null, 'A2');
        $sheet->setCellValueExplicit('C2', '01', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('E2', '081234567890', DataType::TYPE_STRING);
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $instructions = $book->createSheet();
        $instructions->setTitle('Petunjuk');
        $instructions->fromArray([
            ['Template impor pelanggan PAM RW.10'], ['Maksimal 5.000 baris data; XLSX maksimal 5 MB.'],
            ['Jangan mengubah header pada lembar pertama. Hapus baris contoh.'],
            ['rt_code: kode RT aktif, misalnya 01. category: nama kategori aktif.'],
            ['status: active, inactive, disconnected. Tanggal: YYYY-MM-DD.'],
            ['initial_reading: angka positif, maksimal 3 desimal (gunakan titik).'],
            ['meter_serial: harus unik; ID pelanggan dibuat otomatis dan permanen.'],
            ['Telepon dan kode RT disimpan sebagai teks agar angka nol tetap ada.'],
            ['Seluruh data harus valid sebelum impor; transaksi dibatalkan jika satu baris gagal.'],
        ]);
        $instructions->getColumnDimension('A')->setWidth(105);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'template-pelanggan-pam.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function preview(Request $request)
    {
        $request->user()->requirePermission('customers.import');
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']]);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= 5002 && Coordinate::columnIndexFromString($columnAddress) <= 12;
            }
        });
        try {
            $book = $reader->load($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Berkas XLSX tidak dapat dibaca. Unggah template yang diisi tanpa enkripsi.']);
        }
        $sheet = $book->getSheet(0);
        $rawHeaders = array_map(fn ($value) => trim((string) $value), $sheet->rangeToArray('A1:L1', null, false, false)[0]);
        if ($rawHeaders !== self::HEADERS) {
            $book->disconnectWorksheets();

            return back()->withErrors(['file' => 'Header tidak sesuai template. Unduh template terbaru.']);
        }
        if ($sheet->getHighestDataRow() > 5001) {
            $book->disconnectWorksheets();

            return back()->withErrors(['file' => 'Maksimal 5.000 baris data per impor.']);
        }
        $rows = [];
        $seenSerials = [];
        for ($line = 2; $line <= $sheet->getHighestDataRow(); $line++) {
            $values = [];
            $formula = false;
            foreach (self::HEADERS as $index => $header) {
                $cell = $sheet->getCell([$index + 1, $line]);
                $formula = $formula || $cell->isFormula();
                $value = $cell->getValue();
                if (in_array($header, ['joined_on', 'installed_on']) && is_numeric($value) && Date::isDateTime($cell)) {
                    $value = Date::excelToDateTimeObject($value)->format('Y-m-d');
                }
                $values[$header] = trim((string) $value);
            }
            if (implode('', $values) === '') {
                continue;
            }
            $values['rt_code'] = str_pad($values['rt_code'], 2, '0', STR_PAD_LEFT);
            $values['status'] = $values['status'] ?: 'active';
            [$data, $errors] = $this->validateRow($request, $values);
            if ($formula) {
                $errors[] = 'Formula tidak diperbolehkan; tempelkan nilai saja.';
            }
            if (isset($seenSerials[$values['meter_serial']])) {
                $errors[] = 'Nomor meter berulang dalam berkas (baris '.$seenSerials[$values['meter_serial']].').';
            }
            $seenSerials[$values['meter_serial']] = $line;
            $rows[] = ['line' => $line, 'values' => $values, 'data' => $data, 'errors' => $errors];
        }
        $book->disconnectWorksheets();
        if (! $rows) {
            return back()->withErrors(['file' => 'Berkas tidak berisi data pelanggan.']);
        }
        $preview = ['token' => (string) Str::uuid(), 'user_id' => $request->user()->id, 'created_at' => now()->timestamp, 'rows' => $rows];
        $request->session()->put('customer_import', $preview);

        return redirect()->route('imports.index')->with('status', 'Pratinjau siap. Periksa data sebelum mengimpor.');
    }

    public function commit(Request $request)
    {
        $request->user()->requirePermission('customers.import');
        Gate::forUser($request->user())->authorize('create', Customer::class);
        $request->validate(['token' => ['required', 'uuid']]);
        $preview = $request->session()->get('customer_import');
        abort_unless($preview && $preview['user_id'] === $request->user()->id && hash_equals($preview['token'], $request->string('token')->toString()), 419, 'Pratinjau tidak berlaku. Unggah ulang berkas.');
        abort_if(now()->timestamp - $preview['created_at'] > 3600, 419, 'Pratinjau kedaluwarsa. Unggah ulang berkas.');
        if (collect($preview['rows'])->contains(fn ($row) => ! empty($row['errors']))) {
            return back()->withErrors(['file' => 'Masih ada baris gagal. Perbaiki berkas lalu ulangi pratinjau.']);
        }
        $count = DB::transaction(function () use ($request, $preview) {
            foreach ($preview['rows'] as $row) {
                // Revalidate at commit so changed masters and serial collisions cannot
                // bypass the preview. Database unique constraints remain authoritative.
                [$data, $errors] = $this->validateRow($request, $row['values']);
                if ($errors) {
                    throw ValidationException::withMessages(['file' => 'Baris '.$row['line'].': '.implode('; ', $errors)]);
                }
                $customer = Customer::create([
                    'number' => NumberService::next('customer'), 'name' => $data['name'], 'address' => $data['address'],
                    'rt_id' => $data['rt_id'], 'category_id' => $data['category_id'], 'phone' => $data['phone'] ?: null,
                    'status' => $data['status'], 'joined_on' => $data['joined_on'], 'location' => $data['location'] ?: null, 'notes' => $data['notes'] ?: null,
                ]);
                Meter::create(['customer_id' => $customer->id, 'serial' => $data['meter_serial'], 'initial_reading' => $data['initial_reading'], 'installed_on' => $data['installed_on'], 'active' => true]);
                AuditService::record('customer.import', $customer, [], $customer->toArray());
            }

            return count($preview['rows']);
        });
        $request->session()->forget('customer_import');

        return redirect()->route('customers.index')->with('status', $count.' pelanggan dan meter berhasil diimpor.');
    }

    public function errors(Request $request, ExportService $exports)
    {
        $request->user()->requirePermission('customers.import');
        $preview = $request->session()->get('customer_import');
        abort_unless($preview && $preview['user_id'] === $request->user()->id, 404);
        $columns = [['key' => 'line', 'label' => 'Baris Excel', 'type' => 'number']];
        foreach (self::HEADERS as $header) {
            $columns[] = ['key' => $header, 'label' => $header, 'type' => 'text'];
        }
        $columns[] = ['key' => 'errors', 'label' => 'Kesalahan', 'type' => 'text'];
        $rows = collect($preview['rows'])->filter(fn ($r) => ! empty($r['errors']))->map(fn ($r) => ['line' => $r['line']] + $r['values'] + ['errors' => implode('; ', $r['errors'])])->values()->all();

        return $exports->xlsx(['title' => 'Baris impor gagal', 'columns' => $columns, 'rows' => $rows, 'filters' => [], 'totals' => [], 'rtTotals' => []], 'baris-impor-gagal');
    }

    private function validateRow(Request $request, array $values): array
    {
        $rt = Rt::where('code', $values['rt_code'])->where('active', true)->first();
        $category = Category::where('name', $values['category'])->where('active', true)->first();
        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:150'], 'address' => ['required', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'], 'status' => ['required', Rule::in(['active', 'inactive', 'disconnected'])],
            'joined_on' => ['required', 'date_format:Y-m-d'], 'meter_serial' => ['required', 'string', 'max:100', Rule::unique('meters', 'serial')],
            'initial_reading' => ['required', 'regex:/^\d{1,11}'.((int) SettingService::get('meter_precision', 3) > 0 ? '(?:\.\d{1,'.(int) SettingService::get('meter_precision', 3).'})?' : '').'$/D'],
            'installed_on' => ['required', 'date_format:Y-m-d'], 'location' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $errors = $validator->errors()->all();
        if (! $rt) {
            $errors[] = 'Kode RT tidak ditemukan atau nonaktif.';
        } elseif ($request->user()->role?->slug === 'operator' && ! $request->user()->rts()->where('rts.id', $rt->id)->exists()) {
            $errors[] = 'RT berada di luar penugasan operator.';
        }
        if (! $category) {
            $errors[] = 'Kategori tidak ditemukan atau nonaktif.';
        }

        return [$values + ['rt_id' => $rt?->id, 'category_id' => $category?->id], $errors];
    }
}
