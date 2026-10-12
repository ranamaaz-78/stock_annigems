<?php

namespace App\Models;

use App\Enums\JewelleryItemStatus;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * JewelleryItem
 *
 * One physical piece. There is never a quantity column — five similar rings
 * are five rows. Gross and metal (stored as net_weight) are typed by staff;
 * stone totals are derived from allocated packets.
 *
 * Stone composition for display lives on jewellery_item_stones snapshots,
 * not a live join to stones.
 */
class JewelleryItem extends Model
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
        'item_code',
        'barcode',
        'qr_token',
        'category_id',
        'sub_category_id',
        'name',
        'metal_type_id',
        'purity_id',
        'gross_weight',
        'stone_weight_carat',
        'stone_weight_grams',
        'net_weight',
        'description',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross_weight' => 'decimal:3',
            'stone_weight_carat' => 'decimal:3',
            'stone_weight_grams' => 'decimal:3',
            'net_weight' => 'decimal:3',
            'status' => JewelleryItemStatus::class,
        ];
    }

    /**
     * Category (global).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Optional sub-category.
     */
    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(SubCategory::class);
    }

    /**
     * Metal type.
     */
    public function metalType(): BelongsTo
    {
        return $this->belongsTo(MetalType::class);
    }

    /**
     * Purity (must belong to the metal type).
     */
    public function purity(): BelongsTo
    {
        return $this->belongsTo(Purity::class);
    }

    /**
     * Allocated stones with snapshots.
     */
    public function itemStones(): HasMany
    {
        return $this->hasMany(JewelleryItemStone::class);
    }

    /**
     * Gallery images, primary first.
     */
    public function images(): HasMany
    {
        return $this->hasMany(JewelleryImage::class)->orderByDesc('is_primary')->orderBy('sort_order');
    }

    /**
     * Primary image when one is marked.
     */
    public function primaryImage(): HasMany
    {
        return $this->hasMany(JewelleryImage::class)->where('is_primary', true);
    }

    /**
     * Status / material change timeline.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(JewelleryItemMovement::class)->orderByDesc('id');
    }
}
