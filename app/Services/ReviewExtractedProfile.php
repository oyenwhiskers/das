<?php

namespace App\Services;

use App\Models\CustomerIdentification;
use App\Models\CustomerProfileSchemaSnapshot;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewExtractedProfile
{
    public function correct(ExtractedProfile $profile, int $revision, ?int $index, array $input, User $actor): void
    {
        DB::transaction(function () use ($profile, $revision, $index, $input, $actor): void {
            $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->checkRevision($profile, $revision);
            $projection = $profile->reviewed_projection;
            $previous = $index === null ? null : ($projection['values'][$index] ?? null);
            if ($index !== null && $previous === null) {
                throw ValidationException::withMessages(['field' => 'This value no longer exists.']);
            }
            if ($input['state'] === 'excluded') {
                if ($previous === null) {
                    throw ValidationException::withMessages(['field' => 'Choose a value to exclude.']);
                }
                array_splice($projection['values'], $index, 1);
                $new = null;
            } else {
                $snapshot = CustomerProfileSchemaSnapshot::query()->where('hash', $profile->field_schema_hash)->sole();
                $descriptor = collect($snapshot->schema()['fields'])->firstWhere('path', $input['field_id']);
                if ($descriptor === null || ($previous !== null && ($previous['field_id'] !== $input['field_id'] || $previous['row_ref'] !== $input['row_ref']))) {
                    throw ValidationException::withMessages(['field' => 'Choose a supported field and row.']);
                }
                $row = $input['row_ref'];
                if (($descriptor['cardinality'] === 'repeatable') !== ($row !== null && $row !== '' && strlen($row) <= 100)) {
                    throw ValidationException::withMessages(['row' => 'A repeatable field needs a row reference.']);
                }
                $scan = $profile->documentScan()->with('findings')->firstOrFail();
                $entities = array_column($scan->findings->observations['entities'], null, 'id');
                $owners = $scan->findings->record_ownership['findings'] ?? [];
                $entity = $input['entity_ref'];
                if (! isset($entities[$entity]) || ($previous === null && (($owners[$entity] ?? null) !== $profile->record_ref
                    || ! app(ProfileProjection::class)->roleMatches($descriptor['entity_role'], $entities[$entity]['role'] ?? null, $input['field_id'])))
                    || ($previous !== null && $entity !== $previous['entity_ref'])) {
                    throw ValidationException::withMessages(['entity' => 'Choose an entity owned by this record.']);
                }
                $evidence = [];
                foreach (['fields', 'tables', 'content_blocks'] as $group) {
                    foreach ($scan->findings->observations[$group] as $item) {
                        $evidence[$item['id']] = $item['evidence']['page_number'];
                    }
                }
                $ids = $input['evidence_ids'];
                if ($ids === [] || count($ids) !== count(array_unique($ids)) || collect($ids)->contains(fn ($id) => ! isset($evidence[$id])
                    || ! in_array($evidence[$id], $profile->source_pages, true)
                    || (($owners[$id] ?? null) !== $profile->record_ref && ! in_array($id, $previous['evidence_ids'] ?? [], true)))) {
                    throw ValidationException::withMessages(['evidence' => 'Choose evidence on this record’s pages.']);
                }
                $state = $input['state'];
                $value = $state === 'provided' ? $input['value'] : null;
                if (! in_array($state, ['provided', 'ambiguous', 'unreadable'], true)
                    || ($state === 'provided' && ! app(ProfileProjection::class)->validValue($value, $descriptor))) {
                    throw ValidationException::withMessages(['value' => 'The value does not match the historical field definition.']);
                }
                $new = ['field_id' => $input['field_id'], 'entity_ref' => $entity, 'row_ref' => $row,
                    'state' => $state, 'value' => $value, 'evidence_ids' => $ids];
                if (isset($previous['fragment_id'])) {
                    $new['fragment_id'] = $previous['fragment_id'];
                }
                if ($index === null) {
                    $projection['values'][] = $new;
                } else {
                    $projection['values'][$index] = $new;
                }
            }
            $this->save($profile, $projection, $actor, $previous, $new, $input['reason']);
        });
    }

    public function dismissWarning(ExtractedProfile $profile, int $revision, int $index, string $reason, User $actor): void
    {
        DB::transaction(function () use ($profile, $revision, $index, $reason, $actor): void {
            $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->checkRevision($profile, $revision);
            $projection = $profile->reviewed_projection;
            $previous = $projection['warnings'][$index] ?? null;
            if ($previous === null) {
                throw ValidationException::withMessages(['warning' => 'This warning no longer exists.']);
            }
            array_splice($projection['warnings'], $index, 1);
            $this->save($profile, $projection, $actor, ['warning' => $previous], null, $reason);
        });
    }

    public function transfer(ExtractedProfile $source, ExtractedProfile $target, int $sourceRevision, int $targetRevision, string $entity, string $reason, User $actor): void
    {
        if ($source->document_scan_id !== $target->document_scan_id || $source->id === $target->id) {
            throw ValidationException::withMessages(['target' => 'Choose another profile from this scan.']);
        }
        DB::transaction(function () use ($source, $target, $sourceRevision, $targetRevision, $entity, $reason, $actor): void {
            $locked = ExtractedProfile::query()->whereKey([$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id];
            $target = $locked[$target->id];
            $this->checkRevision($source, $sourceRevision);
            $this->checkRevision($target, $targetRevision);
            $from = $source->reviewed_projection;
            $to = $target->reviewed_projection;
            $moving = array_values(array_filter($from['values'], fn ($value) => $value['entity_ref'] === $entity));
            if ($moving === []) {
                throw ValidationException::withMessages(['entity' => 'This entity has no proposed values.']);
            }
            $scan = $source->documentScan()->with('findings')->firstOrFail();
            $evidence = [];
            foreach (['fields', 'tables', 'content_blocks'] as $group) {
                foreach ($scan->findings->observations[$group] as $item) {
                    $evidence[$item['id']] = $item['evidence']['page_number'];
                }
            }
            $from['values'] = array_values(array_filter($from['values'], fn ($value) => $value['entity_ref'] !== $entity));
            foreach ($moving as $value) {
                if (collect($value['evidence_ids'])->every(fn ($id) => in_array($evidence[$id] ?? null, $target->source_pages, true))) {
                    $to['values'][] = $value;
                } else {
                    $unresolved = $scan->profile_unresolved ?? [];
                    $unresolved[] = ['warning' => 'Ownership correction removed a value outside target pages.', 'value' => $value];
                    $scan->update(['profile_unresolved' => $unresolved]);
                }
            }
            $this->save($source, $from, $actor, ['transferred_entity' => $entity, 'values' => $moving], null, $reason);
            $this->save($target, $to, $actor, null, ['received_entity' => $entity, 'values' => $moving], $reason);
        });
    }

    public function markReviewed(ExtractedProfile $profile, int $revision, User $actor): void
    {
        DB::transaction(function () use ($profile, $revision, $actor): void {
            $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->checkRevision($profile, $revision);
            $projection = $profile->reviewed_projection;
            $keys = [];
            foreach ($projection['values'] as $value) {
                $key = json_encode([$value['field_id'], $value['row_ref']], JSON_THROW_ON_ERROR);
                if ($value['state'] !== 'provided' || isset($keys[$key])) {
                    throw ValidationException::withMessages(['review' => 'Resolve uncertain and conflicting values first.']);
                }
                $keys[$key] = true;
            }
            if ($projection['warnings'] !== []) {
                throw ValidationException::withMessages(['review' => 'Resolve profile warnings first.']);
            }
            if (($projection['grouping_status'] ?? 'active') === 'withdrawn') {
                throw ValidationException::withMessages(['review' => 'This profile was withdrawn.']);
            }
            if ($this->heldContinuation($profile)) {
                throw ValidationException::withMessages(['review' => 'A held page group still matches this applicant. Attach it or confirm it is a separate application.']);
            }
            $profile->update(['review_status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'revision' => $revision + 1]);
            $profile->reviewEvents()->create(['actor_id' => $actor->id, 'revision' => $revision + 1,
                'previous_value' => ['status' => 'pending'], 'new_value' => ['status' => 'reviewed'],
                'reason' => 'Review completed', 'created_at' => now()]);
        });
    }

    public function attachFragment(ExtractedProfile $profile, string $fragmentId, string $reason, User $actor): void
    {
        DB::transaction(function () use ($profile, $fragmentId, $reason, $actor): void {
            [$profile, $scan, $ownership, $index] = $this->heldFragment($profile, $fragmentId);
            if ($profile->merges()->exists()) {
                throw ValidationException::withMessages(['fragment' => 'This profile was already merged into a customer.']);
            }
            $fragment = $ownership['fragments'][$index];
            if ($this->identityConflicts($this->identity($profile->reviewed_projection['values'] ?? []), $fragment['identity'] ?? ['name' => null, 'nric' => null])) {
                throw ValidationException::withMessages(['fragment' => 'This fragment names a different applicant.']);
            }
            $stored = $fragment['values'] ?? [];
            $original = $profile->original_projection;
            $reviewed = $profile->reviewed_projection;
            foreach ([$original, $reviewed] as $slot => $projection) {
                $projection['values'] = array_merge($projection['values'] ?? [], $stored);
                $projection['warnings'] = array_merge($projection['warnings'] ?? [], $fragment['warnings'] ?? []);
                $projection['fragment_ids'] = array_values(array_unique([...($projection['fragment_ids'] ?? []), $fragmentId]));
                if ($slot === 0) {
                    $original = $projection;
                } else {
                    $reviewed = $projection;
                }
            }
            $pages = array_values(array_unique(array_merge($profile->source_pages ?? [], $fragment['pages'] ?? [])));
            sort($pages);
            $ownership['fragments'][$index]['state'] = 'attached';
            $ownership['fragments'][$index]['profile_ref'] = $profile->record_ref;
            $this->assignOwners($ownership, $stored, $profile->record_ref);
            $scan->findings->update(['record_ownership' => $ownership]);
            $profile->forceFill([
                'original_projection' => $original,
                'source_pages' => $pages,
                'review_status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
            $this->save($profile, $reviewed, $actor, ['fragment_id' => $fragmentId, 'values' => $stored], ['attached_to' => $profile->record_ref], $reason);
        });
    }

    public function confirmFragment(DocumentScan $scan, string $fragmentId, string $reason, User $actor): ExtractedProfile
    {
        return DB::transaction(function () use ($scan, $fragmentId, $reason, $actor): ExtractedProfile {
            $scan = DocumentScan::query()->lockForUpdate()->findOrFail($scan->id);
            $finding = $scan->findings()->lockForUpdate()->firstOrFail();
            $ownership = $finding->record_ownership ?? [];
            $index = $this->fragmentIndex($ownership, $fragmentId);
            $fragment = $ownership['fragments'][$index];
            $identity = $fragment['identity'] ?? ['name' => null, 'nric' => null];
            $snapshot = CustomerProfileSchemaSnapshot::query()->where('hash', $scan->profile_schema_hash)->sole();
            $number = 1;
            foreach ($scan->extractedProfiles()->lockForUpdate()->pluck('record_ref') as $recordRef) {
                if (preg_match('/^p(\d+)$/', $recordRef, $match) === 1) {
                    $number = max($number, ((int) $match[1]) + 1);
                }
            }
            $recordRef = sprintf('p%04d', $number);
            $projection = [
                'record_ref' => $recordRef,
                'anchor' => $fragment['anchor'] ?? 'Applicant',
                'values' => $fragment['values'] ?? [],
                'warnings' => $fragment['warnings'] ?? [],
                'ownership' => ($identity['name'] || $identity['nric']) ? 'resolved' : 'unresolved',
                'grouping_status' => 'active',
                'form_reference' => $fragment['form_reference'] ?? null,
                'fragment_ids' => [$fragmentId],
            ];
            $profile = $scan->extractedProfiles()->create([
                'record_ref' => $recordRef,
                'source_pages' => $fragment['pages'] ?? [],
                'field_schema_hash' => $snapshot->hash,
                'field_schema_version' => $snapshot->version,
                'prompt_version' => SinglePassExtraction::PROMPT_VERSION,
                'contract_version' => ProfileProjection::VERSION,
                'original_projection' => $projection,
                'reviewed_projection' => $projection,
            ]);
            $ownership['fragments'][$index]['state'] = 'separate';
            $ownership['fragments'][$index]['profile_ref'] = $recordRef;
            $this->assignOwners($ownership, $projection['values'], $recordRef);
            $finding->update(['record_ownership' => $ownership]);
            $this->save($profile, $projection, $actor, null, ['confirmed_separate' => $fragmentId], $reason);

            return $profile;
        });
    }

    public function reverseFragment(DocumentScan $scan, string $fragmentId, string $reason, User $actor): void
    {
        DB::transaction(function () use ($scan, $fragmentId, $reason, $actor): void {
            $scan = DocumentScan::query()->lockForUpdate()->findOrFail($scan->id);
            $finding = $scan->findings()->lockForUpdate()->firstOrFail();
            $ownership = $finding->record_ownership ?? [];
            $index = $this->fragmentIndex($ownership, $fragmentId, ['attached', 'separate']);
            $fragment = $ownership['fragments'][$index];
            $profile = $scan->extractedProfiles()->where('record_ref', $fragment['profile_ref'])->lockForUpdate()->firstOrFail();
            if ($profile->merges()->exists()) {
                throw ValidationException::withMessages(['fragment' => 'This profile was already merged into a customer.']);
            }
            $evidencePages = [];
            foreach (['fields', 'tables', 'content_blocks'] as $group) {
                foreach ($finding->observations[$group] ?? [] as $item) {
                    $evidencePages[$item['id']] = $item['evidence']['page_number'] ?? null;
                }
            }
            $original = $this->withoutFragment($profile->original_projection, $fragmentId);
            $reviewed = $this->withoutFragment($profile->reviewed_projection, $fragmentId);
            $pages = [];
            foreach ($reviewed['values'] as $value) {
                foreach ($value['evidence_ids'] ?? [] as $id) {
                    if (is_int($evidencePages[$id] ?? null)) {
                        $pages[] = $evidencePages[$id];
                    }
                }
            }
            $pages = array_values(array_unique($pages));
            sort($pages);
            if ($reviewed['values'] === []) {
                $reviewed['grouping_status'] = 'withdrawn';
                $original['grouping_status'] = 'withdrawn';
                $pages = [];
            }
            $ownership['fragments'][$index]['state'] = 'held';
            $ownership['fragments'][$index]['profile_ref'] = null;
            $this->assignOwners($ownership, $fragment['values'] ?? [], null);
            $finding->update(['record_ownership' => $ownership]);
            $profile->forceFill([
                'original_projection' => $original,
                'source_pages' => $pages,
                'review_status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
            $this->save($profile, $reviewed, $actor, ['fragment_id' => $fragmentId, 'profile_ref' => $fragment['profile_ref']], ['reversed' => true], $reason);
        });
    }

    public function resolveOwnership(ExtractedProfile $profile, string $reason, User $actor): void
    {
        DB::transaction(function () use ($profile, $reason, $actor): void {
            $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $projection = $profile->reviewed_projection;
            if (($projection['ownership'] ?? 'resolved') === 'resolved') {
                throw ValidationException::withMessages(['ownership' => 'Applicant ownership is already resolved.']);
            }
            $projection['ownership'] = 'resolved';
            $profile->forceFill(['review_status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null])->save();
            $this->save($profile, $projection, $actor, ['ownership' => 'unresolved'], ['ownership' => 'resolved'], $reason);
        });
    }

    /** @return array{0: ExtractedProfile, 1: DocumentScan, 2: array<string, mixed>, 3: int} */
    private function heldFragment(ExtractedProfile $profile, string $fragmentId): array
    {
        $profile = ExtractedProfile::query()->lockForUpdate()->findOrFail($profile->id);
        $scan = DocumentScan::query()->lockForUpdate()->findOrFail($profile->document_scan_id);
        $finding = $scan->findings()->lockForUpdate()->firstOrFail();
        $scan->setRelation('findings', $finding);
        $ownership = $finding->record_ownership ?? [];

        return [$profile, $scan, $ownership, $this->fragmentIndex($ownership, $fragmentId)];
    }

    /** @param array<string, mixed> $ownership @param list<string> $states */
    private function fragmentIndex(array $ownership, string $fragmentId, array $states = ['held']): int
    {
        foreach ($ownership['fragments'] ?? [] as $index => $fragment) {
            if (($fragment['id'] ?? null) === $fragmentId && in_array($fragment['state'] ?? '', $states, true)) {
                return $index;
            }
        }

        throw ValidationException::withMessages(['fragment' => 'This fragment is not waiting for that decision.']);
    }

    /** @param array<string, mixed> $ownership @param list<array<string, mixed>> $stored */
    private function assignOwners(array &$ownership, array $stored, ?string $recordRef): void
    {
        foreach ($stored as $value) {
            $ownership['findings'][$value['entity_ref']] = $recordRef;
            foreach ($value['evidence_ids'] as $evidenceId) {
                $ownership['findings'][$evidenceId] = $recordRef;
            }
        }
    }

    /** @param array<string, mixed> $projection @return array<string, mixed> */
    private function withoutFragment(array $projection, string $fragmentId): array
    {
        $projection['values'] = array_values(array_filter($projection['values'] ?? [], fn (array $value): bool => ($value['fragment_id'] ?? null) !== $fragmentId));
        $projection['fragment_ids'] = array_values(array_filter($projection['fragment_ids'] ?? [], fn (string $id): bool => $id !== $fragmentId));

        return $projection;
    }

    private function heldContinuation(ExtractedProfile $profile): bool
    {
        $fragments = $profile->documentScan()->first()?->findings?->record_ownership['fragments'] ?? [];
        $identity = $this->identity($profile->reviewed_projection['values'] ?? []);
        $reference = $profile->reviewed_projection['form_reference'] ?? null;
        foreach ($fragments as $fragment) {
            if (($fragment['state'] ?? '') !== 'held') {
                continue;
            }
            $other = $fragment['identity'] ?? ['name' => null, 'nric' => null];
            if ((is_string($reference) && $reference !== '' && ($fragment['form_reference'] ?? null) === $reference)
                || $this->samePerson($identity, $other)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $values @return array{name: ?string, nric: ?string} */
    private function identity(array $values): array
    {
        $name = null;
        $nric = null;
        foreach ($values as $value) {
            if (($value['state'] ?? null) !== 'provided') {
                continue;
            }
            if (($value['field_id'] ?? null) === 'customer.full_legal_name' && is_string($value['value'] ?? null)) {
                $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value['value']) ?? ''));
            }
            if (($value['field_id'] ?? null) === 'primary_identification.number') {
                $nric = CustomerIdentification::normalizeNumber(is_string($value['value']) ? $value['value'] : null);
            }
        }

        return ['name' => $name !== '' ? $name : null, 'nric' => $nric];
    }

    /** @param array{name: ?string, nric: ?string} $left @param array{name: ?string, nric: ?string} $right */
    private function identityConflicts(array $left, array $right): bool
    {
        return ($left['nric'] && $right['nric'] && $left['nric'] !== $right['nric'])
            || ($left['name'] && $right['name'] && $left['name'] !== $right['name']);
    }

    /** @param array{name: ?string, nric: ?string} $left @param array{name: ?string, nric: ?string} $right */
    private function samePerson(array $left, array $right): bool
    {
        if ($left['nric'] && $right['nric']) {
            return $left['nric'] === $right['nric'];
        }

        return (bool) ($left['name'] && $right['name'] && $left['name'] === $right['name']);
    }

    private function checkRevision(ExtractedProfile $profile, int $revision): void
    {
        if ($profile->revision !== $revision || $profile->review_status === 'reviewed') {
            throw ValidationException::withMessages(['review' => 'This profile changed or was already reviewed. Reload and try again.']);
        }
    }

    private function save(ExtractedProfile $profile, array $projection, User $actor, ?array $previous, ?array $new, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for this correction.']);
        }
        $profile->update(['reviewed_projection' => $projection, 'revision' => $profile->revision + 1]);
        $profile->reviewEvents()->create(['actor_id' => $actor->id, 'revision' => $profile->revision,
            'previous_value' => $previous, 'new_value' => $new, 'reason' => $reason, 'created_at' => now()]);
    }
}
