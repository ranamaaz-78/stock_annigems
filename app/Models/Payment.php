<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Payment
 *
 * Money received against an invoice or a delivery note. A document can carry
 * more than one: a delivery note paid before it was invoiced contributes its
 * payment to the invoice, and the difference is paid on top.
 */
class Payment extends Model
{
    use BelongsToBranch;
    use HasUuid;
    use TracksUserAttribution;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'payable_type',
        'payable_id',
        'amount',
        'payment_method',
        'payment_reference',
        'paid_on',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'payment_method' => PaymentMethod::class,
        ];
    }

    /**
     * Only creation is attributed: a payment is never edited.
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
     * The invoice or delivery note this was paid against.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
