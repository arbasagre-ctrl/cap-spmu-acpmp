@if ($paginator->hasPages())
    <nav class="app-pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="app-pagination-link" aria-disabled="true">Previous</span>
        @else
            <a class="app-pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
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
            <a class="app-pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else
            <span class="app-pagination-link" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
