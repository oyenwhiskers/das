<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentScan;

class BankStatementProfileRules
{
    public static function isStatementScan(?DocumentScan $scan): bool
    {
        if ($scan === null) {
            return false;
        }

        return self::isStatementLabel($scan->document_type, $scan->document_title);
    }

    public static function isStatementLabel(?string $type, ?string $title): bool
    {
        $label = strtolower(trim((string) $type.' '.(string) $title));

        return str_contains($label, 'bank statement') || str_contains($label, 'passbook');
    }

    public static function normalize(mixed $value): string
    {
        $text = strtolower(trim((string) $value));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $parts */
    public static function hasAddress(array $parts): bool
    {
        $line = self::normalize(($parts['line_1'] ?? '').' '.($parts['line_2'] ?? ''));
        $postCity = self::normalize(($parts['postcode'] ?? '').' '.($parts['city'] ?? ''));

        return $line !== '' || $postCity !== '';
    }

    /**
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $left
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $right
     */
    public static function addressesEqual(array $left, array $right): bool
    {
        if (! self::hasAddress($left) || ! self::hasAddress($right)) {
            return false;
        }
        foreach (['line_1', 'line_2', 'postcode', 'city', 'state', 'country'] as $key) {
            if (self::normalize($left[$key] ?? '') !== self::normalize($right[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    public static function customerIsSelfEmployed(Customer $customer, ?string $arrangement = null): bool
    {
        if ($customer->employment_status === 'self_employed') {
            return true;
        }
        if ($arrangement === 'self_employed') {
            return true;
        }
        $primary = $customer->employments
            ->first(fn ($employment): bool => (bool) $employment->is_primary && (bool) $employment->is_current)
            ?? $customer->employments->first(fn ($employment): bool => (bool) $employment->is_current)
            ?? $customer->employments->first();

        return $primary?->arrangement === 'self_employed';
    }

    /**
     * Self-employed: fill blank office/business address parts from a source address.
     *
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $source
     * @param  array<string, mixed>  $employment
     * @return array<string, mixed>
     */
    public static function copyAddressIntoEmploymentBlanks(array $employment, array $source): array
    {
        if (! self::hasAddress($source)) {
            return $employment;
        }
        foreach ([
            'address_line_1' => 'line_1',
            'address_line_2' => 'line_2',
            'postcode' => 'postcode',
            'city' => 'city',
            'state' => 'state',
            'country' => 'country',
        ] as $employmentField => $sourceField) {
            if (filled($employment[$employmentField] ?? null)) {
                continue;
            }
            $value = $source[$sourceField] ?? '';
            if (filled($value)) {
                $employment[$employmentField] = is_string($value) ? trim($value) : $value;
            }
        }

        return $employment;
    }

    /**
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $mailing
     * @param  array<string, mixed>  $employment
     * @return array<string, mixed>
     */
    public static function copyMailingIntoEmploymentIfBlank(array $employment, array $mailing): array
    {
        return self::copyAddressIntoEmploymentBlanks($employment, $mailing);
    }

    public static function relatedLooksSelfEmployed(?string $occupation, ?string $employerName = null): bool
    {
        $occupation = strtolower(trim((string) $occupation));
        $employerName = strtolower(trim((string) $employerName));
        $blob = trim($occupation.' '.$employerName);
        if ($blob === '') {
            return false;
        }
        if (preg_match('/\b(self[-\s]?employed?|own business|business owner|sole prop(?:rietor)?|freelancer|berniaga|kerja sendiri)\b/u', $blob) === 1) {
            return true;
        }

        // Form-3 shorthand occupations that usually mean self-employed.
        return in_array($occupation, ['business', 'bisnes', 'trader', 'trading'], true);
    }

    /**
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $source
     * @param  array<string, mixed>  $person  camelCase (employerAddressLine1) or snake (employer_address_line_1)
     * @return array<string, mixed>
     */
    public static function copyAddressIntoRelatedEmployerBlanks(array $person, array $source): array
    {
        if (! self::hasAddress($source)) {
            return $person;
        }
        $camel = array_key_exists('employerAddressLine1', $person)
            || array_key_exists('employerCity', $person)
            || array_key_exists('occupation', $person)
            || array_key_exists('employerName', $person);
        $map = $camel
            ? [
                'employerAddressLine1' => 'line_1',
                'employerAddressLine2' => 'line_2',
                'employerPostcode' => 'postcode',
                'employerCity' => 'city',
                'employerState' => 'state',
                'employerCountry' => 'country',
            ]
            : [
                'employer_address_line_1' => 'line_1',
                'employer_address_line_2' => 'line_2',
                'employer_postcode' => 'postcode',
                'employer_city' => 'city',
                'employer_state' => 'state',
                'employer_country' => 'country',
            ];
        foreach ($map as $personField => $sourceField) {
            if (filled($person[$personField] ?? null)) {
                continue;
            }
            $value = $source[$sourceField] ?? '';
            if (filled($value)) {
                $person[$personField] = is_string($value) ? trim($value) : $value;
            }
        }

        return $person;
    }
}
