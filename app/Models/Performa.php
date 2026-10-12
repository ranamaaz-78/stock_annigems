<?php

namespace App\Models;

use App\Enums\LineType;
use App\Enums\PerformaStatus;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Performa
 *
 * Branch-scoped credit-issue ledger. Goods leave stock on create; prices
 * appear only when a settlement invoice is written. Outstanding qty lives
 * on the lines, not on this header.
 */
class Performa extends Model
{
    use BelongsToBranch;
    use HasUuid;
    use SoftDeletes;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'performa_number',
        'creditor_id',
        'performa_date',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performa_date' => 'date',
            'status' => PerformaStatus::class,
        ];
    }

    /**
     * Creditor who took the goods. Always a registered creditor row.
     */
    public function creditor(): BelongsTo
    {
        return $this->belongsTo(Creditor::class);
    }

    /**
     * Issued lines on this ledger.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PerformaItem::class)->orderBy('sort_order');
    }

    /**
     * Payments that converted outstanding qty into invoices.
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(PerformaSettlement::class)->orderByDesc('settled_at');
    }

    /**
     * Display name from the attached creditor.
     */
    public function displayCreditorName(): string
    {
        if ($this->relationLoaded('creditor') && $this->creditor) {
            return $this->creditor->name;
        }

        return 'Creditor';
    }

    /**
     * Whether unpaid goods can still be settled or returned.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Unpaid jewellery pieces and loose-stone qty still with the creditor.
     *
     * @return array{jewellery: int, stones: int}
     */
    public function outstandingSummary(): array
    {
        $jewellery = 0;
        $stones = 0;

        foreach ($this->items as $item) {
            $qty = $item->outstandingQuantity();
            if ($item->line_type === LineType::JEWELLERY_ITEM) {
                $jewellery += $qty;
            } else {
                $stones += $qty;
            }
        }

        return ['jewellery' => $jewellery, 'stones' => $stones];
    }
}
