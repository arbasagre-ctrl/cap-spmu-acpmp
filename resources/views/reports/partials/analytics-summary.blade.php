<section class="analytics-kpi-grid" aria-label="Management performance indicators">
    @foreach([
        ['title' => 'Total requests', 'value' => number_format($totalRequests), 'copy' => 'All requests created', 'icon' => 'requests', 'tone' => 'blue', 'report' => 'requests', 'link' => 'View request report'],
        ['title' => 'Approval rate', 'value' => $decidedRequestCount > 0 ? $percentLabel($approvalRate) : 'N/A', 'copy' => $approvedDecisionCount.' approved of '.$decidedRequestCount.' decisions', 'icon' => 'approval', 'tone' => 'green', 'report' => 'review-turnaround', 'link' => 'View SPMU decisions'],
        ['title' => 'Avg. review turnaround', 'value' => $approvalLabel, 'copy' => 'Average time to complete decision', 'icon' => 'clock', 'tone' => 'purple', 'report' => 'review-turnaround', 'link' => 'View calculation details'],
        ['title' => 'Return compliance', 'value' => $returnCompliance['released'] > 0 ? $percentLabel($returnCompliance['percentage']) : 'N/A', 'copy' => $returnCompliance['closed'].' closed of '.$returnCompliance['released'].' released', 'icon' => 'shield-lock', 'tone' => 'cyan', 'report' => 'compliance', 'link' => 'View compliance report'],
    ] as $metric)
        <article class="card analytics-kpi">
            <span class="reporting-icon tone-{{ $metric['tone'] }}"><x-icon :name="$metric['icon']" size="22" /></span>
            <div>
                <h2>{{ $metric['title'] }}</h2>
                <strong class="analytics-kpi-value">{{ $metric['value'] }}</strong>
                <p>{{ $metric['copy'] }}</p>
                <a class="analytics-link" href="{{ $reportUrl($metric['report']) }}">{{ $metric['link'] }} <span aria-hidden="true">→</span></a>
            </div>
        </article>
    @endforeach
</section>
<section class="analytics-secondary-grid" aria-label="Release and accountability totals">
    <a class="card analytics-secondary-stat" href="{{ $reportUrl('custody') }}" title="Custody transactions physically released during the reporting period">
        <span class="reporting-icon tone-orange"><x-icon name="custody" size="19" /></span>
        <span>Released / On Custody</span><strong>{{ number_format($releasedBorrowings) }}</strong>
    </a>
    <a class="card analytics-secondary-stat" href="{{ $reportUrl('accountability') }}" title="Current unresolved overdue and property-incident custody cases">
        <span class="reporting-icon tone-red"><x-icon name="accountability" size="19" /></span>
        <span>Open Accountability Cases</span><strong>{{ number_format($openAccountabilityCount) }}</strong>
    </a>
</section>
