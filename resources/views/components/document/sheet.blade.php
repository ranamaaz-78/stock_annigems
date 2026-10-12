{{--
    Document sheet shell — design/invoice_view.

    Shared frame for quotations and invoices: branded header, party columns,
    itemised table, totals, and footer. Callers fill the slots; the structure
    and every visual rule stay here so both documents cannot drift apart.

    Slots: meta (required), parties, items, totals, footer, notice (optional).
--}}
@props([
    'docType' => null,      // Heading text, e.g. "Invoice" / "Quotation". Omitted on the live POS sheet.
    'branch',               // Branch model — its name prints under the brand
    'outlinedType' => false, // Renders the heading as an outlined pill
    'a4' => false,           // Forces full page height (print views only)
])

<article {{ $attributes->class(['sheet', 'sheet--a4' => $a4]) }}>
    <header class="sheet__header">
        <div class="sheet__identity">
            @if(brand_asset_url('company_logo'))
                <img class="sheet__logo" src="{{ brand_asset_url('company_logo') }}" alt="{{ setting('company_name') }}">
            @else
                <div class="sheet__mark" aria-hidden="true">{{ config('annigems.documents.brand_mark') }}</div>
            @endif
            <div>
                <p class="sheet__brand">{{ setting('company_name', config('annigems.documents.brand_name')) }}</p>
                <p class="sheet__tagline">{{ config('annigems.documents.brand_tagline') }}</p>
                <p class="sheet__branch">{{ $branch->name }}</p>
            </div>
        </div>

        <div class="sheet__docinfo">
            {{-- Printed documents name themselves; the live POS sheet does not. --}}
            @if(filled($docType))
                <h2 @class(['sheet__doctype', 'sheet__doctype--outlined' => $outlinedType])>{{ $docType }}</h2>
            @endif
            <dl class="sheet__meta">{{ $meta }}</dl>
        </div>
    </header>

    {{-- Void banner / validity callout sits above the party columns. --}}
    @isset($notice)
        <div class="sheet__notice">{{ $notice }}</div>
    @endisset

    <section class="sheet__parties">{{ $parties }}</section>

    <section class="sheet__items">{{ $items }}</section>

    {{-- Grouped so margin-top:auto pins both blocks to the foot of the sheet. --}}
    <div class="sheet__closing">
        <section class="sheet__totals">{{ $totals }}</section>
        <footer class="sheet__footer">{{ $footer }}</footer>
    </div>
</article>
