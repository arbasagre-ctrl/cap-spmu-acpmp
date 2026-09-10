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

    if ($returns['late'] > 0) {
        $issueRows[] = ['label' => 'Late returns recorded', 'value' => $returns['late'], 'tone' => 'warn'];
    }

    $resolvedIncidents = $incidents['total'] - $incidents['open'];

    if ($resolvedIncidents > 0) {
        $issueRows[] = ['label' => 'Resolved incidents', 'value' => $resolvedIncidents, 'tone' => 'good'];
    }

    /*
     * A rate needs a denominator. With no completed returns the honest reading
     * is "not measurable", which is a different statement from 0%.
     */
    $summaryTiles = [
        [
            'label' => 'Completed returns',
            'value' => (string) $completed,
            'measurable' => true,
        ],
        [
            'label' => 'On-time return rate',
            'value' => $returns['on_time_rate'] === null ? 'Not measurable' : $returns['on_time_rate'].'%',
            'measurable' => $returns['on_time_rate'] !== null,
        ],
        [
            'label' => 'Late return rate',
            'value' => $returns['late_rate'] === null ? 'Not measurable' : $returns['late_rate'].'%',
            'measurable' => $returns['late_rate'] !== null,
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
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-overdue"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'late']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="clock" size="19" /></span>
        <span class="analytics-kpi-card-label">Returned Late</span>
        <strong class="analytics-kpi-card-value">{{ $returns['late'] }}</strong>
        <span class="analytics-kpi-card-note">Returned after the due date</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-stock"
        href="{{ AnalyticsDetailLink::to('follow-up', 'returns', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="warning" size="19" /></span>
        <span class="analytics-kpi-card-label">Currently Overdue</span>
        <strong class="analytics-kpi-card-value">{{ $returns['overdue'] }}</strong>
        <span class="analytics-kpi-card-note">Still out past the return date</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-custody"
        href="{{ AnalyticsDetailLink::to('card', 'returns', $periodSelection, $division, $unit, ['for' => 'returns.issues']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="accountability" size="19" /></span>
        <span class="analytics-kpi-card-label">Open Accountability</span>
        <strong class="analytics-kpi-card-value">{{ $returns['open_cases'] }}</strong>
        <span class="analytics-kpi-card-note">Unresolved accountability cases</span>
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.trend']) }}" aria-label="View Return Performance Trend details"><x-icon name="chevron-right" size="15" /></a>
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
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="check-circle" size="15" /></span>
            <div>
                <h2>Return Outcome</h2>
                <p>Completed returns only.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.outcome']) }}" aria-label="View Return Outcome details"><x-icon name="chevron-right" size="15" /></a>
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
                        <dd>{{ $returns['on_time'] }} <span>&middot; {{ $onTimePct }}%</span></dd>
                    </div>
                    <div class="is-late">
                        <dt>Late</dt>
                        <dd>{{ $returns['late'] }} <span>&middot; {{ $latePct }}%</span></dd>
                    </div>
                </dl>
            </div>
        @endif
    </section>
</div>

{{-- Lifecycle ---------------------------------------------------------- --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.lifecycle']) }}"
        class="analytics-card analytics-lifecycle">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="custody" size="15" /></span>
        <div>
            <h2>Borrowing Lifecycle</h2>
        </div>
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.lifecycle']) }}" aria-label="View Borrowing Lifecycle details"><x-icon name="chevron-right" size="15" /></a>
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
                <span class="analytics-lifecycle-link" aria-hidden="true"><x-icon name="chevron-right" size="15" /></span>
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
                    <x-icon name="chevron-right" size="13" />
                </a>
            @endif
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.followup']) }}" aria-label="View Current Overdue Follow-up details"><x-icon name="chevron-right" size="15" /></a>
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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.condition']) }}" aria-label="View Return Condition details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($conditionRows === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No return inspections were recorded during this period.
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($conditionRows as $row)
                        <li>
                            <span
                                class="analytics-rank-row is-static"
                                tabindex="0"
                                role="img"
                                data-chart-tip
                                data-tip-title="{{ $row['label'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Quantity received', (string) $row['quantity']],
                                ]) }}"
                                aria-label="{{ $row['label'] }}: {{ $row['quantity'] }} received"
                            >
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['label'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span
                                            class="analytics-rank-fill {{ $row['is_fine'] ? 'is-good' : 'is-issue' }}"
                                            style="width: {{ max(3, $row['share']) }}%"
                                        ></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">{{ $row['quantity'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>

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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.summary']) }}" aria-label="View Return Performance Summary details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            <div class="analytics-ministats">
                @foreach($summaryTiles as $tile)
                    <div class="analytics-ministat{{ $tile['measurable'] ? '' : ' is-unmeasured' }}">
                        <span>{{ $tile['label'] }}</span>
                        <strong>{{ $tile['value'] }}</strong>
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
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'returns', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'returns.issues']) }}" aria-label="View Accountability and Return Issues details"><x-icon name="chevron-right" size="15" /></a>
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
