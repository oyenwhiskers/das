<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DocumentScan;

class BankStatementProfileRules
{
    public const SELF_EMPLOYED_OCCUPATION = 'ENTREPRENEUR';

    public const SELF_EMPLOYED_EMPLOYER_NAME = 'OWNER';

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
     * Clear applicant employment enum from status and/or arrangement. Prefers arrangement when both set.
     *
     * @return 'employed'|'self_employed'|'retired'|'other'|null
     */
    public static function clearEmploymentKind(?string $status, ?string $arrangement): ?string
    {
        $allowed = ['employed', 'self_employed', 'retired', 'other'];
        $arrangement = strtolower(trim((string) $arrangement));
        if (in_array($arrangement, $allowed, true)) {
            return $arrangement;
        }
        $status = strtolower(trim((string) $status));
        if (in_array($status, $allowed, true)) {
            return $status;
        }

        return null;
    }

    /**
     * Applicant employment kind: keep a clear tick; when unclear, self-employed wording → self_employed,
     * employer filled → employed, else leave unset (do not invent self_employed).
     *
     * @return 'employed'|'self_employed'|'retired'|'other'|null
     */
    public static function resolveApplicantEmploymentKind(
        ?string $status,
        ?string $arrangement,
        ?string $employerName,
        ?string $occupation = null,
    ): ?string {
        $clear = self::clearEmploymentKind($status, $arrangement);
        if ($clear !== null) {
            return $clear;
        }
        if (self::relatedLooksSelfEmployed($occupation, $employerName)) {
            return 'self_employed';
        }

        return filled(trim((string) $employerName)) ? 'employed' : null;
    }

    /**
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed}
     */
    public static function addressParts(?object $address): array
    {
        return [
            'line_1' => $address?->line_1 ?? '',
            'line_2' => $address?->line_2 ?? '',
            'postcode' => $address?->postcode ?? '',
            'city' => $address?->city ?? '',
            'state' => $address?->state ?? '',
            'country' => $address?->country ?? '',
        ];
    }

    /**
     * Effective mailing parts (same_as_residential → residential). Empty when none.
     *
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed}
     */
    public static function effectiveMailingParts(Customer $customer): array
    {
        $customer->loadMissing(['mailingAddress', 'residentialAddress']);
        $mailing = $customer->mailingAddress;
        if ($mailing?->same_as_residential === true) {
            return self::addressParts($customer->residentialAddress);
        }

        return self::addressParts($mailing);
    }

    /**
     * Self-employed employer/office source: mailing if present, else residential.
     *
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $mailing
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $residential
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed}
     */
    public static function preferredSelfEmployedAddressParts(array $mailing, array $residential): array
    {
        return self::hasAddress($mailing) ? [
            'line_1' => $mailing['line_1'] ?? '',
            'line_2' => $mailing['line_2'] ?? '',
            'postcode' => $mailing['postcode'] ?? '',
            'city' => $mailing['city'] ?? '',
            'state' => $mailing['state'] ?? '',
            'country' => $mailing['country'] ?? '',
        ] : [
            'line_1' => $residential['line_1'] ?? '',
            'line_2' => $residential['line_2'] ?? '',
            'postcode' => $residential['postcode'] ?? '',
            'city' => $residential['city'] ?? '',
            'state' => $residential['state'] ?? '',
            'country' => $residential['country'] ?? '',
        ];
    }

    /**
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed}
     */
    public static function preferredSelfEmployedAddressFromCustomer(Customer $customer): array
    {
        $customer->loadMissing(['mailingAddress', 'residentialAddress']);

        return self::preferredSelfEmployedAddressParts(
            self::effectiveMailingParts($customer),
            self::addressParts($customer->residentialAddress),
        );
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

    /** Self-employed: blank employer general phone ← applicant mobile. */
    public static function copyMobileIntoEmploymentPhoneIfBlank(array $employment, ?string $mobile): array
    {
        if (filled($employment['general_telephone'] ?? null) || blank($mobile)) {
            return $employment;
        }
        $employment['general_telephone'] = trim((string) $mobile);

        return $employment;
    }

    /** Self-employed: blank employer name ← OWNER. Supports employer_name or employerName. */
    public static function copyOwnerIntoEmployerNameIfBlank(array $fields): array
    {
        $key = array_key_exists('employerName', $fields) ? 'employerName' : 'employer_name';
        if (filled($fields[$key] ?? null)) {
            return $fields;
        }
        $fields[$key] = self::SELF_EMPLOYED_EMPLOYER_NAME;

        return $fields;
    }

    public static function relatedLooksSelfEmployed(?string $occupation, ?string $employerName = null): bool
    {
        $occupation = strtolower(trim((string) $occupation));
        $employerName = strtolower(trim((string) $employerName));
        $blob = trim($occupation.' '.$employerName);
        if ($blob === '') {
            return false;
        }
        if (preg_match('/\b(self[-\s]?employed?|self[-\s]?working|own business|business owner|sole prop(?:rietor)?|freelancer|entrepreneur|enterpreneur|berniaga|bekerja sendiri|kerja sendiri)\b/u', $blob) === 1) {
            return true;
        }

        // Form-3 shorthand occupations that usually mean self-employed.
        return in_array($occupation, ['business', 'bisnes', 'trader', 'trading'], true);
    }

    /**
     * Letterhead / HQ / registered-office text is not the account branch.
     * Statements often print HQ at the top and the real Branch/Cawangan lower down.
     */
    public static function looksLikeBankHeadquarters(mixed $branch, mixed $evidence = null): bool
    {
        $blob = self::normalize(trim((string) $evidence).' '.trim((string) $branch));
        if ($blob === '') {
            return false;
        }
        if (preg_match('/\b(hq|h q|head office|headquarters|ibu pejabat|pejabat utama|registered office|pejabat berdaftar|corporate hq|corporate head office)\b/u', $blob) === 1) {
            return true;
        }
        foreach ([
            'menara maybank', 'jalan tun perak', 'menara cimb', 'menara public bank',
            'menara rhb', 'menara hong leong', 'menara alliance', 'menara mbsb',
        ] as $landmark) {
            if (str_contains($blob, $landmark)) {
                return true;
            }
        }

        // Floor/level + tower/menara is typical HQ letterhead, not a retail branch name.
        return preg_match('/\b(level|lvl|floor|tingkat)\s*\d+\b/u', $blob) === 1
            && preg_match('/\b(menara|tower|wisma)\b/u', $blob) === 1;
    }

    /**
     * Statement branch: keep only when evidence/value clearly marks the account Branch/Cawangan,
     * not HQ letterhead, mailing address, or bank name. Unsure → null.
     *
     * @param  array{line_1?: mixed, line_2?: mixed, postcode?: mixed, city?: mixed, state?: mixed, country?: mixed}  $mailing
     */
    public static function confidentStatementBranch(
        mixed $branch,
        mixed $evidence = null,
        array $mailing = [],
        mixed $bankName = null,
    ): ?string {
        $branch = trim((string) $branch);
        if ($branch === '') {
            return null;
        }
        $normBranch = self::normalize($branch);
        if ($normBranch === '' || $normBranch === self::normalize($bankName)) {
            return null;
        }
        if (self::looksLikeBankHeadquarters($branch, $evidence)) {
            return null;
        }
        $mailingBits = [];
        foreach (['line_1', 'line_2', 'postcode', 'city', 'state', 'country'] as $key) {
            $part = self::normalize($mailing[$key] ?? '');
            if ($part !== '') {
                $mailingBits[] = $part;
            }
        }
        if (in_array($normBranch, $mailingBits, true)) {
            return null;
        }
        $composed = trim(implode(' ', $mailingBits));
        if ($composed !== '' && (
            $normBranch === $composed
            || str_contains($composed, $normBranch)
            || str_contains($normBranch, $composed)
        )) {
            return null;
        }
        $cue = '/\b(branch|cawangan|kod\s*cawangan|branch\s*(?:name|code|address|no\.?|number)?)\b/ui';
        if (preg_match($cue, trim((string) $evidence).' '.$branch) !== 1) {
            return null;
        }

        return $branch;
    }

    /**
     * Drop uncertain statement branch_address values from an extracted value list.
     *
     * @param  list<array<string, mixed>>  $values
     * @return list<array<string, mixed>>
     */
    public static function dropUncertainStatementBranches(array $values): array
    {
        $mailingByRow = [];
        $bankByRow = [];
        foreach ($values as $value) {
            $field = is_string($value['field_id'] ?? null) ? $value['field_id'] : '';
            $row = is_string($value['row_ref'] ?? null) ? $value['row_ref'] : '';
            if (($value['state'] ?? null) !== 'provided' || ! is_string($value['value'] ?? null)) {
                continue;
            }
            if (preg_match('/^addresses\[mailing\]\.(.+)$/', $field, $match) === 1) {
                $mailingByRow[''][$match[1]] = $value['value'];
            }
            if ($field === 'bank_accounts[].bank_name') {
                $bankByRow[$row] = $value['value'];
            }
        }
        $kept = [];
        foreach ($values as $value) {
            if (($value['field_id'] ?? null) !== 'bank_accounts[].branch_address'
                || ($value['state'] ?? null) !== 'provided') {
                $kept[] = $value;

                continue;
            }
            $row = is_string($value['row_ref'] ?? null) ? $value['row_ref'] : '';
            $confident = self::confidentStatementBranch(
                $value['value'] ?? null,
                $value['evidence_text'] ?? null,
                $mailingByRow[''] ?? [],
                $bankByRow[$row] ?? null,
            );
            if ($confident === null) {
                continue;
            }
            $value['value'] = $confident;
            $kept[] = $value;
        }

        return $kept;
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
