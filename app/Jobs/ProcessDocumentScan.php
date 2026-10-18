<?php

namespace App\Jobs;

use App\Enums\DocumentScanStatus;
use App\Models\DocumentScan;
use App\Services\GeminiDocumentReader;
use App\Services\SinglePassExtraction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessDocumentScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public int $uniqueFor = 3660;

    public function __construct(public int $documentScanId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [random_int(30, 45), random_int(60, 90)];
    }

    public function uniqueId(): string
    {
        return (string) $this->documentScanId;
    }

    public function handle(GeminiDocumentReader $reader): void
    {
        $documentScan = DocumentScan::query()->findOrFail($this->documentScanId);

        if (in_array($documentScan->status, [DocumentScanStatus::Completed, DocumentScanStatus::Failed], true)) {
            return;
        }

        $run = (string) Str::uuid();
        $updated = DocumentScan::query()->whereKey($documentScan->id)
            ->whereIn('status', [DocumentScanStatus::Pending->value, DocumentScanStatus::Processing->value])
            ->update([
                'status' => DocumentScanStatus::Processing->value,
                'gemini_model' => config('services.gemini.model'),
                'processing_started_at' => $documentScan->processing_started_at ?? now(),
                'processing_error' => null,
            ]);
        if ($updated !== 1) {
            return;
        }
        Cache::put(DocumentScan::activeRunKey($documentScan->id), $run, now()->addHours(2));

        try {
            app(SinglePassExtraction::class)->run($documentScan->fresh(), $reader, $run);
        } catch (UnexpectedValueException) {
            $this->failRun($documentScan->id, $run, 'Gemini returned an invalid document result.');
        } catch (RuntimeException $exception) {
            if (Cache::get(DocumentScan::activeRunKey($documentScan->id)) !== $run) {
                return;
            }
            if (in_array($exception->getCode(), [408, 429], true) || $exception->getCode() >= 500) {
                throw $exception;
            }

            $this->failRun($documentScan->id, $run, 'Document processing could not be completed. Please contact an administrator.');
        }
    }

    private function failRun(int $id, string $run, string $message): void
    {
        if (Cache::get(DocumentScan::activeRunKey($id)) !== $run) {
            return;
        }
        DocumentScan::query()->whereKey($id)->update([
            'status' => DocumentScanStatus::Failed->value,
            'processing_error' => $message,
            'processing_completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DocumentScan::query()->whereKey($this->documentScanId)
            ->where('status', '!=', DocumentScanStatus::Completed->value)
            ->where(fn ($query) => $query->whereNull('processing_error')->orWhere('processing_error', '!=', 'Scan cancelled.'))
            ->update([
                'status' => DocumentScanStatus::Failed->value,
                'processing_error' => $exception instanceof ConnectionException
                    ? $exception->getMessage()
                    : 'Document processing could not be completed. Please contact an administrator.',
                'processing_completed_at' => now(),
            ]);
    }
}
