<?php

namespace App\Services;

use App\Models\DocumentScan;
use DateTimeImmutable;
use UnexpectedValueException;

class ProfileProjection
{
    public const VERSION = '1.0';

    /** @return array{records: array<string, array>, unresolved: array, rejected: array} */
    public function validate(DocumentScan $scan, array $response, array $schema, int $start, int $end): array
    {
        if (($response['contract_version'] ?? null) !== self::VERSION
            || ($response['schema_hash'] ?? null) !== $scan->profile_schema_hash
            || ! is_array($response['records'] ?? null) || ! array_is_list($response['records'])
            || ! is_array($response['unresolved'] ?? null) || ! array_is_list($response['unresolved'])) {
            throw new UnexpectedValueException('The profile response contract or schema hash is invalid.');
        }

        $fields = array_column($schema['fields'], null, 'path');
        if (count($fields) !== count($schema['fields'])) {
            throw new UnexpectedValueException('The stored profile schema has duplicate paths.');
        }
        $finding = $scan->findings;
        $owners = $finding->record_ownership['findings'] ?? [];
        $observations = $finding->observations;
        $entities = array_column($observations['entities'], null, 'id');
        $evidence = [];
        foreach (['fields', 'tables', 'content_blocks'] as $group) {
            foreach ($observations[$group] as $item) {
                $evidence[$item['id']] = $item['evidence']['page_number'];
            }
        }
        $plans = array_column($finding->record_ownership['records'] ?? [], null, 'id');
        $records = [];
        $unresolved = $response['unresolved'];
        $rejected = [];
        foreach ($response['records'] as $record) {
            $ref = $record['record_ref'] ?? null;
            if (! is_string($ref) || ! isset($plans[$ref]) || isset($records[$ref])
                || ! collect($plans[$ref]['regions'])->contains(fn (array $region): bool => $region['page_number'] >= $start && $region['page_number'] <= $end)
                || ! is_array($record['values'] ?? null) || ! array_is_list($record['values'])
                || ! is_array($record['warnings'] ?? null) || ! array_is_list($record['warnings'])) {
                $unresolved[] = ['warning' => 'A structurally invalid profile record was rejected.'];
                if (is_string($ref) && isset($plans[$ref])) {
                    $rejected[] = $ref;
                }

                continue;
            }
            $values = [];
            $warnings = $record['warnings'];
            $seen = [];
            $rows = [];
            foreach ($record['values'] as $value) {
                $path = $value['field_id'] ?? null;
                $descriptor = is_string($path) ? ($fields[$path] ?? null) : null;
                $row = $value['row_ref'] ?? null;
                $entity = $value['entity_ref'] ?? null;
                $state = $value['state'] ?? null;
                $ids = $value['evidence_ids'] ?? null;
                $key = json_encode([$path, $row], JSON_THROW_ON_ERROR);
                $issue = null;
                if ($descriptor === null) {
                    $issue = 'Unsupported field';
                } elseif (! is_string($entity) || ! isset($entities[$entity]) || ($owners[$entity] ?? null) !== $ref
                    || ! $this->roleMatches($descriptor['entity_role'], $entities[$entity]['role'] ?? null, $path)) {
                    $issue = 'Entity ownership or role mismatch';
                } elseif (($descriptor['cardinality'] === 'repeatable' && (! is_string($row) || trim($row) === '' || strlen($row) > 100))
                    || ($descriptor['cardinality'] === 'one' && $row !== null)) {
                    $issue = 'Invalid row reference';
                } elseif ($row !== null && isset($rows[explode('[].', $path, 2)[0]][$row])
                    && $descriptor['entity_role'] !== 'applicant'
                    && $rows[explode('[].', $path, 2)[0]][$row] !== $entity) {
                    $issue = 'Child row refers to different people';
                } elseif (! in_array($state, ['provided', 'ambiguous', 'unreadable'], true)) {
                    $issue = 'Invalid value state';
                } elseif (! is_array($ids) || ! array_is_list($ids) || $ids === []
                    || collect($ids)->contains(fn ($id) => ! is_string($id))
                    || count($ids) !== count(array_unique($ids))
                    || collect($ids)->contains(fn ($id) => ! isset($evidence[$id])
                        || ($owners[$id] ?? null) !== $ref || $evidence[$id] < $start || $evidence[$id] > $end)) {
                    $issue = 'Evidence ID, page, or record mismatch';
                } elseif ($state === 'provided' && (! array_key_exists('value', $value) || ! $this->validValue($value['value'], $descriptor))) {
                    $issue = 'Invalid field value';
                } elseif ($state !== 'provided' && ($value['value'] ?? null) !== null) {
                    $issue = 'Uncertain field has a provided value';
                } elseif (isset($seen[$key])) {
                    $issue = 'Duplicate or conflicting field value';
                }
                if ($issue !== null) {
                    $warnings[] = ['field_id' => $path, 'row_ref' => $row, 'issue' => $issue];

                    continue;
                }
                $seen[$key] = true;
                if ($row !== null && $descriptor['entity_role'] !== 'applicant') {
                    $rows[explode('[].', $path, 2)[0]][$row] = $entity;
                }
                $values[] = [
                    'field_id' => $path, 'entity_ref' => $entity, 'row_ref' => $row,
                    'state' => $state, 'value' => $value['value'] ?? null, 'evidence_ids' => $ids,
                ];
            }
            $records[$ref] = ['record_ref' => $ref, 'values' => $values, 'warnings' => $warnings];
        }

        return ['records' => $records, 'unresolved' => $unresolved, 'rejected' => $rejected];
    }

    public function roleMatches(string $role, mixed $sourceRole, string $path): bool
    {
        if (! is_string($sourceRole)) {
            return false;
        }
        $source = str_replace(['’', '`'], "'", strtolower(trim(preg_replace('/\s+/u', ' ', $sourceRole) ?? '')));
        if (str_starts_with($path, 'relationships[].')) {
            $related = $this->relatedRole($source);

            return str_contains($path, '.related_person.') ? $related : $related || $this->applicantRole($source);
        }

        return match ($role) {
            'applicant' => $this->applicantRole($source),
            'related_person' => $this->relatedRole($source),
            'pep_associate' => in_array($source, ['pep', 'politically exposed person', 'pep associate'], true),
            default => false,
        };
    }

    private function applicantRole(string $role): bool
    {
        return in_array($role, ['applicant', 'primary applicant', 'holder', 'owner', 'subject'], true);
    }

    private function relatedRole(string $role): bool
    {
        $exact = ['spouse', 'husband', 'wife', 'father', 'mother', 'parent', 'son', 'daughter', 'child', 'brother', 'sister', 'sibling', 'next of kin', 'nok', 'kin', 'relative', 'related person'];
        if (in_array($role, $exact, true)) {
            return true;
        }

        return preg_match("/^(?:applicant|primary applicant)'s (?:".implode('|', array_map(fn (string $name): string => str_replace(' ', '\\s+', $name), $exact)).')$/u', $role) === 1;
    }

    public function repairBrokenFfLigature(string $value): string
    {
        // shortcut: a lone Ư is the broken ff ligature in these PDFs; leave a value that has any other non-ASCII letter.
        if (preg_match('/[Ưư]/u', $value) !== 1) {
            return $value;
        }
        $rest = preg_replace('/[Ưư]/u', '', $value);
        if (! is_string($rest) || preg_match('/[^\x00-\x7F]/', $rest) === 1) {
            return $value;
        }

        return preg_replace('/\s*[Ưư]/u', 'ff', $value) ?? $value;
    }

    public function isAddressLinePath(string $path): bool
    {
        return preg_match('/(?:^|\.)((?:employer_)?address_line_[12]|line_[12]|branch_address|employer_address)$/', $path) === 1;
    }

    public function stripAddressLineCommas(string $value): string
    {
        if (! str_contains($value, ',')) {
            return $value;
        }

        return trim(preg_replace('/\s+/u', ' ', str_replace(',', ' ', $value)) ?? $value);
    }

    public function isPhonePath(string $path): bool
    {
        return str_contains($path, 'contacts[mobile]')
            || str_contains($path, 'contacts[home]')
            || str_ends_with($path, '.mobile')
            || str_ends_with($path, '.home_telephone')
            || str_ends_with($path, '.general_telephone')
            || str_ends_with($path, '.direct_telephone')
            || str_ends_with($path, '.fax');
    }

    public function stripPhonePunctuation(string $value): string
    {
        return preg_replace('/[^\d+]/', '', $value) ?? $value;
    }

    public function dayFirstDate(string $value): ?string
    {
        // shortcut: these forms print day-first dates; accept month-first only if a document uses it.
        if (preg_match('/^(\d{1,2})[\/\-\s]+(\d{1,2})[\/\-\s]+(\d{4})$/', trim($value), $match) !== 1 || ! checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $match[3], (int) $match[2], (int) $match[1]);
    }

    public function validValue(mixed $value, array $field): bool
    {
        return match ($field['type']) {
            'boolean' => is_bool($value),
            'date' => is_string($value) && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false
                && $date->format('Y-m-d') === $value
                && (! str_contains($field['validation'], 'before-or-equal:today') || $value <= now()->toDateString()),
            'decimal' => is_string($value) && preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $value) === 1,
            'enum' => is_string($value) && in_array($value, $field['allowed_values'], true),
            'array' => is_array($value) && array_is_list($value)
                && ! collect($value)->contains(fn ($item) => ! is_string($item) || ! in_array($item, $field['allowed_values'], true))
                && count($value) === count(array_unique($value)),
            'string' => $this->validString($value, $field['validation']),
            default => false,
        };
    }

    private function validString(mixed $value, string $validation): bool
    {
        if (! is_string($value) || trim($value) === ''
            || mb_strlen($value) > (preg_match('/max:(\d+)/', $validation, $limit) ? (int) $limit[1] : 2000)) {
            return false;
        }

        return (! str_contains($validation, 'email') || filter_var($value, FILTER_VALIDATE_EMAIL) !== false)
            && (! str_contains($validation, 'phone:') || preg_match('/^[+0-9\s\-()]{6,30}$/D', $value) === 1)
            && (! str_contains($validation, 'two-letter-country') || preg_match('/^[A-Za-z]{2}$/D', $value) === 1)
            && (! str_contains($validation, 'registration-format') || preg_match('/^[A-Za-z0-9\s\-\/().]+$/D', $value) === 1)
            && (! str_contains($validation, 'account-format') || preg_match('/^[A-Za-z0-9][A-Za-z0-9\s-]*$/D', $value) === 1);
    }
}
