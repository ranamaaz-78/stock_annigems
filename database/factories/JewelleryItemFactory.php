<?php

namespace Database\Factories;

use App\Enums\JewelleryItemStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\JewelleryItem;
use App\Models\MetalType;
use App\Models\Purity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * JewelleryItemFactory
 *
 * Test helper. Codes and tokens are set explicitly so tests that do not
 * go through JewelleryItemService still persist unique identifiers.
 *
 * @extends Factory<JewelleryItem>
 */
class JewelleryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'JWL-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'branch_id' => Branch::factory(),
            'item_code' => $code,
            'barcode' => $code,
            'qr_token' => Str::random(48),
            'category_id' => Category::factory(),
            'sub_category_id' => null,
            'name' => 'Test ring',
            'metal_type_id' => MetalType::factory(),
            'purity_id' => Purity::factory(),
            'gross_weight' => '5.000',
            'stone_weight_carat' => '0.000',
            'stone_weight_grams' => '0.000',
            'net_weight' => '5.000',
            'description' => null,
            'status' => JewelleryItemStatus::AVAILABLE,
        ];
    }
}
