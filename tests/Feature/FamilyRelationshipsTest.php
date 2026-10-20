<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Customers\FamilyRelationships;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FamilyRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_discarding_relationship_edit_resets_the_form(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')
            ->set('fullLegalName', 'Unsaved Person')
            ->dispatch('discard-relationship-edit')
            ->assertSet('mode', '')
            ->assertSet('fullLegalName', '');

        $this->assertDatabaseCount('customer_relationships', 0);
    }

    public function test_adding_relationship_opens_modal_and_keeps_list_visible(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->assertDontSee('aria-labelledby="family-form-title"', false)
            ->call('add')
            ->assertSee('aria-labelledby="family-form-title"', false)
            ->assertSee('No relationships added yet')
            ->call('cancel')
            ->assertDontSee('aria-labelledby="family-form-title"', false);
    }

    public function test_staff_adds_edits_and_removes_standalone_person_without_deleting_identity(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        $component = Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', 'Siti Binti Hassan')->set('relationship', 'spouse')
            ->set('mobile', '+60123456789')->set('isNextOfKin', true)->set('isPrimaryNextOfKin', true)
            ->call('save')->assertHasNoErrors()->assertSee('Siti Binti Hassan');

        $relationship = $customer->relationships()->firstOrFail();
        $this->assertDatabaseCount('customers', 1);
        $this->assertTrue($relationship->is_primary_next_of_kin);

        $component->call('edit', $relationship->id)->assertSee('aria-labelledby="family-form-title"', false)
            ->set('occupation', 'Accountant')
            ->call('save')->assertHasNoErrors()->call('view', $relationship->id)
            ->assertSee('Accountant')->call('remove', $relationship->id);

        $this->assertSoftDeleted('customer_relationships', ['id' => $relationship->id]);
        $this->assertDatabaseHas('related_people', ['id' => $relationship->related_person_id, 'occupation' => 'Accountant']);
        $this->assertSame(['related_person_added', 'next_of_kin_updated', 'related_person_updated', 'relationship_removed'], $customer->activities()->reorder()->orderBy('id')->pluck('action')->map(fn ($action): string => $action->value)->all());
    }

    public function test_related_person_employer_address_parts_are_independent_of_legacy_text(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', 'Spouse')->set('relationship', 'spouse')
            ->set('employerAddressLine1', '12 Jalan Merdeka')
            ->set('employerCity', 'Kuala Lumpur')
            ->set('employerState', 'Wilayah Persekutuan')
            ->set('employerPostcode', '05000')
            ->set('employerCountry', 'Malaysia')
            ->call('save')->assertHasNoErrors();

        $person = $customer->relationships()->firstOrFail()->relatedPerson;
        $this->assertSame('12 Jalan Merdeka', $person->employer_address_line_1);
        $this->assertSame('Kuala Lumpur', $person->employer_city);
        $this->assertSame('Wilayah Persekutuan', $person->employer_state);
        $this->assertSame('05000', $person->employer_postcode);
        $this->assertSame('Malaysia', $person->employer_country);
        $this->assertNull($person->employer_address);
    }

    public function test_existing_customer_can_be_linked_once_without_changing_master_details(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $spouse = Customer::factory()->for($staff, 'creator')->create(['full_legal_name' => 'Siti Binti Hassan']);

        $component = Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('source', 'customer')->set('linkedCustomerId', (string) $spouse->id)
            ->set('relationship', 'spouse')->set('isNextOfKin', true)->call('save')->assertHasNoErrors();

        $this->assertDatabaseHas('customer_relationships', ['customer_id' => $customer->id, 'related_customer_id' => $spouse->id, 'related_person_id' => null]);
        $this->assertDatabaseCount('related_people', 0);

        $component->call('add')->set('source', 'customer')->set('linkedCustomerId', (string) $spouse->id)
            ->set('relationship', 'other')->set('otherRelationship', 'Next of kin')->call('save')->assertHasErrors('linkedCustomerId');

        $this->assertSame('Siti Binti Hassan', $spouse->fresh()->full_legal_name);
        $this->assertSame(1, $customer->relationships()->count());
    }

    public function test_self_link_and_next_of_kin_without_contact_are_rejected(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('source', 'customer')->set('linkedCustomerId', (string) $customer->id)
            ->set('relationship', 'spouse')->call('save')->assertHasErrors('linkedCustomerId');

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', 'Relative')->set('relationship', 'father')
            ->set('isNextOfKin', true)->call('save')->assertHasErrors('mobile');

        $this->assertDatabaseCount('customer_relationships', 0);
    }

    public function test_inactive_staff_cannot_manage_relationships(): void
    {
        $staff = User::factory()->staff()->inactive()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])->assertForbidden();
    }

    public function test_primary_next_of_kin_moves_to_one_person_and_spouse_can_also_be_next_of_kin(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        $component = Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer]);
        $component->call('add')->set('fullLegalName', 'Spouse')->set('relationship', 'spouse')
            ->set('mobile', '0123456789')->set('isNextOfKin', true)->set('isPrimaryNextOfKin', true)
            ->call('save')->assertHasNoErrors();
        $first = $customer->relationships()->firstOrFail();

        $component->call('add')->set('fullLegalName', 'Brother')->set('relationship', 'brother')
            ->set('mobile', '0133456789')->set('isNextOfKin', true)->set('isPrimaryNextOfKin', true)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(2, $customer->relationships()->count());
        $this->assertFalse($first->fresh()->is_primary_next_of_kin);
        $this->assertSame('Brother', $customer->relationships()->where('is_primary_next_of_kin', true)->firstOrFail()->relatedPerson->full_legal_name);
        $this->assertTrue($first->fresh()->is_next_of_kin);
    }

    public function test_profile_shows_family_section_and_escapes_related_person_name(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        $this->actingAs($staff)->get(route('customers.show', $customer))->assertOk()->assertSee('Family & Relationships')->assertSee('No relationships added yet');

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', '<script>alert(1)</script>')->set('relationship', 'other')
            ->set('otherRelationship', 'Friend')->call('save')->assertHasNoErrors()
            ->assertSee('&lt;script&gt;', escape: false)->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    public function test_removing_link_preserves_existing_customer_and_allows_relinking(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $relative = Customer::factory()->for($staff, 'creator')->create();

        $component = Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer]);
        $component->call('add')->set('source', 'customer')->set('linkedCustomerId', (string) $relative->id)
            ->set('relationship', 'father')->call('save')->assertHasNoErrors();
        $oldLink = $customer->relationships()->firstOrFail();

        $component->call('remove', $oldLink->id)->call('add')->set('source', 'customer')
            ->set('linkedCustomerId', (string) $relative->id)->set('relationship', 'father')
            ->call('save')->assertHasNoErrors();

        $this->assertModelExists($relative);
        $this->assertSoftDeleted('customer_relationships', ['id' => $oldLink->id]);
        $this->assertSame(1, $customer->relationships()->count());
    }

    public function test_clearing_next_of_kin_also_clears_preferred_designation(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', 'Relative')->set('relationship', 'sister')
            ->set('mobile', '0123456789')->set('isNextOfKin', true)->set('isPrimaryNextOfKin', true)
            ->set('isNextOfKin', false)->assertSet('isPrimaryNextOfKin', false)
            ->call('save')->assertHasNoErrors();

        $this->assertFalse($customer->relationships()->firstOrFail()->is_next_of_kin);
    }

    public function test_self_employed_next_of_kin_gets_applicant_residential_as_employer_address(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create();
        $customer->addresses()->create([
            'type' => \App\Enums\AddressType::Residential,
            'line_1' => 'LOT 244 KG BARU',
            'city' => 'Machang',
            'state' => 'Kelantan',
            'postcode' => '18500',
            'country' => 'Malaysia',
        ]);

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer->fresh()])
            ->call('add')
            ->set('fullLegalName', 'Mazwan Bin Mahmud')
            ->set('relationship', 'father')
            ->set('occupation', 'BUSINESS')
            ->set('mobile', '+60123456789')
            ->set('isNextOfKin', true)
            ->set('isPrimaryNextOfKin', true)
            ->call('save')->assertHasNoErrors();

        $person = $customer->relationships()->firstOrFail()->relatedPerson;
        $this->assertSame('LOT 244 KG BARU', $person->employer_address_line_1);
        $this->assertSame('Machang', $person->employer_city);
        $this->assertSame('18500', $person->employer_postcode);
        $this->assertSame('Malaysia', $person->employer_country);
    }

    public function test_a_single_customer_cannot_be_given_a_spouse(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->for($staff, 'creator')->create(['marital_status' => 'Single']);

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $customer])
            ->call('add')->set('fullLegalName', 'Mazwan Bin Mahmud')->set('relationship', 'spouse')
            ->set('mobile', '+60123456789')->set('isNextOfKin', true)->set('isPrimaryNextOfKin', true)
            ->call('save')->assertHasErrors(['relationship']);

        $this->assertDatabaseCount('customer_relationships', 0);
    }

    public function test_the_same_family_identification_is_stored_separately_for_each_customer(): void
    {
        $staff = User::factory()->staff()->create();
        $firstCustomer = Customer::factory()->for($staff, 'creator')->create();
        $secondCustomer = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $firstCustomer])
            ->call('add')->set('fullLegalName', 'Siti Hassan')->set('relationship', 'spouse')
            ->set('identificationType', 'nric')->set('identificationNumber', '900101121234')
            ->call('save')->assertHasNoErrors();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $secondCustomer])
            ->call('add')->set('fullLegalName', 'Different Person')->set('relationship', 'sister')
            ->set('identificationType', 'nric')->set('identificationNumber', '900101121234')
            ->call('save')->assertHasNoErrors();

        $this->assertDatabaseCount('related_people', 2);
        $this->assertDatabaseCount('customer_relationships', 2);
        $this->assertSame('Siti Hassan', $firstCustomer->relationships()->firstOrFail()->relatedPerson->full_legal_name);
        $this->assertSame('Different Person', $secondCustomer->relationships()->firstOrFail()->relatedPerson->full_legal_name);
        $this->assertSame('900101-12-1234', $secondCustomer->relationships()->firstOrFail()->relatedPerson->identification_number);
        $this->assertSame('900101121234', $secondCustomer->relationships()->firstOrFail()->relatedPerson->normalized_identification_number);
    }

    public function test_a_family_copy_can_use_an_identification_that_already_belongs_to_a_customer(): void
    {
        $staff = User::factory()->staff()->create();
        $existing = Customer::factory()->for($staff, 'creator')->create([
            'full_legal_name' => 'Suhaimi Bin Shafain',
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '810506036065',
        ]);
        CustomerIdentification::query()->create([
            'customer_id' => $existing->id,
            'type' => IdentificationType::Nric,
            'issuing_jurisdiction' => 'MY',
            'number' => '810506036065',
            'normalized_number' => '810506036065',
            'is_primary' => true,
        ]);
        $applicant = Customer::factory()->for($staff, 'creator')->create();

        Livewire::actingAs($staff)->test(FamilyRelationships::class, ['customer' => $applicant])
            ->call('add')->set('fullLegalName', 'Suhaimi Bin Shafain')->set('relationship', 'other')
            ->set('otherRelationship', 'Next of kin')
            ->set('identificationType', 'nric')->set('identificationNumber', '810506036065')
            ->call('save')->assertHasNoErrors();

        $relationship = $applicant->relationships()->firstOrFail();
        $this->assertNull($relationship->related_customer_id);
        $this->assertSame('Suhaimi Bin Shafain', $relationship->relatedPerson->full_legal_name);
        $this->assertSame('810506-03-6065', $relationship->relatedPerson->identification_number);
        $this->assertSame('810506036065', $relationship->relatedPerson->normalized_identification_number);
    }
}
