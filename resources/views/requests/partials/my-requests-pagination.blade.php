<nav class="app-pagination mr-pagination" aria-label="My requests pagination">
    @if($paginator->onFirstPage())
        <span class="app-pagination-link" aria-disabled="true" aria-label="Previous page"><x-icon name="arrow-left" size="15" /></span>
    @else
        <a class="app-pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-icon name="arrow-left" size="15" /></a>
    @endif

    @foreach($elements as $element)
        @if(is_string($element))
            <span class="app-pagination-ellipsis" aria-hidden="true">{{ $element }}</span>
        @endif

        @if(is_array($element))
            @foreach($element as $page => $url)
                @if($page === $paginator->currentPage())
                    <span class="app-pagination-link is-active" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                @else
                    <a class="app-pagination-link" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if($paginator->hasMorePages())
        <a class="app-pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"><x-icon name="arrow-right" size="15" /></a>
    @else
        <span class="app-pagination-link" aria-disabled="true" aria-label="Next page"><x-icon name="arrow-right" size="15" /></span>
    @endif
</nav>
