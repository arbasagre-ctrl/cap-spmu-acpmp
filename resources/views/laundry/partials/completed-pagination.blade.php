<nav class="completed-laundry-pagination" aria-label="Completed laundry pagination">
    @if($paginator->onFirstPage())
        <span class="completed-page-link" aria-disabled="true" aria-label="Previous page"><x-icon name="chevron-right" class="completed-page-previous" size="16" /></span>
    @else
        <a class="completed-page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-icon name="chevron-right" class="completed-page-previous" size="16" /></a>
    @endif
    @foreach($elements as $element)
        @if(is_string($element))
            <span class="completed-page-ellipsis" aria-hidden="true">{{ $element }}</span>
        @endif
        @if(is_array($element))
            @foreach($element as $page => $url)
                @if($page === $paginator->currentPage())
                    <span class="completed-page-link is-active" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                @else
                    <a class="completed-page-link" href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach
    @if($paginator->hasMorePages())
        <a class="completed-page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next page"><x-icon name="chevron-right" size="16" /></a>
    @else
        <span class="completed-page-link" aria-disabled="true" aria-label="Next page"><x-icon name="chevron-right" size="16" /></span>
    @endif
</nav>
