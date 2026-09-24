@php
    /*
    | Borrowing & Return Performance.
    |
    | The tab answers one question: how are returns, overdue follow-up and
    | accountability outcomes performing? Borrowing demand - who borrows most,
    | which units borrow most - is a different question and lives in Demand &
    | Utilization, so it is not repeated here.
    |
    | Two measures are kept strictly apart, because conflating them would
    | misstate both:
    |
    |   CURRENTLY OVERDUE - still physically out after the due date. Present
    |                       tense, not limited by the reporting period.
    |   RETURNED LATE     - came back, only after the due date. A finished
    |                       borrowing inside the reporting period.
    |
    | Nothing is calculated in this file. Every figure is computed in
    | AnalyticsService and handed over by AnalyticsController, and a reading
    | that does not exist is stated as absent rather than shown as zero.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;

    $completed = $returns['completed'];

    /*
     * The donut describes completed returns only. A borrowing that is still
     * out has no return outcome yet, so currently-overdue cases are not a
     * slice of it.
     */
    $onTimePct = $completed > 0 ? round($returns['on_time'] / $completed * 100) : null;
    $latePct = $completed > 0 ? 100 - $onTimePct : null;

    $trendPlotted = $returnTrend['plotted'];

    $returnTrendMode = match (true) {
        $trendPlotted === 0 => 'empty',
        count($returnTrend['points']) === 1 => 'single',
        default => 'line',
    };

    $singleReturnPoint = $returnTrend['points'][0] ?? null;

    /* Only conditions someone actually recorded are charted. */
    $conditionRows = $returnConditions['rows'];

    $lifecycleMax = max(1, max(array_column($lifecycle, 'value')));

    /*
     * Issue counts, each from a figure the services already computed. A
     * category with nothing recorded is dropped rather than drawn as a zero.
     */
    $issueRows = [];

    if ($returns['open_cases'] > 0) {
        $issueRows[] = ['label' => 'Open accountability cases', 'value' => $returns['open_cases'], 'tone' => 'risk'];
    }

    foreach ($incidents['types'] as $type) {
        $issueRows[] = [
            'label' => $type['type'],
            'value' => $type['count'],
            'tone' => 'warn',
            'meta' => ($type['quantity'] + 0).' units affected',
        ];
    }

    $resolvedIncidents = $incidents['total'] - $incidents['open'];

    if ($resolvedIncidents > 0) {
        $issueRows[] = ['label' => 'Resolved incidents', 'value' => $resolvedIncidents, 'tone' => 'good'];
    }

    /*
     * A rate needs a denominator. With no completed returns the honest reading
     * is "not measurable", which is a different statement from 0%.
     */
    /*
     * Completed returns and the on-time rate each carry one period-over-period
     * line here and nowhere else on this tab: the KPI cards above compare the
     * on-time and late counts, so the concepts are not repeated. The late
     * rate is the on-time rate's complement and is not shown a second time.
     */
    $summaryTiles = [
        [
            'label' => 'Completed returns',
            'value' => (string) $completed,
            'measurable' => true,
            'comparison' => $returnComparison['completed'],
        ],
        [
            'label' => 'On-time return rate',
            'value' => $returns['on_time_rate'] === null ? 'Not measurable' : $returns['on_time_rate'].'%',
            'measurable' => $returns['on_time_rate'] !== null,
            'comparison' => $returnComparison['on_time_rate'],
        ],
        [
            'label' => 'Average borrowing duration',
            'value' => $returns['average_duration']['label'] ?? 'Not available',
            'measurable' => ($returns['average_duration']['label'] ?? null) !== null,
        ],
    ];
@endphp

{{-- Headline figures ------------------------------------------------- --}}
<div class="analytics-kpis">
    <a
        class="analytics-kpi-card tone-ontime"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'on-time']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
        <span class="analytics-kpi-card-label">Returned On Time</span>
        <strong class="analytics-kpi-card-value">{{ $returns['on_time'] }}</strong>
        <span class="analytics-kpi-card-note">Returned on or before the due date</span>
        {{--
            Completed returns are period outcomes, so they carry a previous
            period. Currently Overdue and Open Accountability below do not:
            one is today's backlog, the other a hybrid of opening date and
            present status, and neither has an equivalent past reading.
        --}}
        @include('analytics.partials.period-delta', ['comparison' => $returnComparison['on_time'], 'deltaClass' => 'analytics-kpi-card-meta'])
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-overdue"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'late']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="clock" size="19" /></span>
        <span class="analytics-kpi-card-label">Returned Late</span>
        <strong class="analytics-kpi-card-value">{{ $returns['late'] }}</strong>
        <span class="analytics-kpi-card-note">Returned after the due date</span>
        @include('analytics.partials.period-delta', ['comparison' => $returnComparison['late'], 'deltaClass' => 'analytics-kpi-card-meta'])
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-stock"
        href="{{ AnalyticsDetailLink::to('follow-up', 'returns', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="warning" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Overdue</span>
        <strong class="analytics-kpi-card-value">{{ $returns['overdue'] }}</strong>
        <span class="analytics-kpi-card-note">Still out past the return date</span>
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-custody"
        href="{{ AnalyticsDetailLink::to('card', 'returns', $periodSelection, $division, $unit, ['for' => 'returns.issues']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="accountability" size="19" /></span>
        <span class="analytics-kpi-card-label">Open Accountability</span>
        <strong class="analytics-kpi-card-value">{{ $returns['open_cases'] }}</strong>
        <span class="analytics-kpi-card-note">Cases opened in the selected period that remain unresolved.</span>
        <x-icon name="arrow-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Return trend and outcome ------------------------------------------ --}}
<div class="analytics-overview-main">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.trend']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
            <div>
                <h2>Return Performance Trend</h2>
                <p>Completed returns per {{ $returnTrend['granularity'] }}, split into on time and late.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.trend']) }}" aria-label="View Return Performance Trend details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        @if($returnTrendMode === 'empty')
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="custody" size="19" /></span>
                    No completed returns during this period.
                </p>
            </div>
        @elseif($returnTrendMode === 'single')
            <div class="analytics-card-body analytics-trend-single">
                <div class="analytics-trend-single-figure">
                    <span class="analytics-trend-single-value">
                        <strong>{{ $singleReturnPoint['on_time'] + $singleReturnPoint['late'] }}</strong>
                        completed
                    </span>
                    <span class="analytics-return-split">
                        <span class="is-ontime">{{ $singleReturnPoint['on_time'] }} on time</span>
                        <span class="is-late">{{ $singleReturnPoint['late'] }} late</span>
                    </span>
                    <span class="analytics-trend-single-label">{{ $singleReturnPoint['label'] }}</span>
                </div>
            </div>
        @else
            <div class="analytics-card-body is-plot">
                @include('analytics.partials.return-trend-line', ['returnTrend' => $returnTrend])
            </div>
        @endif

        @if($returnTrendMode !== 'empty' && $trendPlotted < $returnTrend['total'])
            <footer class="analytics-card-foot">
                {{ $returnTrend['total'] - $trendPlotted }} completed
                {{ $returnTrend['total'] - $trendPlotted === 1 ? 'return' : 'returns' }}
                closed outside this period, not plotted.
            </footer>
        @endif
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.outcome']) }}"
        class="analytics-card analytics-return-outcome">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="check-circle" size="15" /></span>
            <div>
                <h2>Return Outcome</h2>
                <p>Completed returns only.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.outcome']) }}" aria-label="View Return Outcome details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        @if($completed === 0)
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                    No completed returns; outcome not yet measurable.
                </p>
            </div>
        @else
            @php
                /* One ring, two arcs. Circumference of r=42 is 2*pi*42. */
                $circumference = 263.894;
                $onTimeArc = round($onTimePct / 100 * $circumference, 3);
            @endphp

            <div class="analytics-card-body analytics-donut-body">
                <div class="analytics-donut" role="img" aria-label="{{ $onTimePct }}% of {{ $completed }} completed returns were on time">
                    <svg viewBox="0 0 100 100" focusable="false" aria-hidden="true">
                        <circle class="analytics-donut-track" cx="50" cy="50" r="42" />
                        <circle
                            class="analytics-donut-arc is-late"
                            cx="50" cy="50" r="42"
                            stroke-dasharray="{{ $circumference }} {{ $circumference }}"
                            stroke-dashoffset="0"
                            tabindex="0"
                            role="img"
                            data-chart-tip
                            data-tip-title="Returned late"
                            data-tip-rows="{{ json_encode([
                                [$returns['late'] === 1 ? 'Return' : 'Returns', (string) $returns['late']],
                                ['Share of completed returns', $latePct.'%'],
                            ]) }}"
                            aria-label="Returned late: {{ $returns['late'] }} of {{ $completed }} completed returns, {{ $latePct }} percent"
                        />
                        <circle
                            class="analytics-donut-arc is-ontime"
                            cx="50" cy="50" r="42"
                            stroke-dasharray="{{ $onTimeArc }} {{ round($circumference - $onTimeArc, 3) }}"
                            stroke-dashoffset="0"
                            tabindex="0"
                            role="img"
                            data-chart-tip
                            data-tip-title="Returned on time"
                            data-tip-rows="{{ json_encode([
                                [$returns['on_time'] === 1 ? 'Return' : 'Returns', (string) $returns['on_time']],
                                ['Share of completed returns', $onTimePct.'%'],
                            ]) }}"
                            aria-label="Returned on time: {{ $returns['on_time'] }} of {{ $completed }} completed returns, {{ $onTimePct }} percent"
                        />
                    </svg>
                    <span class="analytics-donut-centre">
                        <strong>{{ $completed }}</strong>
                        <small>Completed returns</small>
                    </span>
                </div>

                <dl class="analytics-donut-legend">
                    <div class="is-ontime">
                        <dt>On time</dt>
                        <dd>
                            <strong class="analytics-outcome-count">{{ $returns['on_time'] }}</strong>
                            <span class="analytics-outcome-rate">{{ $onTimePct }}%</span>
                        </dd>
                    </div>
                    <div class="is-late">
                        <dt>Late</dt>
                        <dd>
                            <strong class="analytics-outcome-count">{{ $returns['late'] }}</strong>
                            <span class="analytics-outcome-rate">{{ $latePct }}%</span>
                        </dd>
                    </div>
                </dl>
            </div>
        @endif
    </section>
</div>

{{-- Where late returns concentrate ----------------------------------------- --}}
@php
    /*
     | Late Return Patterns: the completed returns already counted above
     | (Returned On Time + Returned Late, by physical completion date),
     | split by the organisation recorded on the request at borrowing time.
     | Each bar is a rate of that segment's own completed returns, so the
     | track is 0-100 and a 20% rate fills a fifth of it; the counts are
     | always printed beside it so the sample is never hidden. Every figure
     | comes from AnalyticsService::lateReturnRates(); nothing is computed
     | here, and no comparison with a previous period is drawn.
     */
    $lateRateLink = static fn (string $level, ?array $row = null): string => AnalyticsDetailLink::to(
        'late-rate', 'returns', $periodSelection, $division, $unit,
        ['level' => $level]
            + ($row ? ['for' => $row['code'] ?? 'unspecified'] : [])
            + ($row && $level === 'unit' ? ['segment' => $row['unit']] : [])
    );

    $rateText = static fn (?float $rate): string => $rate === null ? '—' : $rate.'%';

    /* The card shows the leading rows; the detail lists every segment. */
    $lateRatePanels = [
        ['level' => 'division', 'title' => 'Late Return Rate by Division', 'data' => $lateRatesByDivision, 'shown' => 4],
        ['level' => 'unit', 'title' => 'Late Return Rate by Unit', 'data' => $lateRatesByUnit, 'shown' => 6],
    ];
@endphp
<section
        data-card-detail="{{ $lateRateLink('division') }}"
        class="analytics-card analytics-laterate">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
        <div>
            <h2>Late Return Patterns</h2>
            <p>Late-return share among completed returns in the selected period.</p>
        </div>
        <a class="analytics-card-open" href="{{ $lateRateLink('division') }}" aria-label="View Late Return Patterns details"><x-icon name="arrow-right" size="15" /></a>
    </header>

    @if(! $lateRatesByDivision['available'])
        <div class="analytics-card-body">
            <p class="analytics-blank">
                <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
                No completed returns are available for late-return rate analysis in this period.
            </p>
        </div>
    @else
        <div class="analytics-card-body analytics-laterate-body">
            @foreach($lateRatePanels as $panel)
                @php
                    $rows = array_slice($panel['data']['groups'], 0, $panel['shown']);
                    $hidden = count($panel['data']['groups']) - count($rows);
                @endphp
                <div class="analytics-laterate-panel">
                    <h3 class="analytics-laterate-title">
                        <a href="{{ $lateRateLink($panel['level']) }}">{{ $panel['title'] }}</a>
                    </h3>

                    @if($rows === [])
                        <p class="analytics-laterate-none">
                            {{ $panel['level'] === 'unit'
                                ? 'No completed return in this period carries a recorded unit.'
                                : 'No completed returns in this period.' }}
                        </p>
                    @else
                        <ul class="analytics-laterate-rows" aria-label="{{ $panel['title'] }}">
                            @foreach($rows as $row)
                                <li>
                                    <a
                                        href="{{ $lateRateLink($panel['level'], $row) }}"
                                        data-chart-tip
                                        data-tip-title="{{ $row['label'] }}{{ $panel['level'] === 'unit' ? ' · '.$row['division_label'] : '' }}"
                                        data-tip-rows="{{ json_encode([
                                            ['Late return rate', $rateText($row['late_rate'])],
                                            ['Returned late', (string) $row['late']],
                                            ['Completed returns', (string) $row['completed']],
                                        ]) }}"
                                        aria-label="View details for {{ $row['label'] }}: late return rate {{ $rateText($row['late_rate']) }}, {{ $row['late'] }} late of {{ $row['completed'] }} completed returns"
                                    >
                                        <span class="analytics-laterate-head">
                                            <span class="analytics-laterate-name">
                                                {{ $row['label'] }}
                                                @if($panel['level'] === 'unit')
                                                    <small class="analytics-rank-tag is-{{ strtolower(explode('_', (string) ($row['code'] ?? 'unspecified'))[0]) }}">{{ $row['division_label'] }}</small>
                                                @endif
                                            </span>
                                            <strong class="analytics-laterate-rate">{{ $rateText($row['late_rate']) }}</strong>
                                        </span>
                                        {{-- The track is 0-100: the fill is the rate itself, not a share of the largest row. --}}
                                        <span class="analytics-laterate-track" aria-hidden="true">
                                            <span class="analytics-laterate-fill" style="width: {{ $row['late_rate'] ?? 0 }}%"></span>
                                        </span>
                                        <span class="analytics-laterate-counts">{{ $row['late'] }} late · {{ $row['completed'] }} completed</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>

                        @if($hidden > 0)
                            <a class="analytics-laterate-more" href="{{ $lateRateLink($panel['level']) }}">
                                View all {{ count($panel['data']['groups']) }} {{ $panel['level'] === 'unit' ? 'units' : 'classifications' }}
                                <x-icon name="arrow-right" size="13" />
                            </a>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>

        <footer class="analytics-card-foot">
            Rates are shown with their counts; a small count is a small sample.
            @if($selectedBorrower)
                Scoped to the selected borrower's completed returns.
            @endif
        </footer>

        @if($lateRatesByDivision['summary'])
            <p class="analytics-insight-strip">
                <x-icon name="information" size="14" aria-hidden="true" />
                <span>{{ $lateRatesByDivision['summary'] }}</span>
            </p>
        @endif
    @endif
</section>

{{-- Lifecycle ---------------------------------------------------------- --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.lifecycle']) }}"
        class="analytics-card analytics-lifecycle">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="custody" size="15" /></span>
        <div>
            <h2>Borrowing Lifecycle</h2>
        </div>
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.lifecycle']) }}" aria-label="View Borrowing Lifecycle details"><x-icon name="arrow-right" size="15" /></a>
    </header>

    <div class="analytics-lifecycle-strip">
        @foreach($lifecycle as $index => $stage)
            <div class="analytics-lifecycle-stage">
                <span class="analytics-lifecycle-label">{{ $stage['label'] }}</span>
                <strong class="analytics-lifecycle-value">{{ $stage['value'] }}</strong>
                <span class="analytics-lifecycle-note">{{ $stage['note'] }}</span>
                <span
                    class="analytics-lifecycle-bar"
                    style="width: {{ $stage['value'] > 0 ? max(6, round($stage['value'] / $lifecycleMax * 100)) : 0 }}%"
                    aria-hidden="true"
                ></span>
            </div>

            @unless($loop->last)
                <span class="analytics-lifecycle-link" aria-hidden="true"><x-icon name="arrow-right" size="15" /></span>
            @endunless
        @endforeach
    </div>
</section>

{{-- Overdue follow-up and return condition ----------------------------- --}}
<div class="analytics-overview-rankings">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.followup']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="warning" size="15" /></span>
            <div>
                <h2>Current Overdue Follow-up</h2>
            </div>
            @if($currentOverdue['total'] > $currentOverdue['shown'])
                <a class="analytics-card-action" href="{{ AnalyticsDetailLink::to('follow-up', 'returns', $periodSelection, $division, $unit) }}">
                    View all {{ $currentOverdue['total'] }}
                    <x-icon name="arrow-right" size="13" />
                </a>
            @endif
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.followup']) }}" aria-label="View Current Overdue Follow-up details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($currentOverdue['cases'] === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark is-good" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                    No borrowings are currently overdue.
                </p>
            @else
                <ul class="analytics-followup">
                    @foreach($currentOverdue['cases'] as $case)
                        <li>
                            <a
                                class="analytics-followup-row"
                                href="{{ AnalyticsDetailLink::to('follow-up', 'returns', $periodSelection, $division, $unit) }}"
                            >
                                <span class="analytics-followup-main">
                                    <span class="analytics-followup-name">{{ $case['borrower'] }}</span>
                                    <span class="analytics-followup-ref">
                                        {{ $case['custody_no'] }}
                                        @if($case['request_no']) &middot; {{ $case['request_no'] }} @endif
                                    </span>
                                </span>
                                <span class="analytics-followup-due">
                                    <span>Due {{ optional($case['due_at'])->format('d M Y') ?: '—' }}</span>
                                    <strong>
                                        {{ $case['days_overdue'] === null
                                            ? 'Overdue'
                                            : $case['days_overdue'].' '.($case['days_overdue'] === 1 ? 'day' : 'days').' overdue' }}
                                    </strong>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.condition']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
            <div>
                <h2>Return Condition</h2>
                <p>Recorded at return inspection, by quantity received.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.condition']) }}" aria-label="View Return Condition details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($conditionRows === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No return inspections were recorded during this period.
                </p>
            @else
                @php
                    $conditionTotal = max(1, (float) $returnConditions['total']);
                @endphp
                <div class="analytics-condition-composition" role="img" aria-label="Return condition composition">
                    <div class="analytics-condition-bar">
                        @foreach($conditionRows as $row)
                            @php $pct = round(((float) $row['quantity'] / $conditionTotal) * 100, 1); @endphp
                            <span
                                class="analytics-condition-segment {{ $row['is_fine'] ? 'is-good' : 'is-issue' }}"
                                style="width: {{ $pct }}%"
                                tabindex="0"
                                role="img"
                                data-chart-tip
                                data-tip-title="{{ $row['label'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Quantity received', (string) $row['quantity']],
                                    ['Share of returned quantity', $pct.'%'],
                                ]) }}"
                                aria-label="{{ $row['label'] }}: {{ $row['quantity'] }} received, {{ $pct }} percent"
                            ></span>
                        @endforeach
                    </div>

                    <ul class="analytics-condition-legend">
                        @foreach($conditionRows as $row)
                            @php $pct = round(((float) $row['quantity'] / $conditionTotal) * 100, 1); @endphp
                            <li>
                                <span class="analytics-condition-key {{ $row['is_fine'] ? 'is-good' : 'is-issue' }}" aria-hidden="true"></span>
                                <span>{{ $row['label'] }}</span>
                                <strong>{{ $row['quantity'] }}</strong>
                                <small>{{ $pct }}%</small>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>
</div>

{{-- Age of the current overdue backlog ---------------------------------- --}}
@php
    /*
     | Overdue Aging: the Currently Overdue population - the same query as the
     | KPI above and the follow-up list beside it - grouped by whole days past
     | the effective due date. Current state only: no reporting period and no
     | previous-period comparison apply, because today's backlog has no
     | equivalent past reading. Every count, share and width comes from
     | AnalyticsService::overdueAging(); nothing is recalculated here.
     */
    $agingLink = static fn (?string $bucket = null): string => AnalyticsDetailLink::to(
        'overdue-aging', 'returns', $periodSelection, $division, $unit, $bucket ? ['bucket' => $bucket] : []
    );
@endphp
<section
        data-card-detail="{{ $agingLink() }}"
        class="analytics-card analytics-aging">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clock" size="15" /></span>
        <div>
            <h2>Overdue Aging</h2>
            <p>Current overdue borrowings grouped by days past due.</p>
        </div>
        @if($overdueAging['available'])
            <span class="analytics-count-pill">{{ $overdueAging['total'] }} overdue</span>
        @endif
        <a class="analytics-card-open" href="{{ $agingLink() }}" aria-label="View Overdue Aging details"><x-icon name="arrow-right" size="15" /></a>
    </header>

    @if(! $overdueAging['available'])
        <div class="analytics-card-body">
            <p class="analytics-blank">
                <span class="analytics-blank-mark is-good" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                No borrowings are currently overdue.
            </p>
        </div>
    @else
        <div class="analytics-card-body">
            {{--
                Three bands, counts first. Width is the band against the largest
                band - geometry only - and the share beside it is the band's
                part of the currently overdue backlog, labelled as such in the
                tooltip. Each row opens that band's detail.
            --}}
            <ul class="analytics-aging-bands" aria-label="Currently overdue borrowings by days past due">
                @foreach($overdueAging['groups'] as $row)
                    <li class="{{ $row['count'] === 0 ? 'is-none' : '' }}">
                        <a
                            href="{{ $agingLink($row['key']) }}"
                            data-chart-tip
                            data-tip-title="{{ $row['label'] }} past due"
                            data-tip-rows="{{ json_encode([
                                [$row['count'] === 1 ? 'Borrowing' : 'Borrowings', (string) $row['count']],
                                ['Share of currently overdue', $row['share'].'%'],
                            ]) }}"
                            aria-label="View details for {{ $row['label'] }} past due: {{ $row['count'] }} {{ $row['count'] === 1 ? 'borrowing' : 'borrowings' }}, {{ $row['share'] }} percent of currently overdue"
                        >
                            <span class="analytics-aging-label">{{ $row['label'] }}</span>
                            <span class="analytics-aging-track" aria-hidden="true">
                                <span class="analytics-aging-fill" style="width: {{ $row['width'] }}%"></span>
                            </span>
                            <span class="analytics-aging-value">
                                {{ $row['count'] }}
                                <small>{{ $row['share'] }}%</small>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if($overdueAging['unbanded'] > 0)
                <p class="analytics-aging-note">
                    {{ $overdueAging['unbanded'] }} {{ $overdueAging['unbanded'] === 1 ? 'borrowing is' : 'borrowings are' }}
                    flagged overdue but not yet a full day past due, and {{ $overdueAging['unbanded'] === 1 ? 'is' : 'are' }} counted in the total above.
                </p>
            @endif
        </div>

        <footer class="analytics-card-foot">
            Current state as of today, not limited to the reporting period.
        </footer>

        @if($overdueAging['insight'])
            <p class="analytics-insight-strip">
                <x-icon name="information" size="14" aria-hidden="true" />
                <span>{{ $overdueAging['insight'] }}</span>
            </p>
        @endif
    @endif
</section>

{{-- Summary and issues ------------------------------------------------- --}}
<div class="analytics-overview-rankings">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.summary']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="reports" size="15" /></span>
            <div>
                <h2>Return Performance Summary</h2>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.summary']) }}" aria-label="View Return Performance Summary details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            <div class="analytics-ministats">
                @foreach($summaryTiles as $tile)
                    <div class="analytics-ministat{{ $tile['measurable'] ? '' : ' is-unmeasured' }}">
                        <span>{{ $tile['label'] }}</span>
                        <strong>{{ $tile['value'] }}</strong>
                        @isset($tile['comparison'])
                            @include('analytics.partials.period-delta', ['comparison' => $tile['comparison']])
                        @endisset
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.issues']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="accountability" size="15" /></span>
            <div>
                <h2>Accountability &amp; Return Issues</h2>
                <p>Recorded against this period's borrowings.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.issues']) }}" aria-label="View Accountability and Return Issues details"><x-icon name="arrow-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($issueRows === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark is-good" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                    No accountability or return issues recorded.
                </p>
            @else
                <ul class="analytics-issues">
                    @foreach($issueRows as $issue)
                        <li class="tone-{{ $issue['tone'] }}">
                            <span class="analytics-issues-label">
                                {{ $issue['label'] }}
                                @isset($issue['meta'])<small>{{ $issue['meta'] }}</small>@endisset
                            </span>
                            <strong>{{ $issue['value'] }}</strong>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</div>
