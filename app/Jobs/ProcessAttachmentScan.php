<?php

namespace App\Jobs;

use App\Enums\AttachmentScanStatus;
use App\Models\AttachmentScan;
use App\Services\AttachmentFiling;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessAttachmentScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    // Keep unique lock longer than $timeout so a second worker cannot double-run the same scan.
    public int $uniqueFor = 420;

    public function __construct(public int $attachmentScanId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [random_int(15, 30), random_int(45, 60)];
    }

    public function uniqueId(): string
    {
        return (string) $this->attachmentScanId;
    }

    public function handle(AttachmentFiling $filing): void
    {
        $scan = AttachmentScan::query()->findOrFail($this->attachmentScanId);

        if (in_array($scan->status, [
            AttachmentScanStatus::Attached,
            AttachmentScanStatus::NeedsReview,
            AttachmentScanStatus::Failed,
        ], true)) {
            return;
        }

        $updated = AttachmentScan::query()->whereKey($scan->id)
            ->whereIn('status', [AttachmentScanStatus::Pending->value, AttachmentScanStatus::Processing->value])
            ->update([
                'status' => AttachmentScanStatus::Processing->value,
                'gemini_model' => config('services.gemini.model'),
                'processing_started_at' => $scan->processing_started_at ?? now(),
                'processing_error' => null,
            ]);
        if ($updated !== 1) {
            return;
        }

        try {
            $filing->process($scan->fresh());
        } catch (UnexpectedValueException) {
            $this->failScan($scan->id, 'Gemini returned an invalid attachment result.');
        } catch (RuntimeException $exception) {
            if (in_array($exception->getCode(), [408, 429], true) || $exception->getCode() >= 500) {
                throw $exception;
            }
            $this->failScan($scan->id, 'Attachment processing could not be completed. Please contact an administrator.');
        }
    }

    private function failScan(int $id, string $message): void
    {
        AttachmentScan::query()->whereKey($id)->update([
            'status' => AttachmentScanStatus::Failed->value,
            'processing_error' => $message,
            'processing_completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        AttachmentScan::query()->whereKey($this->attachmentScanId)
            ->whereNotIn('status', [
                AttachmentScanStatus::Attached->value,
                AttachmentScanStatus::NeedsReview->value,
            ])
            ->update([
                'status' => AttachmentScanStatus::Failed->value,
                'processing_error' => $exception instanceof ConnectionException
                    ? $exception->getMessage()
                    : 'Attachment processing could not be completed. Please contact an administrator.',
                'processing_completed_at' => now(),
            ]);
    }
}
