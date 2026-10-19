<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerEmployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerEmployment>
 */
class CustomerEmploymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'arrangement' => 'employed',
            'occupation' => fake()->jobTitle(),
            'employer_name' => fake()->company(),
            'is_current' => true,
            'is_primary' => false,
        ];
    }

    public function previous(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_current' => false,
            'is_primary' => false,
            'end_date' => now()->subMonth()->toDateString(),
        ]);
    }

    public function selfEmployed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'arrangement' => 'self_employed',
            'employer_name' => null,
        ]);
    }
}
