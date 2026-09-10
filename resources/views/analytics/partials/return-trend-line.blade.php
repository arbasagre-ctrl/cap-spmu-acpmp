@php
    /*
    | Completed returns over time, as two series.
    |
    | Borrowing & Return Performance only. Both series are finished returns:
    | one came back on or before the due date, the other after it. Equipment
    | that is still out is OVERDUE, is never plotted here, and is never added
    | to either series.
    |
    | Presentation only - every count and share comes from
    | AnalyticsService::returnTrend(). Geometry matches the Overview line so
    | the two tabs read the same way: 7% headroom above the peak, baseline at
    | 93%, x spread evenly across the width.
    */
    $rtPoints = $returnTrend['points'];
    $rtCount = count($rtPoints);

    $rtTop = 7.0;
    $rtBase = 93.0;
    $rtSpan = $rtBase - $rtTop;

    $rtSeries = [];

    foreach (['on_time', 'late'] as $key) {
        $coords = [];

        foreach ($rtPoints as $index => $point) {
            $coords[] = [
                'x' => $rtCount > 1 ? round($index / ($rtCount - 1) * 100, 3) : 50.0,
                'y' => round($rtBase - ($point[$key.'_share'] / 100 * $rtSpan), 3),
                'label' => $point['label'],
                'value' => $point[$key],
                'index' => $index,
            ];
        }

        $rtSeries[$key] = [
            'coords' => $coords,
            'stroke' => 'M'.implode(' L', array_map(
                static fn (array $c): string => $c['x'].','.$c['y'],
                $coords
            )),
        ];
    }
@endphp

<div class="analytics-line analytics-line--dual" role="img" aria-label="Completed returns per {{ $returnTrend['granularity'] }}, split into on time and late">
    <div class="analytics-line-legend">
        <span class="analytics-line-key is-ontime">On time</span>
        <span class="analytics-line-key is-late">Late</span>
    </div>

    <div class="analytics-line-plot">
        <span class="analytics-line-grid" aria-hidden="true"></span>

        <svg class="analytics-line-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
            <path class="analytics-line-stroke is-ontime" d="{{ $rtSeries['on_time']['stroke'] }}" vector-effect="non-scaling-stroke" />
            <path class="analytics-line-stroke is-late" d="{{ $rtSeries['late']['stroke'] }}" vector-effect="non-scaling-stroke" />
        </svg>

        <div class="analytics-line-markers">
            @foreach($rtSeries as $key => $series)
                @foreach($series['coords'] as $coord)
                    <span
                        class="analytics-line-marker is-static {{ $key === 'on_time' ? 'is-ontime' : 'is-late' }}"
                        style="left: {{ $coord['x'] }}%; top: {{ $coord['y'] }}%"
                        tabindex="0"
                        role="img"
                        data-chart-tip
                        data-tip-title="{{ $coord['label'] }}"
                        data-tip-rows="{{ json_encode([[
                            $key === 'on_time' ? 'Returned on time' : 'Returned late',
                            $coord['value'].' '.($coord['value'] === 1 ? 'return' : 'returns'),
                        ]]) }}"
                        aria-label="{{ $coord['label'] }}: {{ $coord['value'] }} {{ $coord['value'] === 1 ? 'return' : 'returns' }} {{ $key === 'on_time' ? 'on time' : 'late' }}"
                    >
                        <span class="analytics-line-dot" aria-hidden="true"></span>
                    </span>
                @endforeach
            @endforeach
        </div>
    </div>

    <div class="analytics-line-axis" aria-hidden="true">
        @foreach($rtPoints as $point)
            <span>{{ $point['label'] }}</span>
        @endforeach
    </div>
</div>
