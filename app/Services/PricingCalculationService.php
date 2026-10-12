<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Enums\MakingChargeType;
use App\Enums\TaxType;

/**
 * PricingCalculationService
 *
 * Single authoritative source for quotation and invoice math. Used by save,
 * print views, and conversion — browser values are never trusted.
 *
 * Tax is applied AFTER discount. Do not reverse this order.
 */
class PricingCalculationService
{
    /**
     * Calculate one line from raw input arrays (pre-persist or from request).
     *
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $stones
     * @return array<string, mixed>
     */
    public function calculateLine(array $line, array $stones = []): array
    {
        $lineType = LineType::from((string) ($line['line_type'] ?? LineType::JEWELLERY_ITEM->value));

        if ($lineType === LineType::LOOSE_STONE) {
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $pricePerPiece = $this->money($line['price_per_piece'] ?? $line['stone_unit_price'] ?? 0);
            $stoneValue = round($pricePerPiece * $quantity, 2);

            return array_merge($line, [
                'quantity' => $quantity,
                'gold_rate_applied' => '0.00',
                'gold_value' => '0.00',
                'making_charge_type' => MakingChargeType::NONE->value,
                'making_charge_value' => '0.00',
                'making_charges' => '0.00',
                'stone_value' => $this->formatMoney($stoneValue),
                'line_total' => $this->formatMoney($stoneValue),
            ]);
        }

        $netWeight = (float) ($line['net_weight_snapshot'] ?? 0);
        $goldRate = $this->money($line['gold_rate_applied'] ?? 0);
        $goldValue = round($netWeight * $goldRate, 2);

        $makingType = MakingChargeType::from((string) ($line['making_charge_type'] ?? MakingChargeType::NONE->value));
        $makingValue = $this->money($line['making_charge_value'] ?? 0);
        $makingCharges = match ($makingType) {
            MakingChargeType::FIXED => round($makingValue, 2),
            MakingChargeType::PER_GRAM => round($makingValue * $netWeight, 2),
            default => 0.0,
        };

        $stoneValue = 0.0;
        $calculatedStones = [];

        foreach ($stones as $stone) {
            $qty = max(0, (int) ($stone['quantity'] ?? 1));
            $price = $this->money($stone['price_per_piece'] ?? 0);
            $total = round($price * $qty, 2);
            $stoneValue += $total;
            $calculatedStones[] = array_merge($stone, [
                'quantity' => $qty,
                'price_per_piece' => $this->formatMoney($price),
                'total_price' => $this->formatMoney($total),
            ]);
        }

        $lineTotal = round($goldValue + $makingCharges + $stoneValue, 2);

        return array_merge($line, [
            'gold_rate_applied' => $this->formatMoney($goldRate),
            'gold_value' => $this->formatMoney($goldValue),
            'making_charge_type' => $makingType->value,
            'making_charge_value' => $this->formatMoney($makingValue),
            'making_charges' => $this->formatMoney($makingCharges),
            'stone_value' => $this->formatMoney($stoneValue),
            'line_total' => $this->formatMoney($lineTotal),
            'stones' => $calculatedStones,
        ]);
    }

    /**
     * Calculate document totals from calculated lines.
     *
     * Tax applies after discount — order is fixed here.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function calculateDocument(array $lines, array $document): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $this->money($line['line_total'] ?? 0);
        }
        $subtotal = round($subtotal, 2);

        $discountType = DiscountType::from((string) ($document['discount_type'] ?? DiscountType::NONE->value));
        $discountValue = $this->money($document['discount_value'] ?? 0);
        $discountAmount = match ($discountType) {
            DiscountType::FIXED => min($discountValue, $subtotal),
            DiscountType::PERCENTAGE => round($subtotal * min(100, $discountValue) / 100, 2),
            default => 0.0,
        };

        $afterDiscount = round(max(0, $subtotal - $discountAmount), 2);

        $taxType = TaxType::from((string) ($document['tax_type'] ?? TaxType::NONE->value));
        $taxRate = $this->money($document['tax_rate'] ?? 0);
        // Tax after discount — never before.
        $taxAmount = match ($taxType) {
            TaxType::FIXED => round($taxRate, 2),
            TaxType::PERCENTAGE => round($afterDiscount * min(100, $taxRate) / 100, 2),
            default => 0.0,
        };

        // Recargo de equivalencia is charged on the same discounted base as IVA.
        $recargoRate = $this->money($document['recargo_rate'] ?? 0);
        $recargoAmount = round($afterDiscount * min(100, $recargoRate) / 100, 2);

        $grandTotal = round($afterDiscount + $taxAmount + $recargoAmount, 2);

        return [
            'subtotal' => $this->formatMoney($subtotal),
            'discount_type' => $discountType->value,
            'discount_value' => $this->formatMoney($discountValue),
            'discount_amount' => $this->formatMoney($discountAmount),
            'tax_type' => $taxType->value,
            'tax_rate' => $this->formatMoney($taxRate),
            'tax_amount' => $this->formatMoney($taxAmount),
            'tax_name' => $document['tax_name'] ?? null,
            'recargo_rate' => number_format($recargoRate, 3, '.', ''),
            'recargo_amount' => $this->formatMoney($recargoAmount),
            'recargo_name' => $document['recargo_name'] ?? null,
            'grand_total' => $this->formatMoney($grandTotal),
        ];
    }

    /**
     * Full recalculation: lines + document in one call.
     *
     * @param  list<array<string, mixed>>  $lines  Each may include nested `stones`
     * @param  array<string, mixed>  $document
     * @return array{lines: list<array<string, mixed>>, document: array<string, mixed>}
     */
    public function recalculate(array $lines, array $document): array
    {
        $calculatedLines = [];

        foreach ($lines as $line) {
            $stones = $line['stones'] ?? [];
            unset($line['stones']);
            $calculated = $this->calculateLine($line, $stones);
            $calculatedLines[] = $calculated;
        }

        return [
            'lines' => $calculatedLines,
            'document' => $this->calculateDocument($calculatedLines, $document),
        ];
    }

    /**
     * Parse monetary input safely.
     */
    public function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    /**
     * Format money to two decimal places as string for storage consistency.
     */
    public function formatMoney(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
