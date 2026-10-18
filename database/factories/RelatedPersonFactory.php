<?php

namespace Database\Factories;

use App\Models\RelatedPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedPerson>
 */
class RelatedPersonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_legal_name' => fake()->name(),
            'mobile' => '01'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
        ];
    }
}
