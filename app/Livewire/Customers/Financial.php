<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileSchema;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Financial extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public const PERSONAL_INCOME = CustomerProfileSchema::PERSONAL_INCOME;

    public const JOINT_INCOME = CustomerProfileSchema::JOINT_INCOME;

    public const NET_ASSETS = CustomerProfileSchema::NET_ASSETS;

    public const PORTFOLIO = CustomerProfileSchema::PORTFOLIO;

    public const WEALTH_SOURCES = CustomerProfileSchema::WEALTH_SOURCES;

    public Customer $customer;

    public bool $editing = false;

    public ?int $editingDeclarationId = null;

    /** @var array<string, mixed> */
    public array $financial = [];

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->resetForm();
    }

    public function edit(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetForm();
        $this->editing = true;
    }

    public function updatedFinancialMonthlyIncomeRm(mixed $monthlyIncome): void
    {
        $this->financial['personal_annual_income_bracket'] = CustomerProfileSchema::personalAnnualBracketFromMonthly($monthlyIncome) ?? '';
    }

    public function save(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveFinancial($this->customer, $this->financial, auth()->user(), $this->editingDeclarationId);

        $this->cancel();
        $this->dispatch('financial-updated');
        $this->notifySuccess('Financial declaration saved.');
    }

    #[On('discard-financial-edit')]
    public function cancel(): void
    {
        $this->editing = false;
        $this->resetForm();
        $this->resetValidation();
    }

    private function resetForm(): void
    {
        $current = $this->customer->financialDeclarations()->where('current_customer_key', $this->customer->id)->first();
        $this->editingDeclarationId = $current?->id;
        $this->financial = [
            'monthly_income_rm' => $current?->monthly_income_rm ?? '',
            'personal_annual_income_bracket' => $current?->personal_annual_income_bracket ?? '',
            'joint_annual_income_bracket' => $current?->joint_annual_income_bracket ?? '',
            'net_asset_bracket' => $current?->net_asset_bracket ?? '',
            'portfolio_bracket' => $current?->portfolio_bracket ?? '',
            'portfolio_ownership' => $current?->portfolio_ownership ?? '',
            'wealth_sources' => $current?->wealth_sources ?? [],
            'other_wealth_source' => $current?->other_wealth_source ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_entry',
        ];
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.financial', [
            'current' => $this->customer->financialDeclarations()->where('current_customer_key', $this->customer->id)->first(),
            'history' => $this->customer->financialDeclarations()->whereNull('current_customer_key')->latest('id')->get(),
        ]);
    }
}
