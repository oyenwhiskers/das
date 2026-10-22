<?php

namespace App\Services\Bimbsec;

use App\Enums\ContactType;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerEmployment;
use App\Models\CustomerRelationship;
use App\Models\RelatedPerson;
use App\Services\BankStatementProfileRules;
use App\Services\CustomerProfileSchema;
use Illuminate\Support\Carbon;

class Resolver
{
    /** @var list<array{level: string, label: string, message: string, field_id: ?string}> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private Catalog $catalog) {}

    /**
     * @param  array<string, mixed>  $choices
     * @return array{draws: list<array{field: array<string, mixed>, kind: string, text: ?string, on: bool, choice: ?string, rows: list<string>}>, warnings: list<array{level: string, label: string, message: string, field_id: ?string}>, source_hash: string}
     */
    public function resolve(Customer $customer, array $choices): array
    {
        $this->warnings = [];
        $this->seen = [];
        $choices = BimbsecApplication::normalizeChoices($choices);
        $context = $this->context($customer, $choices);
        $draws = [];
        $manual = 0;
        foreach ($this->catalog->fields() as $field) {
            if ($this->skip($field)) {
                $manual++;

                continue;
            }
            $draw = $this->drawFor($field, $context);
            if ($draw !== null) {
                $draws[] = $draw;
            }
        }
        if ($manual > 0) {
            $this->warn('manual', 'Manual signing', $manual.' signature, declaration, checklist and office boxes are left blank for handwriting.', null);
        }
        $hash = hash('sha256', json_encode([
            'schema' => CustomerProfileSchema::VERSION,
            'template' => $this->catalog->templateHash,
            'mapping' => $this->catalog->mappingHash,
            'customer' => $customer->id,
            'choices' => $choices,
            'draws' => array_map(fn (array $draw): array => [
                'id' => $draw['field']['id'],
                'text' => $draw['text'],
                'on' => $draw['on'],
                'choice' => $draw['choice'],
                'rows' => $draw['rows'],
            ], $draws),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return ['draws' => $draws, 'warnings' => $this->warnings, 'source_hash' => $hash];
    }

    /**
     * @param  array<string, mixed>  $choices
     * @return array{relationship: ?CustomerRelationship, employment: ?CustomerEmployment}
     */
    public function subjects(Customer $customer, array $choices): array
    {
        $this->warnings = [];
        $this->seen = [];
        $choices = BimbsecApplication::normalizeChoices($choices);
        $customer->loadMissing([
            'relationships.relatedPerson', 'relationships.relatedCustomer.employments',
            'relationships.relatedCustomer.contacts', 'employments',
        ]);

        return [
            'relationship' => $this->person($customer, $choices),
            'employment' => $this->employment($customer),
        ];
    }

    /** @param  array<string, mixed>  $field */
    private function skip(array $field): bool
    {
        $scope = (string) ($field['source']['scope'] ?? '');
        $policy = (string) ($field['fill_policy'] ?? '');

        return ! ($field['should_autofill'] ?? false)
            || in_array($policy, ['manual', 'preserve'], true)
            || in_array($scope, ['authorized_office', 'manual_review'], true)
            || in_array($field['source']['reference_key'] ?? '', [
                'application.bisonline_login_id', 'application.principal_parties',
                'application.authorized_signatory_name', 'application.authorized_signatory_id',
                'application.witness_name', 'application.witness_id',
            ], true);
    }

    /**
     * @param  array<string, mixed>  $choices
     * @return array<string, mixed>
     */
    private function context(Customer $customer, array $choices): array
    {
        $customer->load([
            'primaryIdentification', 'contacts', 'residentialAddress', 'mailingAddress',
            'relationships.relatedPerson', 'relationships.relatedCustomer.primaryIdentification',
            'relationships.relatedCustomer.contacts', 'relationships.relatedCustomer.employments',
            'employments', 'bankAccounts', 'taxIdentification', 'taxResidencies', 'fatcaDeclarations',
            'financialDeclarations', 'latestInvestmentProfile', 'brokerageDeclarations', 'brokerageAccounts',
            'complianceDeclarations', 'pepAssociations.relatedPerson', 'pepAssociations.customerRelationship.relatedPerson',
            'pepAssociations.customerRelationship.relatedCustomer.primaryIdentification',
        ]);

        return [
            'customer' => $customer,
            'choices' => $choices,
            'person' => $this->person($customer, $choices),
            'employment' => $this->employment($customer),
            'bank' => $this->bank($customer, $choices),
            'fatca' => $customer->fatcaDeclarations->firstWhere('current_customer_key', $customer->id),
            'financial' => $customer->financialDeclarations->firstWhere('current_customer_key', $customer->id),
            'investment' => $customer->latestInvestmentProfile,
            'brokerage' => $customer->brokerageDeclarations->firstWhere('current_customer_key', $customer->id),
            'pep' => $customer->complianceDeclarations->firstWhere('current_key', $customer->id.':pep'),
        ];
    }

    /** @param  array<string, mixed>  $choices */
    private function person(Customer $customer, array $choices): ?CustomerRelationship
    {
        $relationships = $customer->relationships;
        $choice = $choices['relationship_id'] ?? null;
        if ($choice === 'none') {
            return null;
        }
        if (is_int($choice)) {
            $selected = $relationships->firstWhere('id', $choice);
            if ($selected === null) {
                $this->warn('attention', 'Spouse or next of kin', 'The chosen related person is not on this customer. The family section was left blank.', null);
            }

            return $selected;
        }
        $active = $relationships->where('is_primary_next_of_kin', true)->values();
        if ($active->count() === 1) {
            return $active->first();
        }
        if ($relationships->count() === 1) {
            return $relationships->first();
        }
        if ($relationships->isNotEmpty()) {
            $this->warn('attention', 'Spouse or next of kin', 'More than one related person could fill this section. Mark one as active on the customer profile, or choose one on this application. The family section was left blank.', null);
        }

        return null;
    }

    private function employment(Customer $customer): ?CustomerEmployment
    {
        $rows = $customer->employments;
        $primary = $rows->first(fn (CustomerEmployment $row): bool => $row->is_primary && $row->is_current);
        if ($primary !== null) {
            return $primary;
        }
        if ($rows->count() === 1) {
            return $rows->first();
        }
        if ($rows->isNotEmpty()) {
            $this->warn('attention', 'Employment', 'No single primary employment is marked. Employment details were left blank.', null);
        }

        return null;
    }

    /** @param  array<string, mixed>  $choices */
    private function bank(Customer $customer, array $choices): ?CustomerBankAccount
    {
        $choice = $choices['bank_account_id'] ?? null;
        if ($choice === 'none') {
            return null;
        }
        if (is_int($choice)) {
            $selected = $customer->bankAccounts->firstWhere('id', $choice);
            if ($selected === null) {
                $this->warn('attention', 'Bank account', 'The chosen bank account is not on this customer. The bank section was left blank.', null);
            }

            return $selected;
        }
        // Active sole Malaysia accounts only; primary disambiguates when several qualify.
        $eligible = $customer->bankAccounts->filter(fn (CustomerBankAccount $account): bool => $account->is_active && $this->applicantOwned($account) && $this->malaysia($account->bank_country));
        $primary = $eligible->where('is_primary', true);
        if ($primary->count() === 1) {
            return $primary->first();
        }
        if ($eligible->count() === 1) {
            return $eligible->first();
        }
        if ($customer->bankAccounts->isNotEmpty()) {
            $this->warn('attention', 'Bank account', 'No single primary sole Malaysia bank account is marked. Choose one on this application. The bank section was left blank.', null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $context
     * @return array{field: array<string, mixed>, kind: string, text: ?string, on: bool, choice: ?string, rows: list<string>}|null
     */
    private function drawFor(array $field, array $context): ?array
    {
        $kind = match ($field['placement']['type'] ?? '') {
            'text', 'character_cells' => 'text',
            'date_character_cells' => 'date',
            'checkbox' => 'check',
            'boolean_choices', 'enum_choice' => 'choice',
            'repeating_table_cell' => 'rows',
            default => 'skip',
        };
        if ($kind === 'text' && ($field['placement']['type'] ?? '') === 'character_cells') {
            $kind = 'cells';
        }
        $value = $this->value($field, $context);
        $draw = [
            'field' => $field,
            'kind' => $kind,
            'text' => null,
            'on' => false,
            'choice' => null,
            'rows' => [],
        ];
        if ($kind === 'check') {
            $draw['on'] = $value === true;
            if ($value === null && $draw['on'] === false) {
                return $value === null ? null : $draw;
            }

            return $draw['on'] ? $draw : null;
        }
        if ($kind === 'choice') {
            $draw['choice'] = is_string($value) ? $value : null;

            return $draw['choice'] === null ? null : $draw;
        }
        if ($kind === 'rows') {
            $draw['rows'] = is_array($value) ? array_values(array_filter($value, fn ($row): bool => is_string($row) && $row !== '')) : [];

            return $draw['rows'] === [] ? null : $draw;
        }
        $draw['text'] = is_string($value) && $value !== '' ? $value : null;
        if ($draw['text'] === null && in_array($kind, ['text', 'cells', 'date'], true)) {
            return null;
        }

        return $draw['text'] === null ? null : $draw;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $context
     */
    private function value(array $field, array $context): mixed
    {
        $key = (string) ($field['source']['reference_key'] ?? '');
        $item = (string) ($field['printed_item'] ?? '');
        $customer = $context['customer'];

        return match ($key) {
            'personal.salutation' => $this->text($customer->salutation, 'Salutation'),
            'personal.legal_name' => $this->text($customer->full_legal_name, 'Name of applicant', 'attention'),
            'identification.current_nric_or_passport' => $this->text($customer->primaryIdentification?->number ?: $customer->primary_identification_number, 'NRIC / passport', 'attention'),
            'identification.old_nric' => $this->text($customer->primaryIdentification?->old_nric, 'Old NRIC'),
            'personal.date_of_birth' => $this->digits($customer->date_of_birth, 'Date of birth'),
            'personal.marital_status' => $this->marital($customer->marital_status, $item),
            'personal.nationality' => $this->nationality($customer->nationality, $item),
            'personal.country_of_residence' => $this->text($customer->country_of_residence, 'Country of residence'),
            'personal.gender' => $this->one($customer->gender, ['male' => 'Male', 'female' => 'Female'], $item, 'Gender'),
            'personal.race' => $this->race($customer->race, $item),
            'addresses.residential' => $this->address($customer->residentialAddress, $item, false),
            'addresses.property_status' => $this->occupancy($customer->residentialAddress?->occupancy_status?->value, $customer->residentialAddress?->occupancy_other, $item),
            'addresses.mailing' => $customer->mailingAddress?->same_as_residential === true
                ? null
                : $this->address($customer->mailingAddress, $item, true),
            'contact.home_phone' => $this->contact($customer, ContactType::Home, 'Home telephone'),
            'contact.mobile_phone' => $this->contact($customer, ContactType::Mobile, 'Mobile telephone'),
            'contact.email' => $this->contact($customer, ContactType::Email, 'Email'),
            'contact.social_account' => $this->social($customer),
            'relationships.spouse_or_next_of_kin' => $this->relative($customer, $context['person'], $item, $field),
            'employment.lth_group_employee' => $this->yesNo($context['choices']['lth_group_employee'] ?? null, $item, 'LTH Group employee', false, 'no'),
            'employment.status' => $this->employmentStatus($customer, $item),
            'employment.occupation_designation' => $this->text($context['employment']?->occupation, 'Occupation'),
            'employment.employer_name' => $this->text($context['employment']?->employer_name, 'Employer'),
            'employment.employer_general_phone' => $this->employerGeneralPhone($customer, $context['employment']),
            'employment.business_registration_no' => $this->text($context['employment']?->registration_number, 'Business registration'),
            'employment.industry' => $this->text($context['employment']?->industry, 'Industry'),
            'employment.sub_industry' => $this->text($context['employment']?->sub_industry, 'Sub-industry'),
            'employment.business_address' => $this->employmentAddress($customer, $context['employment'], $item),
            'employment.office_direct_phone' => $this->text($context['employment']?->direct_telephone, 'Office telephone'),
            'employment.fax' => $this->text($context['employment']?->fax, 'Fax'),
            'employment.start_or_retirement_date' => $this->employmentDate($customer, $context['employment']),
            'banking.references' => $this->bankValue($context['bank'], $item),
            'financial.monthly_income' => $this->money($context['financial']?->monthly_income_rm),
            'financial.personal_gross_annual_income_bracket' => $this->one($context['financial']?->personal_annual_income_bracket, [
                'up_to_30000' => '≤ RM30,000', '30001_60000' => 'RM30,001-RM60,000', '60001_120000' => 'RM60,001-RM120,000',
                '120001_180000' => 'RM120,001-RM180,000', '180001_300000' => 'RM180,001–RM300,000', 'over_300000' => '> RM300,000',
            ], $item, 'Personal income'),
            'financial.joint_annual_income_bracket' => $this->one($context['financial']?->joint_annual_income_bracket, [
                'up_to_50000' => '≤ RM50,000', '50001_100000' => 'RM50,001-RM100,000', '100001_200000' => 'RM100,001-RM200,000',
                '200001_300000' => 'RM200,001-RM300,000', '300001_400000' => 'RM300,001-RM400,000', 'over_400000' => '> RM400,000',
            ], $item, 'Joint income'),
            'financial.net_asset_bracket' => $this->one($context['financial']?->net_asset_bracket, [
                'up_to_50000' => '≤ RM50,000', '50001_200000' => 'RM50,001-RM200,000', '200001_500000' => 'RM200,001-RM500,000',
                '500001_1000000' => 'RM500,001-RM1,000,000', '1000001_3000000' => 'RM1,000,001-RM3,000,000', 'over_3000000' => '>RM3,000,000',
            ], $item, 'Net assets'),
            'financial.net_investment_portfolio_bracket' => $this->one($context['financial']?->portfolio_bracket, [
                'up_to_50000' => '≤ RM50,000', '50001_100000' => 'RM50,001-RM100,000', '100001_300000' => 'RM100,001-RM300,000',
                '300001_500000' => 'RM300,001-RM500,000', 'source_fifth_range' => 'RM500,001-RM1,000,00', 'over_1000000' => '>RM1,000,000',
            ], $item, 'Portfolio'),
            'financial.source_of_wealth_funds' => $this->many($context['financial']?->wealth_sources, [
                'savings' => 'Savings', 'salary' => 'Salary', 'business_investment_income' => 'Business/Investment Income',
                'rental_income' => 'Rental Income', 'inherited_wealth' => 'Inherited Wealth', 'shareholdings' => 'Shareholdings',
                'asset' => 'Asset', 'directorship' => 'Directorship', 'credit_facility' => 'Credit Facility',
                'retained_earnings' => 'Retained Earnings', 'other' => 'Others',
            ], $item, $context['financial']?->other_wealth_source, 'Source of wealth'),
            'investment.objectives' => $this->many($context['investment']?->objectives, [
                'short_term' => 'Short Term <1 year', 'long_term' => 'Long Term >3 years',
                'dividend_income' => 'Dividend Income', 'capital_growth' => 'Capital Growth',
            ], $item, null, 'Investment objectives'),
            'investment.risk_tolerance' => $this->one($context['investment']?->risk_tolerance, [
                'conservative' => 'Conservative', 'moderate' => 'Moderate', 'aggressive' => 'Aggressive',
            ], $item, 'Risk tolerance'),
            'investment.trading_knowledge' => $this->one($context['investment']?->trading_knowledge, [
                'beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advance',
            ], $item, 'Trading knowledge'),
            'investment.products' => $this->many($context['investment']?->products, [
                'local_equities' => 'Equities trading (local)', 'foreign_equities' => 'Equities trading (foreign)',
                'short_term_share_margin' => 'Retail Equity – Short term share margin financing',
                'share_margin' => 'Retail Equity – Share margin financing', 'other' => 'Others',
            ], $item, $context['investment']?->other_product_details, 'Investment products'),
            'investment.has_other_brokers' => $this->yesNo($context['brokerage']?->answer, $item, 'Other brokerage accounts', false, 'no'),
            'investment.other_broker_accounts' => $this->brokers($customer, $item),
            'compliance.pep_exposure' => $this->yesNo($context['pep']?->answer, $item, 'PEP declaration'),
            'compliance.pep_people' => $this->pep($customer, $item),
            'tax.malaysian_tin' => $this->text($customer->taxIdentification?->malaysian_tin, 'Malaysian TIN'),
            'tax.us_person_for_irs' => $this->boolYesNo($context['fatca']?->us_person_for_tax, $item, 'US person for tax'),
            'tax.us_green_card_holder' => $this->boolYesNo($context['fatca']?->us_green_card_holder, $item, 'US green card'),
            'tax.us_resident' => $this->boolYesNo($context['fatca']?->us_resident, $item, 'US resident'),
            'tax.us_person_classification_marked' => $this->classification($context['fatca'], $item),
            'tax.no_tin_reason_boxes' => $this->tinReasons($customer, $item),
            'tax.foreign_residencies' => $this->residencies($customer, $item),
            'application.account_type' => $this->accountType($context['choices'], $item),
            'application.applicant_verification_mode' => $this->verification($context['choices'], $item),
            'application.dealings_capacity' => $this->dealings($context['choices'], $item),
            'application.margin_credit_limit_applied' => $this->margin($context['choices']),
            'application.online_trading_requested' => $this->yesNo($context['choices']['online_trading'] ?? null, $item, 'Online trading', true),
            'application.estatement_delivery_option' => $this->one($context['choices']['estatement'] ?? null, ['email' => 'by e-mail', 'bisonline' => 'BISonline'], $item, 'Statement delivery', true),
            'application.payment_instruction' => $this->one($context['choices']['payment'] ?? null, [
                'cheque' => 'To issue cheque payable to me', 'epayment' => 'e-Payment – credit directly into my bank account',
            ], $item, 'Payment instruction', true),
            'application.trading_document_checklist' => in_array($item, [
                'Certified true copy of Applicant NRIC/Passport',
                'Certified true copy of latest 1 month bank statement/passbook',
            ], true),
            'compliance.related_to_bssb_staff' => $this->yesNo(null, $item, 'Related to a BSSB director, employee or dealer\'s representative', false, 'no'),
            'compliance.related_to_bssb_clients' => $this->yesNo(null, $item, 'Related to a BSSB client', false, 'no'),
            'compliance.bssb_staff_relatives', 'compliance.bssb_client_relatives' => null,
            default => $this->unmapped($key),
        };
    }

    private function text(mixed $value, string $label, string $level = 'optional'): ?string
    {
        if (blank($value)) {
            $this->warn($level, $label, $label.' has no value in the Customer Profile and was left blank.', null);

            return null;
        }

        return trim((string) $value);
    }

    private function digits(mixed $value, string $label): ?string
    {
        if (blank($value)) {
            $this->warn('optional', $label, $label.' has no value and was left blank.', null);

            return null;
        }
        try {
            return Carbon::parse($value)->format('dmY');
        } catch (\Throwable) {
            $this->warn('attention', $label, $label.' is not a valid date and was left blank.', null);

            return null;
        }
    }

    private function marital(mixed $value, string $item): bool
    {
        $printed = match (strtolower(trim((string) $value))) {
            'married' => 'Married',
            'divorced' => 'Divorced',
            default => 'Single',
        };

        return $printed === $item;
    }

    /** @param  array<string, string>  $map */
    private function one(mixed $value, array $map, string $item, string $label, bool $required = false): mixed
    {
        if (blank($value)) {
            $this->warn($required ? 'attention' : 'optional', $label, $label.' was not answered and was left blank.', null);

            return null;
        }
        $key = strtolower(trim((string) $value));
        $printed = $map[$key] ?? $map[$value] ?? null;
        if ($printed === null) {
            $this->warn('attention', $label, $label.' has a value with no box on this form and was left blank.', null);

            return null;
        }
        if (str_starts_with($item, 'Others (specify)')) {
            return null;
        }

        return $printed === $item;
    }

    /** @param  array<int, string>|null  $values
     * @param  array<string, string>  $map
     */
    private function many(?array $values, array $map, string $item, ?string $other, string $label): mixed
    {
        if ($values === null || $values === []) {
            $this->warn('optional', $label, $label.' has no selection and was left blank.', null);

            return null;
        }
        $printed = [];
        foreach ($values as $value) {
            if (isset($map[$value])) {
                $printed[] = $map[$value];
            } else {
                $this->warn('attention', $label, $label.' contains a value with no box on this form.', null);
            }
        }
        if (str_starts_with($item, 'Others (specify)')) {
            return in_array('Others', $printed, true) ? $this->text($other, $label.' other') : null;
        }

        return in_array($item, $printed, true);
    }

    private function nationality(?string $value, string $item): mixed
    {
        if (blank($value)) {
            $this->warn('optional', 'Nationality', 'Nationality has no value and was left blank.', null);

            return null;
        }
        $malaysian = strtolower(trim($value)) === 'malaysian';
        if (str_starts_with($item, 'Others (specify)')) {
            return $malaysian ? null : trim($value);
        }
        if ($item === 'Malaysian') {
            return $malaysian;
        }
        if ($item === 'Others') {
            return ! $malaysian;
        }

        return null;
    }

    private function race(?string $value, string $item): mixed
    {
        if (blank($value)) {
            $this->warn('optional', 'Race', 'Race has no value and was left blank.', null);

            return null;
        }
        $map = ['malay' => 'Malay', 'chinese' => 'Chinese', 'indian' => 'Indian'];
        $printed = $map[strtolower(trim($value))] ?? null;
        if (str_starts_with($item, 'Others (specify)')) {
            return $printed === null ? trim($value) : null;
        }
        if ($item === 'Others') {
            return $printed === null;
        }

        return $printed === $item;
    }

    private function occupancy(?string $value, ?string $other, string $item): mixed
    {
        if (blank($value)) {
            $this->warn('optional', 'Property status', 'Property status has no value and was left blank.', null);

            return null;
        }
        $map = ['owned' => 'Owned', 'rented' => 'Rented', 'other' => 'Others'];
        $printed = $map[$value] ?? null;
        if ($printed === null) {
            $this->warn('attention', 'Property status', 'Property status has a value with no box on this form and was left blank.', null);

            return null;
        }
        if (str_starts_with($item, 'Others (specify)')) {
            return $printed === 'Others' ? $this->text($other, 'Property status other') : null;
        }

        return $printed === $item;
    }

    private function address(mixed $address, string $item, bool $mailing): mixed
    {
        $label = $mailing ? 'Mailing address' : 'Residential address';
        if ($address === null) {
            $this->warn('optional', $label, $label.' has no value and was left blank.', null);

            return null;
        }
        if ($item === 'address_lines') {
            return $this->text(trim(implode(' ', array_filter([$address->line_1, $address->line_2]))), $label);
        }

        return $this->text($address->{$item} ?? null, $this->titled($label, $item));
    }

    private function contact(Customer $customer, ContactType $type, string $label): ?string
    {
        $value = $customer->contacts->first(fn ($contact): bool => $contact->type === $type)?->value
            ?? ($type === ContactType::Mobile ? $customer->primary_mobile : ($type === ContactType::Email ? $customer->primary_email : null));

        return $this->text($value, $label);
    }

    private function social(Customer $customer): ?string
    {
        $general = $customer->contacts->first(fn ($contact): bool => $contact->type === ContactType::Social && blank($contact->platform));
        if ($general !== null) {
            return $this->text($general->value, 'Social account');
        }
        if ($customer->contacts->contains(fn ($contact): bool => $contact->type === ContactType::Social && filled($contact->platform))) {
            $this->warn('attention', 'Social account', 'Only a platform-specific social handle is stored. The combined social box was left blank.', null);
        }

        return null;
    }

    private function relative(Customer $customer, ?CustomerRelationship $relationship, string $item, array $field): mixed
    {
        if ($relationship === null) {
            return null;
        }
        $role = $relationship->relationship === 'spouse' ? 'spouse' : 'next_of_kin';
        if (($field['placement']['type'] ?? '') === 'enum_choice') {
            return $role;
        }
        $person = $this->relativePayload($customer, $relationship);
        if ($item === 'relationship_specification') {
            return $role === 'next_of_kin' ? $relationship->relationshipLabel() : null;
        }
        if ($item === 'date_of_birth') {
            return $this->digits($person['date_of_birth'] ?? null, 'Spouse or next of kin date of birth');
        }
        $column = [
            'legal_name' => 'name', 'nric_or_passport' => 'nric', 'employer_name_and_address' => 'employer',
            'employer_city' => 'employer_city', 'employer_state' => 'employer_state', 'employer_postcode' => 'employer_postcode',
            'employer_country' => 'employer_country', 'occupation' => 'occupation', 'home_phone' => 'home',
            'mobile_phone' => 'mobile', 'email' => 'email',
        ][$item] ?? null;
        if ($column === null) {
            return $this->unmapped($item);
        }
        $level = in_array($item, ['legal_name', 'nric_or_passport'], true) ? 'attention' : 'optional';

        return $this->text($person[$column] ?? null, $this->titled('Spouse or next of kin', $item), $level);
    }

    /** @return array<string, mixed> */
    private function relativePayload(Customer $customer, CustomerRelationship $relationship): array
    {
        $linked = $relationship->relatedCustomer;
        $person = $relationship->relatedPerson;
        if ($linked instanceof Customer) {
            $job = $linked->employments->first(fn (CustomerEmployment $row): bool => $row->is_primary && $row->is_current) ?? $linked->employments->first();

            return [
                'name' => $linked->full_legal_name,
                'nric' => $linked->primaryIdentification?->number ?: $linked->primary_identification_number,
                'date_of_birth' => $linked->date_of_birth,
                'occupation' => $job?->occupation,
                'employer' => trim(implode(' ', array_filter([$job?->employer_name, $job?->address_line_1, $job?->address_line_2]))),
                'employer_city' => $job?->city, 'employer_state' => $job?->state, 'employer_postcode' => $job?->postcode, 'employer_country' => $job?->country,
                'home' => $linked->contacts->firstWhere('type', ContactType::Home)?->value,
                'mobile' => $linked->primary_mobile,
                'email' => $linked->primary_email,
            ];
        }
        if ($person instanceof RelatedPerson) {
            $line1 = $person->employer_address_line_1;
            $line2 = $person->employer_address_line_2;
            $city = $person->employer_city;
            $state = $person->employer_state;
            $postcode = $person->employer_postcode;
            $country = $person->employer_country;
            if (BankStatementProfileRules::relatedLooksSelfEmployed($person->occupation, $person->employer_name)) {
                $source = BankStatementProfileRules::preferredSelfEmployedAddressFromCustomer($customer);
                $line1 = filled($line1) ? $line1 : ($source['line_1'] ?: null);
                $line2 = filled($line2) ? $line2 : ($source['line_2'] ?: null);
                $city = filled($city) ? $city : ($source['city'] ?: null);
                $state = filled($state) ? $state : ($source['state'] ?: null);
                $postcode = filled($postcode) ? $postcode : ($source['postcode'] ?: null);
                $country = filled($country) ? $country : ($source['country'] ?: null);
            }

            return [
                'name' => $person->full_legal_name,
                'nric' => $person->identification_number,
                'date_of_birth' => $person->date_of_birth,
                'occupation' => $person->occupation,
                'employer' => trim(implode(' ', array_filter([$person->employer_name, $line1, $line2, $person->employer_address]))),
                'employer_city' => $city, 'employer_state' => $state,
                'employer_postcode' => $postcode, 'employer_country' => $country,
                'home' => $person->home_telephone, 'mobile' => $person->mobile, 'email' => $person->email,
            ];
        }

        return [];
    }

    private function employmentStatus(Customer $customer, string $item): mixed
    {
        $map = ['employed' => 'Employed', 'self_employed' => 'Self-Employed', 'retired' => 'Retired', 'other' => 'Others'];
        if ($customer->employment_status === 'unemployed') {
            $this->warn('attention', 'Employment status', 'Unemployed has no box on this form and was left blank.', null);

            return null;
        }
        if (str_starts_with($item, 'Others (specify)')) {
            return $customer->employment_status === 'other' ? $this->text($customer->employment_status_other, 'Employment status other') : null;
        }

        return $this->one($customer->employment_status, $map, $item, 'Employment status');
    }

    /** Self-employed and this office-address part is blank: use mailing, else residential. */
    public function businessAddressFieldFromResidential(Customer $customer, ?CustomerEmployment $employment, string $item): bool
    {
        if ($employment === null) {
            return false;
        }
        if ($customer->employment_status !== 'self_employed' && $employment->arrangement !== 'self_employed') {
            return false;
        }
        if (! BankStatementProfileRules::hasAddress(BankStatementProfileRules::preferredSelfEmployedAddressFromCustomer($customer))) {
            return false;
        }
        if ($item === 'address_lines') {
            return blank($employment->address_line_1) && blank($employment->address_line_2);
        }

        return blank($employment->{$item} ?? null);
    }

    /** Self-employed and employer general phone blank: use applicant mobile. */
    private function employerGeneralPhone(Customer $customer, ?CustomerEmployment $employment): ?string
    {
        $phone = $employment?->general_telephone;
        if (blank($phone) && BankStatementProfileRules::customerIsSelfEmployed($customer, $employment?->arrangement)) {
            $phone = $customer->contacts->first(fn ($contact): bool => $contact->type === ContactType::Mobile)?->value
                ?? $customer->primary_mobile;
        }

        return $this->text($phone, 'Employer telephone');
    }

    private function employmentAddress(Customer $customer, ?CustomerEmployment $employment, string $item): ?string
    {
        if ($employment === null) {
            return null;
        }
        $fallback = BankStatementProfileRules::preferredSelfEmployedAddressFromCustomer($customer);
        if ($item === 'address_lines') {
            if ($this->businessAddressFieldFromResidential($customer, $employment, 'address_lines')) {
                $line1 = $fallback['line_1'] ?: null;
                $line2 = $fallback['line_2'] ?: null;
            } else {
                $line1 = $employment->address_line_1;
                $line2 = $employment->address_line_2;
            }

            return $this->text(trim(implode(' ', array_filter([$line1, $line2]))), 'Employer address');
        }
        $value = $employment->{$item} ?? null;
        if ($this->businessAddressFieldFromResidential($customer, $employment, $item)) {
            $value = $fallback[$item] ?: null;
        }

        return $this->text($value, $this->titled('Employer', $item));
    }

    private function employmentDate(Customer $customer, ?CustomerEmployment $employment): ?string
    {
        if ($customer->employment_status === 'retired') {
            return $this->digits($customer->retirement_date, 'Retirement date');
        }

        return $this->digits($employment?->start_date, 'Employment date');
    }

    private function bankValue(?CustomerBankAccount $account, string $item): mixed
    {
        if ($account === null) {
            return null;
        }
        $value = match ($item) {
            'bank' => $account->bank_name,
            'branch_or_address' => $account->branch_address,
            'account_type' => match ($account->account_type) {
                'SAVINGS', 'savings' => 'Savings', 'CURRENT', 'current' => 'Current', 'OTHER', 'other' => 'Other', default => null,
            },
            'account_number' => $account->account_number,
            default => null,
        };
        if ($item === 'account_type' && $value === null && filled($account->account_type)) {
            $this->warn('attention', 'Bank account type', 'Bank account type has a value with no label on this form and was left blank.', null);
        }

        return $value === null ? null : [$value === '' ? '' : (string) $value];
    }

    private function money(mixed $value): ?string
    {
        if (blank($value)) {
            $this->warn('optional', 'Monthly income', 'Monthly income has no value and was left blank.', null);

            return null;
        }

        return number_format((float) $value, 2, '.', ',');
    }

    private function brokers(Customer $customer, string $item): mixed
    {
        $rows = $customer->brokerageAccounts->filter(fn ($account): bool => $account->is_active !== false)->values();
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() > 3) {
            $this->warn('attention', 'Other brokers', $rows->count().' other brokerage accounts are recorded and the form fits 3. Extra accounts were not printed.', null);
        }
        $slice = $rows->take(3);
        $values = $slice->map(fn ($account, int $index): string => match ($item) {
            'row_no' => (string) ($index + 1),
            'broker_name' => (string) $account->broker_name,
            'branch_or_address' => (string) ($account->branch_address ?? ''),
            'account_type' => match ($account->account_type) {
                'trading' => 'Trading', 'margin' => 'Margin', default => '',
            },
            default => '',
        })->filter(fn (string $value): bool => $value !== '')->values()->all();

        return $values === [] ? null : $values;
    }

    private function pep(Customer $customer, string $item): mixed
    {
        $rows = $customer->pepAssociations->filter(fn ($row): bool => $row->superseded_at === null)->values();
        if ($rows->isEmpty()) {
            return null;
        }
        if ($rows->count() > 3) {
            $this->warn(in_array($item, ['name', 'nric'], true) ? 'blocking' : 'attention', 'PEP persons', $rows->count().' PEP persons are recorded and the form fits 3. Extra persons were not printed.', null);
        }
        $values = [];
        foreach ($rows->take(3) as $index => $row) {
            $linked = $row->relatedPerson ?? $row->customerRelationship?->relatedPerson;
            $relatedCustomer = $row->customerRelationship?->relatedCustomer;
            $name = $linked?->full_legal_name ?: $relatedCustomer?->full_legal_name;
            $nric = $linked?->identification_number ?: $relatedCustomer?->primaryIdentification?->number;
            if (in_array($item, ['name', 'nric'], true) && blank($item === 'name' ? $name : $nric)) {
                $this->warn('attention', 'PEP person', 'A PEP record has no '.$item.' on the linked person. That box was left blank.', null);
            }
            $value = match ($item) {
                'row_no' => (string) ($index + 1),
                'name' => (string) ($name ?? ''),
                'relationship' => (string) ($row->relationship ?? ''),
                'occupation' => (string) ($row->occupation ?? ''),
                'nric' => (string) ($nric ?? ''),
                'employer_or_country' => trim(implode(', ', array_filter([$row->organization, $row->country]))),
                default => '',
            };
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values === [] ? null : $values;
    }

    private function tinReasons(Customer $customer, string $item): ?bool
    {
        $reasons = $customer->taxResidencies->whereNull('ended_on')->pluck('missing_tin_reason')->filter()->unique();
        if ($reasons->isEmpty()) {
            return null;
        }
        $map = ['A' => 'Reason A', 'B' => 'Reason B', 'C' => 'Reason C'];

        return $reasons->contains(array_search($item, $map, true));
    }

    private function residencies(Customer $customer, string $item): ?string
    {
        $rows = $customer->taxResidencies->whereNull('ended_on')->values();
        if ($rows->isEmpty()) {
            return null;
        }
        $lines = $rows->map(fn ($row): string => match ($item) {
            'country_of_tax_residence' => (string) $row->jurisdiction,
            'tin' => (string) ($row->foreign_tin ?? ''),
            'missing_tin_reason' => (string) ($row->missing_tin_reason ?? ''),
            'missing_tin_reason_b_explanation' => (string) ($row->missing_tin_explanation ?? ''),
            default => '',
        })->filter(fn (string $line): bool => $line !== '')->values();
        if ($lines->isEmpty()) {
            return null;
        }
        if ($lines->count() > 2) {
            $this->warn($item === 'tin' ? 'blocking' : 'attention', 'Foreign tax residency', $lines->count().' foreign tax rows are recorded and this box fits 2. Extra rows were not printed.', null);
            $lines = $lines->take(2);
        }

        return $lines->implode("\n");
    }

    /** @param  array<string, mixed>  $choices */
    private function accountType(array $choices, string $item): ?bool
    {
        $value = $choices['account_type'] ?? null;
        if ($value === null) {
            $this->warn('attention', 'Account type', 'Account type was not chosen on this application and was left blank.', null);

            return null;
        }

        return ($value === 'trading' && $item === 'Trading Account') || ($value === 'margin' && $item === 'Margin Account');
    }

    /** @param  array<string, mixed>  $choices */
    private function verification(array $choices, string $item): ?bool
    {
        $value = $choices['verification_mode'] ?? null;
        if ($value === null) {
            return null;
        }

        return ($value === 'non_face' && $item === 'Non Face-To-Face') || ($value === 'face' && $item === 'Face to Face');
    }

    /** @param  array<string, mixed>  $choices */
    private function dealings(array $choices, string $item): bool
    {
        $value = $choices['dealings_capacity'] ?? 'principal';

        return ($value === 'principal' && $item === 'For me as principal')
            || ($value === 'on_behalf' && str_starts_with($item, 'For and on behalf'));
    }

    /** @param  array<string, mixed>  $choices */
    private function margin(array $choices): ?string
    {
        if (($choices['account_type'] ?? null) !== 'margin') {
            return null;
        }

        return $this->text($choices['margin_credit_limit'] ?? null, 'Margin limit', 'attention');
    }

    private function yesNo(mixed $value, string $item, string $label, bool $required = false, ?string $default = null): ?string
    {
        if ($value === 'not_declared' || $value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }
            $this->warn($required ? 'attention' : 'optional', $label, $label.' was not answered and both boxes were left blank.', null);

            return null;
        }
        $normalized = is_bool($value) ? ($value ? 'yes' : 'no') : strtolower((string) $value);
        if (! in_array($normalized, ['yes', 'no'], true)) {
            $this->warn('attention', $label, $label.' is not an explicit yes or no and both boxes were left blank.', null);

            return null;
        }

        return $normalized;
    }

    private function boolYesNo(mixed $value, string $item, string $label): ?string
    {
        if ($value === null) {
            return 'no';
        }

        return $value === true ? 'yes' : ($value === false ? 'no' : null);
    }

    private function classification(mixed $fatca, string $item): ?bool
    {
        $value = $fatca?->selected_classification;
        if (blank($value)) {
            $value = $fatca?->us_person_for_tax === true || $fatca?->us_green_card_holder === true || $fatca?->us_resident === true
                ? 'us_person'
                : 'non_us_person';
        }
        $map = ['us_person' => 'US Person', 'non_us_person' => 'Non US Person'];
        if (! isset($map[$value])) {
            $this->warn('attention', 'FATCA classification', 'FATCA classification has a value with no box on this form and was left blank.', null);

            return null;
        }

        return $map[$value] === $item;
    }

    private function unmapped(string $key): null
    {
        $label = 'Unanswered form item';
        $this->warn('attention', $label, $label.' was left blank.', null);

        return null;
    }

    private function titled(string $context, string $item): string
    {
        $names = [
            'city' => 'city', 'state' => 'state', 'postcode' => 'postcode', 'country' => 'country',
            'legal_name' => 'name', 'nric_or_passport' => 'NRIC / passport',
            'employer_name_and_address' => 'employer name and address', 'employer_city' => 'employer city',
            'employer_state' => 'employer state', 'employer_postcode' => 'employer postcode', 'employer_country' => 'employer country',
            'occupation' => 'occupation', 'home_phone' => 'home telephone', 'mobile_phone' => 'mobile phone', 'email' => 'email',
        ];

        return $context.' '.($names[$item] ?? str_replace('_', ' ', $item));
    }

    private function applicantOwned(CustomerBankAccount $account): bool
    {
        // shortcut: a blank ownership is treated as sole, upgrade when imports record joint explicitly
        return in_array(strtolower(trim((string) $account->ownership)), ['', 'sole'], true);
    }

    private function malaysia(?string $country): bool
    {
        return in_array(strtolower(trim((string) $country)), ['malaysia', 'my'], true);
    }

    private function warn(string $level, string $label, string $message, ?string $fieldId): void
    {
        $fingerprint = $level.'|'.$label.'|'.$message;
        if (isset($this->seen[$fingerprint])) {
            return;
        }
        $this->seen[$fingerprint] = true;
        $this->warnings[] = ['level' => $level, 'label' => $label, 'message' => $message, 'field_id' => $fieldId];
    }
}
