{{--
    The client / creditor card: label and name on the left, contact lines on the
    right. Pass the name in $slot's first element and the lines in the second.
--}}
@props(['label', 'divided' => false])

<div class="sheet__party">
    <div>
        <p class="sheet__party-label">{{ $label }}</p>
        {{ $name ?? '' }}
    </div>
    {{ $slot }}
</div>