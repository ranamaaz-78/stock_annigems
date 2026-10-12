<?php

namespace Database\Factories;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use App\Models\Branch;
use App\Models\Stone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * StoneFactory
 *
 * Test helper for one supplier lot. Codes and barcodes are set explicitly so
 * tests do not depend on CodeSequenceService or the barcode generator unless
 * they exercise those paths. Default state is a per-piece lot.
 *
 * @extends Factory<Stone>
 */
class StoneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'STN-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'branch_id' => Branch::factory(),
            'stone_code' => $code,
            'barcode' => (string) fake()->unique()->numberBetween(1000000000, 99999999999),
            'name' => 'Test stone lot',
            'shape' => 'OVAL',
            'dimension' => '8x6',
            'carat' => null,
            'pairs' => 0,
            'weight_grams' => null,
            'pricing_mode' => StonePricingMode::PER_PIECE,
            'price_per_carat' => null,
            'price_per_piece' => '9.0000',
            'price_per_gram' => null,
            'piece_price' => '9.0000',
            'total_cost' => '90.0000',
            'cost_formula' => '10 pcs x 9 = 90',
            'status' => StoneStatus::IN_STOCK,
            'tray_no' => 'K1',
            'image_code' => null,
            'remarks' => null,
            'is_active' => true,
        ];
    }

    /**
     * A per-carat lot: carat and a carat rate, no piece rate.
     */
    public function perCarat(string $carat = '2.100', string $rate = '2.0000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_mode' => StonePricingMode::PER_CARAT,
            'carat' => $carat,
            'price_per_carat' => $rate,
            'price_per_piece' => null,
            'piece_price' => number_format((float) $carat * (float) $rate, 4, '.', ''),
        ]);
    }

    /**
     * A bulk gram lot: weight and a gram rate, no pieces.
     */
    public function perGram(string $grams = '150.000', string $rate = '2.5000'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pricing_mode' => StonePricingMode::PER_GRAM,
            'carat' => null,
            'weight_grams' => $grams,
            'price_per_piece' => null,
            'price_per_gram' => $rate,
            'piece_price' => null,
            'total_cost' => number_format((float) $grams * (float) $rate, 4, '.', ''),
        ]);
    }
}
