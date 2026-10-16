<?php

namespace App\Models;

use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Creditor
 *
 * Branch-scoped party who takes jewellery or stones on performa (credit).
 * Not a sales customer — quotations and POS never attach this row.
 * Performas are issued against a creditor registered here first.
 */
class Creditor extends Model
{
    use BelongsToBranch;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'creditor_code',
        'name',
        'phone',
        'alternate_phone',
        'email',
        'nie',
        'address',
        'city',
        'notes',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    /**
     * Performas issued against this creditor.
     */
    public function performas(): HasMany
    {
        return $this->hasMany(Performa::class);
    }

    /**
     * Active creditors only (shown in the performa picker).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }
}
