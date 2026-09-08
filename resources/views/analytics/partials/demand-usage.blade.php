@php
    /*
    | Demand & Usage: what was asked for, and what actually went out.
    |
    | The two are deliberately never merged. A request that was filed but
    | never released is real demand and belongs in Most Requested; only a
    | physical release belongs in Actually Released. Every ranking states
    | which of the two it counts.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;
@endphp

<section class="analytics-section{{ $trend['points'] === [] ? ' is-empty' : '' }}">
    <h2>Usage Trend</h2>
    <div class="analytics-section-body">
        @if($trend['points'] === [])
            <p class="analytics-empty">No borrowing requests were recorded for this period.</p>
        @else
            <p class="analytics-metric-note">
                Filed borrowing demand, grouped by {{ $trend['granularity'] }}. This chart counts requests,
                not released quantity, so it does not describe asset usage.
            </p>

            @include('analytics.partials.trend-chart', ['trend' => $trend])

            <p class="analytics-reading">{{ $trend['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $requested['items'] === [] ? ' is-empty' : '' }}">
    <h2>Most Requested Items</h2>
    <div class="analytics-section-body">
        @if($requested['items'] === [])
            <p class="analytics-empty">No equipment was requested during this period.</p>
        @else
            <p class="analytics-metric-note">
                Expressed demand: counted as {{ strtolower($requested['metric']) }}, with the requested quantity alongside.
                A requested quantity is what was asked for, not what went out.
            </p>

            <div class="analytics-bars">
                @foreach($requested['items'] as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
                        aria-label="View details for {{ $row['name'] }}"
                    >
                        <span class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">
                                {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
                                · {{ $row['quantity'] }} {{ $row['unit'] }}
                            </span>
                        </span>
                        <span class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $requested['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $released['items'] === [] ? ' is-empty' : '' }}">
    <h2>Actually Released &mdash; Top Fast-Moving</h2>
    <div class="analytics-section-body">
        @if($released['items'] === [])
            <p class="analytics-empty">No equipment was physically released during this period.</p>
        @else
            <p class="analytics-metric-note">
                Actual asset usage: counted as {{ strtolower($released['metric']) }}, taken from physical release
                records rather than request counts. This is the only measure on this page that reflects equipment
                that actually left the store.
            </p>

            <div class="analytics-bars">
                @foreach($released['items'] as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}"
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

            <p class="analytics-reading">{{ $released['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section">
    <h2>Slow-Moving Items</h2>
    <div class="analytics-section-body">
        @if($slowMoving['items'] === [])
            <p class="analytics-empty">No borrowable equipment is recorded yet.</p>
        @else
            <p class="analytics-metric-note">
                Starts from the borrowable catalogue, so equipment with no releases at all appears here.
            </p>

            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="numeric">Released</th>
                            <th class="numeric">Releases</th>
                            <th>Last activity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($slowMoving['items'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('equipment', 'demand', $periodSelection, $division, $unit, ['item' => $row['item_id']]) }}">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['released'] }}</td>
                                <td class="numeric">{{ $row['transactions'] }}</td>
                                <td>{{ $row['last_activity'] ? \Carbon\Carbon::parse($row['last_activity'])->format('d M Y') : 'No release recorded' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $slowMoving['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $groups['total'] === 0 ? ' is-empty' : '' }}">
    <h2>Demand by Division</h2>
    <div class="analytics-section-body">
        @if($groups['total'] === 0)
            <p class="analytics-empty">No borrowing requests were recorded for this period.</p>
        @else
            <div class="analytics-bars">
                @foreach($groups['groups'] as $group)
                    @if($group['code'])
                        <a
                            class="analytics-bar-row analytics-bar-link is-{{ strtolower(explode('_', $group['code'])[0]) }}"
                            href="{{ AnalyticsDetailLink::to('division', 'demand', $periodSelection, $group['code'], null, ['for' => $group['code']]) }}"
                            aria-label="View details for {{ $group['label'] }}"
                        >
                            <span class="analytics-bar-head">
                                <span class="analytics-bar-name">{{ $group['label'] }}</span>
                                <span class="analytics-bar-value">{{ $group['count'] }} · {{ $group['percentage'] }}%</span>
                            </span>
                            <span class="analytics-bar-track">
                                <span class="analytics-bar-fill" style="width: {{ $group['percentage'] }}%"></span>
                            </span>
                        </a>
                    @else
                        <div class="analytics-bar-row">
                            <div class="analytics-bar-head">
                                <span class="analytics-bar-name">{{ $group['label'] }}</span>
                                <span class="analytics-bar-value">{{ $group['count'] }} · {{ $group['percentage'] }}%</span>
                            </div>
                            <div class="analytics-bar-track">
                                <span class="analytics-bar-fill" style="width: {{ $group['percentage'] }}%"></span>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            <p class="analytics-reading">{{ $groups['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $units['columns']->isEmpty() ? ' is-empty' : '' }}">
    <h2>Demand by Unit</h2>
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
                                    href="{{ AnalyticsDetailLink::to('unit', 'demand', $periodSelection, $column['code'], $row['name'], ['for' => $row['name']]) }}"
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

<section class="analytics-section{{ $peak['available'] ? '' : ' is-empty' }}">
    <h2>Peak Borrowing Periods</h2>
    <div class="analytics-section-body">
        @if(! $peak['available'])
            <p class="analytics-empty">
                Not enough activity to determine a reliable peak.
                A weekday or hour pattern is reported once at least {{ $peak['requirement'] }} requests have been filed in the period;
                there {{ $peak['total'] === 1 ? 'is' : 'are' }} currently {{ $peak['total'] }}.
            </p>
        @else
            <p class="analytics-metric-note">Based on when borrowing requests were filed.</p>

            <div class="analytics-bars">
                @foreach($peak['days'] as $day)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $day['label'] }}</span>
                            <span class="analytics-bar-value">{{ $day['count'] }}</span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $day['share'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $peak['summary'] }}</p>
        @endif
    </div>
</section>
