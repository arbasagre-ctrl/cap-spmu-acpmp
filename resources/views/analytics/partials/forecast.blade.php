{{--
    Forecast: the only predictive section.

    Everything here is a projection, never a record. When the history is too
    thin the figures are withheld rather than guessed, and the reason is shown
    in their place.
--}}

<div class="analytics-forecast-window">
    <div>
        <span class="analytics-kpi-label">Selected period</span>
        <strong>{{ $from->format('d M Y') }} &ndash; {{ $to->format('d M Y') }}</strong>
    </div>
    <div>
        <span class="analytics-kpi-label">Forecast period</span>
        <strong>{{ $forecastFrom->format('d M Y') }} &ndash; {{ $forecastTo->format('d M Y') }}</strong>
    </div>
</div>

@if(! $demand['available'])
    <section class="analytics-section is-empty">
        <h2>Forecast</h2>
        <div class="analytics-section-body">
            <p class="analytics-empty">{{ $demand['reason'] }}</p>
            <p class="analytics-reading">{{ $demand['requirement'] }}</p>

            @if($demand['current'] > 0)
                <p class="analytics-reading">
                    Known scheduled demand for the selected period: {{ $demand['current'] }}
                    {{ $demand['current'] === 1 ? 'request' : 'requests' }} already recorded.
                    This is a count of what exists, not a forecast.
                </p>
            @endif
        </div>
    </section>
@else
    <div class="analytics-kpis">
        <article class="analytics-kpi">
            <span class="analytics-kpi-label">Expected Requests</span>
            <strong>{{ $demand['forecast'] }}</strong>
            <small>
                @switch($demand['direction'])
                    @case('higher') Higher than the current period @break
                    @case('lower') Lower than the current period @break
                    @default Similar to the current period
                @endswitch
            </small>
        </article>

        <article class="analytics-kpi">
            <span class="analytics-kpi-label">Main Group</span>
            <strong class="is-text">
                {{ $divisionForecast['leader']['short_label'] ?? 'Not enough data' }}
            </strong>
            <small>
                {{ $divisionForecast['leader'] ? 'Expected to remain highest' : 'No group is expected to lead' }}
            </small>
        </article>

        <article class="analytics-kpi">
            <span class="analytics-kpi-label">Busiest Unit</span>
            <strong class="is-text">
                {{ $unitForecast['available'] ? $unitForecast['leader']['unit'] : 'Insufficient history' }}
            </strong>
            <small>
                @if($unitForecast['available'])
                    About {{ $unitForecast['leader']['forecast'] }} expected
                    {{ $unitForecast['leader']['forecast'] === 1 ? 'request' : 'requests' }}
                @else
                    No unit has enough history to predict
                @endif
            </small>
        </article>

        <article class="analytics-kpi{{ ($equipmentForecast['at_risk_count'] ?? 0) > 0 ? ' is-attention' : '' }}">
            <span class="analytics-kpi-label">Equipment to Watch</span>
            <strong>{{ $equipmentForecast['at_risk_count'] ?? 0 }}</strong>
            <small>Equipment types that may not cover demand</small>
        </article>
    </div>

    <section class="analytics-section">
        <h2>Borrowing Demand Forecast</h2>
        <div class="analytics-section-body">
            @php
                $bars = collect($demand['history'])
                    ->map(fn (array $row): array => [
                        'label' => $row['label'],
                        'value' => $row['count'],
                        'kind' => 'Actual',
                    ])
                    ->push([
                        'label' => $from->format('d M').' – '.$to->format('d M Y'),
                        'value' => $demand['current'],
                        'kind' => 'Actual',
                    ])
                    ->push([
                        'label' => $forecastFrom->format('d M').' – '.$forecastTo->format('d M Y'),
                        'value' => $demand['forecast'],
                        'kind' => 'Forecast',
                    ]);

                $peak = max(1, (int) $bars->max('value'));
            @endphp

            <div class="analytics-bars">
                @foreach($bars as $bar)
                    <div class="analytics-bar-row{{ $bar['kind'] === 'Forecast' ? ' is-forecast' : '' }}">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">
                                {{ $bar['label'] }}
                                <span class="analytics-tag">{{ $bar['kind'] }}</span>
                            </span>
                            <span class="analytics-bar-value">{{ $bar['value'] }}</span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ (int) round($bar['value'] / $peak * 100) }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $demand['summary'] }}</p>
        </div>
    </section>

    <section class="analytics-section{{ $divisionForecast['available'] ? '' : ' is-empty' }}">
        <h2>Expected Borrower Group</h2>
        <div class="analytics-section-body">
            @if(! $divisionForecast['available'])
                <p class="analytics-empty">Not enough historical data to forecast by borrower group.</p>
            @else
                @php($groupPeak = max(1, (int) collect($divisionForecast['groups'])->max('forecast')))

                <div class="analytics-bars">
                    @foreach($divisionForecast['groups'] as $group)
                        <div class="analytics-bar-row is-{{ strtolower(explode('_', $group['code'])[0]) }}">
                            <div class="analytics-bar-head">
                                <span class="analytics-bar-name">{{ $group['label'] }}</span>
                                <span class="analytics-bar-value">
                                    {{ $group['forecast'] }} expected
                                    <small>(now {{ $group['current'] }})</small>
                                </span>
                            </div>
                            <div class="analytics-bar-track">
                                <span class="analytics-bar-fill" style="width: {{ (int) round($group['forecast'] / $groupPeak * 100) }}%"></span>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="analytics-reading">{{ $divisionForecast['summary'] }}</p>
            @endif
        </div>
    </section>

    <section class="analytics-section{{ ($equipmentForecast['items'] ?? []) === [] ? ' is-empty' : '' }}">
        <h2>Equipment Demand &amp; Availability Forecast</h2>
        <div class="analytics-section-body">
            @if(! ($equipmentForecast['available'] ?? false))
                <p class="analytics-empty">{{ $equipmentForecast['reason'] ?? 'Not enough historical data to forecast equipment demand.' }}</p>
            @elseif($equipmentForecast['items'] === [])
                <p class="analytics-empty">{{ $equipmentForecast['summary'] }}</p>
            @else
                <div class="analytics-table-scroll">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th scope="col">Equipment</th>
                                <th scope="col" class="is-numeric">Expected demand</th>
                                <th scope="col" class="is-numeric">Expected available</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($equipmentForecast['items'] as $row)
                                <tr>
                                    <th scope="row">
                                        {{ $row['name'] }}
                                        @if($row['laundry_required'] && $row['laundry_held'] > 0)
                                            <small>{{ $row['laundry_held'] + 0 }} under Laundry Operations</small>
                                        @endif
                                    </th>
                                    <td class="is-numeric">{{ $row['demand'] }}</td>
                                    <td class="is-numeric">{{ $row['expected_available'] + 0 }}</td>
                                    <td>
                                        <span class="analytics-status is-{{ strtolower(str_replace(' ', '-', $row['status'])) }}">
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

    <section class="analytics-section{{ $busyPeriod['available'] ? '' : ' is-empty' }}">
        <h2>Expected Busy Period</h2>
        <div class="analytics-section-body">
            @if(! $busyPeriod['available'])
                <p class="analytics-empty">Not enough historical data to show how demand falls across the next period.</p>
            @else
                <div class="analytics-table-scroll">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th scope="col">Slice</th>
                                <th scope="col">Dates</th>
                                <th scope="col" class="is-numeric">Expected requests</th>
                                <th scope="col">Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($busyPeriod['buckets'] as $bucket)
                                <tr>
                                    <th scope="row">{{ $bucket['label'] }}</th>
                                    <td>{{ $bucket['range'] }}</td>
                                    <td class="is-numeric">{{ $bucket['expected'] }}</td>
                                    <td>
                                        <span class="analytics-status is-{{ strtolower($bucket['level']) }}">
                                            {{ $bucket['level'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="analytics-reading">{{ $busyPeriod['summary'] }}</p>
            @endif
        </div>
    </section>
@endif

<section class="analytics-section analytics-basis">
    <h2>Forecast Basis</h2>
    <div class="analytics-section-body">
        <p class="analytics-reading">{{ $forecastBasis['summary'] }}</p>

        <details class="analytics-details">
            <summary>View calculation details</summary>
            <ul>
                @foreach($forecastBasis['details'] as $detail)
                    <li>{{ $detail }}</li>
                @endforeach
            </ul>
        </details>
    </div>
</section>
