{{-- Compact muted attribution block. Style: resources/css/components/_attribution.scss --}}
<div {{ $attributes->merge(['class' => 'attribution']) }}>
    <p class="attribution__row">
        <span class="attribution__label">{{ __('Created by:') }}</span>
        <span>{{ $createdLine }}</span>
    </p>
    @if($updatedLine)
        <p class="attribution__row">
            <span class="attribution__label">{{ __('Last updated:') }}</span>
            <span>{{ $updatedLine }}</span>
        </p>
    @endif
</div>
