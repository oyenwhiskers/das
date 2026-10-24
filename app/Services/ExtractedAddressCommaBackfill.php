<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\CustomerBankAccount;
use App\Models\CustomerBrokerageAccount;
use App\Models\CustomerEmployment;
use App\Models\ExtractedProfile;
use App\Models\RelatedPerson;
use Illuminate\Support\Collection;

class ExtractedAddressCommaBackfill
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

        CustomerAddress::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $addresses): void {
                foreach ($addresses as $address) {
                    $this->reformatColumns($address, ['line_1', 'line_2']);
                }
            });

        CustomerEmployment::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $jobs): void {
                foreach ($jobs as $job) {
                    $this->reformatColumns($job, ['address_line_1', 'address_line_2']);
                }
            });

        RelatedPerson::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $people): void {
                foreach ($people as $person) {
                    $this->reformatColumns($person, [
                        'employer_address',
                        'employer_address_line_1',
                        'employer_address_line_2',
                    ]);
                }
            });

        CustomerBankAccount::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $accounts): void {
                foreach ($accounts as $account) {
                    $this->reformatColumns($account, ['branch_address']);
                }
            });

        CustomerBrokerageAccount::query()
            ->orderBy('id')
            ->chunkById(100, function (Collection $accounts): void {
                foreach ($accounts as $account) {
                    $this->reformatColumns($account, ['branch_address']);
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
            if (! $this->projection->isAddressLinePath($field)) {
                continue;
            }
            $cleaned = $this->projection->stripAddressLineCommas($value['value']);
            if ($cleaned !== $value['value']) {
                $values[$index]['value'] = $cleaned;
            }
        }

        return $values;
    }

    /** @param list<string> $columns */
    private function reformatColumns(object $model, array $columns): void
    {
        $updates = [];
        foreach ($columns as $column) {
            $current = $model->{$column};
            if (! is_string($current) || $current === '' || ! str_contains($current, ',')) {
                continue;
            }
            $cleaned = $this->projection->stripAddressLineCommas($current);
            if ($cleaned !== $current) {
                $updates[$column] = $cleaned === '' ? null : $cleaned;
            }
        }
        if ($updates !== []) {
            $model->update($updates);
        }
    }
}
