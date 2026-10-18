<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Customers\Create;
use App\Livewire\Customers\Index;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\CustomerPepAssociation;
use App\Models\CustomerRelationship;
use App\Models\User;
use App\Services\CustomerReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_customers(): void
    {
        $this->get(route('customers.index'))->assertRedirect(route('login'));
    }

    public function test_staff_can_view_customer_list(): void
    {
        $user = User::factory()->staff()->create();

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Customers');
    }

    public function test_customer_reference_is_generated_sequentially(): void
    {
        $generator = app(CustomerReferenceGenerator::class);

        $this->assertSame('DAS-000001', $generator->generate());
        $this->assertSame('DAS-000002', $generator->generate());
    }

    public function test_staff_can_create_customer(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Ahmad Bin Ali')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101121234')
            ->set('mobile', '0123456789')
            ->set('email', 'ahmad@example.com')
            ->set('nationality', 'Malaysian')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'full_legal_name' => 'Ahmad Bin Ali',
            'reference' => 'DAS-000001',
            'primary_email' => 'ahmad@example.com',
            'race' => 'Malay',
            'country_of_residence' => 'Malaysia',
        ]);
    }

    public function test_strong_duplicate_identification_blocks_create(): void
    {
        $user = User::factory()->staff()->create();
        $existing = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101121234',
        ]);

        CustomerIdentification::query()->create([
            'customer_id' => $existing->id,
            'type' => IdentificationType::Nric,
            'issuing_jurisdiction' => 'MY',
            'number' => '900101-12-1234',
            'normalized_number' => '900101121234',
            'is_primary' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Another Person')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101121234')
            ->call('save')
            ->assertSet('strongMatches', fn ($matches) => count($matches) === 1)
            ->assertNoRedirect();

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_weak_duplicate_requires_confirmation(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Ahmad Bin Ali',
            'primary_email' => 'ahmad@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Someone Else')
            ->set('email', 'ahmad@example.com')
            ->call('save')
            ->assertSet('weakMatches', fn ($matches) => count($matches) === 1)
            ->assertNoRedirect();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Someone Else')
            ->set('email', 'ahmad@example.com')
            ->set('confirmWeakDuplicates', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('customers', 2);
    }

    public function test_customer_list_search_and_filter(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Ahmad Bin Ali']);
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Siti Aminah']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Ahmad')
            ->assertSee('Ahmad Bin Ali')
            ->assertDontSee('Siti Aminah')
            ->set('search', '')
            ->assertSee('Ahmad Bin Ali')
            ->assertSee('Siti Aminah');
    }

    public function test_profile_personal_section_can_be_updated(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Ahmad Bin Ali',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('full_legal_name', 'Ahmad Bin Ali Updated')
            ->set('marital_status', 'Married')
            ->set('race', 'Indian')
            ->set('date_of_birth', '1990-01-01')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'full_legal_name' => 'Ahmad Bin Ali Updated',
            'marital_status' => 'Married',
            'race' => 'Indian',
        ]);
    }

    public function test_clearing_customer_race_restores_malay_default(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create(['race' => 'Chinese']);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('race', '')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'race' => 'Malay']);
    }

    public function test_social_platforms_remain_separate_and_legacy_contact_is_preserved(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $customer->contacts()->create(['type' => 'social', 'value' => 'unknown-handle', 'normalized_value' => 'unknown-handle']);

        Livewire::actingAs($staff)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'contact')
            ->set('socialAccounts.facebook', 'hairaa.fb')
            ->set('socialAccounts.instagram', 'hairaa.ig')
            ->set('socialAccounts.twitter', 'hairaa.x')
            ->call('saveContact')->assertHasNoErrors();

        $accounts = $customer->contacts()->where('type', 'social')->get()->keyBy('platform');
        $this->assertSame('unknown-handle', $accounts->get('')->value);
        $this->assertSame('hairaa.fb', $accounts->get('facebook')->value);
        $this->assertSame('hairaa.ig', $accounts->get('instagram')->value);
        $this->assertSame('hairaa.x', $accounts->get('twitter')->value);
    }

    public function test_other_property_status_requires_and_preserves_description(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create();
        $component = Livewire::actingAs($staff)->test(Show::class, ['customer' => $customer]);

        $component->call('editSection', 'address')->set('occupancy_status', 'other')
            ->call('saveAddress')->assertHasErrors('occupancy_other');
        $component->set('occupancy_other', 'Living with family')->call('saveAddress')->assertHasNoErrors();

        $this->assertSame('Living with family', $customer->residentialAddress()->firstOrFail()->occupancy_other);
    }

    public function test_clearing_customer_country_of_residence_restores_malaysia_default(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create(['country_of_residence' => 'Singapore']);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->assertSet('country_of_residence', 'Singapore')
            ->set('country_of_residence', '')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'country_of_residence' => 'Malaysia']);
    }

    public function test_profile_shows_full_identification_in_header_while_form_value_stays_clear_until_editing(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101121234',
            'nationality' => null,
        ]);

        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Customer Summary')
            ->assertDontSee('tab-overview')
            ->assertSee('tab-personal')
            ->assertSee('tab-relationships')
            ->assertSee(" : 'personal'", false)
            ->assertSee('View Activity')
            ->assertSee('customer-activity-modal')
            ->assertSee('900101121234')
            ->assertSee('Not provided')
            ->assertDontSee('********1234');

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->assertSet('identification_number', '')
            ->call('editSection', 'contact')
            ->assertSet('identification_number', '')
            ->call('editSection', 'personal')
            ->assertSet('identification_number', '900101121234')
            ->call('cancelEdit')
            ->assertSet('identification_number', '');
    }

    public function test_archived_customer_can_be_restored_from_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->archived()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer->fresh()])
            ->call('restore')
            ->assertHasNoErrors();

        $this->assertTrue($customer->fresh()->isActive());
        $this->assertSame(['restored'], $customer->activities()->reorder()->orderBy('id')->pluck('action')->map(fn ($action): string => $action->value)->all());
    }

    public function test_archived_customer_can_be_restored_from_list(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->archived()->create([
            'full_legal_name' => 'List Archived Customer',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('status', 'archived')
            ->assertSee('List Archived Customer')
            ->call('restore', $customer->id);

        $this->assertTrue($customer->fresh()->isActive());
    }

    public function test_customer_can_be_deleted_from_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $customer->contacts()->create(['type' => 'email', 'value' => 'delete@example.com', 'normalized_value' => 'delete@example.com', 'is_primary' => true]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->assertSee('Delete customer?')
            ->call('delete')
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
        $this->assertDatabaseMissing('customer_contacts', ['customer_id' => $customer->id]);
        $this->actingAs($user)->get(route('customers.show', $customer))->assertNotFound();
    }

    public function test_archived_customer_can_be_deleted_from_profile(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->archived()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->assertSee('Delete customer?')
            ->call('delete')
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
    }

    public function test_deleting_customer_removes_links_from_other_customers(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $otherCustomer = Customer::factory()->for($user, 'creator')->create();
        $relationship = CustomerRelationship::factory()->for($otherCustomer)->linkedToCustomer($customer)->create();
        $association = CustomerPepAssociation::query()->create([
            'customer_id' => $otherCustomer->id,
            'customer_relationship_id' => $relationship->id,
            'relationship' => 'father',
            'position_status' => 'current',
            'started_on' => '2026-01-01',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('delete')
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
        $this->assertModelExists($otherCustomer);
        $this->assertDatabaseMissing('customer_relationships', ['id' => $relationship->id]);
        $this->assertModelMissing($association);
    }

    public function test_customer_list_shows_identification_numbers(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Visible Identity Customer',
            'primary_identification_number' => '001212120333',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('001212120333');
    }
}
