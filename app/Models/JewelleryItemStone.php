<?php

namespace App\Models;

use App\Enums\StoneOrigin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JewelleryItemStone
 *
 * Allocation of a Module 3 catalog stone into a piece. Display fields are
 * snapshots taken at allocation time so later catalog edits never rewrite
 * what is physically set in the piece. stone_id remains for stock accounting.
 *
 * No uuid — always reached through the parent item.
 */
class JewelleryItemStone extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'jewellery_item_id',
        'stone_id',
        'quantity',
        'stone_code_snapshot',
        'stone_name_snapshot',
        'carat_snapshot',
        'stone_type_snapshot',
        'shape_snapshot',
        'color_snapshot',
        'clarity_snapshot',
        'cut_snapshot',
        'origin_snapshot',
        'total_carat',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'carat_snapshot' => 'decimal:3',
            'total_carat' => 'decimal:3',
        ];
    }

    /**
     * Parent jewellery piece.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }

    /**
     * Catalog stone (may be inactive or later edited).
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }

    /**
     * Origin label for display from the snapshot string.
     */
    public function originLabel(): string
    {
        $origin = StoneOrigin::tryFrom((string) $this->origin_snapshot);

        return $origin?->label() ?? (string) $this->origin_snapshot;
    }
}
