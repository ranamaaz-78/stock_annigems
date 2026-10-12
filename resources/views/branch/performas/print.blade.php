{{--
    Printable performa — quantity memo, no money.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Performa {{ $performa->performa_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--a4">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print</button>
    </div>

    <x-document.sheet doc-type="Performa" :branch="$performa->branch" :a4="true" outlined-type>
        <x-slot:meta>
            <x-document.meta-row label="Performa No">{{ $performa->performa_number }}</x-document.meta-row>
            <x-document.meta-row label="Date">{{ setting_date($performa->performa_date) }}</x-document.meta-row>
            <x-document.meta-row label="Status">{{ $performa->status->label() }}</x-document.meta-row>
        </x-slot:meta>

        <x-slot:parties>
            <x-document.party label="Issued to">
                <p class="sheet__party-name">{{ $performa->displayCreditorName() }}</p>
                <p class="sheet__party-lines">
                    {{ $performa->creditor?->phone ?: '—' }}
                    @if($performa->creditor?->email)
                        <br>{{ $performa->creditor->email }}
                    @endif
                </p>
            </x-document.party>
            <x-document.party label="Issued by" divided>
                <p class="sheet__party-name">{{ $performa->creator?->name ?? '—' }}</p>
                <p class="sheet__party-lines">Quantity memo — not a tax invoice. Prices are confirmed on payment.</p>
            </x-document.party>
        </x-slot:parties>

        <x-slot:items>
            <table class="table-admin">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Code</th>
                        <th>Issued</th>
                        <th>Settled</th>
                        <th>Returned</th>
                        <th>Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($performa->items as $item)
                        <tr>
                            <td>{{ $item->name_snapshot }}</td>
                            <td>{{ $item->item_code_snapshot }}</td>
                            <td>{{ $item->quantity_issued }}</td>
                            <td>{{ $item->quantity_settled }}</td>
                            <td>{{ $item->quantity_returned }}</td>
                            <td>{{ $item->outstandingQuantity() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-slot:items>

        <x-slot:totals></x-slot:totals>

        <x-slot:footer>
            <div>
                <p class="sheet__terms-label">Performa terms</p>
                <p class="sheet__terms">Goods listed here have left stock on credit. This is not a sale. Payment is recorded on a separate invoice.</p>
                @if($performa->notes)
                    <p class="sheet__terms"><strong>Notes:</strong> {{ $performa->notes }}</p>
                @endif
            </div>
        </x-slot:footer>
    </x-document.sheet>
</body>
</html>
