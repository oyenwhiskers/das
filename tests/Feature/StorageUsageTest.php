<?php

namespace Tests\Feature;

use App\Models\AttachmentScan;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\StorageUsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_usage_page_is_admin_only_and_shows_disk_metrics(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('document-scans/sample.pdf', str_repeat('a', 2048));

        DocumentScan::factory()->create(['file_size' => 2048, 'storage_path' => 'document-scans/sample.pdf']);
        AttachmentScan::factory()->create(['file_size' => 1024, 'storage_path' => 'attachment-scans/sample.pdf']);

        $staff = User::factory()->create();
        $this->actingAs($staff)->get(route('storage-usage.index'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get(route('storage-usage.index'))
            ->assertOk()
            ->assertSee('Storage Usage')
            ->assertSee('Server disk')
            ->assertSee('DAS files')
            ->assertSee('Document scans')
            ->assertSee('Attachments')
            ->assertSee('2.00 KB')
            ->assertSee('1.00 KB')
            ->assertSee('Free')
            ->assertSee('App files')
            ->assertSee('/app/storage/app');
    }

    public function test_report_uses_local_disk_and_survives_missing_private_directory(): void
    {
        Storage::fake('local');
        $root = Storage::disk('local')->path('');
        if (is_dir($root)) {
            $this->deleteDirectory($root);
        }

        DocumentScan::factory()->create(['file_size' => 4096, 'storage_path' => 'document-scans/a.pdf']);

        $report = app(StorageUsageReport::class)->report();

        $this->assertDirectoryExists($report['path']);
        $this->assertSame(4096, $report['documents']);
        $this->assertSame(0, $report['attachments']);
        $this->assertIsBool($report['available']);
        $this->assertIsFloat($report['free']);
        $this->assertIsFloat($report['total']);
        $this->assertStringContainsString('storage', str_replace('\\', '/', $report['mount']));
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
