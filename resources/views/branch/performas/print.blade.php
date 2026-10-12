{{--
    Printable performa — quantities and prices. Prices are always printed; only
    an old performa issued before prices were kept (every line at zero) prints
    without the price columns. Same sheet design as the other documents.
--}}
@php
    $showPrices = $performa->items->contains(fn ($item) => (float) $item->unit_price > 0);

    $issued = (int) $performa->items->sum('quantity_issued');
    $settled = (int) $performa->items->sum('quantity_settled');
    $returned = (int) $performa->items->sum('quantity_returned');
    $outstanding = (int) $performa->items->sum(fn ($item) => $item->outstandingQuantity());

    $valueIssued = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->quantity_issued));
    $valueSettled = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->quantity_settled));
    $valueReturned = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->quantity_returned));
    $valueOutstanding = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->outstandingQuantity()));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Performa {{ $performa->performa_number }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.scss'])
</head>
<body class="doc-page doc-page--a4">
    <div class="doc-page__toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print</button>
    </div>

    <x-document.sheet doc-type="Performa" :number="$performa->performa_number" :branch="$performa->branch" :a4="true">
        <x-slot:meta>
            <x-document.meta-row label="Issue date">{{ setting_date($performa->performa_date) }}</x-document.meta-row>
            <x-document.meta-row label="Issued by">{{ $performa->creator?->name ?? '—' }}</x-document.meta-row>
            <x-document.meta-row label="Status">{{ $performa->status->label() }}</x-document.meta-row>
        </x-slot:meta>

        <x-slot:parties>
            <x-document.party label="Issued to">
                <x-slot:name><p class="sheet__party-name">{{ $performa->displayCreditorName() }}</p></x-slot:name>
                <p class="sheet__party-lines">
                    {{ $performa->creditor?->phone ?: '—' }}
                    @if($performa->creditor?->email)
                        <br>{{ $performa->creditor->email }}
                    @endif
                </p>
            </x-document.party>
        </x-slot:parties>

        <x-slot:extras>
            <div class="sheet__stats">
                <div class="sheet__stat"><span class="sheet__meta-label">Issued</span><strong>{{ $issued }}</strong></div>
                <div class="sheet__stat"><span class="sheet__meta-label">Settled</span><strong>{{ $settled }}</strong></div>
                <div class="sheet__stat"><span class="sheet__meta-label">Returned</span><strong>{{ $returned }}</strong></div>
                <div class="sheet__stat sheet__stat--accent"><span class="sheet__meta-label">Outstanding</span><strong>{{ $outstanding }}</strong></div>
            </div>
        </x-slot:extras>

        <x-slot:items>
            <table class="sheet-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="sheet-table__num">Issued</th>
                        <th class="sheet-table__num">Settled</th>
                        <th class="sheet-table__num">Returned</th>
                        <th class="sheet-table__num">{{ $showPrices ? 'Out' : 'Outstanding' }}</th>
                        @if($showPrices)
                            <th class="sheet-table__num">Price</th>
                            <th class="sheet-table__num">Amount</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($performa->items as $item)
                        @php
                            $isStone = $item->line_type === \App\Enums\LineType::LOOSE_STONE;
                            $detail = $isStone
                                ? 'Loose stone'
                                : trim(($item->purity_snapshot ?? '').' '.($item->metal_snapshot ?? '')
                                    .($item->net_weight_snapshot ? ' · '.number_format($item->net_weight_snapshot, 3).' g' : ''));
                            $priced = (float) $item->unit_price > 0;
                        @endphp
                        <tr>
                            <td>
                                <span class="sheet-table__code">{{ $item->item_code_snapshot }}</span>
                                <span class="sheet-table__name">{{ $item->name_snapshot }}</span>
                                @if($detail !== '')
                                    <span class="sheet-table__sub">{{ $detail }}</span>
                                @endif
                            </td>
                            <td class="sheet-table__num">{{ $item->quantity_issued }}</td>
                            <td class="sheet-table__num">{{ $item->quantity_settled }}</td>
                            <td class="sheet-table__num">{{ $item->quantity_returned }}</td>
                            <td class="sheet-table__total">{{ $item->outstandingQuantity() }}</td>
                            @if($showPrices)
                                <td class="sheet-table__num">{{ $priced ? setting_amount($item->unit_price) : '—' }}</td>
                                <td class="sheet-table__total">{{ $priced ? setting_amount($item->valueOf($item->quantity_issued)) : '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-slot:items>

        <x-slot:terms>
            <p class="sheet__terms-label">Performa terms</p>
            <p class="sheet__terms">Goods listed here have left stock on credit. Payment is recorded on a separate invoice; unpaid goods are returned against this number.</p>
            @if($performa->notes)
                <p class="sheet__terms-label" style="margin-top: 8px">Notes</p>
                <p class="sheet__terms">{{ $performa->notes }}</p>
            @endif
        </x-slot:terms>

        @if($showPrices)
            <x-slot:totals>
                <div class="sheet-totals">
                    <div class="sheet-totals__row"><span class="sheet-totals__label">Value issued</span><span class="sheet-totals__value">{{ setting_amount($valueIssued) }}</span></div>
                    <div class="sheet-totals__row"><span class="sheet-totals__label">Settled</span><span class="sheet-totals__value">−{{ setting_amount($valueSettled) }}</span></div>
                    <div class="sheet-totals__row"><span class="sheet-totals__label">Returned</span><span class="sheet-totals__value">−{{ setting_amount($valueReturned) }}</span></div>
                    <div class="sheet-totals__grand">
                        <span class="sheet-totals__grand-label">Outstanding</span>
                        <span class="sheet-totals__grand-value">{{ setting_money($valueOutstanding) }}</span>
                    </div>
                </div>
            </x-slot:totals>
        @endif

        <x-slot:footer>
            <p class="sheet__footer-title">Performa {{ $performa->performa_number }}</p>
            <p class="sheet__footer-text">Goods on memo — not a tax invoice.</p>
        </x-slot:footer>
    </x-document.sheet>
</body>
</html>
