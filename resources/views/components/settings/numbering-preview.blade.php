{{--
    Next-code strip on the Numbering card. SuperAdmin sees what the next
    jewellery / stone / customer / quotation / invoice / intake code will
    be after the current prefixes, padding and sequences.
--}}
@props([
    'hints' => [],
])

@php
    $rows = collect($hints)->unique('label')->filter(fn ($row) => is_array($row) && isset($row['sample']))->values();
@endphp

@if($rows->isNotEmpty())
    <div class="settings-numbering-preview">
        @foreach($rows as $row)
            <div class="settings-numbering-preview__item">
                <span class="text-label-caps settings-numbering-preview__label">{{ $row['label'] }}</span>
                <span class="text-data-mono settings-numbering-preview__code">{{ $row['sample'] }}</span>
            </div>
        @endforeach
    </div>
@endif
