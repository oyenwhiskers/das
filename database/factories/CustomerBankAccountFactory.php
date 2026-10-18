<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerBankAccount>
 */
class CustomerBankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->numerify('##########');

        return [
            'customer_id' => Customer::factory(),
            'bank_name' => 'Maybank',
            'bank_country' => 'Malaysia',
            'branch_address' => null,
            'account_type' => 'savings',
            'account_number' => $number,
            'account_holder_name' => fake()->name(),
            'ownership' => 'sole',
            'is_primary' => false,
            'is_active' => true,
            'primary_active_key' => null,
            'identity_hash' => CustomerBankAccount::identityHash('Maybank', 'Malaysia', $number),
        ];
    }
}
