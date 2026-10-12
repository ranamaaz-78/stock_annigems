<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PerformaSettlement
 *
 * One payment against a performa. Always creates a completed invoice; stock
 * conversion (on-memo → sold) happens in PerformaSettlementService so the
 * regular invoice deduct path is never run twice.
 */
class PerformaSettlement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'performa_id',
        'invoice_id',
        'payment_method',
        'payment_reference',
        'settled_at',
        'created_by',
        'created_by_role',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'settled_at' => 'datetime',
        ];
    }

    /**
     * Parent performa.
     */
    public function performa(): BelongsTo
    {
        return $this->belongsTo(Performa::class);
    }

    /**
     * Completed invoice written for this payment.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Staff who took the payment.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
