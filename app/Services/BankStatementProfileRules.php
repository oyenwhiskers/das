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
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $mailing
     * @param  array<string, mixed>  $employment
     * @return array<string, mixed>
     */
    public static function copyMailingIntoEmploymentIfBlank(array $employment, array $mailing): array
    {
        if (! self::hasAddress($mailing)) {
            return $employment;
        }
        $map = [
            'address_line_1' => 'line_1',
            'address_line_2' => 'line_2',
            'postcode' => 'postcode',
            'city' => 'city',
            'state' => 'state',
            'country' => 'country',
        ];
        foreach (array_keys($map) as $field) {
            if (filled($employment[$field] ?? null)) {
                return $employment;
            }
        }
        foreach ($map as $employmentField => $mailField) {
            $value = $mailing[$mailField] ?? '';
            if (filled($value)) {
                $employment[$employmentField] = is_string($value) ? trim($value) : $value;
            }
        }

        return $employment;
    }
}
