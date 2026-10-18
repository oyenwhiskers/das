<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use Illuminate\Database\Seeder;

class FamilyRelationshipSeeder extends Seeder
{
    public function run(): void
    {
        $customer = Customer::factory()->create();
        $spouse = Customer::factory()->create();

        CustomerRelationship::factory()->for($customer)->linkedToCustomer($spouse)->create([
            'relationship' => 'spouse',
            'is_next_of_kin' => true,
            'is_primary_next_of_kin' => true,
        ]);

        CustomerRelationship::factory()->for($customer)->create();
    }
}
