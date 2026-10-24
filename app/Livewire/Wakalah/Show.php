<?php

namespace App\Livewire\Wakalah;

use App\Models\Customer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Wakalah Letter — DAS')]
class Show extends Component
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
        return view('livewire.wakalah.show', [
            'customer' => $this->customer,
        ])->layout('layouts.app');
    }
}
