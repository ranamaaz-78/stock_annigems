{{--
    Printable quotation.

    Standalone page (no app shell) loading app.scss only, so all layout comes
    from the .doc-page / .sheet design-system classes. The sheet is the shared
    partial, identical to the in-app detail screen.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Quotation') }} {{ $quotation->quotation_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--{{ branch_setting('quotation_print_format', $quotation->branch_id, 'a4') }}">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @include('branch.quotations._sheet', ['quotation' => $quotation, 'a4' => true])
</body>
</html>
