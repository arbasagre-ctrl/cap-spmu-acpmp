@php
    /*
    | Borrowing & Returns: who borrows, and whether it comes back.
    |
    | OVERDUE and LATE RETURN are separate measures throughout and are never
    | added together. Overdue is present-tense: the item has not come back.
    | A late return is finished: the item is back, only after its due date.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;
@endphp

<div class="analytics-kpis">
    <a
        class="analytics-kpi analytics-kpi-link"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'on-time']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Returned On Time</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $returns['on_time'] }}</strong>
        <small>Closed on or before the expected return date</small>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link{{ $returns['late'] > 0 ? ' is-attention' : '' }}"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'late']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Returned Late</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $returns['late'] }}</strong>
        <small>Returned, but after the expected return date</small>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link{{ $returns['overdue'] > 0 ? ' is-attention' : '' }}"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'overdue']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Currently Overdue</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $returns['overdue'] }}</strong>
        <small>Still out past the return date, as of today</small>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link{{ $returns['open_cases'] > 0 ? ' is-attention' : '' }}"
        href="{{ AnalyticsDetailLink::to('returns', 'returns', $periodSelection, $division, $unit, ['state' => 'accountability']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Open Accountability</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $returns['open_cases'] }}</strong>
        <small>Unresolved incident or billing case</small>
    </a>
</div>

<section class="analytics-section{{ $returns['has_data'] ? '' : ' is-empty' }}">
    <h2>Return Performance</h2>
    <div class="analytics-section-body">
        @if(! $returns['has_data'])
            <p class="analytics-empty">No completed returns are available for this period yet.</p>
        @else
            <div class="analytics-stat-grid">
                <div class="analytics-stat is-static">
                    <span>On-Time Return Rate</span>
                    <strong>{{ $returns['on_time_rate'] === null ? 'N/A' : $returns['on_time_rate'].'%' }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Late Return Rate</span>
                    <strong>{{ $returns['late_rate'] === null ? 'N/A' : $returns['late_rate'].'%' }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Completed Returns</span>
                    <strong>{{ $returns['completed'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Average Borrowing Duration</span>
                    <strong>{{ $returns['average_duration']['available'] ? $returns['average_duration']['label'] : 'N/A' }}</strong>
                </div>
            </div>

            <p class="analytics-metric-note">
                Rates are shares of completed returns. They exclude borrowings that are still out, which are counted separately as Currently Overdue.
            </p>

            <p class="analytics-reading">{{ $returns['summary'] }}</p>

            @if($returns['average_duration']['available'])
                <p class="analytics-reading">{{ $returns['average_duration']['summary'] }}</p>
            @endif
        @endif
    </div>
</section>

<section class="analytics-section{{ $borrowers['borrowers'] === [] ? ' is-empty' : '' }}">
    <h2>Most Frequent Borrowers</h2>
    <div class="analytics-section-body">
        @if($borrowers['borrowers'] === [])
            <p class="analytics-empty">No borrowing requests were filed during this period.</p>
        @else
            <p class="analytics-metric-note">Counted as borrowing requests filed in this period.</p>

            <div class="analytics-bars">
                @foreach($borrowers['borrowers'] as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('borrower', 'returns', $periodSelection, $division, $unit, ['for' => $row['name']]) }}"
                        aria-label="View details for {{ $row['name'] }}"
                    >
                        <span class="analytics-bar-head">
                            <span class="analytics-bar-name">
                                {{ $row['name'] }}
                                @if($row['unit'])
                                    <small>{{ $row['unit'] }}</small>
                                @endif
                            </span>
                            <span class="analytics-bar-value">
                                {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
                            </span>
                        </span>
                        <span class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $borrowers['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $units['columns']->isEmpty() ? ' is-empty' : '' }}">
    <h2>Top Divisions &amp; Units</h2>
    <div class="analytics-section-body">
        @if($units['columns']->isEmpty())
            <p class="analytics-empty">No borrowing requests were recorded for this period.</p>
        @else
            <div class="analytics-columns">
                @foreach($units['columns'] as $column)
                    <div class="analytics-column">
                        <h3>{{ $column['label'] }}</h3>
                        <div class="analytics-bars">
                            @foreach($column['units'] as $row)
                                <a
                                    class="analytics-bar-row analytics-bar-link"
                                    href="{{ AnalyticsDetailLink::to('unit', 'returns', $periodSelection, $column['code'], $row['name'], ['for' => $row['name']]) }}"
                                    aria-label="View details for {{ $row['name'] }}"
                                >
                                    <span class="analytics-bar-head">
                                        <span class="analytics-bar-name">{{ $row['name'] }}</span>
                                        <span class="analytics-bar-value">{{ $row['count'] }}</span>
                                    </span>
                                    <span class="analytics-bar-track">
                                        <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>

<section class="analytics-section{{ $lateBorrowers['borrowers'] === [] ? ' is-empty' : '' }}">
    <h2>Borrowers with Most Late Returns</h2>
    <div class="analytics-section-body">
        @if($lateBorrowers['borrowers'] === [])
            <p class="analytics-empty">{{ $lateBorrowers['summary'] }}</p>
        @else
            <p class="analytics-metric-note">
                Completed returns only: the equipment came back, after its due date. Borrowings still out are counted as Currently Overdue instead.
            </p>

            <div class="analytics-bars">
                @foreach($lateBorrowers['borrowers'] as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('borrower', 'returns', $periodSelection, $division, $unit, ['for' => $row['name']]) }}"
                        aria-label="View details for {{ $row['name'] }}"
                    >
                        <span class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">
                                {{ $row['late_returns'] }} late {{ $row['late_returns'] === 1 ? 'return' : 'returns' }}
                            </span>
                        </span>
                        <span class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $lateBorrowers['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $incidents['total'] === 0 ? ' is-empty' : '' }}">
    <h2>Accountability &amp; Incidents</h2>
    <div class="analytics-section-body">
        @if($incidents['total'] === 0)
            <p class="analytics-empty">{{ $incidents['summary'] }}</p>
        @else
            <div class="analytics-stat-grid">
                <div class="analytics-stat is-static">
                    <span>Incidents Recorded</span>
                    <strong>{{ $incidents['total'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Still Open</span>
                    <strong>{{ $incidents['open'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Units Affected</span>
                    <strong>{{ $incidents['affected_quantity'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Incident Rate</span>
                    <strong>{{ $incidents['rate'] === null ? 'N/A' : $incidents['rate'].'%' }}</strong>
                </div>
            </div>

            <p class="analytics-metric-note">
                Incident rate is affected units as a share of the quantity physically released in this period.
            </p>

            @if($incidents['types'] !== [])
                <div class="analytics-table-scroll">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th class="numeric">Cases</th>
                                <th class="numeric">Units Affected</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($incidents['types'] as $row)
                                <tr>
                                    <td>{{ $row['type'] }}</td>
                                    <td class="numeric">{{ $row['count'] }}</td>
                                    <td class="numeric">{{ $row['quantity'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="analytics-reading">{{ $incidents['summary'] }}</p>
        @endif
    </div>
</section>
