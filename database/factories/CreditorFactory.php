<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Creditor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * CreditorFactory
 *
 * Test helper. The creditor code is set explicitly so tests do not depend
 * on CodeSequenceService unless they are exercising that path. Phone is
 * unique per factory run because (branch_id, phone) is unique.
 *
 * @extends Factory<Creditor>
 */
class CreditorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'creditor_code' => 'CRD-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
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
