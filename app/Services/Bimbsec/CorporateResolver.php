<?php

namespace App\Services\Bimbsec;

use App\Enums\AddressType;
use App\Enums\BssbRelatedPartyKind;
use App\Enums\ContactType;
use App\Enums\RepresentativeRole;
use App\Models\BimbsecApplication;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerRelationship;
use App\Models\RelatedPerson;
use App\Services\CustomerProfileSchema;
use Illuminate\Support\Carbon;

class CorporateResolver
{
    /** @var list<array{level: string, label: string, message: string, field_id: ?string}> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private CorporateCatalog $catalog) {}

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
            'kind' => 'corporate',
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

    /** @param  array<string, mixed>  $field */
    private function skip(array $field): bool
    {
        $scope = (string) ($field['source']['scope'] ?? '');
        $policy = (string) ($field['fill_policy'] ?? '');

        return ! ($field['should_autofill'] ?? false)
            || in_array($policy, ['manual', 'preserve'], true)
            || in_array($scope, ['authorized_office', 'manual_review'], true)
            || str_starts_with((string) ($field['source']['reference_key'] ?? ''), 'application.execution.')
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
            'primaryIdentification', 'contacts', 'addresses', 'corporateDetails',
            'relationships.relatedPerson', 'bankAccounts', 'taxIdentification', 'taxResidencies',
            'fatcaDeclarations', 'latestInvestmentProfile', 'brokerageDeclarations', 'brokerageAccounts',
            'complianceDeclarations', 'pepAssociations.relatedPerson',
            'pepAssociations.customerRelationship.relatedPerson',
            'pepAssociations.customerRelationship.relatedCustomer.primaryIdentification',
            'bssbRelatedParties',
        ]);

        $reps = $customer->relationships->whereNotNull('representative_role');

        return [
            'customer' => $customer,
            'choices' => $choices,
            'details' => $customer->corporateDetails,
            'registered' => $customer->addresses->firstWhere('type', AddressType::Registered),
            'mailing' => $customer->addresses->firstWhere('type', AddressType::Mailing),
            'primary' => $reps->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::PrimaryContact)?->relatedPerson,
            'signatory1' => $reps->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::AuthorizedSignatory && (int) $row->signatory_order === 1)?->relatedPerson,
            'signatory2' => $reps->first(fn (CustomerRelationship $row): bool => $row->representative_role === RepresentativeRole::AuthorizedSignatory && (int) $row->signatory_order === 2)?->relatedPerson,
            'bank' => $this->bank($customer, $choices),
            'fatca' => $customer->fatcaDeclarations->firstWhere('current_customer_key', $customer->id),
            'investment' => $customer->latestInvestmentProfile,
            'brokerage' => $customer->brokerageDeclarations->firstWhere('current_customer_key', $customer->id),
            'pep' => $customer->complianceDeclarations->firstWhere('current_key', $customer->id.':pep'),
            'bssb_staff' => $customer->complianceDeclarations->firstWhere('current_key', $customer->id.':bssb_staff'),
            'bssb_client' => $customer->complianceDeclarations->firstWhere('current_key', $customer->id.':bssb_client'),
        ];
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
        $eligible = $customer->bankAccounts->filter(fn (CustomerBankAccount $account): bool => $account->is_active);
        $primary = $eligible->where('is_primary', true);
        if ($primary->count() === 1) {
            return $primary->first();
        }
        if ($eligible->count() === 1) {
            return $eligible->first();
        }
        if ($customer->bankAccounts->isNotEmpty()) {
            $this->warn('attention', 'Bank account', 'No single primary bank account is marked. Choose one on this application. The bank section was left blank.', null);
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
        $details = $context['details'];

        return match ($key) {
            'corporate.registered_name' => $this->text($customer->full_legal_name, 'Registered name', 'attention'),
            'corporate.registration_number' => $this->text($customer->primary_identification_number, 'Business registration no.', 'attention'),
            'corporate.registration_date' => $this->dateText($details?->registered_at, 'Date of registration'),
            'corporate.registration_country' => $this->text($details?->registration_country, 'Country of registration'),
            'corporate.entity_type' => $this->text($details?->entity_type, 'Entity type'),
            'corporate.entity_sub_type' => $this->text($details?->entity_sub_type, 'Entity sub type'),
            'corporate.business_activity' => $this->text(
                trim(collect([$details?->industry, $details?->business_activity])->filter()->implode(' — ')),
                'Industry / activity'
            ),
            'corporate.authorized_capital_rm' => $this->money($details?->authorized_capital_rm, 'Authorized capital'),
            'corporate.paid_up_capital_rm' => $this->money($details?->paid_up_capital_rm, 'Paid-up capital'),
            'corporate.phone' => $this->text(
                $customer->contacts->firstWhere('type', ContactType::Mobile)?->value ?? $customer->primary_mobile,
                'Phone no.'
            ),
            'corporate.fax' => $this->text($customer->contacts->firstWhere('type', ContactType::Fax)?->value, 'Fax no.'),
            'corporate.email' => $this->text(
                $customer->contacts->firstWhere('type', ContactType::Email)?->value ?? $customer->primary_email,
                'Email'
            ),
            'corporate.registered_address' => $this->address($context['registered'], 'Registered address'),
            'corporate.mailing_address' => $this->mailing($context['mailing']),
            'corporate.primary_contact.name' => $this->rep($context['primary'], 'name', 'Primary contact name'),
            'corporate.primary_contact.identity_number' => $this->rep($context['primary'], 'identification', 'Primary contact ID'),
            'corporate.primary_contact.mobile' => $this->rep($context['primary'], 'mobile', 'Primary contact mobile'),
            'corporate.primary_contact.office_phone' => $this->rep($context['primary'], 'office_telephone', 'Primary contact office telephone'),
            'corporate.primary_contact.extension' => $this->rep($context['primary'], 'office_extension', 'Primary contact extension'),
            'corporate.primary_contact.designation' => $this->rep($context['primary'], 'occupation', 'Primary contact designation'),
            'corporate.primary_contact.email' => $this->rep($context['primary'], 'email', 'Primary contact email'),
            'corporate.authorized_signatory_1.name' => $this->rep($context['signatory1'], 'name', 'Signatory 1 name'),
            'corporate.authorized_signatory_1.identity_number' => $this->rep($context['signatory1'], 'identification', 'Signatory 1 ID'),
            'corporate.authorized_signatory_1.mobile' => $this->rep($context['signatory1'], 'mobile', 'Signatory 1 mobile'),
            'corporate.authorized_signatory_1.office_phone' => $this->rep($context['signatory1'], 'office_telephone', 'Signatory 1 office telephone'),
            'corporate.authorized_signatory_1.extension' => $this->rep($context['signatory1'], 'office_extension', 'Signatory 1 extension'),
            'corporate.authorized_signatory_1.designation' => $this->rep($context['signatory1'], 'occupation', 'Signatory 1 designation'),
            'corporate.authorized_signatory_1.email' => $this->rep($context['signatory1'], 'email', 'Signatory 1 email'),
            'corporate.authorized_signatory_2.name' => $this->rep($context['signatory2'], 'name', 'Signatory 2 name'),
            'corporate.authorized_signatory_2.identity_number' => $this->rep($context['signatory2'], 'identification', 'Signatory 2 ID'),
            'corporate.authorized_signatory_2.mobile' => $this->rep($context['signatory2'], 'mobile', 'Signatory 2 mobile'),
            'corporate.authorized_signatory_2.office_phone' => $this->rep($context['signatory2'], 'office_telephone', 'Signatory 2 office telephone'),
            'corporate.authorized_signatory_2.extension' => $this->rep($context['signatory2'], 'office_extension', 'Signatory 2 extension'),
            'corporate.authorized_signatory_2.designation' => $this->rep($context['signatory2'], 'occupation', 'Signatory 2 designation'),
            'corporate.authorized_signatory_2.email' => $this->rep($context['signatory2'], 'email', 'Signatory 2 email'),
            'corporate.estimated_net_asset_or_aum_band' => $this->one($details?->aum_bracket, [
                'up_to_1000000' => '≤RM1,000,000',
                '1000001_10000000' => 'RM1,000,001 – RM10,000,000',
                '10000001_50000000' => 'RM10,000,001 – RM50,000,000',
                '50000001_100000000' => 'RM50,000,001- RM100,000,000',
                'over_100000000' => '≥RM100,000,001',
            ], $item, 'AUM bracket'),
            'compliance.related_to_bssb_staff' => $this->yesNo($context['bssb_staff']?->answer, $item, 'Related to BSSB staff', false, 'no'),
            'compliance.related_to_bssb_clients' => $this->yesNo($context['bssb_client']?->answer, $item, 'Related to BSSB client', false, 'no'),
            'compliance.bssb_staff_relatives' => $this->bssbParty($customer, BssbRelatedPartyKind::Staff, $item),
            'compliance.bssb_client_relatives' => $this->bssbParty($customer, BssbRelatedPartyKind::Client, $item),
            'banking.references' => $this->bankField($context['bank'], $item),
            'investment.objectives' => $this->many($context['investment']?->objectives, [
                'short_term' => 'Short Term <1 year',
                'long_term' => 'Long Term >3 years',
                'dividend_income' => 'Dividend Income',
                'capital_growth' => 'Capital Growth',
            ], $item, null, 'Investment objectives'),
            'investment.risk_tolerance' => $this->one($context['investment']?->risk_tolerance, [
                'conservative' => 'Conservative', 'moderate' => 'Moderate', 'aggressive' => 'Aggressive',
            ], $item, 'Risk tolerance'),
            'investment.products' => $this->many($context['investment']?->products, [
                'local_equities' => 'Equities trading (local)',
                'foreign_equities' => 'Equities trading (foreign)',
                'short_term_share_margin' => 'Retail Equity – Short term share margin financing',
                'share_margin' => 'Retail Equity – Share margin financing',
                'other' => 'Others',
            ], $item, $context['investment']?->other_product_details, 'Investment products'),
            'investment.products_other_details' => in_array('other', $context['investment']?->products ?? [], true)
                ? $this->text($context['investment']?->other_product_details, 'Other product details')
                : null,
            'investment.has_other_brokers' => $this->yesNo($context['brokerage']?->answer, $item, 'Other brokerage accounts', false, 'no'),
            'investment.other_broker_accounts' => $this->broker($customer, $item),
            'compliance.pep_exposure' => $this->yesNo($context['pep']?->answer, $item, 'PEP declaration'),
            'compliance.pep_people' => $this->pep($customer, $item),
            'tax.malaysian_tin' => $this->text($customer->taxIdentification?->malaysian_tin, 'Malaysian TIN'),
            'tax.malaysian_sst' => $this->text($details?->sst, 'SST'),
            'tax.us_person_for_irs' => $this->boolYesNo($context['fatca']?->us_person_for_tax, $item, 'US person for tax'),
            'tax.us_green_card_holder' => $this->boolYesNo($context['fatca']?->us_green_card_holder, $item, 'US green card'),
            'tax.us_resident' => $this->boolYesNo($context['fatca']?->us_resident, $item, 'US resident'),
            'tax.us_person_classification_marked' => $this->classification($context['fatca'], $item),
            'tax.no_tin_reason_boxes' => $this->tinReasons($customer, $item),
            'tax.foreign_residencies' => $this->residencies($customer, $item),
            'application.account_type' => $this->accountType($context['choices'], $item),
            'application.dealings_capacity' => $this->dealings($context['choices'], $item),
            'application.margin_credit_limit_applied' => $this->margin($context['choices']),
            'application.online_trading_requested' => $this->yesNo($context['choices']['online_trading'] ?? null, $item, 'Online trading', true),
            'application.estatement_delivery_option' => $this->one($context['choices']['estatement'] ?? null, [
                'email' => 'by e-mail', 'bisonline' => 'BISonline',
            ], $item, 'Statement delivery', true),
            'application.payment_instruction' => $this->one($context['choices']['payment'] ?? null, [
                'cheque' => 'To issue cheque payable to us',
                'epayment' => 'e-Payment – credit directly into our bank account as filled in Section B',
            ], $item, 'Payment instruction', true),
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

    private function dateText(mixed $value, string $label): ?string
    {
        if (blank($value)) {
            $this->warn('optional', $label, $label.' has no value and was left blank.', null);

            return null;
        }
        try {
            // 10 character cells on the corporate form fit dd/mm/yyyy.
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            $this->warn('attention', $label, $label.' is not a valid date and was left blank.', null);

            return null;
        }
    }

    private function money(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            $this->warn('optional', $label, $label.' has no value and was left blank.', null);

            return null;
        }

        return number_format((float) $value, 2, '.', ',');
    }

    private function address(mixed $address, string $label): ?string
    {
        if ($address === null || blank($address->line_1 ?? null)) {
            $this->warn('attention', $label, $label.' has no value and was left blank.', null);

            return null;
        }

        return trim(collect([
            $address->line_1,
            $address->line_2,
            collect([$address->postcode, $address->city])->filter()->implode(' '),
            $address->state,
            $address->country,
        ])->filter()->implode(', '));
    }

    private function mailing(mixed $mailing): ?string
    {
        if ($mailing === null || ($mailing->same_as_residential ?? true)) {
            return null;
        }

        return $this->address($mailing, 'Mailing address');
    }

    private function rep(?RelatedPerson $person, string $field, string $label): ?string
    {
        if ($person === null) {
            $this->warn('optional', $label, $label.' is not on the corporate profile and was left blank.', null);

            return null;
        }

        $value = match ($field) {
            'name' => $person->full_legal_name,
            'identification' => $person->identification_number,
            'mobile' => $person->mobile,
            'office_telephone' => $person->home_telephone,
            'office_extension' => $person->office_extension,
            'occupation' => $person->occupation,
            'email' => $person->email,
            default => null,
        };

        return $this->text($value, $label);
    }

    private function bankField(?CustomerBankAccount $bank, string $item): mixed
    {
        if ($bank === null) {
            return null;
        }
        $value = match ($item) {
            'Bank' => $bank->bank_name,
            'Branch/Address' => $bank->branch_address,
            'Account Type (Savings/Current/Others)' => match ($bank->account_type) {
                'SAVINGS', 'savings' => 'Savings',
                'CURRENT', 'current' => 'Current',
                'OTHER', 'other' => 'Other',
                default => $bank->account_type,
            },
            'Account No.' => $bank->account_number,
            default => null,
        };
        if ($value === null || $value === '') {
            return null;
        }

        return [(string) $value];
    }

    private function broker(Customer $customer, string $item): mixed
    {
        $rows = $customer->brokerageAccounts->filter(fn ($account): bool => $account->is_active !== false)->values();
        if ($rows->isEmpty()) {
            return null;
        }
        $values = $rows->take(2)->map(fn ($account, int $index): string => match ($item) {
            'No.' => (string) ($index + 1),
            'Name of Broker' => (string) $account->broker_name,
            'Branch/Address' => (string) ($account->branch_address ?? ''),
            'Account Type (Trading/Margin)' => match ($account->account_type) {
                'trading' => 'Trading', 'margin' => 'Margin', default => (string) $account->account_type,
            },
            default => '',
        })->filter(fn (string $value): bool => $value !== '')->values()->all();

        return $values === [] ? null : $values;
    }

    private function bssbParty(Customer $customer, BssbRelatedPartyKind $kind, string $item): mixed
    {
        $rows = $customer->bssbRelatedParties->where('kind', $kind)->values();
        if ($rows->isEmpty()) {
            return null;
        }
        $values = $rows->take(2)->map(fn ($party, int $index): string => match ($item) {
            'No.' => (string) ($index + 1),
            'Name of directors/employees/DR', 'Name of client' => (string) ($party->name ?? ''),
            'Relationship' => (string) ($party->relationship ?? ''),
            'NRIC/Registration No.', 'NRIC No.' => (string) ($party->identification_number ?? ''),
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
        $values = [];
        foreach ($rows->take(2) as $index => $row) {
            $person = $row->relatedPerson ?? $row->customerRelationship?->relatedPerson;
            $value = match ($item) {
                'No.' => (string) ($index + 1),
                'Name of the Person' => (string) ($person?->full_legal_name ?? ''),
                'Relationship' => (string) ($row->relationship ?? ''),
                'Occupation' => (string) ($row->occupation ?? $person?->occupation ?? ''),
                'NRIC' => (string) ($person?->identification_number ?? ''),
                'Employer/Country' => trim(implode(', ', array_filter([$row->organization, $row->country]))),
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

        return $lines->take(2)->implode("\n");
    }

    /** @param  array<string, string>  $map */
    private function one(mixed $value, array $map, string $item, string $label, bool $required = false): mixed
    {
        if (blank($value)) {
            $this->warn($required ? 'attention' : 'optional', $label, $label.' was not answered and was left blank.', null);

            return null;
        }
        $printed = $map[$value] ?? $map[strtolower((string) $value)] ?? null;
        if ($printed === null) {
            $this->warn('attention', $label, $label.' has a value with no box on this form and was left blank.', null);

            return null;
        }

        return $printed === $item;
    }

    /**
     * @param  array<int, string>|null  $values
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
            }
        }
        if ($printed === []) {
            $this->warn('attention', $label, $label.' has a value with no box on this form and was left blank.', null);

            return null;
        }

        return in_array($item, $printed, true);
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

        return ($value === 'principal' && $item === 'For us as principal')
            || ($value === 'on_behalf' && str_starts_with($item, 'For and on behalf of party(ies)'));
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
            return null;
        }

        return $map[$value] === $item;
    }

    private function unmapped(string $key): null
    {
        $this->warn('attention', 'Unanswered form item', 'Unanswered form item was left blank.', null);

        return null;
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
