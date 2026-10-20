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
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

#[Title('Scan Attachment — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;
    use WithPagination;

    public $document;

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

    public function processDocument(): void
    {
        $this->authorize('create', AttachmentScan::class);

        $this->validate([
            'document' => [
                'required', 'file',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'extensions:pdf,jpg,jpeg,png',
                'max:'.config('document_scanner.max_upload_kb'),
            ],
        ]);

        $originalFilename = basename(str_replace('\\', '/', $this->document->getClientOriginalName()));

        if ($originalFilename === '' || mb_strlen($originalFilename) > 255) {
            $this->addError('document', 'The filename must be 255 characters or fewer.');

            return;
        }

        $path = $this->document->store('attachment-scans', 'local');

        if (! is_string($path)) {
            $this->addError('document', 'The attachment could not be stored.');

            return;
        }

        try {
            $attachmentScan = AttachmentScan::query()->create([
                'uploaded_by' => auth()->id(),
                'original_filename' => $originalFilename,
                'storage_path' => $path,
                'mime_type' => $this->document->getMimeType(),
                'file_size' => $this->document->getSize(),
                'status' => AttachmentScanStatus::Pending,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        try {
            ProcessAttachmentScan::dispatch($attachmentScan->id);
        } catch (Throwable $exception) {
            $attachmentScan->update([
                'status' => AttachmentScanStatus::Failed,
                'processing_error' => 'Processing could not be queued. Please contact an administrator.',
                'processing_completed_at' => now(),
            ]);

            report($exception);
            session()->flash('status', 'Attachment uploaded, but processing could not be queued.');
            $this->redirect(route('attachments.show', $attachmentScan), navigate: true);

            return;
        }

        session()->flash('status', 'Attachment uploaded. Processing will begin shortly.');
        $this->redirect(route('attachments.show', $attachmentScan), navigate: true);
    }

    public function render(): View
    {
        $this->authorize('viewAny', AttachmentScan::class);

        return view('livewire.attachments.index', [
            'documents' => AttachmentScan::query()->with(['uploader', 'customer'])->latest()->paginate(15),
            'maxUploadMb' => config('document_scanner.max_upload_kb') / 1024,
        ])->layout('layouts.app');
    }
}
