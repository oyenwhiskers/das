<?php

namespace Tests\Feature;

use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerProfileWrites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerSalutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_customers_receive_salutation_from_gender_without_replacing_a_custom_title(): void
    {
        $male = Customer::factory()->create(['gender' => 'Male']);
        $female = Customer::factory()->create(['gender' => 'Female']);
        $other = Customer::factory()->create(['gender' => 'Other']);
        $custom = Customer::factory()->create(['gender' => 'Female', 'salutation' => 'Dr.']);

        $this->assertSame('Encik', $male->fresh()->salutation);
        $this->assertSame('Cik', $female->fresh()->salutation);
        $this->assertNull($other->fresh()->salutation);
        $this->assertSame('Dr.', $custom->fresh()->salutation);
    }

    public function test_changing_gender_updates_an_automatic_salutation_but_preserves_a_custom_title(): void
    {
        $customer = Customer::factory()->create(['gender' => 'Male']);
        $custom = Customer::factory()->create(['gender' => 'Male', 'salutation' => 'Dr.']);

        $customer->update(['gender' => 'Female']);
        $custom->update(['gender' => 'Female']);
        $married = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'Married']);

        $this->assertSame('Cik', $customer->fresh()->salutation);
        $this->assertSame('Dr.', $custom->fresh()->salutation);
        $this->assertSame('Puan', $married->fresh()->salutation);
        $married->update(['marital_status' => 'Single']);
        $this->assertSame('Cik', $married->fresh()->salutation);
    }

    public function test_profile_form_suggests_salutation_when_gender_changes(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create(['gender' => 'Male']);

        Livewire::actingAs($user)
            ->test(Show::class, ['customer' => $customer])
            ->call('editSection', 'personal')
            ->set('gender', 'Female')
            ->assertSet('salutation', 'Cik')
            ->set('marital_status', 'Married')
            ->assertSet('salutation', 'Puan')
            ->call('savePersonal')
            ->assertHasNoErrors();

        $this->assertSame('Puan', $customer->fresh()->salutation);
    }

    public function test_saving_stale_cik_with_married_in_the_same_write_becomes_puan(): void
    {
        $customer = Customer::factory()->create(['gender' => 'Other', 'salutation' => 'Dr.']);
        DB::table('customers')->where('id', $customer->id)->update([
            'gender' => 'Female',
            'salutation' => null,
            'marital_status' => null,
        ]);

        $customer->refresh()->update([
            'salutation' => 'CIK',
            'gender' => 'Female',
            'marital_status' => 'Married',
        ]);

        $this->assertSame('Puan', $customer->fresh()->salutation);
    }

    public function test_save_personal_keeps_puan_when_merge_sends_cik_with_married(): void
    {
        $user = User::factory()->staff()->create();
        $customer = Customer::factory()->for($user, 'creator')->create([
            'gender' => 'Female',
            'marital_status' => null,
            'salutation' => 'Cik',
        ]);

        app(CustomerProfileWrites::class)->savePersonal($customer, [
            'salutation' => 'CIK',
            'full_legal_name' => $customer->full_legal_name,
            'gender' => 'Female',
            'marital_status' => 'Married',
            'identification_type' => null,
            'identification_number' => null,
            'date_of_birth' => null,
            'nationality' => null,
            'country_of_residence' => 'Malaysia',
            'race' => 'Malay',
            'old_nric' => null,
            'issuing_jurisdiction' => null,
        ], $user, true);

        $this->assertSame('Puan', $customer->fresh()->salutation);
        $this->assertSame('Married', $customer->fresh()->marital_status);
    }

    public function test_create_with_explicit_cik_and_married_becomes_puan(): void
    {
        $customer = Customer::factory()->create([
            'gender' => 'Female',
            'marital_status' => 'Married',
            'salutation' => 'Cik',
        ]);

        $this->assertSame('Puan', $customer->fresh()->salutation);
    }

    public function test_backfill_updates_existing_standard_titles_and_leaves_custom_titles_intact(): void
    {
        $male = Customer::factory()->create(['gender' => 'Male']);
        $female = Customer::factory()->create(['gender' => 'Female']);
        $incorrect = Customer::factory()->create(['gender' => 'Female']);
        $custom = Customer::factory()->create(['gender' => 'Female', 'salutation' => 'Dr.']);
        $other = Customer::factory()->create(['gender' => 'Other']);
        DB::table('customers')->where('id', $male->id)->update(['salutation' => null]);
        DB::table('customers')->where('id', $female->id)->update(['salutation' => '']);
        DB::table('customers')->where('id', $incorrect->id)->update(['salutation' => 'Mr.']);

        $migration = require database_path('migrations/2026_10_15_224035_backfill_customer_salutations.php');
        $migration->up();

        $this->assertSame('Mr.', $male->fresh()->salutation);
        $this->assertSame('Ms.', $female->fresh()->salutation);
        $this->assertSame('Ms.', $incorrect->fresh()->salutation);
        $this->assertSame('Dr.', $custom->fresh()->salutation);
        $this->assertNull($other->fresh()->salutation);
    }

    public function test_marital_status_backfill_fixes_stale_automatic_titles_and_leaves_custom_titles(): void
    {
        $marriedCik = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'Married', 'salutation' => 'Puan']);
        $marriedMs = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'MARRIED', 'salutation' => 'Puan']);
        $singlePuan = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'Single', 'salutation' => 'Cik']);
        $maleMs = Customer::factory()->create(['gender' => 'Male', 'marital_status' => 'Married', 'salutation' => 'Encik']);
        $custom = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'Married', 'salutation' => 'Dr.']);
        $alreadyPuan = Customer::factory()->create(['gender' => 'Female', 'marital_status' => 'Married', 'salutation' => 'Puan']);

        DB::table('customers')->where('id', $marriedCik->id)->update(['salutation' => 'CIK']);
        DB::table('customers')->where('id', $marriedMs->id)->update(['salutation' => 'Ms.']);
        DB::table('customers')->where('id', $singlePuan->id)->update(['salutation' => 'Puan']);
        DB::table('customers')->where('id', $maleMs->id)->update(['salutation' => 'Ms.']);

        $migration = require database_path('migrations/2026_10_21_185500_backfill_customer_salutations_from_marital_status.php');
        $migration->up();

        $this->assertSame('Puan', $marriedCik->fresh()->salutation);
        $this->assertSame('Puan', $marriedMs->fresh()->salutation);
        $this->assertSame('Cik', $singlePuan->fresh()->salutation);
        $this->assertSame('Encik', $maleMs->fresh()->salutation);
        $this->assertSame('Dr.', $custom->fresh()->salutation);
        $this->assertSame('Puan', $alreadyPuan->fresh()->salutation);
    }
}
