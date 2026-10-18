<?php

namespace App\Services;

use App\Models\DocumentScan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfPageBatches
{
    /** @return array{count: int, batches: array<int, string>} */
    public function make(DocumentScan $scan, int $size): array
    {
        // shortcut: compressed PDFs need local Python with pypdf, use a PHP parser if that runtime is unavailable.
        $path = Storage::disk('local')->path($scan->storage_path);

        try {
            $source = new Fpdi;
            $count = $source->setSourceFile($path);
            $batches = [];
            for ($start = 1; $start <= $count; $start += $size) {
                $pdf = new Fpdi;
                $pdf->setSourceFile($path);
                for ($page = $start; $page <= min($count, $start + $size - 1); $page++) {
                    $template = $pdf->importPage($page);
                    $dimensions = $pdf->getTemplateSize($template);
                    $pdf->AddPage($dimensions['orientation'], [$dimensions['width'], $dimensions['height']]);
                    $pdf->useTemplate($template);
                }
                $batches[$start] = $pdf->Output('S');
            }

            return ['count' => $count, 'batches' => $batches];
        } catch (CrossReferenceException) {
            return $this->splitWithPython($path, $size);
        } catch (Throwable $exception) {
            throw new RuntimeException('The PDF could not be read or split locally.', previous: $exception);
        }
    }

    /** @return array{count: int, batches: array<int, string>} */
    private function splitWithPython(string $path, int $size): array
    {
        try {
            $process = new Process([
                PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3',
                base_path('app/Services/split_pdf.py'), $path, (string) $size,
            ]);
            $process->setTimeout(300)->run();
            $result = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
            if (! is_array($result) || ! is_int($result['count'] ?? null) || $result['count'] < 1 || ! is_array($result['batches'] ?? null)) {
                throw new RuntimeException('The local PDF fallback could not split the document.');
            }
            $batches = [];
            for ($start = 1; $start <= $result['count']; $start += $size) {
                $encoded = $result['batches'][$start] ?? null;
                $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;
                if (! is_string($bytes) || $bytes === '') {
                    throw new RuntimeException('The local PDF fallback returned an incomplete batch.');
                }
                $batches[$start] = $bytes;
            }

            return ['count' => $result['count'], 'batches' => $batches];
        } catch (Throwable $exception) {
            throw new RuntimeException('The PDF could not be read or split locally. Check that Python and pypdf are installed.', previous: $exception);
        }
    }
}
