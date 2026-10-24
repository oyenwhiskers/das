<?php

namespace App\Services;

use App\Models\ExtractedProfile;
use Illuminate\Support\Collection;

class ExtractedPhoneBackfill
{
    public function __construct(private ProfileProjection $projection) {}

    public function run(): void
    {
        ExtractedProfile::query()
            ->orderBy('id')
            ->chunkById(50, function (Collection $profiles): void {
                foreach ($profiles as $profile) {
                    $this->reformatProfile($profile);
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
            if (! is_array($value) || ($value['state'] ?? null) !== 'provided' || ! is_string($value['value'] ?? null)) {
                continue;
            }
            $field = is_string($value['field_id'] ?? null) ? $value['field_id'] : '';
            if (! $this->projection->isPhonePath($field)) {
                continue;
            }
            $cleaned = $this->projection->stripPhonePunctuation($value['value']);
            if ($cleaned !== $value['value']) {
                $values[$index]['value'] = $cleaned;
            }
        }

        return $values;
    }
}
