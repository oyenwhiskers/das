<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerRelationship;
use App\Models\RelatedPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerRelationship>
 */
class CustomerRelationshipFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'related_person_id' => RelatedPerson::factory(),
            'relationship' => 'father',
            'is_next_of_kin' => false,
            'is_primary_next_of_kin' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CustomerRelationship $relationship): void {
            $identity = $relationship->related_customer_id
                ? "customer:{$relationship->related_customer_id}"
                : "person:{$relationship->related_person_id}";
            $relationship->update(['active_identity_key' => "{$relationship->customer_id}:{$identity}"]);
        });
    }

    public function linkedToCustomer(Customer $relatedCustomer): static
    {
        return $this->state(fn (): array => [
            'related_person_id' => null,
            'related_customer_id' => $relatedCustomer->id,
        ]);
    }
}
