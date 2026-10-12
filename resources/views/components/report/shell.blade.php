{{--
    Report shell — one structure for every report screen.

    Order is fixed so the twenty-odd report pages are learned once: page header
    with export actions, family tabs, revenue notice, filter bar, self-describing
    meta strip, KPI cards, chart, then the detail table(s).

    A page supplies its filter controls through the `filters` slot and may append
    extra tables through the default slot. Everything else is driven by the
    ReportResult, which means a card and its report can never disagree.
--}}
@props([
    // App\Support\Reports\ReportResult
    'result',
    // list<array{key,label,url}> — already permission-filtered by the controller
    'tabs' => [],
    'activeTab' => null,
    // Named export route, or null when the actor may not export.
    'exportRoute' => null,
    'exportQuery' => [],
    'canExport' => false,
    // Money reports must carry the revenue-not-profit notice.
    'showRevenueNotice' => false,
    'subtitle' => null,
])

<x-page-header :title="$result->title" :subtitle="$subtitle">
    @if($exportRoute)
        <x-slot:actions>
            <x-report.export-actions
                :export-route="$exportRoute"
                :query="$exportQuery"
                :can-export="$canExport"
            />
        </x-slot:actions>
    @endif
</x-page-header>

<x-report.tabs :tabs="$tabs" :active="$activeTab" />

@if($showRevenueNotice)
    {{-- Required wording: the system records no purchase cost, so nothing here
         is profit or margin. Stated on screen and repeated on every export. --}}
    <p class="report-notice">
        <span class="material-symbols-outlined icon icon--sm report-notice__icon" aria-hidden="true">info</span>
        <span>
            These are <strong>revenue</strong> figures, not profit. Purchase cost is not recorded by the
            system, so margin cannot be calculated. Voided invoices are excluded from every total below.
        </span>
    </p>
@endif

@isset($filters)
    <form method="GET" class="panel p-4 report-print-hide" style="margin-bottom: var(--gutter)">
        <div class="filter-bar filter-bar--flush">
            {{ $filters }}
        </div>
    </form>
@endisset

{{-- Self-describing header: which branch, which report, which range, run by
     whom. Repeated at the top of every export for the same reason. --}}
<div class="report-meta">
    @foreach($result->meta('header', []) as $item)
        <div class="report-meta__item">
            <span class="report-meta__label">{{ $item['label'] }}</span>
            <span class="report-meta__value">{{ $item['value'] }}</span>
        </div>
    @endforeach
</div>

@if(! empty($result->summary))
    <div class="kpi-grid {{ count($result->summary) === 3 ? 'kpi-grid--3' : '' }}">
        @foreach($result->summary as $card)
            <x-report.kpi
                :label="$card['label']"
                :value="$card['value']"
                :hint="$card['hint'] ?? null"
                :icon="$card['icon'] ?? null"
                :trend="$card['trend'] ?? null"
            />
        @endforeach
    </div>
@endif

@if($result->chart)
    @if(($result->chart['orientation'] ?? 'horizontal') === 'vertical')
        <x-report.column-chart :chart="$result->chart" class="report-section" />
    @else
        <x-report.bar-chart :chart="$result->chart" class="report-section" />
    @endif
@endif

@if(! empty($result->columns))
    <x-report.table
        :columns="$result->columns"
        :rows="$result->rows"
        :totals="$result->totals"
        :title="$result->meta('table_title', 'Breakdown')"
        :empty-title="$result->meta('empty_title', 'No data for this period')"
        :empty-message="$result->meta('empty_message', 'Nothing matched the selected date range and filters.')"
    />
@endif

{{-- Some reports carry a second aggregate below the main table (discount by
     staff, stone totals by type). Declared in meta by the service so the CSV
     export picks them up from the same place. --}}
@foreach($result->meta('extra_tables', []) as $table)
    <x-report.table
        :columns="$table['columns']"
        :rows="$table['rows']"
        :totals="$table['totals'] ?? []"
        :title="$table['title']"
    />
@endforeach

{{-- Extra sections a specific report needs beyond the standard tables. --}}
{{ $slot }}
