@php
    /*
    | Pagination for a generated report.
    |
    | The paginator was built with the report type, reporting period and every
    | applied filter in its query, so moving between pages never silently
    | changes what is being reported.
    */
@endphp

<nav class="report-pagination" aria-label="Report record pages">
    @if($paginator->onFirstPage())
        <span class="report-page-link" aria-disabled="true">Previous</span>
    @else
        <a class="report-page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
    @endif

    @foreach($elements as $element)
        @if(is_string($element))
            <span class="report-page-ellipsis" aria-hidden="true">{{ $element }}</span>
        @endif

        @if(is_array($element))
            @foreach($element as $page => $url)
                @if($page === $paginator->currentPage())
                    <span class="report-page-link is-active" aria-current="page">{{ $page }}</span>
                @else
                    <a class="report-page-link" href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    @if($paginator->hasMorePages())
        <a class="report-page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
    @else
        <span class="report-page-link" aria-disabled="true">Next</span>
    @endif
</nav>
