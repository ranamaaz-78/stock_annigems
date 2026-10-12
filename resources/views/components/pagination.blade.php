{{-- Wrapper around Laravel paginator with compact styling. --}}
@props(['paginator'])

@if($paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'mt-4']) }}>
        {{ $paginator->withQueryString()->links() }}
    </div>
@endif
