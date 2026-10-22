<?php

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerContact;
use App\Models\CustomerIdentification;
use App\Models\CustomerProfileSchemaSnapshot;
use App\Models\ExtractedProfile;
use Illuminate\Support\Collection;

class ProfileMergePreview
{
    /** @param  array<string, string>  $targets
     * @param  list<string>  $excluded
     * @return array<string, mixed> */
    public function make(ExtractedProfile $profile, Customer $customer, string $strategy, array $targets, array $excluded): array
    {
        $profile->loadMissing('documentScan');
        $customer->load([
            'identifications', 'contacts', 'addresses', 'relationships.relatedPerson', 'employments', 'bankAccounts',
            'taxIdentification', 'taxResidencies', 'fatcaDeclarations', 'financialDeclarations', 'investmentProfiles',
            'brokerageDeclarations', 'brokerageAccounts', 'complianceDeclarations', 'pepAssociations.relatedPerson',
        ]);
        $labels = collect(CustomerProfileSchemaSnapshot::query()->where('hash', $profile->field_schema_hash)->sole()->schema()['fields'])->keyBy('path');
        $provided = collect($profile->reviewed_projection['values'])->filter(fn (array $value): bool => $value['state'] === 'provided')->values();
        if ($strategy === 'replace_all') {
            $have = $provided->pluck('field_id')->all();
            foreach ($labels as $path => $field) {
                if (! is_string($path) || in_array($path, $have, true) || $this->repeatable($this->module($path))) {
                    continue;
                }
                if (! in_array($this->module($path), ['personal', 'contacts', 'addresses', 'employment_status', 'tin'], true)) {
                    continue;
                }
                $current = $this->current($customer, $path, null);
                if ($this->isEmpty($current)) {
                    continue;
                }
                $provided->push([
                    'field_id' => $path, 'row_ref' => null, 'state' => 'provided', 'value' => null, 'evidence_ids' => [],
                ]);
            }
        }
        $byRow = $provided->groupBy(fn (array $value): string => $this->rowKey($value));
        $resolved = [];
        foreach ($byRow as $key => $values) {
            $module = $this->module($values[0]['field_id']);
            if ($this->repeatable($module)) {
                $resolved[$key] = $this->resolveRow($customer, $module, $values->all(), $targets[$key] ?? '');
            }
        }
        $lines = [];
        $currents = [];
        foreach ($provided as $value) {
            $module = $this->module($value['field_id']);
            $key = $this->lineKey($value);
            $rowKey = $this->rowKey($value);
            $target = $resolved[$rowKey]['target'] ?? null;
            $current = $this->current($customer, $value['field_id'], $target);
            $proposed = $value['value'];
            $action = $this->action($current, $proposed, $strategy, (string) $value['field_id']);
            if (is_string($current) && is_string($proposed) && ! str_contains(strtolower((string) $value['field_id']), 'email') && $current !== $proposed && mb_strtoupper($current, 'UTF-8') === mb_strtoupper($proposed, 'UTF-8') && ! $this->isIdentificationNumberField((string) $value['field_id'])) {
                $action = 'replace';
            }
            $included = in_array($action, ['add', 'replace', 'clear'], true)
                && ! in_array($key, $excluded, true)
                && ! in_array('module:'.$module, $excluded, true)
                && ! $this->kindExcluded((string) $value['field_id'], $excluded);
            $lines[] = [
                'key' => $key,
                'field_id' => $value['field_id'],
                'label' => $labels[$value['field_id']]['label'] ?? $value['field_id'],
                'row_ref' => $value['row_ref'],
                'row_key' => $this->repeatable($module) ? $rowKey : null,
                'action' => $action,
                'included' => $included,
                'current' => $this->display($value['field_id'], $current),
                'proposed' => $this->display($value['field_id'], $proposed),
                'evidence_ids' => $value['evidence_ids'],
                'raw_current' => $current,
            ];
            $currents[] = [$value['field_id'], $value['row_ref'], $target, $this->canonical($current)];
        }
        $rows = [];
        foreach ($resolved as $key => $row) {
            $blocked = $row['blocked'];
            if ($blocked === null && $row['target'] === 'new') {
                $blocked = $this->missingRequired($key, $byRow[$key]->all(), $customer, null);
            }
            if ($blocked === null && is_int($row['target'])) {
                $blocked = $this->missingRequired($key, $byRow[$key]->all(), $customer, $row['target']);
            }
            $rows[] = ['key' => $key, 'target' => $row['target'], 'ambiguous' => $row['ambiguous'], 'blocked' => $blocked, 'options' => $row['options']];
            $resolved[$key]['blocked'] = $blocked;
        }
        $identity = $this->identityBlock($provided->all(), $customer);
        $operations = $identity === null ? $this->operations($customer, $profile, $lines, $provided->all(), $resolved) : [];

        return [
            'identity' => $identity,
            'hints' => $this->hints($provided->all(), $customer),
            'lines' => $lines,
            'rows' => $rows,
            'ready' => $identity === null,
            'baseline' => hash('sha256', json_encode([$profile->id, $profile->revision, $strategy, $identity, $targets, $excluded, $currents], JSON_THROW_ON_ERROR)),
            'operations' => $operations,
        ];
    }

    /** @param  list<array<string, mixed>>  $values */
    private function identityBlock(array $values, Customer $customer): ?string
    {
        $type = $this->proposed($values, 'primary_identification.type');
        $number = $this->proposed($values, 'primary_identification.number');
        $identification = $customer->identifications->firstWhere('is_primary', true) ?? $customer->primaryIdentification;
        $customerType = $identification?->type?->value ?? $customer->primary_identification_type?->value;
        $customerNumber = $identification?->number ?? $customer->primary_identification_number;
        if (! is_string($type) || ! is_string($number) || $type === '' || $number === '' || $customerType === null || blank($customerNumber)) {
            return null;
        }
        if ($type !== $customerType || CustomerIdentification::normalizeNumber($number) !== CustomerIdentification::normalizeNumber((string) $customerNumber)) {
            return 'Primary identification does not match this customer.';
        }

        return null;
    }

    /** @param  list<array<string, mixed>>  $values
     * @return list<string> */
    private function hints(array $values, Customer $customer): array
    {
        $hints = [];
        $name = $this->proposed($values, 'customer.full_legal_name');
        if (is_string($name) && $this->canonical($name) !== '' && $this->canonical($name) === $this->canonical($customer->full_legal_name)) {
            $hints[] = 'The name matches this customer. A name match does not confirm identity.';
        }
        $email = $this->proposed($values, 'contacts[email].value');
        if (is_string($email) && strcasecmp($email, (string) $customer->primary_email) === 0) {
            $hints[] = 'The email matches this customer. An email match does not confirm identity.';
        }
        $mobile = $this->proposed($values, 'contacts[mobile].value');
        if (is_string($mobile) && $customer->primary_mobile && preg_replace('/\D/', '', $mobile) === preg_replace('/\D/', '', $customer->primary_mobile)) {
            $hints[] = 'The mobile number matches this customer. A mobile match does not confirm identity.';
        }

        return $hints;
    }

    /** @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $provided
     * @param  array<string, array<string, mixed>>  $resolved
     * @return list<array<string, mixed>> */
    private function operations(Customer $customer, ExtractedProfile $profile, array $lines, array $provided, array $resolved): array
    {
        $included = collect($lines)->where('included', true);
        $proposed = collect($provided)->keyBy(fn (array $value): string => $this->lineKey($value));
        $operations = [];
        $statement = BankStatementProfileRules::isStatementScan($profile->documentScan);
        $changesFor = function (string $module, ?string $rowKey = null) use ($included, $proposed): array {
            return $included->filter(function (array $line) use ($module, $rowKey): bool {
                return $this->module($line['field_id']) === $module && $line['row_key'] === $rowKey;
            })->map(fn (array $line): array => [
                'field_id' => $line['field_id'], 'row_ref' => $line['row_ref'], 'result' => $line['action'],
                'old' => $line['raw_current'],
                'new' => $proposed[$line['key']]['value'], 'evidence_ids' => $line['evidence_ids'],
            ])->values()->all();
        };
        if ($changes = $changesFor('personal')) {
            $operations[] = ['type' => 'personal', 'payload' => $this->overlay($this->personal($customer), $included, $proposed, 'personal'), 'changes' => $changes];
        }
        if ($changes = $changesFor('contacts')) {
            $operations[] = ['type' => 'contact', 'payload' => $this->overlay($this->contacts($customer), $included, $proposed, 'contacts'), 'changes' => $changes];
        }
        $addressPayload = null;
        if ($changes = $changesFor('addresses')) {
            $addressPayload = $this->overlay($this->addresses($customer), $included, $proposed, 'addresses');
            if ($statement) {
                $addressPayload = $this->applyStatementMailingSameAs($addressPayload);
            }
            $operations[] = ['type' => 'address', 'payload' => $addressPayload, 'changes' => $changes];
        }
        $proposedEmployer = $this->proposedModuleValue($included, $proposed, 'employments[].employer_name');
        $proposedArrangement = $this->proposedModuleValue($included, $proposed, 'employments[].arrangement');
        $proposedOccupation = $this->proposedModuleValue($included, $proposed, 'employments[].occupation');
        if ($changes = $changesFor('employment_status')) {
            $statusPayload = $this->overlay($this->employmentStatus($customer), $included, $proposed, 'employment_status');
            $statusKind = BankStatementProfileRules::resolveApplicantEmploymentKind(
                is_string($statusPayload['employmentStatus'] ?? null) ? $statusPayload['employmentStatus'] : null,
                is_string($proposedArrangement) ? $proposedArrangement : null,
                is_string($proposedEmployer) ? $proposedEmployer : null,
                is_string($proposedOccupation) ? $proposedOccupation : null,
            );
            if ($statusKind !== null && BankStatementProfileRules::clearEmploymentKind(
                is_string($statusPayload['employmentStatus'] ?? null) ? $statusPayload['employmentStatus'] : null,
                is_string($proposedArrangement) ? $proposedArrangement : null,
            ) === null) {
                $statusPayload['employmentStatus'] = $statusKind;
            }
            $operations[] = ['type' => 'employment_status', 'payload' => $statusPayload, 'changes' => $changes];
        }
        $addressBase = $addressPayload ?? $this->addresses($customer);
        $residentialParts = $this->residentialPartsFromPayload($addressBase, $customer);
        $mailingParts = $this->mailingPartsFromPayload($addressBase, $customer);
        $selfEmployedAddress = BankStatementProfileRules::preferredSelfEmployedAddressParts($mailingParts, $residentialParts);
        foreach ($resolved as $rowKey => $row) {
            $module = explode('|', $rowKey, 2)[0];
            $changes = $changesFor($module, $rowKey);
            if ($changes === [] || $row['target'] === null || $row['blocked'] !== null) {
                continue;
            }
            $id = $row['target'] === 'new' ? null : $row['target'];
            $payload = $this->rowPayload($customer, $module, $id, $included, $proposed, $rowKey);
            if ($module === 'employments') {
                $status = $this->proposedModuleValue($included, $proposed, 'customer.employment_status')
                    ?? $customer->employment_status;
                $arrangement = is_string($payload['arrangement'] ?? null) ? $payload['arrangement'] : null;
                $employer = is_string($payload['employer_name'] ?? null) ? $payload['employer_name'] : null;
                $occupation = is_string($payload['occupation'] ?? null) ? $payload['occupation'] : null;
                $kind = BankStatementProfileRules::resolveApplicantEmploymentKind($status, $arrangement, $employer, $occupation);
                if ($kind !== null && BankStatementProfileRules::clearEmploymentKind($status, $arrangement) === null) {
                    $payload['arrangement'] = $kind;
                }
                if ($kind === 'self_employed') {
                    $payload['occupation'] = BankStatementProfileRules::SELF_EMPLOYED_OCCUPATION;
                    $payload = BankStatementProfileRules::copyAddressIntoEmploymentBlanks($payload, $selfEmployedAddress);
                    $mobile = $this->proposedModuleValue($included, $proposed, 'contacts[mobile].value')
                        ?? $customer->contacts->firstWhere('type', ContactType::Mobile)?->value
                        ?? $customer->primary_mobile;
                    $payload = BankStatementProfileRules::copyMobileIntoEmploymentPhoneIfBlank(
                        $payload,
                        is_string($mobile) ? $mobile : null,
                    );
                }
            }
            if ($module === 'relationships'
                && BankStatementProfileRules::relatedLooksSelfEmployed(
                    is_string($payload['occupation'] ?? null) ? $payload['occupation'] : null,
                    is_string($payload['employerName'] ?? null) ? $payload['employerName'] : null,
                )) {
                $payload['occupation'] = BankStatementProfileRules::SELF_EMPLOYED_OCCUPATION;
                $payload = BankStatementProfileRules::copyAddressIntoRelatedEmployerBlanks($payload, $selfEmployedAddress);
            }
            $operations[] = ['type' => $module, 'id' => $id, 'payload' => $payload, 'changes' => $changes];
        }
        foreach (['tin', 'fatca', 'financial', 'investment', 'brokerage_declaration'] as $module) {
            if ($changes = $changesFor($module)) {
                $operations[] = ['type' => $module, 'payload' => $this->singularPayload($customer, $module, $included, $proposed), 'id' => $this->singularId($customer, $module), 'changes' => $changes];
            }
        }
        foreach (['pep', 'bankruptcy', 'petition'] as $kind) {
            if ($changes = $changesFor('compliance:'.$kind)) {
                $operations[] = ['type' => 'compliance', 'kind' => $kind, 'payload' => $this->compliancePayload($customer, $kind, $included, $proposed), 'changes' => $changes];
            }
        }

        return $operations;
    }

    /** @param  array<string, mixed>  $payload
     * @return array<string, mixed> */
    private function applyStatementMailingSameAs(array $payload): array
    {
        $residential = [
            'line_1' => $payload['residential_line_1'] ?? '',
            'line_2' => $payload['residential_line_2'] ?? '',
            'postcode' => $payload['residential_postcode'] ?? '',
            'city' => $payload['residential_city'] ?? '',
            'state' => $payload['residential_state'] ?? '',
            'country' => $payload['residential_country'] ?? '',
        ];
        $mailing = [
            'line_1' => $payload['mailing_line_1'] ?? '',
            'line_2' => $payload['mailing_line_2'] ?? '',
            'postcode' => $payload['mailing_postcode'] ?? '',
            'city' => $payload['mailing_city'] ?? '',
            'state' => $payload['mailing_state'] ?? '',
            'country' => $payload['mailing_country'] ?? '',
        ];
        if (! BankStatementProfileRules::hasAddress($mailing)) {
            return $payload;
        }
        if (! BankStatementProfileRules::hasAddress($residential)) {
            $payload['same_as_residential'] = false;

            return $payload;
        }
        if (BankStatementProfileRules::addressesEqual($residential, $mailing)) {
            $payload['same_as_residential'] = true;
            foreach (['mailing_line_1', 'mailing_line_2', 'mailing_postcode', 'mailing_city', 'mailing_state', 'mailing_country'] as $key) {
                $payload[$key] = '';
            }

            return $payload;
        }
        $payload['same_as_residential'] = false;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $addressPayload
     * @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed}
     */
    /** @param  array<string, mixed>  $addressPayload
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed} */
    private function residentialPartsFromPayload(array $addressPayload, Customer $customer): array
    {
        $parts = [
            'line_1' => $addressPayload['residential_line_1'] ?? '',
            'line_2' => $addressPayload['residential_line_2'] ?? '',
            'postcode' => $addressPayload['residential_postcode'] ?? '',
            'city' => $addressPayload['residential_city'] ?? '',
            'state' => $addressPayload['residential_state'] ?? '',
            'country' => $addressPayload['residential_country'] ?? '',
        ];
        if (BankStatementProfileRules::hasAddress($parts)) {
            return $parts;
        }

        return BankStatementProfileRules::addressParts(
            $customer->addresses->firstWhere('type', AddressType::Residential),
        );
    }

    /** @param  array<string, mixed>  $addressPayload
     * @return array{line_1: mixed, line_2: mixed, postcode: mixed, city: mixed, state: mixed, country: mixed} */
    private function mailingPartsFromPayload(array $addressPayload, Customer $customer): array
    {
        $parts = [
            'line_1' => $addressPayload['mailing_line_1'] ?? '',
            'line_2' => $addressPayload['mailing_line_2'] ?? '',
            'postcode' => $addressPayload['mailing_postcode'] ?? '',
            'city' => $addressPayload['mailing_city'] ?? '',
            'state' => $addressPayload['mailing_state'] ?? '',
            'country' => $addressPayload['mailing_country'] ?? '',
        ];
        if (BankStatementProfileRules::hasAddress($parts)) {
            return $parts;
        }
        if (($addressPayload['same_as_residential'] ?? false) === true) {
            return $this->residentialPartsFromPayload($addressPayload, $customer);
        }

        return BankStatementProfileRules::effectiveMailingParts($customer);
    }

    /** @param  array<string, mixed>  $base
     * @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     * @return array<string, mixed> */
    private function overlay(array $base, $included, $proposed, string $module): array
    {
        foreach ($included as $line) {
            if ($this->module($line['field_id']) !== $module) {
                continue;
            }
            $target = $this->inputKey($line['field_id']);
            if (is_array($target)) {
                $base[$target[0]][$target[1]] = $proposed[$line['key']]['value'];
            } else {
                $base[$target] = $proposed[$line['key']]['value'];
            }
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function personal(Customer $customer): array
    {
        $identification = $customer->identifications->firstWhere('is_primary', true);

        return [
            'salutation' => $customer->salutation ?? '',
            'full_legal_name' => $customer->full_legal_name ?? '',
            'identification_type' => $identification?->type?->value ?? $customer->primary_identification_type?->value ?? '',
            'identification_number' => $identification?->number ?? $customer->primary_identification_number ?? '',
            'issuing_jurisdiction' => $identification?->issuing_jurisdiction === 'UNSPECIFIED' ? '' : ($identification?->issuing_jurisdiction ?? ''),
            'old_nric' => $identification?->old_nric ?? '',
            'date_of_birth' => $customer->date_of_birth?->format('Y-m-d') ?? '',
            'gender' => $customer->gender ?? '',
            'nationality' => $customer->nationality ?? '',
            'country_of_residence' => $customer->country_of_residence ?? '',
            'race' => $customer->race ?? '',
            'marital_status' => $customer->marital_status ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function contacts(Customer $customer): array
    {
        $social = [];
        foreach (['facebook', 'instagram', 'twitter'] as $platform) {
            $social[$platform] = $customer->contacts->first(fn ($contact): bool => $contact->type === ContactType::Social && $contact->platform === $platform)?->value ?? '';
        }

        return [
            'mobile' => $customer->contacts->firstWhere('type', ContactType::Mobile)?->value ?? $customer->primary_mobile ?? '',
            'home_telephone' => $customer->contacts->firstWhere('type', ContactType::Home)?->value ?? '',
            'email' => $customer->contacts->firstWhere('type', ContactType::Email)?->value ?? $customer->primary_email ?? '',
            'social' => $customer->contacts->first(fn ($contact): bool => $contact->type === ContactType::Social && $contact->platform === null)?->value ?? '',
            'socialAccounts' => $social,
        ];
    }

    /** @return array<string, mixed> */
    private function addresses(Customer $customer): array
    {
        $residential = $customer->addresses->firstWhere('type', AddressType::Residential);
        $mailing = $customer->addresses->firstWhere('type', AddressType::Mailing);

        return [
            'residential_line_1' => $residential?->line_1 ?? '', 'residential_line_2' => $residential?->line_2 ?? '',
            'residential_city' => $residential?->city ?? '', 'residential_state' => $residential?->state ?? '',
            'residential_postcode' => $residential?->postcode ?? '', 'residential_country' => $residential?->country ?? '',
            'occupancy_status' => $residential?->occupancy_status?->value ?? '', 'occupancy_other' => $residential?->occupancy_other ?? '',
            'same_as_residential' => (bool) ($mailing?->same_as_residential ?? true),
            'mailing_line_1' => $mailing?->line_1 ?? '', 'mailing_line_2' => $mailing?->line_2 ?? '',
            'mailing_city' => $mailing?->city ?? '', 'mailing_state' => $mailing?->state ?? '',
            'mailing_postcode' => $mailing?->postcode ?? '', 'mailing_country' => $mailing?->country ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function employmentStatus(Customer $customer): array
    {
        return [
            'employmentStatus' => $customer->employment_status ?? '',
            'employmentStatusOther' => $customer->employment_status_other ?? '',
            'retirementDate' => $customer->retirement_date?->format('Y-m-d') ?? '',
        ];
    }

    private function current(Customer $customer, string $fieldId, mixed $target): mixed
    {
        if ($this->repeatable($this->module($fieldId)) && ! is_int($target)) {
            return null;
        }
        $leaf = substr($fieldId, (int) strrpos($fieldId, '.') + 1);

        return match ($this->module($fieldId)) {
            'personal' => $this->personal($customer)[$this->inputKey($fieldId)] ?: null,
            'employment_status' => $this->employmentStatus($customer)[$this->inputKey($fieldId)] ?: null,
            'contacts' => $this->contactValue($customer, $fieldId) ?: null,
            'addresses' => $this->addressValue($customer, $fieldId),
            'tin' => $customer->taxIdentification?->malaysian_tin,
            'fatca' => in_array($leaf, ['us_person_for_tax', 'us_green_card_holder', 'us_resident'], true)
                ? $this->yesNo($this->currentFatca($customer)?->{$leaf})
                : $this->plain($this->currentFatca($customer)?->{$leaf}),
            'financial' => $this->plain($this->currentFinancial($customer)?->{$leaf}),
            'investment' => $this->plain($customer->investmentProfiles->sortByDesc('id')->first()?->{$leaf}),
            'brokerage_declaration' => $this->plain($customer->brokerageDeclarations->firstWhere('current_customer_key', $customer->id)?->{$leaf}),
            'compliance:pep', 'compliance:bankruptcy', 'compliance:petition' => $this->plain($customer->complianceDeclarations->firstWhere('current_key', $customer->id.':'.substr($this->module($fieldId), 11))?->{$leaf}),
            'relationships' => $this->relationshipValue($customer, $target, $fieldId),
            'employments' => $this->plain($customer->employments->firstWhere('id', $target)?->{$leaf}),
            'bank_accounts' => $this->plain($customer->bankAccounts->firstWhere('id', $target)?->{$leaf}),
            'tax_residencies' => $this->plain($customer->taxResidencies->firstWhere('id', $target)?->{$leaf}),
            'brokerage_accounts' => $this->plain($customer->brokerageAccounts->firstWhere('id', $target)?->{$leaf}),
            'pep_associations' => $this->plain($customer->pepAssociations->firstWhere('id', $target)?->{$leaf}),
            default => null,
        };
    }

    private function contactValue(Customer $customer, string $fieldId): mixed
    {
        $contacts = $this->contacts($customer);
        $key = $this->inputKey($fieldId);

        return is_array($key) ? $contacts[$key[0]][$key[1]] : $contacts[$key];
    }

    private function addressValue(Customer $customer, string $fieldId): mixed
    {
        $value = $this->addresses($customer)[$this->inputKey($fieldId)];
        if ($fieldId === 'addresses[mailing].same_as_residential') {
            $mailing = $customer->addresses->firstWhere('type', AddressType::Mailing);

            return $mailing === null ? null : (bool) $mailing->same_as_residential;
        }

        return $value === '' ? null : $value;
    }

    private function relationshipValue(Customer $customer, mixed $target, string $fieldId): mixed
    {
        $relationship = $customer->relationships->firstWhere('id', $target);
        if ($relationship === null) {
            return null;
        }
        $person = $relationship->relatedPerson;
        $leaf = substr($fieldId, (int) strrpos($fieldId, '.') + 1);
        if (str_contains($fieldId, '.related_person.')) {
            $value = $person?->{$leaf === 'identification_type' ? 'identification_type' : $leaf};

            return $leaf === 'identification_type' ? $value?->value : $this->plain($value);
        }

        return match ($leaf) {
            'relationship' => $relationship->relationship,
            'other_relationship' => $relationship->other_relationship,
            'is_next_of_kin' => $relationship->is_next_of_kin,
            'is_primary_next_of_kin' => $relationship->is_primary_next_of_kin,
            default => null,
        };
    }

    /** @param  list<array<string, mixed>>  $values
     * @return array{target: mixed, ambiguous: bool, blocked: ?string, options: list<array{id: string, label: string}>} */
    private function resolveRow(Customer $customer, string $module, array $values, string $choice): array
    {
        $options = $this->options($customer, $module);
        if ($choice === 'new' || (ctype_digit($choice) && collect($options)->contains(fn (array $option): bool => $option['id'] === $choice))) {
            return ['target' => $choice === 'new' ? 'new' : (int) $choice, 'ambiguous' => false, 'blocked' => $module === 'pep_associations' && $choice === 'new' ? 'Choose an existing PEP association. The profile has no person name to create one.' : null, 'options' => $options];
        }
        $match = $this->autoMatch($customer, $module, $values);
        if ($match === 'blocked') {
            return ['target' => null, 'ambiguous' => false, 'blocked' => 'Choose an existing PEP association. The profile has no person name to create one.', 'options' => $options];
        }
        if ($match === 'ambiguous') {
            return ['target' => null, 'ambiguous' => true, 'blocked' => null, 'options' => $options];
        }

        return ['target' => $match, 'ambiguous' => false, 'blocked' => null, 'options' => $options];
    }

    /** @param  list<array<string, mixed>>  $values */
    private function autoMatch(Customer $customer, string $module, array $values): mixed
    {
        $proposed = [];
        foreach ($values as $value) {
            $proposed[$value['field_id']] = $value['value'];
        }

        return match ($module) {
            'bank_accounts' => $this->matchBank($customer, $proposed),
            'relationships' => $this->matchRelationship($customer, $proposed),
            'tax_residencies' => $this->matchTax($customer, $proposed),
            'employments' => $customer->employments->isEmpty() ? 'new' : 'ambiguous',
            'brokerage_accounts' => $customer->brokerageAccounts->isEmpty() ? 'new' : 'ambiguous',
            'pep_associations' => 'blocked',
            default => 'ambiguous',
        };
    }

    /** @param  array<string, mixed>  $proposed */
    private function matchBank(Customer $customer, array $proposed): mixed
    {
        $name = $proposed['bank_accounts[].bank_name'] ?? null;
        $country = $proposed['bank_accounts[].bank_country'] ?? null;
        $number = $proposed['bank_accounts[].account_number'] ?? null;
        if (is_string($name) && is_string($country) && is_string($number) && $name !== '' && $country !== '' && $number !== '') {
            $hash = CustomerBankAccount::identityHash($name, $country, $number);

            return $customer->bankAccounts->firstWhere('identity_hash', $hash)?->id ?? 'new';
        }

        return $customer->bankAccounts->isEmpty() ? 'new' : 'ambiguous';
    }

    /** @param  array<string, mixed>  $proposed */
    private function matchRelationship(Customer $customer, array $proposed): mixed
    {
        $type = $proposed['relationships[].related_person.identification_type'] ?? null;
        $number = $proposed['relationships[].related_person.identification_number'] ?? null;
        if (is_string($type) && is_string($number) && $type !== '' && $number !== '') {
            $normalized = CustomerIdentification::normalizeNumber($number);
            $matches = $customer->relationships->filter(fn ($relationship): bool => $relationship->relatedPerson?->identification_type?->value === $type
                && $relationship->relatedPerson?->normalized_identification_number === $normalized);
            if ($matches->count() === 1) {
                return $matches->first()->id;
            }

            return $matches->isEmpty() ? 'new' : 'ambiguous';
        }

        return $customer->relationships->isEmpty() ? 'new' : 'ambiguous';
    }

    /** @param  array<string, mixed>  $proposed */
    private function matchTax(Customer $customer, array $proposed): mixed
    {
        $jurisdiction = $proposed['tax_residencies[].jurisdiction'] ?? null;
        if (is_string($jurisdiction) && trim($jurisdiction) !== '') {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($jurisdiction)) ?? trim($jurisdiction));
            $found = $customer->taxResidencies->first(fn ($residency): bool => $residency->current_jurisdiction_key === $key);

            return $found?->id ?? 'new';
        }

        return $customer->taxResidencies->whereNotNull('current_jurisdiction_key')->isEmpty() ? 'new' : 'ambiguous';
    }

    /** @return list<array{id: string, label: string}> */
    private function options(Customer $customer, string $module): array
    {
        $rows = match ($module) {
            'bank_accounts' => $customer->bankAccounts->map(fn ($account): array => ['id' => (string) $account->id, 'label' => $account->bank_name.' '.$this->display('bank_accounts[].account_number', $account->account_number)]),
            'relationships' => $customer->relationships->map(fn ($relationship): array => ['id' => (string) $relationship->id, 'label' => $relationship->relatedPerson?->full_legal_name ?? $relationship->relatedCustomer?->full_legal_name ?? 'Relationship '.$relationship->id]),
            'tax_residencies' => $customer->taxResidencies->whereNotNull('current_jurisdiction_key')->map(fn ($residency): array => ['id' => (string) $residency->id, 'label' => $residency->jurisdiction]),
            'employments' => $customer->employments->map(fn ($employment): array => ['id' => (string) $employment->id, 'label' => $employment->employer_name ?: ($employment->occupation ?: 'Employment '.$employment->id)]),
            'brokerage_accounts' => $customer->brokerageAccounts->map(fn ($account): array => ['id' => (string) $account->id, 'label' => $account->broker_name]),
            'pep_associations' => $customer->pepAssociations->whereNull('ended_on')->whereNull('superseded_at')->map(fn ($association): array => ['id' => (string) $association->id, 'label' => $association->displayName() ?: 'PEP association '.$association->id]),
            default => collect(),
        };
        $options = $rows->values()->all();
        if ($module !== 'pep_associations') {
            array_unshift($options, ['id' => 'new', 'label' => 'New row']);
        }

        return $options;
    }

    /** @param  list<array<string, mixed>>  $values */
    private function missingRequired(string $rowKey, array $values, Customer $customer, ?int $id): ?string
    {
        $module = explode('|', $rowKey, 2)[0];
        $have = [];
        foreach ($values as $value) {
            $have[$value['field_id']] = $value['value'];
        }
        if ($id !== null) {
            return null;
        }
        $blank = fn (string $field): bool => blank($have[$field] ?? null);
        $missing = match ($module) {
            'bank_accounts' => array_values(array_filter([
                $blank('bank_accounts[].bank_name') ? 'bank name' : null,
                $blank('bank_accounts[].bank_country') ? 'bank country' : null,
                $blank('bank_accounts[].account_number') ? 'account number' : null,
                $blank('bank_accounts[].account_holder_name') ? 'account holder name' : null,
            ])),
            'relationships' => array_values(array_filter([
                $blank('relationships[].related_person.full_legal_name') ? 'family member name' : null,
                ($blank('relationships[].relationship') && $blank('relationships[].other_relationship'))
                    ? 'relationship to the applicant' : null,
            ])),
            'employments' => ($blank('employments[].arrangement') && $blank('employments[].occupation') && $blank('employments[].employer_name'))
                ? ['an arrangement, an occupation, or an employer name'] : [],
            'tax_residencies' => array_values(array_filter([
                $blank('tax_residencies[].jurisdiction') ? 'jurisdiction' : null,
                $blank('tax_residencies[].effective_date') ? 'effective date' : null,
                ($blank('tax_residencies[].foreign_tin') && $blank('tax_residencies[].missing_tin_reason')) ? 'a foreign TIN or a reason it is missing' : null,
            ])),
            'brokerage_accounts' => array_values(array_filter([
                $blank('brokerage_accounts[].broker_name') ? 'broker name' : null,
                $blank('brokerage_accounts[].account_type') ? 'account type' : null,
            ])),
            default => [],
        };
        if ($missing === []) {
            return null;
        }
        $section = match ($module) {
            'bank_accounts' => 'Bank account',
            'relationships' => 'Family member',
            'employments' => 'Employment',
            'tax_residencies' => 'Foreign tax residency',
            'brokerage_accounts' => 'Other brokerage account',
            default => 'This row',
        };
        $list = count($missing) === 1 ? $missing[0] : implode(', ', array_slice($missing, 0, -1)).' and '.$missing[array_key_last($missing)];

        return $section.' is missing '.$list.'.';
    }

    /** @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     * @return array<string, mixed> */
    private function rowPayload(Customer $customer, string $module, ?int $id, $included, $proposed, string $rowKey): array
    {
        $base = match ($module) {
            'employments' => $this->employmentPayload($customer, $id),
            'bank_accounts' => $this->bankPayload($customer, $id),
            'relationships' => $this->relationshipPayload($customer, $id),
            'tax_residencies' => $this->taxPayload($customer, $id),
            'brokerage_accounts' => $this->brokeragePayload($customer, $id),
            'pep_associations' => $this->pepPayload($customer, $id),
            default => [],
        };
        foreach ($included as $line) {
            if ($line['row_key'] !== $rowKey || $line['field_id'] === $module.'[].ended_on' || $line['field_id'] === $module.'[].is_active') {
                continue;
            }
            $base[$this->inputKey($line['field_id'])] = $proposed[$line['key']]['value'];
        }
        if ($module === 'relationships' && blank($base['relationship'] ?? null)) {
            if (filled($base['otherRelationship'] ?? null) || ($base['isActive'] ?? false) || ($base['isNextOfKin'] ?? false)) {
                $base['relationship'] = 'other';
                if (blank($base['otherRelationship'] ?? null)) {
                    $base['otherRelationship'] = 'Next of kin';
                }
            }
        }
        if ($module === 'tax_residencies') {
            $end = $included->first(fn (array $line): bool => $line['row_key'] === $rowKey && str_ends_with($line['field_id'], '.ended_on'));
            $base['_ended_on'] = $end ? $proposed[$end['key']]['value'] : null;
        }
        if (in_array($module, ['bank_accounts', 'brokerage_accounts'], true)) {
            $active = $included->first(fn (array $line): bool => $line['row_key'] === $rowKey && str_ends_with($line['field_id'], '.is_active'));
            $base['_is_active'] = $active ? $proposed[$active['key']]['value'] : null;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function employmentPayload(Customer $customer, ?int $id): array
    {
        $employment = $id ? $customer->employments->firstWhere('id', $id) : null;
        $payload = array_fill_keys(['arrangement', 'occupation', 'employer_name', 'registration_number', 'industry', 'sub_industry', 'start_date', 'end_date', 'general_telephone', 'direct_telephone', 'fax', 'address_line_1', 'address_line_2', 'city', 'state', 'postcode', 'country'], '');
        $payload['is_current'] = true;
        $payload['is_primary'] = false;
        if ($employment) {
            foreach (array_keys($payload) as $field) {
                $value = $employment->{$field};
                $payload[$field] = in_array($field, ['is_current', 'is_primary'], true) ? (bool) $value : ($value instanceof \DateTimeInterface ? $value->format('Y-m-d') : ($value ?? ''));
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function bankPayload(Customer $customer, ?int $id): array
    {
        $account = $id ? $customer->bankAccounts->firstWhere('id', $id) : null;

        return [
            'bank_name' => $account?->bank_name ?? '', 'bank_country' => $account?->bank_country ?? '',
            'branch_address' => $account?->branch_address ?? '', 'account_type' => $account?->account_type ?? '',
            'account_number' => '', 'account_holder_name' => $account?->account_holder_name ?? '',
            'ownership' => $account?->ownership ?? '', 'is_primary' => (bool) ($account?->is_primary ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private function relationshipPayload(Customer $customer, ?int $id): array
    {
        $relationship = $id ? $customer->relationships->firstWhere('id', $id) : null;
        $person = $relationship?->relatedPerson;
        $payload = [
            'source' => 'new', 'fullLegalName' => $person?->full_legal_name ?? '', 'relationship' => $relationship?->relationship ?? '',
            'otherRelationship' => $relationship?->other_relationship ?? '', 'identificationType' => $person?->identification_type?->value ?? '',
            'identificationNumber' => '', 'dateOfBirth' => $person?->date_of_birth?->format('Y-m-d') ?? '', 'gender' => $person?->gender ?? '',
            'nationality' => $person?->nationality ?? '', 'mobile' => $person?->mobile ?? '', 'homeTelephone' => $person?->home_telephone ?? '',
            'email' => $person?->email ?? '', 'occupation' => $person?->occupation ?? '', 'employerName' => $person?->employer_name ?? '',
            'employerAddress' => $person?->employer_address ?? '', 'employerAddressLine1' => $person?->employer_address_line_1 ?? '',
            'employerAddressLine2' => $person?->employer_address_line_2 ?? '', 'employerCity' => $person?->employer_city ?? '',
            'employerState' => $person?->employer_state ?? '', 'employerPostcode' => $person?->employer_postcode ?? '',
            'employerCountry' => $person?->employer_country ?? '',
            'isActive' => (bool) ($relationship?->is_primary_next_of_kin ?? false),
        ];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function taxPayload(Customer $customer, ?int $id): array
    {
        $residency = $id ? $customer->taxResidencies->firstWhere('id', $id) : null;

        return [
            'jurisdiction' => $residency?->jurisdiction ?? '', 'foreign_tin' => '', 'missing_tin_reason' => $residency?->missing_tin_reason ?? '',
            'missing_tin_explanation' => $residency?->missing_tin_explanation ?? '', 'effective_date' => $residency?->effective_date?->format('Y-m-d') ?? today()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function brokeragePayload(Customer $customer, ?int $id): array
    {
        $account = $id ? $customer->brokerageAccounts->firstWhere('id', $id) : null;

        return ['broker_name' => $account?->broker_name ?? '', 'branch_address' => $account?->branch_address ?? '', 'account_type' => $account?->account_type ?? ''];
    }

    /** @return array<string, mixed> */
    private function pepPayload(Customer $customer, ?int $id): array
    {
        $association = $customer->pepAssociations->firstWhere('id', $id);

        return [
            'source' => 'new', 'name' => $association?->relatedPerson?->full_legal_name ?? '', 'relationship' => $association?->relationship ?? '',
            'occupation' => $association?->occupation ?? '', 'position' => $association?->position ?? '', 'position_meaning' => $association?->position_meaning ?? 'public_position',
            'organization' => $association?->organization ?? '', 'country' => $association?->country ?? '', 'position_status' => $association?->position_status ?? 'current',
            'remarks' => $association?->remarks ?? '', 'started_on' => $association?->started_on?->format('Y-m-d') ?? today()->toDateString(),
            'identification_type' => '', 'identification_number' => '',
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     * @return array<string, mixed> */
    private function singularPayload(Customer $customer, string $module, $included, $proposed): array
    {
        $base = match ($module) {
            'tin' => ['malaysian_tin' => $customer->taxIdentification?->malaysian_tin ?? ''],
            'fatca' => $this->fatcaPayload($customer),
            'financial' => $this->financialPayload($customer),
            'investment' => $this->investmentPayload($customer),
            'brokerage_declaration' => $this->brokerageDeclarationPayload($customer),
            default => [],
        };

        return $this->overlay($base, $included, $proposed, $module);
    }

    /** @return array<string, mixed> */
    private function fatcaPayload(Customer $customer): array
    {
        $current = $this->currentFatca($customer);

        return [
            'us_person_for_tax' => $this->yesNo($current?->us_person_for_tax) ?? '',
            'us_green_card_holder' => $this->yesNo($current?->us_green_card_holder) ?? '',
            'us_resident' => $this->yesNo($current?->us_resident) ?? '',
            'selected_classification' => $current?->selected_classification ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_entry',
        ];
    }

    /** @return array<string, mixed> */
    private function financialPayload(Customer $customer): array
    {
        $current = $this->currentFinancial($customer);

        return [
            'monthly_income_rm' => $current?->monthly_income_rm ?? '',
            'personal_annual_income_bracket' => $current?->personal_annual_income_bracket ?? '',
            'joint_annual_income_bracket' => $current?->joint_annual_income_bracket ?? '',
            'net_asset_bracket' => $current?->net_asset_bracket ?? '',
            'portfolio_bracket' => $current?->portfolio_bracket ?? '',
            'portfolio_ownership' => $current?->portfolio_ownership ?? '',
            'wealth_sources' => $current?->wealth_sources ?? [],
            'other_wealth_source' => $current?->other_wealth_source ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_entry',
        ];
    }

    /** @return array<string, mixed> */
    private function investmentPayload(Customer $customer): array
    {
        $current = $customer->investmentProfiles->sortByDesc('id')->first();

        return [
            'objectives' => $current?->objectives ?? [], 'risk_tolerance' => $current?->risk_tolerance ?? '',
            'trading_knowledge' => $current?->trading_knowledge ?? '', 'products' => $current?->products ?? [],
            'other_product_details' => $current?->other_product_details ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function brokerageDeclarationPayload(Customer $customer): array
    {
        $current = $customer->brokerageDeclarations->firstWhere('current_customer_key', $customer->id);

        return [
            'answer' => $current?->answer ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_entry',
        ];
    }

    /** @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     * @return array<string, mixed> */
    private function compliancePayload(Customer $customer, string $kind, $included, $proposed): array
    {
        $current = $customer->complianceDeclarations->firstWhere('current_key', $customer->id.':'.$kind);
        $base = [
            'answer' => $current?->answer ?? '', 'remarks' => $current?->remarks ?? '',
            'declared_on' => $current?->declared_on?->toDateString() ?? today()->toDateString(),
            'source' => $current?->source ?? 'staff_recorded', 'confirmation_status' => $current?->confirmation_status ?? 'unconfirmed',
        ];

        return $this->overlay($base, $included, $proposed, 'compliance:'.$kind);
    }

    private function singularId(Customer $customer, string $module): ?int
    {
        return match ($module) {
            'fatca' => $this->currentFatca($customer)?->id,
            'financial' => $this->currentFinancial($customer)?->id,
            'brokerage_declaration' => $customer->brokerageDeclarations->firstWhere('current_customer_key', $customer->id)?->id,
            default => null,
        };
    }

    private function currentFatca(Customer $customer): mixed
    {
        return $customer->fatcaDeclarations->firstWhere('current_customer_key', $customer->id);
    }

    private function currentFinancial(Customer $customer): mixed
    {
        return $customer->financialDeclarations->firstWhere('current_customer_key', $customer->id);
    }

    private function action(mixed $current, mixed $proposed, string $strategy, ?string $fieldId = null): string
    {
        if ($this->isEmpty($proposed)) {
            return $strategy === 'replace_all' && ! $this->isEmpty($current) ? 'clear' : 'skip';
        }
        if ($this->same($current, $proposed, $fieldId)) {
            return 'skip';
        }
        if ($this->isEmpty($current)) {
            return 'add';
        }

        return in_array($strategy, ['overwrite', 'replace_all'], true) ? 'replace' : 'conflict';
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function same(mixed $current, mixed $proposed, ?string $fieldId = null): bool
    {
        if ($fieldId !== null && $this->isIdentificationNumberField($fieldId)) {
            $left = is_string($current) || is_numeric($current) ? (string) $current : null;
            $right = is_string($proposed) || is_numeric($proposed) ? (string) $proposed : null;

            return CustomerIdentification::normalizeNumber($left) !== null
                && CustomerIdentification::normalizeNumber($left) === CustomerIdentification::normalizeNumber($right);
        }

        if ($fieldId !== null && $this->isPhoneField($fieldId)) {
            $left = is_string($current) ? CustomerContact::normalizeValue(ContactType::Mobile, $current) : null;
            $right = is_string($proposed) ? CustomerContact::normalizeValue(ContactType::Mobile, $proposed) : null;

            return filled($left) && $left === $right;
        }

        return $this->canonical($current) === $this->canonical($proposed);
    }

    private function isIdentificationNumberField(string $fieldId): bool
    {
        return $fieldId === 'primary_identification.number'
            || str_ends_with($fieldId, '.identification_number');
    }

    private function isPhoneField(string $fieldId): bool
    {
        return str_contains($fieldId, 'contacts[mobile]')
            || str_contains($fieldId, 'contacts[home]')
            || str_ends_with($fieldId, '.mobile')
            || str_ends_with($fieldId, '.home_telephone')
            || str_ends_with($fieldId, '.general_telephone')
            || str_ends_with($fieldId, '.direct_telephone')
            || str_ends_with($fieldId, '.fax');
    }

    private function canonical(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $copy = $value;
            sort($copy);

            return json_encode(array_values($copy), JSON_THROW_ON_ERROR);
        }
        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $value) ?? ''));
    }

    private function yesNo(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value === true || $value === 'yes' ? 'yes' : 'no';
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value === '' ? null : $value;
    }

    /** @param  list<array<string, mixed>>  $values */
    private function proposed(array $values, string $fieldId): mixed
    {
        foreach ($values as $value) {
            if ($value['field_id'] === $fieldId) {
                return $value['value'];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $included
     * @param  Collection<string, array<string, mixed>>  $proposed
     */
    private function proposedModuleValue($included, $proposed, string $fieldId): mixed
    {
        $line = $included->first(fn (array $row): bool => ($row['field_id'] ?? null) === $fieldId);
        if ($line === null) {
            return null;
        }

        return $proposed[$line['key']]['value'] ?? null;
    }

    private function inputKey(string $fieldId): array|string
    {
        return match ($fieldId) {
            'customer.salutation' => 'salutation',
            'customer.full_legal_name' => 'full_legal_name',
            'customer.date_of_birth' => 'date_of_birth',
            'customer.gender' => 'gender',
            'customer.nationality' => 'nationality',
            'customer.country_of_residence' => 'country_of_residence',
            'customer.race' => 'race',
            'customer.marital_status' => 'marital_status',
            'customer.employment_status' => 'employmentStatus',
            'customer.employment_status_other' => 'employmentStatusOther',
            'customer.retirement_date' => 'retirementDate',
            'primary_identification.type' => 'identification_type',
            'primary_identification.number' => 'identification_number',
            'primary_identification.issuing_jurisdiction' => 'issuing_jurisdiction',
            'primary_identification.old_nric' => 'old_nric',
            'contacts[mobile].value' => 'mobile',
            'contacts[home].value' => 'home_telephone',
            'contacts[email].value' => 'email',
            'contacts[social].value' => 'social',
            'contacts[social:facebook].value' => ['socialAccounts', 'facebook'],
            'contacts[social:instagram].value' => ['socialAccounts', 'instagram'],
            'contacts[social:twitter].value' => ['socialAccounts', 'twitter'],
            'addresses[residential].line_1' => 'residential_line_1',
            'addresses[residential].line_2' => 'residential_line_2',
            'addresses[residential].city' => 'residential_city',
            'addresses[residential].state' => 'residential_state',
            'addresses[residential].postcode' => 'residential_postcode',
            'addresses[residential].country' => 'residential_country',
            'addresses[residential].occupancy_status' => 'occupancy_status',
            'addresses[residential].occupancy_other' => 'occupancy_other',
            'addresses[mailing].line_1' => 'mailing_line_1',
            'addresses[mailing].line_2' => 'mailing_line_2',
            'addresses[mailing].city' => 'mailing_city',
            'addresses[mailing].state' => 'mailing_state',
            'addresses[mailing].postcode' => 'mailing_postcode',
            'addresses[mailing].country' => 'mailing_country',
            'addresses[mailing].same_as_residential' => 'same_as_residential',
            'relationships[].relationship' => 'relationship',
            'relationships[].other_relationship' => 'otherRelationship',
            'relationships[].is_next_of_kin' => 'isNextOfKin',
            'relationships[].is_primary_next_of_kin' => 'isActive',
            'relationships[].related_person.full_legal_name' => 'fullLegalName',
            'relationships[].related_person.identification_type' => 'identificationType',
            'relationships[].related_person.identification_number' => 'identificationNumber',
            'relationships[].related_person.date_of_birth' => 'dateOfBirth',
            'relationships[].related_person.gender' => 'gender',
            'relationships[].related_person.nationality' => 'nationality',
            'relationships[].related_person.mobile' => 'mobile',
            'relationships[].related_person.home_telephone' => 'homeTelephone',
            'relationships[].related_person.email' => 'email',
            'relationships[].related_person.occupation' => 'occupation',
            'relationships[].related_person.employer_name' => 'employerName',
            'relationships[].related_person.employer_address' => 'employerAddress',
            'relationships[].related_person.employer_address_line_1' => 'employerAddressLine1',
            'relationships[].related_person.employer_address_line_2' => 'employerAddressLine2',
            'relationships[].related_person.employer_city' => 'employerCity',
            'relationships[].related_person.employer_state' => 'employerState',
            'relationships[].related_person.employer_postcode' => 'employerPostcode',
            'relationships[].related_person.employer_country' => 'employerCountry',
            'tax_identification.malaysian_tin' => 'malaysian_tin',
            default => substr($fieldId, (int) strrpos($fieldId, '.') + 1),
        };
    }

    public function moduleOf(string $path): string
    {
        return $this->module($path);
    }

    /** @param  list<string>  $excluded */
    private function kindExcluded(string $fieldId, array $excluded): bool
    {
        if (preg_match('/^addresses\[(residential|mailing)\]/', $fieldId, $match) === 1) {
            return in_array('module:addresses:'.$match[1], $excluded, true);
        }
        if (preg_match('/^contacts\[([^\]]+)\]/', $fieldId, $match) === 1) {
            $kind = str_starts_with($match[1], 'social') ? 'social' : $match[1];

            return in_array('module:contacts:'.$kind, $excluded, true);
        }

        return false;
    }

    private function module(string $path): string
    {
        if (preg_match('/^compliance\[(pep|bankruptcy|petition)\]/', $path, $match)) {
            return 'compliance:'.$match[1];
        }

        return match (true) {
            str_starts_with($path, 'customer.employment_status'), $path === 'customer.retirement_date' => 'employment_status',
            str_starts_with($path, 'customer.'), str_starts_with($path, 'primary_identification.') => 'personal',
            str_starts_with($path, 'contacts[') => 'contacts',
            str_starts_with($path, 'addresses[') => 'addresses',
            str_starts_with($path, 'tax_identification.') => 'tin',
            str_starts_with($path, 'tax_residencies[].') => 'tax_residencies',
            str_starts_with($path, 'fatca.') => 'fatca',
            str_starts_with($path, 'financial.') => 'financial',
            str_starts_with($path, 'investment.') => 'investment',
            str_starts_with($path, 'brokerage_declaration.') => 'brokerage_declaration',
            str_starts_with($path, 'brokerage_accounts[].') => 'brokerage_accounts',
            str_starts_with($path, 'relationships[].') => 'relationships',
            str_starts_with($path, 'employments[].') => 'employments',
            str_starts_with($path, 'bank_accounts[].') => 'bank_accounts',
            str_starts_with($path, 'pep_associations[].') => 'pep_associations',
            default => 'other',
        };
    }

    private function repeatable(string $module): bool
    {
        return in_array($module, ['relationships', 'employments', 'bank_accounts', 'tax_residencies', 'brokerage_accounts', 'pep_associations'], true);
    }

    /** @param  array<string, mixed>  $value */
    private function rowKey(array $value): string
    {
        $module = $this->module($value['field_id']);

        return $this->repeatable($module) ? $module.'|'.($value['row_ref'] ?? '') : $module;
    }

    /** @param  array<string, mixed>  $value */
    private function lineKey(array $value): string
    {
        return $value['field_id'].'|'.($value['row_ref'] ?? '');
    }

    private function display(string $fieldId, mixed $value): string
    {
        if ($this->isEmpty($value)) {
            return 'Empty';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return implode(', ', $value);
        }
        $sensitive = str_ends_with($fieldId, '.account_number') || str_ends_with($fieldId, '.foreign_tin') || str_ends_with($fieldId, '.malaysian_tin');
        if ($sensitive) {
            return '••••'.substr((string) $value, -4);
        }

        return (string) $value;
    }
}
