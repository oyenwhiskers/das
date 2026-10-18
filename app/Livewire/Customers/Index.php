<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerActivityAction;
use App\Enums\CustomerStatus;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Services\CustomerActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Customers — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $sort = 'updated';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function restore(int $customerId, CustomerActivityLogger $logger): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('update', $customer);

        if (! $customer->isArchived()) {
            return;
        }

        $customer->update([
            'status' => CustomerStatus::Active,
            'updated_by' => auth()->id(),
        ]);

        $logger->log($customer, CustomerActivityAction::Restored, auth()->user());

        $this->notifySuccess('Customer restored.');
    }

    public function render(): View
    {
        $this->authorize('viewAny', Customer::class);

        $hasFilters = filled($this->search) || $this->status !== 'all';

        $customers = Customer::query()
            ->search($this->search)
            ->when($this->status === 'active', fn ($query) => $query->where('status', CustomerStatus::Active))
            ->when($this->status === 'archived', fn ($query) => $query->where('status', CustomerStatus::Archived))
            ->when($this->sort === 'name_asc', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'name_desc', fn ($query) => $query->orderByDesc('full_legal_name'))
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'added', fn ($query) => $query->latest('created_at'))
            ->when($this->sort === 'updated' || $this->sort === '', fn ($query) => $query->latest('updated_at'))
            ->paginate(15);

        return view('livewire.customers.index', [
            'customers' => $customers,
            'hasFilters' => $hasFilters,
        ])->layout('layouts.app');
    }
}
