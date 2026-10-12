{{--
    Report family switcher (SCSS: .report-tabs).

    Rendered as links rather than buttons so each report is bookmarkable and
    keyboard navigation works without JavaScript. The tab list is built by the
    controller and already filtered by permission, so a tab a user may not open
    is never emitted.
--}}
@props([
    // list<array{key: string, label: string, url: string}>
    'tabs',
    'active',
])

@if(count($tabs) > 1)
    <nav {{ $attributes->merge(['class' => 'report-tabs report-print-hide']) }} aria-label="Report type">
        @foreach($tabs as $tab)
            <a
                href="{{ $tab['url'] }}"
                class="report-tabs__tab {{ $tab['key'] === $active ? 'report-tabs__tab--active' : '' }}"
                @if($tab['key'] === $active) aria-current="page" @endif
            >
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>
@endif
