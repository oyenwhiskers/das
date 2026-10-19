<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Customers\Create;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\CustomerIdentification;
use App\Models\RelatedPerson;
use App\Models\User;
use App\Services\CustomerCreator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerIdentificationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_blocks_formatted_duplicate_of_archived_customer(): void
    {
        $user = User::factory()->staff()->create();
        $existing = app(CustomerCreator::class)->create([
            'full_legal_name' => 'Archived Person',
            'identification_type' => 'nric',
            'identification_number' => '900101-12-1234',
        ], $user);
        $existing->update(['status' => 'archived']);

        Livewire::actingAs($user)->test(Create::class)
            ->set('full_legal_name', 'New Person')
            ->set('identification_type', 'nric')
            ->set('identification_number', '900101121234')
            ->call('save')
            ->assertSet('strongMatches', fn (array $matches): bool => count($matches) === 1)
            ->assertNoRedirect();

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_profile_edit_blocks_duplicate_of_archived_customer_and_preserves_original_data(): void
    {
        $user = User::factory()->staff()->create();
        $existing = app(CustomerCreator::class)->create([
            'full_legal_name' => 'Archived Person',
            'identification_type' => 'nric',
            'identification_number' => '900101-12-1234',
        ], $user);
        $existing->update(['status' => 'archived']);
        $customer = Customer::factory()->for($user, 'creator')->create(['reference' => 'DAS-000002', 'full_legal_name' => 'Original Name']);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('full_legal_name', 'Changed Name')
            ->set('identification_type', 'nric')
            ->set('identification_number', '900101121234')
            ->call('savePersonal')
            ->assertHasErrors(['identification_number']);

        $this->assertSame('Original Name', $customer->fresh()->full_legal_name);
        $this->assertDatabaseCount('customer_identifications', 1);
    }

    public function test_profile_can_save_same_identification_in_another_format(): void
    {
        $user = User::factory()->staff()->create();
        $customer = app(CustomerCreator::class)->create([
            'full_legal_name' => 'Same Person',
            'identification_type' => 'nric',
            'identification_number' => '900101-12-1234',
        ], $user);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_number', '900101121234')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_identifications', [
            'customer_id' => $customer->id,
            'issuing_jurisdiction' => 'MY',
            'number' => '900101-12-1234',
            'normalized_number' => '900101121234',
        ]);
        $this->assertSame('900101-12-1234', $customer->fresh()->primary_identification_number);
    }

    public function test_create_stores_undashed_nric_in_dashed_format(): void
    {
        $user = User::factory()->staff()->create();
        $customer = app(CustomerCreator::class)->create([
            'full_legal_name' => 'Dashed Person',
            'identification_type' => 'nric',
            'identification_number' => '900101121234',
        ], $user);

        $this->assertSame('900101-12-1234', $customer->primary_identification_number);
        $this->assertDatabaseHas('customer_identifications', [
            'customer_id' => $customer->id,
            'number' => '900101-12-1234',
            'normalized_number' => '900101121234',
        ]);
    }

    public function test_backfill_rewrites_existing_undashed_nrics_and_leaves_others(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101121234',
        ]);
        $identification = CustomerIdentification::query()->create([
            'customer_id' => $customer->id,
            'type' => IdentificationType::Nric,
            'issuing_jurisdiction' => 'MY',
            'number' => '900101121234',
            'normalized_number' => '900101121234',
            'is_primary' => true,
        ]);
        $passportCustomer = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Passport,
            'primary_identification_number' => 'A1234567',
        ]);
        $person = RelatedPerson::query()->create([
            'full_legal_name' => 'Family Member',
            'identification_type' => IdentificationType::Nric,
            'identification_number' => '810506036065',
            'normalized_identification_number' => '810506036065',
        ]);
        $short = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '7704036259',
        ]);

        $migration = require database_path('migrations/2026_10_19_120000_backfill_nric_dashed_format.php');
        $migration->up();

        $this->assertSame('900101-12-1234', $customer->fresh()->primary_identification_number);
        $this->assertSame('900101-12-1234', $identification->fresh()->number);
        $this->assertSame('900101121234', $identification->fresh()->normalized_number);
        $this->assertSame('A1234567', $passportCustomer->fresh()->primary_identification_number);
        $this->assertSame('810506-03-6065', $person->fresh()->identification_number);
        $this->assertSame('810506036065', $person->fresh()->normalized_identification_number);
        $this->assertSame('7704036259', $short->fresh()->primary_identification_number);
    }

    public function test_passport_identity_is_scoped_to_issuing_jurisdiction(): void
    {
        $user = User::factory()->staff()->create();
        app(CustomerCreator::class)->create([
            'full_legal_name' => 'First Passport',
            'identification_type' => 'passport',
            'identification_number' => 'ab-123',
            'issuing_jurisdiction' => 'MY',
        ], $user);

        Livewire::actingAs($user)->test(Create::class)
            ->set('full_legal_name', 'Second Passport')
            ->set('identification_type', 'passport')
            ->set('identification_number', 'AB123')
            ->set('issuing_jurisdiction', 'SG')
            ->set('confirmWeakDuplicates', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('customer_identifications', 2);
    }

    public function test_missing_identification_fields_and_invalid_nric_are_rejected(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)->test(Create::class)
            ->set('full_legal_name', 'Incomplete')
            ->set('identification_type', 'passport')
            ->call('save')
            ->assertHasErrors(['identification_number', 'issuing_jurisdiction']);

        Livewire::actingAs($user)->test(Create::class)
            ->set('full_legal_name', 'Invalid')
            ->set('identification_type', 'nric')
            ->set('identification_number', '123')
            ->call('save')
            ->assertHasErrors(['identification_number']);

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_can_register_without_identification_and_add_it_later(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)->test(Create::class)
            ->set('full_legal_name', 'Later Identification')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $customer = Customer::query()->firstOrFail();

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_type', 'passport')
            ->set('identification_number', ' AB-123 ')
            ->set('issuing_jurisdiction', 'sg')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_identifications', [
            'customer_id' => $customer->id,
            'type' => 'passport',
            'issuing_jurisdiction' => 'SG',
            'normalized_number' => 'AB123',
        ]);
    }

    public function test_profile_rejects_missing_jurisdiction_and_malformed_nric(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_type', 'passport')
            ->set('identification_number', 'AB123')
            ->call('savePersonal')
            ->assertHasErrors(['issuing_jurisdiction']);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_type', 'nric')
            ->set('identification_number', 'bad')
            ->call('savePersonal')
            ->assertHasErrors(['identification_number']);
    }

    public function test_profile_can_record_old_nric_without_current_identification(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create();

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('old_nric', 'A1234567')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customer_identifications', [
            'customer_id' => $customer->id,
            'type' => 'other',
            'issuing_jurisdiction' => 'UNSPECIFIED',
            'old_nric' => 'A1234567',
        ]);

        Livewire::actingAs($user)->test(Show::class, ['customer' => $customer->fresh()])
            ->call('editSection', 'personal')
            ->set('full_legal_name', 'Updated Name')
            ->call('savePersonal')
            ->assertHasNoErrors();
    }

    public function test_database_rejects_second_write_after_stale_duplicate_check(): void
    {
        $user = User::factory()->staff()->create();
        app(CustomerCreator::class)->create([
            'full_legal_name' => 'First',
            'identification_type' => 'nric',
            'identification_number' => '900101121234',
        ], $user);
        $second = Customer::factory()->for($user, 'creator')->create(['reference' => 'DAS-000002']);

        $this->expectException(UniqueConstraintViolationException::class);

        CustomerIdentification::query()->create([
            'customer_id' => $second->id,
            'type' => IdentificationType::Nric,
            'issuing_jurisdiction' => 'MY',
            'number' => '900101-12-1234',
            'normalized_number' => '900101121234',
            'is_primary' => true,
        ]);
    }

    public function test_inactive_user_cannot_enter_customer_lifecycle(): void
    {
        $user = User::factory()->inactive()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($user)->get(route('customers.index'))->assertRedirect();
        $this->get(route('customers.create'))->assertRedirect();
        $this->get(route('customers.show', $customer))->assertRedirect();
    }
}
