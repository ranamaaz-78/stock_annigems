<?php

namespace App\Models;

use App\Enums\DeliveryNoteStatus;
use App\Enums\DiscountType;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DeliveryNote (albarán)
 *
 * Goods handed to a client with prices and an optional discount but no tax and
 * no payment. Created from the POS; stock leaves at once. Immutable after
 * issue: it is cancelled or converted to an invoice, never edited.
 */
class DeliveryNote extends Model
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
        'delivery_note_number',
        'customer_id',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'customer_address_snapshot',
        'delivery_date',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'grand_total',
        'status',
        'invoice_id',
        'cancelled_by',
        'cancelled_by_role',
        'cancelled_at',
        'cancel_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'status' => DeliveryNoteStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Only created_by attribution: a note is never edited.
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
     * Client the goods went to.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Invoice this note was converted into, if any.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Lines on this note.
     */
    public function items(): HasMany
    {
        return $this->hasMany(DeliveryNoteItem::class)->orderBy('sort_order');
    }

    /**
     * Whether the goods are still out and the note can be cancelled or invoiced.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
