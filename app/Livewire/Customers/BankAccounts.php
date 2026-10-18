<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class BankAccounts extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public string $mode = '';

    public ?int $selectedId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public bool $confirmNumberChange = false;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
        $this->resetForm();
    }

    public function add(): void
    {
        $this->authorize('update', $this->customer);
        $this->resetForm();
        $this->mode = 'edit';
    }

    public function view(int $id): void
    {
        $this->authorize('view', $this->customer);
        $this->account($id);
        $this->selectedId = $id;
        $this->mode = 'view';
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->customer);
        $account = $this->account($id);
        $this->resetForm();
        $this->selectedId = $id;
        foreach (['bank_name', 'bank_country', 'branch_address', 'account_type', 'account_holder_name', 'ownership', 'is_primary'] as $field) {
            $this->form[$field] = $account->{$field} ?? '';
        }
        $this->mode = 'edit';
    }

    #[On('discard-bank-account-edit')]
    public function cancel(): void
    {
        $this->resetForm();
        $this->mode = '';
        $this->resetValidation();
    }

    public function save(CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->saveBankAccount($this->customer, $this->form, auth()->user(), $this->selectedId, $this->confirmNumberChange);
        $this->cancel();
        $this->dispatch('bank-account-updated');
        $this->notifySuccess('Bank account saved.');
    }

    public function setActive(int $id, bool $active, CustomerActivityLogger $logger): void
    {
        $this->authorize('update', $this->customer);
        app(CustomerProfileWrites::class)->setBankActive($this->customer, $id, $active, auth()->user());
        $this->cancel();
        $this->dispatch('bank-account-updated');
        $this->notifySuccess($active ? 'Bank account reactivated.' : 'Bank account deactivated.');
    }

    private function account(int $id): CustomerBankAccount
    {
        return $this->customer->bankAccounts()->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->selectedId = null;
        $this->confirmNumberChange = false;
        $this->form = [
            'bank_name' => '', 'bank_country' => 'Malaysia', 'branch_address' => '',
            'account_type' => 'savings', 'account_number' => '', 'account_holder_name' => '',
            'ownership' => 'sole', 'is_primary' => false,
        ];
    }

    public function render(): View
    {
        $this->authorize('view', $this->customer);

        return view('livewire.customers.bank-accounts', [
            'accounts' => $this->customer->bankAccounts()->orderByDesc('is_active')->orderByDesc('is_primary')->latest()->get(),
            'selected' => $this->selectedId ? $this->customer->bankAccounts()->find($this->selectedId) : null,
        ]);
    }
}
