<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItemStone
 *
 * Immutable snapshot of per-stone pricing copied from quotation at conversion.
 */
class InvoiceItemStone extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_item_id',
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
     * Parent invoice line.
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }
}
