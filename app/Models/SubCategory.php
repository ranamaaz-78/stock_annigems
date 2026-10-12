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
use Illuminate\Support\Str;

/**
 * SubCategory
 *
 * Global child of a Category. Unique name/slug only within the parent.
 */
class SubCategory extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
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
     * Fill slug and rewrite uniqueness on soft delete.
     */
    protected static function booted(): void
    {
        static::creating(function (SubCategory $sub): void {
            if ($sub->slug === null || $sub->slug === '') {
                $sub->slug = Str::slug($sub->name);
            }
        });

        static::deleting(function (SubCategory $sub): void {
            if ($sub->isForceDeleting()) {
                return;
            }

            $sub->slug = $sub->slug.'-deleted-'.$sub->id;
            $sub->name = $sub->name.' (deleted '.$sub->id.')';
            $sub->saveQuietly();
        });
    }

    /**
     * Parent category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Pieces using this sub-category.
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
