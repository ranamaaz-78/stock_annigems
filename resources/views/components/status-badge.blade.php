{{-- Status badge pill (SCSS: .badge / .badge--*). --}}
@props([
    'status' => 'active',
    'label' => null,
])

@php
    // Accept a backed enum, a boolean active flag, or a raw string.
    $raw = $status instanceof \BackedEnum ? $status->value : $status;

    if (is_bool($raw)) {
        $raw = $raw ? 'active' : 'inactive';
    }

    $normalized = strtolower(trim((string) $raw));

    if ($normalized === '' || $normalized === '0' || $normalized === 'false') {
        $normalized = 'inactive';
    } elseif ($normalized === '1' || $normalized === 'true') {
        $normalized = 'active';
    }

    // Only these have a colour modifier in _badges.scss; anything else falls
    // back to the neutral pill so a new status never renders unstyled.
    $known = [
        'active', 'available', 'completed',
        'inactive', 'transferred', 'cancelled',
        'reserved', 'under_repair', 'draft',
        'sold', 'damaged', 'lost', 'voided',
        'converted',
        'open', 'partial', 'settled', 'on_memo',
        // Report export lifecycle (Module 6).
        'pending', 'processing', 'streamed', 'failed',
    ];

    $modifier = in_array($normalized, $known, true) ? 'badge--'.$normalized : 'badge--inactive';

    // Prefer the enum's own label, then a humanised fallback.
    $text = $label
        ?? ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : null)
        ?? \Illuminate\Support\Str::headline($normalized);
@endphp

<span {{ $attributes->merge(['class' => "badge $modifier"]) }}>
    {{ $text }}
</span>
