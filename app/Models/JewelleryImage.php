<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JewelleryImage
 *
 * Private-disk photo of a piece. Served through gated routes, never as a
 * public storage URL. No uuid — reached through the parent item.
 */
class JewelleryImage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'jewellery_item_id',
        'path',
        'is_primary',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Parent piece.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }
}
