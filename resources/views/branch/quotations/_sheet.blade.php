{{--
    Quotation document sheet. Design: "Printable designs" → Quotation (Presupuesto).

    A quote is never mistaken for a bill: it reads "Total with tax", carries the
    validity date and the line "not a tax invoice", and has no verification QR.

    Expects: $quotation and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;

    $validityDays = (int) config('annigems.documents.quotation_validity_days');
    $validUntil = $quotation->quotation_date->copy()->addDays($validityDays);
    $customer = $quotation->customer;
    $phone = $customer?->phone ?? $quotation->walkin_phone;
    $terms = branch_setting('quotation_footer_note', $quotation->branch_id) ?: config('annigems.documents.quotation_terms');
    $branch = $quotation->branch;

    $rate = (float) $quotation->tax_rate;
    $taxed = (float) $quotation->tax_amount > 0 || (filled($quotation->tax_name) && $rate <= 0);
    $taxName = filled($quotation->tax_name) ? $quotation->tax_name : 'IVA';
    $pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $discountLabel = __('Discount').($quotation->discount_type === \App\Enums\DiscountType::PERCENTAGE && (float) $quotation->discount_value > 0 ? ' ('.$pct($quotation->discount_value).'%)' : '');
    $hasRecargo = (float) ($quotation->recargo_amount ?? 0) > 0;
@endphp

<article @class(['pd', 'pd--quotation', 'pd--a4' => $a4, 'pd--dense' => $quotation->items->count() > 12])>
    <div class="pd__page">
        <header class="pd__qhead">
            <div class="pd__brand pd__brand--lg"><x-pd.logo /></div>
            <div class="pd__company--right"><x-pd.company :branch="$branch" /></div>
        </header>

        <section class="pd__intro">
            <div>
                <span class="pd__rule" aria-hidden="true"></span>
                <h1 class="pd__qtitle">{{ __('Quotation') }}</h1>
                <p class="pd__qnumber">{{ $quotation->quotation_number }}</p>
            </div>
            <div class="pd__stats">
                <div><p class="pd__label">{{ __('Date') }}</p><p>{{ setting_date($quotation->quotation_date) }}</p></div>
                <div><p class="pd__label">{{ __('Valid until') }}</p><p>{{ setting_date($validUntil) }}</p></div>
                <div><p class="pd__label">{{ $taxed ? __('Total with tax') : __('Total') }}</p><p class="is-accent">{{ setting_money($quotation->grand_total) }}</p></div>
            </div>
        </section>

        {{-- A cancelled quote is no longer actionable; say so up top. --}}
        @if($quotation->status === \App\Enums\QuotationStatus::CANCELLED)
            <div class="pd__notice">{{ __('This quotation has been cancelled.') }}</div>
        @endif

        <section class="pd__cards">
            <div class="pd__card pd__card--accent">
                <p class="pd__label">{{ __('Prepared for') }}</p>
                <p class="pd__party-name">{{ $quotation->displayCustomerName() }}</p>
                <p class="pd__party-lines">
                    @if($customer?->nie)<span>N.I.F / N.I.E {{ $customer->nie }}</span>@endif
                    <span>{{ $phone ?: __('Walk-in customer') }}</span>
                    @if($customer?->address)<span>{{ $customer->address }}</span>@endif
                </p>
            </div>
            <div class="pd__card">
                <p class="pd__label">{{ __('Prepared by') }}</p>
                <p class="pd__party-name">{{ setting('company_name', config('annigems.documents.brand_name')) }}</p>
                <p class="pd__party-lines">
                    @if($branch?->email)<span>{{ $branch->email }}</span>@endif
                    @if($branch?->contact_number)<span>{{ $branch->contact_number }}</span>@endif
                    <span>{{ $quotation->creator?->name ?? '—' }}</span>
                </p>
            </div>
        </section>

        <div class="pd__table-wrap" style="margin-top: 28px">
            <x-pd.lines :items="$quotation->items" :document="$quotation" mode="quotation" />
        </div>

        <section class="pd__sums pd__sums--end">
            <div class="pd__totals pd__totals--wide">
                <div class="pd__total-row"><span>{{ $taxed ? __('Total without tax') : __('Subtotal') }}</span><span>{{ setting_money($quotation->subtotal) }}</span></div>
                @if((float) $quotation->discount_amount > 0)
                    <div class="pd__total-row">
                        <span>{{ $discountLabel }}</span>
                        <span>−{{ setting_money($quotation->discount_amount) }}</span>
                    </div>
                @endif
                @if($taxed)
                    <div class="pd__total-row"><span>{{ $taxName }}</span><span>{{ $rate > 0 ? setting_money($quotation->tax_amount) : __('Exempt') }}</span></div>
                @endif
                @if($hasRecargo)
                    <div class="pd__total-row"><span>{{ __('Recargo de equivalencia') }} ({{ $pct($quotation->recargo_rate) }}%)</span><span>{{ setting_money($quotation->recargo_amount) }}</span></div>
                @endif
                <div class="pd__grand pd__grand--accent">
                    <span class="pd__grand-label">{{ $taxed ? __('Total with tax') : __('Total') }}</span>
                    <span class="pd__grand-value">{{ setting_money($quotation->grand_total) }}</span>
                </div>
            </div>
        </section>

        <x-pd.notes :notes="$quotation->notes" :terms="$terms" terms-label="Scope and conditions" />

        <footer class="pd__footer pd__footer--rule">
            <span class="pd__footer-strong">{{ __('This is a quotation, not a tax invoice.') }}</span>
            <span>{{ $quotation->quotation_number }}</span>
        </footer>
    </div>
</article>
