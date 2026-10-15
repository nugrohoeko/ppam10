<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Rt;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ReportService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class CloudStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed();
        $this->admin = User::factory()->create([
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'active' => true,
        ]);
        Customer::create([
            'number' => 'PAM10-991001', 'name' => 'Cloud export customer', 'address' => 'RW.10',
            'rt_id' => Rt::first()->id, 'category_id' => Category::first()->id,
            'status' => 'active', 'joined_on' => '2026-01-01',
        ]);
    }

    private function pathlessDisk(bool $rejectWrites = false): FilesystemAdapter
    {
        $backing = Storage::fake('local');
        $disk = new class($backing->getDriver(), $backing->getAdapter(), $backing->getConfig()) extends FilesystemAdapter
        {
            public bool $rejectWrites = false;

            public function path($path)
            {
                throw new LogicException('Remote storage does not provide local paths.');
            }

            public function put($path, $contents, $options = [])
            {
                return $this->rejectWrites ? false : parent::put($path, $contents, $options);
            }
        };
        $disk->rejectWrites = $rejectWrites;
        Storage::set('local', $disk);

        return $disk;
    }

    private function export(string $format): array
    {
        $token = (string) Str::uuid();
        $filters = ['type' => 'customers', 'format' => $format];
        Cache::put('pam-export:'.$token, [
            'user_id' => $this->admin->id, 'status' => 'queued', 'filters' => $filters,
        ], 3600);

        return [$token, new GenerateReportExport($this->admin->id, $token, $filters)];
    }

    public function test_queued_xlsx_can_be_stored_and_downloaded_without_a_local_storage_path(): void
    {
        $disk = $this->pathlessDisk();
        [$token, $job] = $this->export('xlsx');
        $job->handle(app(ReportService::class), app(ExportService::class));
        $state = Cache::get('pam-export:'.$token);
        $this->assertSame('ready', $state['status']);
        $bytes = $disk->get($state['path']);
        $this->assertStringStartsWith('PK', $bytes);

        $temporaryFile = tmpfile();
        try {
            fwrite($temporaryFile, $bytes);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(stream_get_meta_data($temporaryFile)['uri']));
            $this->assertStringContainsString('Cloud export customer', $zip->getFromName('xl/sharedStrings.xml'));
            $zip->close();
        } finally {
            fclose($temporaryFile);
        }

        $response = $this->actingAs($this->admin)->get(route('reports.exportDownload', $token));
        $response->assertOk()->assertDownload('laporan-pam.xlsx');
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_queued_pdf_uses_writable_cache_and_remote_private_storage(): void
    {
        $disk = $this->pathlessDisk();
        [$token, $job] = $this->export('pdf');
        $job->handle(app(ReportService::class), app(ExportService::class));
        $state = Cache::get('pam-export:'.$token);
        $this->assertSame('ready', $state['status']);
        $this->assertStringStartsWith('%PDF-', $disk->get($state['path']));
        $this->assertDirectoryExists(storage_path('framework/cache/dompdf'));
    }

    public function test_failed_remote_upload_does_not_mark_either_export_format_ready(): void
    {
        $this->pathlessDisk(true);
        foreach (['xlsx', 'pdf'] as $format) {
            [$token, $job] = $this->export($format);
            try {
                $job->handle(app(ReportService::class), app(ExportService::class));
                $this->fail('A rejected upload must fail the export.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Berkas ekspor tidak dapat disimpan.', $exception->getMessage());
                $job->failed($exception);
            }
            $state = Cache::get('pam-export:'.$token);
            $this->assertSame('failed', $state['status']);
            $this->assertArrayNotHasKey('path', $state);
        }
    }

    public function test_private_disk_can_alias_private_s3_without_changing_laragon_default(): void
    {
        $repository = Env::getRepository();
        $original = Env::get('PRIVATE_FILESYSTEM_DISK');
        try {
            $repository->set('PRIVATE_FILESYSTEM_DISK', 's3');
            $cloud = require config_path('filesystems.php');
            $this->assertSame($cloud['disks']['s3'], $cloud['disks']['local']);
            $this->assertSame('s3', $cloud['disks']['local']['driver']);
            $this->assertSame('private', $cloud['disks']['local']['visibility']);
            $this->assertTrue($cloud['disks']['local']['throw']);

            $repository->set('PRIVATE_FILESYSTEM_DISK', 'local');
            $local = require config_path('filesystems.php');
            $this->assertSame('local', $local['disks']['local']['driver']);
            $this->assertSame(storage_path('app/private'), $local['disks']['local']['root']);
            $this->assertFalse($local['disks']['local']['serve']);

            $repository->set('PRIVATE_FILESYSTEM_DISK', 'unsupported');
            $this->expectException(\InvalidArgumentException::class);
            require config_path('filesystems.php');
        } finally {
            $original === null ? $repository->clear('PRIVATE_FILESYSTEM_DISK') : $repository->set('PRIVATE_FILESYSTEM_DISK', $original);
        }
    }
}
