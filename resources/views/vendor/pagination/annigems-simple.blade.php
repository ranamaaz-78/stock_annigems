{{-- Previous / Next only, in the Aanigemas look (SCSS: .pager). --}}
@if ($paginator->hasPages())
    <nav class="pager pager--simple" role="navigation" aria-label="Pagination">
        <ul class="pager__list">
            <li>
                @if ($paginator->onFirstPage())
                    <span class="pager__item is-disabled" aria-disabled="true">Previous</span>
                @else
                    <a class="pager__item" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                @endif
            </li>
            <li>
                @if ($paginator->hasMorePages())
                    <a class="pager__item" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
                @else
                    <span class="pager__item is-disabled" aria-disabled="true">Next</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
