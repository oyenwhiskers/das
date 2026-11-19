<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerType;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Services\CustomerProfileSchema;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class CorporateAum extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public const AUM_BRACKETS = CustomerProfileSchema::AUM_BRACKETS;

    public Customer $customer;

    public bool $editing = false;

    public string $aum_bracket = '';

    public function mount(Customer $customer): void
    {
        abort_unless($customer->type === CustomerType::Corporate, 404);
        $this->authorize('view', $customer);
        $this->customer = $customer->load('corporateDetails');
        $this->aum_bracket = $customer->corporateDetails?->aum_bracket ?? '';
    }

    public function edit(): void
    {
        $this->authorize('update', $this->customer);
        $this->aum_bracket = $this->customer->corporateDetails?->aum_bracket ?? '';
        $this->editing = true;
        $this->resetValidation();
    }

    #[On('discard-financial-edit')]
    public function cancel(): void
    {
        $this->editing = false;
        $this->aum_bracket = $this->customer->corporateDetails?->aum_bracket ?? '';
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveCorporateAum($this->customer, [
            'aum_bracket' => $this->aum_bracket,
        ], auth()->user());
        $this->customer = $this->customer->fresh('corporateDetails');
        $this->editing = false;
        $this->dispatch('financial-updated');
        $this->notifySuccess('AUM bracket saved.');
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.corporate-aum');
    }
}
