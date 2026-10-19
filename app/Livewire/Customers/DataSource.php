<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Component;

class DataSource extends Component
{
    use AuthorizesRequests;

    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);
        $this->customer = $customer;
    }

    public function render(): View
    {
        return view('livewire.customers.data-source', [
            'merges' => $this->customer->profileMerges()
                ->with(['extractedProfile.documentScan', 'merger'])
                ->latest('created_at')
                ->get(),
        ]);
    }
}
