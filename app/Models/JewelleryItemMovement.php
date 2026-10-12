<?php

namespace App\Models;

use App\Enums\JewelleryItemMovementType;
use App\Enums\JewelleryItemStatus;
use App\Traits\BelongsToBranch;
use App\Traits\TracksPerformedByAttribution;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * JewelleryItemMovement
 *
 * Append-only timeline of status and material changes. Actor + role are
 * snapshotted at the moment of the action (performed_by / performed_by_role).
 * No uuid — listed on the parent item detail page.
 */
class JewelleryItemMovement extends Model
{
    use BelongsToBranch;
    use TracksPerformedByAttribution;
    use TracksUserAttribution {
        TracksPerformedByAttribution::attributionColumnMap insteadof TracksUserAttribution;
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'jewellery_item_id',
        'type',
        'from_status',
        'to_status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => JewelleryItemMovementType::class,
            'from_status' => JewelleryItemStatus::class,
            'to_status' => JewelleryItemStatus::class,
        ];
    }

    /**
     * Piece this movement belongs to.
     */
    public function jewelleryItem(): BelongsTo
    {
        return $this->belongsTo(JewelleryItem::class);
    }

    /**
     * Actor at the time of the action (includes deactivated users).
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by')->withTrashed();
    }
}
