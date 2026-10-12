{{--
    A titled column in the document's party row (Billed to / Payment details /
    Validity). Pass divided on the right-hand column to draw the separator rule.
--}}
@props(['label', 'divided' => false])

<div @class(['sheet__party', 'sheet__party--divided' => $divided])>
    <p class="sheet__party-label">{{ $label }}</p>
    {{ $slot }}
</div>
