<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TaxType;
use App\Traits\BelongsToBranch;
use App\Traits\HasUuid;
use App\Traits\TracksUserAttribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Invoice
 *
 * Immutable sale snapshot. Created by converting a quotation or by issuing
 * directly from the quotation workspace (quotation_id then stays null).
 * Corrections are made by voiding, never by editing. verification_token is
 * separate from uuid.
 */
class Invoice extends Model
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
        'invoice_number',
        'verification_token',
        'quotation_id',
        'performa_id',
        'customer_id',
        'customer_name_snapshot',
        'customer_phone_snapshot',
        'invoice_date',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_type',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'payment_method',
        'payment_reference',
        'status',
        'voided_by',
        'voided_by_role',
        'voided_at',
        'void_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_type' => TaxType::class,
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => InvoiceStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Invoices are immutable — only created_by attribution.
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
     * Source quotation when converted from one.
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * Source performa when this invoice settled a credit issue.
     */
    public function performa(): BelongsTo
    {
        return $this->belongsTo(Performa::class);
    }

    /**
     * Customer at time of sale.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Line items on this invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * Completed sales only.
     *
     * A voided invoice is a reversed sale: its stock went back on the shelf and
     * its money was not kept. It must therefore never reach a revenue, count,
     * discount, tax, or weight-sold figure. Every report and every dashboard
     * card goes through this one scope so the rule cannot be applied in one
     * place and forgotten in another. Voided invoices are reported separately.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', InvoiceStatus::COMPLETED);
    }

    /**
     * Voided sales only — for the dedicated voided-invoices report.
     */
    public function scopeVoided(Builder $query): Builder
    {
        return $query->where($this->getTable().'.status', InvoiceStatus::VOIDED);
    }

    /**
     * Whether this invoice is voided.
     */
    public function isVoided(): bool
    {
        return $this->status === InvoiceStatus::VOIDED;
    }
}
