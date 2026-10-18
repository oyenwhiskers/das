<?php

namespace App\Livewire\Bimbsec;

use App\Models\BimbsecBatch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('BIMBSEC Batch — DAS')]
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
        $this->batch->refresh();

        return view('livewire.bimbsec.batch', [
            'batch' => $this->batch,
        ])->layout('layouts.app');
    }
}
