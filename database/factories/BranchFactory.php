<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * BranchFactory
 *
 * Test helper for branches. Attribution columns are filled by
 * TracksUserAttribution, not by this factory.
 *
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Boutique',
            'code' => strtoupper(fake()->unique()->bothify('??-##')),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'contact_number' => '+92 300 1234567',
            'email' => fake()->unique()->companyEmail(),
            'status' => BranchStatus::ACTIVE,
        ];
    }

    /**
     * Inactive branch.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BranchStatus::INACTIVE,
        ]);
    }
}
