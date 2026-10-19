<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CustomerFactory
 *
 * Test helper. The customer code is set explicitly so tests do not depend on
 * CodeSequenceService unless they are exercising that path, and the phone is
 * unique because phone uniqueness is enforced system-wide.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_code' => 'CUS-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'name' => fake()->name(),
            'phone' => '03'.fake()->unique()->numerify('#########'),
            'alternate_phone' => null,
            'email' => null,
            'address' => null,
            'city' => null,
            'notes' => null,
            'status' => true,
        ];
    }
}
