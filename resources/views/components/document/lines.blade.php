{{--
    Itemised document table — design/invoice_view column set:
    CODE / DESCRIPTION / WEIGHT / GOLD + STONE / MAKING / TOTAL.

    Works for both QuotationItem and InvoiceItem collections because the line
    snapshot columns are identical on each model.
--}}
@props(['items'])

<table class="sheet-table">
    <thead>
        <tr>
            <th>Code</th>
            <th>Description</th>
            <th class="sheet-table__num">Weight</th>
            <th class="sheet-table__num">Gold / Stone</th>
            <th class="sheet-table__num">Making</th>
            <th class="sheet-table__num">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            @php
                // Loose stones carry no gold value, so the design's combined
                // metal/stone column is the sum of whichever applies.
                $materialValue = (float) $item->gold_value + (float) $item->stone_value;
                $isLooseStone = $item->line_type === \App\Enums\LineType::LOOSE_STONE;
            @endphp
            <tr>
                <td>{{ $item->item_code_snapshot }}</td>
                <td class="sheet-table__desc">
                    {{ $item->name_snapshot }}
                    @if($isLooseStone)
                        <span class="sheet-table__sub">Qty {{ $item->quantity }}</span>
                    @elseif($item->purity_snapshot)
                        <span class="sheet-table__sub">{{ $item->purity_snapshot }}</span>
                    @endif

                    {{-- Set stones are priced inside the line, so they are listed
                         here rather than as separate rows. Each fragment is
                         omitted when unknown, so a legacy row with no snapshot
                         prints "× 2" instead of a misleading "0.00ct". --}}
                    @foreach($item->itemStones as $stone)
                        @php
                            $stoneParts = array_filter([
                                $stone->stone_name_snapshot ?: $stone->stone_type_snapshot,
                                (float) $stone->carat_snapshot > 0 ? number_format($stone->carat_snapshot, 2).'ct' : null,
                            ]);
                        @endphp
                        <span class="sheet-table__sub">
                            {{ implode(' · ', $stoneParts) }} &times; {{ $stone->quantity }}
                        </span>
                    @endforeach
                </td>
                <td class="sheet-table__muted">
                    {{ $item->net_weight_snapshot ? number_format($item->net_weight_snapshot, 3).'g' : '—' }}
                </td>
                <td class="sheet-table__num">{{ setting_amount($materialValue) }}</td>
                <td class="sheet-table__num">{{ setting_amount($item->making_charges) }}</td>
                <td class="sheet-table__total">{{ setting_amount($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
