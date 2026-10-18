<?php

namespace App\Jobs;

use App\Enums\DocumentScanStatus;
use App\Models\DocumentScan;
use App\Services\GeminiDocumentReader;
use App\Services\StoreDocumentFindings;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessDocumentExtraction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3660;

    public function __construct(public int $documentScanId) {}

    public function uniqueId(): string
    {
        return (string) $this->documentScanId;
    }

    public function handle(GeminiDocumentReader $reader, StoreDocumentFindings $store): void
    {
        $scan = DocumentScan::query()->findOrFail($this->documentScanId);

        if ($scan->findings()->exists()) {
            return;
        }

        if ($scan->status !== DocumentScanStatus::Completed || ! $scan->readable) {
            $scan->update([
                'extraction_status' => 'failed',
                'extraction_error' => 'Detailed extraction could not run because the document was not completed as readable.',
            ]);

            return;
        }

        $scan->update([
            'extraction_status' => 'processing',
            'extraction_error' => null,
            'gemini_model' => config('services.gemini.model'),
        ]);

        try {
            if ($scan->mime_type === 'application/pdf') {
                $prepared = $reader->preparePdfBatches($scan);
                if ($prepared['count'] > 1) {
                    $jobs = [];
                    foreach ($prepared['ranges'] as $range) {
                        if (! $reader->hasValidPdfBatch($scan, $prepared['count'], $range['start'], $range['end'])) {
                            $jobs[] = new ProcessDocumentBatch(
                                $scan->id,
                                $prepared['count'],
                                $range['start'],
                                $range['end']
                            );
                        }
                    }

                    if ($jobs === []) {
                        FinalizeDocumentExtraction::dispatch($scan->id, $prepared['count'], $prepared['ranges']);
                    } else {
                        $scanId = $scan->id;
                        $pageCount = $prepared['count'];
                        $ranges = $prepared['ranges'];
                        Bus::batch($jobs)
                            ->name('document-extraction-'.$scanId)
                            ->then(static function (Batch $batch) use ($scanId, $pageCount, $ranges): void {
                                FinalizeDocumentExtraction::dispatch($scanId, $pageCount, $ranges);
                            })
                            ->dispatch();
                    }

                    return;
                }
            }

            $payload = $reader->extract($scan);

            $this->ensureCompleteReading($payload);

            $store->store($scan, $payload);
        } catch (LogicException $exception) {
            if (! $scan->findings()->exists()) {
                throw $exception;
            }
        } catch (ValidationException|UnexpectedValueException|ConnectionException|RuntimeException $exception) {
            if ($this->job !== null) {
                $this->fail($exception);

                return;
            }

            $scan->update([
                'extraction_status' => 'failed',
                'extraction_error' => $this->failedExtractionMessage($exception),
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DocumentScan::query()->whereKey($this->documentScanId)
            ->whereDoesntHave('findings')
            ->update([
                'extraction_status' => 'failed',
                'extraction_error' => $this->failedExtractionMessage($exception),
            ]);
    }

    /** @param array<string, mixed> $payload */
    private function ensureCompleteReading(array $payload): void
    {
        $documentStatus = is_string($payload['document']['reading_status'] ?? null)
            ? $payload['document']['reading_status']
            : 'missing';
        $pageCount = is_int($payload['document']['page_count'] ?? null)
            ? $payload['document']['page_count']
            : null;
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : null;

        if ($documentStatus === 'complete'
            && is_int($pageCount)
            && is_array($pages)
            && count($pages) === $pageCount
            && $pageCount > 0
            && ! collect($pages)->contains(fn (mixed $page): bool => ! is_array($page) || ($page['reading_status'] ?? null) !== 'complete')) {
            return;
        }

        $pageSummary = collect($pages ?? [])
            ->map(function (mixed $page, int $index): string {
                if (! is_array($page)) {
                    return 'page '.($index + 1).': invalid';
                }

                $number = is_int($page['number'] ?? null) ? $page['number'] : $index + 1;
                $status = is_string($page['reading_status'] ?? null) ? $page['reading_status'] : 'missing';

                return "page {$number}: {$status}";
            })
            ->implode(', ');

        $countSummary = is_int($pageCount)
            ? ' Expected '.$pageCount.' page record(s), received '.count($pages ?? []).'.'
            : ' Document page_count was missing.';

        throw new UnexpectedValueException(
            'Gemini did not read every document page. Document reading status: '.$documentStatus.'.'
            .$countSummary
            .($pageSummary !== '' ? ' Pages: '.$pageSummary.'.' : ' No page records were returned.')
        );
    }

    private function isUnavailableRuntimeException(RuntimeException $exception): bool
    {
        return in_array($exception->getCode(), [408, 429], true) || $exception->getCode() >= 500;
    }

    private function failedExtractionMessage(?Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $errors = $exception->errors();
            $issueCount = count($errors);

            Log::warning('Document extraction findings failed validation.', [
                'document_scan_id' => $this->documentScanId,
                'issue_count' => $issueCount,
                'error_attributes' => array_slice(array_keys($errors), 0, 50),
                'error_messages' => array_slice(
                    collect($errors)
                        ->flatMap(fn (array $messages, string $attribute): array => array_map(
                            fn (string $message): string => $attribute.': '.$message,
                            $messages
                        ))
                        ->unique()
                        ->values()
                        ->all(),
                    0,
                    50
                ),
            ]);

            return 'Detailed extraction could not be completed because the AI result failed validation'
                .($issueCount > 0 ? ' ('.$issueCount.' issue'.($issueCount === 1 ? '' : 's').')' : '')
                .'. Check: '.implode(', ', array_slice(array_keys($errors), 0, 5)).'.';
        }

        if ($exception instanceof UnexpectedValueException) {
            return "Detailed extraction could not be completed because the AI returned an incomplete or invalid result.\n\n"
                .$exception->getMessage();
        }

        if ($exception instanceof ConnectionException) {
            return 'Detailed extraction could not be completed because the connection to the AI service failed. Please retry.';
        }

        if ($exception instanceof RuntimeException && $this->isUnavailableRuntimeException($exception)) {
            $status = $exception->getCode() > 0 ? ' (HTTP '.$exception->getCode().')' : '';
            $message = 'Detailed extraction could not be completed because the AI service was temporarily unavailable'.$status.'. Please retry.';

            return $exception->getMessage() !== ''
                ? $message."\n\n".$exception->getMessage()
                : $message;
        }

        if ($exception instanceof RuntimeException && is_string($exception->getMessage()) && $exception->getMessage() !== '') {
            return "Detailed extraction could not be completed.\n\n".$exception->getMessage();
        }

        return 'Detailed extraction could not be completed. Please try again or contact an administrator.';
    }
}
