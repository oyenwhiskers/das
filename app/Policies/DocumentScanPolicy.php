<?php

namespace App\Policies;

use App\Models\DocumentScan;
use App\Models\User;

class DocumentScanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, DocumentScan $documentScan): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function delete(User $user, DocumentScan $documentScan): bool
    {
        return $user->isActive();
    }
}
