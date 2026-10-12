<?php

namespace Database\Factories;

use App\Models\MetalType;
use App\Models\Purity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * PurityFactory
 *
 * @extends Factory<Purity>
 */
class PurityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metal_type_id' => MetalType::factory(),
            'name' => fake()->unique()->numerify('##K'),
            'value' => '0.7500',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
