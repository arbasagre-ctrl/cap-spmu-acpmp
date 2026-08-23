@extends('layouts.app', ['title' => 'Reports & Analytics'])

@section('content')

@php
    $periodLabel = $from->isSameDay($to)
        ? $from->format('d M Y')
        : $from->format('d M Y').' – '.$to->format('d M Y');

    $requestOutcomeMax = max((int) ($requestOutcomes->max() ?? 0), 1);
    $custodyMax = max((int) ($custodyStatuses->max() ?? 0), 1);
    $utilizationMax = max((float) ($topItems->max('used_quantity') ?? 0), 1);
    $trendMax = max(
        (int) ($borrowingTrend->max('requests') ?? 0),
        (int) ($borrowingTrend->max('released') ?? 0),
        1
    );

    $approvalHours = intdiv($averageApprovalSeconds, 3600);
    $approvalMinutes = intdiv($averageApprovalSeconds % 3600, 60);

    $approvalLabel = $averageApprovalSeconds > 0
        ? trim(($approvalHours > 0 ? $approvalHours.'h ' : '').$approvalMinutes.'m')
        : 'N/A';

    $returnComplianceLabel = $returnCompliance['released'] > 0
        ? number_format($returnCompliance['percentage'], 1).'%'
        : 'N/A';

    $reportUrl = fn (string $report) => route('reports.index', [
        'tab' => 'reports',
        'report' => $report,
        'academic_period' => $periodSelection,
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
    ]);
@endphp

<style>
    .analytics-dashboard {
        --analytics-line: var(--border, #d7e0ea);
        --analytics-muted: var(--text-muted, #64748b);
        --analytics-ink: var(--text, #18324a);
        --analytics-soft: var(--surface-subtle, #f7f9fc);
        display: grid;
        gap: 16px;
    }

    .analytics-heading-actions,
    .analytics-module-row,
    .analytics-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .analytics-heading-actions {
        justify-content: flex-end;
    }

    .analytics-kpi-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }

    .analytics-kpi-link {
        color: inherit;
        text-decoration: none;
        transition: transform .16s ease, box-shadow .16s ease;
    }

    .analytics-kpi-link:hover {
        transform: translateY(-1px);
        box-shadow: 0 10px 26px rgba(16, 42, 67, .09);
    }

    .analytics-kpi-link .kpi-support {
        display: block;
        margin-top: 5px;
        color: var(--analytics-muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .analytics-kpi-link .kpi-drilldown,
    .analytics-card-link {
        color: var(--primary, #1769e0);
        font-size: 11px;
        font-weight: 800;
        text-decoration: none;
    }

    .analytics-kpi-link .kpi-drilldown {
        display: inline-flex;
        margin-top: 8px;
    }

    .analytics-grid-two {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }

    .analytics-card {
        min-width: 0;
        padding: 18px;
    }

    .analytics-card h2 {
        margin: 2px 0 4px;
    }

    .analytics-card .section-copy {
        margin: 0;
        color: var(--analytics-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .analytics-card-link {
        flex: 0 0 auto;
        padding-top: 3px;
    }

    .analytics-list {
        display: grid;
        gap: 10px;
        margin-top: 14px;
    }

    .analytics-list-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        min-height: 40px;
        padding: 9px 10px;
        border: 1px solid var(--analytics-line);
        border-radius: 9px;
        background: var(--surface, #fff);
    }

    a.analytics-list-row {
        color: inherit;
        text-decoration: none;
    }

    a.analytics-list-row:hover {
        border-color: #b9cbe0;
        background: #fbfdff;
    }

    .analytics-list-row strong {
        color: var(--analytics-ink);
    }

    .analytics-list-row small {
        display: block;
        margin-top: 2px;
        color: var(--analytics-muted);
        font-size: 10px;
    }

    .analytics-list-value {
        color: var(--analytics-ink);
        font-weight: 800;
        white-space: nowrap;
    }

    .trend-legend {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        margin-top: 12px;
        color: var(--analytics-muted);
        font-size: 11px;
        font-weight: 700;
    }

    .trend-legend span {
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .trend-legend i {
        width: 10px;
        height: 10px;
        border-radius: 3px;
        background: var(--info, #1769e0);
    }

    .trend-legend .released i {
        background: var(--success, #1f7a4d);
    }

    .trend-chart {
        display: grid;
        grid-template-columns: repeat(var(--trend-columns), minmax(86px, 1fr));
        gap: 12px;
        align-items: end;
        margin-top: 16px;
        padding: 14px 8px 6px;
        overflow-x: auto;
        overflow-y: visible;
        border-top: 1px solid var(--analytics-line);
    }

    .trend-column {
        position: relative;
        display: grid;
        grid-template-rows: 170px auto;
        gap: 8px;
        min-width: 86px;
        outline: none;
    }

    .trend-bars {
        display: flex;
        align-items: end;
        justify-content: center;
        gap: 8px;
        min-height: 170px;
        padding-top: 38px;
        box-sizing: border-box;
    }

    .trend-bar {
        position: relative;
        width: 22px;
        min-height: 4px;
        max-height: 126px;
        border-radius: 6px 6px 2px 2px;
        background: var(--info, #1769e0);
        transition: transform .16s ease, filter .16s ease;
    }

    .trend-bar.is-released {
        background: var(--success, #1f7a4d);
    }

    .trend-column:hover .trend-bar,
    .trend-column:focus .trend-bar {
        filter: saturate(1.08);
    }

    .trend-column:hover .trend-bar:first-of-type,
    .trend-column:focus .trend-bar:first-of-type {
        transform: translateY(-2px);
    }

    .trend-column:hover .trend-bar.is-released,
    .trend-column:focus .trend-bar.is-released {
        transform: translateY(-2px);
    }

    .trend-bar strong {
        position: absolute;
        left: 50%;
        bottom: calc(100% + 6px);
        transform: translateX(-50%);
        color: var(--analytics-ink);
        font-size: 10px;
        font-weight: 800;
        white-space: nowrap;
        pointer-events: none;
    }

    .trend-tooltip {
        position: absolute;
        top: 2px;
        left: 50%;
        z-index: 5;
        display: none;
        min-width: 154px;
        max-width: 210px;
        padding: 9px 10px;
        transform: translateX(-50%);
        border: 1px solid var(--analytics-line);
        border-radius: 9px;
        background: var(--surface, #fff);
        box-shadow: 0 8px 24px rgba(16, 42, 67, .14);
        color: var(--analytics-ink);
        text-align: left;
        pointer-events: none;
    }

    .trend-column:hover .trend-tooltip,
    .trend-column:focus .trend-tooltip {
        display: block;
    }

    .trend-tooltip strong {
        display: block;
        margin-bottom: 5px;
        font-size: 11px;
        line-height: 1.35;
    }

    .trend-tooltip span {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        color: var(--analytics-muted);
        font-size: 10px;
        line-height: 1.45;
    }

    .trend-tooltip span + span {
        margin-top: 2px;
    }

    .trend-tooltip b {
        color: var(--analytics-ink);
        font-size: 10px;
    }

    .trend-label {
        color: var(--analytics-muted);
        font-size: 10px;
        line-height: 1.3;
        text-align: center;
    }

    .inventory-health-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        margin-top: 14px;
    }

    .inventory-health-item {
        min-width: 0;
        padding: 12px;
        border: 1px solid var(--analytics-line);
        border-radius: 10px;
        background: var(--analytics-soft);
        color: inherit;
        text-decoration: none;
    }

    .inventory-health-item:hover {
        border-color: #b9cbe0;
        background: #fbfdff;
    }

    .inventory-health-item strong {
        display: block;
        color: var(--analytics-ink);
        font-size: 22px;
        line-height: 1.1;
    }

    .inventory-health-item span {
        display: block;
        margin-top: 5px;
        color: var(--analytics-muted);
        font-size: 10px;
        font-weight: 700;
    }

    @media (max-width: 1050px) {
        .analytics-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .inventory-health-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 760px) {
        .analytics-kpi-grid,
        .analytics-grid-two {
            grid-template-columns: 1fr;
        }

        .inventory-health-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 520px) {
        .inventory-health-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Business intelligence</p>
        <h1>Reports &amp; Analytics</h1>
        <p>
            Analytics provides the management overview.
            Open Reports for detailed records, evidence, printing, and CSV export.
        </p>
    </div>

    <div class="analytics-heading-actions">
        @if(auth()->user()->hasRole('SPMU') || auth()->user()->hasRole('ICTU'))
            <a class="button secondary ui-pressable" href="{{ route('reports.audit') }}">
                <x-icon name="reports" size="16" />
                Audit Trail
            </a>

            <a class="button secondary ui-pressable" href="{{ route('reports.notifications') }}">
                <x-icon name="notifications" size="16" />
                Delivery
            </a>
        @endif
    </div>
</section>

<div class="analytics-dashboard">

    <section class="content-area">
        <div class="analytics-module-row">
            @include('reports.partials.module-tabs')

            <span class="meta">
                @if($selectedAcademicPeriod)
                    {{ $selectedAcademicPeriod->academic_year }}
                    · {{ $selectedAcademicPeriod->term_name }}
                    · {{ $periodLabel }}
                @else
                    Custom reporting period: {{ $periodLabel }}
                @endif
            </span>
        </div>

        <form method="get" class="card report-filter-card top-gap">
            <input type="hidden" name="tab" value="analytics">

            <div
                class="report-filter-row"
                style="grid-template-columns: minmax(280px, 1.15fr) minmax(160px, .55fr) minmax(160px, .55fr) auto;"
            >
                @include(
                    'reports.partials.academic-period-filter',
                    ['periodSelectId' => 'analytics-academic-period']
                )

                <button class="button primary ui-pressable" type="submit">
                    Apply
                </button>
            </div>
        </form>
    </section>

    <section class="analytics-kpi-grid">
        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-info ui-pressable"
            href="{{ $reportUrl('requests') }}"
        >
            <span class="kpi-icon"><x-icon name="reports" size="18" /></span>
            <strong class="kpi-value">{{ number_format($totalRequests) }}</strong>
            <span class="kpi-label">Total requests</span>
            <small class="kpi-support">Requests created during the selected period.</small>
            <span class="kpi-drilldown">View request report →</span>
        </a>

        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-success ui-pressable"
            href="{{ $reportUrl('review-turnaround') }}"
        >
            <span class="kpi-icon"><x-icon name="approval" size="18" /></span>
            <strong class="kpi-value">
                {{ $decidedRequestCount > 0 ? number_format($approvalRate, 1).'%' : 'N/A' }}
            </strong>
            <span class="kpi-label">SPMU approval rate</span>
            <small class="kpi-support">
                {{ $approvedDecisionCount }} approved of {{ $decidedRequestCount }} completed SPMU decisions.
            </small>
            <span class="kpi-drilldown">View SPMU decisions →</span>
        </a>

        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-info ui-pressable"
            href="{{ $reportUrl('review-turnaround') }}"
        >
            <span class="kpi-icon"><x-icon name="clock" size="18" /></span>
            <strong class="kpi-value">{{ $approvalLabel }}</strong>
            <span class="kpi-label">Average review turnaround</span>
            <small class="kpi-support">
                Average time from SPMU receipt to completed decision.
            </small>
            <span class="kpi-drilldown">See how this was calculated →</span>
        </a>

        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-success ui-pressable"
            href="{{ $reportUrl('custody') }}"
        >
            <span class="kpi-icon"><x-icon name="custody" size="18" /></span>
            <strong class="kpi-value">{{ number_format($releasedBorrowings) }}</strong>
            <span class="kpi-label">Released borrowings</span>
            <small class="kpi-support">Custody transactions physically released during the period.</small>
            <span class="kpi-drilldown">View custody report →</span>
        </a>

        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-info ui-pressable"
            href="{{ $reportUrl('compliance') }}"
        >
            <span class="kpi-icon"><x-icon name="success" size="18" /></span>
            <strong class="kpi-value">{{ $returnComplianceLabel }}</strong>
            <span class="kpi-label">Return compliance</span>
            <small class="kpi-support">
                {{ $returnCompliance['closed'] }} closed of {{ $returnCompliance['released'] }} released transactions.
            </small>
            <span class="kpi-drilldown">View compliance report →</span>
        </a>

        <a
            class="card dashboard-kpi-card analytics-kpi-link kpi-accent-danger ui-pressable"
            href="{{ $reportUrl('accountability') }}"
        >
            <span class="kpi-icon"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ number_format($openAccountabilityCount) }}</strong>
            <span class="kpi-label">Open accountability cases</span>
            <small class="kpi-support">Unresolved overdue and property-incident custody cases.</small>
            <span class="kpi-drilldown">View accountability report →</span>
        </a>
    </section>

    <section class="content-area">
        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Operational trend</p>
                    <h2>Borrowing activity trend</h2>
                    <p class="section-copy">
                        Requests created versus borrowings physically released.
                        The chart automatically groups data by {{ $trendGranularity }} for the selected date range.
                        Hover or focus a period to see exact counts.
                    </p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('borrowing') }}">
                    View detailed report →
                </a>
            </div>

            <div class="trend-legend">
                <span><i></i>Requests</span>
                <span class="released"><i></i>Released</span>
            </div>

            @if($borrowingTrend->isNotEmpty())
                <div
                    class="trend-chart"
                    style="--trend-columns: {{ max($borrowingTrend->count(), 1) }}"
                    aria-label="Borrowing activity trend"
                >
                    @foreach($borrowingTrend as $point)
                        <div
                            class="trend-column"
                            tabindex="0"
                            aria-label="{{ $point['label'] }}: {{ $point['requests'] }} requests created, {{ $point['released'] }} borrowings released"
                        >
                            <div class="trend-tooltip" role="tooltip">
                                <strong>{{ $point['label'] }}</strong>

                                <span>
                                    Requests created
                                    <b>{{ $point['requests'] }}</b>
                                </span>

                                <span>
                                    Borrowings released
                                    <b>{{ $point['released'] }}</b>
                                </span>
                            </div>

                            <div class="trend-bars">
                                <span
                                    class="trend-bar"
                                    style="height: {{ max(4, ($point['requests'] / $trendMax) * 82) }}%;"
                                    title="{{ $point['requests'] }} requests created"
                                >
                                    <strong>{{ $point['requests'] }}</strong>
                                </span>

                                <span
                                    class="trend-bar is-released"
                                    style="height: {{ max(4, ($point['released'] / $trendMax) * 82) }}%;"
                                    title="{{ $point['released'] }} borrowings released"
                                >
                                    <strong>{{ $point['released'] }}</strong>
                                </span>
                            </div>

                            <span class="trend-label">{{ $point['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No borrowing activity for this period.</p>
            @endif
        </article>
    </section>

    <section class="analytics-grid-two">
        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Request performance</p>
                    <h2>Request outcomes</h2>
                    <p class="section-copy">Management-friendly grouping of request states.</p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('requests') }}">View report →</a>
            </div>

            @if($requestOutcomes->isNotEmpty())
                <div class="distribution-list compact-distribution">
                    @foreach($requestOutcomes as $label => $total)
                        <div class="distribution-row">
                            <div class="distribution-row-head">
                                <span>{{ $label }}</span>
                                <strong>{{ $total }}</strong>
                            </div>
                            <div class="distribution-bar-track">
                                <span
                                    class="distribution-bar-fill"
                                    style="width: {{ ($total / $requestOutcomeMax) * 100 }}%"
                                ></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No request activity for this period.</p>
            @endif
        </article>

        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Custody performance</p>
                    <h2>Custody status</h2>
                    <p class="section-copy">Custody states created during the selected period.</p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('custody') }}">View report →</a>
            </div>

            @if($custodyStatuses->isNotEmpty())
                <div class="distribution-list compact-distribution">
                    @foreach($custodyStatuses as $status => $total)
                        <div class="distribution-row">
                            <div class="distribution-row-head">
                                <span>{{ str($status)->replace('_', ' ')->title() }}</span>
                                <strong>{{ $total }}</strong>
                            </div>
                            <div class="distribution-bar-track">
                                <span
                                    class="distribution-bar-fill"
                                    style="width: {{ ($total / $custodyMax) * 100 }}%"
                                ></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No custody activity for this period.</p>
            @endif
        </article>
    </section>

    <section class="analytics-grid-two">
        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Asset utilization</p>
                    <h2>Top 5 most utilized items</h2>
                    <p class="section-copy">Physical release quantity during {{ $periodLabel }}.</p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('utilization') }}">Full ranking →</a>
            </div>

            @if($topItems->isNotEmpty() && $topItems->sum('used_quantity') > 0)
                <div class="distribution-list compact-distribution">
                    @foreach($topItems->take(5) as $item)
                        <div class="distribution-row">
                            <div class="distribution-row-head">
                                <a href="{{ route('inventory.show', $item->id) }}">
                                    {{ $item->unique_description }}
                                </a>
                                <strong>{{ $item->used_quantity + 0 }} released</strong>
                            </div>
                            <div class="distribution-bar-track">
                                <span
                                    class="distribution-bar-fill"
                                    style="width: {{ ($item->used_quantity / $utilizationMax) * 100 }}%"
                                ></span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No released-item utilization for this period.</p>
            @endif
        </article>

        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Low utilization</p>
                    <h2>5 least utilized items</h2>
                    <p class="section-copy">
                        Lowest physical release quantities for {{ $periodLabel }}.
                        This is only a five-item preview; Reports shows the full automatic ranking.
                    </p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('utilization') }}">Full ranking →</a>
            </div>

            @if($leastUtilizedItems->isNotEmpty())
                <div class="analytics-list">
                    @foreach($leastUtilizedItems as $item)
                        <a
                            class="analytics-list-row ui-pressable"
                            href="{{ route('inventory.show', $item->id) }}"
                        >
                            <strong>{{ $item->unique_description }}</strong>
                            <span class="analytics-list-value">{{ $item->used_quantity + 0 }} released</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No inventory items to compare.</p>
            @endif
        </article>
    </section>

    <section class="analytics-grid-two">
        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Borrower activity</p>
                    <h2>Most frequent borrowers</h2>
                    <p class="section-copy">Neutral ranking based on request count.</p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('borrowers') }}">View borrower report →</a>
            </div>

            @if($mostFrequentBorrowers->isNotEmpty())
                <div class="analytics-list">
                    @foreach($mostFrequentBorrowers->take(5) as $row)
                        <a
                            class="analytics-list-row ui-pressable"
                            href="{{ $reportUrl('borrowers') }}"
                        >
                            <strong>{{ $row->full_name }}</strong>
                            <span class="analytics-list-value">{{ $row->borrowing_count }} requests</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No borrower activity for this period.</p>
            @endif
        </article>

        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Return &amp; accountability</p>
                    <h2>Late returns and violations</h2>
                    <p class="section-copy">
                        Operational indicators only; sanctions remain case-by-case SPMU decisions.
                    </p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('accountability') }}">
                    View accountability report →
                </a>
            </div>

            <div class="analytics-list">
                @foreach($borrowersWithMostLateReturns->take(3) as $row)
                    <div class="analytics-list-row">
                        <div>
                            <strong>{{ $row->full_name }}</strong>
                            <small>Late returns</small>
                        </div>
                        <span class="analytics-list-value">{{ $row->late_return_count }}</span>
                    </div>
                @endforeach

                @foreach($borrowersWithMostViolations->take(3) as $row)
                    <div class="analytics-list-row">
                        <div>
                            <strong>{{ $row->full_name }}</strong>
                            <small>Confirmed violations</small>
                        </div>
                        <span class="analytics-list-value">{{ $row->violation_count }}</span>
                    </div>
                @endforeach

                @if($borrowersWithMostLateReturns->isEmpty() && $borrowersWithMostViolations->isEmpty())
                    <p class="empty-state-inline">No late-return or confirmed-violation activity.</p>
                @endif
            </div>
        </article>
    </section>

    <section class="analytics-grid-two">
        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Asset risk</p>
                    <h2>Recorded condition incidents</h2>
                    <p class="section-copy">Historical incident quantities during the selected period.</p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('accountability') }}">View report →</a>
            </div>

            @if($assetConditionTrends->isNotEmpty())
                <div class="analytics-list">
                    @foreach($assetConditionTrends->take(5) as $row)
                        <div class="analytics-list-row">
                            <div>
                                <strong>{{ $row->unique_description }}</strong>
                                <small>{{ str($row->observed_condition)->replace('_', ' ')->title() }}</small>
                            </div>
                            <span class="analytics-list-value">{{ $row->affected_quantity + 0 }} affected</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="empty-state-inline">No asset condition incidents recorded for this period.</p>
            @endif
        </article>

        <article class="card analytics-card">
            <div class="analytics-card-header">
                <div>
                    <p class="eyebrow">Inventory health</p>
                    <h2>Current operational inventory</h2>
                    <p class="section-copy">
                        Current snapshot; these are not historical balances for the selected period.
                    </p>
                </div>

                <a class="analytics-card-link" href="{{ $reportUrl('inventory') }}">
                    Detailed inventory report →
                </a>
            </div>

            <div class="inventory-health-grid">
                @foreach([
                    'physical_available' => 'Physical Available',
                    'allocated' => 'Allocated',
                    'on_custody' => 'On Custody',
                    'laundry' => 'Laundry',
                    'incident_unavailable' => 'Incident / Unavailable',
                ] as $key => $label)
                    <a class="inventory-health-item ui-pressable" href="{{ $reportUrl('inventory') }}">
                        <strong>{{ $inventoryHealth[$key] + 0 }}</strong>
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </article>
    </section>

    <section class="content-area">
        <details class="report-formula-panel">
            <summary>How these management KPIs are calculated</summary>

            <div class="report-formula-body">
                <p><strong>Total requests:</strong> requests created within the selected reporting period.</p>
                <p><strong>SPMU approval rate:</strong> approved SPMU decisions divided by completed SPMU approve, reject, or return-for-revision decisions.</p>
                <p><strong>Average review turnaround:</strong> average elapsed time from SPMU receipt to a completed SPMU decision. Approved, rejected, and returned-for-revision decisions are included.</p>
                <p><strong>Released borrowings:</strong> custody transactions physically released during the period.</p>
                <p><strong>Return compliance:</strong> closed custody transactions divided by transactions physically released during the period.</p>
                <p><strong>Utilization:</strong> total physically released quantity per inventory item during the selected period.</p>
            </div>
        </details>
    </section>

</div>

@endsection
