{{--
    Right-aligned money stack for a document sheet.

    Discount and tax rows are hidden when zero — the approved design keeps the
    block short rather than printing a column of dashes.
--}}
@props([
    'document',                        // Quotation or Invoice
    'grandLabel' => 'Grand Total',     // Quotations call this an estimate
])

@php
    /**
     * Trim trailing zeros so a 5% discount reads "5%" and not "5.00%".
     */
    $trim = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');

    $discountLabel = 'Discount';
    if ($document->discount_type === \App\Enums\DiscountType::PERCENTAGE && (float) $document->discount_value > 0) {
        $discountLabel .= ' ('.$trim($document->discount_value).'%)';
    }

    $taxName = (string) setting('tax_label', 'GST');
    $taxLabel = (float) $document->tax_rate > 0
        ? $taxName.' ('.$trim($document->tax_rate).'%)'
        : $taxName;
@endphp

<div class="sheet-totals">
    <div class="sheet-totals__row">
        <span class="sheet-totals__label">Subtotal</span>
        <span class="sheet-totals__value">{{ setting_amount($document->subtotal) }}</span>
    </div>

    @if((float) $document->discount_amount > 0)
        <div class="sheet-totals__row">
            <span class="sheet-totals__label">{{ $discountLabel }}</span>
            <span class="sheet-totals__value">−{{ setting_amount($document->discount_amount) }}</span>
        </div>
    @endif

    @if((float) $document->tax_amount > 0)
        <div class="sheet-totals__row">
            <span class="sheet-totals__label">{{ $taxLabel }}</span>
            <span class="sheet-totals__value">{{ setting_amount($document->tax_amount) }}</span>
        </div>
    @endif

    <div class="sheet-totals__grand">
        <span class="sheet-totals__grand-label">{{ $grandLabel }}</span>
        <span class="sheet-totals__grand-value">{{ setting_money($document->grand_total) }}</span>
    </div>
</div>
