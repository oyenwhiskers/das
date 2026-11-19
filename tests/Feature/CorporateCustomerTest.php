<?php

namespace Tests\Feature;

use App\Enums\AddressType;
use App\Enums\CustomerType;
use App\Enums\IdentificationType;
use App\Enums\RepresentativeRole;
use App\Livewire\Customers\Create;
use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerIdentification;
use App\Models\CustomerRelationship;
use App\Models\RelatedPerson;
use App\Models\User;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CorporateCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_corporate_customer(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('customerType', CustomerType::Corporate->value)
            ->set('identification_type', IdentificationType::BusinessRegistration->value)
            ->set('full_legal_name', 'Acme Holdings Sdn Bhd')
            ->set('identification_number', '202001234567')
            ->set('mobile', '0326131600')
            ->set('email', 'corp@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $customer = Customer::query()->first();
        $this->assertNotNull($customer);
        $this->assertSame(CustomerType::Corporate, $customer->type);
        $this->assertSame('Acme Holdings Sdn Bhd', $customer->full_legal_name);
        $this->assertDatabaseHas('customer_corporate_details', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('customer_fatca_declarations', [
            'customer_id' => $customer->id,
            'selected_classification' => 'non_us_person',
            'source' => 'system_default',
        ]);
    }

    public function test_individuals_index_excludes_corporate_and_corporate_index_excludes_individuals(): void
    {
        $user = User::factory()->staff()->create();
        $individual = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Individual,
            'full_legal_name' => 'Individual Person',
        ]);
        $corporate = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Corporate Entity Sdn Bhd',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Individual Person')
            ->assertDontSee('Corporate Entity Sdn Bhd');

        $this->actingAs($user)
            ->get(route('corporate-customers.index'))
            ->assertOk()
            ->assertSee('Corporate Entity Sdn Bhd')
            ->assertDontSee('Individual Person');

        $this->assertTrue($individual->isActive());
        $this->assertTrue($corporate->exists);
    }

    public function test_strong_duplicate_brn_blocks_corporate_create(): void
    {
        $user = User::factory()->staff()->create();
        $existing = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Existing Corp',
            'primary_identification_type' => IdentificationType::BusinessRegistration,
            'primary_identification_number' => '202001234567',
        ]);
        CustomerIdentification::query()->create([
            'customer_id' => $existing->id,
            'type' => IdentificationType::BusinessRegistration,
            'issuing_jurisdiction' => 'MY',
            'number' => '202001234567',
            'normalized_number' => '202001234567',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('customerType', CustomerType::Corporate->value)
            ->set('identification_type', IdentificationType::BusinessRegistration->value)
            ->set('full_legal_name', 'Another Corp')
            ->set('identification_number', '202001234567')
            ->call('save')
            ->assertSet('strongMatches', fn ($matches) => count($matches) === 1)
            ->assertNoRedirect();

        $this->assertSame(1, Customer::query()->where('type', CustomerType::Corporate)->count());
    }

    public function test_corporate_profile_completion_does_not_require_gender_or_dob(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Acme Holdings Sdn Bhd',
            'primary_identification_type' => IdentificationType::BusinessRegistration,
            'primary_identification_number' => '202001234567',
            'primary_mobile' => '0326131600',
            'date_of_birth' => null,
            'gender' => null,
            'marital_status' => null,
        ]);

        CustomerAddress::query()->create([
            'customer_id' => $customer->id,
            'type' => AddressType::Registered,
            'line_1' => 'Level 1, Tower A',
            'city' => 'Kuala Lumpur',
            'country' => 'Malaysia',
        ]);

        $sections = $customer->fresh()->profileSectionCompletion();
        $this->assertArrayHasKey('company', $sections);
        $this->assertArrayNotHasKey('personal', $sections);
        $this->assertSame(100, $sections['company']);
        $this->assertArrayHasKey('representatives', $sections);
        $this->assertSame(0, $sections['representatives']);
    }

    public function test_corporate_company_section_moves_with_brn_and_registered_address(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Bare Corp',
            'primary_identification_number' => null,
            'primary_mobile' => null,
            'primary_email' => null,
        ]);

        $this->assertLessThan(100, $customer->fresh()->profileSectionCompletion()['company']);

        app(CustomerProfileWrites::class)->saveCorporateCompany($customer, [
            'full_legal_name' => 'Bare Corp',
            'identification_number' => '199901234567',
            'mobile' => '0312345678',
            'email' => '',
            'fax' => '',
            'registered_at' => '',
            'registration_country' => 'MY',
            'entity_type' => '',
            'entity_sub_type' => '',
            'industry' => '',
            'business_activity' => '',
            'authorized_capital_rm' => '',
            'paid_up_capital_rm' => '',
        ], $user, true);

        app(CustomerProfileWrites::class)->saveCorporateAddress($customer, [
            'registered_line_1' => '1 Jalan Ampang',
            'registered_line_2' => '',
            'registered_city' => 'Kuala Lumpur',
            'registered_state' => 'WP',
            'registered_postcode' => '50450',
            'registered_country' => 'Malaysia',
            'same_as_registered' => true,
            'mailing_line_1' => '',
            'mailing_line_2' => '',
            'mailing_city' => '',
            'mailing_state' => '',
            'mailing_postcode' => '',
            'mailing_country' => '',
        ], $user);

        $this->assertSame(100, $customer->fresh()->profileSectionCompletion()['company']);
    }

    public function test_saving_primary_contact_completes_representatives_section(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Rep Corp',
        ]);

        app(CustomerProfileWrites::class)->saveRepresentative($customer, [
            'representativeRole' => RepresentativeRole::PrimaryContact->value,
            'signatoryOrder' => '',
            'fullLegalName' => 'Contact Person',
            'identificationType' => IdentificationType::Nric->value,
            'identificationNumber' => '900101121234',
            'mobile' => '0123456789',
            'homeTelephone' => '0326131600',
            'officeExtension' => '2101',
            'email' => 'contact@example.com',
            'occupation' => 'Director',
        ], $user);

        $this->assertDatabaseHas('customer_relationships', [
            'customer_id' => $customer->id,
            'representative_role' => RepresentativeRole::PrimaryContact->value,
        ]);
        $this->assertDatabaseHas('related_people', [
            'full_legal_name' => 'Contact Person',
            'home_telephone' => '0326131600',
            'office_extension' => '2101',
        ]);
        $this->assertSame(100, $customer->fresh()->profileSectionCompletion()['representatives']);
        $this->assertSame(1, RelatedPerson::query()->count());
        $this->assertSame(1, CustomerRelationship::query()->where('customer_id', $customer->id)->count());
    }

    public function test_corporate_detail_tabs_and_sst_save(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'type' => CustomerType::Corporate,
            'full_legal_name' => 'Tax Corp Sdn Bhd',
        ]);

        $this->actingAs($user)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Company')
            ->assertSee('Representatives')
            ->assertSee('Financial Information')
            ->assertSee('Investment')
            ->assertSee('Tax & Residency')
            ->assertSee('Compliance')
            ->assertDontSee('Personal Information')
            ->assertDontSee('Work Information');

        app(CustomerProfileWrites::class)->saveCorporateSst($customer, 'W10-1234-56789012', $user);

        $this->assertDatabaseHas('customer_corporate_details', [
            'customer_id' => $customer->id,
            'sst' => 'W10-1234-56789012',
        ]);

        $sections = $customer->fresh()->profileSectionCompletion();
        $this->assertArrayHasKey('company', $sections);
        $this->assertArrayHasKey('representatives', $sections);
        $this->assertArrayHasKey('financial', $sections);
        $this->assertArrayNotHasKey('personal', $sections);
        $this->assertArrayNotHasKey('work', $sections);
        $this->assertArrayNotHasKey('relationships', $sections);
    }
}
