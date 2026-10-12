<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StoneIntakeItem
 *
 * One stone type inside an intake batch. Snapshots name and carat so later
 * edits to the stone master cannot rewrite history. No uuid — reached only
 * through the parent intake.
 */
class StoneIntakeItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'stone_intake_id',
        'stone_id',
        'quantity',
        'weight_grams',
        'carat_snapshot',
        'name_snapshot',
        'scanned_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'weight_grams' => 'decimal:3',
            'carat_snapshot' => 'decimal:3',
            'scanned_count' => 'integer',
        ];
    }

    /**
     * Parent intake batch.
     */
    public function intake(): BelongsTo
    {
        return $this->belongsTo(StoneIntake::class, 'stone_intake_id');
    }

    /**
     * Registered stone type this line increased.
     */
    public function stone(): BelongsTo
    {
        return $this->belongsTo(Stone::class);
    }
}
