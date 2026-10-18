<?php

namespace App\Livewire\Customers;

use App\Enums\CustomerActivityAction;
use App\Enums\CustomerStatus;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\Customer;
use App\Services\CustomerActivityLogger;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
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
    public string $sort = 'name_asc';

    /** @var list<int> */
    public array $selected = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function sortByName(): void
    {
        $this->sort = $this->sort === 'name_asc' ? 'name_desc' : 'name_asc';
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
        $this->selected = [];
    }

    public function toggle(int $customerId): void
    {
        $this->selected = in_array($customerId, $this->selected, true)
            ? array_values(array_filter($this->selected, fn (int $id): bool => $id !== $customerId))
            : [...$this->selected, $customerId];
    }

    /** @param  list<int>  $ids */
    public function togglePage(array $ids): void
    {
        $ids = array_map(intval(...), $ids);
        $allSelected = $ids !== [] && collect($ids)->every(fn (int $id): bool => in_array($id, $this->selected, true));
        $this->selected = $allSelected
            ? array_values(array_filter($this->selected, fn (int $id): bool => ! in_array($id, $ids, true)))
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function deleteSelected(): void
    {
        $customers = Customer::query()->whereIn('id', $this->selected)->get();
        foreach ($customers as $customer) {
            $this->authorize('delete', $customer);
        }

        DB::transaction(function () use ($customers): void {
            foreach ($customers as $customer) {
                $customer->deleteWithDependents();
            }
        });

        $count = $customers->count();
        $this->selected = [];
        $this->resetPage();

        if ($count > 0) {
            $this->notifySuccess($count === 1 ? 'Customer deleted.' : $count.' customers deleted.');
        }
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
            ->withProfileCompletion()
            ->search($this->search)
            ->when($this->status === 'active', fn ($query) => $query->where('status', CustomerStatus::Active))
            ->when($this->status === 'archived', fn ($query) => $query->where('status', CustomerStatus::Archived))
            ->when($this->sort === 'name_asc', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'name_desc', fn ($query) => $query->orderByDesc('full_legal_name'))
            ->when($this->sort === 'name', fn ($query) => $query->orderBy('full_legal_name'))
            ->when($this->sort === 'added', fn ($query) => $query->latest('created_at'))
            ->when($this->sort === 'updated' || $this->sort === '', fn ($query) => $query->latest('updated_at'))
            ->paginate(15);

        $pageIds = $customers->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return view('livewire.customers.index', [
            'customers' => $customers,
            'hasFilters' => $hasFilters,
            'pageIds' => $pageIds,
            'pageSelected' => $pageIds !== [] && collect($pageIds)->every(fn (int $id): bool => in_array($id, $this->selected, true)),
        ])->layout('layouts.app');
    }
}
