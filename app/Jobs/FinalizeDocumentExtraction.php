<?php

namespace App\Jobs;

use App\Models\DocumentScan;
use App\Services\GeminiDocumentReader;
use App\Services\StoreDocumentFindings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;

class FinalizeDocumentExtraction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param list<array{start: int, end: int}> $ranges */
    public function __construct(
        public int $documentScanId,
        public int $pageCount,
        public array $ranges,
    ) {}

    public function handle(GeminiDocumentReader $reader, StoreDocumentFindings $store): void
    {
        $scan = DocumentScan::query()->findOrFail($this->documentScanId);
        if ($scan->findings()->exists()) {
            return;
        }

        $store->store($scan, $reader->mergePdfBatches($scan, $this->pageCount, $this->ranges));
    }

    public function failed(?Throwable $exception): void
    {
        DocumentScan::query()->whereKey($this->documentScanId)
            ->whereDoesntHave('findings')
            ->update([
                'extraction_status' => 'failed',
                'extraction_error' => 'Detailed extraction batches finished, but their results could not be combined. '
                    .match (true) {
                        $exception instanceof ValidationException => 'The combined result failed validation. Check: '
                            .implode(', ', array_slice(array_keys($exception->errors()), 0, 5)).'.',
                        $exception instanceof UnexpectedValueException => $exception->getMessage(),
                        default => 'The combined result could not be stored. Please contact an administrator.',
                    },
            ]);
    }
}
