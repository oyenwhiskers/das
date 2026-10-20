<?php

namespace App\Livewire\Customers;

use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentType;
use App\Models\AttachmentScan;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class Attachments extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Customer $customer;

    /** @var array<string, mixed> */
    public array $uploads = [];

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
    }

    public function updatedUploads(mixed $value, string $key): void
    {
        if ($value === null) {
            return;
        }

        $this->storeUpload($key);
    }

    public function storeUpload(string $typeValue): void
    {
        $this->authorize('create', AttachmentScan::class);
        $this->authorize('view', $this->customer);

        $type = AttachmentType::tryFrom($typeValue);
        if ($type === null) {
            $this->addError('uploads.'.$typeValue, 'Unknown attachment type.');

            return;
        }

        $field = 'uploads.'.$typeValue;
        $this->validate([
            $field => [
                'required', 'file',
                'mimetypes:application/pdf,image/jpeg,image/png',
                'extensions:pdf,jpg,jpeg,png',
                'max:'.config('document_scanner.max_upload_kb'),
            ],
        ]);

        $file = $this->uploads[$typeValue] ?? null;
        if ($file === null) {
            $this->addError($field, 'Choose a file to upload.');

            return;
        }

        $originalFilename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if ($originalFilename === '' || mb_strlen($originalFilename) > 255) {
            $this->addError($field, 'The filename must be 255 characters or fewer.');

            return;
        }

        $path = $file->store('attachment-scans', 'local');
        if (! is_string($path)) {
            $this->addError($field, 'The attachment could not be stored.');

            return;
        }

        try {
            AttachmentScan::query()->create([
                'uploaded_by' => auth()->id(),
                'original_filename' => $originalFilename,
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => AttachmentScanStatus::Attached,
                'attachment_type' => $type,
                'customer_id' => $this->customer->id,
                'matched_by' => auth()->id(),
                'attached_at' => now(),
                'processing_started_at' => now(),
                'processing_completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }

        unset($this->uploads[$typeValue]);
        $this->resetValidation($field);
        $this->dispatch('notify', type: 'success', message: $type->label().' uploaded for '.$this->customer->full_legal_name.'.');
    }

    public function render(): View
    {
        $scans = $this->customer->attachmentScans()
            ->with('uploader')
            ->latest('attached_at')
            ->latest('id')
            ->get();

        /** @var Collection<string, Collection<int, AttachmentScan>> $grouped */
        $grouped = $scans->groupBy(fn ($scan) => $scan->attachment_type?->value ?? 'unknown');

        return view('livewire.customers.attachments', [
            'types' => AttachmentType::cases(),
            'grouped' => $grouped,
            'maxUploadMb' => config('document_scanner.max_upload_kb') / 1024,
        ]);
    }
}
