<?php

namespace App\Services;

use App\Enums\IdentificationType;
use App\Enums\OccupancyStatus;
use App\Models\CustomerProfileSchemaSnapshot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerProfileSchema
{
    public const VERSION = '1.0';

    public const PERSONAL_INCOME = ['up_to_30000' => 'Up to RM30,000', '30001_60000' => 'RM30,001–RM60,000', '60001_120000' => 'RM60,001–RM120,000', '120001_180000' => 'RM120,001–RM180,000', '180001_300000' => 'RM180,001–RM300,000', 'over_300000' => 'Over RM300,000'];

    public const JOINT_INCOME = ['up_to_50000' => 'Up to RM50,000', '50001_100000' => 'RM50,001–RM100,000', '100001_200000' => 'RM100,001–RM200,000', '200001_300000' => 'RM200,001–RM300,000', '300001_400000' => 'RM300,001–RM400,000', 'over_400000' => 'Over RM400,000'];

    public const NET_ASSETS = ['up_to_50000' => 'Up to RM50,000', '50001_200000' => 'RM50,001–RM200,000', '200001_500000' => 'RM200,001–RM500,000', '500001_1000000' => 'RM500,001–RM1,000,000', '1000001_3000000' => 'RM1,000,001–RM3,000,000', 'over_3000000' => 'Over RM3,000,000'];

    public const PORTFOLIO = ['up_to_50000' => 'Up to RM50,000', '50001_100000' => 'RM50,001–RM100,000', '100001_300000' => 'RM100,001–RM300,000', '300001_500000' => 'RM300,001–RM500,000', 'source_fifth_range' => 'RM500,001–RM1,000,00 (as printed)', 'over_1000000' => 'Over RM1,000,000'];

    public const WEALTH_SOURCES = ['savings' => 'Savings', 'salary' => 'Salary', 'business_investment_income' => 'Business/Investment Income', 'rental_income' => 'Rental Income', 'inherited_wealth' => 'Inherited Wealth', 'shareholdings' => 'Shareholdings', 'asset' => 'Asset', 'directorship' => 'Directorship', 'credit_facility' => 'Credit Facility', 'retained_earnings' => 'Retained Earnings', 'other' => 'Other'];

    public const OBJECTIVES = ['short_term' => 'Short Term (< 1 year)', 'long_term' => 'Long Term (> 3 years)', 'dividend_income' => 'Dividend Income', 'capital_growth' => 'Capital Growth'];

    public const PRODUCTS = ['local_equities' => 'Local Equities Trading', 'foreign_equities' => 'Foreign Equities Trading', 'short_term_share_margin' => 'Retail Equity — Short-Term Share Margin Financing', 'share_margin' => 'Retail Equity — Share Margin Financing', 'other' => 'Other'];

    public static function personalAnnualBracketFromMonthly(mixed $monthlyIncome): ?string
    {
        if ($monthlyIncome === '' || $monthlyIncome === null) {
            return null;
        }

        if (! is_scalar($monthlyIncome) || ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', (string) $monthlyIncome)) {
            return null;
        }

        $annualIncome = (float) $monthlyIncome * 12;

        return match (true) {
            $annualIncome <= 30000 => 'up_to_30000',
            $annualIncome <= 60000 => '30001_60000',
            $annualIncome <= 120000 => '60001_120000',
            $annualIncome <= 180000 => '120001_180000',
            $annualIncome <= 300000 => '180001_300000',
            default => 'over_300000',
        };
    }

    public static function options(string $key): array
    {
        return match ($key) {
            'identification_type' => array_column(IdentificationType::cases(), 'value'),
            'occupancy_status' => array_column(OccupancyStatus::cases(), 'value'),
            'relationship' => ['spouse', 'father', 'mother', 'son', 'daughter', 'brother', 'sister', 'other'],
            'employment_status' => ['employed', 'self_employed', 'retired', 'other'],
            'arrangement' => ['employed', 'self_employed', 'retired', 'other'],
            'bank_account_type' => ['savings', 'current', 'other'],
            'bank_ownership' => ['sole', 'joint', 'other'],
            'missing_tin_reason' => ['A', 'B', 'C'],
            'yes_no' => ['yes', 'no'],
            'fatca_classification' => ['us_person', 'non_us_person'],
            'personal_income' => array_keys(self::PERSONAL_INCOME),
            'joint_income' => array_keys(self::JOINT_INCOME),
            'net_assets' => array_keys(self::NET_ASSETS),
            'portfolio' => array_keys(self::PORTFOLIO),
            'portfolio_ownership' => ['personal', 'joint_with_spouse'],
            'wealth_sources' => array_keys(self::WEALTH_SOURCES),
            'objectives' => array_keys(self::OBJECTIVES),
            'risk_tolerance' => ['conservative', 'moderate', 'aggressive'],
            'trading_knowledge' => ['beginner', 'intermediate', 'advanced'],
            'products' => array_keys(self::PRODUCTS),
            'brokerage_answer', 'compliance_answer' => ['yes', 'no', 'not_declared'],
            'brokerage_account_type' => ['trading', 'margin'],
            'position_meaning' => ['public_position', 'occupation'],
            'position_status' => ['current', 'former', 'seeking', 'being_considered'],
            default => throw new InvalidArgumentException("Unknown profile option set: {$key}"),
        };
    }

    /** Each row is path|type or option set|description|validation|conditional edit rule. */
    private const FIELDS = [
        'personal' => <<<'FIELDS'
customer.salutation|string|Applicant's title or salutation|max:50
customer.full_legal_name|string|Applicant's full legal name|max:255
primary_identification.type|identification_type|Applicant's primary identification document type|enum
primary_identification.number|string|Applicant's primary identification number|max:100|Requires identification type; NRIC has 12 digits after normalization
primary_identification.old_nric|string|Applicant's old NRIC number|max:50
primary_identification.issuing_jurisdiction|string|Two-letter issuing country of applicant's passport or other identification|regex:two-letter-country|Required for passport or other identification
customer.date_of_birth|date|Applicant's date of birth|date
customer.gender|string|Applicant's stated gender|max:50
customer.nationality|string|Applicant's nationality|max:100
customer.race|string|Applicant's stated race|max:100
customer.marital_status|string|Applicant's marital status|max:50
customer.country_of_residence|string|Applicant's country of residence|max:100
contacts[mobile].value|string|Applicant's primary mobile telephone number|phone:6-30
contacts[home].value|string|Applicant's home telephone number|phone:6-30
contacts[email].value|string|Applicant's primary email address|email,max:255
contacts[social].value|string|Applicant's general social contact handle|max:255
contacts[social:facebook].value|string|Applicant's Facebook handle|max:255
contacts[social:instagram].value|string|Applicant's Instagram handle|max:255
contacts[social:twitter].value|string|Applicant's Twitter handle|max:255
addresses[residential].line_1|string|First line of applicant's residential address|max:255
addresses[residential].line_2|string|Second line of applicant's residential address|max:255
addresses[residential].postcode|string|Postcode of applicant's residential address|max:20
addresses[residential].city|string|City of applicant's residential address|max:100
addresses[residential].state|string|State of applicant's residential address|max:100
addresses[residential].country|string|Country of applicant's residential address|max:100
addresses[residential].occupancy_status|occupancy_status|Applicant's residential occupancy status|enum
addresses[residential].occupancy_other|string|Applicant's other residential occupancy description|max:255|Only when occupancy status is other
addresses[mailing].same_as_residential|boolean|Whether applicant's mailing address equals residential address|boolean|Explicit selection only; silence does not change address
addresses[mailing].line_1|string|First line of applicant's mailing address|max:255|Only when mailing address differs from residential
addresses[mailing].line_2|string|Second line of applicant's mailing address|max:255|Only when mailing address differs from residential
addresses[mailing].postcode|string|Postcode of applicant's mailing address|max:20|Only when mailing address differs from residential
addresses[mailing].city|string|City of applicant's mailing address|max:100|Only when mailing address differs from residential
addresses[mailing].state|string|State of applicant's mailing address|max:100|Only when mailing address differs from residential
addresses[mailing].country|string|Country of applicant's mailing address|max:100|Only when mailing address differs from residential
FIELDS,
        'relationships' => <<<'FIELDS'
relationships[].relationship|relationship|Applicant's relationship to this related person|enum
relationships[].other_relationship|string|Description of other relationship to applicant|max:100|Only when relationship is other
relationships[].related_person.full_legal_name|string|Related person's full legal name, not applicant's|max:255
relationships[].related_person.identification_type|identification_type|Related person's identification document type|enum
relationships[].related_person.identification_number|string|Related person's identification number, not applicant's|max:100|Requires identification type
relationships[].related_person.date_of_birth|date|Related person's date of birth|date,before-or-equal:today
relationships[].related_person.gender|string|Related person's stated gender|max:50
relationships[].related_person.nationality|string|Related person's nationality|max:100
relationships[].related_person.mobile|string|Related person's mobile telephone number|phone:6-30
relationships[].related_person.home_telephone|string|Related person's home telephone number|phone:6-30
relationships[].related_person.email|string|Related person's email address|email,max:255
relationships[].related_person.occupation|string|Related person's occupation, not applicant's|max:255
relationships[].related_person.employer_name|string|Related person's employer name|max:255
relationships[].related_person.employer_address|string|Related person's unstructured employer address|max:2000
relationships[].related_person.employer_address_line_1|string|First line of related person's employer address|max:255
relationships[].related_person.employer_address_line_2|string|Second line of related person's employer address|max:255
relationships[].related_person.employer_postcode|string|Postcode of related person's employer address|max:20
relationships[].related_person.employer_city|string|City of related person's employer address|max:255
relationships[].related_person.employer_state|string|State of related person's employer address|max:255
relationships[].related_person.employer_country|string|Country of related person's employer address|max:255
relationships[].is_next_of_kin|boolean|Whether this related person is applicant's next of kin|boolean|Explicit designation only
relationships[].is_primary_next_of_kin|boolean|Whether this related person is applicant's primary next of kin|boolean|Requires next-of-kin designation and usable phone; explicit selection only
FIELDS,
        'employment' => <<<'FIELDS'
customer.employment_status|employment_status|Applicant's overall employment status|enum
customer.employment_status_other|string|Applicant's other employment status description|max:255|Only when employment status is other
customer.retirement_date|date|Applicant's retirement date|date,before-or-equal:today|Only when employment status is retired
employments[].arrangement|arrangement|Applicant's employment or business arrangement|enum
employments[].occupation|string|Applicant's occupation in this employment|max:255
employments[].employer_name|string|Name of applicant's employer or business|max:255
employments[].registration_number|string|Employer or business registration number|max:100,registration-format
employments[].industry|string|Industry of applicant's employer|max:255
employments[].sub_industry|string|Sub-industry of applicant's employer|max:255
employments[].start_date|date|Start date of applicant's employment|date
employments[].end_date|date|End date of applicant's employment|date|Only for non-current employment; silence never ends employment
employments[].is_current|boolean|Whether applicant's employment is current|boolean|Explicit designation only
employments[].is_primary|boolean|Whether this is applicant's primary current employment|boolean|Only for current employment; explicit designation only
employments[].general_telephone|string|Employer's general telephone number|phone:6-30
employments[].direct_telephone|string|Applicant's direct telephone at employer|phone:6-30
employments[].fax|string|Employer's fax number|phone:6-30
employments[].address_line_1|string|First line of employer's address|max:255
employments[].address_line_2|string|Second line of employer's address|max:255
employments[].postcode|string|Postcode of employer's address|max:20
employments[].city|string|City of employer's address|max:255
employments[].state|string|State of employer's address|max:255
employments[].country|string|Country of employer's address|max:255
FIELDS,
        'bank_accounts' => <<<'FIELDS'
bank_accounts[].bank_name|string|Name of bank holding applicant's account|max:255
bank_accounts[].bank_country|string|Country of applicant's bank|max:255
bank_accounts[].branch_address|string|Branch address of applicant's bank|max:255
bank_accounts[].account_type|bank_account_type|Type of applicant's bank account|enum
bank_accounts[].account_number|string|Applicant's bank account number|max:100,account-format
bank_accounts[].account_holder_name|string|Printed name of bank account holder|max:255
bank_accounts[].ownership|bank_ownership|Whether applicant's bank account is sole or joint|enum
bank_accounts[].is_primary|boolean|Whether this is applicant's primary active bank account|boolean|Only for active account; explicit designation only
bank_accounts[].is_active|boolean|Whether applicant's bank account is active|boolean|Explicit status only; silence never deactivates an account
FIELDS,
        'tax' => <<<'FIELDS'
tax_identification.malaysian_tin|string|Applicant's Malaysian tax identification number|max:100
tax_residencies[].jurisdiction|string|Foreign tax jurisdiction of applicant|max:255
tax_residencies[].foreign_tin|string|Applicant's tax identification number in this foreign jurisdiction|max:100|Provide this or a missing TIN reason
tax_residencies[].missing_tin_reason|missing_tin_reason|Reason applicant lacks a foreign TIN in this jurisdiction|enum|Only when foreign TIN is absent
tax_residencies[].missing_tin_explanation|string|Applicant's explanation for missing foreign TIN|max:2000|Required for missing TIN reason B
tax_residencies[].effective_date|date|Effective date of applicant's foreign tax residency|date,before-or-equal:today
tax_residencies[].ended_on|date|End date of applicant's foreign tax residency|date,before-or-equal:today|Explicit termination only; silence never ends residency
fatca.us_person_for_tax|yes_no|Applicant's stated US person for tax answer|enum
fatca.us_green_card_holder|yes_no|Applicant's stated US green card answer|enum
fatca.us_resident|yes_no|Applicant's stated US residency answer|enum
fatca.selected_classification|fatca_classification|Applicant's explicitly selected FATCA classification, not a printed option|enum|Only an explicit selection mark is provided
fatca.declared_on|date|Date of applicant's FATCA declaration|date,before-or-equal:today
FIELDS,
        'financial' => <<<'FIELDS'
financial.monthly_income_rm|decimal|Applicant's monthly income in Malaysian ringgit, not an annual bracket|decimal:13,2
financial.personal_annual_income_bracket|personal_income|Applicant's selected personal annual income bracket|enum
financial.joint_annual_income_bracket|joint_income|Applicant's selected joint annual income bracket|enum
financial.net_asset_bracket|net_assets|Applicant's selected net asset bracket|enum
financial.portfolio_bracket|portfolio|Applicant's selected portfolio value bracket|enum
financial.portfolio_ownership|portfolio_ownership|Applicant's stated personal or spousal joint portfolio ownership|enum
financial.wealth_sources|wealth_sources|Applicant's selected sources of wealth or funds|enum-array
financial.other_wealth_source|string|Applicant's other source of wealth or funds|max:255|Required when wealth sources include other
financial.declared_on|date|Date of applicant's financial declaration|date,before-or-equal:today
FIELDS,
        'investment' => <<<'FIELDS'
investment.objectives|objectives|Applicant's selected investment objectives|enum-array
investment.risk_tolerance|risk_tolerance|Applicant's investment risk tolerance, not compliance risk|enum
investment.trading_knowledge|trading_knowledge|Applicant's stated trading knowledge|enum
investment.products|products|Applicant's selected investment products|enum-array
investment.other_product_details|string|Applicant's other investment product description|max:255|Required when products include other
brokerage_declaration.answer|brokerage_answer|Applicant's answer about accounts with other brokerages|enum
brokerage_declaration.declared_on|date|Date of applicant's other brokerage declaration|date,before-or-equal:today
brokerage_accounts[].broker_name|string|Name of applicant's other broker|max:255
brokerage_accounts[].branch_address|string|Branch address of applicant's other broker|max:255
brokerage_accounts[].account_type|brokerage_account_type|Type of applicant's other brokerage account|enum
brokerage_accounts[].is_active|boolean|Whether applicant's other brokerage account is active|boolean|Explicit status only; silence never deactivates an account
FIELDS,
        'compliance' => <<<'FIELDS'
compliance[pep].answer|compliance_answer|Applicant's explicit politically exposed person declaration answer|enum
compliance[pep].remarks|string|Applicant's remarks on politically exposed person declaration|max:2000
compliance[pep].declared_on|date|Date of applicant's politically exposed person declaration|date,before-or-equal:today
compliance[bankruptcy].answer|compliance_answer|Applicant's explicit bankruptcy declaration answer|enum
compliance[bankruptcy].remarks|string|Applicant's remarks on bankruptcy declaration|max:2000
compliance[bankruptcy].declared_on|date|Date of applicant's bankruptcy declaration|date,before-or-equal:today
compliance[petition].answer|compliance_answer|Applicant's explicit bankruptcy petition awareness answer|enum
compliance[petition].remarks|string|Applicant's remarks on bankruptcy petition awareness|max:2000
compliance[petition].declared_on|date|Date of applicant's bankruptcy petition awareness declaration|date,before-or-equal:today
pep_associations[].relationship|string|Relationship of politically exposed person to applicant|max:100
pep_associations[].occupation|string|Occupation of politically exposed person|max:255
pep_associations[].position|string|Public position of politically exposed person|max:255
pep_associations[].position_meaning|position_meaning|Whether position text names a public position or occupation|enum
pep_associations[].organization|string|Organization of politically exposed person|max:255
pep_associations[].country|string|Country of politically exposed position|max:100
pep_associations[].position_status|position_status|Current or former status of politically exposed position|enum
pep_associations[].remarks|string|Remarks about politically exposed person association|max:2000
pep_associations[].started_on|date|Start date of politically exposed person association|date,before-or-equal:today
pep_associations[].ended_on|date|End date of politically exposed person association|date,before-or-equal:today|Explicit termination only; silence never ends association
FIELDS,
    ];

    public static function descriptors(): array
    {
        $descriptors = [];
        foreach (self::FIELDS as $module => $lines) {
            foreach (explode("\n", trim($lines)) as $line) {
                [$path, $kind, $description, $validation, $condition] = array_pad(explode('|', $line), 5, null);
                $parent = str_contains($path, '.') ? substr($path, 0, strrpos($path, '.')) : null;
                $options = in_array($kind, ['string', 'date', 'boolean', 'decimal'], true) ? null : self::options($kind);
                $descriptors[] = [
                    'path' => $path,
                    'module' => $module,
                    'label' => ucwords(str_replace('_', ' ', substr($path, strrpos($path, '.') + 1))),
                    'description' => $description,
                    'type' => $options ? (str_ends_with($validation, '-array') ? 'array' : 'enum') : $kind,
                    'allowed_values' => $options,
                    'entity_role' => str_contains($path, '.related_person.') ? 'related_person' : (str_starts_with($path, 'pep_associations[].') ? 'pep_associate' : 'applicant'),
                    'cardinality' => str_contains($path, '[]') ? 'repeatable' : 'one',
                    'parent_path' => $parent,
                    'validation' => $validation,
                    'conditional_edit' => $condition,
                    'unprovided' => 'Leave existing value unchanged; never infer a selection or termination from silence.',
                ];
            }
        }
        usort($descriptors, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        $paths = array_column($descriptors, 'path');
        if (count($paths) !== count(array_unique($paths)) || count($paths) !== 138 || collect($descriptors)->contains(fn ($field) => blank($field['description']) || blank($field['validation']))) {
            throw new InvalidArgumentException('Customer Profile schema is incomplete or contains duplicate paths.');
        }

        return $descriptors;
    }

    /** @return list<string> */
    public static function readingOrder(): array
    {
        $paths = [];
        foreach (self::FIELDS as $lines) {
            foreach (explode("\n", trim($lines)) as $line) {
                $paths[] = explode('|', $line, 2)[0];
            }
        }

        return $paths;
    }

    /** @return array{0: string, 1: ?string} */
    public static function canonicalFieldId(string $path): array
    {
        $row = null;
        if (preg_match('/\[(\d+)\]/', $path, $match) === 1) {
            $row = $match[1];
            $path = preg_replace('/\[\d+\]/', '[]', $path) ?? $path;
        }

        return [$path, $row];
    }

    public static function canonicalJson(): string
    {
        return json_encode(['version' => self::VERSION, 'fields' => self::descriptors()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(): string
    {
        return hash('sha256', self::canonicalJson());
    }

    public static function snapshot(): CustomerProfileSchemaSnapshot
    {
        $json = self::canonicalJson();
        $hash = hash('sha256', $json);

        return DB::transaction(function () use ($json, $hash): CustomerProfileSchemaSnapshot {
            return CustomerProfileSchemaSnapshot::query()->firstOrCreate(['hash' => $hash], ['version' => self::VERSION, 'canonical_json' => $json]);
        });
    }
}
