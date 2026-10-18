<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentScanStatus;
use App\Jobs\ProcessDocumentScan;
use App\Models\DocumentScan;
use App\Services\CustomerProfileSchema;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

#[Title('AI Document Reader — DAS')]
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
        $this->authorize('viewAny', DocumentScan::class);
        $ids = DocumentScan::query()->orderBy('id')->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $this->selected = $ids !== [] && count(array_diff($ids, $this->selected)) === 0 ? [] : $ids;
    }

    public function deleteSelected(): void
    {
        $this->authorize('viewAny', DocumentScan::class);
        $scans = DocumentScan::query()->whereIn('id', $this->selected)->get();
        foreach ($scans as $scan) {
            $this->authorize('delete', $scan);
            Storage::disk('local')->delete($scan->storage_path);
            Storage::disk('local')->deleteDirectory('document-scans/'.$scan->id);
            $scan->delete();
        }
        $this->selected = [];
        $this->resetPage();
        if ($scans->isNotEmpty()) {
            $this->dispatch('notify', type: 'success', message: $scans->count().($scans->count() === 1 ? ' document' : ' documents').' deleted.');
        }
    }

    public function processDocument(): void
    {
        $this->authorize('create', DocumentScan::class);

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

        $path = $this->document->store('document-scans', 'local');

        if (! is_string($path)) {
            $this->addError('document', 'The document could not be stored.');

            return;
        }

        try {
            $schema = CustomerProfileSchema::snapshot();
            $documentScan = DocumentScan::query()->create([
                'uploaded_by' => auth()->id(),
                'original_filename' => $originalFilename,
                'storage_path' => $path,
                'mime_type' => $this->document->getMimeType(),
                'file_size' => $this->document->getSize(),
                'status' => DocumentScanStatus::Pending,
                'profile_schema_hash' => $schema->hash,
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        try {
            ProcessDocumentScan::dispatch($documentScan->id);
        } catch (Throwable $exception) {
            $documentScan->update([
                'status' => DocumentScanStatus::Failed,
                'processing_error' => 'Processing could not be queued. Please contact an administrator.',
                'processing_completed_at' => now(),
            ]);

            report($exception);
            session()->flash('status', 'Document uploaded, but processing could not be queued.');
            $this->redirect(route('documents.show', $documentScan), navigate: true);

            return;
        }

        session()->flash('status', 'Document uploaded. Processing will begin shortly.');
        $this->redirect(route('documents.show', $documentScan), navigate: true);
    }

    public function render(): View
    {
        $this->authorize('viewAny', DocumentScan::class);

        return view('livewire.documents.index', [
            'documents' => DocumentScan::query()->with('uploader')->latest()->paginate(15),
            'maxUploadMb' => config('document_scanner.max_upload_kb') / 1024,
        ])->layout('layouts.app');
    }
}
