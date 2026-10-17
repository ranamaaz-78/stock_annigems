{{--
    Itemised lines of a printed document.

    Works for QuotationItem, InvoiceItem and DeliveryNoteItem: the snapshot
    columns are identical. Modes: invoice, quotation (accent header, stripes,
    net and with-IVA columns), delivery (dashed rows, no tax). A jewellery piece
    is one line (qty 1, its price the whole piece) with the metal working and
    set stones under the name; a stone sold by weight shows its grams as the
    quantity and its rate per gram as the price.
--}}
@props(['items', 'document', 'mode' => 'invoice'])

@php
    $rate = (float) ($document->tax_rate ?? 0);
    $hasTax = $mode !== 'delivery' && (filled($document->tax_name ?? null) || $rate > 0);
    // A per-line "with IVA" figure only adds up when no document discount sits in between.
    $withIva = $mode === 'quotation' && $hasTax && (float) $document->discount_amount <= 0;
    $pct = rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';

    $rows = collect($items)->map(function ($item) {
        $loose = $item->line_type === \App\Enums\LineType::LOOSE_STONE;
        $grams = $loose ? (float) ($item->weight_grams ?? 0) : 0.0;
        $gramText = rtrim(rtrim(number_format($grams, 3, '.', ''), '0'), '.').' g';
        $weight = $item->net_weight_snapshot ? number_format($item->net_weight_snapshot, 3).' g' : null;
        $goldRate = (float) $item->gold_rate_applied;
        $metal = trim(($item->purity_snapshot ?? '').' '.($item->metal_snapshot ?? ''));

        if ($grams > 0) {
            $qty = $gramText;
            $price = (float) $item->stone_value / $grams;
            $detail = 'Loose stone · per gram';
        } elseif ($loose) {
            $qty = (string) $item->quantity;
            $price = $item->quantity > 0 ? (float) $item->line_total / $item->quantity : (float) $item->line_total;
            $detail = 'Loose stone';
        } else {
            $qty = '1';
            $price = (float) $item->line_total;
            $detail = trim($metal.($weight && $goldRate > 0 ? ' · '.$weight.' × '.setting_amount($goldRate).' per g' : ($weight ? ' · '.$weight : '')));
        }

        return [
            'item' => $item,
            'qty' => $qty,
            'price' => $price,
            'sub' => collect([$item->item_code_snapshot, $detail])->filter()->implode(' · '),
        ];
    });
@endphp

<table @class(['pd-table', 'pd-table--accent' => $mode === 'quotation', 'pd-table--dashed' => $mode === 'delivery'])>
    <colgroup>
        <col style="width: {{ $mode === 'quotation' ? 40 : 28 }}px">
        <col>
        <col style="width: {{ $mode === 'delivery' ? 64 : 56 }}px">
        <col style="width: {{ $mode === 'delivery' ? 92 : 88 }}px">
        @if($hasTax)<col style="width: 46px">@endif
        <col style="width: {{ $mode === 'delivery' ? 100 : 96 }}px">
        @if($withIva)<col style="width: 96px">@endif
    </colgroup>
    <thead>
        <tr>
            <th>#</th>
            <th>Description</th>
            <th>Qty</th>
            <th>Price</th>
            @if($hasTax)<th>IVA</th>@endif
            <th>{{ $mode === 'quotation' && $withIva ? 'Net' : 'Amount' }}</th>
            @if($withIva)<th>With IVA</th>@endif
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $i => $row)
            @php $item = $row['item']; @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="pd-table__desc">
                    <span class="pd-line__name">{{ $item->name_snapshot }}</span>
                    @if($row['sub'] !== '')
                        <span class="pd-line__sub">{{ $row['sub'] }}</span>
                    @endif
                    {{-- Set stones are priced inside the line, so they are chips, not rows. --}}
                    @if($item->itemStones->isNotEmpty())
                        <span class="pd-line__chips">
                            @foreach($item->itemStones as $stone)
                                @php
                                    $parts = array_filter([
                                        $stone->stone_name_snapshot ?: $stone->stone_type_snapshot,
                                        (float) $stone->carat_snapshot > 0 ? number_format($stone->carat_snapshot, 2).' ct' : null,
                                    ]);
                                @endphp
                                <span class="pd-line__chip">{{ implode(' ', $parts) }} &times; {{ $stone->quantity }}</span>
                            @endforeach
                        </span>
                    @endif
                </td>
                <td @class(['is-bold' => $mode === 'delivery'])>{{ $row['qty'] }}</td>
                <td>{{ setting_money($row['price']) }}</td>
                @if($hasTax)<td>{{ $pct }}</td>@endif
                <td class="is-strong">{{ setting_money($item->line_total) }}</td>
                @if($withIva)
                    <td class="is-strong">{{ setting_money((float) $item->line_total * (1 + $rate / 100)) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
