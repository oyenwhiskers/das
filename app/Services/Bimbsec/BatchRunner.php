<?php

namespace App\Services\Bimbsec;

use App\Jobs\GenerateBimbsecBatch;
use App\Models\BimbsecApplication;
use App\Models\BimbsecBatch;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Throwable;

class BatchRunner
{
    public function __construct(private Generator $generator) {}

    /** @param  list<int>  $customerIds */
    public function start(array $customerIds, User $user): BimbsecBatch
    {
        $ids = [];
        foreach ($customerIds as $id) {
            $id = (int) $id;
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        $applicationIds = [];
        foreach ($ids as $id) {
            $customer = Customer::query()->find($id);
            if ($customer === null || ! $user->can('view', $customer)) {
                continue;
            }
            $application = $customer->currentBimbsecApplication() ?? $customer->bimbsecApplications()->create([
                'choices' => BimbsecApplication::normalizeChoices([]),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            $applicationIds[] = $application->id;
        }
        $batch = BimbsecBatch::query()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'application_ids' => $applicationIds,
            'complete' => false,
        ]);
        if (count($applicationIds) <= max(1, (int) config('bimbsec.chunk_size'))) {
            $this->process($batch);
        } else {
            GenerateBimbsecBatch::dispatch($batch->id);
        }
        $batch->refresh();
        if (count($applicationIds) !== count($ids)) {
            if (is_string($batch->storage_path) && $batch->storage_path !== '') {
                Storage::disk('local')->delete($batch->storage_path);
            }
            $summary = $batch->summary ?? [];
            $summary[] = ['ok' => false, 'reference' => '', 'name' => 'Selected customer', 'reason' => 'A selected customer could not be opened and was not included.'];
            $batch->update(['status' => 'incomplete', 'complete' => false, 'storage_path' => null, 'summary' => $summary]);
        }

        return $batch->refresh();
    }

    public function process(BimbsecBatch $batch): void
    {
        $batch->update(['status' => 'running']);
        $summary = [];
        $paths = [];
        $chunk = max(1, (int) config('bimbsec.chunk_size'));
        $ids = array_values($batch->application_ids ?? []);
        foreach (array_chunk($ids, $chunk) as $group) {
            foreach ($group as $applicationId) {
                $application = BimbsecApplication::query()->with('customer')->find($applicationId);
                $reference = $application?->customer?->reference ?? 'unknown';
                $name = $application?->customer?->full_legal_name ?? 'Unknown customer';
                if ($application === null) {
                    $summary[] = ['ok' => false, 'reference' => $reference, 'name' => $name, 'reason' => 'Application was not found.'];

                    continue;
                }
                try {
                    $document = $this->generator->generate($application, $batch->user);
                    $application->refresh();
                    if (! $document->allowsPrint()) {
                        $summary[] = ['ok' => false, 'reference' => $reference, 'name' => $name, 'reason' => 'A name, identification number, address or bank account does not fit the form. Shorten it on the application, then generate again.'];

                        continue;
                    }
                    $summary[] = ['ok' => true, 'reference' => $reference, 'name' => $name, 'reason' => null];
                    $paths[] = $document->storage_path;
                } catch (Throwable $exception) {
                    report($exception);
                    $summary[] = ['ok' => false, 'reference' => $reference, 'name' => $name, 'reason' => 'PDF generation failed.'];
                }
            }
        }
        $failed = collect($summary)->contains(fn (array $row): bool => ! $row['ok']);
        if ($failed || $paths === [] || count($paths) !== count($ids)) {
            $batch->update(['status' => 'incomplete', 'summary' => $summary, 'complete' => false, 'storage_path' => null]);

            return;
        }
        $path = 'bimbsec/batches/'.$batch->id.'.pdf';
        Storage::disk('local')->put($path, $this->merge($paths));
        $batch->update(['status' => 'ready', 'summary' => $summary, 'complete' => true, 'storage_path' => $path]);
    }

    /** @param  list<string>  $paths */
    private function merge(array $paths): string
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        foreach ($paths as $path) {
            $absolute = Storage::disk('local')->path($path);
            $count = $pdf->setSourceFile($absolute);
            for ($page = 1; $page <= $count; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template, 0, 0, $size['width'], $size['height']);
            }
        }

        return $pdf->Output('S');
    }
}
