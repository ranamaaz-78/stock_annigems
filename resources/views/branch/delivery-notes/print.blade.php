{{--
    Printable delivery note (albarán).

    Standalone page like the invoice print view, but the sheet carries only the
    client's details. The tab title names the document, never the shop.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" translate="no">
<head>
    <meta charset="utf-8">
    <meta name="google" content="notranslate">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Albarán') }} {{ $note->delivery_note_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--a4">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @include('branch.delivery-notes._sheet', ['note' => $note, 'a4' => true])
</body>
</html>