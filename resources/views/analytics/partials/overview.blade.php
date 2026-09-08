@php
    /*
    | Overview: what is happening right now.
    |
    | Four figures, then the readings underneath them. Every number is
    | computed in AnalyticsService; nothing is calculated here.
    |
    | A card's primary click opens an Analytics detail for that figure, not
    | the record module: clicking a number is a question about the number.
    | Reports is offered inside the detail as a secondary step.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;
@endphp

<div class="analytics-kpis">
    <a
        class="analytics-kpi analytics-kpi-link tone-requests"
        href="{{ AnalyticsDetailLink::to('requests', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="requests" size="17" /></span>
            <span class="analytics-kpi-label">Requests</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['total'] }}</strong>
        <small>{{ $comparison['summary'] }}</small>
        <span
            class="analytics-kpi-basis"
            title="Filed borrowing demand and review workload for this period, including requests that were rejected. This is not a measure of asset usage."
        >Filed borrowing demand, including rejected requests</span>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link tone-custody"
        href="{{ AnalyticsDetailLink::to('currently-out', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="custody" size="17" /></span>
            <span class="analytics-kpi-label">Currently Out</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['on_custody'] }}</strong>
        <small>Equipment currently in borrower use</small>
        <span
            class="analytics-kpi-basis"
            title="Current physical custody: equipment that was released and has not yet been returned, as of today."
        >Current physical custody, as of today</span>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link tone-followup"
        href="{{ AnalyticsDetailLink::to('follow-up', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="warning" size="17" /></span>
            <span class="analytics-kpi-label">Need Follow-up</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['needs_follow_up'] }}</strong>
        <small>Released and still out past the return date</small>
        <span class="analytics-kpi-basis">Current overdue custody, as of today</span>
    </a>

    <a
        class="analytics-kpi analytics-kpi-link tone-stock"
        href="{{ AnalyticsDetailLink::to('low-availability', 'overview', $periodSelection, $division, $unit) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="inventory" size="17" /></span>
            <span class="analytics-kpi-label">Low Availability</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $lowAvailability['count'] }}</strong>
        <small>At or below {{ (int) ($lowAvailability['threshold'] * 100) }}% usable stock</small>
        <span class="analytics-kpi-basis">Current inventory status, as of today</span>
    </a>
</div>

<section class="analytics-section">
    <h2>What You Need to Know</h2>
    <div class="analytics-section-body">
        @if(empty($insights))
            <p class="analytics-empty">Nothing has been recorded for this period yet, so there is nothing to report.</p>
        @else
            <ul class="analytics-insights">
                @foreach(array_slice($insights, 0, 4) as $insight)
                    <li class="analytics-insight">
                        <span class="analytics-insight-mark" aria-hidden="true"></span>
                        <span>{{ $insight }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

<section class="analytics-section{{ $trend['points'] === [] ? ' is-empty' : '' }}">
    <h2>Borrowing Activity Trend</h2>
    <div class="analytics-section-body">
        @if($trend['points'] === [])
            <p class="analytics-empty">No borrowing requests were recorded for this period.</p>
        @else
            <p class="analytics-metric-note">
                Requests filed, grouped by {{ $trend['granularity'] }}. Released quantity is a separate measure and is reported under Demand and Usage.
            </p>

            @include('analytics.partials.trend-chart', ['trend' => $trend])

            <p class="analytics-reading">{{ $trend['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $equipment['items'] === [] ? ' is-empty' : '' }}">
    <h2>Top Fast-Moving Items</h2>
    <div class="analytics-section-body">
        @if($equipment['items'] === [])
            <p class="analytics-empty">No equipment was physically released during this period.</p>
        @else
            <p class="analytics-metric-note">
                Actual asset usage, ranked by quantity physically released. Request counts are a separate measure.
            </p>

            <div class="analytics-bars">
                @foreach(array_slice($equipment['items'], 0, 5) as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('equipment', 'overview', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
                        aria-label="View details for {{ $row['name'] }}"
                    >
                        <span class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">{{ $row['released'] }} {{ $row['unit'] }}</span>
                        </span>
                        <span class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $equipment['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $units['columns']->isEmpty() ? ' is-empty' : '' }}">
    <h2>Top Borrowing Units</h2>
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
                                    href="{{ AnalyticsDetailLink::to('unit', 'overview', $periodSelection, $column['code'], $row['name'], ['for' => $row['name']]) }}"
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
