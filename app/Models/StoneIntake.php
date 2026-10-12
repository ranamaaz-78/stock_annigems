<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksPerformedByAttribution;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * StoneIntake
 *
 * Immutable batch of scanned (or typed) stone quantities received into a
 * branch. There is no edit/update path; mistakes wait for a later adjustment.
 *
 * performed_by is a role snapshot at save time — never a live lookup.
 */
class StoneIntake extends Model
{
    use BelongsToBranch;
    use HasUuid;
    use SoftDeletes;
    use TracksPerformedByAttribution;
    use TracksUserAttribution {
        TracksPerformedByAttribution::attributionColumnMap insteadof TracksUserAttribution;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'intake_number',
        'intake_date',
        'total_stone_types',
        'total_quantity',
        'total_carat',
        'total_weight_grams',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intake_date' => 'date',
            'total_stone_types' => 'integer',
            'total_quantity' => 'integer',
            'total_carat' => 'decimal:3',
            'total_weight_grams' => 'decimal:3',
        ];
    }

    /**
     * Line items in this batch.
     */
    public function items(): HasMany
    {
        return $this->hasMany(StoneIntakeItem::class);
    }
}
