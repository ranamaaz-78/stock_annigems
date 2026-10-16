{{--
    Quotation document sheet.

    Same frame as the invoice, but a quote is never mistaken for a bill: the
    validity tile is outlined, the total reads "Estimated total", and there is
    no verification QR — a quote is not a receipt.

    Expects: $quotation and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;

    $validityDays = (int) config('annigems.documents.quotation_validity_days');
    $validUntil = $quotation->quotation_date->copy()->addDays($validityDays);
    $customerPhone = $quotation->customer?->phone ?? $quotation->walkin_phone;
    $terms = branch_setting('quotation_footer_note', $quotation->branch_id) ?: config('annigems.documents.quotation_terms');
@endphp

<x-document.sheet doc-type="Quotation" :number="$quotation->quotation_number" :branch="$quotation->branch" :a4="$a4">
    <x-slot:meta>
        <x-document.meta-row label="Issue date">{{ setting_date($quotation->quotation_date) }}</x-document.meta-row>
        <x-document.meta-row label="Prepared by">{{ $quotation->creator?->name ?? '—' }}</x-document.meta-row>
        <x-document.meta-row label="Valid until" outline>
            {{ setting_date($validUntil) }} · {{ $validityDays }} {{ Str::plural('day', $validityDays) }}
        </x-document.meta-row>
    </x-slot:meta>

    {{-- Converted or cancelled quotes are no longer actionable; say so up top
         so nobody prices against a stale document. --}}
    @if($quotation->convertedInvoice)
        <x-slot:notice>
            <x-alert type="success">
                Converted to invoice
                <strong>{{ $quotation->convertedInvoice->invoice_number }}</strong>
                on {{ setting_date($quotation->convertedInvoice->invoice_date) }}.
            </x-alert>
        </x-slot:notice>
    @elseif($quotation->status === \App\Enums\QuotationStatus::CANCELLED)
        <x-slot:notice>
            <x-alert type="error">This quotation has been cancelled.</x-alert>
        </x-slot:notice>
    @endif

    <x-slot:parties>
        <x-document.party label="Prepared for">
            <x-slot:name><p class="sheet__party-name">{{ $quotation->displayCustomerName() }}</p></x-slot:name>
            <p class="sheet__party-lines">
                {{ $customerPhone ?: 'Walk-in customer' }}
                @if($quotation->customer?->address)
                    <br>{{ $quotation->customer->address }}
                @endif
                @if($quotation->customer?->nie)
                    <br>NIE / NIF {{ $quotation->customer->nie }}
                @endif
            </p>
        </x-document.party>
    </x-slot:parties>

    <x-slot:items>
        <x-document.lines :items="$quotation->items" />
    </x-slot:items>

    <x-slot:terms>
        <p class="sheet__terms-label">Quotation terms</p>
        <p class="sheet__terms">{{ $terms }}</p>
        @if($quotation->notes)
            <p class="sheet__terms-label" style="margin-top: 8px">Notes</p>
            <p class="sheet__terms">{{ $quotation->notes }}</p>
        @endif
    </x-slot:terms>

    <x-slot:totals>
        <x-document.totals :document="$quotation" grand-label="Estimated Total" />
    </x-slot:totals>

    <x-slot:footer>
        <p class="sheet__footer-title">A quotation, not a receipt.</p>
        <p class="sheet__footer-text">Quote {{ $quotation->quotation_number }} when you return to buy.</p>
    </x-slot:footer>
</x-document.sheet>