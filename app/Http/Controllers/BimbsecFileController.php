<?php

namespace App\Http\Controllers;

use App\Models\BimbsecApplication;
use App\Models\BimbsecBatch;
use App\Models\Customer;
use App\Services\Bimbsec\Generator;
use App\Services\Bimbsec\SigningDates;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BimbsecFileController extends Controller
{
    public function __construct(private SigningDates $dates, private Generator $generator) {}

    public function document(Customer $customer, BimbsecApplication $application): Response
    {
        abort_unless($application->customer_id === $customer->id, 404);
        Gate::authorize('view', $application);
        $document = $this->generator->refresh($application, request()->user());
        abort_unless($document !== null && Storage::disk('local')->exists($document->storage_path), 404);
        $customer->loadMissing('primaryIdentification');
        $bytes = $this->dates->apply(
            Storage::disk('local')->get($document->storage_path),
            [$this->verifiedBy($customer)],
        );
        $download = request()->boolean('download');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="BIMBSEC-'.$customer->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function print(Customer $customer, BimbsecApplication $application): View
    {
        abort_unless($application->customer_id === $customer->id, 404);
        Gate::authorize('view', $application);
        $document = $this->generator->refresh($application, request()->user());
        abort_unless($document !== null && $document->allowsPrint(), 403);

        return view('bimbsec.print', [
            'url' => route('bimbsec.file', [$customer, $application]),
        ]);
    }

    public function batch(BimbsecBatch $batch): Response
    {
        Gate::authorize('view', $batch);
        abort_unless($batch->complete && is_string($batch->storage_path) && Storage::disk('local')->exists($batch->storage_path), 404);
        $ids = array_values($batch->application_ids ?? []);
        $customers = BimbsecApplication::query()
            ->with(['customer.primaryIdentification'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $applicants = [];
        foreach ($ids as $id) {
            $applicants[] = $this->verifiedBy($customers->get($id)?->customer);
        }
        $bytes = $this->dates->apply(Storage::disk('local')->get($batch->storage_path), $applicants);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => (request()->boolean('download') ? 'attachment' : 'inline').'; filename="BIMBSEC-batch-'.$batch->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function printBatch(BimbsecBatch $batch): View
    {
        Gate::authorize('view', $batch);
        abort_unless($batch->complete && is_string($batch->storage_path), 404);

        return view('bimbsec.print', [
            'url' => route('bimbsec.batches.file', $batch),
        ]);
    }

    /** @return array{name: string, nric: string} */
    private function verifiedBy(?Customer $customer): array
    {
        if ($customer === null) {
            return ['name' => '', 'nric' => ''];
        }

        return [
            'name' => (string) $customer->full_legal_name,
            'nric' => (string) ($customer->primaryIdentification?->number ?: $customer->primary_identification_number ?: ''),
        ];
    }
}
