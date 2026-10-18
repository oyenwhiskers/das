<?php

namespace App\Jobs;

use App\Models\BimbsecBatch;
use App\Services\Bimbsec\BatchRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateBimbsecBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public int $batchId) {}

    public function handle(BatchRunner $runner): void
    {
        $batch = BimbsecBatch::query()->find($this->batchId);
        if ($batch === null || $batch->status === 'ready') {
            return;
        }
        try {
            $runner->process($batch);
        } catch (Throwable $exception) {
            report($exception);
            $batch->update(['status' => 'incomplete', 'complete' => false]);
        }
    }
}
