<?php

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GoldRate
 *
 * Global per-purity rate history row. Never updated in place — each change
 * inserts a new row. SuperAdmin managed; no branch_id.
 */
class GoldRate extends Model
{
    use HasUuid;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purity_id',
        'rate_per_gram',
        'effective_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_per_gram' => 'decimal:2',
            'effective_date' => 'date',
        ];
    }

    /**
     * Only created_by attribution — rates are insert-only.
     *
     * @return array{created_by: ?string, created_by_role: ?string, updated_by: ?string, updated_by_role: ?string}
     */
    public function attributionColumnMap(): array
    {
        return [
            'created_by' => 'created_by',
            'created_by_role' => 'created_by_role',
            'updated_by' => null,
            'updated_by_role' => null,
        ];
    }

    /**
     * Purity this rate applies to.
     */
    public function purity(): BelongsTo
    {
        return $this->belongsTo(Purity::class);
    }
}
