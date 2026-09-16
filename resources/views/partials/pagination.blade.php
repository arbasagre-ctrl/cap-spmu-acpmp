@if ($paginator->hasPages())
    <nav class="app-pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="app-pagination-link" aria-disabled="true" style="gap:5px"><x-icon name="arrow-left" size="14" /><span>Previous</span></span>
        @else
            <a class="app-pagination-link" style="gap:5px" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="arrow-left" size="14" /><span>Previous</span></a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="app-pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="app-pagination-link is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="app-pagination-link" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="app-pagination-link" style="gap:5px" href="{{ $paginator->nextPageUrl() }}" rel="next"><span>Next</span><x-icon name="arrow-right" size="14" /></a>
        @else
            <span class="app-pagination-link" aria-disabled="true" style="gap:5px"><span>Next</span><x-icon name="arrow-right" size="14" /></span>
        @endif
    </nav>
@endif
