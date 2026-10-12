{{--
    Itemised document table: ITEM / WEIGHT / METAL / STONES / MAKING / AMOUNT.

    Works for QuotationItem, InvoiceItem and DeliveryNoteItem collections
    because the line snapshot columns are identical on each model. A line shows
    its code as a tag, the metal working under the name, and each set stone as
    a chip; pass :making="false" to drop the metal/stones/making split (used by
    the delivery note, which shows one price per line).
--}}
@props(['items', 'split' => true])

<table class="sheet-table">
    <thead>
        <tr>
            <th>Item</th>
            @if($split)
                <th class="sheet-table__num">Weight</th>
                <th class="sheet-table__num">Metal</th>
                <th class="sheet-table__num">Stones</th>
                <th class="sheet-table__num">Making</th>
            @else
                <th class="sheet-table__num">Qty / weight</th>
                <th class="sheet-table__num">Price</th>
            @endif
            <th class="sheet-table__num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $item)
            @php
                $isLooseStone = $item->line_type === \App\Enums\LineType::LOOSE_STONE;
                $weight = $item->net_weight_snapshot ? number_format($item->net_weight_snapshot, 3).' g' : null;
                $rate = (float) $item->gold_rate_applied;
                $metalName = trim(($item->purity_snapshot ?? '').' '.($item->metal_snapshot ?? ''));
            @endphp
            <tr>
                <td>
                    <span class="sheet-table__code">{{ $item->item_code_snapshot }}</span>
                    <span class="sheet-table__name">{{ $item->name_snapshot }}</span>
                    @if($isLooseStone)
                        <span class="sheet-table__sub">Loose stone · {{ $item->quantity }} {{ $item->quantity == 1 ? 'pc' : 'pcs' }}</span>
                    @elseif($metalName !== '')
                        <span class="sheet-table__sub">
                            {{ $metalName }}@if($weight && $rate > 0) · {{ $weight }} × {{ setting_amount($rate) }} per g @endif
                        </span>
                    @endif

                    {{-- Set stones are priced inside the line, so they are listed
                         here as chips rather than as separate rows. Each fragment
                         is omitted when unknown, so a legacy row with no snapshot
                         prints "× 2" instead of a misleading "0.00ct". --}}
                    @if($item->itemStones->isNotEmpty())
                        <span class="sheet-table__chips">
                            @foreach($item->itemStones as $stone)
                                @php
                                    $stoneParts = array_filter([
                                        $stone->stone_name_snapshot ?: $stone->stone_type_snapshot,
                                        (float) $stone->carat_snapshot > 0 ? number_format($stone->carat_snapshot, 2).' ct' : null,
                                    ]);
                                @endphp
                                <span class="sheet-table__chip">{{ implode(' ', $stoneParts) }} &times; {{ $stone->quantity }}</span>
                            @endforeach
                        </span>
                    @endif
                </td>
                @if($split)
                    <td class="sheet-table__num">{{ $weight ?? '—' }}</td>
                    <td class="sheet-table__num">{{ $isLooseStone ? '—' : setting_amount($item->gold_value) }}</td>
                    <td class="sheet-table__num">{{ $isLooseStone || (float) $item->stone_value > 0 ? setting_amount($item->stone_value) : '—' }}</td>
                    <td class="sheet-table__num">{{ (float) $item->making_charges > 0 ? setting_amount($item->making_charges) : '—' }}</td>
                @else
                    <td class="sheet-table__num">
                        {{ $isLooseStone ? $item->quantity.($item->quantity == 1 ? ' pc' : ' pcs') : '1 pc'.($weight ? ' · '.$weight : '') }}
                    </td>
                    <td class="sheet-table__num">{{ setting_amount($isLooseStone && $item->quantity > 0 ? (float) $item->line_total / $item->quantity : $item->line_total) }}</td>
                @endif
                <td class="sheet-table__total">{{ setting_amount($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>