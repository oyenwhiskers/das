<?php

namespace App\Services;

use App\Enums\IdentificationType;
use App\Models\AttachmentScan;
use App\Models\CustomerIdentification;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\ExtractedProfileMerge;
use App\Models\ExtractedProfileReviewEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExtractedNricBackfill
{
    public function run(): void
    {
        ExtractedProfile::query()
            ->orderBy('id')
            ->chunkById(50, function (Collection $profiles): void {
                foreach ($profiles as $profile) {
                    $this->reformatProfile($profile);
                }
            });

        DocumentScan::query()
            ->whereHas('extractedProfiles')
            ->orderBy('id')
            ->chunkById(25, function (Collection $scans): void {
                foreach ($scans as $scan) {
                    $this->collapseDuplicates($scan);
                }
            });

        AttachmentScan::query()
            ->whereNotNull('extracted_identification_number')
            ->orderBy('id')
            ->chunkById(100, function (Collection $scans): void {
                foreach ($scans as $scan) {
                    $formatted = $this->dashedNric($scan->extracted_identification_number);
                    if ($formatted !== null && $formatted !== $scan->extracted_identification_number) {
                        $scan->update(['extracted_identification_number' => $formatted]);
                    }
                }
            });
    }

    private function reformatProfile(ExtractedProfile $profile): void
    {
        $original = $this->formatProjection(is_array($profile->original_projection) ? $profile->original_projection : []);
        $reviewed = $this->formatProjection(is_array($profile->reviewed_projection) ? $profile->reviewed_projection : []);
        if ($original === $profile->original_projection && $reviewed === $profile->reviewed_projection) {
            return;
        }

        $profile->update([
            'original_projection' => $original,
            'reviewed_projection' => $reviewed,
        ]);
    }

    /** @param array<string, mixed> $projection @return array<string, mixed> */
    public function formatProjection(array $projection): array
    {
        if (! is_array($projection['values'] ?? null)) {
            return $projection;
        }
        $projection['values'] = $this->formatValues($projection['values']);

        return $projection;
    }

    /** @param list<array<string, mixed>> $values @return list<array<string, mixed>> */
    public function formatValues(array $values): array
    {
        foreach ($values as $index => $value) {
            if (! is_array($value) || ($value['state'] ?? null) !== 'provided') {
                continue;
            }
            $field = is_string($value['field_id'] ?? null) ? $value['field_id'] : '';
            if (! $this->isIdentityNumber($field) || ! is_string($value['value'] ?? null)) {
                continue;
            }
            $formatted = $this->dashedNric($value['value']);
            if ($formatted !== null) {
                $values[$index]['value'] = $formatted;
            }
        }

        return $values;
    }

    private function collapseDuplicates(DocumentScan $scan): void
    {
        $profiles = $scan->extractedProfiles()
            ->orderBy('id')
            ->get()
            ->filter(fn (ExtractedProfile $profile): bool => ($profile->reviewed_projection['grouping_status'] ?? 'active') !== 'withdrawn')
            ->values();

        $groups = [];
        foreach ($profiles as $profile) {
            $nric = $this->primaryNric($profile);
            if ($nric === null) {
                continue;
            }
            $groups[$nric][] = $profile;
        }

        $changed = false;
        foreach ($groups as $group) {
            if (count($group) < 2 || ! $this->safeToMerge($group)) {
                continue;
            }
            $keeper = $group[0];
            foreach (array_slice($group, 1) as $duplicate) {
                $this->absorbProfile($keeper, $duplicate);
                $this->withdraw($duplicate);
                $changed = true;
            }
            $keeper->refresh();
        }

        if ($changed) {
            $this->rebuildPlan($scan->fresh());
        }
    }

    /** @param list<ExtractedProfile> $group */
    private function safeToMerge(array $group): bool
    {
        $ids = array_map(fn (ExtractedProfile $profile): int => $profile->id, $group);
        if (ExtractedProfileMerge::query()->whereIn('extracted_profile_id', $ids)->exists()) {
            return false;
        }
        if (ExtractedProfileReviewEvent::query()->whereIn('extracted_profile_id', $ids)->exists()) {
            return false;
        }

        $names = [];
        $formRefs = [];
        foreach ($group as $profile) {
            $name = $this->primaryName($profile);
            if ($name !== null) {
                $names[] = $name;
            }
            $form = $profile->reviewed_projection['form_reference'] ?? null;
            if (is_string($form) && trim($form) !== '') {
                $formRefs[] = trim($form);
            }
        }
        if (count(array_unique($formRefs)) > 1) {
            return false;
        }
        if ($names === []) {
            return true;
        }
        $first = $names[0];
        foreach ($names as $name) {
            if ($name !== $first && ! $this->abbreviatedName($name, $first)) {
                return false;
            }
        }

        return true;
    }

    private function absorbProfile(ExtractedProfile $keeper, ExtractedProfile $duplicate): void
    {
        $pages = array_values(array_unique([...($keeper->source_pages ?? []), ...($duplicate->source_pages ?? [])]));
        sort($pages);

        $original = $this->absorbProjection(
            is_array($keeper->original_projection) ? $keeper->original_projection : [],
            is_array($duplicate->original_projection) ? $duplicate->original_projection : [],
        );
        $reviewed = $this->absorbProjection(
            is_array($keeper->reviewed_projection) ? $keeper->reviewed_projection : [],
            is_array($duplicate->reviewed_projection) ? $duplicate->reviewed_projection : [],
        );

        $keeper->update([
            'source_pages' => $pages,
            'original_projection' => $this->formatProjection($original),
            'reviewed_projection' => $this->formatProjection($reviewed),
        ]);
    }

    /** @param array<string, mixed> $target @param array<string, mixed> $extra @return array<string, mixed> */
    private function absorbProjection(array $target, array $extra): array
    {
        $values = is_array($target['values'] ?? null) ? $target['values'] : [];
        $warnings = is_array($target['warnings'] ?? null) ? $target['warnings'] : [];
        foreach (is_array($extra['values'] ?? null) ? $extra['values'] : [] as $value) {
            if (! is_array($value)) {
                continue;
            }
            $index = $this->sameFieldIndex($values, $value);
            if ($index === null) {
                $values[] = $value;

                continue;
            }
            if ($this->sameFact($values[$index], $value)) {
                if ($this->fullerName($value, $values[$index])) {
                    $values[$index]['value'] = $value['value'];
                }

                continue;
            }
            $warnings[] = [
                'field_id' => $value['field_id'] ?? null,
                'row_ref' => $value['row_ref'] ?? null,
                'issue' => 'Conflicting value requires review',
            ];
        }
        $target['values'] = $values;
        $target['warnings'] = array_merge($warnings, is_array($extra['warnings'] ?? null) ? $extra['warnings'] : []);

        return $target;
    }

    private function withdraw(ExtractedProfile $profile): void
    {
        $reviewed = is_array($profile->reviewed_projection) ? $profile->reviewed_projection : [];
        $original = is_array($profile->original_projection) ? $profile->original_projection : [];
        $reviewed['grouping_status'] = 'withdrawn';
        $original['grouping_status'] = 'withdrawn';
        $profile->update([
            'reviewed_projection' => $reviewed,
            'original_projection' => $original,
        ]);
    }

    private function rebuildPlan(DocumentScan $scan): void
    {
        $active = $scan->extractedProfiles()
            ->orderBy('id')
            ->get()
            ->filter(fn (ExtractedProfile $profile): bool => ($profile->reviewed_projection['grouping_status'] ?? 'active') !== 'withdrawn')
            ->values();

        $plan = $active->map(fn (ExtractedProfile $profile): array => [
            'id' => $profile->record_ref,
            'anchor' => $profile->reviewed_projection['anchor'] ?? 'Applicant',
            'regions' => array_map(
                fn (int $page): array => ['page_number' => $page, 'bbox' => null],
                $profile->source_pages ?? [],
            ),
        ])->all();

        DB::transaction(function () use ($scan, $plan): void {
            $scan->update(['record_plan' => $plan]);
            $finding = $scan->findings;
            if ($finding === null) {
                return;
            }
            $ownership = is_array($finding->record_ownership) ? $finding->record_ownership : [];
            $ownership['records'] = $plan;
            $finding->update(['record_ownership' => $ownership]);
        });
    }

    private function primaryNric(ExtractedProfile $profile): ?string
    {
        foreach (is_array($profile->reviewed_projection['values'] ?? null) ? $profile->reviewed_projection['values'] : [] as $value) {
            if (($value['field_id'] ?? null) === 'primary_identification.number' && ($value['state'] ?? null) === 'provided' && is_string($value['value'] ?? null)) {
                $normalized = CustomerIdentification::normalizeNumber($value['value']);
                if ($normalized !== null && preg_match('/^\d{12}$/', $normalized) === 1) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function primaryName(ExtractedProfile $profile): ?string
    {
        foreach (is_array($profile->reviewed_projection['values'] ?? null) ? $profile->reviewed_projection['values'] : [] as $value) {
            if (($value['field_id'] ?? null) === 'customer.full_legal_name' && ($value['state'] ?? null) === 'provided' && is_string($value['value'] ?? null)) {
                $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value['value']) ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    private function dashedNric(?string $number): ?string
    {
        $normalized = CustomerIdentification::normalizeNumber($number);
        if ($normalized === null || preg_match('/^\d{12}$/', $normalized) !== 1) {
            return null;
        }

        return CustomerIdentification::formatNumber($number, IdentificationType::Nric);
    }

    private function isIdentityNumber(string $field): bool
    {
        return $field === 'primary_identification.number' || str_ends_with($field, '.identification_number');
    }

    /** @param list<array<string, mixed>> $values @param array<string, mixed> $incoming */
    private function sameFieldIndex(array $values, array $incoming): ?int
    {
        $key = $this->fieldKey($incoming);
        foreach ($values as $index => $value) {
            if (is_array($value) && $this->fieldKey($value) === $key) {
                return $index;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $value */
    private function fieldKey(array $value): string
    {
        $row = $value['row_ref'] ?? null;

        return ($value['field_id'] ?? '')."\n".(is_string($row) ? trim($row) : '');
    }

    /** @param array<string, mixed> $kept @param array<string, mixed> $incoming */
    private function sameFact(array $kept, array $incoming): bool
    {
        $field = is_string($incoming['field_id'] ?? null) ? $incoming['field_id'] : '';
        if ($this->isIdentityNumber($field)) {
            $left = CustomerIdentification::normalizeNumber(is_string($kept['value'] ?? null) ? $kept['value'] : null);
            $right = CustomerIdentification::normalizeNumber(is_string($incoming['value'] ?? null) ? $incoming['value'] : null);

            return $left !== null && $left === $right;
        }
        $left = $this->plain($kept['value'] ?? null);
        $right = $this->plain($incoming['value'] ?? null);
        if ($left === null || $right === null) {
            return ($kept['value'] ?? null) === ($incoming['value'] ?? null);
        }
        if ($left === $right) {
            return true;
        }

        return $field === 'customer.full_legal_name' && $this->abbreviatedName($left, $right);
    }

    /** @param array<string, mixed> $incoming @param array<string, mixed> $kept */
    private function fullerName(array $incoming, array $kept): bool
    {
        if (($incoming['field_id'] ?? null) !== 'customer.full_legal_name') {
            return false;
        }
        $next = $this->plain($incoming['value'] ?? null);
        $current = $this->plain($kept['value'] ?? null);

        return $next !== null && $current !== null && mb_strlen(str_replace('.', '', $next)) > mb_strlen(str_replace('.', '', $current));
    }

    private function plain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $plain = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));

        return $plain === '' ? null : $plain;
    }

    private function abbreviatedName(string $left, string $right): bool
    {
        $left = $this->nameTokens($left);
        $right = $this->nameTokens($right);
        if ($left === [] || $left === $right || count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $token) {
            if ($token === $right[$index]) {
                continue;
            }
            if ([$token, $right[$index]] !== ['b', 'bin'] && [$token, $right[$index]] !== ['bin', 'b']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function nameTokens(string $name): array
    {
        return array_values(array_filter(explode(' ', str_replace(['.', ','], ' ', $name)), fn (string $token): bool => $token !== ''));
    }
}
