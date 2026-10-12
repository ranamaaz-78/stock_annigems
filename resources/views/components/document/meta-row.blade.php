{{-- One fact about the document, as a tile (date, payment, status…). --}}
@props(['label', 'outline' => false])

<div @class(['sheet__tile', 'sheet__tile--outline' => $outline])>
    <span class="sheet__meta-label">{{ $label }}</span>
    <span class="sheet__meta-value">{{ $slot }}</span>
</div>