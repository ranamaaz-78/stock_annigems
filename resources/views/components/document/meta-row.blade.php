{{-- One label/value pair in the document header meta grid. --}}
@props(['label'])

<dt class="sheet__meta-label">{{ $label }}</dt>
<dd class="sheet__meta-value">{{ $slot }}</dd>
