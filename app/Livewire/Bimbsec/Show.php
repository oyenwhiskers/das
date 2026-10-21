<?php

namespace App\Livewire\Bimbsec;

use App\Enums\ContactType;
use App\Enums\CustomerActivityAction;
use App\Livewire\Concerns\NotifiesBrowser;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerBankAccount;
use App\Models\CustomerContact;
use App\Models\CustomerEmployment;
use App\Models\CustomerFinancialDeclaration;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\BankStatementProfileRules;
use App\Services\Bimbsec\Catalog;
use App\Services\Bimbsec\Generator;
use App\Services\Bimbsec\Resolver;
use App\Services\CustomerActivityLogger;
use App\Services\CustomerProfileSchema;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('BIMBSEC Application — DAS')]
class Show extends Component
{
    use AuthorizesRequests;
    use NotifiesBrowser;

    public Customer $customer;

    public BimbsecApplication $application;

    public ?string $account_type = null;

    public string $margin_credit_limit = '';

    public ?string $lth_group_employee = null;

    public ?string $online_trading = null;

    public ?string $estatement = null;

    public ?string $payment = null;

    public string $relationship_id = '';

    public string $bank_account_id = '';

    public string $old_nric = '';

    public string $home_telephone = '';

    public string $malaysian_tin = '';

    public string $occupation = '';

    public string $employer_name = '';

    public string $registration_number = '';

    public string $industry = '';

    public string $sub_industry = '';

    public string $employer_address = '';

    public string $employer_city = '';

    public string $employer_state = '';

    public string $employer_postcode = '';

    public string $employer_country = '';

    public string $office_telephone = '';

    public string $fax = '';

    public string $employment_date = '';

    public string $monthly_income = '';

    public string $personal_income = '';

    public string $joint_income = '';

    public string $net_assets = '';

    public string $portfolio = '';

    public string $portfolio_ownership = '';

    public string $other_wealth_source = '';

    /** @var list<string> */
    public array $wealth_sources = [];

    public string $kin_occupation = '';

    public string $kin_employer_city = '';

    public string $kin_employer_state = '';

    public string $kin_employer_postcode = '';

    public string $kin_home_telephone = '';

    public string $kin_email = '';

    public ?int $employment_id = null;

    public ?int $financial_id = null;

    public ?int $relationship_record_id = null;

    public bool $kin_editable = false;

    public bool $kin_linked = false;

    public bool $fromProfile = false;

    /** @var array<string, string> */
    public array $overflow_values = [];

    public function mount(Customer $customer, BimbsecApplication $application): void
    {
        abort_unless($application->customer_id === $customer->id, 404);
        $fromProfile = request('from') === 'profile';
        $current = $customer->currentBimbsecApplication();
        $this->customer = $customer;
        $this->application = $current ?? $application;
        if ($current !== null && $current->id !== $application->id) {
            $this->redirect(route('bimbsec.show', $fromProfile
                ? [$customer, $current, 'from' => 'profile']
                : [$customer, $current]));

            return;
        }
        $this->fromProfile = $fromProfile;
        $this->authorize('view', $application);
        $choices = $application->choices ?? [];
        $this->account_type = $choices['account_type'] ?? 'trading';
        $this->margin_credit_limit = (string) ($choices['margin_credit_limit'] ?? '');
        $this->lth_group_employee = $choices['lth_group_employee'] ?? null;
        $this->online_trading = $choices['online_trading'] ?? 'no';
        $this->estatement = $choices['estatement'] ?? 'email';
        $this->payment = $choices['payment'] ?? 'epayment';
        $this->relationship_id = (string) ($choices['relationship_id'] ?? '');
        $this->bank_account_id = (string) ($choices['bank_account_id'] ?? '');
        $this->fillProfile(app(Resolver::class));
    }

    public function updatedRelationshipId(): void
    {
        $this->loadKin(app(Resolver::class));
    }

    public function saveForm(CustomerProfileWrites $writes, Generator $generator, Resolver $resolver, CustomerActivityLogger $activity): void
    {
        $this->authorize('update', $this->application);
        $choices = $this->validatedChoices();
        if ($choices === null) {
            return;
        }
        $this->application->update(['choices' => $choices, 'updated_by' => auth()->id()]);
        $customer = $this->customer->fresh();
        $this->writeProfile($writes, $activity, $customer, auth()->user());
        $generator->generate($this->application->refresh(), auth()->user());
        $this->application->unsetRelation('document');
        $this->customer->refresh();
        $this->overflow_values = [];
        $this->fillProfile($resolver);
        $this->notifySuccess('Saved. The customer profile and this PDF now use these values.');
    }

    public function generate(Generator $generator): void
    {
        $this->authorize('update', $this->application);
        $choices = $this->validatedChoices();
        if ($choices === null) {
            return;
        }
        $this->application->update(['choices' => $choices, 'updated_by' => auth()->id()]);
        $generator->generate($this->application->refresh(), auth()->user());
        $this->application->unsetRelation('document');
        $this->application->refresh();
        $this->notifySuccess('PDF generated from the current profile and application choices.');
    }

    public function saveOverflow(Generator $generator, Resolver $resolver, CustomerActivityLogger $activity): void
    {
        $this->authorize('update', $this->application);
        $customer = $this->customer->fresh(['primaryIdentification', 'residentialAddress', 'mailingAddress', 'bankAccounts', 'employments', 'relationships.relatedPerson', 'relationships.relatedCustomer', 'taxIdentification']);
        $fields = $this->blockingFields($generator->assess($customer, $this->application->choices ?? [])['warnings'], $customer);
        if ($fields === []) {
            return;
        }
        foreach ($fields as $field) {
            $value = trim((string) ($this->overflow_values[$field['key']] ?? ''));
            if ($value === '' && in_array($field['reference'], ['personal.legal_name', 'identification.current_nric_or_passport'], true)) {
                $this->addError('overflow_values.'.$field['key'], 'Enter a shorter '.$field['label'].'.');

                return;
            }
            $this->writeOverflow($activity, $resolver, $customer, $field, $value);
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
        }
        $generator->generate($this->application->refresh(), auth()->user());
        $this->application->unsetRelation('document');
        $this->customer->refresh();
        $this->overflow_values = [];
        $this->fillProfile($resolver);
        $this->notifySuccess('Saved. The customer profile and this PDF now use these values.');
    }

    public function render(Resolver $resolver, Generator $generator): View
    {
        $this->authorize('view', $this->application);
        $this->customer->load(['relationships.relatedPerson', 'relationships.relatedCustomer', 'bankAccounts']);
        $generator->refresh($this->application, auth()->user());
        $this->application->unsetRelation('document');
        $assessment = $generator->assess($this->customer->fresh(), $this->application->choices ?? []);
        $warnings = array_map(
            fn (array $warning): array => $this->plainWarning($warning),
            $assessment['warnings'],
        );
        $blocking = $this->blockingFields($warnings);
        $this->rememberOverflow($blocking);

        return view('livewire.bimbsec.show', [
            'document' => $this->application->document,
            'warnings' => $warnings,
            'relationships' => $this->customer->relationships,
            'banks' => $this->customer->bankAccounts,
            'personalIncome' => CustomerProfileSchema::PERSONAL_INCOME,
            'jointIncome' => CustomerProfileSchema::JOINT_INCOME,
            'netAssets' => CustomerProfileSchema::NET_ASSETS,
            'portfolioOptions' => CustomerProfileSchema::PORTFOLIO,
            'wealthOptions' => CustomerProfileSchema::WEALTH_SOURCES,
            'blocking' => $blocking,
            'blocked' => $assessment['blocked'],
        ])->layout('layouts.app');
    }

    private function fillProfile(Resolver $resolver): void
    {
        $customer = $this->customer->load(['primaryIdentification', 'contacts', 'taxIdentification', 'financialDeclarations', 'employments']);
        $this->old_nric = (string) $customer->primaryIdentification?->old_nric;
        $this->home_telephone = (string) $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Home && $contact->platform === null)?->value;
        $this->malaysian_tin = (string) $customer->taxIdentification?->malaysian_tin;
        $subjects = $resolver->subjects($customer, $this->application->choices ?? []);
        $job = $subjects['employment'];
        $this->employment_id = $job?->id;
        $this->occupation = (string) $job?->occupation;
        $this->employer_name = (string) $job?->employer_name;
        $this->registration_number = (string) $job?->registration_number;
        $this->industry = (string) $job?->industry;
        $this->sub_industry = (string) $job?->sub_industry;
        $this->employer_address = (string) $job?->address_line_1;
        $this->employer_city = (string) $job?->city;
        $this->employer_state = (string) $job?->state;
        $this->employer_postcode = (string) $job?->postcode;
        $this->employer_country = (string) $job?->country;
        $this->office_telephone = (string) $job?->direct_telephone;
        $this->fax = (string) $job?->fax;
        $this->employment_date = $customer->employment_status === 'retired'
            ? (string) $customer->retirement_date?->toDateString()
            : (string) $job?->start_date?->toDateString();
        $financial = $customer->financialDeclarations->firstWhere('current_customer_key', $customer->id);
        $this->financial_id = $financial?->id;
        $this->monthly_income = $financial?->monthly_income_rm === null ? '' : (string) $financial->monthly_income_rm;
        $this->personal_income = (string) $financial?->personal_annual_income_bracket;
        $this->joint_income = (string) $financial?->joint_annual_income_bracket;
        $this->net_assets = (string) $financial?->net_asset_bracket;
        $this->portfolio = (string) $financial?->portfolio_bracket;
        $this->portfolio_ownership = (string) $financial?->portfolio_ownership;
        $this->other_wealth_source = (string) $financial?->other_wealth_source;
        $this->wealth_sources = array_values($financial?->wealth_sources ?? []);
        $this->loadKin($resolver);
    }

    private function loadKin(Resolver $resolver): void
    {
        $choices = $this->application->choices ?? [];
        $choices['relationship_id'] = $this->relationship_id === '' ? null : $this->relationship_id;
        $relationship = $resolver->subjects($this->customer, $choices)['relationship'];
        $person = $relationship?->relatedPerson;
        $linked = $relationship?->relatedCustomer;
        $this->relationship_record_id = $relationship?->id;
        $this->kin_linked = $relationship?->related_customer_id !== null;
        $this->kin_editable = $person !== null;
        if ($linked instanceof Customer) {
            $job = $linked->employments->first(fn (CustomerEmployment $row): bool => $row->is_primary && $row->is_current)
                ?? $linked->employments->first();
            $this->kin_occupation = (string) $job?->occupation;
            $this->kin_employer_city = (string) $job?->city;
            $this->kin_employer_state = (string) $job?->state;
            $this->kin_employer_postcode = (string) $job?->postcode;
            $this->kin_home_telephone = (string) $linked->contacts->firstWhere('type', ContactType::Home)?->value;
            $this->kin_email = (string) $linked->primary_email;
        } else {
            $this->kin_occupation = (string) $person?->occupation;
            $this->kin_employer_city = (string) $person?->employer_city;
            $this->kin_employer_state = (string) $person?->employer_state;
            $this->kin_employer_postcode = (string) $person?->employer_postcode;
            $this->kin_home_telephone = (string) $person?->home_telephone;
            $this->kin_email = (string) $person?->email;
        }
    }

    private function writeProfile(CustomerProfileWrites $writes, CustomerActivityLogger $activity, Customer $customer, User $user): void
    {
        $customer->load(['primaryIdentification', 'contacts', 'taxIdentification', 'financialDeclarations', 'employments', 'relationships.relatedPerson']);
        $this->writeOldNric($activity, $customer, $user);
        $this->writeHomeTelephone($writes, $customer, $user);
        $this->writeTin($writes, $customer, $user);
        $this->writeEmployment($writes, $customer, $user);
        $this->writeFinancial($writes, $customer, $user);
        $this->writeKin($writes, $customer, $user);
    }

    private function writeOldNric(CustomerActivityLogger $activity, Customer $customer, User $user): void
    {
        $identification = $customer->primaryIdentification;
        if ($identification === null || trim((string) $identification->old_nric) === trim($this->old_nric)) {
            return;
        }
        // shortcut: savePersonal would fill a blank race with Malay, so only the old NRIC column is written
        $identification->update(['old_nric' => trim($this->old_nric) === '' ? null : trim($this->old_nric)]);
        $activity->log($customer, CustomerActivityAction::Updated, $user, 'Old NRIC updated from BIMBSEC');
    }

    private function writeHomeTelephone(CustomerProfileWrites $writes, Customer $customer, User $user): void
    {
        $home = (string) $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Home && $contact->platform === null)?->value;
        if (trim($home) === trim($this->home_telephone)) {
            return;
        }
        $social = [];
        foreach (['facebook', 'instagram', 'twitter'] as $platform) {
            $social[$platform] = $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Social && $contact->platform === $platform)?->value;
        }
        $this->attempt(fn () => $writes->saveContact($customer, [
            'mobile' => $customer->primary_mobile,
            'home_telephone' => trim($this->home_telephone),
            'email' => $customer->primary_email,
            'social' => $customer->contacts->first(fn (CustomerContact $contact): bool => $contact->type === ContactType::Social && $contact->platform === null)?->value,
            'socialAccounts' => $social,
        ], $user));
    }

    private function writeTin(CustomerProfileWrites $writes, Customer $customer, User $user): void
    {
        if (trim((string) $customer->taxIdentification?->malaysian_tin) === trim($this->malaysian_tin)) {
            return;
        }
        $writes->saveTin($customer, $this->malaysian_tin, $user);
    }

    private function writeEmployment(CustomerProfileWrites $writes, Customer $customer, User $user): void
    {
        $job = $this->employment_id ? $customer->employments->firstWhere('id', $this->employment_id) : null;
        if ($customer->employment_status === 'retired' && ($customer->retirement_date?->toDateString() ?? '') !== $this->employment_date) {
            $this->attempt(fn () => $writes->saveEmploymentStatus($customer, [
                'employmentStatus' => 'retired',
                'employmentStatusOther' => $customer->employment_status_other,
                'retirementDate' => $this->employment_date,
            ], $user));
        }
        $fields = [
            'arrangement' => $job?->arrangement,
            'arrangement_other' => $customer->employment_status_other,
            'occupation' => $this->occupation,
            'employer_name' => $this->employer_name,
            'registration_number' => $this->registration_number,
            'industry' => $this->industry,
            'sub_industry' => $this->sub_industry,
            'start_date' => $customer->employment_status === 'retired' ? $job?->start_date?->toDateString() : ($this->employment_date ?: null),
            'end_date' => $job?->end_date?->toDateString(),
            'is_current' => $job?->is_current ?? true,
            'is_primary' => $job?->is_primary ?? true,
            'general_telephone' => $job?->general_telephone,
            'direct_telephone' => $this->office_telephone,
            'fax' => $this->fax,
            'address_line_1' => $this->employer_address,
            'address_line_2' => $job?->address_line_2,
            'city' => $this->employer_city,
            'state' => $this->employer_state,
            'postcode' => $this->employer_postcode,
            'country' => $this->employer_country,
        ];
        if ($job === null && blank($fields['occupation']) && blank($fields['employer_name']) && blank($fields['arrangement'])) {
            return;
        }
        if ($job instanceof CustomerEmployment && $this->employmentUnchanged($job, $fields)) {
            return;
        }
        $this->attempt(fn () => $writes->saveEmployment($customer, $fields, $user, $job?->id, true));
    }

    /** @param  array<string, mixed>  $fields */
    private function employmentUnchanged(CustomerEmployment $job, array $fields): bool
    {
        foreach (['occupation', 'employer_name', 'registration_number', 'industry', 'sub_industry', 'direct_telephone', 'fax', 'address_line_1', 'city', 'state', 'postcode', 'country'] as $key) {
            if (trim((string) $job->{$key}) !== trim((string) $fields[$key])) {
                return false;
            }
        }

        return ($job->start_date?->toDateString() ?? '') === (string) $fields['start_date'];
    }

    private function writeFinancial(CustomerProfileWrites $writes, Customer $customer, User $user): void
    {
        $current = $customer->financialDeclarations->firstWhere('current_customer_key', $customer->id);
        $sources = array_values($this->wealth_sources);
        if ($current instanceof CustomerFinancialDeclaration && $this->moneySame($current->monthly_income_rm, $this->monthly_income)
            && (string) $current->personal_annual_income_bracket === $this->personal_income
            && (string) $current->joint_annual_income_bracket === $this->joint_income
            && (string) $current->net_asset_bracket === $this->net_assets
            && (string) $current->portfolio_bracket === $this->portfolio
            && array_values($current->wealth_sources ?? []) == $sources
            && trim((string) $current->other_wealth_source) === trim($this->other_wealth_source)) {
            return;
        }
        if ($current === null && trim($this->monthly_income) === '' && $this->personal_income === '' && $this->joint_income === '' && $this->net_assets === '' && $this->portfolio === '' && $sources === []) {
            return;
        }
        $this->attempt(fn () => $writes->saveFinancial($customer, [
            'monthly_income_rm' => $this->monthly_income,
            'personal_annual_income_bracket' => $this->personal_income,
            'joint_annual_income_bracket' => $this->joint_income,
            'net_asset_bracket' => $this->net_assets,
            'portfolio_bracket' => $this->portfolio,
            'portfolio_ownership' => $this->portfolio_ownership,
            'wealth_sources' => $sources,
            'other_wealth_source' => $this->other_wealth_source,
            'declared_on' => now()->toDateString(),
            'source' => 'staff_entry',
        ], $user, $current?->id));
    }

    private function writeKin(CustomerProfileWrites $writes, Customer $customer, User $user): void
    {
        if (! $this->kin_editable || $this->relationship_record_id === null) {
            return;
        }
        $record = $customer->relationships->firstWhere('id', $this->relationship_record_id);
        $person = $record?->relatedPerson;
        if (! $record instanceof CustomerRelationship || $person === null) {
            return;
        }
        if (trim((string) $person->occupation) === trim($this->kin_occupation)
            && trim((string) $person->employer_city) === trim($this->kin_employer_city)
            && trim((string) $person->employer_state) === trim($this->kin_employer_state)
            && trim((string) $person->employer_postcode) === trim($this->kin_employer_postcode)
            && trim((string) $person->home_telephone) === trim($this->kin_home_telephone)
            && trim((string) $person->email) === trim($this->kin_email)) {
            return;
        }
        $this->attempt(fn () => $writes->saveRelationship($customer, [
            'source' => 'new',
            'fullLegalName' => $person->full_legal_name,
            'relationship' => $record->relationship,
            'otherRelationship' => $record->other_relationship,
            'identificationType' => $person->identification_type?->value ?? $person->identification_type,
            'identificationNumber' => $person->identification_number,
            'dateOfBirth' => $person->date_of_birth?->toDateString(),
            'gender' => $person->gender,
            'nationality' => $person->nationality,
            'mobile' => $person->mobile,
            'homeTelephone' => $this->kin_home_telephone,
            'email' => $this->kin_email,
            'occupation' => $this->kin_occupation,
            'employerName' => $person->employer_name,
            'employerAddress' => $person->employer_address,
            'employerAddressLine1' => $person->employer_address_line_1,
            'employerAddressLine2' => $person->employer_address_line_2,
            'employerCity' => $this->kin_employer_city,
            'employerState' => $this->kin_employer_state,
            'employerPostcode' => $this->kin_employer_postcode,
            'employerCountry' => $person->employer_country,
            'isNextOfKin' => (bool) $record->is_next_of_kin,
            'isPrimaryNextOfKin' => (bool) $record->is_primary_next_of_kin,
        ], $user, $record));
    }

    private function moneySame(mixed $stored, string $input): bool
    {
        if (trim($input) === '') {
            return $stored === null || $stored === '';
        }

        return is_numeric($stored) && (float) $stored === (float) $input;
    }

    private function attempt(callable $write): void
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            $map = [
                'form.occupation' => 'occupation', 'form.employer_name' => 'employer_name', 'form.registration_number' => 'registration_number',
                'form.direct_telephone' => 'office_telephone', 'form.fax' => 'fax', 'form.start_date' => 'employment_date',
                'form.address_line_1' => 'employer_address', 'form.city' => 'employer_city', 'form.state' => 'employer_state',
                'form.postcode' => 'employer_postcode', 'form.country' => 'employer_country',
                'financial.monthly_income_rm' => 'monthly_income', 'financial.other_wealth_source' => 'other_wealth_source',
                'financial.declared_on' => 'monthly_income', 'home_telephone' => 'home_telephone',
                'homeTelephone' => 'kin_home_telephone', 'email' => 'kin_email', 'occupation' => 'kin_occupation',
            ];
            $messages = [];
            foreach ($exception->errors() as $key => $errors) {
                $messages[$map[$key] ?? $key] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }

    /** @return array<string, mixed>|null */
    private function validatedChoices(): ?array
    {
        $choices = BimbsecApplication::normalizeChoices([
            'account_type' => $this->account_type,
            'margin_credit_limit' => $this->margin_credit_limit,
            'lth_group_employee' => $this->lth_group_employee,
            'online_trading' => $this->online_trading,
            'estatement' => $this->estatement,
            'payment' => $this->payment,
            'relationship_id' => $this->relationship_id === '' ? null : $this->relationship_id,
            'bank_account_id' => $this->bank_account_id === '' ? null : $this->bank_account_id,
        ]);
        if (is_int($choices['relationship_id']) && ! $this->customer->relationships()->whereKey($choices['relationship_id'])->exists()) {
            $this->addError('relationship_id', 'Choose a related person on this customer.');

            return null;
        }
        if (is_int($choices['bank_account_id']) && ! $this->customer->bankAccounts()->whereKey($choices['bank_account_id'])->exists()) {
            $this->addError('bank_account_id', 'Choose a bank account on this customer.');

            return null;
        }

        return $choices;
    }

    /** @param  array<string, mixed>  $warning
     * @return array<string, mixed>
     */
    private function plainWarning(array $warning): array
    {
        $message = (string) ($warning['message'] ?? '');
        if (preg_match('/has (\d+) (?:characters|records) and the form fits (\d+)/', $message, $match) !== 1) {
            return $warning;
        }
        $dropped = max(0, (int) $match[1] - (int) $match[2]);
        $records = str_contains($message, 'records');
        $reference = $warning['reference_key'] ?? null;
        $item = (string) ($warning['printed_item'] ?? '');
        if (! is_string($reference) && is_string($warning['field_id'] ?? null)) {
            $mapped = collect(app(Catalog::class)->fields())->firstWhere('id', $warning['field_id']);
            $reference = $mapped['source']['reference_key'] ?? null;
            $item = (string) ($mapped['printed_item'] ?? $item);
        }
        $label = is_string($reference) ? $this->overflowLabel($reference, $item) : $this->humanLabel((string) ($warning['label'] ?? 'This field'));
        $left = $records
            ? $dropped.' '.($dropped === 1 ? 'entry was' : 'entries were').' left off.'
            : $dropped.' '.($dropped === 1 ? 'letter was' : 'letters were').' left off.';
        $warning['label'] = $label;
        $warning['message'] = $label.' is too long for the form. '.$left;

        return $warning;
    }

    private function humanLabel(string $label): string
    {
        return match ($label) {
            'address_lines' => 'Address',
            'employer_name_and_address' => 'Employer name and address',
            'nric_or_passport' => 'NRIC or passport',
            'legal_name' => 'Name',
            'account_number' => 'Account number',
            default => ucfirst(str_replace('_', ' ', $label)),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array{key: string, label: string, message: string, reference: string, item: string, value: string}>
     */
    private function blockingFields(array $warnings, ?Customer $customer = null): array
    {
        $customer ??= $this->customer;
        $fields = [];
        foreach ($warnings as $warning) {
            if (($warning['level'] ?? '') !== 'blocking') {
                continue;
            }
            $reference = $warning['reference_key'] ?? null;
            $item = (string) ($warning['printed_item'] ?? '');
            if (! is_string($reference) && is_string($warning['field_id'] ?? null)) {
                $mapped = collect(app(Catalog::class)->fields())->firstWhere('id', $warning['field_id']);
                $reference = $mapped['source']['reference_key'] ?? null;
                $item = (string) ($mapped['printed_item'] ?? $item);
            }
            if (! is_string($reference)) {
                continue;
            }
            $fields[] = [
                'key' => str_replace('.', '_', $reference).'__'.str_replace('.', '_', $item),
                'label' => $this->overflowLabel($reference, $item),
                'message' => (string) ($warning['message'] ?? ''),
                'reference' => $reference,
                'item' => $item,
                'value' => $this->overflowCurrent($customer, $reference, $item),
            ];
        }

        return $fields;
    }

    /** @param  list<array{key: string, value: string}>  $fields */
    private function rememberOverflow(array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field['key'], $this->overflow_values)) {
                $this->overflow_values[$field['key']] = $field['value'];
            }
        }
    }

    private function overflowLabel(string $reference, string $item): string
    {
        $names = [
            'personal.legal_name' => 'Name of applicant',
            'identification.current_nric_or_passport' => 'NRIC / passport',
            'identification.old_nric' => 'Old NRIC',
            'tax.malaysian_tin' => 'Malaysian TIN',
            'banking.references' => 'Bank account number',
        ];
        if (isset($names[$reference])) {
            return $names[$reference];
        }
        $place = match ($reference) {
            'addresses.residential' => 'Residential address',
            'addresses.mailing' => 'Mailing address',
            'employment.business_address' => 'Employer address',
            'relationships.spouse_or_next_of_kin' => 'Spouse or next of kin',
            default => 'Field',
        };
        $part = match ($item) {
            'address_lines', 'employer_name_and_address', '' => '',
            'legal_name' => 'name',
            'nric_or_passport' => 'NRIC / passport',
            default => str_replace('_', ' ', $item),
        };

        return trim($place.' '.$part);
    }

    private function overflowCurrent(Customer $customer, string $reference, string $item): string
    {
        $address = match ($reference) {
            'addresses.residential' => $customer->residentialAddress,
            'addresses.mailing' => $customer->mailingAddress?->same_as_residential ? $customer->residentialAddress : $customer->mailingAddress,
            default => null,
        };
        if ($address instanceof CustomerAddress) {
            return $item === 'address_lines'
                ? trim(implode(' ', array_filter([$address->line_1, $address->line_2])))
                : (string) ($address->{$item} ?? '');
        }
        $resolver = app(Resolver::class);
        $job = $resolver->subjects($customer, $this->application->choices ?? [])['employment'];
        if ($reference === 'employment.business_address' && $job !== null) {
            if ($resolver->businessAddressFieldFromResidential($customer, $job, $item === '' ? 'address_lines' : $item)) {
                $fallback = BankStatementProfileRules::preferredSelfEmployedAddressFromCustomer($customer);

                return $item === 'address_lines'
                    ? trim(implode(' ', array_filter([$fallback['line_1'] ?? null, $fallback['line_2'] ?? null])))
                    : (string) ($fallback[$item] ?? '');
            }

            return $item === 'address_lines'
                ? trim(implode(' ', array_filter([$job->address_line_1, $job->address_line_2])))
                : (string) ($job->{$item} ?? '');
        }

        return match ($reference) {
            'personal.legal_name' => (string) $customer->full_legal_name,
            'identification.current_nric_or_passport' => (string) ($customer->primaryIdentification?->number ?: $customer->primary_identification_number),
            'identification.old_nric' => (string) $customer->primaryIdentification?->old_nric,
            'tax.malaysian_tin' => (string) $customer->taxIdentification?->malaysian_tin,
            'banking.references' => (string) $this->overflowBank($customer)?->account_number,
            'relationships.spouse_or_next_of_kin' => $this->overflowKin($customer, $item),
            default => '',
        };
    }

    private function overflowKin(Customer $customer, string $item): string
    {
        $relationship = app(Resolver::class)->subjects($customer, $this->application->choices ?? [])['relationship'];
        $person = $relationship?->relatedPerson ?? $relationship?->relatedCustomer;
        if ($person === null) {
            return '';
        }

        return match ($item) {
            'legal_name' => (string) ($person->full_legal_name ?? ''),
            'nric_or_passport' => (string) ($person->identification_number ?? $person->primary_identification_number ?? ''),
            'employer_name_and_address' => trim(implode(' ', array_filter([
                $person->employer_name ?? null,
                $person->employer_address_line_1 ?? $person->address_line_1 ?? null,
            ]))),
            default => (string) ($person->{'employer_'.$item} ?? $person->{$item} ?? ''),
        };
    }

    private function overflowBank(Customer $customer): ?CustomerBankAccount
    {
        $choice = BimbsecApplication::normalizeChoices($this->application->choices ?? [])['bank_account_id'];
        if (is_int($choice)) {
            return $customer->bankAccounts->firstWhere('id', $choice);
        }

        return $customer->bankAccounts->first(fn (CustomerBankAccount $account): bool => $account->is_active && $account->is_primary);
    }

    /** @param  array{reference: string, item: string, key: string, label: string}  $field */
    private function writeOverflow(CustomerActivityLogger $activity, Resolver $resolver, Customer $customer, array $field, string $value): void
    {
        $user = auth()->user();
        $reference = $field['reference'];
        $item = $field['item'];
        if (in_array($reference, ['addresses.residential', 'addresses.mailing'], true)) {
            $this->writeOverflowAddress($activity, $customer, $user, $reference === 'addresses.mailing', $item, $value);

            return;
        }
        if ($reference === 'employment.business_address') {
            $job = $resolver->subjects($customer, $this->application->choices ?? [])['employment'];
            if ($job === null) {
                return;
            }
            $column = $item === 'address_lines' ? 'address_line_1' : $item;
            $job->update($item === 'address_lines'
                ? ['address_line_1' => $value === '' ? null : $value, 'address_line_2' => null]
                : [$column => $value === '' ? null : $value]);
            $activity->log($customer, CustomerActivityAction::EmploymentUpdated, $user, 'Employer address corrected from BIMBSEC');

            return;
        }
        if ($reference === 'personal.legal_name') {
            $customer->update(['full_legal_name' => $value, 'updated_by' => $user->id]);
            $activity->log($customer, CustomerActivityAction::Updated, $user, 'Name corrected from BIMBSEC');

            return;
        }
        if ($reference === 'identification.old_nric') {
            $customer->primaryIdentification?->update(['old_nric' => $value === '' ? null : $value]);
            $activity->log($customer, CustomerActivityAction::Updated, $user, 'Old NRIC corrected from BIMBSEC');

            return;
        }
        if ($reference === 'identification.current_nric_or_passport') {
            $type = $customer->primary_identification_type ?? $customer->primaryIdentification?->type;
            $formatted = \App\Models\CustomerIdentification::formatNumber($value === '' ? null : $value, $type) ?? '';
            $customer->update(['primary_identification_number' => $formatted === '' ? null : $formatted, 'updated_by' => $user->id]);
            $customer->primaryIdentification?->update([
                'number' => $formatted === '' ? null : $formatted,
                'normalized_number' => \App\Models\CustomerIdentification::normalizeNumber($formatted === '' ? null : $formatted),
            ]);
            $activity->log($customer, CustomerActivityAction::Updated, $user, 'NRIC corrected from BIMBSEC');

            return;
        }
        if ($reference === 'tax.malaysian_tin') {
            $customer->taxIdentification()->updateOrCreate([], ['malaysian_tin' => $value === '' ? null : $value]);
            $activity->log($customer, CustomerActivityAction::MalaysianTinUpdated, $user, 'Malaysian TIN corrected from BIMBSEC');

            return;
        }
        if ($reference === 'banking.references') {
            $account = $this->overflowBank($customer);
            if ($account === null) {
                return;
            }
            $account->update([
                'account_number' => $value,
                'identity_hash' => CustomerBankAccount::identityHash($account->bank_name, (string) $account->bank_country, $value),
            ]);
            $activity->log($customer, CustomerActivityAction::BankAccountUpdated, $user, 'Bank account number corrected from BIMBSEC');

            return;
        }
        if ($reference === 'relationships.spouse_or_next_of_kin') {
            $relationship = $resolver->subjects($customer, $this->application->choices ?? [])['relationship'];
            $person = $relationship?->relatedPerson;
            if ($person === null) {
                return;
            }
            $column = match ($item) {
                'legal_name' => 'full_legal_name',
                'nric_or_passport' => 'identification_number',
                'employer_name_and_address' => 'employer_address',
                default => str_starts_with($item, 'employer_') ? $item : 'employer_'.$item,
            };
            if ($column === 'identification_number') {
                $formatted = \App\Models\CustomerIdentification::formatNumber($value === '' ? null : $value, $person->identification_type) ?? null;
                $person->update([
                    'identification_number' => $formatted,
                    'normalized_identification_number' => \App\Models\CustomerIdentification::normalizeNumber($formatted),
                ]);
            } else {
                $person->update([$column => $value === '' ? null : $value]);
            }
            $activity->log($customer, CustomerActivityAction::Updated, $user, 'Spouse or next of kin corrected from BIMBSEC');

            return;
        }
        $this->addError('overflow_values.'.$field['key'], 'Shorten this on the customer profile.');
    }

    private function writeOverflowAddress(CustomerActivityLogger $activity, Customer $customer, User $user, bool $mailing, string $item, string $value): void
    {
        $generator = app(Generator::class);
        $mirror = $generator->mailingMirrorsResidential($customer, $item !== '' ? $item : null);
        if ($mailing && $mirror) {
            $mailing = false;
        }
        $address = $mailing ? $customer->mailingAddress : $customer->residentialAddress;
        if ($address === null) {
            $address = $customer->addresses()->create([
                'type' => $mailing ? \App\Enums\AddressType::Mailing : \App\Enums\AddressType::Residential,
                'same_as_residential' => false,
            ]);
        }
        $payload = $item === 'address_lines'
            ? ['line_1' => $value === '' ? null : $value, 'line_2' => null]
            : [$item => $value === '' ? null : $value];
        if ($mailing) {
            $payload['same_as_residential'] = false;
        }
        $address->update($payload);
        if (! $mailing) {
            $mail = $customer->mailingAddress;
            if ($mail !== null && $mail->same_as_residential !== true && $mirror) {
                $mail->update($payload + ['same_as_residential' => false]);
            }
        }
        $activity->log($customer, CustomerActivityAction::Updated, $user, ($mailing ? 'Mailing' : 'Residential').' address corrected from BIMBSEC');
    }
}
