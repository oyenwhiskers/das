<?php

namespace Tests\Feature;

use App\Enums\IdentificationType;
use App\Livewire\Customers\Create;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NricAutofillTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_autofills_empty_dob_and_gender_from_nric(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101-14-1235')
            ->assertSet('date_of_birth', '1990-01-01')
            ->assertSet('gender', 'Male')
            ->assertSet('nationality', 'Malaysian');
    }

    public function test_create_form_does_not_overwrite_existing_dob_and_gender(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('date_of_birth', '1985-05-05')
            ->set('gender', 'Other')
            ->set('nationality', 'Singaporean')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101141235')
            ->assertSet('date_of_birth', '1985-05-05')
            ->assertSet('gender', 'Other')
            ->assertSet('nationality', 'Singaporean');
    }

    public function test_create_form_does_not_autofill_for_passport(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('identification_type', IdentificationType::Passport->value)
            ->set('identification_number', '900101141235')
            ->assertSet('date_of_birth', '')
            ->assertSet('gender', '')
            ->assertSet('nationality', '');
    }

    public function test_create_persists_autofilled_dob_and_gender(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Ahmad Bin Ali')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101141234')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'full_legal_name' => 'Ahmad Bin Ali',
            'date_of_birth' => '1990-01-01 00:00:00',
            'gender' => 'Female',
            'salutation' => 'Cik',
        ]);
    }

    public function test_create_fills_missing_details_again_when_saving(): void
    {
        $user = User::factory()->staff()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('full_legal_name', 'Siti Aminah')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101141234')
            ->set('date_of_birth', '')
            ->set('gender', '')
            ->set('nationality', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'full_legal_name' => 'Siti Aminah',
            'date_of_birth' => '1990-01-01 00:00:00',
            'gender' => 'Female',
            'salutation' => 'Cik',
            'nationality' => 'Malaysian',
        ]);
    }

    public function test_profile_personal_edit_autofills_empty_dob_and_gender_from_nric(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
            'primary_identification_type' => null,
            'primary_identification_number' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '050615145679')
            ->assertSet('date_of_birth', '2005-06-15')
            ->assertSet('gender', 'Male')
            ->assertSet('salutation', 'Encik')
            ->assertSet('nationality', 'Malaysian');
    }

    public function test_profile_personal_edit_does_not_overwrite_existing_values(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'date_of_birth' => '1988-03-03',
            'gender' => 'Female',
            'nationality' => 'Singaporean',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('identification_type', IdentificationType::Nric->value)
            ->set('identification_number', '900101141235')
            ->assertSet('date_of_birth', '1988-03-03')
            ->assertSet('gender', 'Female')
            ->assertSet('nationality', 'Singaporean');
    }

    public function test_existing_nric_fills_missing_personal_details_when_editing_and_saving(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '050615145679',
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->assertSet('date_of_birth', '2005-06-15')
            ->assertSet('gender', 'Male')
            ->assertSet('nationality', 'Malaysian')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'date_of_birth' => '2005-06-15 00:00:00',
            'gender' => 'Male',
            'nationality' => 'Malaysian',
        ]);
    }

    public function test_saving_existing_nric_fills_missing_details_after_they_are_cleared(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101141234',
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('date_of_birth', '')
            ->set('gender', '')
            ->set('nationality', '')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'date_of_birth' => '1990-01-01 00:00:00',
            'gender' => 'Female',
            'nationality' => 'Malaysian',
        ]);
    }

    public function test_existing_customers_receive_only_missing_nric_details_during_backfill(): void
    {
        $user = User::factory()->staff()->create();
        $missingDetails = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => '900101141235',
            'date_of_birth' => null,
            'gender' => 'Other',
            'nationality' => null,
        ]);
        $invalidNric = Customer::factory()->for($user, 'creator')->create([
            'primary_identification_type' => IdentificationType::Nric,
            'primary_identification_number' => 'invalid',
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
        ]);

        $migration = require database_path('migrations/2026_10_15_220556_backfill_customer_details_from_nric.php');
        $migration->up();

        $this->assertDatabaseHas('customers', [
            'id' => $missingDetails->id,
            'date_of_birth' => '1990-01-01',
            'gender' => 'Other',
            'nationality' => 'Malaysian',
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $invalidNric->id,
            'date_of_birth' => null,
            'gender' => null,
            'nationality' => null,
        ]);
    }
}
