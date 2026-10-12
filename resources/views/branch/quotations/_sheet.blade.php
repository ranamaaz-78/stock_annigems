{{--
    Quotation document sheet.

    Same frame as the invoice (design/invoice_view) but deliberately
    distinguishable: the heading is an outlined pill, the second party column
    carries validity instead of payment details, the grand total is labelled an
    estimate, and no verification QR is printed — a quote is not a receipt.

    Expects: $quotation and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;

    $validityDays = (int) config('annigems.documents.quotation_validity_days');
    $validUntil = $quotation->quotation_date->copy()->addDays($validityDays);
    $customerPhone = $quotation->customer?->phone ?? $quotation->walkin_phone;
@endphp

<x-document.sheet doc-type="Quotation" :branch="$quotation->branch" :a4="$a4" outlined-type>
    <x-slot:meta>
        <x-document.meta-row label="Quotation No">{{ $quotation->quotation_number }}</x-document.meta-row>
        <x-document.meta-row label="Date">{{ setting_date($quotation->quotation_date) }}</x-document.meta-row>
        <x-document.meta-row label="Valid until">{{ setting_date($validUntil) }}</x-document.meta-row>
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
            <p class="sheet__party-name">{{ $quotation->displayCustomerName() }}</p>
            <p class="sheet__party-lines">
                {{ $customerPhone ?: 'Walk-in customer' }}
                @if($quotation->customer?->address)
                    <br>{{ $quotation->customer->address }}
                @endif
            </p>
        </x-document.party>

        <x-document.party label="Validity" divided>
            <p class="sheet__party-name">{{ setting_date($validUntil) }}</p>
            <p class="sheet__party-lines">
                Valid for {{ $validityDays }} {{ Str::plural('day', $validityDays) }} from the quotation date.<br>
                Prepared by {{ $quotation->creator?->name ?? '—' }}
            </p>
        </x-document.party>
    </x-slot:parties>

    <x-slot:items>
        <x-document.lines :items="$quotation->items" />
    </x-slot:items>

    <x-slot:totals>
        <x-document.totals :document="$quotation" grand-label="Estimated Total" />
    </x-slot:totals>

    <x-slot:footer>
        <div>
            <p class="sheet__terms-label">Quotation terms</p>
            <p class="sheet__terms">{{ branch_setting('quotation_footer_note', $quotation->branch_id) ?: config('annigems.documents.quotation_terms') }}</p>
            @if($quotation->notes)
                <p class="sheet__terms"><strong>Notes:</strong> {{ $quotation->notes }}</p>
            @endif
        </div>

        <div class="sheet__codes">
            <div class="sheet__code">
                <span class="sheet__code-label">Reference</span>
                <span class="sheet__code-value">{{ $quotation->quotation_number }}</span>
            </div>
        </div>
    </x-slot:footer>
</x-document.sheet>
