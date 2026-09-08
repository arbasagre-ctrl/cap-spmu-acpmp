@php
    /*
    | Inventory Health: the present physical position of the catalogue.
    |
    | Everything on this tab is current-state. It deliberately does not follow
    | the reporting period, because stock is where it is today regardless of
    | which month is being reviewed; the one exception is stock coverage,
    | which needs a usage window and says so.
    |
    | Every figure comes from InventoryService, the same authoritative
    | availability rule the Inventory module and Reports use.
    */
    use App\Support\AnalyticsDetailLink;
    use App\Support\AnalyticsDrilldown;

    $totals = $inventory['totals'];
@endphp

<section class="analytics-section">
    <h2>Inventory Status</h2>
    <div class="analytics-section-body">
        <p class="analytics-metric-note">Current position across {{ $inventory['item_count'] }} active items, as of today.</p>

        <div class="analytics-stat-grid">
            <div class="analytics-stat is-static">
                <span>Available</span>
                <strong>{{ $totals['available'] }}</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>Reserved / Allocated</span>
                <strong>{{ $totals['allocated'] }}</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>On Custody</span>
                <strong>{{ $totals['on_custody'] }}</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>In Laundry</span>
                <strong>{{ $totals['laundry'] }}</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>Damaged / Maintenance</span>
                <strong>{{ $totals['maintenance'] }}</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>Incident / Unavailable</span>
                <strong>{{ $totals['problem'] }}</strong>
            </div>
        </div>

        <p class="analytics-reading">{{ $inventory['summary'] }}</p>

        <p class="analytics-section-action">
            <a href="{{ AnalyticsDrilldown::inventory($periodSelection) }}">
                View source records in Reports
                <x-icon name="external-link" size="14" />
            </a>
        </p>
    </div>
</section>

<section class="analytics-section{{ $lowAvailability['items'] === [] ? ' is-empty' : '' }}">
    <h2>Low Availability</h2>
    <div class="analytics-section-body">
        @if($lowAvailability['items'] === [])
            <p class="analytics-empty">{{ $lowAvailability['summary'] }}</p>
        @else
            <p class="analytics-metric-note">
                Borrowable equipment whose usable stock is at or below
                {{ (int) ($lowAvailability['threshold'] * 100) }}% of its serviceable total, as of today.
                Units currently out, reserved, in laundry or held by an incident are already excluded by the Inventory module.
            </p>

            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="numeric">Serviceable Total</th>
                            <th class="numeric">Currently Available</th>
                            <th class="numeric">Availability</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lowAvailability['items'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('equipment', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['stock'] }}</td>
                                <td class="numeric">{{ $row['available'] }}</td>
                                <td class="numeric">{{ $row['share'] }}%</td>
                                <td>
                                    <span class="analytics-tag {{ $row['status'] === 'Unavailable' ? 'is-critical' : 'is-attention' }}">
                                        {{ $row['status'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $lowAvailability['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $released['items'] === [] ? ' is-empty' : '' }}">
    <h2>Utilization</h2>
    <div class="analytics-section-body">
        @if($released['items'] === [])
            <p class="analytics-empty">No equipment was physically released during this period.</p>
        @else
            <p class="analytics-metric-note">
                Most utilized, measured as quantity physically released in the selected period.
                This is actual asset usage. Requested quantity is a different measure and is reported under Demand and Usage.
            </p>

            <div class="analytics-bars">
                @foreach($released['items'] as $row)
                    <a
                        class="analytics-bar-row analytics-bar-link"
                        href="{{ AnalyticsDetailLink::to('equipment', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
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

            @if($slowMoving['items'] !== [])
                <p class="analytics-metric-note analytics-metric-note-spaced">Least utilized in the same period.</p>

                <div class="analytics-table-scroll">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="numeric">Released</th>
                                <th>Last activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($slowMoving['items'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="numeric">{{ $row['released'] }}</td>
                                    <td>{{ $row['last_activity'] ? \Carbon\Carbon::parse($row['last_activity'])->format('d M Y') : 'No release recorded' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</section>

<section class="analytics-section{{ $coverage['items'] === [] ? ' is-empty' : '' }}">
    <h2>Stock Coverage &amp; Stockout Risk</h2>
    <div class="analytics-section-body">
        @if($coverage['items'] === [])
            <p class="analytics-empty">{{ $coverage['summary'] }}</p>
        @else
            <p class="analytics-metric-note">
                An estimate, not a probability. Average daily usage is the quantity physically released over the
                {{ $coverage['window_days'] }}-day period divided by its length; coverage is current usable
                availability divided by that rate. It assumes usage continues at the observed rate.
                Risk bands: High under {{ $coverage['thresholds']['high'] }} days,
                Medium up to {{ $coverage['thresholds']['medium'] }} days, Low beyond that.
                An item is only measured once it has at least {{ $coverage['requirement']['releases'] }}
                separate releases in a period of at least {{ $coverage['requirement']['window_days'] }} days;
                below that it reports insufficient usage history instead of a rate.
            </p>

            @if($coverage['insufficient'] > 0)
                <p class="analytics-metric-note">
                    {{ $coverage['insufficient'] }} of {{ count($coverage['items']) }}
                    {{ $coverage['insufficient'] === 1 ? 'item does' : 'items do' }}
                    not yet have enough release history to project coverage.
                </p>
            @endif

            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="numeric">Available Now</th>
                            <th class="numeric">Released</th>
                            <th class="numeric">Avg / Day</th>
                            <th class="numeric">Est. Days Cover</th>
                            <th>Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($coverage['items'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('coverage', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['available'] }}</td>
                                <td class="numeric">{{ $row['released'] }}</td>
                                {{--
                                    Rate, coverage and risk stand or fall together: showing a
                                    daily rate beside "insufficient history" would invite the
                                    reader to do the division themselves.
                                --}}
                                <td class="numeric">{{ $row['sufficient'] ? $row['per_day'] : '—' }}</td>
                                <td class="numeric">{{ $row['sufficient'] && $row['days_cover'] !== null ? $row['days_cover'] : '—' }}</td>
                                <td>
                                    @if(! $row['sufficient'])
                                        <span class="analytics-tag">Insufficient usage history</span>
                                    @elseif($row['risk'] === null)
                                        <span class="analytics-tag">No recent consumption</span>
                                    @else
                                        <span class="analytics-tag {{ $row['risk'] === 'High' ? 'is-critical' : ($row['risk'] === 'Medium' ? 'is-attention' : 'is-positive') }}">
                                            {{ $row['risk'] }} Risk
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $coverage['summary'] }}</p>
        @endif
    </div>
</section>
