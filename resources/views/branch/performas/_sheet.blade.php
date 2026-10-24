{{--
    Performa sheet. Design: "Printable designs" → Proforma.

    Quantities and prices. Prices are always printed; only an old performa issued
    before prices were kept (every line at zero) prints without the price
    columns. A performa is a credit memo, so besides what was issued it shows
    what was paid for, returned and is still out.

    Expects: $performa and $a4 (true only on the standalone print page).
--}}
@php
    $a4 = $a4 ?? false;
    $showPrices = $performa->items->contains(fn ($item) => (float) $item->unit_price > 0);
    $creditor = $performa->creditor;
    $branch = $performa->branch;

    // Worth in each line's own unit: pieces, or grams on a weighed line.
    $valueIssued = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->isWeighed() ? $item->weight_issued : $item->quantity_issued));
    $valueSettled = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->isWeighed() ? $item->weight_settled : $item->quantity_settled));
    $valueReturned = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->isWeighed() ? $item->weight_returned : $item->quantity_returned));
    $valueOutstanding = (float) $performa->items->sum(fn ($item) => $item->valueOf($item->outstandingAmount()));

    $badge = match ($performa->status) {
        \App\Enums\PerformaStatus::SETTLED => 'pd__badge--paid',
        \App\Enums\PerformaStatus::CANCELLED => 'pd__badge--void',
        default => '',
    };
    $terms = __('Goods listed here have left stock on credit. Payment is recorded on a separate invoice; unpaid goods are returned against this number. Not a tax invoice.');
@endphp

<article @class(['pd', 'pd--performa', 'pd--a4' => $a4, 'pd--dense' => $performa->items->count() > 12])>
    <div class="pd__page">
        <span class="pd__bar" aria-hidden="true"></span>

        <header class="pd__head">
            <div class="pd__brand">
                <x-pd.logo />
                <x-pd.company :branch="$branch" />
            </div>
            <div class="pd__title-block">
                <h1 class="pd__title">{{ __('Performa') }}</h1>
                <span @class(['pd__badge', $badge])>{{ $performa->status->label() }}</span>
                <p class="pd__tagline">{{ __('Not a tax invoice') }}</p>
            </div>
        </header>

        <section class="pd__row">
            <div class="pd__party">
                <p class="pd__label">{{ __('Prepared for') }}</p>
                <p class="pd__party-name">{{ $performa->displayCreditorName() }}</p>
                <p class="pd__party-lines">
                    @if($creditor?->nie)<span>N.I.F / N.I.E {{ $creditor->nie }}</span>@endif
                    @if($creditor?->phone)<span>{{ $creditor->phone }}</span>@endif
                    @if($creditor?->email)<span>{{ $creditor->email }}</span>@endif
                    @if($creditor?->address)<span>{{ $creditor->address }}</span>@endif
                </p>
            </div>
            <dl class="pd__facts">
                <dt>{{ __('Performa no.') }}</dt><dd class="is-strong">{{ $performa->performa_number }}</dd>
                <dt>{{ __('Issue date') }}</dt><dd>{{ setting_date($performa->performa_date) }}</dd>
                <dt>{{ __('Issued by') }}</dt><dd>{{ $performa->creator?->name ?? '—' }}</dd>
                @if($showPrices)
                    <dt>{{ __('Balance due') }}</dt><dd class="is-strong">{{ setting_money($valueOutstanding) }}</dd>
                @endif
            </dl>
        </section>

        <div class="pd__table-wrap">
            <table class="pd-table">
                <colgroup>
                    <col style="width: 108px">
                    <col>
                    <col style="width: 58px">
                    <col style="width: 50px">
                    <col style="width: 70px">
                    <col style="width: 48px">
                    @if($showPrices)
                        <col style="width: 78px">
                        <col style="width: 88px">
                    @endif
                </colgroup>
                <thead>
                    <tr>
                        <th>{{ __('Code') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th>{{ __('Issued') }}</th>
                        <th>{{ __('Paid') }}</th>
                        <th>{{ __('Returned') }}</th>
                        <th>{{ __('Out') }}</th>
                        @if($showPrices)
                            <th>{{ __('Price') }}</th>
                            <th>{{ __('Amount') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($performa->items as $i => $item)
                        @php
                            $isStone = $item->line_type === \App\Enums\LineType::LOOSE_STONE;
                            $detail = $isStone
                                ? null
                                : trim(($item->purity_snapshot ?? '').' '.($item->metal_snapshot ?? '')
                                    .($item->net_weight_snapshot ? ' · '.number_format($item->net_weight_snapshot, 3).' g' : ''));
                            $sub = (string) $detail;
                            $priced = (float) $item->unit_price > 0;
                        @endphp
                        <tr>
                            <td class="pd-table__code">{{ $item->item_code_snapshot }}</td>
                            <td class="pd-table__desc">
                                <span class="pd-line__name">{{ $item->name_snapshot }}</span>
                                @if($sub !== '')<span class="pd-line__sub">{{ $sub }}</span>@endif
                                @if($isStone && $item->stone && $item->stone->detailBadges(withPricing: false) !== [])
                                    <span class="pd-line__chips">
                                        @foreach($item->stone->detailBadges(withPricing: false) as $badge)<span class="pd-line__chip">{{ $badge }}</span>@endforeach
                                    </span>
                                @endif
                            </td>
                            <td>{{ $item->isWeighed() ? $item->amountLabel($item->weight_issued) : $item->quantity_issued }}</td>
                            <td>{{ $item->isWeighed() ? $item->amountLabel($item->weight_settled) : $item->quantity_settled }}</td>
                            <td>{{ $item->isWeighed() ? $item->amountLabel($item->weight_returned) : $item->quantity_returned }}</td>
                            <td class="is-bold">{{ $item->amountLabel($item->outstandingAmount()) }}</td>
                            @if($showPrices)
                                <td>{{ $priced ? setting_money($item->unit_price).($item->isWeighed() ? ' / '.$item->weightUnit() : '') : '—' }}</td>
                                <td class="is-strong">{{ $priced ? setting_money($item->valueOf($item->isWeighed() ? $item->weight_issued : $item->quantity_issued)) : '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($showPrices)
            <section class="pd__sums">
                <p class="pd__note-small">{{ __('Prices are the amounts agreed with the customer for this shipment. Taxes are not calculated on this document.') }}</p>
                <div class="pd__totals">
                    <div class="pd__total-row"><span>{{ __('Value issued') }}</span><span>{{ setting_money($valueIssued) }}</span></div>
                    <div class="pd__total-row"><span>{{ __('Paid for') }}</span><span>−{{ setting_money($valueSettled) }}</span></div>
                    <div class="pd__total-row"><span>{{ __('Returned') }}</span><span>−{{ setting_money($valueReturned) }}</span></div>
                    <div class="pd__grand">
                        <span class="pd__grand-label">{{ __('Outstanding') }}</span>
                        <span class="pd__grand-value">{{ setting_money($valueOutstanding) }}</span>
                    </div>
                </div>
            </section>
        @endif

        <x-pd.notes :notes="$performa->notes" :terms="$terms" />

        <footer class="pd__footer pd__footer--rule">
            <span>{{ setting('company_name', config('annigems.documents.brand_name')) }}@if($branch?->email) · {{ $branch->email }}@endif @if($branch?->contact_number) · {{ $branch->contact_number }}@endif</span>
            <span>{{ $performa->performa_number }}</span>
        </footer>
    </div>
</article>
