{{-- A4 code sheet. Stones repeat one packet barcode; jewellery packs unique pieces. --}}
@php
    $copies = max(1, (int) ($copies ?? 1));
    $columns = max(1, (int) ($columns ?? 3));
    $labelSize = $labelSize ?? 'medium';
    $kind = $kind ?? 'barcode';
    $layout = $layout ?? 'repeat';
    $uuids = $uuids ?? [];
    $isBulk = count($uuids) > 1;
    $applyUrl = $applyUrl ?? url()->current();
    $documentTitle = $documentTitle ?? __('Labels');
    $isUnique = $layout === 'unique';
    $brand = (string) setting('company_name', 'Aani Gemas');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <title>{{ $documentTitle }} — {{ setting('company_name', 'Aani Gemas') }}</title>
    <x-brand.favicon />
    @vite(['resources/css/tailwind.css', 'resources/css/app.scss'])
</head>
<body class="stone-label-print">
    <form method="GET" action="{{ $applyUrl }}" class="no-print stone-label-toolbar">
        @if($isBulk)
            @foreach($uuids as $uuid)
                <input type="hidden" name="uuids[]" value="{{ $uuid }}">
            @endforeach
        @endif
        @unless($isUnique)
            <label class="form-label" for="label-copies">{{ __('Copies') }}</label>
            <input
                id="label-copies"
                class="input-field"
                type="number"
                name="copies"
                min="1"
                max="48"
                value="{{ $copies }}"
            >
            <x-btn type="submit" variant="secondary">{{ __('Apply') }}</x-btn>
        @endunless
        <x-btn type="button" onclick="window.print()">{{ __('Print') }}</x-btn>
        <x-btn type="button" variant="secondary" onclick="window.close()">{{ __('Close') }}</x-btn>
    </form>

    @if($isUnique)
        <section class="stone-label-page" style="--stone-label-cols: {{ $columns }}">
            <div class="stone-label-grid">
                @foreach($sheets as $sheet)
                    <div class="stone-label-cell stone-label-cell--{{ $labelSize }} @if($kind === 'qr') stone-label-cell--qr @endif @if($kind === 'both') stone-label-cell--both @endif">
                        <p class="stone-label-cell__brand">{{ $brand }}</p>
                        <p class="stone-label-cell__name">{{ $sheet['name'] }}</p>
                        <div class="stone-label-cell__codes">
                            @if(($sheet['barcodeUri'] ?? '') !== '')
                                <img class="stone-label-cell__barcode" src="{{ $sheet['barcodeUri'] }}" alt="">
                            @endif
                            @if(($sheet['qrUri'] ?? '') !== '')
                                <img class="stone-label-cell__qr" src="{{ $sheet['qrUri'] }}" alt="">
                            @endif
                        </div>
                        <p class="stone-label-cell__code">{{ $sheet['code'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>
    @else
        @foreach($sheets as $sheet)
            <section class="stone-label-page" style="--stone-label-cols: {{ $columns }}">
                <h1 class="stone-label-page__title">{{ $sheet['name'] }}</h1>
                <div class="stone-label-grid">
                    @for($i = 0; $i < $copies; $i++)
                        <div class="stone-label-cell stone-label-cell--{{ $labelSize }}">
                            <p class="stone-label-cell__brand">{{ $brand }}</p>
                            @if(($sheet['barcodeUri'] ?? $sheet['imageUri'] ?? '') !== '')
                                <img src="{{ $sheet['barcodeUri'] ?? $sheet['imageUri'] }}" alt="">
                            @endif
                            <p class="stone-label-cell__code">{{ $sheet['code'] }}</p>
                        </div>
                    @endfor
                </div>
            </section>
        @endforeach
    @endif
</body>
</html>
