<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerEmployment;
use Illuminate\Database\Seeder;

class CustomerEmploymentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $customer = Customer::factory()->create(['employment_status' => 'employed']);

        CustomerEmployment::factory()->for($customer)->create([
            'occupation' => 'Software Engineer',
            'employer_name' => 'ABC Technology Sdn Bhd',
            'start_date' => '2025-01-01',
            'is_primary' => true,
            'primary_current_key' => $customer->id,
        ]);

        CustomerEmployment::factory()->previous()->for($customer)->create([
            'occupation' => 'Junior Developer',
            'employer_name' => 'XYZ Solutions Sdn Bhd',
            'start_date' => '2023-01-01',
            'end_date' => '2024-12-31',
        ]);
    }
}
