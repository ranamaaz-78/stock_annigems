{{--
    Printable invoice — design/invoice_view.

    Standalone page (no app shell) that loads app.scss only, so all layout
    comes from the .doc-page / .sheet design-system classes. The sheet itself is
    the shared x-document.sheet component, identical to the in-app detail view.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@100..900&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--{{ branch_setting('invoice_print_format', $invoice->branch_id, 'a4') }}">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print</button>
    </div>

    @include('branch.invoices._sheet', ['invoice' => $invoice, 'a4' => true])
</body>
</html>
