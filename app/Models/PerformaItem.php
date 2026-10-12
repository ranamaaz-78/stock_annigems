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
     * Qty still with the customer and unpaid.
     */
    public function outstandingQuantity(): int
    {
        return max(0, $this->quantity_issued - $this->quantity_settled - $this->quantity_returned);
    }
}
