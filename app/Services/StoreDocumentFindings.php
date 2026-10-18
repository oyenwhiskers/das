<?php

namespace App\Services;

use App\Jobs\ProcessProfileExtraction;
use App\Models\DocumentFinding;
use App\Models\DocumentScan;
use Illuminate\Support\Facades\DB;
use LogicException;

class StoreDocumentFindings
{
    public function __construct(public DocumentFindingsV1 $contract, public DocumentRecordOwnership $ownership) {}

    /** @param array<string, mixed> $payload */
    public function store(DocumentScan $scan, array $payload): DocumentFinding
    {
        $entries = $payload['record_ownership'] ?? null;
        unset($payload['record_ownership']);
        $observations = $this->contract->validate($scan, $payload);
        $owners = $this->ownership->validate($scan, $observations, $entries);
        $existingIds = array_fill_keys(array_merge(...array_map(
            fn (string $group): array => array_column($observations[$group], 'id'),
            ['sections', 'entities', 'fields', 'tables', 'content_blocks', 'issues']
        )), true);
        foreach ($owners as $findingId => $recordId) {
            if ($recordId !== null) {
                continue;
            }
            $id = 'unresolved-ownership-'.substr(hash('sha256', $findingId), 0, 24);
            if (isset($existingIds[$id])) {
                throw new LogicException('A generated ownership issue ID conflicts with a finding.');
            }
            $existingIds[$id] = true;
            $observations['issues'][] = [
                'id' => $id, 'type' => 'uncertain_record_ownership', 'severity' => 'warning',
                'description' => 'This observation could not be assigned to a logical record.',
                'finding_id' => $findingId, 'page_number' => null, 'resolution_status' => 'open',
            ];
        }
        $recordOwnership = $scan->record_plan === null ? null : [
            'records' => $this->ownership->plan($scan->record_plan, $observations['document']['page_count']),
            'findings' => $owners,
        ];

        $finding = DB::transaction(function () use ($scan, $observations, $recordOwnership): DocumentFinding {
            $scan = DocumentScan::query()->lockForUpdate()->findOrFail($scan->getKey());
            if ($scan->findings()->exists()) {
                throw new LogicException('Original document findings have already been stored.');
            }

            $finding = $scan->findings()->create([
                'schema_version' => DocumentFindingsV1::VERSION,
                'observations' => $observations,
                'record_ownership' => $recordOwnership,
            ]);
            $scan->update([
                'extraction_status' => 'completed',
                'extraction_error' => null,
            ]);

            return $finding;
        });
        if ($scan->profile_schema_hash !== null) {
            try {
                ProcessProfileExtraction::dispatch($scan->id);
            } catch (\Throwable $exception) {
                $scan->update(['profile_status' => 'failed', 'profile_error' => 'Profile extraction could not be queued.']);
                report($exception);
            }
        }

        return $finding;
    }
}
