<?php

namespace Tests\Feature;

use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
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
}
