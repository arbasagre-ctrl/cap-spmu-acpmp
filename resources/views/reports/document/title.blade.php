@php
    /*
    | Report title and the period it covers.
    |
    | Activity reports state a range; a snapshot such as Inventory Status
    | states the date it was taken, because quoting a range would misdescribe
    | what the figures mean.
    */
    $periodMode = App\Reports\ReportCatalogue::periodMode($dataset->reportKey);
    $meta = $dataset->meta;
@endphp

<div class="doc-title-block">
    <h1 class="doc-title">{{ mb_strtoupper($dataset->label) }}</h1>

    @if($periodMode === 'as_of')
        <p class="doc-period">As of {{ $meta['as_of_long'] ?? '' }}</p>
    @else
        <p class="doc-period">For the period {{ $meta['period_long'] ?? '' }}</p>
    @endif
</div>
