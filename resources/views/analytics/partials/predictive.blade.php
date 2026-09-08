@php
    /*
    | Predictive Analytics.
    |
    | The projection is a weighted moving average over completed comparable
    | periods. It is deterministic arithmetic: no model, no training, no
    | randomness. Nothing here may be described as machine learning, and the
    | wording throughout stays hedged - expected, projected, estimated.
    |
    | When the history is not there, this page says so instead of printing a
    | number. That is the point of the minimum-history guard, not a fault.
    */
    use App\Support\AnalyticsDetailLink;

    $hasDemand = $demand['available'] ?? false;
    $hasEquipment = ($equipmentForecast['available'] ?? false) && ($equipmentForecast['items'] ?? []) !== [];
    $hasBusy = $busyPeriod['available'] ?? false;
    $coverageRisk = $coverage['available'] ?? false;
@endphp

<div class="analytics-kpis">
    <a
        class="analytics-kpi analytics-kpi-link"
        href="{{ AnalyticsDetailLink::to('forecast', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['metric' => 'demand']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Forecasted Demand</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        @if($hasDemand)
            <strong>{{ $demand['forecast'] }}</strong>
            <small>Projected requests for {{ $forecastFrom->format('d M') }} &ndash; {{ $forecastTo->format('d M Y') }}</small>
        @else
            <strong class="is-text">Not enough history</strong>
            <small>Requires at least 3 completed comparable periods</small>
        @endif
    </a>

    {{--
        Stockout Risk has no detail of its own: coverage is explained per
        item, from the rows on Inventory Health. It stays a read-only figure
        rather than a card that looks clickable and leads nowhere.
    --}}
    <article class="analytics-kpi{{ $coverageRisk && $coverage['high_risk'] > 0 ? ' is-attention' : '' }}">
        <span class="analytics-kpi-label">Stockout Risk</span>
        @if($coverageRisk)
            <strong>{{ $coverage['high_risk'] }}</strong>
            <small>Items estimated to last under {{ $coverage['thresholds']['high'] }} days at the observed usage rate</small>
        @else
            <strong class="is-text">Insufficient usage history</strong>
            <small>
                Needs at least {{ $coverage['requirement']['releases'] }} separate releases of an item
                across a period of at least {{ $coverage['requirement']['window_days'] }} days
            </small>
        @endif
    </article>

    <a
        class="analytics-kpi analytics-kpi-link"
        href="{{ AnalyticsDetailLink::to('forecast', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['metric' => 'busy']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Expected Busy Period</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        @if($hasBusy && $busyPeriod['busiest'])
            <strong class="is-text">{{ $busyPeriod['busiest']['label'] }}</strong>
            <small>{{ $busyPeriod['busiest']['range'] }}</small>
        @else
            <strong class="is-text">Not enough history</strong>
            <small>Requires completed comparable periods to project a shape</small>
        @endif
    </a>

    <a
        class="analytics-kpi analytics-kpi-link{{ $hasEquipment && ($equipmentForecast['at_risk_count'] ?? 0) > 0 ? ' is-attention' : '' }}"
        href="{{ AnalyticsDetailLink::to('forecast', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['metric' => 'equipment']) }}"
    >
        <span class="analytics-kpi-top">
            <span class="analytics-kpi-label">Equipment Shortage Risk</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-arrow" />
        </span>
        @if($hasEquipment)
            <strong>{{ $equipmentForecast['at_risk_count'] ?? 0 }}</strong>
            <small>Equipment types that may not cover projected demand</small>
        @else
            <strong class="is-text">Not enough history</strong>
            <small>Requires at least 3 completed comparable periods</small>
        @endif
    </a>
</div>

{{--
    Each projection is guarded on its own history, so one can be available
    while another is not. Saying so keeps the mix from reading as a fault.
--}}
<p class="analytics-guard-note">
    Each projection below is checked against its own history. A section can
    report a figure while another still says there is not enough history,
    because they count different things over different records.
</p>

<section class="analytics-section">
    <h2>Borrowing Demand Forecast</h2>
    <div class="analytics-section-body">
        @if(! $hasDemand)
            <p class="analytics-empty">
                {{ $demand['reason'] }}
                {{ $demand['requirement'] }}
                Continue recording transactions and the forecast will become available automatically.
            </p>

            @if(($demand['current'] ?? 0) > 0)
                {{-- What is already on the books, stated as a count, not a projection. --}}
                <p class="analytics-reading">
                    Known scheduled demand for the selected period: {{ $demand['current'] }}
                    {{ $demand['current'] === 1 ? 'request' : 'requests' }} already recorded.
                    This is a count of what exists, not a forecast.
                </p>
            @endif

            @if(($demand['history'] ?? []) !== [])
                <div class="analytics-table-scroll">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Historical period</th>
                                <th class="numeric">Observed requests</th>
                                <th class="numeric">Weight</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($demand['history'] as $row)
                                <tr>
                                    <td>{{ $row['label'] }}</td>
                                    <td class="numeric">{{ $row['count'] }}</td>
                                    <td class="numeric">{{ $row['weight'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <div class="analytics-stat-grid">
                <div class="analytics-stat is-static">
                    <span>Current period</span>
                    <strong>{{ $demand['current'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Projected next period</span>
                    <strong>{{ $demand['forecast'] }}</strong>
                </div>
                <div class="analytics-stat is-static">
                    <span>Direction</span>
                    <strong class="is-text">{{ ucfirst($demand['direction']) }}</strong>
                </div>
            </div>

            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Historical period</th>
                            <th class="numeric">Observed requests</th>
                            <th class="numeric">Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($demand['history'] as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td class="numeric">{{ $row['count'] }}</td>
                                <td class="numeric">{{ $row['weight'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <details class="analytics-explain">
                <summary>How this was calculated</summary>
                <div>
                    @php
                        /* Oldest first in the table; the formula reads most recent first. */
                        $ordered = array_reverse($demand['history']);
                        $numerator = collect($ordered)
                            ->map(fn (array $row): string => $row['count'].'×'.$row['weight'])
                            ->implode(' + ');
                        $weightSum = collect($ordered)->sum('weight');
                        $raw = collect($ordered)->sum(fn (array $row): int => $row['count'] * $row['weight']);
                    @endphp

                    <p>Method: weighted moving average over the {{ count($demand['history']) }} completed periods before the one selected.</p>
                    <p class="analytics-formula">({{ $numerator }}) ÷ {{ $weightSum }} = {{ round($raw / max(1, $weightSum), 2) }} ≈ {{ $demand['forecast'] }}</p>
                    <p>Rounded to whole requests and never below zero. This is an estimate based on historical activity, not a guarantee.</p>
                </div>
            </details>

            <p class="analytics-reading">{{ $demand['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ ($divisionForecast['available'] ?? false) ? '' : ' is-empty' }}">
    <h2>Expected Demand by Division</h2>
    <div class="analytics-section-body">
        @if(! ($divisionForecast['available'] ?? false))
            <p class="analytics-empty">Not enough historical data to project demand by division.</p>
        @else
            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Division</th>
                            <th class="numeric">Current period</th>
                            <th class="numeric">Projected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($divisionForecast['groups'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('division', 'predictive', $periodSelection, $row['code'], null, ['for' => $row['code']]) }}">
                                        {{ $row['label'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['current'] }}</td>
                                <td class="numeric">{{ $row['forecast'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $divisionForecast['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ ($unitForecast['available'] ?? false) ? '' : ' is-empty' }}">
    <h2>Expected Demand by Unit</h2>
    <div class="analytics-section-body">
        @if(! ($unitForecast['available'] ?? false))
            <p class="analytics-empty">
                Not enough historical data to project demand by unit.
                A unit is only projected once it has recorded activity across the completed periods.
            </p>
        @else
            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th class="numeric">Observations</th>
                            <th class="numeric">Current period</th>
                            <th class="numeric">Projected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unitForecast['units'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('unit', 'predictive', $periodSelection, null, $row['unit'], ['for' => $row['unit']]) }}">
                                        {{ $row['unit'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['observations'] }}</td>
                                <td class="numeric">{{ $row['current'] }}</td>
                                <td class="numeric">{{ $row['forecast'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $unitForecast['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $hasEquipment ? '' : ' is-empty' }}">
    <h2>Equipment Demand &amp; Availability</h2>
    <div class="analytics-section-body">
        @if(! ($equipmentForecast['available'] ?? false))
            <p class="analytics-empty">{{ $equipmentForecast['reason'] ?? 'Not enough historical data to forecast equipment demand.' }}</p>
        @elseif(! $hasEquipment)
            <p class="analytics-empty">{{ $equipmentForecast['summary'] }}</p>
        @else
            <p class="analytics-metric-note">
                Expected availability comes from the Inventory module for
                {{ $forecastFrom->format('d M') }} &ndash; {{ $forecastTo->format('d M Y') }} and already excludes reserved units,
                equipment still out, linen under Laundry Operations, and units held by an incident.
            </p>

            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="numeric">Forecast demand</th>
                            <th class="numeric">Expected availability</th>
                            <th>Outlook</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($equipmentForecast['items'] as $row)
                            <tr>
                                <td>
                                    <a href="{{ AnalyticsDetailLink::to('equipment', 'predictive', $periodSelection, null, null, ['item' => $row['item_id']]) }}">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="numeric">{{ $row['demand'] }}</td>
                                <td class="numeric">{{ $row['expected_available'] }}</td>
                                <td>
                                    <span class="analytics-tag {{ $row['status'] === 'Possible Shortage' ? 'is-critical' : ($row['status'] === 'Limited' ? 'is-attention' : 'is-positive') }}">
                                        {{ $row['status'] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="analytics-reading">{{ $equipmentForecast['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $hasBusy ? '' : ' is-empty' }}">
    <h2>Expected Busy Period</h2>
    <div class="analytics-section-body">
        @if(! $hasBusy)
            <p class="analytics-empty">Not enough historical data to show how demand is expected to fall across the next period.</p>
        @else
            <div class="analytics-bars">
                @foreach($busyPeriod['buckets'] as $bucket)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">
                                {{ $bucket['label'] }}
                                <small>{{ $bucket['range'] }}</small>
                            </span>
                            <span class="analytics-bar-value">
                                {{ $bucket['expected'] }}
                                <span class="analytics-tag {{ $bucket['level'] === 'High' ? 'is-attention' : '' }}">{{ $bucket['level'] }}</span>
                            </span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ max(2, $busyPeriod['busiest'] && $busyPeriod['busiest']['expected'] > 0 ? (int) round($bucket['expected'] / $busyPeriod['busiest']['expected'] * 100) : 0) }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $busyPeriod['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section">
    <h2>Forecast Basis</h2>
    <div class="analytics-section-body">
        <div class="analytics-stat-grid">
            <div class="analytics-stat is-static">
                <span>Method</span>
                <strong class="is-text">Weighted Moving Average</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>History used</span>
                <strong class="is-text">{{ App\Services\ForecastService::HISTORY_PERIODS }} completed periods</strong>
            </div>
            <div class="analytics-stat is-static">
                <span>Weights</span>
                <strong class="is-text">{{ implode(' · ', App\Services\ForecastService::WEIGHTS) }}</strong>
            </div>
        </div>

        <p class="analytics-reading">{{ $forecastBasis['summary'] }}</p>

        <ul class="analytics-insights">
            @foreach($forecastBasis['details'] as $detail)
                <li class="analytics-insight">
                    <span class="analytics-insight-mark" aria-hidden="true"></span>
                    <span>{{ $detail }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
