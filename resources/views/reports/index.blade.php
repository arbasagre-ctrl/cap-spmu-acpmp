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

    $reportUrl = fn (string $report, ?string $statusFocus = null) => route('reports.index', array_filter([
        'tab' => 'reports',
        'report' => $report,
        'academic_period' => $periodSelection,
        'status_focus' => $statusFocus,
    ], fn ($value) => $value !== null && $value !== ''));

    $analyticsStatusUrl = fn (string $statusFocus) => route('reports.index', [
        'tab' => 'analytics',
        'academic_period' => $periodSelection,
        'status_focus' => $statusFocus,
    ]).'#request-status-records';

    $statusFocusLabels = [
        'ALL' => 'All Requests',
        'DRAFT' => 'Draft',
        'OBLIGATION_OPEN' => 'Obligation Open',
        'COMPLETED' => 'Completed',
    ];

    $reportingPeriodLabel = match($periodSelection) {
        'week' => 'Weekly',
        'month' => 'Monthly',
        'semester' => 'Semester',
        'academic_year' => 'Academic Year',
        default => 'Reporting Period',
    };
@endphp

@php
    $percentLabel = fn ($value) => rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
    $trendCeiling = max(1, (int) ceil($trendMax * 1.25));
    $outcomeRows = collect([
        'Approved' => (int) ($requestOutcomes['Approved'] ?? 0),
        'Denied' => (int) ($requestOutcomes['Rejected'] ?? 0),
        'Cancelled' => (int) ($requestOutcomes['Cancelled / Expired'] ?? 0),
    ])->merge($requestOutcomes->except(['Approved', 'Rejected', 'Cancelled / Expired']));
    $custodyRows = collect([
        'ACTIVE' => (int) ($custodyStatuses['ACTIVE'] ?? 0),
        'PREPARING_RELEASE' => (int) ($custodyStatuses['PREPARING_RELEASE'] ?? 0),
        'CLOSED' => (int) ($custodyStatuses['CLOSED'] ?? 0),
    ])->merge($custodyStatuses->except(['ACTIVE', 'PREPARING_RELEASE', 'CLOSED']));
@endphp

@include('reports.partials.workspace-styles')
@include('reports.partials.analytics-styles')

<div class="reporting-workspace reporting-analytics">
    <section class="page-heading">
        <div>
            <p class="eyebrow">Business intelligence</p>
            <h1>Reports &amp; Analytics</h1>
            <p>Management overview of requests, custody, returns, accountability, and inventory.</p>
        </div>
    </section>

    <div class="analytics-dashboard">
        @include('reports.partials.module-tabs')

        <form method="get" class="card analytics-period-toolbar" aria-label="Analytics reporting period">
            <input type="hidden" name="tab" value="analytics">
            @include('reports.partials.period-selection', ['periodSelectId' => 'analytics-academic-period'])
            <noscript><button class="button primary" type="submit">Apply</button></noscript>
            @include('reports.partials.heading-actions')
        </form>

        @include('reports.partials.analytics-summary')

        <section class="analytics-chart-grid" aria-label="Borrowing and custody activity">
            @include('reports.partials.analytics-trend')

            @foreach([
                ['title' => 'Request outcomes', 'rows' => $outcomeRows, 'denominator' => max($outcomeRows->sum(), 1), 'report' => 'requests', 'link' => 'View request report', 'tone' => 'blue'],
                ['title' => 'Custody status', 'rows' => $custodyRows, 'denominator' => max($custodyRows->sum(), 1), 'report' => 'custody', 'link' => 'View custody report', 'tone' => 'green'],
            ] as $distribution)
                <article class="card analytics-distribution">
                    <h2>{{ $distribution['title'] }}</h2>
                    <div class="analytics-distribution-rows">
                        @foreach($distribution['rows'] as $label => $total)
                            @php
                                $displayLabel = $distribution['report'] === 'custody'
                                    ? (['ACTIVE' => 'Active', 'PREPARING_RELEASE' => 'Preparing Release', 'CLOSED' => 'Returned'][$label] ?? str($label)->replace('_', ' ')->title())
                                    : $label;
                            @endphp
                            <div class="analytics-distribution-row">
                                <div><span>{{ $displayLabel }}</span><strong>{{ $total }}</strong></div>
                                <div class="analytics-bar-track" aria-hidden="true"><span class="tone-{{ $distribution['tone'] }}" style="width: {{ ($total / $distribution['denominator']) * 100 }}%"></span></div>
                            </div>
                        @endforeach
                    </div>
                    <a class="analytics-link" href="{{ $reportUrl($distribution['report']) }}">{{ $distribution['link'] }} <span aria-hidden="true">→</span></a>
                </article>
            @endforeach
        </section>

        <section class="analytics-bottom-grid" aria-label="Asset utilization and operational insights">
            <article class="card analytics-utilization">
                <div class="analytics-panel-heading">
                    <h2>Asset utilization</h2>
                    <a class="analytics-link" href="{{ $reportUrl('utilization') }}">Full ranking <span aria-hidden="true">→</span></a>
                </div>
                <div class="analytics-rankings">
                    @foreach(['Most utilized items' => $topItems->take(5), 'Least utilized items' => $leastUtilizedItems->take(5)] as $label => $ranking)
                        <div class="analytics-ranking">
                            <h3>{{ $label }}</h3>
                            <ol>
                                @forelse($ranking as $item)
                                    <li><a href="{{ route('inventory.show', $item->id) }}">{{ $item->unique_description }}</a><strong>{{ $item->used_quantity + 0 }} released</strong></li>
                                @empty
                                    <li class="analytics-ranking-empty">No inventory items to compare.</li>
                                @endforelse
                            </ol>
                        </div>
                    @endforeach
                </div>
            </article>
            @include('reports.partials.analytics-insights')
        </section>

        <details class="card analytics-formulas">
            <summary>How these management KPIs are calculated <x-icon name="chevron-down" size="17" /></summary>
            <div class="analytics-formula-body">
                <p><strong>Total requests:</strong> requests created within the selected reporting period.</p>
                <p><strong>Approval rate:</strong> approved SPMU decisions divided by completed SPMU approve, reject, or return-for-revision decisions.</p>
                <p><strong>Average review turnaround:</strong> average elapsed time from SPMU receipt to a completed decision.</p>
                <p><strong>Released / On Custody:</strong> custody transactions physically released during the period, including those subsequently returned.</p>
                <p><strong>Return compliance:</strong> closed custody transactions divided by transactions physically released during the period.</p>
                <p><strong>Request outcomes and custody status:</strong> current states of records created within the selected period. Cancelled includes expired requests.</p>
                <p><strong>Utilization:</strong> total physically released quantity per inventory item during the selected period.</p>
                <p><strong>Open accountability and inventory health:</strong> current operational snapshots, not historical balances for the selected period.</p>
                <nav class="analytics-status-links" aria-label="Request status drill-down">
                    @foreach($statusFocusLabels as $focus => $label)
                        <a class="button secondary small" href="{{ $analyticsStatusUrl($focus) }}">{{ $label }}: {{ $requestStatusSnapshot[['ALL' => 'total', 'DRAFT' => 'draft', 'OBLIGATION_OPEN' => 'obligation_open', 'COMPLETED' => 'completed'][$focus]] }}</a>
                    @endforeach
                </nav>
            </div>
        </details>
        @include('reports.partials.analytics-status-records')
    </div>
</div>
<script>
document.getElementById('analytics-academic-period')?.addEventListener('change', function () {
    this.form.requestSubmit();
});
</script>
@endsection
