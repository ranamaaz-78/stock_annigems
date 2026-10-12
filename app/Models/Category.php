<?php

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Category
 *
 * Global jewellery category (e.g. Gold Rings). Shared by every branch.
 * Deactivating hides it from new item forms; existing items keep the FK.
 */
class Category extends Model
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
     * Rewrite unique name/slug on soft delete so the same label can be reused.
     */
    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            if ($category->slug === null || $category->slug === '') {
                $category->slug = Str::slug($category->name);
            }
        });

        static::deleting(function (Category $category): void {
            if ($category->isForceDeleting()) {
                return;
            }

            $category->slug = $category->slug.'-deleted-'.$category->id;
            $category->name = $category->name.' (deleted '.$category->id.')';
            $category->saveQuietly();
        });
    }

    /**
     * Child sub-categories.
     */
    public function subCategories(): HasMany
    {
        return $this->hasMany(SubCategory::class);
    }

    /**
     * Pieces in this category (any branch).
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
