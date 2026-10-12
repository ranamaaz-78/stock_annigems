{{--
    Delivery note (albarán) sheet.

    The client's copy: it names the document and the client and nothing about
    the shop — no logo, company, branch, tagline, terms or QR. That is on
    purpose, so this view does not use x-document.sheet (which always prints
    the branded header). Lines and totals reuse the shared components; the
    totals show the discount and never a tax row.

    Expects: $note and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
@endphp

<article @class(['sheet', 'sheet--delivery-note', 'sheet--a4' => $a4])>
    <header class="sheet__header sheet__header--delivery-note">
        <div>
            <h2 class="sheet__headline">Delivery Note</h2>
            <p class="sheet__headline-alt">Albarán</p>
        </div>

        <dl class="sheet__meta">
            <x-document.meta-row label="No.">{{ $note->delivery_note_number }}</x-document.meta-row>
            <x-document.meta-row label="Date">{{ setting_date($note->delivery_date) }}</x-document.meta-row>
        </dl>
    </header>

    @if($note->status === \App\Enums\DeliveryNoteStatus::CANCELLED)
        <div class="sheet__notice">
            <x-alert type="error">
                <strong>This delivery note has been cancelled.</strong> {{ $note->cancel_reason }}
            </x-alert>
        </div>
    @endif

    <section class="sheet__parties sheet__parties--single">
        <x-document.party label="Client">
            <p class="sheet__party-name">{{ $note->customer_name_snapshot }}</p>
            <p class="sheet__party-lines">
                {{ $note->customer_phone_snapshot ?? '—' }}
                @if($note->customer_address_snapshot)
                    <br>{{ $note->customer_address_snapshot }}
                @endif
            </p>
        </x-document.party>
    </section>

    <section class="sheet__items">
        <x-document.lines :items="$note->items" />
    </section>

    <div class="sheet__closing">
        <section class="sheet__totals">
            <x-document.totals :document="$note" grand-label="Total" />
        </section>

        @if(filled($note->notes))
            <footer class="sheet__footer sheet__footer--note">
                <div>
                    <p class="sheet__terms-label">Notes</p>
                    <p class="sheet__terms">{{ $note->notes }}</p>
                </div>
            </footer>
        @endif
    </div>
</article>