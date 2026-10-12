<?php

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MetalType
 *
 * Global metal vocabulary (Gold, Silver, …). Purities belong to a metal type.
 */
class MetalType extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Rewrite unique name on soft delete.
     */
    protected static function booted(): void
    {
        static::deleting(function (MetalType $metal): void {
            if ($metal->isForceDeleting()) {
                return;
            }

            $metal->name = $metal->name.' (deleted '.$metal->id.')';
            $metal->saveQuietly();
        });
    }

    /**
     * Purities offered for this metal.
     */
    public function purities(): HasMany
    {
        return $this->hasMany(Purity::class);
    }

    /**
     * Pieces in this metal.
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
