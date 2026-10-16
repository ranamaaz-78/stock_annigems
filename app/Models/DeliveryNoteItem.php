<?php

namespace App\Models;

use App\Enums\LineType;
use App\Enums\MakingChargeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DeliveryNoteItem
 *
 * Immutable snapshot of one delivered line, shaped like an invoice line so it
 * can be copied onto an invoice unchanged.
 */
class DeliveryNoteItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'delivery_note_id',
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
        'weight_grams',
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
            'weight_grams' => 'decimal:3',
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
     * Parent note.
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * Delivered jewellery piece.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }

    /**
     * Delivered loose stone lot.
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }

    /**
     * Set stones priced inside this line.
     */
    public function itemStones(): HasMany
    {
        return $this->hasMany(DeliveryNoteItemStone::class);
    }
}
