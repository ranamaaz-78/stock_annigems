<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\MakingChargeType;
use App\Enums\QuotationStatus;
use App\Enums\TaxType;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quotation
 *
 * Branch-scoped priced document. May reference a customer or capture walk-in
 * details. Prices are recalculated on save; no version history.
 */
class Quotation extends Model
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
        'quotation_number',
        'customer_id',
        'walkin_name',
        'walkin_phone',
        'quotation_date',
        'default_gold_rate',
        'default_stone_price',
        'default_making_charge_type',
        'default_making_charge_value',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_type',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'status',
        'notes',
        'converted_invoice_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'default_gold_rate' => 'decimal:2',
            'default_stone_price' => 'decimal:2',
            'default_making_charge_value' => 'decimal:2',
            'default_making_charge_type' => MakingChargeType::class,
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_type' => TaxType::class,
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'status' => QuotationStatus::class,
        ];
    }

    /**
     * Linked customer when not a walk-in.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Line items on this quotation.
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    /**
     * Invoice created from this quotation, if converted.
     */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    /**
     * Display name: customer record or walk-in name.
     */
    public function displayCustomerName(): string
    {
        if ($this->customer_id && $this->relationLoaded('customer') && $this->customer) {
            return $this->customer->name;
        }

        return (string) ($this->walkin_name ?: 'Walk-in');
    }

    /**
     * Whether this quotation can still be edited.
     */
    public function isEditable(): bool
    {
        return $this->status === QuotationStatus::DRAFT;
    }
}
