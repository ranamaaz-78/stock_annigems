{{--
    Certificate of Authenticity, 5 x 4.5 inch landscape, after the shop's printed card.
    Opens its own print dialog: Print sends it to the printer, "Save as PDF" in the
    same dialog downloads it. Only facts about the piece appear here; never cost,
    rate or internal notes. The QR opens the piece's public verification page. To flip the card to portrait, swap --cert-w and --cert-h.
--}}
@php
    $brand = (string) setting('company_name', 'Aani Gemas');
    $metal = mb_strtoupper(trim((string) $item->metalType?->name));
    $purity = mb_strtoupper(trim((string) $item->purity?->name));
    $grams = fn ($value): string => rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.').' '.__('grams');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Certificate of Authenticity') }} {{ $item->item_code }}</title>
    <x-brand.favicon />
    <style>
        :root { --cert-w: 5in; --cert-h: 4.5in; --gold: #b8923a; --navy: #14264a; --ink: #2b2b2b; }
        @page { size: 5in 4.5in; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { font-family: Georgia, 'Times New Roman', serif; color: var(--ink); background: #e9e7e2; }

        .toolbar { display: flex; gap: .5rem; justify-content: center; padding: .75rem; font-family: system-ui, sans-serif; }
        .toolbar button { padding: .5rem 1rem; border: 1px solid var(--gold); border-radius: .375rem; background: #fff; color: #5c4710; cursor: pointer; font-size: .875rem; }
        .toolbar button.primary { background: var(--gold); color: #fff; }

        .cert { width: var(--cert-w); height: var(--cert-h); margin: 0 auto 1rem; padding: .1in; background: #fff; position: relative; overflow: hidden; }
        .cert__frame { position: relative; height: 100%; border: 1px solid var(--gold); padding: .07in .22in .06in; display: flex; flex-direction: column; align-items: center; text-align: center; }
        .cert__frame::before { content: ''; position: absolute; inset: .035in; border: .5px solid var(--gold); pointer-events: none; }
        .cert__corner { position: absolute; width: .32in; height: .32in; color: var(--gold); }
        .cert__corner--tl { top: .02in; left: .02in; }
        .cert__corner--tr { top: .02in; right: .02in; transform: scaleX(-1); }
        .cert__corner--bl { bottom: .02in; left: .02in; transform: scaleY(-1); }
        .cert__corner--br { bottom: .02in; right: .02in; transform: scale(-1, -1); }

        .cert__gem { width: .4in; height: .3in; color: var(--gold); margin-top: .01in; }
        .cert__flourish { width: 1.25in; height: .09in; color: var(--gold); margin-top: -.02in; }
        .cert__title { margin: .02in 0 0; font-size: 22pt; line-height: 1; font-weight: 700; letter-spacing: .05em; color: var(--navy); }
        .cert__sub { margin: .03in 0 0; font-size: 8.5pt; letter-spacing: .3em; font-weight: 700; color: var(--navy); }
        .cert__rule { width: 1.5in; height: 1px; margin: .045in 0; border: 0; background: linear-gradient(90deg, transparent, var(--gold), transparent); }
        .cert__intro { margin: 0; font-size: 6.6pt; line-height: 1.35; color: #444; }

        .cert__body { display: flex; align-items: stretch; width: 100%; gap: .2in; margin-top: .09in; flex: 1; min-height: 0; text-align: left; }
        .cert__table { flex: 1.25; min-width: 0; }
        .cert__row { display: flex; align-items: baseline; padding: .028in 0 .022in; border-bottom: .6px dotted var(--gold); font-size: 6.4pt; line-height: 1.2; }
        .cert__row dt { flex: 0 0 1.2in; color: var(--navy); font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
        .cert__row dd { margin: 0; color: var(--ink); }
        .cert__table dl { margin: 0; }
        .cert__note { margin: .03in 0 0; font-size: 5pt; font-style: italic; color: #555; }

        .cert__photo { flex: 1; min-width: 0; align-self: center; height: 1.35in; border: 1px solid var(--gold); border-radius: .08in; padding: .04in; display: flex; align-items: center; justify-content: center; background: #fff; }
        .cert__photo img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: .05in; }

        .cert__bottom { display: flex; align-items: center; justify-content: space-between; width: 100%; gap: .15in; margin-top: .04in; text-align: left; }
        .cert__guarantee { flex: 1.3; margin: 0; font-size: 6.4pt; line-height: 1.4; color: #444; }
        .cert__qr { flex: none; text-align: center; }
        .cert__qr img { width: .6in; height: .6in; display: block; }
        .cert__qr span { display: block; margin-top: .01in; font-size: 4.8pt; letter-spacing: .06em; text-transform: uppercase; color: var(--navy); }
        .cert__seal { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .02in; }
        .cert__seal img { width: .62in; height: .62in; object-fit: contain; display: block; }
        .cert__for { font-size: 6.2pt; letter-spacing: .04em; font-weight: 700; text-transform: uppercase; color: var(--navy); }

        .cert__thanks { display: flex; align-items: center; gap: .08in; margin-top: .03in; font-size: 5.6pt; letter-spacing: .18em; text-transform: uppercase; color: var(--gold); }
        .cert__thanks::before, .cert__thanks::after { content: ''; width: .35in; height: 1px; background: var(--gold); }

        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            .cert { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">{{ __('Print / Save as PDF') }}</button>
        <button type="button" onclick="window.close()">{{ __('Close') }}</button>
    </div>

    <article class="cert">
        <div class="cert__frame">
            {{-- Corner flourishes: one drawing, mirrored into each corner. --}}
            @foreach(['tl', 'tr', 'bl', 'br'] as $corner)
                <svg class="cert__corner cert__corner--{{ $corner }}" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" aria-hidden="true">
                    <path d="M3 37V12C3 7 7 3 12 3h25"/>
                    <path d="M8 37V16c0-5 3-8 8-8h21" opacity=".55"/>
                    <path d="M12 12c0 4 4 5 6 3s0-5-3-4-3 4-1 5" />
                    <circle cx="3" cy="3" r="1.6" fill="currentColor"/>
                </svg>
            @endforeach

            <svg class="cert__gem" viewBox="0 0 48 36" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3h24l9 11-21 20L3 14z"/>
                <path d="M3 14h42M17 3l-5 11 12 20 12-20-5-11M24 34 17 14M24 34l7-20"/>
            </svg>
            <svg class="cert__flourish" viewBox="0 0 120 9" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" aria-hidden="true">
                <path d="M2 5h38c6 0 6-3 11-3s5 3 9 3 4-3 9-3 5 3 11 3h38"/>
                <circle cx="60" cy="5" r="1.4" fill="currentColor" stroke="none"/>
            </svg>

            <h1 class="cert__title">{{ mb_strtoupper(__('Certificate')) }}</h1>
            <p class="cert__sub">{{ mb_strtoupper(__('of Authenticity')) }}</p>
            <hr class="cert__rule">
            <p class="cert__intro">{{ __('This is to certify that the jewelry described below has been examined and tested.') }}</p>

            <div class="cert__body">
                <div class="cert__table">
                    <dl>
                        <div class="cert__row"><dt>{{ __('Item') }}</dt><dd>{{ mb_strtoupper($item->name) }}</dd></div>
                        <div class="cert__row"><dt>{{ __('Metal') }}</dt><dd>{{ trim($metal.' '.$purity) }}</dd></div>
                        <div class="cert__row"><dt>{{ mb_strtoupper($item->metalType?->name ?? __('Metal')) }} {{ __('WEIGHT') }}</dt><dd>{{ $grams($item->net_weight) }}</dd></div>
                        @foreach($stoneRows as $row)
                            <div class="cert__row"><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>
                        @endforeach
                        <div class="cert__row"><dt>{{ __('TOTAL WEIGHT') }}</dt><dd>{{ $grams($item->gross_weight) }}{{ $stoneRows->isNotEmpty() ? '*' : '' }}</dd></div>
                    </dl>
                    @if($stoneRows->isNotEmpty())
                        <p class="cert__note">*{{ __('Total weight includes gemstones') }}.</p>
                    @endif
                </div>
                <div class="cert__photo">
                    @if($photoUrl)
                        <img src="{{ $photoUrl }}" alt="">
                    @endif
                </div>
            </div>

            <div class="cert__bottom">
                <p class="cert__guarantee">{{ __('This certificate guarantees the authenticity and quality of the materials used in the jewelry described herein.') }}</p>
                @if($qrUri !== '')
                    <div class="cert__qr">
                        <img src="{{ $qrUri }}" alt="">
                        <span>{{ __('Scan to verify') }}</span>
                    </div>
                @endif
                <div class="cert__seal">
                    <img src="{{ brand_logo_url() }}" alt="{{ $brand }}">
                    <span class="cert__for">{{ __('For') }} {{ $brand }}</span>
                </div>
            </div>

            <div class="cert__thanks">{{ __('Thank you for your trust') }}</div>
        </div>
    </article>

    <script>
        // Opens the print dialog straight away; "Save as PDF" there downloads it.
        // Add ?preview=1 to look at the certificate without printing.
        if (!new URLSearchParams(location.search).has('preview')) {
            window.addEventListener('load', () => setTimeout(() => window.print(), 600));
        }
    </script>
</body>
</html>
