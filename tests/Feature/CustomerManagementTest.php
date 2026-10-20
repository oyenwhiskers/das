<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Customers\Create;
use App\Livewire\Customers\Index;
use App\Livewire\Customers\Show;
use App\Models\BimbsecApplication;
use App\Models\BimbsecDocument;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\CustomerPepAssociation;
use App\Models\CustomerRelationship;
use App\Models\DocumentScan;
use App\Models\ExtractedProfile;
use App\Models\ExtractedProfileMerge;
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
            ->assertSet('sort', 'name_asc')
            ->set('search', 'Ahmad')
            ->assertSee('Ahmad Bin Ali')
            ->assertDontSee('Siti Aminah')
            ->set('search', '')
            ->assertSee('Ahmad Bin Ali')
            ->assertSee('Siti Aminah');
    }

    public function test_customer_list_defaults_to_name_ascending(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Zainal Abidin']);
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Ahmad Bin Ali']);

        $html = Livewire::actingAs($user)->test(Index::class)->html();
        $this->assertLessThan(
            strpos($html, 'Zainal Abidin'),
            strpos($html, 'Ahmad Bin Ali'),
        );
    }

    public function test_customer_list_name_header_toggles_alphabetical_sort(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Zainal Abidin']);
        Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Ahmad Bin Ali']);

        $component = Livewire::actingAs($user)->test(Index::class)->assertSet('sort', 'name_asc');
        $asc = $component->html();
        $this->assertLessThan(strpos($asc, 'Zainal Abidin'), strpos($asc, 'Ahmad Bin Ali'));

        $desc = $component->call('sortByName')->assertSet('sort', 'name_desc')->html();
        $this->assertLessThan(strpos($desc, 'Ahmad Bin Ali'), strpos($desc, 'Zainal Abidin'));

        $component->call('sortByName')->assertSet('sort', 'name_asc');
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

    public function test_personal_edit_shows_bimbsec_fit_hint_and_still_saves_when_over_limit(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Ahmad Bin Ali',
        ]);
        $long = str_repeat('A', 70);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->assertSee('BIMBSEC form fits 64 characters')
            ->set('full_legal_name', $long)
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'full_legal_name' => $long,
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

    public function test_contact_phones_are_stored_without_punctuation(): void
    {
        $staff = User::factory()->staff()->create();
        $customer = Customer::factory()->create(['primary_mobile' => null]);

        Livewire::actingAs($staff)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'contact')
            ->set('mobile', '012-883 4419')
            ->set('home_telephone', '03-7721 4400')
            ->call('saveContact')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('0128834419', $customer->primary_mobile);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'type' => 'mobile',
            'value' => '0128834419',
            'normalized_value' => '0128834419',
        ]);
        $this->assertDatabaseHas('customer_contacts', [
            'customer_id' => $customer->id,
            'type' => 'home',
            'value' => '0377214400',
            'normalized_value' => '0377214400',
        ]);
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
            ->assertSee('More')
            ->assertSee('customer-more-menu')
            ->assertSee('View Activity')
            ->assertSee('openBimbsec()')
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

    public function test_profile_more_menu_opens_bimbsec_detail(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $application = $customer->bimbsecApplications()->create([
            'choices' => BimbsecApplication::normalizeChoices([]),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        BimbsecDocument::query()->create([
            'bimbsec_application_id' => $application->id,
            'customer_id' => $customer->id,
            'source_hash' => str_repeat('a', 64),
            'template_sha256' => str_repeat('b', 64),
            'mapping_sha256' => str_repeat('c', 64),
            'storage_path' => 'bimbsec/applications/test.pdf',
            'page_count' => 1,
            'warnings' => [],
            'print_blocked' => false,
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer->fresh()])
            ->call('openBimbsec')
            ->assertRedirect(route('bimbsec.show', [$customer, $application]));
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

    public function test_deleting_a_customer_clears_a_profile_merge_and_their_own_form(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();
        $scan = DocumentScan::factory()->for($user, 'uploader')->create();
        $profile = ExtractedProfile::query()->create([
            'document_scan_id' => $scan->id,
            'record_ref' => 'R1',
            'source_pages' => [1],
            'field_schema_hash' => str_repeat('a', 64),
            'field_schema_version' => '1',
            'contract_version' => '1',
            'original_projection' => [],
            'reviewed_projection' => [],
        ]);
        $merge = ExtractedProfileMerge::query()->create([
            'submission_key' => 'delete-customer',
            'extracted_profile_id' => $profile->id,
            'customer_id' => $customer->id,
            'reviewer_revision' => 0,
            'strategy' => 'fill_empty',
            'changes' => [],
            'merged_by' => $user->id,
            'created_at' => now(),
        ]);
        $customer->bimbsecApplications()->create([
            'choices' => [],
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('delete')
            ->assertRedirect(route('customers.index'));

        $this->assertModelMissing($customer);
        $this->assertModelMissing($merge);
        $this->assertModelExists($profile);
    }

    public function test_invalid_ic_shows_a_format_error(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Bad Ic Customer',
            'primary_identification_number' => '7704036259',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->assertSee('7704036259')
            ->assertSee('NRIC must be 12 digits (with or without dashes).');

        $customer->update([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101121234',
        ]);
        $this->assertNull($customer->fresh()->identificationFormatError());

        $customer->update([
            'primary_identification_type' => IdentificationType::Passport,
            'primary_identification_number' => 'A12345',
        ]);
        $this->assertNull($customer->fresh()->identificationFormatError());
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

    public function test_customer_list_uses_a_row_count_and_profile_completion(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Numbered Customer',
        ]);

        $html = Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Numbered Customer')
            ->assertSee('29%')
            ->html();

        $this->assertMatchesRegularExpression('/<th[^>]*>#<\/th>/', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*'.preg_quote($customer->reference, '/').'\s*</', $html);
    }

    public function test_profile_completion_percent_rises_as_sections_are_filled(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'full_legal_name' => 'Sparse Customer',
            'nationality' => null,
            'primary_email' => null,
            'primary_mobile' => null,
        ]);

        $this->assertSame(12, $customer->profileCompletionPercent());
        $this->assertSame(11, $customer->profileSectionCompletion()['personal']);
        $this->assertSame(0, $customer->profileSectionCompletion()['relationships']);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->assertSee('11%')
            ->assertSee('0%')
            ->assertSee('Still needs to be filled', false);

        $customer->update([
            'primary_identification_number' => '900101121234',
            'date_of_birth' => '1990-01-01',
            'gender' => 'male',
            'nationality' => 'Malaysian',
            'marital_status' => 'Single',
            'primary_mobile' => '0123456789',
            'primary_email' => 'sparse@example.com',
            'employment_status' => 'employed',
        ]);
        $customer->addresses()->create([
            'type' => 'residential',
            'line_1' => '1 Jalan Test',
        ]);

        $fresh = $customer->fresh();
        $this->assertSame(65, $fresh->profileCompletionPercent());
        $this->assertSame(100, $fresh->profileSectionCompletion()['personal']);
        $this->assertSame(50, $fresh->profileSectionCompletion()['work']);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $fresh])
            ->assertDontSee('Still needs to be filled', false);
    }

    public function test_selected_customers_can_be_deleted_from_the_list(): void
    {
        $user = User::factory()->staff()->create();
        $keep = Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Keep Customer']);
        $remove = Customer::factory()->for($user, 'creator')->create(['full_legal_name' => 'Remove Customer']);
        $other = Customer::factory()->for($user, 'creator')->create();
        $relationship = CustomerRelationship::factory()->for($other)->linkedToCustomer($remove)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('toggle', $remove->id)
            ->call('deleteSelected')
            ->assertSee('Keep Customer')
            ->assertDontSee('Remove Customer');

        $this->assertModelMissing($remove);
        $this->assertModelExists($keep);
        $this->assertModelExists($other);
        $this->assertDatabaseMissing('customer_relationships', ['id' => $relationship->id]);
    }

    public function test_select_all_includes_customers_on_every_page(): void
    {
        $user = User::factory()->staff()->create();
        Customer::factory()->for($user, 'creator')->count(16)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('toggleAll')
            ->assertCount('selected', 16);
    }
}
