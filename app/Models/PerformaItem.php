<?php

namespace App\Models;

use App\Enums\LineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PerformaItem
 *
 * One issued jewellery piece or loose-stone quantity. Prices are not stored
 * here — they are captured on the settlement invoice. Outstanding is
 * issued − settled − returned and must never go negative.
 */
class PerformaItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'performa_id',
        'line_type',
        'jewellery_item_id',
        'stone_id',
        'item_code_snapshot',
        'name_snapshot',
        'category_snapshot',
        'metal_snapshot',
        'purity_snapshot',
        'gross_weight_snapshot',
        'net_weight_snapshot',
        'quantity_issued',
        'quantity_settled',
        'quantity_returned',
        'weight_issued',
        'weight_settled',
        'weight_returned',
        'unit_price',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_type' => LineType::class,
            'gross_weight_snapshot' => 'decimal:3',
            'net_weight_snapshot' => 'decimal:3',
            'quantity_issued' => 'integer',
            'quantity_settled' => 'integer',
            'quantity_returned' => 'integer',
            'weight_issued' => 'decimal:3',
            'weight_settled' => 'decimal:3',
            'weight_returned' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Parent performa.
     */
    public function performa(): BelongsTo
    {
        return $this->belongsTo(Performa::class);
    }

    /**
     * Source jewellery item when line_type is jewellery_item.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }

    /**
     * Source stone when line_type is loose_stone.
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }

    /**
     * Whether this line is a per-gram stone, counted in grams not pieces.
     * Its unit_price is then the price of one gram.
     */
    public function isWeighed(): bool
    {
        return (float) $this->weight_issued > 0;
    }

    /**
     * Worth of an amount (pieces, or grams on a weighed line) at the price
     * fixed when the performa was issued. Zero when the line was never priced.
     */
    public function valueOf(int|float $amount): float
    {
        return round((float) $this->unit_price * max(0, (float) $amount), 2);
    }

    /**
     * Grams still with the creditor and unpaid (weighed lines).
     */
    public function outstandingWeight(): float
    {
        return max(0.0, round((float) $this->weight_issued - (float) $this->weight_settled - (float) $this->weight_returned, 3));
    }

    /**
     * What is still out, in the line's own unit: grams on a weighed line,
     * pieces otherwise. Use this where the unit does not matter.
     */
    public function outstandingAmount(): int|float
    {
        return $this->isWeighed() ? $this->outstandingWeight() : $this->outstandingQuantity();
    }

    /**
     * What has been paid for, in the line's own unit.
     */
    public function settledAmount(): int|float
    {
        return $this->isWeighed() ? (float) $this->weight_settled : (int) $this->quantity_settled;
    }

    /**
     * An amount worded in the line's own unit: "3" for pieces, "5.5 g" for a
     * weighed line.
     */
    public function amountLabel(int|float $amount): string
    {
        if ($this->isWeighed()) {
            return rtrim(rtrim(number_format((float) $amount, 3, '.', ''), '0'), '.').' g';
        }

        return (string) (int) $amount;
    }

    /**
     * Qty still with the customer and unpaid. A weighed line counts as one
     * piece while any grams remain, so "is anything left" checks keep working.
     */
    public function outstandingQuantity(): int
    {
        if ($this->isWeighed()) {
            return $this->outstandingWeight() > 0 ? 1 : 0;
        }

        return max(0, $this->quantity_issued - $this->quantity_settled - $this->quantity_returned);
    }
}
