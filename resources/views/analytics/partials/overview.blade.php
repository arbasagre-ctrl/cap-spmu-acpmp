{{--
    Overview: what is happening right now.

    Four figures, then the plain-language readings underneath them. Every
    number is computed in AnalyticsService; nothing is calculated here.

    Each figure links to the existing record page that lists what it counts,
    so the card is a shortcut into the records rather than a second report.
--}}
<div class="analytics-kpis">
    <a class="analytics-kpi analytics-kpi-link tone-requests" href="{{ route('requests.index') }}">
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="requests" size="17" /></span>
            <span class="analytics-kpi-label">Requests</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['total'] }}</strong>
        <small>{{ $comparison['summary'] }}</small>
    </a>

    <a class="analytics-kpi analytics-kpi-link tone-custody" href="{{ route('custody.index') }}">
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="custody" size="17" /></span>
            <span class="analytics-kpi-label">Currently Out</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['on_custody'] }}</strong>
        <small>Equipment currently in borrower use</small>
    </a>

    <a class="analytics-kpi analytics-kpi-link tone-followup" href="{{ route('accountability.index') }}">
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="warning" size="17" /></span>
            <span class="analytics-kpi-label">Need Follow-up</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $overview['needs_follow_up'] }}</strong>
        <small>Released borrowings past their return date</small>
    </a>

    <a class="analytics-kpi analytics-kpi-link tone-stock" href="{{ route('inventory.index') }}">
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-icon" aria-hidden="true"><x-icon name="inventory" size="17" /></span>
            <span class="analytics-kpi-label">Low Availability</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        <strong>{{ $lowAvailability['count'] }}</strong>
        <small>Equipment types at or below {{ (int) ($lowAvailability['threshold'] * 100) }}% usable stock</small>
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

<section class="analytics-section{{ $groups['total'] === 0 ? ' is-empty' : '' }}">
    <h2>Borrower Activity</h2>
    <div class="analytics-section-body">
        @if($groups['total'] === 0)
            <p class="analytics-empty">No borrowing requests were recorded for this period.</p>
        @else
            <div class="analytics-bars">
                @foreach($groups['groups'] as $group)
                    <div class="analytics-bar-row {{ $group['code'] ? 'is-'.strtolower(explode('_', $group['code'])[0]) : '' }}">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $group['label'] }}</span>
                            <span class="analytics-bar-value">
                                {{ $group['count'] }} {{ $group['count'] === 1 ? 'request' : 'requests' }}
                                · {{ $group['percentage'] }}%
                            </span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $group['percentage'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $groups['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $units['columns']->isEmpty() ? ' is-empty' : '' }}">
    <h2>Most Active Units</h2>
    <div class="analytics-section-body">
        @if($units['columns']->isEmpty())
            <p class="analytics-empty">No unit recorded a borrowing request during this period.</p>
        @else
            <div class="analytics-split">
                @foreach($units['columns'] as $column)
                    <section>
                        <h3>{{ $column['label'] }}</h3>
                        <div class="analytics-bars">
                            @foreach(array_slice($column['units'], 0, 3) as $row)
                                <div class="analytics-bar-row">
                                    <div class="analytics-bar-head">
                                        <span class="analytics-bar-name">{{ $row['name'] }}</span>
                                        <span class="analytics-bar-value">{{ $row['count'] }}</span>
                                    </div>
                                    <div class="analytics-bar-track">
                                        <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            @foreach($units['summary'] as $sentence)
                <p class="analytics-reading">{{ $sentence }}</p>
            @endforeach
        @endif
    </div>
</section>

<section class="analytics-section{{ $lowAvailability['watch'] === null ? ' is-empty' : '' }}">
    <h2>Equipment to Watch</h2>
    <div class="analytics-section-body">
        @if($lowAvailability['watch'] === null)
            <p class="analytics-empty">No equipment currently requires availability attention.</p>
        @else
            @php($watch = $lowAvailability['watch'])
            <div class="analytics-watch">
                <div>
                    <strong>{{ $watch['name'] }}</strong>
                    <span>{{ $watch['available'] + 0 }} of {{ $watch['stock'] + 0 }} available</span>
                </div>
                <span class="analytics-status is-{{ strtolower(str_replace(' ', '-', $watch['status'])) }}">
                    {{ $watch['status'] }}
                </span>
            </div>

            <p class="analytics-reading">{{ $lowAvailability['summary'] }}</p>

            @if($watch['laundry_required'] && $watch['laundry'] > 0)
                <p class="analytics-reading">
                    {{ $watch['laundry'] + 0 }} returned units are still with Laundry Operations and stay unavailable until it completes.
                </p>
            @endif
        @endif
    </div>
</section>
