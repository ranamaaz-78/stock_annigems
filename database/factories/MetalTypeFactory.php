<?php

namespace Database\Factories;

use App\Models\MetalType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * MetalTypeFactory
 *
 * @extends Factory<MetalType>
 */
class MetalTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' metal',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
