<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Meter;
use App\Models\PaymentMethod;
use App\Models\Period;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ExportService;
use App\Services\MeterService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\SettingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Period $period;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed();
        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id'), 'active' => true]);
        SettingService::set('reading_verification', false);
        $this->period = Period::create(['name' => '2026-01', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'due_date' => '2026-02-20']);
        $this->customer = $this->customer('PAM10-900001', Rt::where('code', '01')->first(), '=2+3');
        $reading = app(MeterService::class)->save($this->admin, $this->customer, $this->period, ['current_reading' => '21.000', 'status' => 'submitted']);
        $this->invoice = app(BillingService::class)->generate($this->admin, $reading);
    }

    private function customer(string $number, Rt $rt, string $name = 'Pelanggan uji'): Customer
    {
        $customer = Customer::create(['number' => $number, 'name' => $name, 'address' => 'RW.10', 'rt_id' => $rt->id, 'category_id' => Category::where('name', 'Rumah')->value('id'), 'status' => 'active', 'joined_on' => '2026-01-01']);
        Meter::create(['customer_id' => $customer->id, 'serial' => 'M-'.$number, 'initial_reading' => '0.000', 'installed_on' => '2026-01-01', 'active' => true]);

        return $customer;
    }

    private function pay(int $amount, string $date = '2026-02-05')
    {
        return app(PaymentService::class)->pay($this->admin, ['customer_id' => $this->customer->id, 'account_id' => Account::first()->id, 'payment_method_id' => PaymentMethod::first()->id, 'amount' => $amount, 'paid_on' => $date, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_billing_period_and_receipt_date_filters_remain_separate_and_export_totals_match(): void
    {
        $this->pay(12000);
        $this->pay(10000, '2026-03-05');
        $filters = ['type' => 'invoices', 'period_id' => $this->period->id, 'date_from' => '2026-02-01', 'date_to' => '2026-02-28', 'rt_ids' => [$this->customer->rt_id]];
        $report = app(ReportService::class)->build($this->admin, $filters);
        $this->assertCount(1, $report['rows']);
        $this->assertSame(48000, $report['totals']['total']);
        $this->assertSame(12000, $report['totals']['paid']);
        $this->assertSame(26000, $report['totals']['balance']);
        $receipts = app(ReportService::class)->build($this->admin, array_merge($filters, ['type' => 'receipts']));
        $this->assertSame(12000, $receipts['totals']['amount']);
        $outside = app(ReportService::class)->build($this->admin, array_merge($filters, ['rt_ids' => [Rt::where('code', '02')->value('id')]]));
        $this->assertCount(0, $outside['rows']);
        $book = app(ExportService::class)->spreadsheet($report);
        $sheet = $book->getActiveSheet();
        $this->assertSame(48000, $sheet->getCell('H7')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('H7')->getDataType());
        $this->assertSame(12000, $sheet->getCell('I7')->getValue());
        $this->assertSame('=2+3', $sheet->getCell('B7')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B7')->getDataType());
        $book->disconnectWorksheets();
    }

    public function test_historical_reading_and_invoice_rt_filters_use_snapshots(): void
    {
        $oldRt = $this->customer->rt_id;
        $this->customer->update(['rt_id' => Rt::where('code', '02')->value('id')]);
        foreach (['invoices', 'readings'] as $type) {
            $report = app(ReportService::class)->build($this->admin, ['type' => $type, 'period_id' => $this->period->id, 'rt_ids' => [$oldRt]]);
            $this->assertCount(1, $report['rows']);
            $this->assertSame('RT.01', $report['rows'][0]['rt']);
        }
    }

    public function test_operator_export_and_label_cannot_escape_assigned_rt(): void
    {
        $role = Role::where('slug', 'operator')->first();
        $role->update(['permissions' => array_merge($role->permissions, ['reports.view', 'reports.export', 'invoices.view', 'barcodes.print', 'barcodes.export'])]);
        $operator = User::factory()->create(['role_id' => $role->id, 'active' => true]);
        $operator->rts()->attach(Rt::where('code', '02')->value('id'));
        $report = app(ReportService::class)->build($operator, ['type' => 'invoices', 'rt_ids' => [$this->customer->rt_id]]);
        $this->assertCount(0, $report['rows']);
        $this->actingAs($operator)->get(route('barcodes.pdf', ['ids' => [$this->customer->id]]))->assertStatus(422);
        $this->actingAs($operator)->get(route('reports.index'))->assertOk()->assertDontSee('PAM10-900001');
        $this->actingAs($operator)->get(route('reports.index', ['type' => 'receipts']))->assertForbidden();
    }

    public function test_customer_export_keeps_search_status_rt_filters_and_own_permission(): void
    {
        $this->customer->update(['name' => 'Pelanggan ekspor']);
        $this->customer('PAM10-900002', Rt::where('code', '01')->first(), 'Tidak cocok');
        $role = Role::create(['name' => 'Ekspor master', 'slug' => 'export-master', 'permissions' => ['customers.view', 'customers.export']]);
        $user = User::factory()->create(['role_id' => $role->id, 'active' => true]);
        $response = $this->actingAs($user)->get(route('reports.export', ['type' => 'customers', 'format' => 'xlsx', 'q' => 'Pelanggan ekspor', 'status' => 'active', 'rt_id' => $this->customer->rt_id, 'page' => 2]));
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'pam-export-test-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame('PAM10-900001', $book->getActiveSheet()->getCell('A7')->getValue());
            $this->assertSame('Pelanggan ekspor', $book->getActiveSheet()->getCell('B7')->getValue());
            $this->assertNull($book->getActiveSheet()->getCell('A8')->getValue());
            $headers = $book->getActiveSheet()->rangeToArray('A6:M6')[0];
            $this->assertNotContains('Tagihan Rp', $headers);
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
    }

    public function test_all_fifteen_reports_render_with_live_data_and_pdf_exports_are_real(): void
    {
        $this->pay(10000);
        $this->customer('PAM10-900002', Rt::where('code', '02')->first());
        foreach (array_keys(ReportService::TYPES) as $type) {
            $this->actingAs($this->admin)->get(route('reports.index', ['type' => $type, 'period_id' => $this->period->id]))->assertOk();
        }
        $response = $this->actingAs($this->admin)->get(route('reports.export', ['type' => 'invoices', 'format' => 'pdf']));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $barcode = $this->actingAs($this->admin)->get(route('barcodes.pdf', ['ids' => [$this->customer->id], 'copies' => 2]));
        $barcode->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $barcode->getContent());
    }

    public function test_payment_reversal_keeps_historical_cash_dates_and_balancing_rows(): void
    {
        $payment = $this->pay(10000);
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
        app(PaymentService::class)->reverse($this->admin, $payment, 'Pembayaran salah pelanggan');
        $reports = app(ReportService::class);
        $this->assertSame(0, $reports->build($this->admin, ['type' => 'receipts'])['totals']['amount']);
        $this->assertSame(10000, $reports->build($this->admin, ['type' => 'receipts', 'date_from' => '2026-02-01', 'date_to' => '2026-02-28'])['totals']['amount']);
        $this->assertSame(-10000, $reports->build($this->admin, ['type' => 'receipts', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31'])['totals']['amount']);
        $cash = $reports->build($this->admin, ['type' => 'cashbook']);
        $this->assertSame(10000, $cash['totals']['incoming']);
        $this->assertSame(10000, $cash['totals']['outgoing']);
        $this->travelBack();
    }

    public function test_queued_export_produces_private_file_and_checks_owner(): void
    {
        Storage::fake('local');
        $response = $this->actingAs($this->admin)->get(route('reports.export', ['type' => 'invoices', 'format' => 'xlsx', 'async' => 1]));
        $response->assertRedirect();
        $url = $response->headers->get('Location');
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Laporan siap diunduh');
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->actingAs($this->admin)->get(route('reports.exportDownload', $token))->assertOk()->assertDownload('laporan-pam.xlsx');
        $other = User::factory()->create(['role_id' => $this->admin->role_id, 'active' => true]);
        $this->actingAs($other)->get(route('reports.exportDownload', $token))->assertNotFound();
    }

    public function test_export_is_discarded_when_permissions_change_during_generation(): void
    {
        Storage::fake('local');
        $token=(string) Str::uuid();
        $filters=['type'=>'customers','format'=>'xlsx'];
        \Illuminate\Support\Facades\Cache::put('pam-export:'.$token,['user_id'=>$this->admin->id,'status'=>'queued','created_at'=>now()->format('d/m/Y H:i'),'filters'=>$filters],3600);
        $exports=new class($this->admin->role_id) extends ExportService {
            public function __construct(private int $roleId) {}
            public function spreadsheet(array $report): Spreadsheet
            {
                $book=parent::spreadsheet($report);
                Role::findOrFail($this->roleId)->update(['permissions'=>['dashboard.view','customers.view','customers.export']]);
                return $book;
            }
        };
        try {
            (new \App\Jobs\GenerateReportExport($this->admin->id,$token,$filters))->handle(app(ReportService::class),$exports);
            $this->fail('Ekspor dengan hak akses yang berubah seharusnya dibuang.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            $this->assertStringContainsString('Hak akses berubah',$exception->getMessage());
        }
        $this->assertSame([],Storage::disk('local')->allFiles('exports'));
        $this->assertNotSame('ready',\Illuminate\Support\Facades\Cache::get('pam-export:'.$token)['status']);
    }

    private function workbook(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['name', 'address', 'rt_code', 'category', 'phone', 'status', 'joined_on', 'meter_serial', 'initial_reading', 'installed_on', 'location', 'notes'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'pam-import-');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'pelanggan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_import_previews_errors_and_commits_customer_and_meter_once(): void
    {
        $values = ['Pelanggan impor', 'Alamat impor', '01', 'Rumah', '0812345', 'active', '2026-01-01', 'M-IMPORTED', '1.250', '2026-01-01', 'Depan rumah', ''];
        $file = $this->workbook([$values]);
        try {
            $this->actingAs($this->admin)->post(route('imports.preview'), ['file' => $file])->assertRedirect(route('imports.index'))->assertSessionHasNoErrors();
            $preview = session('customer_import');
            $this->assertSame([], $preview['rows'][0]['errors']);
            $this->actingAs($this->admin)->post(route('imports.commit'), ['token' => $preview['token']])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('customers', ['name' => 'Pelanggan impor']);
            $this->assertDatabaseHas('meters', ['serial' => 'M-IMPORTED', 'initial_reading' => '1.25']);
            $this->actingAs($this->admin)->post(route('imports.commit'), ['token' => $preview['token']])->assertStatus(419);
        } finally {
            @unlink($file->getPathname());
        }
        $file = $this->workbook([$values]);
        try {
            $this->actingAs($this->admin)->post(route('imports.preview'), ['file' => $file])->assertRedirect(route('imports.index'));
            $preview = session('customer_import');
            $this->assertNotEmpty($preview['rows'][0]['errors']);
            $this->actingAs($this->admin)->post(route('imports.commit'), ['token' => $preview['token']])->assertSessionHasErrors('file');
            $this->assertSame(1, Customer::where('name', 'Pelanggan impor')->count());
        } finally {
            @unlink($file->getPathname());
        }
    }
}
