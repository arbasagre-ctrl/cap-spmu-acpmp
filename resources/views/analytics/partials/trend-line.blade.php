@php
    /*
    | Borrowing activity as a line with a soft area fill.
    |
    | Shared by any tab that reads its buckets as a time series. The caller
    | passes the section its markers should open a detail in; without one the
    | partial keeps its original Overview destination.
    |
    | Presentation only: the points, their labels and their normalised share
    | all come from AnalyticsService::trend(). Nothing is computed here beyond
    | turning an existing share into a coordinate, and each marker opens the
    | same Analytics detail the bar plot opened.
    |
    | GEOMETRY
    | --------
    | x is inset rather than running 0-100. A marker is a 26px circle centred
    | on its x, so a point at 0 or 100 had half of itself outside the plot and
    | the line appeared to start mid-stroke against the card edge.
    |
    | y uses the share already normalised against the busiest bucket. The top
    | padding leaves room for the value label that rides above the peak marker,
    | and the baseline sits above the floor so a zero bucket still reads as a
    | point on the axis rather than falling off it.
    |
    | The whole plot carries an --trend modifier so these rules cannot reach
    | the dual-line returns chart, which shares the base classes.
    */
    use App\Support\AnalyticsDetailLink;

    $lineSection = $lineSection ?? 'overview';
    $linePoints = $trend['points'];
    $lineCount = count($linePoints);

    $lineDivision = $selectedDivision === 'all' ? null : $selectedDivision;
    $lineUnit = $selectedUnit === 'all' ? null : $selectedUnit;

    $xStart = 5.0;
    $xEnd = 95.0;
    $topPadding = 16.0;
    $baseline = 88.0;
    $span = $baseline - $topPadding;

    $coords = [];

    foreach ($linePoints as $index => $point) {
        $coords[] = [
            'x' => $lineCount > 1
                ? round($xStart + ($index / ($lineCount - 1) * ($xEnd - $xStart)), 3)
                : 50.0,
            'y' => round($baseline - ($point['share'] / 100 * $span), 3),
            'label' => $point['label'],
            'count' => $point['count'],
            'index' => $index,
        ];
    }

    /* Four hairlines plus the baseline; a denser grid competes with the line. */
    $gridLines = [];

    for ($step = 0; $step <= 4; $step++) {
        $gridLines[] = round($baseline - ($step / 4 * $span), 3);
    }

    $strokePath = 'M'.implode(' L', array_map(
        static fn (array $c): string => $c['x'].','.$c['y'],
        $coords
    ));

    /* The fill closes on the baseline, not the floor, so it sits on the axis. */
    $first = $coords[0];
    $last = $coords[$lineCount - 1];
    $areaPath = 'M'.$first['x'].','.$baseline.' L'.implode(' L', array_map(
        static fn (array $c): string => $c['x'].','.$c['y'],
        $coords
    )).' L'.$last['x'].','.$baseline.' Z';
@endphp

<div class="analytics-line analytics-line--trend" role="img" aria-label="Borrowing requests filed per {{ $trend['granularity'] }}">
    <div class="analytics-line-plot">
        {{--
            The path is stretched to the box, so every stroke is kept at a true
            width with non-scaling-stroke rather than being squashed with it.
        --}}
        <svg class="analytics-line-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="analytics-line-gradient" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#2563eb" stop-opacity=".20" />
                    <stop offset="100%" stop-color="#2563eb" stop-opacity="0" />
                </linearGradient>
            </defs>

            @foreach($gridLines as $index => $y)
                <line
                    class="analytics-line-rule{{ $index === 0 ? ' is-base' : '' }}"
                    x1="0" x2="100" y1="{{ $y }}" y2="{{ $y }}"
                    vector-effect="non-scaling-stroke"
                />
            @endforeach

            <path class="analytics-line-area" d="{{ $areaPath }}" />
            <path class="analytics-line-stroke" d="{{ $strokePath }}" vector-effect="non-scaling-stroke" />
        </svg>

        <div class="analytics-line-markers">
            @foreach($coords as $coord)
                <a
                    class="analytics-line-marker"
                    style="left: {{ $coord['x'] }}%; top: {{ $coord['y'] }}%"
                    href="{{ AnalyticsDetailLink::to('trend', $lineSection, $periodSelection, $lineDivision, $lineUnit, ['bucket' => $coord['index']]) }}"
                    data-chart-tip
                    data-tip-title="{{ $coord['label'] }}"
                    data-tip-rows="{{ json_encode([[
                        $coord['count'] === 1 ? 'Request filed' : 'Requests filed',
                        (string) $coord['count'],
                    ]]) }}"
                    aria-label="View details for {{ $coord['label'] }}: {{ $coord['count'] }} {{ $coord['count'] === 1 ? 'request' : 'requests' }}"
                >
                    <span class="analytics-line-dot" aria-hidden="true"></span>
                    <span class="analytics-line-tip" aria-hidden="true">{{ $coord['count'] }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Each label sits under the point it belongs to, not in an even column. --}}
    <div class="analytics-line-axis" aria-hidden="true">
        @foreach($coords as $coord)
            <span style="left: {{ $coord['x'] }}%">{{ $coord['label'] }}</span>
        @endforeach
    </div>
</div>
