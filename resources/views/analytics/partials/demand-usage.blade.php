@php
    /*
    | Demand & Utilization: what was asked for, and what actually went out.
    |
    | The two are deliberately never merged. A request that was filed but never
    | released is real demand and belongs in Most Requested; only a physical
    | release belongs in Top Released. Every card states which of the two it
    | counts, and no card adds one to the other.
    |
    | Layout only. Every figure is computed in AnalyticsService and handed here
    | by AnalyticsController; nothing below is calculated from records, and a
    | reading that does not exist is stated as absent rather than filled in.
    |
    | A card's primary click opens an Analytics detail for that figure, not the
    | record module: clicking a number is a question about the number.
    */
    use App\Services\AnalyticsService;
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;

    /* Quantities are stored as decimals; whole units should not read as 9.00. */
    $qty = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');

    /*
     | How the trend should be drawn, on the same rule Overview uses: a period
     | is only plotted once at least one bucket carries a request, so a filter
     | with no activity does not render as a flat line along the floor.
     */
    $trendFilled = array_sum(array_column($trend['points'], 'count')) > 0;

    $trendMode = match (true) {
        $trend['points'] === [] || ! $trendFilled => 'empty',
        count($trend['points']) === 1 => 'single',
        default => 'line',
    };

    /*
     | Division legend.
     |
     | borrowerGroups() drops divisions with no activity, which is right for a
     | ranking but wrong for a composition: a reader comparing parts of a whole
     | needs to see the division that contributed nothing. The counts already
     | fetched are re-read against the full division list, so a zero division is
     | restored without a second query.
     */
    $groupCounts = collect($groups['groups'])->keyBy(fn (array $row): string => $row['code'] ?? '__unspecified');

    $divisionLegend = collect(AnalyticsService::DIVISIONS)
        ->map(fn (string $label, string $code): array => [
            'code' => $code,
            'label' => $label,
            'count' => (int) ($groupCounts[$code]['count'] ?? 0),
            'percentage' => (int) ($groupCounts[$code]['percentage'] ?? 0),
            'tone' => strtolower(explode('_', $code)[0]),
        ])
        ->values();

    if ($groupCounts->has('__unspecified')) {
        $unspecified = $groupCounts['__unspecified'];
        $divisionLegend->push([
            'code' => null,
            'label' => $unspecified['label'],
            'count' => (int) $unspecified['count'],
            'percentage' => (int) $unspecified['percentage'],
            'tone' => 'unspecified',
        ]);
    }

    /*
     | Donut geometry. The radius is chosen so the circumference is exactly 100
     | and a dash length is a percentage directly. Arc lengths use the unrounded
     | share so the ring closes; the printed percentage stays the service's
     | rounded one, so what is drawn and what is read never disagree by a step.
     */
    $donutTotal = (int) $groups['total'];
    $donutOffset = 0.0;
    $donutSegments = [];

    if ($donutTotal > 0) {
        foreach ($divisionLegend as $slice) {
            if ($slice['count'] <= 0) {
                continue;
            }

            $length = $slice['count'] / $donutTotal * 100;

            $donutSegments[] = [
                'tone' => $slice['tone'],
                'label' => $slice['label'],
                /* Carried onto the arc so it can state what it draws. */
                'count' => $slice['count'],
                'percentage' => $slice['percentage'],
                'length' => round($length, 3),
                'offset' => round($donutOffset, 3),
            ];

            $donutOffset += $length;
        }
    }

    /*
     | Top borrowing units across every division, rather than one block per
     | division. unitRankings() already returns each division's top five, so
     | ranking them against each other is a re-read of rows already fetched and
     | the combined top five is complete. Division becomes secondary metadata.
     */
    $rankedUnits = collect($units['columns'])
        ->flatMap(fn (array $column): array => collect($column['units'])
            ->map(fn (array $row): array => $row + [
                'division_code' => $column['code'],
                'division_label' => $column['label'],
                'division_tone' => strtolower(explode('_', $column['code'])[0]),
            ])
            ->all())
        ->sortByDesc('count')
        ->take(5)
        ->values();

    $unitLeaderCount = (int) ($rankedUnits->max('count') ?: 0);

    $topRequested = array_slice($requested['items'], 0, 5);
    $topReleased = array_slice($released['items'], 0, 5);

    /*
     | How Most Requested should be drawn.
     |
     | The bar answers "how does this item compare to the one above it", so it
     | is only worth drawing when there is a comparison to make. When every
     | shown item appeared in the same number of requests the bars would all
     | be full width, which reads as a ranking that the data does not support;
     | the rows are listed plainly instead and the tie is stated once. A single
     | item has nothing to be compared against, so it is listed the same way,
     | but without a sentence about items agreeing with each other.
     */
    $requestedMode = match (true) {
        $topRequested === [] => 'empty',
        count($topRequested) === 1 => 'single',
        count(array_unique(array_column($topRequested, 'requests'))) === 1 => 'tied',
        default => 'ranked',
    };

    /*
     | Low / no usage: catalogue items with nothing released in the period.
     | slowMovingItems() sorts ascending by released quantity, so the zero rows
     | sit at the front; only those are shown, and only the first five.
     */
    $noUsage = collect($slowMoving['items'])
        ->filter(fn (array $row): bool => $row['released'] <= 0)
        ->take(5)
        ->values();

    $noUsageTotal = (int) $slowMoving['never_moved'];

    /* Peak: the busiest bucket is tagged rather than described twice. */
    $peakHighest = $peak['available'] ? (int) collect($peak['days'])->max('count') : 0;
@endphp

{{-- Headline figures --------------------------------------------------- --}}
<div class="analytics-kpis">
    {{--
        Requests Filed has an Analytics detail of its own, so it is a link.
        The three beside it are readings without a record list to open, and
        they stay plain cards rather than looking clickable and leading nowhere.
    --}}
    <a
        class="analytics-kpi-card tone-requests"
        href="{{ AnalyticsDetailLink::to('requests', 'demand', $periodSelection, $division, $unit) }}"
        aria-label="View the borrowing requests filed in this period"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="requests" size="19" /></span>
        <span class="analytics-kpi-card-label">Requests Filed</span>
        <strong class="analytics-kpi-card-value">{{ $totals['requests'] }}</strong>
        <span class="analytics-kpi-card-note">Valid borrowing requests</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-quantity"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-quantity']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="inventory" size="19" /></span>
        <span class="analytics-kpi-card-label">Requested Quantity</span>
        <strong class="analytics-kpi-card-value">{{ $qty($totals['requested_quantity']) }}</strong>
        <span class="analytics-kpi-card-note">Total quantity requested</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-released"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.released-quantity']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="custody" size="19" /></span>
        <span class="analytics-kpi-card-label">Released Quantity</span>
        <strong class="analytics-kpi-card-value">{{ $qty($totals['released_quantity']) }}</strong>
        <span class="analytics-kpi-card-note">Quantity physically released</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-units"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.active-units']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="users" size="19" /></span>
        <span class="analytics-kpi-card-label">Active Borrowing Units</span>
        <strong class="analytics-kpi-card-value">{{ $totals['active_units'] }}</strong>
        <span class="analytics-kpi-card-note">Units with borrowing activity</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Trend and composition ---------------------------------------------- --}}
<div class="analytics-demand-main">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.trend']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
            <div>
                <h2>Borrowing Demand Trend</h2>
                <p>Number of borrowing requests filed per {{ $trend['granularity'] }}.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.trend']) }}" aria-label="View Borrowing Demand Trend details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        @if($trendMode === 'empty')
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="analytics" size="19" /></span>
                    No borrowing requests were filed during this period.
                </p>
            </div>
        @else
            @if($trendMode === 'single')
                @php $onlyPoint = $trend['points'][0]; @endphp

                <div class="analytics-card-body analytics-trend-single">
                    <a
                        class="analytics-trend-single-figure"
                        href="{{ AnalyticsDetailLink::to('trend', 'demand', $periodSelection, $division, $unit, ['bucket' => 0]) }}"
                        aria-label="View details for {{ $onlyPoint['label'] }}: {{ $onlyPoint['count'] }} {{ $onlyPoint['count'] === 1 ? 'request' : 'requests' }}"
                    >
                        <span class="analytics-trend-single-value">
                            <strong>{{ $onlyPoint['count'] }}</strong>
                            {{ $onlyPoint['count'] === 1 ? 'request' : 'requests' }}
                        </span>
                        <span class="analytics-trend-single-bar" aria-hidden="true"><span></span></span>
                        <span class="analytics-trend-single-label">{{ $onlyPoint['label'] }}</span>
                    </a>
                </div>
            @else
                <div class="analytics-card-body is-plot">
                    @include('analytics.partials.trend-line', ['trend' => $trend, 'lineSection' => 'demand'])
                </div>
            @endif

            <p class="analytics-insight-strip">
                <x-icon name="lightbulb" size="14" aria-hidden="true" />
                <span>{{ $trend['summary'] }}</span>
            </p>
        @endif
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.division']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
            <div>
                <h2>Demand by Division</h2>
                <p>Share of borrowing requests filed by each division.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.division']) }}" aria-label="View Demand by Division details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        @if($donutTotal === 0)
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
                    No borrowing activity by division during this period.
                </p>
            </div>
        @else
            <div class="analytics-card-body analytics-donut-body">
                <div class="analytics-donut" role="img" aria-label="Borrowing requests by division: {{ $donutTotal }} in total">
                    <svg viewBox="0 0 42 42" aria-hidden="true" focusable="false">
                        <circle class="analytics-donut-rail" cx="21" cy="21" r="15.9155" />
                        @foreach($donutSegments as $segment)
                            {{--
                                Dash length is the slice, the gap is the rest of
                                the ring. The offset counts backwards from the
                                12 o'clock start, which the -90deg rotation sets.
                            --}}
                            <circle
                                class="analytics-donut-arc is-{{ $segment['tone'] }}"
                                cx="21"
                                cy="21"
                                r="15.9155"
                                tabindex="0"
                                role="img"
                                data-chart-tip
                                data-tip-title="{{ $segment['label'] }}"
                                data-tip-rows="{{ json_encode([
                                    [$segment['count'] === 1 ? 'Request' : 'Requests', (string) $segment['count']],
                                    ['Share of requests', $segment['percentage'].'%'],
                                ]) }}"
                                aria-label="{{ $segment['label'] }}: {{ $segment['count'] }} {{ $segment['count'] === 1 ? 'request' : 'requests' }}, {{ $segment['percentage'] }} percent of requests"
                                stroke-dasharray="{{ $segment['length'] }} {{ round(100 - $segment['length'], 3) }}"
                                stroke-dashoffset="{{ round(100 - $segment['offset'], 3) }}"
                            />
                        @endforeach
                    </svg>

                    <span class="analytics-donut-centre">
                        <strong>{{ $donutTotal }}</strong>
                        <small>Total {{ $donutTotal === 1 ? 'Request' : 'Requests' }}</small>
                    </span>
                </div>

                <ul class="analytics-donut-legend">
                    @foreach($divisionLegend as $slice)
                        <li>
                            @if($slice['code'] && $slice['count'] > 0)
                                <a href="{{ AnalyticsDetailLink::to('division', 'demand', $periodSelection, $slice['code'], null, ['for' => $slice['code']]) }}">
                                    <span class="analytics-donut-key is-{{ $slice['tone'] }}" aria-hidden="true"></span>
                                    <span class="analytics-donut-name">{{ $slice['label'] }}</span>
                                    <span class="analytics-donut-figure">{{ $slice['count'] }} <small>· {{ $slice['percentage'] }}%</small></span>
                                </a>
                            @else
                                <div>
                                    <span class="analytics-donut-key is-{{ $slice['tone'] }}" aria-hidden="true"></span>
                                    <span class="analytics-donut-name">{{ $slice['label'] }}</span>
                                    <span class="analytics-donut-figure">{{ $slice['count'] }} <small>· {{ $slice['percentage'] }}%</small></span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="analytics-insight-strip">
                <x-icon name="information" size="14" aria-hidden="true" />
                <span>{{ $groups['summary'] }}</span>
            </p>
        @endif
    </section>
</div>

{{-- What was asked for -------------------------------------------------- --}}
<div class="analytics-demand-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-items']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
            <div>
                <h2>Most Requested Items</h2>
                <p>Top items by number of requests.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.requested-items']) }}" aria-label="View Most Requested Items details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($requestedMode === 'empty')
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No requested items were recorded during this period.
                </p>
            @else
                {{--
                    The bar measures how many requests contained the item, never
                    the quantity: one request for 150 chairs must not outrank an
                    item that two separate requests asked for. Quantity is stated
                    beside it as the secondary reading.

                    Where counts vary the name sits beside its bar, so the bars
                    form one column that can be compared down the card. Where
                    every item ties the track is dropped rather than drawn at
                    full width for all of them - see $requestedMode above.
                --}}
                <ol class="analytics-rank {{ $requestedMode === 'ranked' ? 'analytics-rank-split' : 'analytics-rank-flat' }}">
                    @foreach($topRequested as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    [$row['requests'] === 1 ? 'Request' : 'Requests', (string) $row['requests']],
                                    ['Requested quantity', $qty($row['quantity'])],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['name'] }}</span>
                                    @if($requestedMode === 'ranked')
                                        <span class="analytics-rank-track">
                                            <span class="analytics-rank-fill" style="width: {{ max(3, $row['share']) }}%"></span>
                                        </span>
                                    @endif
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
                                    <small>Requested qty: {{ $qty($row['quantity']) }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        {{-- Sits flush at the card's bottom edge, like every other strip here. --}}
        @if($requestedMode === 'tied')
            <p class="analytics-insight-strip">
                <x-icon name="information" size="14" aria-hidden="true" />
                <span>All top items appeared in the same number of requests.</span>
            </p>
        @endif
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.units']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
            <div>
                <h2>Top Borrowing Units</h2>
                <p>Ranked by filed requests.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.units']) }}" aria-label="View Top Borrowing Units details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($rankedUnits->isEmpty())
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
                    No borrowing unit activity during this period.
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($rankedUnits as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('unit', 'demand', $periodSelection, $row['division_code'], $row['name'], ['for' => $row['name']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Division', $row['division_label']],
                                    [$row['count'] === 1 ? 'Request filed' : 'Requests filed', (string) $row['count']],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">
                                        {{ $row['name'] }}
                                        <small class="analytics-rank-tag is-{{ $row['division_tone'] }}">{{ $row['division_label'] }}</small>
                                    </span>
                                    <span class="analytics-rank-track">
                                        <span
                                            class="analytics-rank-fill"
                                            style="width: {{ $unitLeaderCount > 0 ? max(3, round($row['count'] / $unitLeaderCount * 100)) : 3 }}%"
                                        ></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $row['count'] }}
                                    <small>{{ $row['count'] === 1 ? 'request' : 'requests' }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>

{{-- What actually went out ---------------------------------------------- --}}
<div class="analytics-demand-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.released-items']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="custody" size="15" /></span>
            <div>
                <h2>Top Released Items</h2>
                <p>Items with the highest quantity physically released.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.released-items']) }}" aria-label="View Top Released Items details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($topReleased === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No equipment was physically released during this period.
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($topReleased as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Physically released', $qty($row['released']).' '.$row['unit']],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['name'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span class="analytics-rank-fill is-released" style="width: {{ max(3, $row['share']) }}%"></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $qty($row['released']) }}
                                    <small>{{ $row['unit'] }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.low-usage']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="warning" size="15" /></span>
            <div>
                <h2>Low / No Usage Items</h2>
                <p>Items with no releases during this period.</p>
            </div>
            {{--
                No "View all" here.

                The full catalogue reading lives in the Equipment Utilization
                report, and Analytics has no list detail of its own for quiet
                items. Reports currently answers every request with a 500 -
                route [reports.print] is referenced but never registered - so a
                link would be an offer the application cannot keep. The count in
                the footer states the same fact without sending anyone nowhere.
            --}}
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.low-usage']) }}" aria-label="View Low / No Usage Items details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($noUsage->isEmpty())
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                    {{ $slowMoving['items'] === []
                        ? 'No borrowable equipment is recorded yet.'
                        : 'Every borrowable item was released at least once during this period.' }}
                </p>
            @else
                <ul class="analytics-quiet">
                    @foreach($noUsage as $row)
                        <li>
                            <a href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}">
                                <span class="analytics-quiet-name">{{ $row['name'] }}</span>
                                <span class="analytics-quiet-state">No release</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($noUsageTotal > 0)
            <footer class="analytics-card-foot">
                {{ $noUsageTotal }} {{ $noUsageTotal === 1 ? 'item' : 'items' }} had no recorded releases.
            </footer>
        @endif
    </section>
</div>

{{-- When requests are filed ---------------------------------------------- --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.peak']) }}"
        class="analytics-card">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clock" size="15" /></span>
        <div>
            <h2>Peak Borrowing Periods</h2>
            <p>Most active weekdays or hours for filed borrowing requests.</p>
        </div>
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'demand', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'demand.peak']) }}" aria-label="View Peak Borrowing Periods details"><x-icon name="chevron-right" size="15" /></a>
    </header>

    @if(! $peak['available'])
        <div class="analytics-card-body">
            <p class="analytics-blank">
                <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="clock" size="19" /></span>
                Not enough activity to determine a reliable peak.
            </p>
        </div>
    @else
        <div class="analytics-card-body">
            <ul class="analytics-peak">
                @foreach($peak['days'] as $day)
                    <li
                        class="{{ $peakHighest > 0 && $day['count'] === $peakHighest ? 'is-peak' : '' }}"
                        tabindex="0"
                                                data-chart-tip
                        data-tip-title="{{ $day['label'] }}"
                        data-tip-rows="{{ json_encode(array_values(array_filter([
                            [$day['count'] === 1 ? 'Request filed' : 'Requests filed', (string) $day['count']],
                            $peakHighest > 0 && $day['count'] === $peakHighest ? ['Busiest period', 'Yes'] : null,
                        ]))) }}"
                        aria-label="{{ $day['label'] }}: {{ $day['count'] }} {{ $day['count'] === 1 ? 'request' : 'requests' }} filed"
                    >
                        <span class="analytics-peak-label">{{ $day['label'] }}</span>
                        <span class="analytics-peak-track">
                            <span class="analytics-peak-fill" style="width: {{ max(2, $day['share']) }}%"></span>
                        </span>
                        <span class="analytics-peak-value">
                            {{ $day['count'] }}
                            @if($peakHighest > 0 && $day['count'] === $peakHighest)
                                <small class="analytics-peak-tag">Peak</small>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="analytics-insight-strip">
            <x-icon name="lightbulb" size="14" aria-hidden="true" />
            <span>{{ $peak['summary'] }}</span>
        </p>
    @endif
</section>
