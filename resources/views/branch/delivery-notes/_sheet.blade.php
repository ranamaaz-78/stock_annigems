{{--
    Delivery note (albarán) sheet. Design: "Printable designs" → Delivery note.

    The client's copy: it names the document and the client and nothing about
    the shop. Lines show one price each; the total is net, with the discount when
    there is one, and never a tax row. Ends with a signature strip.

    Expects: $note and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
    $customer = $note->customer;
    $units = (int) $note->items->filter(fn ($i) => (float) ($i->weight_grams ?? 0) <= 0)->sum('quantity');
    $grams = (float) $note->items->sum('weight_grams');
    $unitsText = collect([
        $units > 0 ? '<strong>'.number_format($units).'</strong> '.Str::plural('unit', $units) : null,
        $grams > 0 ? '<strong>'.rtrim(rtrim(number_format($grams, 3, '.', ''), '0'), '.').'</strong> g' : null,
    ])->filter()->implode(' + ');
    $pct = static fn ($v): string => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $discountLabel = 'Discount'.($note->discount_type === \App\Enums\DiscountType::PERCENTAGE && (float) $note->discount_value > 0 ? ' ('.$pct($note->discount_value).'%)' : '');
    $terms = 'This delivery note is not a tax invoice.';
@endphp

<article @class(['pd', 'pd--delivery', 'pd--a4' => $a4, 'pd--dense' => $note->items->count() > 12])>
    <div class="pd__page">
        <header class="pd__head" style="align-items: center">
            <h1 class="pd__dtitle">Albarán</h1>
            <div class="pd__numbox">
                <div><p class="pd__label">No.</p><p>{{ $note->delivery_note_number }}</p></div>
                <div><p class="pd__label">Date</p><p>{{ setting_date($note->delivery_date) }}</p></div>
            </div>
        </header>

        @if($note->status === \App\Enums\DeliveryNoteStatus::CANCELLED)
            <div class="pd__notice"><strong>This delivery note has been cancelled.</strong> {{ $note->cancel_reason }}</div>
        @endif

        <section class="pd__deliver">
            <p class="pd__label">Deliver to</p>
            <div class="pd__fields">
                <div class="pd__field"><span>Name</span><span>{{ $note->customer_name_snapshot }}</span></div>
                @if($customer?->nie || $note->customer_phone_snapshot)
                    <div class="pd__fields-pair">
                        <div class="pd__field"><span>N.I.F / N.I.E</span><span>{{ $customer?->nie ?: '—' }}</span></div>
                        <div class="pd__field"><span>Phone</span><span>{{ $note->customer_phone_snapshot ?: '—' }}</span></div>
                    </div>
                @endif
                @if($note->customer_address_snapshot)
                    <div class="pd__field"><span>Address</span><span>{{ $note->customer_address_snapshot }}</span></div>
                @endif
            </div>
        </section>

        <div class="pd__table-wrap" style="margin-top: 36px">
            <x-pd.lines :items="$note->items" :document="$note" mode="delivery" />
        </div>

        <section class="pd__sums" style="margin-top: 24px">
            <p class="pd__units">{!! $unitsText !!} in <strong>{{ $note->items->count() }}</strong> {{ Str::plural('line', $note->items->count()) }}</p>
            <div class="pd__totals" style="width: 260px">
                @if((float) $note->discount_amount > 0)
                    <div class="pd__total-row"><span>Subtotal</span><span>{{ setting_money($note->subtotal) }}</span></div>
                    <div class="pd__total-row">
                        <span>{{ $discountLabel }}</span>
                        <span>−{{ setting_money($note->discount_amount) }}</span>
                    </div>
                @endif
                <div class="pd__net"><span>Total (net)</span><span>{{ setting_money($note->grand_total) }}</span></div>
            </div>
        </section>

        <x-pd.notes :notes="$note->notes" :terms="$terms" />

        <section class="pd__sign">
            <div><span></span><p>Received by</p></div>
            <div><span></span><p>Date</p></div>
        </section>

        <footer class="pd__footer pd__footer--dashed">
            <span>Keep this slip with the goods.</span>
            <span>{{ $note->delivery_note_number }}</span>
        </footer>
    </div>
</article>
