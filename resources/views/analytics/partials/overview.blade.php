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

    $trendMode = $trend['points'] === [] || ! $trendFilled ? 'empty' : 'line';

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
            'text' => $returns['on_time'].' of '.$returns['completed'].' completed '
                .($returns['completed'] === 1 ? 'return was' : 'returns were').' on time ('
                .$returns['on_time_rate'].'%).',
            'href' => AnalyticsDetailLink::to(
                'card',
                'overview',
                $periodSelection,
                $division,
                $unit,
                ['for' => 'overview.return-compliance']
            ),
        ];

    usort($priorityRows, fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);
    $priorityRows = array_slice($priorityRows, 0, 4);

@endphp

@include('analytics.partials.overview-summary-styles')

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
        {{--
            Period-scoped, so it has a previous period to stand against. The
            three cards beside it are current-state and deliberately do not.
        --}}
        @include('analytics.partials.period-delta', ['comparison' => $demandComparison['requests'], 'deltaClass' => 'analytics-kpi-card-meta'])
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-custody"
        href="{{ AnalyticsDetailLink::to('currently-out', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="custody" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Out</span>
        <strong class="analytics-kpi-card-value">{{ $overview['on_custody'] }}</strong>
        <span class="analytics-kpi-card-note">Released, not yet returned</span>
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-overdue"
        href="{{ AnalyticsDetailLink::to('follow-up', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="warning" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Overdue</span>
        <strong class="analytics-kpi-card-value">{{ $overview['needs_follow_up'] }}</strong>
        <span class="analytics-kpi-card-note">Borrowings past their return date</span>
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-stock"
        href="{{ AnalyticsDetailLink::to('low-availability', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="inventory" size="19" /></span>
        <span class="analytics-kpi-card-label">Low Availability</span>
        <strong class="analytics-kpi-card-value">{{ $lowAvailability['count'] }}</strong>
        <span class="analytics-kpi-card-note">At or below {{ $lowAvailabilityPercent }}% usable stock</span>
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Trend and priority signals ---------------------------------------- --}}
@if($detail)
    @include('analytics.partials.detail-panel')
@endif

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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.trend']) }}" aria-label="View Borrowing Demand Trend details"><x-icon name="arrow-right" size="15" /></a>
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
                This is an ordered time series, so a line communicates movement
                between periods better than independent columns. Data and drill-
                down links remain exactly the same.
            --}}
            <div class="analytics-card-body is-plot">
                @include('analytics.partials.trend-line', ['trend' => $trend, 'lineSection' => 'overview'])
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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'overview', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'overview.insights']) }}" aria-label="View Priority Insights details"><x-icon name="arrow-right" size="15" /></a>
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
                                <x-icon name="arrow-right" size="14" class="analytics-priority-arrow" />
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

{{-- Cross-tab executive summary -------------------------------------- --}}
<section class="analytics-card analytics-summary-shell">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
        <div>
            <h2>Analytics Summary</h2>
            <p>Headline readings from each detailed analytics workspace.</p>
        </div>
    </header>

    <div class="analytics-summary-grid">
        <a class="analytics-summary-card" href="{{ route('analytics.index', $carry + ['section' => 'demand']) }}">
            <span class="analytics-summary-icon" aria-hidden="true"><x-icon name="analytics" size="16" /></span>
            <span class="analytics-summary-title">Demand &amp; Utilization</span>
            <x-icon name="arrow-right" size="14" class="analytics-summary-arrow" />

            <dl class="analytics-summary-metrics">
                <div>
                    <dt>Requested quantity</dt>
                    <dd>{{ number_format((float) $demandSummary['requested_quantity']) }}</dd>
                    @include('analytics.partials.period-delta', ['comparison' => $demandComparison['requested_quantity']])
                </div>
                <div>
                    <dt>Released quantity</dt>
                    <dd>{{ number_format((float) $demandSummary['released_quantity']) }}</dd>
                    @include('analytics.partials.period-delta', ['comparison' => $demandComparison['released_quantity']])
                </div>
                <div>
                    <dt>Active borrowing units</dt>
                    <dd>{{ number_format((int) $demandSummary['active_units']) }}</dd>
                </div>
            </dl>

            <span class="analytics-summary-status">
                {{ $peakSummary['available']
                    ? 'Peak: '.$peakSummary['peak_day'].' · '.$peakSummary['peak_hour']
                    : 'Peak pattern: not enough activity yet' }}
            </span>
        </a>

        <a class="analytics-summary-card" href="{{ route('analytics.index', $carry + ['section' => 'inventory']) }}">
            <span class="analytics-summary-icon" aria-hidden="true"><x-icon name="inventory" size="16" /></span>
            <span class="analytics-summary-title">Inventory Health</span>
            <x-icon name="arrow-right" size="14" class="analytics-summary-arrow" />

            <dl class="analytics-summary-metrics">
                <div>
                    <dt>Available units</dt>
                    <dd>{{ number_format((float) $inventorySummary['totals']['available']) }}</dd>
                </div>
                <div>
                    <dt>On custody</dt>
                    <dd>{{ number_format((float) $inventorySummary['totals']['on_custody']) }}</dd>
                </div>
            </dl>

            <span class="analytics-summary-status">
                Current physical stock across all organizational units
            </span>
        </a>

        <a class="analytics-summary-card" href="{{ route('analytics.index', $carry + ['section' => 'returns']) }}">
            <span class="analytics-summary-icon" aria-hidden="true"><x-icon name="custody" size="16" /></span>
            <span class="analytics-summary-title">Borrowing &amp; Return Performance</span>
            <x-icon name="arrow-right" size="14" class="analytics-summary-arrow" />

            <dl class="analytics-summary-metrics">
                <div>
                    <dt>Approved requests</dt>
                    <dd>{{ number_format((int) $overview['approved']) }}</dd>
                </div>
                <div>
                    <dt>Completed returns</dt>
                    <dd>{{ number_format((int) $returns['completed']) }}</dd>
                </div>
                <div>
                    <dt>Returned on time / Late</dt>
                    <dd>{{ number_format((int) $returns['on_time']) }} / {{ number_format((int) $returns['late']) }}</dd>
                </div>
            </dl>

            <span class="analytics-summary-status">
                <span title="Cases opened in the selected period that remain unresolved.">Open accountability: {{ number_format((int) $returns['open_cases']) }}</span>
                <span aria-hidden="true"> · </span>
                @if($returns['on_time_rate'] === null)
                    On-time return rate: not yet measurable
                @else
                    On-time return rate: {{ $returns['on_time_rate'] }}%
                    {{-- One rate, one comparison: the counts above are not repeated as deltas here. --}}
                    @include('analytics.partials.period-delta', ['comparison' => $returnComparison['on_time_rate']])
                @endif
            </span>
        </a>

        <a class="analytics-summary-card" href="{{ route('analytics.index', $carry + ['section' => 'predictive']) }}">
            <span class="analytics-summary-icon" aria-hidden="true"><x-icon name="calendar" size="16" /></span>
            <span class="analytics-summary-title">Forecast &amp; Planning</span>
            <x-icon name="arrow-right" size="14" class="analytics-summary-arrow" />

            <dl class="analytics-summary-metrics">
                <div>
                    <dt>Forecast readiness</dt>
                    <dd class="is-text">{{ $forecastSummary['ready'] ? 'Ready' : 'Limited history' }}</dd>
                </div>
                <div>
                    <dt>Scheduled next period</dt>
                    <dd>{{ number_format((int) $forecastSummary['scheduled']) }}</dd>
                </div>
                <div>
                    <dt>Forecasted demand</dt>
                    <dd class="{{ $forecastSummary['ready'] ? '' : 'is-text' }}">
                        {{ $forecastSummary['ready']
                            ? number_format((int) $forecastSummary['forecast']).' requests'
                            : 'Not available' }}
                    </dd>
                </div>
            </dl>

            <span class="analytics-summary-status">
                Next period: {{ $forecastSummary['from']->format('d M') }} – {{ $forecastSummary['to']->format('d M Y') }}
            </span>
        </a>
    </div>
</section>
