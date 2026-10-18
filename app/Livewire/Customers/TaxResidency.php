<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerTaxResidency;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class TaxResidency extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $editing = '';

    public ?int $selectedResidencyId = null;

    public ?int $editingFatcaId = null;

    public string $malaysianTin = '';

    /** @var array<string, string> */
    public array $residency = [];

    /** @var array<string, string> */
    public array $fatca = [];

    public string $endDate = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->resetForms();
    }

    public function editTin(): void
    {
        $this->authorize('update', $this->customer);
        $this->cancel();
        $this->editing = 'tin';
    }

    public function saveTin(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveTin($this->customer, $this->malaysianTin, auth()->user());

        $this->cancel();
        $this->dispatch('tax-updated');
        $this->notifySuccess('Malaysian TIN saved.');
    }

    public function addResidency(): void
    {
        $this->authorize('update', $this->customer);
        $this->cancel();
        $this->editing = 'residency';
    }

    public function editResidency(int $id): void
    {
        $this->authorize('update', $this->customer);
        $record = $this->currentResidency($id);
        $this->cancel();
        $this->selectedResidencyId = $id;
        $this->residency = [
            'jurisdiction' => $record->jurisdiction,
            'foreign_tin' => '',
            'missing_tin_reason' => $record->missing_tin_reason ?? '',
            'missing_tin_explanation' => $record->missing_tin_explanation ?? '',
            'effective_date' => $record->effective_date->toDateString(),
        ];
        $this->editing = 'residency';
    }

    public function saveResidency(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveResidency($this->customer, $this->residency, auth()->user(), $this->selectedResidencyId);
        $this->cancel();
        $this->dispatch('tax-updated');
        $this->notifySuccess('Tax residency saved.');
    }

    public function startEndResidency(int $id): void
    {
        $this->authorize('update', $this->customer);
        $this->currentResidency($id);
        $this->cancel();
        $this->selectedResidencyId = $id;
        $this->editing = 'end';
    }

    public function endResidency(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->endResidency($this->customer, (int) $this->selectedResidencyId, $this->endDate, auth()->user());
        $this->cancel();
        $this->dispatch('tax-updated');
        $this->notifySuccess('Tax residency ended.');
    }

    public function editFatca(): void
    {
        $this->authorize('update', $this->customer);
        $this->cancel();
        $current = $this->customer->fatcaDeclarations()->where('current_customer_key', $this->customer->id)->first();
        if ($current) {
            $this->editingFatcaId = $current->id;
            foreach (['us_person_for_tax', 'us_green_card_holder', 'us_resident'] as $field) {
                $this->fatca[$field] = $current->{$field} === null ? '' : ($current->{$field} ? 'yes' : 'no');
            }
            $this->fatca['declared_on'] = $current->declared_on->toDateString();
            $this->fatca['source'] = $current->source;
            $this->fatca['selected_classification'] = $current->selected_classification ?? '';
        }
        $this->editing = 'fatca';
    }

    public function saveFatca(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveFatca($this->customer, $this->fatca, auth()->user(), $this->editingFatcaId);
        $this->cancel();
        $this->dispatch('tax-updated');
        $this->notifySuccess('FATCA declaration saved.');
    }

    #[On('discard-tax-residency-edit')]
    public function cancel(): void
    {
        $this->editing = '';
        $this->selectedResidencyId = null;
        $this->editingFatcaId = null;
        $this->resetForms();
        $this->resetValidation();
    }

    private function resetForms(): void
    {
        $this->malaysianTin = '';
        $this->residency = ['jurisdiction' => '', 'foreign_tin' => '', 'missing_tin_reason' => '', 'missing_tin_explanation' => '', 'effective_date' => today()->toDateString()];
        $this->fatca = ['us_person_for_tax' => '', 'us_green_card_holder' => '', 'us_resident' => '', 'selected_classification' => '', 'declared_on' => today()->toDateString(), 'source' => 'staff_entry'];
        $this->endDate = today()->toDateString();
    }

    private function currentResidency(int $id): CustomerTaxResidency
    {
        return $this->customer->taxResidencies()->whereNotNull('current_jurisdiction_key')->findOrFail($id);
    }

    private function answer(string $answer): ?bool
    {
        return $answer === '' ? null : $answer === 'yes';
    }

    public function render(): View
    {
        return view('livewire.customers.tax-residency', [
            'taxIdentification' => $this->customer->taxIdentification()->first(),
            'residencies' => $this->customer->taxResidencies()->orderByRaw('ended_on IS NOT NULL')->latest('effective_date')->get(),
            'currentFatca' => $this->customer->fatcaDeclarations()->with('recorder')->where('current_customer_key', $this->customer->id)->first(),
        ]);
    }
}
