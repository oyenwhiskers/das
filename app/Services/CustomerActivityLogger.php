<?php

namespace App\Services;

use App\Enums\CustomerActivityAction;
use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\User;

class CustomerActivityLogger
{
    public function log(Customer $customer, CustomerActivityAction $action, ?User $user = null, ?string $description = null): CustomerActivity
    {
        return CustomerActivity::query()->create([
            'customer_id' => $customer->id,
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description ?? $action->label(),
            'created_at' => now(),
        ]);
    }
}
