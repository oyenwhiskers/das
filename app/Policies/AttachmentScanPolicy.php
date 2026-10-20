<?php

namespace App\Policies;

use App\Models\AttachmentScan;
use App\Models\User;

class AttachmentScanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, AttachmentScan $attachmentScan): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isActive();
    }

    public function delete(User $user, AttachmentScan $attachmentScan): bool
    {
        return $user->isActive();
    }

    public function update(User $user, AttachmentScan $attachmentScan): bool
    {
        return $user->isActive();
    }
}
