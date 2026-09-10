@php
    /*
    | Borrowing activity as vertical bars.
    |
    | Overview only. Demand & Utilization reads the same buckets as a line and
    | keeps trend-line; this partial is not shared with it, so changing one
    | cannot move the other.
    |
    | Presentation only. The buckets, their labels and their normalised share
    | all come from AnalyticsService::trend(); nothing here is computed beyond
    | turning an existing share into a bar height, and every column opens the
    | same Analytics detail the plot already offered.
    |
    | LAYOUT
    | ------
    | Each bucket is one flex column holding its value, its bar and its label,
    | so a label can only ever be as wide as the column it belongs to. That is
    | what keeps long period names inside the card: there is no absolutely
    | positioned text to overhang an edge.
    */
    use App\Support\AnalyticsDetailLink;

    $barPoints = $trend['points'];
    $barCount = count($barPoints);

    $barDivision = $selectedDivision === 'all' ? null : $selectedDivision;
    $barUnit = $selectedUnit === 'all' ? null : $selectedUnit;
@endphp

<div
    class="analytics-bars-chart"
    data-points="{{ $barCount }}"
    role="img"
    aria-label="Borrowing requests filed per {{ $trend['granularity'] }}"
>
    <div class="analytics-bars-plot">
        {{-- Four hairlines behind the bars; a denser grid competes with them. --}}
        <span class="analytics-bars-grid" aria-hidden="true"></span>

        @foreach($barPoints as $index => $point)
            <a
                class="analytics-bars-col"
                href="{{ AnalyticsDetailLink::to('trend', 'overview', $periodSelection, $barDivision, $barUnit, ['bucket' => $index]) }}"
                data-chart-tip
                data-tip-title="{{ $point['label'] }}"
                data-tip-rows="{{ json_encode([[
                    $point['count'] === 1 ? 'Request' : 'Requests',
                    (string) $point['count'],
                ]]) }}"
                aria-label="View details for {{ $point['label'] }}: {{ $point['count'] }} {{ $point['count'] === 1 ? 'request' : 'requests' }}"
            >
                <span class="analytics-bars-track">
                    <span class="analytics-bars-value">{{ $point['count'] }}</span>
                    {{--
                        A bucket with no requests still gets a visible stub, so
                        the reader sees a recorded zero rather than a gap that
                        could be mistaken for missing data.
                    --}}
                    <span
                        class="analytics-bars-bar{{ $point['count'] === 0 ? ' is-zero' : '' }}"
                        style="height: {{ $point['count'] === 0 ? 2 : max(4, $point['share']) }}%"
                    ></span>
                </span>
                <span class="analytics-bars-label">{{ $point['label'] }}</span>
            </a>
        @endforeach
    </div>
</div>
