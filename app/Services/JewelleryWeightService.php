<?php

namespace App\Services;

/**
 * JewelleryWeightService
 *
 * Single place for carat-to-gram conversion and jewellery stone totals.
 * Metal (sale/net) weight is typed by staff — this service never derives it.
 * The browser may preview stone totals; the server always recalculates before save.
 */
class JewelleryWeightService
{
    /**
     * 1 metric carat = 0.2 grams. Never duplicate this constant.
     */
    public const GRAMS_PER_CARAT = 0.2;

    /**
     * Convert carat mass to grams.
     */
    public function caratToGrams(float $carat): float
    {
        return round($carat * self::GRAMS_PER_CARAT, 3);
    }

    /**
     * Sum allocated stone carats and convert to grams.
     *
     * A per-gram lot adds its weighed grams straight to the gram total; it
     * has no carat, so it adds nothing to the carat total.
     *
     * @param  list<array{carat: float, quantity: int, grams?: float}>  $allocations
     * @return array{stone_weight_carat: string, stone_weight_grams: string}
     */
    public function derive(array $allocations): array
    {
        $carat = 0.0;
        $weighed = 0.0;
        foreach ($allocations as $row) {
            $carat += ((float) $row['carat']) * (int) $row['quantity'];
            $weighed += (float) ($row['grams'] ?? 0);
        }

        $carat = round($carat, 3);
        $grams = round($this->caratToGrams($carat) + $weighed, 3);

        return [
            'stone_weight_carat' => number_format($carat, 3, '.', ''),
            'stone_weight_grams' => number_format($grams, 3, '.', ''),
        ];
    }
}
