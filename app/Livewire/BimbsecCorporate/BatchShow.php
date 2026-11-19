<?php

namespace App\Livewire\BimbsecCorporate;

use App\Models\BimbsecBatch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('BIMBSEC Corporate batch — DAS')]
class BatchShow extends Component
{
    use AuthorizesRequests;

    public BimbsecBatch $batch;

    public function mount(BimbsecBatch $batch): void
    {
        $this->authorize('view', $batch);
        $this->batch = $batch;
    }

    public function render(): View
    {
        $this->authorize('view', $this->batch);

        return view('livewire.bimbsec-corporate.batch-show', [
            'batch' => $this->batch->fresh(),
        ])->layout('layouts.app');
    }
}
