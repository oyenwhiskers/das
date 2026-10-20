<?php

namespace App\Livewire\Attachments;

use App\Enums\AttachmentScanStatus;
use App\Enums\CustomerStatus;
use App\Models\AttachmentScan;
use App\Models\Customer;
use App\Services\AttachmentFiling;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Attachment — DAS')]
class Show extends Component
{
    use AuthorizesRequests;

    public AttachmentScan $attachmentScan;

    public string $customerQuery = '';

    public string $matchCustomerId = '';

    public function mount(AttachmentScan $attachmentScan): void
    {
        $this->authorize('view', $attachmentScan);
        $this->attachmentScan = $attachmentScan;
    }

    public function selectMatchCustomer(int $customerId): void
    {
        $this->authorize('update', $this->attachmentScan);
        $customer = Customer::query()->where('status', CustomerStatus::Active)->find($customerId);
        if ($customer === null) {
            throw ValidationException::withMessages(['match' => 'Choose an active customer.']);
        }
        $this->matchCustomerId = (string) $customer->id;
    }

    public function confirmMatch(AttachmentFiling $filing): void
    {
        $this->authorize('update', $this->attachmentScan);
        $this->attachmentScan->refresh();

        if ($this->attachmentScan->status !== AttachmentScanStatus::NeedsReview) {
            throw ValidationException::withMessages(['match' => 'This attachment is not waiting for review.']);
        }

        $customer = Customer::query()->where('status', CustomerStatus::Active)->find($this->matchCustomerId);
        if ($customer === null) {
            throw ValidationException::withMessages(['match' => 'Choose an active customer.']);
        }

        $filing->attachToCustomer($this->attachmentScan, $customer, (int) auth()->id());
        $this->attachmentScan->refresh();
        $this->matchCustomerId = '';
        $this->customerQuery = '';
        $this->dispatch('notify', type: 'success', message: 'Attached to '.$customer->full_legal_name.'.');
    }

    public function render(): View
    {
        $this->authorize('view', $this->attachmentScan);
        $this->attachmentScan->loadMissing(['uploader', 'customer', 'matcher']);

        return view('livewire.attachments.show', [
            'matchCustomers' => $this->attachmentScan->status === AttachmentScanStatus::NeedsReview
                ? Customer::query()->where('status', CustomerStatus::Active)->search($this->customerQuery)->orderBy('full_legal_name')->limit(8)->get()
                : collect(),
        ])->layout('layouts.app');
    }
}
