<?php

namespace App\Livewire\StorageUsage;

use App\Services\StorageUsageReport;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Storage Usage — DAS')]
class Index extends Component
{
    public function render(StorageUsageReport $report): View
    {
        return view('livewire.storage-usage.index', $report->report())
            ->layout('layouts.app');
    }
}
