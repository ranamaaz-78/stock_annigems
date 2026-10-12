<?php

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purity
 *
 * Fineness for a metal type (22K → 0.9166). The value column is stored for
 * the future Quotation module and is not used in jewellery CRUD.
 */
class Purity extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'metal_type_id',
        'name',
        'value',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Rewrite unique (metal, name) on soft delete.
     */
    protected static function booted(): void
    {
        static::deleting(function (Purity $purity): void {
            if ($purity->isForceDeleting()) {
                return;
            }

            $purity->name = $purity->name.' (deleted '.$purity->id.')';
            $purity->saveQuietly();
        });
    }

    /**
     * Parent metal.
     */
    public function metalType(): BelongsTo
    {
        return $this->belongsTo(MetalType::class);
    }

    /**
     * Pieces using this purity.
     */
    public function jewelleryItems(): HasMany
    {
        return $this->hasMany(JewelleryItem::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
