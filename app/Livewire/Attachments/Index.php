<?php

namespace App\Livewire\Attachments;

use App\Enums\AttachmentScanStatus;
use App\Jobs\ProcessAttachmentScan;
use App\Models\AttachmentScan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

#[Title('Store Attachment — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithPagination;

    /** @var list<TemporaryUploadedFile>|TemporaryUploadedFile|null */
    public $files = [];

    /** @var list<string> */
    public array $selected = [];

    public function toggleAll(): void
    {
        $this->authorize('viewAny', AttachmentScan::class);
        $ids = AttachmentScan::query()->orderBy('id')->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $this->selected = $ids !== [] && count(array_diff($ids, $this->selected)) === 0 ? [] : $ids;
    }

    public function deleteSelected(): void
    {
        $this->authorize('viewAny', AttachmentScan::class);
        $scans = AttachmentScan::query()->whereIn('id', $this->selected)->get();
        foreach ($scans as $scan) {
            $this->authorize('delete', $scan);
            Storage::disk('local')->delete($scan->storage_path);
            $scan->delete();
        }
        $this->selected = [];
        $this->resetPage();
        if ($scans->isNotEmpty()) {
            $this->dispatch('notify', type: 'success', message: $scans->count().($scans->count() === 1 ? ' attachment' : ' attachments').' deleted.');
        }
    }

    public function removeFile(int $index): void
    {
        $files = $this->fileList();
        unset($files[$index]);
        $this->files = array_values($files);
    }

    public function clearFiles(): void
    {
        $this->files = [];
        $this->resetValidation();
    }

    public function processDocuments(): void
    {
        $this->authorize('create', AttachmentScan::class);

        $maxFiles = (int) config('document_scanner.attachment_max_files');
        $this->files = $this->fileList();

        $this->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => [
                'required', 'file',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'extensions:pdf,jpg,jpeg,png',
                'max:'.config('document_scanner.max_upload_kb'),
            ],
        ]);

        $queued = 0;
        $queueFailed = 0;

        foreach ($this->files as $index => $document) {
            $originalFilename = basename(str_replace('\\', '/', $document->getClientOriginalName()));
            if ($originalFilename === '' || mb_strlen($originalFilename) > 255) {
                $this->addError('files.'.$index, 'The filename must be 255 characters or fewer.');

                continue;
            }

            $path = $document->store('attachment-scans', 'local');
            if (! is_string($path)) {
                $this->addError('files.'.$index, 'The attachment could not be stored.');

                continue;
            }

            try {
                $attachmentScan = AttachmentScan::query()->create([
                    'uploaded_by' => auth()->id(),
                    'original_filename' => $originalFilename,
                    'storage_path' => $path,
                    'mime_type' => $document->getMimeType(),
                    'file_size' => $document->getSize(),
                    'status' => AttachmentScanStatus::Pending,
                ]);
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($path);

                throw $exception;
            }

            try {
                ProcessAttachmentScan::dispatch($attachmentScan->id);
                $queued++;
            } catch (Throwable $exception) {
                $attachmentScan->update([
                    'status' => AttachmentScanStatus::Failed,
                    'processing_error' => 'Processing could not be queued. Please contact an administrator.',
                    'processing_completed_at' => now(),
                ]);
                report($exception);
                $queueFailed++;
            }
        }

        $this->files = [];
        $this->resetValidation();
        $this->resetPage();

        if ($queued === 0 && $queueFailed === 0) {
            $this->addError('files', 'No valid files were queued.');

            return;
        }

        $message = $queued === 1
            ? '1 attachment queued for processing.'
            : $queued.' attachments queued for processing.';
        if ($queueFailed > 0) {
            $message .= ' '.$queueFailed.' could not be queued.';
        }

        session()->flash('status', $message);
        $this->dispatch('notify', type: $queueFailed > 0 ? 'warning' : 'success', message: $message);
    }

    /** @return list<TemporaryUploadedFile> */
    private function fileList(): array
    {
        if ($this->files instanceof TemporaryUploadedFile) {
            return [$this->files];
        }

        if (! is_array($this->files)) {
            return [];
        }

        return array_values(array_filter(
            $this->files,
            fn (mixed $file): bool => $file instanceof TemporaryUploadedFile
        ));
    }

    public function render(): View
    {
        $this->authorize('viewAny', AttachmentScan::class);

        return view('livewire.attachments.index', [
            'scans' => AttachmentScan::query()->with(['uploader', 'customer'])->latest()->paginate(15),
            'pendingFiles' => $this->fileList(),
            'maxUploadMb' => config('document_scanner.max_upload_kb') / 1024,
            'maxFiles' => (int) config('document_scanner.attachment_max_files'),
        ])->layout('layouts.app');
    }
}
