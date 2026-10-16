{{--
    Invoice document sheet.

    Shared by the in-app detail screen and the printable page so the customer
    sees on paper exactly what the cashier sees on screen.

    Expects: $invoice, $qrUri, $verifyUrl, and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
    $customerAddress = $invoice->customer?->address;
    $terms = branch_setting('invoice_footer_note', $invoice->branch_id) ?: config('annigems.documents.invoice_terms');
@endphp

<x-document.sheet doc-type="Invoice" :number="$invoice->invoice_number" :branch="$invoice->branch" :a4="$a4">
    <x-slot:meta>
        <x-document.meta-row label="Issue date">{{ setting_date($invoice->invoice_date) }}</x-document.meta-row>
        <x-document.meta-row label="Payment">
            {{ $invoice->payment_method->label() }}@if($invoice->payment_reference) · Ref {{ $invoice->payment_reference }}@endif
        </x-document.meta-row>
        <x-document.meta-row label="Served by">{{ $invoice->creator?->name ?? '—' }}</x-document.meta-row>
        <x-document.meta-row label="Status">{{ $invoice->isVoided() ? 'Voided' : 'Paid in full' }}</x-document.meta-row>
    </x-slot:meta>

    {{-- A voided invoice must announce itself before anything else is read. --}}
    @if($invoice->isVoided())
        <x-slot:notice>
            <x-alert type="error">
                <strong>This invoice has been voided.</strong> {{ $invoice->void_reason }}
            </x-alert>
        </x-slot:notice>
    @endif

    <x-slot:parties>
        <x-document.party label="Billed to">
            <x-slot:name><p class="sheet__party-name">{{ $invoice->customer_name_snapshot }}</p></x-slot:name>
            <p class="sheet__party-lines">
                {{ $invoice->customer_phone_snapshot ?? '—' }}
                @if($customerAddress)
                    <br>{{ $customerAddress }}
                @endif
                @if($invoice->customer?->nie)
                    <br>NIE / NIF {{ $invoice->customer->nie }}
                @endif
            </p>
        </x-document.party>
    </x-slot:parties>

    <x-slot:items>
        <x-document.lines :items="$invoice->items" />
    </x-slot:items>

    <x-slot:terms>
        <p class="sheet__terms-label">Terms &amp; conditions</p>
        <p class="sheet__terms">{{ $terms }}</p>
    </x-slot:terms>

    <x-slot:totals>
        <x-document.totals :document="$invoice" grand-label="Grand Total" />
    </x-slot:totals>

    {{-- The QR resolves to the public verification page; hiding it on a
         voided invoice would remove the very check a customer needs. --}}
    <x-slot:footer>
        <div>
            <p class="sheet__footer-title">Verify this invoice</p>
            <p class="sheet__footer-text">Scan the code to check it is genuine · {{ $invoice->invoice_number }}</p>
        </div>
        @if(! empty($qrUri))
            <div class="sheet__qr">
                <img src="{{ $qrUri }}" alt="Scan to verify invoice {{ $invoice->invoice_number }}">
            </div>
        @endif
    </x-slot:footer>
</x-document.sheet>