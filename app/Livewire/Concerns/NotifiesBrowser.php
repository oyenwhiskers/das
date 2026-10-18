<?php

namespace App\Livewire\Concerns;

trait NotifiesBrowser
{
    protected function notifySuccess(string $message): void
    {
        $this->dispatch('notify', type: 'success', message: $message);
    }

    protected function notifyError(string $message): void
    {
        $this->dispatch('notify', type: 'error', message: $message);
    }
}
