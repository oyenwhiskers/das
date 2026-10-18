<?php

namespace Tests\Feature;

use App\Models\CustomerProfileSchemaSnapshot;
use App\Services\CustomerProfileSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_business_field_inventory(): void
    {
        $expected = [
            'customer' => 'salutation full_legal_name date_of_birth gender nationality country_of_residence race marital_status employment_status employment_status_other retirement_date',
            'primary_identification' => 'type number issuing_jurisdiction old_nric',
            'contacts[mobile]' => 'value', 'contacts[home]' => 'value', 'contacts[email]' => 'value', 'contacts[social]' => 'value',
            'contacts[social:facebook]' => 'value', 'contacts[social:instagram]' => 'value', 'contacts[social:twitter]' => 'value',
            'addresses[residential]' => 'line_1 line_2 city state postcode country occupancy_status occupancy_other',
            'addresses[mailing]' => 'line_1 line_2 city state postcode country same_as_residential',
            'relationships[]' => 'relationship other_relationship is_next_of_kin is_primary_next_of_kin',
            'relationships[].related_person' => 'full_legal_name identification_type identification_number date_of_birth gender nationality mobile home_telephone email occupation employer_name employer_address employer_address_line_1 employer_address_line_2 employer_city employer_state employer_postcode employer_country',
            'employments[]' => 'arrangement occupation employer_name registration_number industry sub_industry start_date end_date is_current is_primary general_telephone direct_telephone fax address_line_1 address_line_2 city state postcode country',
            'bank_accounts[]' => 'bank_name bank_country branch_address account_type account_number account_holder_name ownership is_primary is_active',
            'tax_identification' => 'malaysian_tin',
            'tax_residencies[]' => 'jurisdiction foreign_tin missing_tin_reason missing_tin_explanation effective_date ended_on',
            'fatca' => 'us_person_for_tax us_green_card_holder us_resident selected_classification declared_on',
            'financial' => 'monthly_income_rm personal_annual_income_bracket joint_annual_income_bracket net_asset_bracket portfolio_bracket portfolio_ownership wealth_sources other_wealth_source declared_on',
            'investment' => 'objectives risk_tolerance trading_knowledge products other_product_details',
            'brokerage_declaration' => 'answer declared_on',
            'brokerage_accounts[]' => 'broker_name branch_address account_type is_active',
            'compliance[pep]' => 'answer remarks declared_on', 'compliance[bankruptcy]' => 'answer remarks declared_on',
            'compliance[petition]' => 'answer remarks declared_on',
            'pep_associations[]' => 'relationship occupation position position_meaning organization country position_status remarks started_on ended_on',
        ];
        $paths = [];
        foreach ($expected as $parent => $fields) {
            foreach (explode(' ', $fields) as $field) {
                $paths[] = "{$parent}.{$field}";
            }
        }
        sort($paths);
        $descriptors = CustomerProfileSchema::descriptors();
        $this->assertSame($paths, array_column($descriptors, 'path'));
        $this->assertCount(138, $descriptors);
        $this->assertEqualsCanonicalizing([
            'personal' => 34, 'relationships' => 22, 'employment' => 22, 'bank_accounts' => 9,
            'tax' => 12, 'financial' => 9, 'investment' => 11, 'compliance' => 19,
        ], array_count_values(array_column($descriptors, 'module')));
        foreach ($descriptors as $field) {
            $this->assertNotEmpty($field['description']);
            $this->assertNotEmpty($field['validation']);
            $this->assertStringContainsString('unchanged', $field['unprovided']);
            if ($field['allowed_values'] !== null) {
                $this->assertNotEmpty($field['allowed_values']);
                $this->assertSame($field['allowed_values'], array_values(array_unique($field['allowed_values'])));
            }
        }
    }

    public function test_options_conditions_and_historical_snapshot(): void
    {
        $fields = collect(CustomerProfileSchema::descriptors())->keyBy('path');
        $this->assertSame(['nric', 'passport', 'other'], $fields['primary_identification.type']['allowed_values']);
        $this->assertSame(array_keys(CustomerProfileSchema::WEALTH_SOURCES), $fields['financial.wealth_sources']['allowed_values']);
        $this->assertSame(array_keys(CustomerProfileSchema::PRODUCTS), $fields['investment.products']['allowed_values']);
        $this->assertSame(['yes', 'no', 'not_declared'], $fields['compliance[petition].answer']['allowed_values']);
        $this->assertSame('related_person', $fields['relationships[].related_person.full_legal_name']['entity_role']);
        $this->assertSame('pep_associate', $fields['pep_associations[].position']['entity_role']);
        $this->assertStringContainsString('active', $fields['bank_accounts[].is_primary']['conditional_edit']);
        $this->assertStringContainsString('Explicit termination', $fields['tax_residencies[].ended_on']['conditional_edit']);

        $json = CustomerProfileSchema::canonicalJson();
        $hash = CustomerProfileSchema::hash();
        $this->assertSame(hash('sha256', $json), $hash);
        $this->assertSame($json, CustomerProfileSchema::canonicalJson());
        $this->assertNotSame($hash, hash('sha256', str_replace('Applicant', 'Customer', $json)));
        $snapshot = CustomerProfileSchema::snapshot();
        $this->assertSame($snapshot->id, CustomerProfileSchema::snapshot()->id);
        $this->assertSame(CustomerProfileSchema::VERSION, $snapshot->schema()['version']);
        CustomerProfileSchemaSnapshot::query()->create([
            'hash' => hash('sha256', 'old schema'), 'version' => '0.9', 'canonical_json' => '{"version":"0.9","fields":[]}',
        ]);
        $this->assertSame(2, CustomerProfileSchemaSnapshot::query()->count());
        $this->assertSame($json, $snapshot->fresh()->canonical_json);
    }
}
