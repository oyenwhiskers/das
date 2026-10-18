<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        self::$sequence++;

        return [
            'reference' => 'DAS-'.str_pad((string) self::$sequence, 6, '0', STR_PAD_LEFT),
            'type' => CustomerType::Individual,
            'status' => CustomerStatus::Active,
            'full_legal_name' => fake()->name(),
            'nationality' => 'Malaysian',
            'primary_email' => fake()->unique()->safeEmail(),
            'primary_mobile' => '01'.fake()->numerify('########'),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerStatus::Archived,
        ]);
    }
}
