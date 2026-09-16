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
        <span class="report-page-link" aria-disabled="true" style="gap:5px"><x-icon name="arrow-left" size="14" /><span>Previous</span></span>
    @else
        <a class="report-page-link" style="gap:5px" href="{{ $paginator->previousPageUrl() }}" rel="prev"><x-icon name="arrow-left" size="14" /><span>Previous</span></a>
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
        <a class="report-page-link" style="gap:5px" href="{{ $paginator->nextPageUrl() }}" rel="next"><span>Next</span><x-icon name="arrow-right" size="14" /></a>
    @else
        <span class="report-page-link" aria-disabled="true" style="gap:5px"><span>Next</span><x-icon name="arrow-right" size="14" /></span>
    @endif
</nav>
