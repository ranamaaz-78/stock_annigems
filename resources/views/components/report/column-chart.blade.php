{{--
    Vertical CSS column chart (SCSS: .chart-columns).

    Used for time-series trends where a horizontal bar list would read poorly —
    the daily sales trend on the branch dashboard and the sales summary report.
    Same contract as x-report.bar-chart: percentages are already computed by
    ReportResult::chart().
--}}
@props([
    'chart',
])

@php
    $points = $chart['points'] ?? [];
@endphp

<div {{ $attributes->merge(['class' => 'panel']) }}>
    <div class="panel-header">
        <h3 class="panel-header__title text-headline-sm">{{ $chart['title'] }}</h3>
    </div>

    <div class="p-4">
        @if(empty($points))
            <x-empty-state
                title="No activity in this period"
                message="Once sales are recorded in the selected range they will appear here."
                icon="show_chart"
            />
        @else
            @if(! empty($chart['unit']))
                <p class="chart-unit">Measured in {{ $chart['unit'] }}.</p>
            @endif

            <div class="chart-columns">
                @foreach($points as $point)
                    <div class="chart-column">
                        <div
                            class="chart-column__track"
                            role="img"
                            aria-label="{{ $point['label'] }}: {{ $point['display'] }}"
                            title="{{ $point['label'] }}: {{ $point['display'] }}"
                        >
                            <div class="chart-column__fill" style="--bar-size: {{ $point['percent'] }}%"></div>
                        </div>
                        <span class="chart-column__label">{{ $point['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
