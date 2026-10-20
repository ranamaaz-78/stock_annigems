{{--
    Printable performa. Design: "Printable designs" → Proforma.

    Quantities and prices. Prices are always printed; only an old performa issued
    before prices were kept (every line at zero) prints without the price
    columns. A performa is a credit memo, so besides what was issued it shows
    what was paid for, returned and is still out.
--}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Performa') }} {{ $performa->performa_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--a4">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @include('branch.performas._sheet', ['performa' => $performa, 'a4' => true])
</body>
</html>
