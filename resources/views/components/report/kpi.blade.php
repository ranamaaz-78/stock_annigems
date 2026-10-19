{{--
    Report / dashboard KPI card (SCSS: .kpi-card).

    Takes one entry from a ReportResult summary array. Every figure is
    pre-formatted by ReportFormatter, so this component performs no arithmetic
    and applies no currency symbol of its own.
--}}
@props([
    'label',
    'value',
    'hint' => null,
    'icon' => null,
    // ['direction' => 'up'|'down'|'flat', 'label' => '+12.5%'] or null when the
    // previous period was zero and a percentage would be meaningless.
    'trend' => null,
    'trendCaption' => null,
])

@php
    // Direction maps to both a modifier class and an arrow glyph. Kept in PHP
    // so the markup below stays readable.
    $trendDirection = $trend['direction'] ?? null;
    $trendIcon = match ($trendDirection) {
        'up' => 'arrow_upward',
        'down' => 'arrow_downward',
        'flat' => 'horizontal_rule',
        default => null,
    };
@endphp

<div {{ $attributes->merge(['class' => 'kpi-card']) }}>
    <div class="kpi-card__header">
        <p class="kpi-card__label text-label-caps">{{ $label }}</p>
        @if($icon)
            <span class="kpi-card__icon">
                <span class="material-symbols-outlined icon icon--md" aria-hidden="true">{{ $icon }}</span>
            </span>
        @endif
    </div>

    <p class="kpi-card__value">{{ $value }}</p>

    @if($trendIcon || $hint)
        <div class="kpi-card__footer">
            @if($trendIcon)
                <span class="kpi-card__trend kpi-card__trend--{{ $trendDirection }}">
                    <span class="material-symbols-outlined icon icon--xs" aria-hidden="true">{{ $trendIcon }}</span>
                    {{ $trend['label'] }}
                </span>
                <span class="kpi-card__hint text-body-sm">{{ $trendCaption ?? __('vs previous period') }}</span>
            @endif

            @if($hint)
                <p class="kpi-card__hint text-body-sm">{{ $hint }}</p>
            @endif
        </div>
    @endif
</div>
