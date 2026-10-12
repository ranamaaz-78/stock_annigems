{{--
    Pagination in the Annigems look (SCSS: .pager). Replaces Laravel's stock
    Tailwind view so the colours come from the design tokens, not from
    Tailwind's grey/indigo. Registered as the default view in
    AppServiceProvider.

    Beside "Showing 26-50 of 163" it offers a "Show 10 / 20 / 50 / 100 / All"
    choice, but only on lists that call per_page() (see app/helpers.php), and
    it stays visible when everything fits on one page so the size can be
    changed back.
--}}
@php
    $total = $paginator->total();
    $sizeChoice = request()->attributes->get('per_page_enabled') === true;
    $showAll = request()->query('per_page') === 'all';
    $options = (array) config('annigems.pagination.options', [10, 20, 50, 100]);
    // A list whose own default is not a standard size (e.g. 24 folders) still
    // shows what it is using now.
    if (! $showAll && ! in_array($paginator->perPage(), $options, true)) {
        $options[] = $paginator->perPage();
        sort($options);
    }
    $current = $showAll ? 'all' : $paginator->perPage();
@endphp
@if ($total > 0)
    <nav class="pager" role="navigation" aria-label="Pagination">
        <p class="pager__summary">
            Showing
            <strong>{{ $paginator->firstItem() }}</strong>&ndash;<strong>{{ $paginator->lastItem() }}</strong>
            of
            <strong>{{ number_format($total) }}</strong>
        </p>

        <div class="pager__tools">
            @if ($sizeChoice && ($total > min($options) || $showAll))
                <label class="pager__size">
                    <span>Show</span>
                    <select class="input-field pager__select" data-per-page data-no-select2="true" aria-label="Rows per page">
                        @foreach ($options as $option)
                            <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
                        @endforeach
                        <option value="all" @selected($showAll)>All</option>
                    </select>
                </label>
            @endif

            @if ($paginator->hasPages())
                <ul class="pager__list">
                    {{-- Previous --}}
                    @if ($paginator->onFirstPage())
                        <li><span class="pager__item pager__item--arrow is-disabled" aria-disabled="true" aria-label="Previous">
                            <span class="material-symbols-outlined icon icon--sm">chevron_left</span>
                        </span></li>
                    @else
                        <li><a class="pager__item pager__item--arrow" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">
                            <span class="material-symbols-outlined icon icon--sm">chevron_left</span>
                        </a></li>
                    @endif

                    {{-- Page numbers --}}
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <li><span class="pager__item pager__item--gap" aria-hidden="true">{{ $element }}</span></li>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <li><span class="pager__item is-current" aria-current="page">{{ $page }}</span></li>
                                @else
                                    <li><a class="pager__item" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a></li>
                                @endif
                            @endforeach
                        @endif
                    @endforeach

                    {{-- Next --}}
                    @if ($paginator->hasMorePages())
                        <li><a class="pager__item pager__item--arrow" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">
                            <span class="material-symbols-outlined icon icon--sm">chevron_right</span>
                        </a></li>
                    @else
                        <li><span class="pager__item pager__item--arrow is-disabled" aria-disabled="true" aria-label="Next">
                            <span class="material-symbols-outlined icon icon--sm">chevron_right</span>
                        </span></li>
                    @endif
                </ul>
            @endif
        </div>
    </nav>
@endif
