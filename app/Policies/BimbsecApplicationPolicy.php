<?php

namespace App\Policies;

use App\Models\BimbsecApplication;
use App\Models\User;

class BimbsecApplicationPolicy
{
    public function view(User $user, BimbsecApplication $application): bool
    {
        return $user->isActive() && $user->can('view', $application->customer);
    }

    public function update(User $user, BimbsecApplication $application): bool
    {
        return $this->view($user, $application);
    }
}
