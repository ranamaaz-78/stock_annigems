{{--
    Export / print actions for a report header.

    The buttons carry the current filter query string, so an export always
    reflects exactly what is on screen. Rendering is decided server-side by the
    caller passing $canExport (resolved from the ReportPolicy) — the buttons are
    never merely hidden with CSS.
--}}
@props([
    // Named route accepting a `format` query parameter.
    'exportRoute',
    // Query parameters describing the currently applied filters.
    'query' => [],
    'canExport' => false,
])

@if($canExport)
    <div {{ $attributes->merge(['class' => 'report-actions report-print-hide']) }}>
        {{-- PDF is the same page rendered through the browser print pipeline,
             the convention already used by invoice and quotation printing. --}}
        <x-btn
            variant="secondary"
            :href="route($exportRoute, array_merge($query, ['format' => 'pdf']))"
            target="_blank"
            rel="noopener"
        >
            <span class="material-symbols-outlined icon icon--sm" aria-hidden="true">print</span>
            Print / PDF
        </x-btn>

        <x-btn :href="route($exportRoute, array_merge($query, ['format' => 'csv']))">
            <span class="material-symbols-outlined icon icon--sm" aria-hidden="true">download</span>
            Export CSV
        </x-btn>
    </div>
@endif
