<?php

namespace App\Policies;

use App\Models\BimbsecBatch;
use App\Models\User;

class BimbsecBatchPolicy
{
    public function view(User $user, BimbsecBatch $batch): bool
    {
        return $user->isActive() && $batch->user_id === $user->id;
    }
}
