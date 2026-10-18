<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerBrokerageAccount;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileSchema;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Investment extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public const OBJECTIVES = CustomerProfileSchema::OBJECTIVES;

    public const PRODUCTS = CustomerProfileSchema::PRODUCTS;

    public Customer $customer;

    public bool $editingProfile = false;

    /** @var array<string, mixed> */
    public array $profile = [];

    public string $brokerageMode = '';

    public ?int $selectedBrokerageId = null;

    /** @var array<string, string> */
    public array $brokerage = [];

    public bool $confirmDuplicate = false;

    public bool $editingBrokerageDeclaration = false;

    public ?int $editingBrokerageDeclarationId = null;

    /** @var array<string, string> */
    public array $brokerageDeclaration = [];

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->resetProfile();
        $this->resetBrokerage();
        $this->resetBrokerageDeclaration();
    }

    public function editProfile(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetProfile();
        $this->editingProfile = true;
    }

    public function cancelProfile(): void
    {
        $this->editingProfile = false;
        $this->resetProfile();
        $this->resetValidation();
    }

    public function saveProfile(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveInvestmentProfile($this->customer, $this->profile, auth()->user());

        $this->cancelProfile();
        $this->dispatch('investment-updated');
        $this->notifySuccess('Investment profile saved.');
    }

    public function addBrokerage(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetBrokerage();
        $this->brokerageMode = 'edit';
    }

    public function editBrokerageDeclaration(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetBrokerageDeclaration();
        $this->editingBrokerageDeclaration = true;
    }

    public function saveBrokerageDeclaration(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveBrokerageDeclaration($this->customer, $this->brokerageDeclaration, auth()->user(), $this->editingBrokerageDeclarationId);

        $this->editingBrokerageDeclaration = false;
        $this->resetBrokerageDeclaration();
        $this->dispatch('investment-updated');
        $this->notifySuccess('Other brokerage declaration saved.');
    }

    public function cancelBrokerageDeclaration(): void
    {
        $this->editingBrokerageDeclaration = false;
        $this->resetBrokerageDeclaration();
        $this->resetValidation();
    }

    public function viewBrokerage(int $id): void
    {
        $this->authorize('view', $this->customer);
        $this->selectedBrokerageId = $this->brokerageAccount($id)->id;
        $this->brokerageMode = 'view';
    }

    public function editBrokerage(int $id): void
    {
        $this->authorize('update', $this->customer);
        $account = $this->brokerageAccount($id);
        $this->resetBrokerage();
        $this->selectedBrokerageId = $account->id;
        $this->brokerage = ['broker_name' => $account->broker_name, 'branch_address' => $account->branch_address ?? '', 'account_type' => $account->account_type];
        $this->brokerageMode = 'edit';
    }

    public function cancelBrokerage(): void
    {
        $this->resetBrokerage();
        $this->brokerageMode = '';
        $this->resetValidation();
    }

    #[On('discard-investment-edit')]
    public function discardEdits(): void
    {
        $this->cancelProfile();
        $this->cancelBrokerage();
        $this->cancelBrokerageDeclaration();
    }

    public function saveBrokerage(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveBrokerage($this->customer, $this->brokerage, auth()->user(), $this->selectedBrokerageId, $this->confirmDuplicate);

        $this->cancelBrokerage();
        $this->dispatch('investment-updated');
        $this->notifySuccess('Brokerage account saved.');
    }

    public function setBrokerageActive(int $id, bool $active, CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->setBrokerageActive($this->customer, $id, $active, auth()->user());

        $this->cancelBrokerage();
        $this->dispatch('investment-updated');
        $this->notifySuccess($active ? 'Brokerage account reactivated.' : 'Brokerage account deactivated.');
    }

    private function brokerageAccount(int $id): CustomerBrokerageAccount
    {
        return $this->customer->brokerageAccounts()->findOrFail($id);
    }

    private function resetProfile(): void
    {
        $current = $this->customer->latestInvestmentProfile()->first();
        $this->profile = ['objectives' => $current?->objectives ?? [], 'risk_tolerance' => $current?->risk_tolerance ?? '', 'trading_knowledge' => $current?->trading_knowledge ?? '', 'products' => $current?->products ?? [], 'other_product_details' => $current?->other_product_details ?? ''];
    }

    private function resetBrokerage(): void
    {
        $this->selectedBrokerageId = null;
        $this->confirmDuplicate = false;
        $this->brokerage = ['broker_name' => '', 'branch_address' => '', 'account_type' => 'trading'];
    }

    private function resetBrokerageDeclaration(): void
    {
        $current = $this->customer->brokerageDeclarations()->where('current_customer_key', $this->customer->id)->first();
        $this->editingBrokerageDeclarationId = $current?->id;
        $this->brokerageDeclaration = [
            'answer' => $current?->answer ?? 'not_declared',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_entry',
        ];
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.investment', [
            'currentProfile' => $this->customer->latestInvestmentProfile()->first(),
            'accounts' => $this->customer->brokerageAccounts()->orderByDesc('is_active')->latest()->get(),
            'selectedBrokerage' => $this->selectedBrokerageId ? $this->customer->brokerageAccounts()->find($this->selectedBrokerageId) : null,
            'currentBrokerageDeclaration' => $this->customer->brokerageDeclarations()->where('current_customer_key', $this->customer->id)->first(),
            'brokerageDeclarationHistory' => $this->customer->brokerageDeclarations()->whereNull('current_customer_key')->latest('id')->get(),
        ]);
    }
}
