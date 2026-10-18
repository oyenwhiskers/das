<?php

namespace Tests\Unit;

use App\Models\DocumentScan;
use App\Services\PdfPageBatches;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Tests\TestCase;

class PdfPageBatchesTest extends TestCase
{
    public function test_compressed_pdf_uses_local_fallback_and_retains_page_batches(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('compressed.pdf', file_get_contents(base_path('tests/Fixtures/compressed.pdf')));

        try {
            (new Fpdi)->setSourceFile(Storage::disk('local')->path('compressed.pdf'));
            $this->fail('The fixture must exercise the compressed PDF fallback.');
        } catch (CrossReferenceException) {
            $this->assertTrue(true);
        }

        $parts = app(PdfPageBatches::class)->make(new DocumentScan(['storage_path' => 'compressed.pdf']), 2);

        $this->assertSame(4, $parts['count']);
        $this->assertSame([1, 3], array_keys($parts['batches']));
        foreach ($parts['batches'] as $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'das-pdf-');
            try {
                file_put_contents($path, $bytes);
                $this->assertSame(2, (new Fpdi)->setSourceFile($path));
            } finally {
                unlink($path);
            }
        }
    }
}
