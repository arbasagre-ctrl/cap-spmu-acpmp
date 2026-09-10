@php
    /*
    | Overview: what is happening right now.
    |
    | Layout only. Every figure below is computed in AnalyticsService and
    | handed to the view by AnalyticsController; nothing on this page is
    | calculated from scratch here, and nothing is fabricated when the data
    | is thin - an absent reading is stated as absent.
    |
    | A card's primary click opens an Analytics detail for that figure, not
    | the record module: clicking a number is a question about the number.
    | Reports is offered inside the detail as a secondary step.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;

    $lowAvailabilityPercent = (int) ($lowAvailability['threshold'] * 100);

    /*
     * unitRankings() returns the top units per division. Ranking them against
     * each other is a re-read of rows already fetched, not a second query, and
     * keeping each division's top five guarantees the combined top five is
     * complete. The bar width is re-normalised against the overall leader so
     * the two ranking cards use one scale.
     */
    $rankedUnits = collect($units['columns'])
        ->flatMap(fn (array $column): array => collect($column['units'])
            ->map(fn (array $row): array => $row + [
                'division_code' => $column['code'],
                'division_label' => $column['label'],
            ])
            ->all())
        ->sortByDesc('count')
        ->take(5)
        ->values();

    $unitLeaderCount = (int) ($rankedUnits->max('count') ?: 0);
    $topItems = array_slice($equipment['items'], 0, 5);

    /*
     * Whether there is anything to plot. trend() keeps interior and leading
     * empty buckets, so a filter with no activity arrives as a full set of
     * zeroes rather than an empty array - charting that is a row of flat stubs,
     * which reads as a broken chart instead of "nothing happened". A period is
     * only plotted when at least one bucket carries a request; one bucket or
     * many, it is then drawn as bars.
     */
    $trendFilled = array_sum(array_column($trend['points'], 'count')) > 0;

    $trendMode = $trend['points'] === [] || ! $trendFilled ? 'empty' : 'bars';

    /*
     * Priority rows.
     *
     * Every row restates a figure the controller already handed this view, so
     * the panel costs no extra query and can state nothing the services do not
     * compute. A reading that does not exist is omitted rather than filled in.
     *
     * Rows are ordered by operational weight, not by a fixed list: a risk that
     * is live right now outranks an informational pattern. Inventory sits at
     * weight 20 while it is a risk; with nothing below the threshold the same
     * row is merely reassurance, so it drops below the demand insight instead
     * of holding a risk slot.
     */
    $priorityRows = [];

    /* Weight 10 - live risk. Silent at zero: "0 overdue" is not a signal. */
    if ($overview['needs_follow_up'] > 0) {
        $overdueCount = $overview['needs_follow_up'];

        $priorityRows[] = [
            'weight' => 10,
            'tone' => 'urgent',
            'icon' => 'warning',
            'title' => 'Currently overdue',
            'text' => $overdueCount.' '.($overdueCount === 1 ? 'borrowing is' : 'borrowings are')
                .' currently past '.($overdueCount === 1 ? 'its return date' : 'their return dates').'.',
            'href' => AnalyticsDetailLink::to('follow-up', 'overview', $periodSelection, $division, $unit),
        ];
    }

    /* Weight 20 - inventory risk, only while there is one. */
    if ($lowAvailability['count'] > 0) {
        $priorityRows[] = [
            'weight' => 20,
            'tone' => 'warning',
            'icon' => 'inventory',
            'title' => 'Low availability',
            /* The service names the worst-affected item; use it when it stands alone. */
            'text' => $lowAvailability['count'] === 1 && $lowAvailability['watch']
                ? $lowAvailability['watch']['name'].' is at or below '.$lowAvailabilityPercent.'% usable stock.'
                : $lowAvailability['count'].' item types are at or below '.$lowAvailabilityPercent.'% usable stock.',
            'href' => AnalyticsDetailLink::to('low-availability', 'overview', $periodSelection, $division, $unit),
        ];
    }

    /* Weight 30 - demand pattern for the selected period. */
    if ($rankedUnits->isNotEmpty()) {
        $leadUnit = $rankedUnits->first();

        $priorityRows[] = [
            'weight' => 30,
            'tone' => 'rank',
            'icon' => 'users',
            'title' => 'Top borrowing unit',
            'text' => $leadUnit['name'].' filed the most requests ('.$leadUnit['count'].').',
            'href' => AnalyticsDetailLink::to(
                'unit',
                'overview',
                $periodSelection,
                $leadUnit['division_code'],
                $leadUnit['name'],
                ['for' => $leadUnit['name']]
            ),
        ];
    }

    /*
     * Weight 35. equipment() sums actual_released_quantity, so this is
     * released volume - not how often an item was asked for. The title says
     * released for that reason.
     */
    if ($topItems !== []) {
        $leadItem = $topItems[0];

        $priorityRows[] = [
            'weight' => 35,
            'tone' => 'item',
            'icon' => 'box',
            'title' => 'Top released item',
            'text' => $leadItem['name'].' had the highest released quantity ('
                .($leadItem['released'] + 0).' '.$leadItem['unit'].').',
            'href' => AnalyticsDetailLink::to(
                'equipment',
                'overview',
                $periodSelection,
                $division,
                $unit,
                ['item' => $leadItem['item_id']]
            ),
        ];
    }

    /* Weight 36 - the same inventory reading, reassurance rather than risk. */
    if ($lowAvailability['count'] === 0) {
        $priorityRows[] = [
            'weight' => 36,
            'tone' => 'neutral',
            'icon' => 'check-circle',
            'title' => 'Inventory availability',
            'text' => 'No item type is currently below the availability threshold.',
            'href' => null,
        ];
    }

    /*
     * Weight 40. A null on-time rate means nothing has come back yet, which is
     * a different reading from 0% on time - only one of them is true here. No
     * service defines a compliance threshold, so the row states the measured
     * rate in a neutral tone rather than grading it against a made-up bar.
     */
    $priorityRows[] = $returns['on_time_rate'] === null
        ? [
            'weight' => 40,
            'tone' => 'neutral',
            'icon' => 'clock',
            'title' => 'Return compliance',
            'text' => 'No completed returns in this period; compliance cannot yet be measured.',
            'href' => null,
        ]
        : [
            'weight' => 40,
            'tone' => 'compliance',
            'icon' => 'check-circle',
            'title' => 'Return compliance',
            'text' => $returns['on_time_rate'].'% of the '.$returns['completed'].' completed '
                .($returns['completed'] === 1 ? 'return was' : 'returns were').' on time.',
            'href' => AnalyticsDetailLink::to(
                'returns',
                'overview',
                $periodSelection,
                $division,
                $unit,
                ['state' => $returns['late'] > 0 ? 'late' : 'on-time']
            ),
        ];

    usort($priorityRows, fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);
    $priorityRows = array_slice($priorityRows, 0, 4);

    /*
     * The snapshot strip carries outcomes for the selected period. It does not
     * repeat the two current-state counts already standing in the cards above,
     * and it claims no metric the services do not compute.
     */
    $snapshot = [
        ['label' => 'Approved for release', 'value' => $overview['approved'], 'icon' => 'approval', 'tone' => 'info'],
        ['label' => 'Completed returns', 'value' => $returns['completed'], 'icon' => 'custody', 'tone' => 'neutral'],
        ['label' => 'Returned on time', 'value' => $returns['on_time'], 'icon' => 'check-circle', 'tone' => 'good'],
        ['label' => 'Returned late', 'value' => $returns['late'], 'icon' => 'clock', 'tone' => 'warn'],
        ['label' => 'Open accountability', 'value' => $returns['open_cases'], 'icon' => 'accountability', 'tone' => 'risk'],
    ];
@endphp

{{-- Headline figures ------------------------------------------------- --}}
<div class="analytics-kpis">
    <a
        class="analytics-kpi-card tone-requests"
        href="{{ AnalyticsDetailLink::to('requests', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="requests" size="19" /></span>
        <span class="analytics-kpi-card-label">Requests</span>
        <strong class="analytics-kpi-card-value">{{ $overview['total'] }}</strong>
        <span
            class="analytics-kpi-card-note"
            title="Filed borrowing demand and review workload, including requests that were rejected. This is not a measure of asset usage."
        >Requests filed this period</span>
        <span class="analytics-kpi-card-meta">{{ $comparison['summary'] }}</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-custody"
        href="{{ AnalyticsDetailLink::to('currently-out', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="custody" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Out</span>
        <strong class="analytics-kpi-card-value">{{ $overview['on_custody'] }}</strong>
        <span class="analytics-kpi-card-note">Released, not yet returned</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-overdue"
        href="{{ AnalyticsDetailLink::to('follow-up', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="warning" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Overdue</span>
        <strong class="analytics-kpi-card-value">{{ $overview['needs_follow_up'] }}</strong>
        <span class="analytics-kpi-card-note">Borrowings past their return date</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-stock"
        href="{{ AnalyticsDetailLink::to('low-availability', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="inventory" size="19" /></span>
        <span class="analytics-kpi-card-label">Low Availability</span>
        <strong class="analytics-kpi-card-value">{{ $lowAvailability['count'] }}</strong>
        <span class="analytics-kpi-card-note">At or below {{ $lowAvailabilityPercent }}% usable stock</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Trend and priority signals ---------------------------------------- --}}
<div class="analytics-overview-main">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.trend']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
            <div>
                <h2>Borrowing Demand Trend</h2>
                <p>Borrowing requests filed per {{ $trend['granularity'] }}.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.trend']) }}" aria-label="View Borrowing Demand Trend details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        @if($trendMode === 'empty')
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="analytics" size="19" /></span>
                    No borrowing activity during this period.
                </p>
            </div>
        @else
            {{--
                One bucket or many, the reading is drawn the same way: vertical
                bars over a shared baseline. A single period used to render as a
                horizontal meter, which read as a progress bar rather than as
                one period of a trend. Presentation only - the points come
                straight from AnalyticsService::trend().
            --}}
            <div class="analytics-card-body is-plot">
                @include('analytics.partials.trend-bars', ['trend' => $trend])
            </div>

            <p class="analytics-insight-strip">
                <x-icon name="lightbulb" size="14" aria-hidden="true" />
                <span>{{ $trend['summary'] }}</span>
            </p>
        @endif
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.insights']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="lightbulb" size="15" /></span>
            <div>
                <h2>Priority Insights</h2>
                <p>Key operational signals for the selected period and current state.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.insights']) }}" aria-label="View Priority Insights details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body is-flush">
            <ul class="analytics-priority">
                @foreach($priorityRows as $row)
                    <li class="analytics-priority-row tone-{{ $row['tone'] }}">
                        @if($row['href'])
                            <a href="{{ $row['href'] }}">
                                <span class="analytics-priority-icon" aria-hidden="true"><x-icon :name="$row['icon']" size="15" /></span>
                                <span class="analytics-priority-body">
                                    <span class="analytics-priority-title">{{ $row['title'] }}</span>
                                    <span class="analytics-priority-text">{{ $row['text'] }}</span>
                                </span>
                                <x-icon name="chevron-right" size="14" class="analytics-priority-arrow" />
                            </a>
                        @else
                            <div>
                                <span class="analytics-priority-icon" aria-hidden="true"><x-icon :name="$row['icon']" size="15" /></span>
                                <span class="analytics-priority-body">
                                    <span class="analytics-priority-title">{{ $row['title'] }}</span>
                                    <span class="analytics-priority-text">{{ $row['text'] }}</span>
                                </span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</div>

{{-- Rankings ---------------------------------------------------------- --}}
<div class="analytics-overview-rankings">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.released']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
            <div>
                <h2>Top Released Items</h2>
                <p>By quantity physically released.</p>
            </div>
            @if($topItems !== [])
                <a class="analytics-card-action" href="{{ route('analytics.index', $carry + ['section' => 'demand']) }}">
                    View all
                    <x-icon name="chevron-right" size="13" />
                </a>
            @endif
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.released']) }}" aria-label="View Top Released Items details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($topItems === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No equipment was physically released during this period.
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($topItems as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('equipment', 'overview', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Physically released', ($row['released'] + 0).' '.$row['unit']],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['name'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span class="analytics-rank-fill" style="width: {{ max(3, $row['share']) }}%"></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $row['released'] + 0 }}
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
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.units']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
            <div>
                <h2>Top Borrowing Units</h2>
                <p>By requests filed this period.</p>
            </div>
            @if($rankedUnits->isNotEmpty())
                <a class="analytics-card-action" href="{{ route('analytics.index', $carry + ['section' => 'demand']) }}">
                    View all
                    <x-icon name="chevron-right" size="13" />
                </a>
            @endif
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.units']) }}" aria-label="View Top Borrowing Units details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($rankedUnits->isEmpty())
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
                    No borrowing unit activity available for this period.
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($rankedUnits as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('unit', 'overview', $periodSelection, $row['division_code'], $row['name'], ['for' => $row['name']]) }}"
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
                                        <small>{{ $row['division_label'] }}</small>
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

{{-- Period outcomes --------------------------------------------------- --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.snapshot']) }}"
        class="analytics-card analytics-snapshot">
    <div class="analytics-snapshot-head">
        <h2>Reporting Period Snapshot</h2>
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.snapshot']) }}" aria-label="View Reporting Period Snapshot details"><x-icon name="chevron-right" size="15" /></a>
    </div>

    <div class="analytics-snapshot-strip">
        @foreach($snapshot as $tile)
            <div class="analytics-snapshot-tile tone-{{ $tile['tone'] }}">
                <span class="analytics-snapshot-icon" aria-hidden="true"><x-icon :name="$tile['icon']" size="15" /></span>
                <span class="analytics-snapshot-body">
                    <span class="analytics-snapshot-label">{{ $tile['label'] }}</span>
                    <strong class="analytics-snapshot-value">{{ $tile['value'] }}</strong>
                </span>
            </div>
        @endforeach
    </div>
</section>
