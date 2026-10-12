{{--
    Chart dispatcher.

    A report or dashboard hands over whatever chart its service produced and
    this picks the right presentation from the chart's own `orientation` key.
    Callers therefore never branch on chart type themselves, and adding a third
    orientation later touches this file only.
--}}
@props([
    'chart' => null,
])

@if($chart)
    @if(($chart['orientation'] ?? 'horizontal') === 'vertical')
        <x-report.column-chart :chart="$chart" {{ $attributes }} />
    @else
        <x-report.bar-chart :chart="$chart" {{ $attributes }} />
    @endif
@endif
