{{--
    Invoice document sheet.

    Shared by the in-app detail screen and the printable page so the customer
    sees on paper exactly what the cashier sees on screen. Design: "Printable
    designs" → Invoice (Factura).

    Expects: $invoice, $qrUri, $verifyUrl, and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
    $customer = $invoice->customer;
    $terms = branch_setting('invoice_footer_note', $invoice->branch_id) ?: config('annigems.documents.invoice_terms');
    $voided = $invoice->isVoided();

    $rate = (float) $invoice->tax_rate;
    $taxed = (float) $invoice->tax_amount > 0 || (filled($invoice->tax_name) && $rate <= 0);
    $base = (float) $invoice->subtotal - (float) $invoice->discount_amount;
    $pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $discountLabel = 'Discount'.($invoice->discount_type === \App\Enums\DiscountType::PERCENTAGE && (float) $invoice->discount_value > 0 ? ' ('.$pct($invoice->discount_value).'%)' : '');
    $taxName = filled($invoice->tax_name) ? $invoice->tax_name : 'IVA';
    $hasRecargo = (float) ($invoice->recargo_amount ?? 0) > 0;
@endphp

<article @class(['pd', 'pd--invoice', 'pd--a4' => $a4, 'pd--dense' => $invoice->items->count() > 12])>
    <div class="pd__page">
        <span class="pd__bar" aria-hidden="true"></span>

        <header class="pd__head">
            <div class="pd__brand">
                <x-pd.logo />
                <x-pd.company :branch="$invoice->branch" />
            </div>
            <div class="pd__title-block">
                <h1 class="pd__title">Invoice</h1>
                <span @class(['pd__badge', 'pd__badge--paid' => ! $voided && $invoice->isPaid(), 'pd__badge--void' => $voided])>{{ $voided ? 'Voided' : $invoice->payment_status->label() }}</span>
            </div>
        </header>

        {{-- A voided invoice must announce itself before anything else is read. --}}
        @if($voided)
            <div class="pd__notice"><strong>This invoice has been voided.</strong> {{ $invoice->void_reason }}</div>
        @endif

        <section class="pd__row">
            <div class="pd__party">
                <p class="pd__label">Bill to</p>
                <p class="pd__party-name">{{ $invoice->customer_name_snapshot }}</p>
                <p class="pd__party-lines">
                    @if($customer?->nie)<span>N.I.F / N.I.E {{ $customer->nie }}</span>@endif
                    @if($invoice->customer_phone_snapshot)<span>{{ $invoice->customer_phone_snapshot }}</span>@endif
                    @if($customer?->address)<span>{{ $customer->address }}</span>@endif
                </p>
            </div>
            <dl class="pd__facts">
                <dt>Invoice no.</dt><dd class="is-strong">{{ $invoice->invoice_number }}</dd>
                <dt>Issue date</dt><dd>{{ setting_date($invoice->invoice_date) }}</dd>
                <dt>Payment</dt><dd>{{ $invoice->isPaid() ? ($invoice->payment_method?->label() ?? 'Paid').($invoice->payment_reference ? ' · '.$invoice->payment_reference : '') : 'Pending' }}</dd>
                @unless($invoice->isPaid() || $voided)
                    <dt>Amount due</dt><dd class="is-strong">{{ setting_money($invoice->balanceDue()) }}</dd>
                @endunless
                <dt>Served by</dt><dd>{{ $invoice->creator?->name ?? '—' }}</dd>
            </dl>
        </section>

        <div class="pd__table-wrap">
            <x-pd.lines :items="$invoice->items" :document="$invoice" mode="invoice" />
        </div>

        <section class="pd__sums">
            <div class="pd__summary">
                @if($taxed)
                    <p class="pd__label">{{ $taxName }} summary</p>
                    <table>
                        <thead><tr><th>Rate</th><th>Base</th><th>IVA</th></tr></thead>
                        <tbody>
                            <tr>
                                <td>{{ $rate > 0 ? $pct($rate).'%' : 'Exempt' }}</td>
                                <td>{{ setting_money($base) }}</td>
                                <td>{{ setting_money($invoice->tax_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="pd__totals">
                <div class="pd__total-row"><span>Subtotal</span><span>{{ setting_money($invoice->subtotal) }}</span></div>
                @if((float) $invoice->discount_amount > 0)
                    <div class="pd__total-row">
                        <span>{{ $discountLabel }}</span>
                        <span>−{{ setting_money($invoice->discount_amount) }}</span>
                    </div>
                @endif
                @if($taxed)
                    <div class="pd__total-row"><span>{{ $taxName }}</span><span>{{ $rate > 0 ? setting_money($invoice->tax_amount) : 'Exempt' }}</span></div>
                @endif
                @if($hasRecargo)
                    <div class="pd__total-row"><span>Recargo de equivalencia ({{ $pct($invoice->recargo_rate) }}%)</span><span>{{ setting_money($invoice->recargo_amount) }}</span></div>
                @endif
                <div class="pd__grand">
                    <span class="pd__grand-label">Total</span>
                    <span class="pd__grand-value">{{ setting_money($invoice->grand_total) }}</span>
                </div>
            </div>
        </section>

        <x-pd.notes :notes="$invoice->notes" :terms="$terms" />

        {{-- The QR resolves to the public verification page; hiding it on a
             voided invoice would remove the very check a customer needs. --}}
        <footer class="pd__footer">
            <span>{{ setting('company_name', config('annigems.documents.brand_name')) }}@if($invoice->branch?->email) · {{ $invoice->branch->email }}@endif @if($invoice->branch?->contact_number) · {{ $invoice->branch->contact_number }}@endif</span>
            @if(! empty($qrUri))
                <div class="pd__verify">
                    <div>
                        <strong>Verify this invoice</strong>
                        <span>Scan the code to confirm it is genuine.</span>
                    </div>
                    <span class="pd__qr"><img src="{{ $qrUri }}" alt="Scan to verify invoice {{ $invoice->invoice_number }}"></span>
                </div>
            @else
                <span>{{ $invoice->invoice_number }}</span>
            @endif
        </footer>
    </div>
</article>
