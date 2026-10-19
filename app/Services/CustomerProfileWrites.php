<?php

namespace App\Services;

use App\Enums\AddressType;
use App\Enums\ContactType;
use App\Enums\CustomerActivityAction;
use App\Enums\IdentificationType;
use App\Enums\OccupancyStatus;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerBrokerageAccount;
use App\Models\CustomerContact;
use App\Models\CustomerIdentification;
use App\Models\CustomerPepAssociation;
use App\Models\CustomerRelationship;
use App\Models\CustomerTaxResidency;
use App\Models\RelatedPerson;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerProfileWrites
{
    public function __construct(
        private CustomerDuplicateChecker $duplicates,
        private CustomerActivityLogger $activity,
    ) {}

    /** @param  array<string, mixed>  $data
     * @return array{weak: list<array{reference: string, full_legal_name: string}>} */
    public function savePersonal(Customer $customer, array $data, User $actor, bool $confirmWeakDuplicates): array
    {
        if (array_key_exists('identification_type', $data) && blank($data['identification_type'])) {
            $data['identification_type'] = null;
        }
        $validated = Validator::make($data, [
            'salutation' => ['nullable', 'string', 'max:50'],
            'full_legal_name' => ['required', 'string', 'max:255'],
            'identification_type' => ['nullable', Rule::enum(IdentificationType::class)],
            'identification_number' => ['required_with:identification_type', 'nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) use ($data): void {
                if (($data['identification_type'] ?? '') === IdentificationType::Nric->value && filled($value) && ! preg_match('/^\d{12}$/', CustomerIdentification::normalizeNumber((string) $value) ?? '')) {
                    $fail('NRIC must be 12 digits (with or without dashes).');
                }
            }],
            'issuing_jurisdiction' => ['required_if:identification_type,passport,other', 'nullable', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'old_nric' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'country_of_residence' => ['nullable', 'string', 'max:100'],
            'race' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'string', 'max:50'],
        ])->validate();

        $identificationType = filled($validated['identification_type'] ?? null) ? IdentificationType::from($validated['identification_type']) : null;
        $matches = $this->duplicates->check($identificationType, ($validated['identification_number'] ?? null) ?: null, $customer->primary_email, $customer->primary_mobile, $validated['full_legal_name'], $customer->id, ($validated['issuing_jurisdiction'] ?? null) ?: null);
        if ($matches['strong']->isNotEmpty()) {
            $this->fail('identification_number', 'This identification already belongs to another customer, including archived customers.');
        }
        $weak = $matches['weak']->map(fn (Customer $match): array => [
            'reference' => $match->reference,
            'full_legal_name' => $match->full_legal_name,
        ])->all();
        if ($weak !== [] && ! $confirmWeakDuplicates) {
            return ['weak' => $weak];
        }

        try {
            DB::transaction(function () use ($customer, $validated, $actor): void {
                $identificationType = filled($validated['identification_type'] ?? null) ? IdentificationType::from($validated['identification_type']) : null;
                $identificationNumber = filled($validated['identification_number'] ?? null)
                    ? CustomerIdentification::formatNumber($validated['identification_number'], $identificationType)
                    : null;
                $issuingJurisdiction = $identificationType === IdentificationType::Nric ? 'MY' : (filled($validated['issuing_jurisdiction'] ?? null) ? strtoupper(trim($validated['issuing_jurisdiction'])) : 'UNSPECIFIED');
                $customer->update([
                    'salutation' => filled($validated['salutation'] ?? null) ? $validated['salutation'] : null,
                    'full_legal_name' => $validated['full_legal_name'],
                    'date_of_birth' => filled($validated['date_of_birth'] ?? null) ? $validated['date_of_birth'] : null,
                    'gender' => filled($validated['gender'] ?? null) ? $validated['gender'] : null,
                    'nationality' => filled($validated['nationality'] ?? null) ? $validated['nationality'] : null,
                    'country_of_residence' => filled($validated['country_of_residence'] ?? null) ? $validated['country_of_residence'] : 'Malaysia',
                    'race' => filled($validated['race'] ?? null) ? $validated['race'] : 'Malay',
                    'marital_status' => filled($validated['marital_status'] ?? null) ? $validated['marital_status'] : null,
                    'primary_identification_type' => $identificationType,
                    'primary_identification_number' => $identificationNumber,
                    'updated_by' => $actor->id,
                ]);
                $identification = $customer->identifications()->where('is_primary', true)->first();
                if ($identificationType || filled($validated['old_nric'] ?? null)) {
                    $payload = [
                        'type' => $identificationType ?? IdentificationType::Other,
                        'number' => $identificationNumber,
                        'normalized_number' => CustomerIdentification::normalizeNumber($identificationNumber),
                        'issuing_jurisdiction' => $issuingJurisdiction,
                        'old_nric' => filled($validated['old_nric'] ?? null) ? $validated['old_nric'] : null,
                        'is_primary' => true,
                    ];
                    if ($identification) {
                        $identification->update($payload);
                    } else {
                        $customer->identifications()->create($payload);
                    }
                } elseif ($identification) {
                    $identification->delete();
                }
                $this->activity->log($customer, CustomerActivityAction::Updated, $actor, 'Personal information updated');
            });
        } catch (UniqueConstraintViolationException $exception) {
            $matches = $this->duplicates->check($identificationType, ($validated['identification_number'] ?? null) ?: null, null, null, null, $customer->id, ($validated['issuing_jurisdiction'] ?? null) ?: null);
            if ($matches['strong']->isEmpty()) {
                throw $exception;
            }
            $this->fail('identification_number', 'This identification already belongs to another customer, including archived customers.');
        }

        return ['weak' => []];
    }

    /** @param  array<string, mixed>  $data */
    public function saveContact(Customer $customer, array $data, User $actor): void
    {
        $validated = Validator::make($data, [
            'mobile' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'home_telephone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'social' => ['nullable', 'string', 'max:255'],
            'socialAccounts.facebook' => ['nullable', 'string', 'max:255'],
            'socialAccounts.instagram' => ['nullable', 'string', 'max:255'],
            'socialAccounts.twitter' => ['nullable', 'string', 'max:255'],
        ])->validate();

        DB::transaction(function () use ($customer, $validated, $actor): void {
            $this->syncContact($customer, ContactType::Mobile, $validated['mobile'] ?: null, true);
            $this->syncContact($customer, ContactType::Home, $validated['home_telephone'] ?: null, false);
            $this->syncContact($customer, ContactType::Email, $validated['email'] ?: null, true);
            $this->syncContact($customer, ContactType::Social, $validated['social'] ?: null, false);
            foreach (['facebook', 'instagram', 'twitter'] as $platform) {
                $this->syncSocialContact($customer, $platform, $validated['socialAccounts'][$platform] ?: null);
            }
            $customer->update([
                'primary_mobile' => filled($validated['mobile']) ? $validated['mobile'] : null,
                'primary_email' => filled($validated['email']) ? $validated['email'] : null,
                'updated_by' => $actor->id,
            ]);
            $this->activity->log($customer, CustomerActivityAction::Updated, $actor, 'Contact information updated');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function saveAddress(Customer $customer, array $data, User $actor): void
    {
        $validated = Validator::make($data, [
            'residential_line_1' => ['nullable', 'string', 'max:255'],
            'residential_line_2' => ['nullable', 'string', 'max:255'],
            'residential_city' => ['nullable', 'string', 'max:100'],
            'residential_state' => ['nullable', 'string', 'max:100'],
            'residential_postcode' => ['nullable', 'string', 'max:20'],
            'residential_country' => ['nullable', 'string', 'max:100'],
            'occupancy_status' => ['nullable', Rule::enum(OccupancyStatus::class)],
            'occupancy_other' => ['required_if:occupancy_status,other', 'nullable', 'string', 'max:255'],
            'same_as_residential' => ['boolean'],
            'mailing_line_1' => ['nullable', 'string', 'max:255'],
            'mailing_line_2' => ['nullable', 'string', 'max:255'],
            'mailing_city' => ['nullable', 'string', 'max:100'],
            'mailing_state' => ['nullable', 'string', 'max:100'],
            'mailing_postcode' => ['nullable', 'string', 'max:20'],
            'mailing_country' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $same = $validated['same_as_residential'] === true || $validated['same_as_residential'] === 1 || $validated['same_as_residential'] === '1';

        DB::transaction(function () use ($customer, $validated, $actor, $same): void {
            $customer->addresses()->updateOrCreate(['type' => AddressType::Residential], [
                'line_1' => $validated['residential_line_1'] ?: null,
                'line_2' => $validated['residential_line_2'] ?: null,
                'city' => $validated['residential_city'] ?: null,
                'state' => $validated['residential_state'] ?: null,
                'postcode' => $validated['residential_postcode'] ?: null,
                'country' => $validated['residential_country'] ?: null,
                'occupancy_status' => filled($validated['occupancy_status']) ? OccupancyStatus::from($validated['occupancy_status']) : null,
                'occupancy_other' => $validated['occupancy_status'] === 'other' ? trim((string) $validated['occupancy_other']) : null,
                'same_as_residential' => false,
            ]);
            $mailing = $same ? [
                'line_1' => $validated['residential_line_1'] ?: null,
                'line_2' => $validated['residential_line_2'] ?: null,
                'city' => $validated['residential_city'] ?: null,
                'state' => $validated['residential_state'] ?: null,
                'postcode' => $validated['residential_postcode'] ?: null,
                'country' => $validated['residential_country'] ?: null,
                'occupancy_status' => null,
                'same_as_residential' => true,
            ] : [
                'line_1' => $validated['mailing_line_1'] ?: null,
                'line_2' => $validated['mailing_line_2'] ?: null,
                'city' => $validated['mailing_city'] ?: null,
                'state' => $validated['mailing_state'] ?: null,
                'postcode' => $validated['mailing_postcode'] ?: null,
                'country' => $validated['mailing_country'] ?: null,
                'occupancy_status' => null,
                'same_as_residential' => false,
            ];
            $customer->addresses()->updateOrCreate(['type' => AddressType::Mailing], $mailing);
            $customer->update(['updated_by' => $actor->id]);
            $this->activity->log($customer, CustomerActivityAction::Updated, $actor, 'Address information updated');
        });
    }

    /** @param  array<string, mixed>  $input */
    public function saveRelationship(Customer $customer, array $input, User $actor, ?CustomerRelationship $record = null): void
    {
        $source = $record ? ($record->related_customer_id ? 'customer' : 'new') : ($input['source'] ?? '');
        $input['source'] = $source;
        $validated = Validator::make($input, [
            'source' => ['required', Rule::in(['new', 'customer'])],
            'linkedCustomerId' => ['exclude_if:source,new', Rule::requiredIf($source === 'customer'), 'nullable', 'integer', 'exists:customers,id'],
            'fullLegalName' => ['exclude_if:source,customer', Rule::requiredIf($source === 'new'), 'nullable', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(CustomerProfileSchema::options('relationship'))],
            'otherRelationship' => ['required_if:relationship,other', 'nullable', 'string', 'max:100'],
            'identificationType' => ['exclude_if:source,customer', 'nullable', 'required_with:identificationNumber', Rule::enum(IdentificationType::class)],
            'identificationNumber' => ['exclude_if:source,customer', 'nullable', 'required_with:identificationType', 'string', 'max:100'],
            'dateOfBirth' => ['exclude_if:source,customer', 'nullable', 'date', 'before_or_equal:today'],
            'gender' => ['exclude_if:source,customer', 'nullable', 'string', 'max:50'],
            'nationality' => ['exclude_if:source,customer', 'nullable', 'string', 'max:100'],
            'mobile' => ['exclude_if:source,customer', 'nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'homeTelephone' => ['exclude_if:source,customer', 'nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'email' => ['exclude_if:source,customer', 'nullable', 'email', 'max:255'],
            'occupation' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerName' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerAddress' => ['exclude_if:source,customer', 'nullable', 'string', 'max:2000'],
            'employerAddressLine1' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerAddressLine2' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerCity' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerState' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'employerPostcode' => ['exclude_if:source,customer', 'nullable', 'string', 'max:20'],
            'employerCountry' => ['exclude_if:source,customer', 'nullable', 'string', 'max:255'],
            'isNextOfKin' => ['boolean'],
            'isPrimaryNextOfKin' => ['boolean'],
        ])->validate();

        $errors = [];
        if ($source === 'customer') {
            $linked = Customer::query()->with('contacts')->findOrFail((int) $validated['linkedCustomerId']);
            if ($linked->id === $customer->id) {
                $errors['linkedCustomerId'] = 'A customer cannot be related to themselves.';
            }
            $contact = $linked->primary_mobile ?: $linked->contacts->firstWhere('type', ContactType::Home)?->value;
        } else {
            $contact = $validated['mobile'] ?: $validated['homeTelephone'];
            if (blank(trim($validated['fullLegalName'] ?? ''))) {
                $errors['fullLegalName'] = 'Full legal name is required.';
            }
        }
        if ($validated['isPrimaryNextOfKin'] && ! $validated['isNextOfKin']) {
            $errors['isPrimaryNextOfKin'] = 'A primary next of kin must also be designated as next of kin.';
        }
        if ($validated['isNextOfKin'] && blank($contact)) {
            $errors[$source === 'customer' ? 'linkedCustomerId' : 'mobile'] = 'Next of kin requires a usable contact number.';
        }
        if ($source === 'new' && filled($validated['identificationNumber'])) {
            $normalized = CustomerIdentification::normalizeNumber($validated['identificationNumber']);
            if ($validated['identificationType'] === IdentificationType::Nric->value && ! preg_match('/^\d{12}$/', $normalized ?? '')) {
                $errors['identificationNumber'] = 'NRIC must be 12 digits (with or without dashes).';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            DB::transaction(function () use ($customer, $validated, $source, $record, $actor): void {
                if ($source === 'new') {
                    $formattedNumber = filled($validated['identificationNumber'] ?? null)
                        ? CustomerIdentification::formatNumber($validated['identificationNumber'], $validated['identificationType'] ?: null)
                        : null;
                    $normalized = CustomerIdentification::normalizeNumber($formattedNumber);
                    $person = $record?->relatedPerson ?? new RelatedPerson;
                    $personDetails = [
                        'full_legal_name' => trim($validated['fullLegalName']),
                        'identification_type' => $validated['identificationType'] ?: null,
                        'identification_number' => $formattedNumber,
                        'normalized_identification_number' => $normalized,
                        'date_of_birth' => $validated['dateOfBirth'] ?: null,
                        'gender' => $validated['gender'] ?: null,
                        'nationality' => $validated['nationality'] ?: null,
                        'mobile' => $validated['mobile'] ?: null,
                        'home_telephone' => $validated['homeTelephone'] ?: null,
                        'email' => $validated['email'] ?: null,
                        'occupation' => $validated['occupation'] ?: null,
                        'employer_name' => $validated['employerName'] ?: null,
                        'employer_address' => $validated['employerAddress'] ?: null,
                        'employer_address_line_1' => $validated['employerAddressLine1'] ?: null,
                        'employer_address_line_2' => $validated['employerAddressLine2'] ?: null,
                        'employer_city' => $validated['employerCity'] ?: null,
                        'employer_state' => $validated['employerState'] ?: null,
                        'employer_postcode' => $validated['employerPostcode'] ?: null,
                        'employer_country' => $validated['employerCountry'] ?: null,
                    ];
                    $person->fill($personDetails)->save();
                    $identityKey = "{$customer->id}:person:{$person->id}";
                } else {
                    $person = null;
                    $identityKey = "{$customer->id}:customer:{$validated['linkedCustomerId']}";
                }
                $record ??= new CustomerRelationship;
                $wasNew = ! $record->exists;
                $nextOfKinChanged = ! $wasNew && ($record->is_next_of_kin !== $validated['isNextOfKin'] || $record->is_primary_next_of_kin !== $validated['isPrimaryNextOfKin']);
                if ($validated['isPrimaryNextOfKin']) {
                    $customer->relationships()->where('is_primary_next_of_kin', true)
                        ->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))
                        ->update(['is_primary_next_of_kin' => false]);
                }
                $record->fill([
                    'customer_id' => $customer->id,
                    'related_person_id' => $person?->id,
                    'related_customer_id' => $source === 'customer' ? (int) $validated['linkedCustomerId'] : null,
                    'relationship' => $validated['relationship'],
                    'other_relationship' => $validated['relationship'] === 'other' ? $validated['otherRelationship'] : null,
                    'is_next_of_kin' => $validated['isNextOfKin'],
                    'is_primary_next_of_kin' => $validated['isPrimaryNextOfKin'],
                    'active_identity_key' => $identityKey,
                ])->save();
                $this->activity->log($customer, $wasNew
                    ? ($source === 'customer' ? CustomerActivityAction::RelationshipLinked : CustomerActivityAction::RelatedPersonAdded)
                    : CustomerActivityAction::RelatedPersonUpdated, $actor);
                if ($nextOfKinChanged || ($wasNew && $validated['isNextOfKin'])) {
                    $this->activity->log($customer, CustomerActivityAction::NextOfKinUpdated, $actor);
                }
            });
        } catch (UniqueConstraintViolationException) {
            $this->fail('linkedCustomerId', 'This person is already linked to this customer.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public function saveEmploymentStatus(Customer $customer, array $data, User $actor): void
    {
        $data = Validator::make($data, [
            'employmentStatus' => ['nullable', Rule::in(CustomerProfileSchema::options('employment_status'))],
            'employmentStatusOther' => ['required_if:employmentStatus,other', 'nullable', 'string', 'max:255'],
            'retirementDate' => ['nullable', 'date', 'before_or_equal:today'],
        ])->validate();
        DB::transaction(function () use ($customer, $data, $actor): void {
            $customer->update([
                'employment_status' => $data['employmentStatus'] ?: null,
                'employment_status_other' => $data['employmentStatus'] === 'other' ? $data['employmentStatusOther'] : $customer->employment_status_other,
                'retirement_date' => $data['employmentStatus'] === 'retired' ? ($data['retirementDate'] ?: null) : $customer->retirement_date,
                'updated_by' => $actor->id,
            ]);
            $this->activity->log($customer, CustomerActivityAction::EmploymentStatusUpdated, $actor);
        });
    }

    /** @param  array<string, mixed>  $fields */
    public function saveEmployment(Customer $customer, array $fields, User $actor, ?int $employmentId = null, bool $confirmPrimaryReplacement = false, bool $isCorrection = false): void
    {
        $validated = Validator::make(['form' => $fields], [
            'form.arrangement' => ['nullable', Rule::in(CustomerProfileSchema::options('arrangement'))],
            'form.occupation' => ['nullable', 'string', 'max:255'],
            'form.employer_name' => ['nullable', 'string', 'max:255'],
            'form.registration_number' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\s\-\/().]+$/'],
            'form.industry' => ['nullable', 'string', 'max:255'],
            'form.sub_industry' => ['nullable', 'string', 'max:255'],
            'form.start_date' => ['nullable', 'date'],
            'form.end_date' => ['nullable', 'date'],
            'form.is_current' => ['boolean'],
            'form.is_primary' => ['boolean'],
            'form.general_telephone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'form.direct_telephone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'form.fax' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9\s\-()]{6,30}$/'],
            'form.address_line_1' => ['nullable', 'string', 'max:255'],
            'form.address_line_2' => ['nullable', 'string', 'max:255'],
            'form.city' => ['nullable', 'string', 'max:255'],
            'form.state' => ['nullable', 'string', 'max:255'],
            'form.postcode' => ['nullable', 'string', 'max:20'],
            'form.country' => ['nullable', 'string', 'max:255'],
        ])->validate()['form'];
        if (blank($validated['arrangement']) && blank($validated['occupation']) && blank($validated['employer_name'])) {
            $this->fail('form.occupation', 'Provide an arrangement, occupation, or employer to identify this employment.');
        }
        if (filled($validated['start_date']) && filled($validated['end_date']) && $validated['end_date'] < $validated['start_date']) {
            $this->fail('form.end_date', 'The end date must be on or after the start date.');
        }
        if ($validated['is_current'] && filled($validated['end_date'])) {
            $this->fail('form.end_date', 'A current employment cannot have an end date. Mark it as previous first.');
        }
        if (! $validated['is_current']) {
            $validated['is_primary'] = false;
        }
        DB::transaction(function () use ($customer, $validated, $actor, $employmentId, $confirmPrimaryReplacement, $isCorrection): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $employment = $employmentId ? $customer->employments()->findOrFail($employmentId) : null;
            $existingPrimary = $customer->employments()->where('is_primary', true)
                ->when($employment, fn ($query) => $query->where('id', '!=', $employment->id))->first();
            if ($validated['is_primary'] && $existingPrimary && ! $confirmPrimaryReplacement) {
                $this->fail('form.is_primary', 'Confirm that this employment should replace the current primary.');
            }
            $wasNew = $employment === null;
            $wasPrimary = $employment?->is_primary ?? false;
            $wasCurrent = $employment?->is_current ?? false;
            if ($validated['is_primary'] && $existingPrimary) {
                $existingPrimary->update(['is_primary' => false, 'primary_current_key' => null]);
            }
            $attributes = collect($validated)->map(fn ($value) => $value === '' ? null : $value)->all();
            $attributes['is_current'] = (bool) $validated['is_current'];
            $attributes['is_primary'] = (bool) $validated['is_primary'];
            $attributes['primary_current_key'] = $validated['is_primary'] ? $customer->id : null;
            if ($employment) {
                $employment->update($attributes);
            } else {
                $customer->employments()->create($attributes);
            }
            $this->activity->log($customer, $wasNew ? CustomerActivityAction::EmploymentAdded : ($isCorrection ? CustomerActivityAction::EmploymentRecordCorrected : CustomerActivityAction::EmploymentUpdated), $actor);
            if ($wasCurrent && ! $validated['is_current']) {
                $this->activity->log($customer, CustomerActivityAction::EmploymentEnded, $actor);
            }
            if ($validated['is_primary'] && (! $wasPrimary || $existingPrimary)) {
                $this->activity->log($customer, CustomerActivityAction::PrimaryEmploymentChanged, $actor);
            }
        });
    }

    /** @param  array<string, mixed>  $fields */
    public function saveBankAccount(Customer $customer, array $fields, User $actor, ?int $accountId = null, bool $confirmNumberChange = false): CustomerBankAccount
    {
        if (blank($fields['ownership'] ?? null)) {
            $fields['ownership'] = null;
        }
        $data = Validator::make(['form' => $fields], [
            'form.bank_name' => ['required', 'string', 'max:255'],
            'form.bank_country' => ['required', 'string', 'max:255'],
            'form.branch_address' => ['nullable', 'string', 'max:255'],
            'form.account_type' => ['required', Rule::in(CustomerProfileSchema::options('bank_account_type'))],
            'form.account_number' => [$accountId ? 'nullable' : 'required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9\s-]*$/'],
            'form.account_holder_name' => ['required', 'string', 'max:255'],
            'form.ownership' => ['nullable', Rule::in(CustomerProfileSchema::options('bank_ownership'))],
            'form.is_primary' => ['boolean'],
        ])->validate()['form'];
        $saved = null;
        DB::transaction(function () use ($customer, $data, $actor, $accountId, $confirmNumberChange, &$saved): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $account = $accountId ? $customer->bankAccounts()->findOrFail($accountId) : null;
            if ($account && $data['account_number'] !== '' && ! $confirmNumberChange) {
                $this->fail('confirmNumberChange', 'Confirm that you want to replace the account number.');
            }
            if ($account && ! $account->is_active && $data['is_primary']) {
                $this->fail('form.is_primary', 'An inactive account cannot be primary.');
            }
            $number = $data['account_number'] !== '' ? $data['account_number'] : $account?->account_number;
            $hash = CustomerBankAccount::identityHash($data['bank_name'], $data['bank_country'], (string) $number);
            if ($customer->bankAccounts()->where('identity_hash', $hash)->when($account, fn ($query) => $query->whereKeyNot($account->id))->exists()) {
                $this->fail('form.account_number', 'This bank account is already recorded for this customer.');
            }
            $oldPrimaryId = $customer->bankAccounts()->where('is_primary', true)->value('id');
            if ($data['is_primary']) {
                $customer->bankAccounts()->where('is_primary', true)->update(['is_primary' => false, 'primary_active_key' => null]);
            }
            $attributes = [
                'bank_name' => trim($data['bank_name']),
                'bank_country' => trim($data['bank_country']),
                'branch_address' => trim((string) $data['branch_address']) ?: null,
                'account_type' => $data['account_type'],
                'account_holder_name' => trim($data['account_holder_name']),
                'ownership' => $data['ownership'],
                'is_primary' => (bool) $data['is_primary'],
                'primary_active_key' => $data['is_primary'] ? $customer->id : null,
                'identity_hash' => $hash,
            ];
            if ($data['account_number'] !== '') {
                $attributes['account_number'] = $data['account_number'];
            }
            if ($account) {
                $account->update($attributes);
            } else {
                $account = $customer->bankAccounts()->create($attributes);
            }
            $saved = $account;
            $this->activity->log($customer, $accountId ? CustomerActivityAction::BankAccountUpdated : CustomerActivityAction::BankAccountAdded, $actor);
            if ($oldPrimaryId !== ($data['is_primary'] ? $account->id : ($oldPrimaryId === $account->id ? null : $oldPrimaryId))) {
                $this->activity->log($customer, CustomerActivityAction::PrimaryBankAccountChanged, $actor);
            }
        }, 3);

        return $saved;
    }

    public function setBankActive(Customer $customer, int $id, bool $active, User $actor): void
    {
        DB::transaction(function () use ($customer, $id, $active, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $account = $customer->bankAccounts()->findOrFail($id);
            if ($account->is_active === $active) {
                return;
            }
            $wasPrimary = $account->is_primary;
            $account->update(['is_active' => $active, 'is_primary' => false, 'primary_active_key' => null]);
            $this->activity->log($customer, $active ? CustomerActivityAction::BankAccountReactivated : CustomerActivityAction::BankAccountDeactivated, $actor);
            if ($wasPrimary) {
                $this->activity->log($customer, CustomerActivityAction::PrimaryBankAccountChanged, $actor);
            }
        }, 3);
    }

    public function saveTin(Customer $customer, ?string $tin, User $actor): void
    {
        $tin = trim((string) Validator::make(['malaysianTin' => $tin], ['malaysianTin' => ['nullable', 'string', 'max:100']])->validate()['malaysianTin']);
        DB::transaction(function () use ($customer, $tin, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $existing = $customer->taxIdentification()->first();
            $customer->taxIdentification()->updateOrCreate([], ['malaysian_tin' => $tin ?: null]);
            $this->activity->log($customer, $existing?->malaysian_tin ? CustomerActivityAction::MalaysianTinUpdated : CustomerActivityAction::MalaysianTinAdded, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveResidency(Customer $customer, array $data, User $actor, ?int $residencyId = null): void
    {
        $data = Validator::make(['residency' => $data], [
            'residency.jurisdiction' => ['required', 'string', 'max:255'],
            'residency.foreign_tin' => ['nullable', 'string', 'max:100'],
            'residency.missing_tin_reason' => ['nullable', Rule::in(CustomerProfileSchema::options('missing_tin_reason'))],
            'residency.missing_tin_explanation' => ['nullable', 'string', 'max:2000'],
            'residency.effective_date' => ['required', 'date', 'before_or_equal:today'],
        ])->validate()['residency'];
        $jurisdiction = trim($data['jurisdiction']);
        $key = mb_strtolower(preg_replace('/\s+/', ' ', $jurisdiction) ?? $jurisdiction);
        $tin = trim((string) $data['foreign_tin']);
        $reason = $data['missing_tin_reason'] ?: null;
        $existingTin = $residencyId ? $this->currentResidency($customer, $residencyId)->foreign_tin : null;
        if (($tin === '' && ! $existingTin && $reason === null) || ($tin !== '' && $reason !== null)) {
            $this->fail('residency.foreign_tin', 'Provide either a foreign TIN or a missing TIN reason.');
        }
        if ($reason === 'B' && trim((string) $data['missing_tin_explanation']) === '') {
            $this->fail('residency.missing_tin_explanation', 'Explain why the customer cannot obtain a TIN.');
        }
        DB::transaction(function () use ($customer, $data, $key, $jurisdiction, $tin, $reason, $actor, $residencyId): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $old = $residencyId ? $this->currentResidency($customer, $residencyId) : null;
            if ($customer->taxResidencies()->where('current_jurisdiction_key', $key)->when($old, fn ($query) => $query->whereKeyNot($old->id))->exists()) {
                $this->fail('residency.jurisdiction', 'A current residency for this jurisdiction already exists.');
            }
            if ($old && $data['effective_date'] < $old->effective_date->toDateString()) {
                $this->fail('residency.effective_date', 'The replacement cannot predate the current declaration.');
            }
            if ($old) {
                $old->update(['ended_on' => $data['effective_date'], 'current_jurisdiction_key' => null]);
            }
            $customer->taxResidencies()->create([
                'jurisdiction' => $jurisdiction,
                'jurisdiction_key' => $key,
                'current_jurisdiction_key' => $key,
                'foreign_tin' => $reason ? null : ($tin ?: $old?->foreign_tin),
                'missing_tin_reason' => $reason,
                'missing_tin_explanation' => $reason === 'B' ? trim((string) $data['missing_tin_explanation']) : null,
                'effective_date' => $data['effective_date'],
            ]);
            $this->activity->log($customer, $old ? CustomerActivityAction::TaxResidencyUpdated : CustomerActivityAction::TaxResidencyAdded, $actor);
        }, 3);
    }

    public function endResidency(Customer $customer, int $residencyId, string $endDate, User $actor): void
    {
        $data = Validator::make(['endDate' => $endDate], ['endDate' => ['required', 'date', 'before_or_equal:today']])->validate();
        DB::transaction(function () use ($customer, $residencyId, $data, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $record = $this->currentResidency($customer, $residencyId);
            if ($data['endDate'] < $record->effective_date->toDateString()) {
                $this->fail('endDate', 'End date cannot predate the effective date.');
            }
            $record->update(['ended_on' => $data['endDate'], 'current_jurisdiction_key' => null]);
            $this->activity->log($customer, CustomerActivityAction::TaxResidencyEnded, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveFatca(Customer $customer, array $data, User $actor, ?int $currentId = null): void
    {
        $data = Validator::make(['fatca' => $data], [
            'fatca.us_person_for_tax' => ['nullable', Rule::in(['', ...CustomerProfileSchema::options('yes_no')])],
            'fatca.us_green_card_holder' => ['nullable', Rule::in(['', ...CustomerProfileSchema::options('yes_no')])],
            'fatca.us_resident' => ['nullable', Rule::in(['', ...CustomerProfileSchema::options('yes_no')])],
            'fatca.selected_classification' => ['nullable', Rule::in(['', ...CustomerProfileSchema::options('fatca_classification')])],
            'fatca.declared_on' => ['required', 'date', 'before_or_equal:today'],
            'fatca.source' => ['required', Rule::in(['staff_entry', 'customer_attestation'])],
        ])->validate()['fatca'];
        DB::transaction(function () use ($customer, $data, $actor, $currentId): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $previous = $customer->fatcaDeclarations()->where('current_customer_key', $customer->id)->first();
            if ($previous?->id !== $currentId) {
                $this->fail('fatca.declared_on', 'This declaration changed while you were editing. Reopen it before saving.');
            }
            if ($previous && $data['declared_on'] < $previous->declared_on->toDateString()) {
                $this->fail('fatca.declared_on', 'A replacement declaration cannot predate the current one.');
            }
            $previous?->update(['current_customer_key' => null]);
            $customer->fatcaDeclarations()->create([
                'current_customer_key' => $customer->id,
                'us_person_for_tax' => $this->yesNo($data['us_person_for_tax']),
                'us_green_card_holder' => $this->yesNo($data['us_green_card_holder']),
                'us_resident' => $this->yesNo($data['us_resident']),
                'selected_classification' => $data['selected_classification'] ?: null,
                'declared_on' => $data['declared_on'],
                'source' => $data['source'],
                'recorded_by' => $actor->id,
            ]);
            $this->activity->log($customer, $previous ? CustomerActivityAction::FatcaDeclarationUpdated : CustomerActivityAction::FatcaDeclarationCreated, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveFinancial(Customer $customer, array $data, User $actor, ?int $currentId = null): void
    {
        $data = Validator::make(['financial' => $data], [
            'financial.monthly_income_rm' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'financial.personal_annual_income_bracket' => ['nullable', Rule::in(CustomerProfileSchema::options('personal_income'))],
            'financial.joint_annual_income_bracket' => ['nullable', Rule::in(CustomerProfileSchema::options('joint_income'))],
            'financial.net_asset_bracket' => ['nullable', Rule::in(CustomerProfileSchema::options('net_assets'))],
            'financial.portfolio_bracket' => ['nullable', Rule::in(CustomerProfileSchema::options('portfolio'))],
            'financial.portfolio_ownership' => ['nullable', Rule::in(CustomerProfileSchema::options('portfolio_ownership'))],
            'financial.wealth_sources' => ['array'],
            'financial.wealth_sources.*' => ['string', Rule::in(CustomerProfileSchema::options('wealth_sources'))],
            'financial.other_wealth_source' => ['nullable', 'string', 'max:255'],
            'financial.declared_on' => ['required', 'date', 'before_or_equal:today'],
            'financial.source' => ['required', Rule::in(['staff_entry', 'customer_attestation'])],
        ])->validate()['financial'];
        if (blank($data['personal_annual_income_bracket'] ?? null)) {
            $suggested = CustomerProfileSchema::personalAnnualBracketFromMonthly($data['monthly_income_rm'] ?? null);
            if ($suggested !== null) {
                $data['personal_annual_income_bracket'] = $suggested;
            }
        }
        $sources = array_values(array_unique($data['wealth_sources'] ?? []));
        if (in_array('other', $sources, true) && blank(trim((string) ($data['other_wealth_source'] ?? '')))) {
            $this->fail('financial.other_wealth_source', 'Specify the other source of wealth or funds.');
        }
        DB::transaction(function () use ($customer, $data, $sources, $actor, $currentId): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $previous = $customer->financialDeclarations()->where('current_customer_key', $customer->id)->first();
            if ($previous?->id !== $currentId) {
                $this->fail('financial.declared_on', 'This declaration changed while you were editing. Reopen it before saving.');
            }
            if ($previous && $data['declared_on'] < $previous->declared_on->toDateString()) {
                $this->fail('financial.declared_on', 'A replacement cannot predate the current declaration.');
            }
            $previous?->update(['current_customer_key' => null]);
            $customer->financialDeclarations()->create([
                'current_customer_key' => $customer->id,
                'monthly_income_rm' => $data['monthly_income_rm'] !== '' && $data['monthly_income_rm'] !== null ? $data['monthly_income_rm'] : null,
                'personal_annual_income_bracket' => $data['personal_annual_income_bracket'] ?: null,
                'joint_annual_income_bracket' => $data['joint_annual_income_bracket'] ?: null,
                'net_asset_bracket' => $data['net_asset_bracket'] ?: null,
                'portfolio_bracket' => $data['portfolio_bracket'] ?: null,
                'portfolio_ownership' => $data['portfolio_ownership'] ?: null,
                'wealth_sources' => $sources,
                'other_wealth_source' => in_array('other', $sources, true) ? trim((string) $data['other_wealth_source']) : null,
                'declared_on' => $data['declared_on'],
                'source' => $data['source'],
                'recorded_by' => $actor->id,
            ]);
            $this->activity->log($customer, $previous ? CustomerActivityAction::FinancialDeclarationUpdated : CustomerActivityAction::FinancialDeclarationCreated, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveInvestmentProfile(Customer $customer, array $data, User $actor): void
    {
        $data = Validator::make(['profile' => $data], [
            'profile.objectives' => ['array'],
            'profile.objectives.*' => ['string', Rule::in(CustomerProfileSchema::options('objectives'))],
            'profile.risk_tolerance' => ['nullable', Rule::in(CustomerProfileSchema::options('risk_tolerance'))],
            'profile.trading_knowledge' => ['nullable', Rule::in(CustomerProfileSchema::options('trading_knowledge'))],
            'profile.products' => ['array'],
            'profile.products.*' => ['string', Rule::in(CustomerProfileSchema::options('products'))],
            'profile.other_product_details' => ['nullable', 'string', 'max:255'],
        ])->validate()['profile'];
        $objectives = array_values(array_unique($data['objectives'] ?? []));
        $products = array_values(array_unique($data['products'] ?? []));
        $otherDetails = trim((string) ($data['other_product_details'] ?? ''));
        if (in_array('other', $products, true) && $otherDetails === '') {
            $this->fail('profile.other_product_details', 'Specify the other investment product.');
        }
        DB::transaction(function () use ($customer, $data, $objectives, $products, $otherDetails, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $existing = $customer->investmentProfiles()->exists();
            $customer->investmentProfiles()->create([
                'objectives' => $objectives,
                'risk_tolerance' => $data['risk_tolerance'] ?: null,
                'trading_knowledge' => $data['trading_knowledge'] ?: null,
                'products' => $products,
                'other_product_details' => in_array('other', $products, true) ? $otherDetails : null,
                'information_source' => 'customer_declared',
                'declared_at' => now(),
                'recorded_by' => $actor->id,
            ]);
            $this->activity->log($customer, $existing ? CustomerActivityAction::InvestmentProfileUpdated : CustomerActivityAction::InvestmentProfileCreated, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveBrokerageDeclaration(Customer $customer, array $data, User $actor, ?int $currentId = null): void
    {
        $data = Validator::make(['brokerageDeclaration' => $data], [
            'brokerageDeclaration.answer' => ['required', Rule::in(CustomerProfileSchema::options('brokerage_answer'))],
            'brokerageDeclaration.declared_on' => ['required', 'date', 'before_or_equal:today'],
            'brokerageDeclaration.source' => ['required', Rule::in(['staff_entry', 'customer_attestation'])],
        ])->validate()['brokerageDeclaration'];
        DB::transaction(function () use ($customer, $data, $actor, $currentId): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $previous = $customer->brokerageDeclarations()->where('current_customer_key', $customer->id)->first();
            if ($previous?->id !== $currentId) {
                $this->fail('brokerageDeclaration.declared_on', 'This declaration changed while you were editing. Reopen it before saving.');
            }
            if ($previous && $data['declared_on'] < $previous->declared_on->toDateString()) {
                $this->fail('brokerageDeclaration.declared_on', 'A replacement cannot predate the current declaration.');
            }
            $previous?->update(['current_customer_key' => null]);
            $customer->brokerageDeclarations()->create([
                'current_customer_key' => $customer->id,
                ...$data,
                'recorded_by' => $actor->id,
            ]);
            $this->activity->log($customer, $previous ? CustomerActivityAction::BrokerageDeclarationUpdated : CustomerActivityAction::BrokerageDeclarationCreated, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveBrokerage(Customer $customer, array $data, User $actor, ?int $accountId = null, bool $confirmDuplicate = false): CustomerBrokerageAccount
    {
        $data = Validator::make(['brokerage' => $data], [
            'brokerage.broker_name' => ['required', 'string', 'max:255'],
            'brokerage.branch_address' => ['nullable', 'string', 'max:255'],
            'brokerage.account_type' => ['required', Rule::in(CustomerProfileSchema::options('brokerage_account_type'))],
        ])->validate()['brokerage'];
        $name = trim($data['broker_name']);
        $branch = trim((string) ($data['branch_address'] ?? ''));
        if ($name === '') {
            $this->fail('brokerage.broker_name', 'Enter a broker name.');
        }
        $saved = null;
        DB::transaction(function () use ($customer, $data, $name, $branch, $actor, $accountId, $confirmDuplicate, &$saved): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $account = $accountId ? $customer->brokerageAccounts()->findOrFail($accountId) : null;
            $normalizedName = mb_strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);
            $normalizedBranch = mb_strtolower(preg_replace('/\s+/', ' ', $branch) ?? $branch);
            $duplicate = $customer->brokerageAccounts()->where('account_type', $data['account_type'])
                ->when($account, fn ($query) => $query->whereKeyNot($account->id))->get()
                ->contains(function (CustomerBrokerageAccount $candidate) use ($normalizedName, $normalizedBranch): bool {
                    $candidateName = mb_strtolower(preg_replace('/\s+/', ' ', trim($candidate->broker_name)) ?? trim($candidate->broker_name));
                    $candidateBranch = mb_strtolower(preg_replace('/\s+/', ' ', trim($candidate->branch_address ?? '')) ?? trim($candidate->branch_address ?? ''));

                    return $candidateName === $normalizedName && $candidateBranch === $normalizedBranch;
                });
            if ($duplicate && ! $confirmDuplicate) {
                $this->fail('confirmDuplicate', 'A matching account is already recorded. Confirm if this is a separate account.');
            }
            $attributes = ['broker_name' => $name, 'branch_address' => $branch ?: null, 'account_type' => $data['account_type']];
            if ($account) {
                $account->update($attributes);
            } else {
                $account = $customer->brokerageAccounts()->create($attributes);
            }
            $saved = $account;
            $this->activity->log($customer, $accountId ? CustomerActivityAction::BrokerageAccountUpdated : CustomerActivityAction::BrokerageAccountAdded, $actor);
        }, 3);

        return $saved;
    }

    public function setBrokerageActive(Customer $customer, int $id, bool $active, User $actor): void
    {
        DB::transaction(function () use ($customer, $id, $active, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $account = $customer->brokerageAccounts()->findOrFail($id);
            if ($account->is_active === $active) {
                return;
            }
            $account->update(['is_active' => $active]);
            $this->activity->log($customer, $active ? CustomerActivityAction::BrokerageAccountReactivated : CustomerActivityAction::BrokerageAccountDeactivated, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function saveComplianceDeclaration(Customer $customer, string $kind, array $data, User $actor): void
    {
        if (! in_array($kind, ['pep', 'bankruptcy', 'petition'], true)) {
            $this->fail('declaration.answer', 'Choose a declaration.');
        }
        $data = Validator::make(['declaration' => $data], [
            'declaration.answer' => ['required', Rule::in(CustomerProfileSchema::options('compliance_answer'))],
            'declaration.remarks' => ['nullable', 'string', 'max:2000'],
            'declaration.declared_on' => ['required', 'date', 'before_or_equal:today'],
            'declaration.source' => ['required', Rule::in(['staff_recorded', 'customer_verbal', 'customer_written'])],
            'declaration.confirmation_status' => ['required', Rule::in(['unconfirmed', 'customer_confirmed'])],
        ])->validate()['declaration'];
        if ($data['confirmation_status'] === 'customer_confirmed' && $data['source'] === 'staff_recorded') {
            $this->fail('declaration.confirmation_status', 'Customer confirmation requires a customer verbal or written source.');
        }
        DB::transaction(function () use ($customer, $kind, $data, $actor): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $old = $customer->complianceDeclarations()->where('current_key', "{$customer->id}:{$kind}")->first();
            if ($old && $data['declared_on'] < $old->declared_on->toDateString()) {
                $this->fail('declaration.declared_on', 'A replacement cannot predate the current declaration.');
            }
            $old?->update(['current_key' => null]);
            $customer->complianceDeclarations()->create([
                'kind' => $kind,
                'answer' => $data['answer'],
                'remarks' => trim((string) $data['remarks']) ?: null,
                'declared_on' => $data['declared_on'],
                'source' => $data['source'],
                'confirmation_status' => $data['confirmation_status'],
                'recorded_by' => $actor->id,
                'current_key' => "{$customer->id}:{$kind}",
            ]);
            $action = match (true) {
                $old && $old->answer === $data['answer'] && $data['confirmation_status'] === 'customer_confirmed' => CustomerActivityAction::ComplianceDeclarationReconfirmed,
                $kind === 'pep' && $old === null => CustomerActivityAction::PepDeclarationCreated,
                $kind === 'pep' => CustomerActivityAction::PepDeclarationUpdated,
                $old === null => CustomerActivityAction::BankruptcyDeclarationCreated,
                default => CustomerActivityAction::BankruptcyDeclarationUpdated,
            };
            $this->activity->log($customer, $action, $actor);
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function savePepAssociation(Customer $customer, array $data, User $actor, ?int $associationId = null): void
    {
        $record = $associationId ? $this->pepRecord($customer, $associationId) : null;
        $submitted = $data['source'] ?? '';
        $source = $record ? ($record->customer_relationship_id ? 'relationship' : ($record->related_person_id ? 'new' : 'self')) : $submitted;
        if ($record && $submitted !== $source) {
            $this->fail('association.source', 'The linked identity cannot be changed during editing.');
        }
        $data['source'] = $source;
        $data = Validator::make(['association' => $data], [
            'association.source' => ['required', Rule::in(['self', 'relationship', 'new'])],
            'association.relationship_id' => ['nullable', 'integer'],
            'association.name' => ['nullable', 'string', 'max:255'],
            'association.relationship' => ['nullable', 'string', 'max:100'],
            'association.occupation' => ['nullable', 'string', 'max:255'],
            'association.position' => ['nullable', 'string', 'max:255'],
            'association.position_meaning' => ['nullable', Rule::in(['', ...CustomerProfileSchema::options('position_meaning')])],
            'association.organization' => ['nullable', 'string', 'max:255'],
            'association.country' => ['nullable', 'string', 'max:100'],
            'association.position_status' => ['required', Rule::in(CustomerProfileSchema::options('position_status'))],
            'association.remarks' => ['nullable', 'string', 'max:2000'],
            'association.started_on' => ['required', 'date', 'before_or_equal:today'],
            'association.identification_type' => ['nullable', Rule::enum(IdentificationType::class)],
            'association.identification_number' => ['nullable', 'string', 'max:100'],
        ])->validate()['association'];
        $errors = [];
        if ($source === 'relationship') {
            $relationship = $customer->relationships()->find($data['relationship_id'] ?? null);
            if (! $relationship || ($record && $relationship->id !== $record->customer_relationship_id)) {
                $errors['association.relationship_id'] = 'Choose an active relationship for this customer.';
            }
        }
        if ($source === 'new' && blank(trim((string) ($data['name'] ?? '')))) {
            $errors['association.name'] = 'Full legal name is required.';
        }
        if ($source === 'new' && blank(trim((string) ($data['relationship'] ?? '')))) {
            $errors['association.relationship'] = 'Relationship or association is required.';
        }
        if ($source === 'new' && ! $record && (bool) ($data['identification_type'] ?? '') !== (bool) ($data['identification_number'] ?? '')) {
            $errors['association.identification_number'] = 'Provide both identification type and number.';
        }
        if ($source === 'new' && ! $record && ! empty($data['identification_number']) && ! empty($data['identification_type'])) {
            $normalized = CustomerIdentification::normalizeNumber($data['identification_number']);
            if (RelatedPerson::query()->where('identification_type', $data['identification_type'])->where('normalized_identification_number', $normalized)->exists()
                || CustomerIdentification::query()->where('type', $data['identification_type'])->where('normalized_number', $normalized)->exists()) {
                $errors['association.identification_number'] = 'This identity already exists. Link its existing relationship where available.';
            }
        }
        if ($source === 'new' && $record && filled($data['identification_number'] ?? '') && blank($data['identification_type'] ?? '')) {
            $errors['association.identification_type'] = 'Select an identification type for the replacement number.';
        }
        if ($source === 'new' && $record && blank($data['identification_number'] ?? '') && filled($data['identification_type'] ?? '') && $data['identification_type'] !== $record->relatedPerson?->identification_type?->value) {
            $errors['association.identification_number'] = 'Enter a replacement number when changing the identification type.';
        }
        if ($source === 'new' && $record && filled($data['identification_number'] ?? '')) {
            $normalized = CustomerIdentification::normalizeNumber($data['identification_number']);
            if (RelatedPerson::query()->whereKeyNot($record->related_person_id)->where('identification_type', $data['identification_type'])->where('normalized_identification_number', $normalized)->exists()
                || CustomerIdentification::query()->where('type', $data['identification_type'])->where('normalized_number', $normalized)->exists()) {
                $errors['association.identification_number'] = 'This identity already exists. Review the person before saving.';
            }
        }
        if ($record && $record->position_meaning === null && ($data['position_meaning'] ?? '') === 'occupation' && filled($data['occupation'] ?? '') && filled($data['position'] ?? '')) {
            $errors['association.position_meaning'] = 'Move the legacy value into Occupation or clear it before classifying.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        try {
            DB::transaction(function () use ($customer, $data, $source, $record, $actor): void {
                Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $relationship = $source === 'relationship' ? $customer->relationships()->findOrFail($data['relationship_id']) : null;
                $person = $record?->relatedPerson;
                if ($source === 'new' && ! $record) {
                    $duplicate = $customer->pepAssociations()->whereNull('ended_on')->whereNull('superseded_at')->with(['relatedPerson', 'customerRelationship.relatedPerson', 'customerRelationship.relatedCustomer', 'customer'])->get()
                        ->first(fn (CustomerPepAssociation $association): bool => mb_strtolower(trim($association->displayName())) === mb_strtolower(trim((string) $data['name'])));
                    if ($duplicate) {
                        $this->fail('association.name', 'An active PEP association already exists for this person.');
                    }
                    $formattedNumber = filled($data['identification_number'] ?? null)
                        ? CustomerIdentification::formatNumber($data['identification_number'], $data['identification_type'] ?: null)
                        : null;
                    $person = RelatedPerson::query()->create([
                        'full_legal_name' => trim((string) $data['name']),
                        'identification_type' => $data['identification_type'] ?: null,
                        'identification_number' => $formattedNumber,
                        'normalized_identification_number' => CustomerIdentification::normalizeNumber($formattedNumber),
                    ]);
                }
                if ($source === 'new' && $record && $person) {
                    $replacementType = filled($data['identification_type'] ?? '') ? $data['identification_type'] : $person->identification_type?->value;
                    $replacementNumber = filled($data['identification_number'] ?? '')
                        ? CustomerIdentification::formatNumber($data['identification_number'], $replacementType)
                        : $person->identification_number;
                    if (trim((string) $data['name']) !== $person->full_legal_name || $replacementNumber !== $person->identification_number || $replacementType !== $person->identification_type?->value) {
                        $person = $person->replicate();
                        $person->fill([
                            'full_legal_name' => trim((string) $data['name']),
                            'identification_type' => $replacementType,
                            'identification_number' => $replacementNumber,
                            'normalized_identification_number' => CustomerIdentification::normalizeNumber($replacementNumber),
                        ])->save();
                    }
                }
                $identity = match ($source) {
                    'self' => "{$customer->id}:self",
                    'relationship' => "{$customer->id}:relationship:{$relationship->id}",
                    default => "{$customer->id}:person:{$person->id}",
                };
                $record ??= new CustomerPepAssociation;
                if ($record->exists && $data['started_on'] < $record->started_on->toDateString()) {
                    $this->fail('association.started_on', 'Start date cannot predate the recorded association.');
                }
                $wasNew = ! $record->exists;
                if (! $wasNew) {
                    $record->update(['active_identity_key' => null, 'superseded_at' => now()]);
                }
                $occupation = trim((string) ($data['occupation'] ?? ''));
                $position = trim((string) ($data['position'] ?? ''));
                $positionMeaning = $data['position_meaning'] ?: null;
                if ($record && $record->position_meaning === null && $positionMeaning === 'occupation') {
                    $occupation = $position;
                    $position = '';
                    $positionMeaning = 'public_position';
                }
                (new CustomerPepAssociation)->fill([
                    'customer_id' => $customer->id,
                    'customer_relationship_id' => $relationship?->id,
                    'related_person_id' => $person?->id,
                    'relationship' => $source === 'self' ? 'Self' : ($source === 'relationship' ? $relationship->relationshipLabel() : trim((string) $data['relationship'])),
                    'occupation' => $occupation ?: null,
                    'position' => $position ?: null,
                    'position_meaning' => $positionMeaning,
                    'organization' => trim((string) ($data['organization'] ?? '')) ?: null,
                    'country' => trim((string) ($data['country'] ?? '')) ?: null,
                    'position_status' => $data['position_status'],
                    'remarks' => trim((string) ($data['remarks'] ?? '')) ?: null,
                    'active_identity_key' => $identity,
                    'started_on' => $data['started_on'],
                    'recorded_by' => $actor->id,
                ])->save();
                $this->activity->log($customer, $wasNew ? CustomerActivityAction::PepRelatedPersonAdded : CustomerActivityAction::PepRelatedPersonUpdated, $actor);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('association.source', 'An active PEP association already exists for this person.');
        }
    }

    public function endPepAssociation(Customer $customer, int $associationId, string $endDate, User $actor): void
    {
        $data = Validator::make(['endDate' => $endDate], ['endDate' => ['required', 'date', 'before_or_equal:today']])->validate();
        DB::transaction(function () use ($customer, $associationId, $data, $actor): void {
            $record = $this->pepRecord($customer, $associationId);
            if ($data['endDate'] < $record->started_on->toDateString()) {
                $this->fail('endDate', 'End date cannot predate the start date.');
            }
            $record->update(['ended_on' => $data['endDate'], 'active_identity_key' => null]);
            $this->activity->log($customer, CustomerActivityAction::PepAssociationEnded, $actor);
        }, 3);
    }

    private function syncContact(Customer $customer, ContactType $type, ?string $value, bool $isPrimary): void
    {
        $existing = $customer->contacts()->where('type', $type)->when($type === ContactType::Social, fn ($query) => $query->whereNull('platform'))->first();
        if (blank($value)) {
            $existing?->delete();

            return;
        }
        $payload = ['value' => $value, 'normalized_value' => CustomerContact::normalizeValue($type, $value), 'is_primary' => $isPrimary];
        if ($existing) {
            $existing->update($payload);
        } else {
            $customer->contacts()->create(['type' => $type, ...$payload]);
        }
    }

    private function syncSocialContact(Customer $customer, string $platform, ?string $value): void
    {
        $existing = $customer->contacts()->where('type', ContactType::Social)->where('platform', $platform)->first();
        if (blank($value)) {
            $existing?->delete();

            return;
        }
        $payload = ['value' => trim($value), 'normalized_value' => CustomerContact::normalizeValue(ContactType::Social, $value), 'is_primary' => false];
        if ($existing) {
            $existing->update($payload);
        } else {
            $customer->contacts()->create(['type' => ContactType::Social, 'platform' => $platform, ...$payload]);
        }
    }

    private function currentResidency(Customer $customer, int $id): CustomerTaxResidency
    {
        return $customer->taxResidencies()->whereNotNull('current_jurisdiction_key')->findOrFail($id);
    }

    private function pepRecord(Customer $customer, int $id): CustomerPepAssociation
    {
        return $customer->pepAssociations()->whereNull('ended_on')->whereNull('superseded_at')->with(['relatedPerson', 'customerRelationship.relatedPerson', 'customerRelationship.relatedCustomer'])->findOrFail($id);
    }

    private function yesNo(mixed $answer): ?bool
    {
        return $answer === '' || $answer === null ? null : $answer === 'yes';
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
