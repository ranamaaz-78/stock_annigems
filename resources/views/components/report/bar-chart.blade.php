{{--
    Horizontal CSS bar chart (SCSS: .chart-bars).

    Consumes the chart array built by ReportResult::chart(), which has already
    scaled each point to a percentage of the largest value. Bar length is passed
    to CSS as the --bar-size custom property so no arbitrary value or colour
    lands in this template.
--}}
@props([
    // ['title' => …, 'unit' => …, 'points' => [['label','value','display','percent'], …]]
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
            {{-- A period with no activity is a normal result, not a failure. --}}
            <x-empty-state
                :title="__('Nothing to chart')"
                :message="__('There was no activity in the selected period.')"
                icon="bar_chart"
            />
        @else
            @if(! empty($chart['unit']))
                <p class="chart-unit">{{ __('Measured in :unit.', ['unit' => $chart['unit']]) }}</p>
            @endif

            <div class="chart-bars">
                @foreach($points as $point)
                    <div>
                        <div class="chart-bar__head">
                            <span class="chart-bar__label">{{ $point['label'] }}</span>
                            <span class="chart-bar__value">{{ $point['display'] }}</span>
                        </div>
                        <div
                            class="chart-bar__track"
                            role="img"
                            aria-label="{{ $point['label'] }}: {{ $point['display'] }}"
                        >
                            <div
                                class="chart-bar__fill {{ $point['percent'] > 0 ? '' : 'chart-bar__fill--empty' }}"
                                style="--bar-size: {{ $point['percent'] }}%"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
