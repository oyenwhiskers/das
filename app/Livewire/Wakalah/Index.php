<?php

namespace App\Livewire\Wakalah;

use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Wakalah Letter — DAS')]
class Index extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $this->authorize('view', $customer);
        $this->redirect(route('wakalah.show', $customer));
    }

    public function render(): View
    {
        $this->authorize('viewAny', Customer::class);
        $customers = Customer::query()
            ->search($this->search)
            ->orderBy('full_legal_name')
            ->paginate(50);

        return view('livewire.wakalah.index', [
            'customers' => $customers,
            'hasFilters' => filled($this->search),
        ])->layout('layouts.app');
    }
}
