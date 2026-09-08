@php
    /*
    | A trend plotted as vertical bars.
    |
    | Bar height is relative to the busiest bucket in the same series, which is
    | the same convention the horizontal rankings use. Each bar opens the
    | Analytics detail for that bucket, so a reader can ask what a spike was
    | made of without leaving the tab.
    */
    use App\Support\AnalyticsDetailLink;

    $trendSection = $trendSection ?? $section;
    $trendDivision = $selectedDivision === 'all' ? null : $selectedDivision;
    $trendUnit = $selectedUnit === 'all' ? null : $selectedUnit;
@endphp

<div class="analytics-trend">
    @foreach($trend['points'] as $index => $point)
        <a
            class="analytics-trend-col"
            href="{{ AnalyticsDetailLink::to('trend', $trendSection, $periodSelection, $trendDivision, $trendUnit, ['bucket' => $index]) }}"
            aria-label="View details for {{ $point['label'] }}: {{ $point['count'] }} {{ $point['count'] === 1 ? 'request' : 'requests' }}"
        >
            <span class="analytics-trend-bar" style="height: {{ max(2, $point['share']) }}%">
                <span class="analytics-trend-count">{{ $point['count'] }}</span>
            </span>
            <span class="analytics-trend-label">{{ $point['label'] }}</span>
        </a>
    @endforeach
</div>
