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
    $documentTitle = $documentTitle ?? 'Labels';
    $isUnique = $layout === 'unique';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }} — {{ setting('company_name', 'Aanigemas') }}</title>
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
            <label class="form-label" for="label-copies">Copies</label>
            <input
                id="label-copies"
                class="input-field"
                type="number"
                name="copies"
                min="1"
                max="48"
                value="{{ $copies }}"
            >
            <x-btn type="submit" variant="secondary">Apply</x-btn>
        @endunless
        <x-btn type="button" onclick="window.print()">Print</x-btn>
        <x-btn type="button" variant="secondary" onclick="window.close()">Close</x-btn>
    </form>

    @if($isUnique)
        <section class="stone-label-page" style="--stone-label-cols: {{ $columns }}">
            <div class="stone-label-grid">
                @foreach($sheets as $sheet)
                    <div class="stone-label-cell stone-label-cell--{{ $labelSize }} @if($kind === 'qr') stone-label-cell--qr @endif @if($kind === 'both') stone-label-cell--both @endif">
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
