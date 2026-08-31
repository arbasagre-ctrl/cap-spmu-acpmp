<article class="card analytics-trend">
    <div class="analytics-panel-heading">
        <div><h2>Borrowing activity trend</h2><p>Requests created vs physically released</p></div>
        <a class="analytics-link" href="{{ $reportUrl('borrowing') }}">View detailed report <span aria-hidden="true">→</span></a>
    </div>
    <div class="analytics-trend-legend"><span><i></i>Requests</span><span><i class="is-released"></i>Released</span></div>
    @if($borrowingTrend->isNotEmpty())
        <div class="analytics-trend-layout">
            <div class="analytics-trend-axis" aria-hidden="true"><span>{{ $trendCeiling }}</span><span>{{ round($trendCeiling / 2, 1) }}</span><span>0</span></div>
            <div class="analytics-trend-scroll">
                <div class="analytics-trend-chart" style="--trend-columns: {{ $borrowingTrend->count() }}" role="group" aria-label="Requests created and physically released by {{ $trendGranularity }}">
                    @foreach($borrowingTrend as $point)
                        <div class="analytics-trend-column" tabindex="0" aria-label="{{ $point['label'] }}: {{ $point['requests'] }} requests, {{ $point['released'] }} released">
                            <div class="analytics-trend-bars" aria-hidden="true">
                                <span class="analytics-trend-bar" style="height: {{ ($point['requests'] / $trendCeiling) * 100 }}%"><strong>{{ $point['requests'] }}</strong></span>
                                <span class="analytics-trend-bar is-released" style="height: {{ ($point['released'] / $trendCeiling) * 100 }}%"><strong>{{ $point['released'] }}</strong></span>
                            </div>
                            <span class="analytics-trend-label">{{ $point['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <p class="analytics-empty">No borrowing activity for this period.</p>
    @endif
</article>
