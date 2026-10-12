<?php

namespace App\Http\Requests\Concerns;

use App\Enums\StonePricingMode;
use App\Enums\StoneStatus;
use Illuminate\Validation\Rule;

/**
 * ValidatesStoneLot
 *
 * Shared rules for the supplier-sheet lot fields used by stone create and
 * update. Each pricing mode only requires the figures its formula consumes,
 * so a per-gram lot is never forced to invent a carat.
 */
trait ValidatesStoneLot
{
    /**
     * Lot rules. The `required_if` chain mirrors StonePricingService: break
     * one and the service would reject the row anyway, so they stay together.
     *
     * @return array<string, mixed>
     */
    protected function lotRules(): array
    {
        $caratMax = (float) config('annigems.stones.carat_max', 50);
        $perCarat = StonePricingMode::PER_CARAT->value;
        $perPiece = StonePricingMode::PER_PIECE->value;
        $perGram = StonePricingMode::PER_GRAM->value;

        return [
            'name' => ['required', 'string', 'max:150'],
            'shape' => ['nullable', 'string', 'max:60'],
            // Free text: the sheet writes sizes as 8x6, 5x0, or 17x12.
            'dimension' => ['nullable', 'string', 'max:40'],
            'pricing_mode' => ['required', Rule::enum(StonePricingMode::class)],
            'status' => ['required', Rule::enum(StoneStatus::class)],

            'carat' => [
                'exclude_unless:pricing_mode,'.$perCarat,
                'required', 'numeric', 'gt:0', 'decimal:0,3', 'max:'.$caratMax,
            ],
            'price_per_carat' => [
                'exclude_unless:pricing_mode,'.$perCarat,
                'required', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999',
            ],

            'price_per_piece' => [
                'exclude_unless:pricing_mode,'.$perPiece,
                'required', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999',
            ],

            'weight_grams' => [
                'exclude_unless:pricing_mode,'.$perGram,
                'required', 'numeric', 'gt:0', 'decimal:0,3', 'max:9999999',
            ],
            'price_per_gram' => [
                'exclude_unless:pricing_mode,'.$perGram,
                'required', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999',
            ],

            // Gram lots are bulk weight and hold no countable pieces.
            'pieces' => [
                'exclude_if:pricing_mode,'.$perGram,
                'required', 'integer', 'min:1', 'max:'.(int) config('annigems.stones.intake_quantity_max', 10000),
            ],
            'pairs' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('annigems.stones.intake_quantity_max', 10000)],

            'tray_no' => ['nullable', 'string', 'max:30'],
            // Name of a picture in the image folder; it may not exist yet.
            'image_code' => ['nullable', 'string', 'max:180'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Human-readable messages for the lot rules.
     *
     * @return array<string, string>
     */
    protected function lotMessages(): array
    {
        return [
            'name.required' => 'Enter a stone name.',
            'pricing_mode.required' => 'Choose per carat, per piece, or per gram.',
            'status.required' => 'Choose a status.',

            'carat.required' => 'Per carat pricing needs a carat weight.',
            'carat.gt' => 'Carat must be greater than zero.',
            'carat.max' => 'Carat is unusually high. Check for a typing error (e.g. 2.00 not 200).',
            'carat.decimal' => 'Carat allows at most three decimal places.',
            'price_per_carat.required' => 'Enter the price per carat.',
            'price_per_carat.gt' => 'Price per carat must be greater than zero.',
            'price_per_carat.decimal' => 'Price per carat allows at most three decimal places.',

            'price_per_piece.required' => 'Enter the price per piece.',
            'price_per_piece.gt' => 'Price per piece must be greater than zero.',
            'price_per_piece.decimal' => 'Price per piece allows at most three decimal places.',

            'weight_grams.required' => 'Per gram pricing needs a weight in grams.',
            'weight_grams.gt' => 'Weight must be greater than zero.',
            'price_per_gram.required' => 'Enter the price per gram.',
            'price_per_gram.gt' => 'Price per gram must be greater than zero.',
            'price_per_gram.decimal' => 'Price per gram allows at most three decimal places.',
            'weight_grams.decimal' => 'Weight allows at most three decimal places.',

            'pieces.required' => 'Enter how many pieces this lot holds.',
            'pieces.min' => 'A lot must hold at least one piece.',
            'pairs.min' => 'Pairs cannot be negative.',
            'image_code.max' => 'Image code must be 180 characters or fewer.',
        ];
    }
}
