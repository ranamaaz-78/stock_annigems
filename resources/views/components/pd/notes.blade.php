{{-- Notes and terms side by side, as on every printed document. --}}
@props(['notes' => null, 'terms' => null, 'termsLabel' => 'Terms'])

<section class="pd__notes">
    <div>
        @if(filled($notes))
            <p class="pd__label">Notes</p>
            <p class="pd__text">{{ $notes }}</p>
        @endif
    </div>
    <div>
        @if(filled($terms))
            <p class="pd__label">{{ $termsLabel }}</p>
            <p class="pd__text">{{ $terms }}</p>
        @endif
    </div>
</section>
