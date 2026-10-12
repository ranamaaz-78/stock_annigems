<?php

namespace App\Models;

use App\Enums\LineType;
use App\Enums\MakingChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InvoiceItem
 *
 * Immutable snapshot of one sale line, copied from a quotation at convert
 * or from the workspace when issuing an invoice directly.
 */
class InvoiceItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
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
        'quantity',
        'gold_rate_applied',
        'gold_value',
        'making_charge_type',
        'making_charge_value',
        'making_charges',
        'stone_value',
        'line_total',
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
            'quantity' => 'integer',
            'gold_rate_applied' => 'decimal:2',
            'gold_value' => 'decimal:2',
            'making_charge_type' => MakingChargeType::class,
            'making_charge_value' => 'decimal:2',
            'making_charges' => 'decimal:2',
            'stone_value' => 'decimal:2',
            'line_total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Parent invoice.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Sold jewellery item reference.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }

    /**
     * Sold loose stone reference.
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }

    /**
     * Stone sub-rows for jewellery lines.
     */
    public function itemStones(): HasMany
    {
        return $this->hasMany(InvoiceItemStone::class);
    }
}
