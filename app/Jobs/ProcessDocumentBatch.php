<?php

namespace App\Jobs;

use App\Enums\DocumentScanStatus;
use App\Models\DocumentScan;
use App\Services\GeminiDocumentReader;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Psr\Http\Client\NetworkExceptionInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessDocumentBatch implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $documentScanId,
        public int $pageCount,
        public int $startPage,
        public int $endPage,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 90];
    }

    public function handle(GeminiDocumentReader $reader): void
    {
        $scan = DocumentScan::query()->findOrFail($this->documentScanId);
        if ($scan->findings()->exists() || $reader->hasValidPdfBatch(
            $scan,
            $this->pageCount,
            $this->startPage,
            $this->endPage
        )) {
            return;
        }
        if ($scan->status !== DocumentScanStatus::Completed || ! $scan->readable) {
            throw new RuntimeException('The document is not ready for detailed extraction.');
        }

        $marker = 'document-scans/'.$scan->id.'/extraction-batches/'.$this->startPage;
        Storage::disk('local')->delete($marker.'.failed');
        Storage::disk('local')->put($marker.'.running', (string) time());
        try {
            $reader->extractPdfBatch($scan, $this->pageCount, $this->startPage, $this->endPage);
        } catch (Throwable $exception) {
            Log::warning('Document page batch extraction failed.', [
                'document_scan_id' => $scan->id,
                'start_page' => $this->startPage,
                'end_page' => $this->endPage,
                'attempt' => $this->job?->attempts() ?? 1,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if ($this->job !== null && ($exception instanceof ValidationException
                || $exception instanceof UnexpectedValueException
                || ($exception instanceof RuntimeException && ! in_array($exception->getCode(), [408, 429], true) && $exception->getCode() < 500))) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        } finally {
            Storage::disk('local')->delete($marker.'.running');
        }
    }

    public function failed(?Throwable $exception): void
    {
        DocumentScan::query()->whereKey($this->documentScanId)
            ->whereDoesntHave('findings')
            ->update([
                'extraction_status' => 'failed',
                'extraction_error' => 'Detailed extraction failed for pages '
                    .$this->startPage.'-'.$this->endPage.'. '.$this->failureReason($exception),
            ]);
        Storage::disk('local')->put(
            'document-scans/'.$this->documentScanId.'/extraction-batches/'.$this->startPage.'.failed',
            ''
        );
    }

    private function failureReason(?Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'The AI result failed validation. Check: '
                .implode(', ', array_slice(array_keys($exception->errors()), 0, 5)).'.',
            $exception instanceof UnexpectedValueException && str_starts_with($exception->getMessage(), 'Visual rereading found omitted form content:') => 'Visual rereading found omitted form content.',
            $exception instanceof UnexpectedValueException => 'The AI returned an incomplete or invalid result. '
                .$exception->getMessage(),
            $exception instanceof ConnectionException,
            $exception instanceof NetworkExceptionInterface => 'The connection to the AI service failed after three attempts.',
            $exception instanceof RuntimeException && ($exception->getCode() === 429 || $exception->getCode() >= 500) => 'The AI service remained temporarily unavailable after three attempts (HTTP '.$exception->getCode().').',
            $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Gemini could not process the document.') => 'The AI request failed (HTTP '.$exception->getCode().'). '.$exception->getMessage(),
            $exception instanceof RuntimeException && in_array($exception->getMessage(), ['The PDF page batch is unavailable.', 'A validated extraction batch could not be stored.', 'The document is not ready for detailed extraction.'], true) => $exception->getMessage(),
            default => 'The page group could not be processed after three attempts.',
        };
    }
}
