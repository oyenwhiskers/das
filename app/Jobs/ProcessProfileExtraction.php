<?php

namespace App\Jobs;

use App\Models\CustomerProfileSchemaSnapshot;
use App\Models\DocumentScan;
use App\Services\GeminiDocumentReader;
use App\Services\PdfPageBatches;
use App\Services\ProfileProjection;
use App\Services\SinglePassExtraction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use UnexpectedValueException;

class ProcessProfileExtraction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3660;

    private const PROMPT_VERSION = '1';

    public function __construct(public int $documentScanId) {}

    public function uniqueId(): string
    {
        return (string) $this->documentScanId;
    }

    public function handle(GeminiDocumentReader $reader, ProfileProjection $projection): void
    {
        $scan = DocumentScan::query()->with('findings')->findOrFail($this->documentScanId);
        if ($scan->profile_schema_hash === null || $scan->profile_status === 'completed') {
            return;
        }
        if ($scan->findings === null || $scan->extractedProfiles()->where('prompt_version', SinglePassExtraction::PROMPT_VERSION)->exists()) {
            app(SinglePassExtraction::class)->run($scan, $reader);

            return;
        }
        $scan->update(['profile_status' => 'processing', 'profile_error' => null]);
        $snapshot = CustomerProfileSchemaSnapshot::query()->where('hash', $scan->profile_schema_hash)->sole();
        $schema = $snapshot->schema();
        if (hash('sha256', $snapshot->canonical_json) !== $snapshot->hash || ($schema['version'] ?? null) !== $snapshot->version) {
            throw new UnexpectedValueException('The historical profile schema snapshot is invalid.');
        }
        $plan = $scan->findings->record_ownership['records'] ?? [];
        if ($plan === []) {
            $scan->update(['profile_status' => 'completed', 'profile_unresolved' => []]);

            return;
        }
        $count = $scan->findings->observations['document']['page_count'];
        $size = config('document_scanner.pages_per_batch');
        $sources = [];
        if ($scan->mime_type === 'application/pdf' && $count > 1) {
            $directory = 'document-scans/'.$scan->id.'/extraction-batches';
            $starts = array_values(array_map(
                fn (string $path): int => (int) pathinfo($path, PATHINFO_FILENAME),
                array_filter(Storage::disk('local')->files($directory),
                    fn (string $path): bool => str_ends_with($path, '.pdf') && ctype_digit(pathinfo($path, PATHINFO_FILENAME)))
            ));
            sort($starts);
            if ($starts !== range(1, $count, $size)) {
                $prepared = app(PdfPageBatches::class)->make($scan, $size);
                if ($prepared['count'] !== $count) {
                    throw new UnexpectedValueException('The stored PDF page count changed since original findings.');
                }
                $sources = $prepared['batches'];
            }
        }
        $records = [];
        $unresolved = [];
        $errors = [];
        $failedPages = [];
        $rejected = [];
        // shortcut: page groups run serially within one 3600-second job; split into batch jobs if larger PDFs approach that limit.
        for ($start = 1; $start <= $count; $start += $size) {
            $end = min($count, $start + $size - 1);
            try {
                $cacheKey = hash('sha256', implode('|', [
                    $scan->id, $start, $end, config('services.gemini.model'), self::PROMPT_VERSION,
                    ProfileProjection::VERSION, $snapshot->hash,
                ]));
                $path = 'document-scans/'.$scan->id.'/profile-batches/'.$cacheKey.'.json';
                $disk = Storage::disk('local');
                $cached = $disk->exists($path);
                $response = $cached ? json_decode($disk->get($path), true) : null;
                if (! is_array($response)) {
                    $response = $reader->extractProfileBatch($scan, $snapshot, $start, $end, $sources[$start] ?? null);
                }
                try {
                    $result = $projection->validate($scan, $response, $schema, $start, $end);
                } catch (UnexpectedValueException $exception) {
                    if (! $cached) {
                        throw $exception;
                    }
                    $disk->delete($path);
                    $response = $reader->extractProfileBatch($scan, $snapshot, $start, $end, $sources[$start] ?? null);
                    $result = $projection->validate($scan, $response, $schema, $start, $end);
                }
                if (! $disk->exists($path) && ! $disk->put($path, json_encode($response, JSON_THROW_ON_ERROR))) {
                    throw new UnexpectedValueException('A validated profile batch could not be cached.');
                }
                $unresolved = array_merge($unresolved, $result['unresolved']);
                $rejected = array_merge($rejected, $result['rejected']);
                foreach ($result['records'] as $ref => $record) {
                    if (! isset($records[$ref])) {
                        $records[$ref] = $record;

                        continue;
                    }
                    foreach ($record['values'] as $value) {
                        $same = collect($records[$ref]['values'])->contains(fn (array $old): bool => $old['field_id'] === $value['field_id'] && $old['row_ref'] === $value['row_ref']);
                        if ($same) {
                            $records[$ref]['warnings'][] = ['field_id' => $value['field_id'], 'row_ref' => $value['row_ref'], 'issue' => 'Cross-batch values require review'];
                        }
                        $records[$ref]['values'][] = $value;
                    }
                    $records[$ref]['warnings'] = array_merge($records[$ref]['warnings'], $record['warnings']);
                }
            } catch (Throwable $exception) {
                $failedPages = array_merge($failedPages, range($start, $end));
                $errors[] = 'Pages '.$start.'-'.$end.': '.($exception instanceof UnexpectedValueException || $exception instanceof \RuntimeException
                    ? $exception->getMessage() : 'Profile extraction failed.');
            }
        }
        foreach ($plan as $entry) {
            $ref = $entry['id'];
            if (! isset($records[$ref]) || in_array($ref, $rejected, true)
                || array_intersect(array_column($entry['regions'], 'page_number'), $failedPages) !== []) {
                $errors[] = 'Record '.$ref.' has no valid projection.';

                continue;
            }
            $original = $records[$ref];
            $scan->extractedProfiles()->firstOrCreate(['record_ref' => $ref], [
                'source_pages' => array_column($entry['regions'], 'page_number'),
                'field_schema_hash' => $snapshot->hash, 'field_schema_version' => $snapshot->version,
                'prompt_version' => self::PROMPT_VERSION,
                'contract_version' => ProfileProjection::VERSION,
                'original_projection' => $original, 'reviewed_projection' => $original,
            ]);
        }
        $scan->update([
            'profile_status' => $errors === [] ? 'completed' : 'failed',
            'profile_error' => $errors === [] ? null : implode("\n", $errors),
            'profile_unresolved' => $unresolved,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        DocumentScan::query()->whereKey($this->documentScanId)->update([
            'profile_status' => 'failed',
            'profile_error' => 'Profile extraction failed. Please retry or contact an administrator.',
        ]);
    }
}
