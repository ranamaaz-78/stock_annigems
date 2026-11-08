<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\LineType;
use App\Enums\PerformaStatus;
use App\Services\PricingCalculationService;
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
        'customer_id',
        'performa_date',
        'status',
        'discount_type',
        'discount_value',
        'tax_type',
        'tax_rate',
        'tax_name',
        'recargo_rate',
        'recargo_name',
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
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'recargo_rate' => 'decimal:3',
        ];
    }

    /**
     * Customer who took the goods on credit. Customers are global, so this
     * is never branch-scoped; trashed customers still show on old performas.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
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
     * Display name from the attached customer.
     */
    public function displayCustomerName(): string
    {
        return $this->customer?->name ?? __('Customer');
    }

    /**
     * Whether a discount, IVA or recargo was chosen when this was issued.
     */
    public function hasTerms(): bool
    {
        return ($this->discount_type !== null && $this->discount_type !== DiscountType::NONE)
            || ($this->tax_type !== null && $this->tax_type !== 'none')
            || (float) $this->recargo_rate > 0;
    }

    /**
     * Subtotal, discount, IVA, recargo and total of everything issued, worked
     * out from the lines with the same calculator invoices use: tax comes
     * after discount.
     *
     * @return array<string, mixed>
     */
    public function documentTotals(): array
    {
        $subtotal = (float) $this->items->sum(
            fn (PerformaItem $item): float => $item->valueOf($item->isWeighed() ? $item->weight_issued : $item->quantity_issued)
        );

        return app(PricingCalculationService::class)->calculateDocument([['line_total' => $subtotal]], [
            'discount_type' => ($this->discount_type ?? DiscountType::NONE)->value,
            'discount_value' => $this->discount_value ?? 0,
            'tax_type' => $this->tax_type ?? 'none',
            'tax_rate' => $this->tax_rate ?? 0,
            'tax_name' => $this->tax_name,
            'recargo_rate' => $this->recargo_rate ?? 0,
            'recargo_name' => $this->recargo_name,
        ]);
    }

    /**
     * What a line's worth becomes once the performa's discount and tax are
     * spread over it: total divided by subtotal. 1 when nothing is chosen.
     */
    public function termsFactor(): float
    {
        $totals = $this->documentTotals();
        $subtotal = (float) $totals['subtotal'];

        return $subtotal > 0 ? (float) $totals['grand_total'] / $subtotal : 1.0;
    }

    /**
     * Whether unpaid goods can still be settled or returned.
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Unpaid jewellery pieces and loose-stone qty still with the customer.
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
