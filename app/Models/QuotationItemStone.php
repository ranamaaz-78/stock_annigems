<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * QuotationItemStone
 *
 * Per-stone pricing sub-row on a jewellery quotation line. Pre-populated from
 * jewellery_item_stones with prices left at zero for the user to fill in.
 */
class QuotationItemStone extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'quotation_item_id',
        'jewellery_item_stone_id',
        'stone_name_snapshot',
        'stone_type_snapshot',
        'carat_snapshot',
        'shape_snapshot',
        'color_snapshot',
        'clarity_snapshot',
        'quantity',
        'price_per_piece',
        'total_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'carat_snapshot' => 'decimal:3',
            'quantity' => 'integer',
            'price_per_piece' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }

    /**
     * Parent quotation line.
     */
    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    /**
     * Module 4 allocation record link.
     */
    public function jewelleryItemStone(): BelongsTo
    {
        return $this->belongsTo(JewelleryItemStone::class);
    }
}
