{{--
    Invoice document sheet — design/invoice_view.

    Shared by the in-app detail screen and the printable page so the customer
    sees on paper exactly what the cashier sees on screen.

    Expects: $invoice, $qrUri, $verifyUrl, and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
    $customerAddress = $invoice->customer?->address;
@endphp

<x-document.sheet doc-type="Invoice" :branch="$invoice->branch" :a4="$a4">
    <x-slot:meta>
        <x-document.meta-row label="Invoice No">{{ $invoice->invoice_number }}</x-document.meta-row>
        <x-document.meta-row label="Date">{{ setting_date($invoice->invoice_date) }}</x-document.meta-row>
        <x-document.meta-row label="Status">{{ $invoice->status->label() }}</x-document.meta-row>
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
            <p class="sheet__party-name">{{ $invoice->customer_name_snapshot }}</p>
            <p class="sheet__party-lines">
                {{ $invoice->customer_phone_snapshot ?? '—' }}
                @if($customerAddress)
                    <br>{{ $customerAddress }}
                @endif
            </p>
        </x-document.party>

        <x-document.party label="Payment details" divided>
            <p class="sheet__party-name">{{ $invoice->payment_method->label() }}</p>
            <p class="sheet__party-lines">
                @if($invoice->payment_reference)
                    Ref: {{ $invoice->payment_reference }}<br>
                @endif
                Served by {{ $invoice->creator?->name ?? '—' }}
            </p>
        </x-document.party>
    </x-slot:parties>

    <x-slot:items>
        <x-document.lines :items="$invoice->items" />
    </x-slot:items>

    <x-slot:totals>
        <x-document.totals :document="$invoice" grand-label="Grand Total" />
    </x-slot:totals>

    <x-slot:footer>
        <div>
            <p class="sheet__terms-label">Terms &amp; conditions</p>
            <p class="sheet__terms">{{ branch_setting('invoice_footer_note', $invoice->branch_id) ?: config('annigems.documents.invoice_terms') }}</p>
        </div>

        {{-- The QR resolves to the public verification page; hiding it on a
             voided invoice would remove the very check a customer needs. --}}
        <div class="sheet__codes">
            @if(! empty($qrUri))
                <div class="sheet__qr">
                    <img src="{{ $qrUri }}" alt="Scan to verify invoice {{ $invoice->invoice_number }}">
                </div>
            @endif
            <div class="sheet__code">
                <span class="sheet__code-label">Verify</span>
                <span class="sheet__code-value">{{ $invoice->invoice_number }}</span>
            </div>
        </div>
    </x-slot:footer>
</x-document.sheet>
