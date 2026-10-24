<?php

namespace App\Livewire\Wakalah;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Wakalah Letter — DAS')]
class Show extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $designation = '';

    public string $witnessName = '';

    public string $witnessNric = '';

    public string $witnessDate = '';

    public int $previewVersion = 0;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->fillFromCustomer();
    }

    public function save(): void
    {
        $this->authorize('update', $this->customer);

        $data = $this->validate([
            'designation' => ['nullable', 'string', 'max:120'],
            'witnessName' => ['nullable', 'string', 'max:200'],
            'witnessNric' => ['nullable', 'string', 'max:40'],
            'witnessDate' => ['nullable', 'date'],
        ]);

        $this->customer->update([
            'wakalah_designation' => filled($data['designation']) ? trim($data['designation']) : null,
            'wakalah_witness_name' => filled($data['witnessName']) ? trim($data['witnessName']) : null,
            'wakalah_witness_nric' => filled($data['witnessNric']) ? trim($data['witnessNric']) : null,
            'wakalah_witness_date' => filled($data['witnessDate']) ? $data['witnessDate'] : null,
            'updated_by' => auth()->id(),
        ]);
        $this->customer->refresh();
        $this->fillFromCustomer();
        $this->previewVersion++;
        $this->notifySuccess('Wakalah letter details saved.');
    }

    public function render(): View
    {
        return view('livewire.wakalah.show', [
            'customer' => $this->customer,
        ])->layout('layouts.app');
    }

    private function fillFromCustomer(): void
    {
        $this->designation = (string) ($this->customer->wakalah_designation ?? '');
        $this->witnessName = (string) ($this->customer->wakalah_witness_name ?? '');
        $this->witnessNric = (string) ($this->customer->wakalah_witness_nric ?? '');
        $this->witnessDate = $this->customer->wakalah_witness_date?->format('Y-m-d') ?? '';
    }
}
