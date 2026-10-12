<?php

namespace App\Models;

use App\Enums\StoneMovementType;
use App\Traits\BelongsToBranch;
use App\Traits\TracksPerformedByAttribution;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * StoneStockMovement
 *
 * Append-only quantity ledger. quantity_before / quantity_after make the
 * chain self-auditing: each row's before must equal the previous after
 * for that stone. Written exclusively by StoneStockService.
 *
 * No uuid — movements are not URL-addressed.
 */
class StoneStockMovement extends Model
{
    use BelongsToBranch;
    use TracksPerformedByAttribution;
    use TracksUserAttribution {
        TracksPerformedByAttribution::attributionColumnMap insteadof TracksUserAttribution;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'stone_id',
        'type',
        'quantity_change',
        'quantity_before',
        'quantity_after',
        'weight_change',
        'weight_before',
        'weight_after',
        'reference_type',
        'reference_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StoneMovementType::class,
            'quantity_change' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'weight_change' => 'decimal:3',
            'weight_before' => 'decimal:3',
            'weight_after' => 'decimal:3',
        ];
    }

    /**
     * Whether this movement changed a gram lot's weight.
     */
    public function isWeightMovement(): bool
    {
        return $this->weight_change !== null;
    }

    /**
     * Stone whose quantity changed.
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }

    /**
     * Intake (today) or later a jewellery item / sale / adjustment.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
