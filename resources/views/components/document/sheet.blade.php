{{--
    Document sheet shell — one design for invoice, quotation, delivery note and
    performa (see _documents.scss). Callers fill the slots; the structure and
    every visual rule stay here so the four documents cannot drift apart, and
    the printed page is the same sheet the screen shows.

    Props: docType (title), number (document no.), branch (null = no company
    block, as on a delivery note), a4 (print views only).
    Slots: meta (tiles), notice, parties, extras (e.g. performa stats), items,
    totals, terms (card beside the totals), footer (strip). All optional.
--}}
@props([
    'docType' => null,
    'number' => null,
    'branch' => null,      // Branch model; its name prints under the brand. null hides the company block.
    'a4' => false,         // Forces full page height (print views only)
])

<article {{ $attributes->class(['sheet', 'sheet--a4' => $a4]) }}>
    <header @class(['sheet__header', 'sheet__header--title-only' => $branch === null])>
        @if($branch !== null)
            <div class="sheet__identity">
                <img class="sheet__logo" src="{{ brand_logo_url() }}" alt="{{ setting('company_name') }}">
                <div>
                    <p class="sheet__brand">{{ setting('company_name', config('annigems.documents.brand_name')) }}</p>
                    <p class="sheet__tagline">{{ config('annigems.documents.brand_tagline') }}</p>
                    <p class="sheet__branch">{{ $branch->name }}</p>
                </div>
            </div>
        @elseif(filled($docType))
            <h2 class="sheet__doctype">{{ $docType }}</h2>
        @endif

        <div class="sheet__docinfo">
            @if($branch !== null && filled($docType))
                <h2 class="sheet__doctype">{{ $docType }}</h2>
            @endif
            @if(filled($number))
                <p class="sheet__number">{{ $number }}</p>
            @endif
        </div>
    </header>

    <div class="sheet__rule" aria-hidden="true"></div>

    @isset($meta)
        <div class="sheet__tiles">{{ $meta }}</div>
    @endisset

    {{-- Void banner / validity callout sits above the party card. --}}
    @isset($notice)
        <div class="sheet__notice">{{ $notice }}</div>
    @endisset

    @isset($parties)
        <section class="sheet__parties">{{ $parties }}</section>
    @endisset

    @isset($extras)
        {{ $extras }}
    @endisset

    @isset($items)
        <section class="sheet__items">{{ $items }}</section>
    @endisset

    {{-- margin-top:auto pins terms and totals to the foot of the page. --}}
    <div class="sheet__closing">
        @isset($terms)
            <div class="sheet__terms-card">{{ $terms }}</div>
        @endisset
        @isset($totals)
            <section class="sheet__totals">{{ $totals }}</section>
        @endisset
    </div>

    @isset($footer)
        <footer class="sheet__footer">{{ $footer }}</footer>
    @endisset
</article>