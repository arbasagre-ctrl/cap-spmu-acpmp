@php
    /*
    | Forecast & Planning.
    |
    | FOUR THINGS ARE KEPT APART, AND NEVER MERGED
    | --------------------------------------------
    |  1. Observed history   - what was actually filed in completed periods.
    |  2. Scheduled demand   - requests ALREADY FILED for the period the
    |                          forecast covers. These are records, not a
    |                          projection. They are never called a forecast.
    |  3. Forecasted demand  - the weighted moving average in ForecastService.
    |                          A statistical estimate, and labelled as one.
    |  4. Forecast readiness - whether enough completed history exists for (3)
    |                          to be produced at all.
    |
    | The method is a weighted moving average over three completed periods. It
    | is arithmetic, not a trained model, and nothing here describes it as one.
    |
    | Layout only: every figure comes from ForecastService or AnalyticsService
    | through AnalyticsController. Where a reading cannot be produced the page
    | says so rather than printing a zero that would read as a real count.
    */
    use App\Support\AnalyticsDetailLink;

    $division = $selectedDivision === 'all' ? null : $selectedDivision;
    $unit = $selectedUnit === 'all' ? null : $selectedUnit;

    $hasDemand = $demand['available'] ?? false;
    $hasDivision = $divisionForecast['available'] ?? false;
    $hasUnit = $unitForecast['available'] ?? false;
    $hasEquipment = ($equipmentForecast['available'] ?? false) && ($equipmentForecast['items'] ?? []) !== [];
    $hasBusy = $busyPeriod['available'] ?? false;

    $readiness = $demand['readiness'] ?? [];
    $ready = ($readiness['periods_met'] ?? false) && ($readiness['observations_met'] ?? false);

    $scheduledTotal = (int) ($scheduled['requests'] ?? 0);
    $windowLabel = $forecastFrom->format('d M').' – '.$forecastTo->format('d M Y');

    $num = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');

    /*
     | The outlook series.
     |
     | Observed = the three completed history periods plus the selected one.
     | The forecast point is appended only when the service produced one; there
     | is deliberately no placeholder point when it did not, because a drawn
     | point is indistinguishable from a real projection.
     */
    $observed = [];

    foreach ($demand['history'] ?? [] as $row) {
        $observed[] = [
            'label' => trim(\Illuminate\Support\Str::of($row['label'])->after('–')->before(' 20')),
            'full' => $row['label'],
            'count' => (int) $row['count'],
        ];
    }

    $observed[] = [
        'label' => 'This period',
        'full' => 'The selected reporting period',
        'count' => (int) ($demand['current'] ?? 0),
    ];

    $forecastPoint = $hasDemand
        ? ['label' => 'Next period', 'full' => $windowLabel, 'count' => (int) $demand['forecast']]
        : null;

    /* Geometry over the whole series so the two segments share one scale. */
    $series = $observed;

    if ($forecastPoint) {
        $series[] = $forecastPoint;
    }

    $seriesMax = max(1, (int) max(array_column($series, 'count')));
    $seriesCount = count($series);
    $topPad = 8.0;
    $baseline = 92.0;
    $span = $baseline - $topPad;

    $coords = [];

    foreach ($series as $index => $point) {
        $coords[] = $point + [
            'x' => $seriesCount > 1 ? round($index / ($seriesCount - 1) * 100, 3) : 50.0,
            'y' => round($baseline - ($point['count'] / $seriesMax * $span), 3),
            'forecast' => $forecastPoint !== null && $index === $seriesCount - 1,
        ];
    }

    $observedCoords = $forecastPoint ? array_slice($coords, 0, -1) : $coords;

    $solidPath = 'M'.implode(' L', array_map(
        static fn (array $c): string => $c['x'].','.$c['y'],
        $observedCoords
    ));

    /* The dashed leg joins the last observed point to the projected one. */
    $dashedPath = null;

    if ($forecastPoint) {
        $last = $observedCoords[count($observedCoords) - 1];
        $next = $coords[$seriesCount - 1];
        $dashedPath = 'M'.$last['x'].','.$last['y'].' L'.$next['x'].','.$next['y'];
    }

    /*
     | Division and unit demand fall back to what is actually scheduled when
     | the statistical forecast is unavailable. The card title changes with it,
     | so a scheduled count is never presented under a forecast heading.
     */
    $divisionRows = $hasDivision
        ? collect($divisionForecast['groups'])
            ->map(fn (array $row): array => [
                'code' => $row['code'],
                'label' => $row['short_label'],
                'value' => (int) $row['forecast'],
            ])
            ->sortByDesc('value')->values()
        : collect($scheduled['divisions']['groups'] ?? [])
            ->map(fn (array $row): array => [
                'code' => $row['code'],
                'label' => $row['label'],
                'value' => (int) $row['count'],
            ])
            ->sortByDesc('value')->values();

    $divisionMax = (int) ($divisionRows->max('value') ?: 0);

    $unitRows = $hasUnit
        ? collect($unitForecast['units'])
            ->map(fn (array $row): array => ['unit' => $row['unit'], 'value' => (int) $row['forecast']])
            ->take(5)->values()
        : collect($scheduled['units']['columns'] ?? [])
            ->flatMap(fn (array $column): array => collect($column['units'])
                ->map(fn (array $row): array => ['unit' => $row['name'], 'value' => (int) $row['count']])
                ->all())
            ->sortByDesc('value')->take(5)->values();

    $unitMax = (int) ($unitRows->max('value') ?: 0);

    /* Shortfalls first: a covered item is not what a planner is looking for. */
    $equipmentRows = $hasEquipment
        ? collect($equipmentForecast['items'])
            ->sortBy(fn (array $row): int => $row['status'] === 'Sufficient' ? 1 : 0)
            ->take(6)->values()
        : collect();

    $busyPeak = $hasBusy ? (int) max(array_column($busyPeriod['buckets'], 'expected')) : 0;

    /*
     | Planning notes.
     |
     | Each note restates a figure already on this page, in the order a planner
     | would act on it: coverage risk, then known commitments, then where the
     | demand sits, then what is still missing. Nothing is recommended and
     | nothing is inferred; a note with no figure behind it is simply absent.
     */
    $notes = [];

    if ($hasEquipment && ($equipmentForecast['at_risk_count'] ?? 0) > 0) {
        $notes[] = ['tone' => 'urgent', 'icon' => 'warning', 'text' => $equipmentForecast['summary']];
    } elseif ($hasEquipment) {
        $notes[] = ['tone' => 'steady', 'icon' => 'check-circle', 'text' => $equipmentForecast['summary']];
    }

    if ($scheduledTotal > 0) {
        $notes[] = [
            'tone' => 'info',
            'icon' => 'calendar',
            'text' => $scheduledTotal.' '.($scheduledTotal === 1 ? 'request is' : 'requests are')
                .' already scheduled for '.$windowLabel.'. These are filed records, not a projection.',
        ];
    }

    if ($divisionRows->isNotEmpty() && $divisionMax > 0) {
        $leader = $divisionRows->first();
        $notes[] = [
            'tone' => 'info',
            'icon' => 'users',
            'text' => $hasDivision
                ? $leader['label'].' is projected to remain the largest borrowing division.'
                : $leader['label'].' accounts for the largest known scheduled demand.',
        ];
    }

    if (! $hasDemand) {
        $notes[] = ['tone' => 'muted', 'icon' => 'information', 'text' => $demand['requirement'] ?? $demand['reason']];
    } elseif (! $hasBusy) {
        $notes[] = ['tone' => 'muted', 'icon' => 'information', 'text' => 'A busy-period pattern is not yet available for this period.'];
    }

    $notes = array_slice($notes, 0, 4);
@endphp

{{-- Headline state ------------------------------------------------------- --}}
<div class="analytics-kpis">
    <a
        class="analytics-kpi-card tone-quantity"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.readiness']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="clipboard-check" size="19" /></span>
        <span class="analytics-kpi-card-label">Forecast Readiness</span>
        <strong class="analytics-kpi-card-value is-text">{{ $ready ? 'Ready' : 'Limited history' }}</strong>
        <span class="analytics-kpi-card-note">
            {{ $ready
                ? 'Required historical periods are available'
                : 'More completed borrowing activity is required' }}
        </span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    {{--
        Already-filed records for the forecast window. A real zero here means
        nothing has been filed for that period yet, which is a true count and
        not an unavailable reading, so it is printed as a number.
    --}}
    <a
        class="analytics-kpi-card tone-requests"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.scheduled']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="calendar" size="19" /></span>
        <span class="analytics-kpi-card-label">Scheduled Demand</span>
        <strong class="analytics-kpi-card-value">{{ $scheduledTotal }}</strong>
        <span class="analytics-kpi-card-note">Requests already recorded for the next period</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-available"
        href="{{ AnalyticsDetailLink::to('forecast', 'predictive', $periodSelection, $division, $unit, ['metric' => 'demand']) }}"
        aria-label="View how the demand forecast was produced"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="analytics" size="19" /></span>
        <span class="analytics-kpi-card-label">Forecasted Demand</span>
        @if($hasDemand)
            <strong class="analytics-kpi-card-value">{{ $demand['forecast'] }}</strong>
            <span class="analytics-kpi-card-note">Projected requests for the next period</span>
        @else
            <strong class="analytics-kpi-card-value is-text">Not available</strong>
            <span class="analytics-kpi-card-note">Insufficient completed history</span>
        @endif
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-attention"
        href="{{ AnalyticsDetailLink::to('forecast', 'predictive', $periodSelection, $division, $unit, ['metric' => 'equipment']) }}"
        aria-label="View equipment coverage against expected demand"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="box" size="19" /></span>
        <span class="analytics-kpi-card-label">Equipment Coverage Risk</span>
        @if($hasEquipment)
            <strong class="analytics-kpi-card-value">{{ $equipmentForecast['at_risk_count'] }}</strong>
            <span class="analytics-kpi-card-note">
                {{ ($equipmentForecast['at_risk_count'] ?? 0) === 0
                    ? 'Expected availability covers every item'
                    : ((($equipmentForecast['at_risk_count'] ?? 0) === 1 ? 'Item' : 'Items').' may not cover expected demand') }}
            </span>
        @else
            {{-- Nothing was measured, which is not the same as nothing at risk. --}}
            <strong class="analytics-kpi-card-value is-text">Not measurable</strong>
            <span class="analytics-kpi-card-note">Insufficient item-level history</span>
        @endif
        <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Outlook and readiness ------------------------------------------------ --}}
<div class="analytics-forecast-main">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.outlook']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="analytics" size="15" /></span>
            <div>
                <h2>Borrowing Demand Outlook</h2>
                <p>Requests filed per period, with the projection shown separately.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.outlook']) }}" aria-label="View Borrowing Demand Outlook details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            {{-- Only states that actually appear in the plot are listed. --}}
            <ul class="analytics-outlook-legend">
                <li><span class="analytics-outlook-key is-observed" aria-hidden="true"></span>Observed</li>
                @if($forecastPoint)
                    <li><span class="analytics-outlook-key is-forecast" aria-hidden="true"></span>Forecast</li>
                @endif
                @if($scheduledTotal > 0)
                    <li><span class="analytics-outlook-key is-scheduled" aria-hidden="true"></span>Scheduled</li>
                @endif
            </ul>

            <div class="analytics-outlook" role="img" aria-label="Borrowing requests filed per period{{ $forecastPoint ? ', with a projection for the next period' : '' }}">
                <div class="analytics-outlook-plot">
                    <span class="analytics-line-grid" aria-hidden="true"></span>

                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                        <path class="analytics-outlook-solid" d="{{ $solidPath }}" vector-effect="non-scaling-stroke" />
                        @if($dashedPath)
                            <path class="analytics-outlook-dashed" d="{{ $dashedPath }}" vector-effect="non-scaling-stroke" />
                        @endif
                    </svg>

                    <div class="analytics-outlook-markers">
                        @foreach($coords as $coord)
                            <span
                                class="analytics-outlook-marker{{ $coord['forecast'] ? ' is-forecast' : '' }}"
                                style="left: {{ $coord['x'] }}%; top: {{ $coord['y'] }}%"
                                tabindex="0"
                                role="img"
                                data-chart-tip
                                data-tip-title="{{ $coord['full'] }}"
                                data-tip-rows="{{ json_encode([
                                    $coord['forecast']
                                        ? ['Forecast', $coord['count'].' projected '.($coord['count'] === 1 ? 'request' : 'requests')]
                                        : ['Observed', $coord['count'].' '.($coord['count'] === 1 ? 'request' : 'requests')],
                                ]) }}"
                                aria-label="{{ $coord['forecast'] ? 'Forecast' : 'Observed' }} {{ $coord['full'] }}: {{ $coord['count'] }} {{ $coord['count'] === 1 ? 'request' : 'requests' }}"
                            >
                                <span class="analytics-outlook-dot" aria-hidden="true"></span>
                                <span class="analytics-outlook-tip" aria-hidden="true">{{ $coord['count'] }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="analytics-outlook-axis" aria-hidden="true">
                    @foreach($coords as $coord)
                        <span class="{{ $coord['forecast'] ? 'is-forecast' : '' }}">{{ $coord['label'] }}</span>
                    @endforeach
                </div>
            </div>

            @if($scheduledTotal > 0)
                <p class="analytics-outlook-note is-scheduled">
                    <x-icon name="calendar" size="14" aria-hidden="true" />
                    <span>
                        <strong>{{ $scheduledTotal }} {{ $scheduledTotal === 1 ? 'request is' : 'requests are' }} already scheduled for the next period.</strong>
                        Filed records, not a projection.
                    </span>
                </p>
            @endif
        </div>

        @if(($demand['history'] ?? []) !== [])
            <details class="analytics-fold">
                <summary>View historical periods</summary>
                <div class="analytics-fold-body">
                    <table class="analytics-mini-table">
                        <thead>
                            <tr>
                                <th scope="col">Historical period</th>
                                <th scope="col" class="numeric">Observed requests</th>
                                <th scope="col" class="numeric">Weight</th>
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
            </details>
        @endif
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.readiness']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clipboard-check" size="15" /></span>
            <div>
                <h2>Forecast Readiness</h2>
                <p>What the method needs before it will project.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.readiness']) }}" aria-label="View Forecast Readiness details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            {{-- The same two conditions the service tests, stated in its terms. --}}
            <ul class="analytics-check">
                <li class="{{ ($readiness['periods_met'] ?? false) ? 'is-met' : 'is-unmet' }}">
                    <span class="analytics-check-mark" aria-hidden="true">
                        <x-icon :name="($readiness['periods_met'] ?? false) ? 'check-circle' : 'warning'" size="15" />
                    </span>
                    <span class="analytics-check-label">Completed periods</span>
                    <span class="analytics-check-value">{{ $readiness['periods_complete'] ?? 0 }} / {{ $readiness['periods_required'] ?? 3 }}</span>
                </li>
                <li class="{{ ($readiness['observations_met'] ?? false) ? 'is-met' : 'is-unmet' }}">
                    <span class="analytics-check-mark" aria-hidden="true">
                        <x-icon :name="($readiness['observations_met'] ?? false) ? 'check-circle' : 'warning'" size="15" />
                    </span>
                    <span class="analytics-check-label">Historical requests</span>
                    <span class="analytics-check-value">{{ $readiness['observations'] ?? 0 }} / {{ $readiness['observations_required'] ?? 3 }}</span>
                </li>
                <li class="is-met">
                    <span class="analytics-check-mark" aria-hidden="true"><x-icon name="check-circle" size="15" /></span>
                    <span class="analytics-check-label">Method</span>
                    <span class="analytics-check-value">{{ $readiness['method'] ?? 'Weighted Moving Average' }}</span>
                </li>
            </ul>

            <p class="analytics-check-state {{ $ready ? 'is-ready' : '' }}">
                <strong>{{ $ready ? 'Forecast available' : 'Forecast unavailable' }}</strong>
                <span>{{ $ready ? ($demand['summary'] ?? '') : $demand['requirement'] }}</span>
            </p>

        </div>
    </section>
</div>

{{-- Where the demand sits ------------------------------------------------ --}}
<div class="analytics-forecast-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.division']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="users" size="15" /></span>
            <div>
                {{-- The heading follows the data: a scheduled count is never called a forecast. --}}
                <h2>{{ $hasDivision ? 'Forecasted' : 'Scheduled' }} Demand by Division</h2>
                <p>{{ $hasDivision
                    ? 'Projected requests for the next period.'
                    : 'Requests already recorded for '.$windowLabel.'.' }}</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.division']) }}" aria-label="View Demand by Division details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($divisionRows->isEmpty() || $divisionMax <= 0)
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="users" size="19" /></span>
                    {{ $hasDivision
                        ? 'No division is expected to record borrowing activity next period.'
                        : 'No borrowing requests are recorded for the next period yet.' }}
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($divisionRows as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('division', 'predictive', $periodSelection, $row['code'], null, ['for' => $row['code']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['label'] }}"
                                data-tip-rows="{{ json_encode([[
                                    $hasDivision ? 'Projected requests' : 'Requests already recorded',
                                    (string) $row['value'],
                                ]]) }}"
                                aria-label="View details for {{ $row['label'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['label'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span class="analytics-rank-fill" style="width: {{ max(3, round($row['value'] / $divisionMax * 100)) }}%"></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $row['value'] }}
                                    <small>{{ $row['value'] === 1 ? 'request' : 'requests' }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.unit']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="id-badge" size="15" /></span>
            <div>
                <h2>{{ $hasUnit ? 'Forecasted' : 'Scheduled' }} Demand by Unit</h2>
                <p>{{ $hasUnit
                    ? 'Units with enough history of their own to project.'
                    : 'Requests already recorded for '.$windowLabel.'.' }}</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.unit']) }}" aria-label="View Demand by Unit details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($unitRows->isEmpty() || $unitMax <= 0)
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="id-badge" size="19" /></span>
                    {{ $hasUnit
                        ? 'No unit is expected to record borrowing activity next period.'
                        : 'No unit demand is recorded for the next period yet.' }}
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach($unitRows as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('unit', 'predictive', $periodSelection, null, $row['unit'], ['for' => $row['unit']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['unit'] }}"
                                data-tip-rows="{{ json_encode([[
                                    $hasUnit ? 'Projected requests' : 'Requests already recorded',
                                    (string) $row['value'],
                                ]]) }}"
                                aria-label="View details for {{ $row['unit'] }}"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['unit'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span class="analytics-rank-fill" style="width: {{ max(3, round($row['value'] / $unitMax * 100)) }}%"></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $row['value'] }}
                                    <small>{{ $row['value'] === 1 ? 'request' : 'requests' }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>

{{-- Can the stock cover it ------------------------------------------------ --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.equipment']) }}"
        class="analytics-card analytics-forecast-equipment">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
        <div>
            <h2>Equipment Demand &amp; Availability</h2>
            <p>Expected demand against expected availability for {{ $windowLabel }}.</p>
        </div>
        @if($hasEquipment && ($equipmentForecast['at_risk_count'] ?? 0) > 0)
            <span class="analytics-count-pill">{{ $equipmentForecast['at_risk_count'] }} at risk</span>
        @endif
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.equipment']) }}" aria-label="View Equipment Demand and Availability details"><x-icon name="chevron-right" size="15" /></a>
    </header>

    <div class="analytics-card-body">
        @if(! $hasEquipment)
            <p class="analytics-blank">
                <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                Equipment demand cannot be projected yet.
            </p>
        @else
            <ul class="analytics-coverbars">
                @foreach($equipmentRows as $row)
                    @php
                        /* Both bars share one scale, so the two are comparable. */
                        $scale = max(1, max($row['demand'], $row['expected_available']));
                    @endphp
                    <li class="{{ $row['status'] === 'Sufficient' ? '' : 'is-risk' }}">
                        <a
                            href="{{ AnalyticsDetailLink::to('equipment', 'predictive', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
                            data-chart-tip
                            data-tip-title="{{ $row['name'] }}"
                            data-tip-rows="{{ json_encode([
                                ['Expected demand', $num((float) $row['demand'])],
                                ['Expected available', $num((float) $row['expected_available'])],
                                ['Status', $row['status']],
                            ]) }}"
                        >
                            <span class="analytics-coverbars-head">
                                <span class="analytics-coverbars-name">{{ $row['name'] }}</span>
                                <span class="analytics-tag {{ $row['status'] === 'Sufficient' ? 'is-positive' : ($row['status'] === 'Limited' ? 'is-attention' : 'is-critical') }}">
                                    {{ $row['status'] }}
                                </span>
                            </span>

                            <span class="analytics-coverbars-row">
                                <span class="analytics-coverbars-key">Demand</span>
                                <span class="analytics-coverbars-track">
                                    <span class="analytics-coverbars-fill is-demand" style="width: {{ max(2, round($row['demand'] / $scale * 100)) }}%"></span>
                                </span>
                                <span class="analytics-coverbars-value">{{ $num((float) $row['demand']) }}</span>
                            </span>

                            <span class="analytics-coverbars-row">
                                <span class="analytics-coverbars-key">Available</span>
                                <span class="analytics-coverbars-track">
                                    <span class="analytics-coverbars-fill is-available" style="width: {{ max(2, round($row['expected_available'] / $scale * 100)) }}%"></span>
                                </span>
                                <span class="analytics-coverbars-value">{{ $num((float) $row['expected_available']) }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            <p class="analytics-insight-strip">
                <x-icon name="information" size="14" aria-hidden="true" />
                <span>{{ $equipmentForecast['summary'] }}</span>
            </p>
        @endif
    </div>
</section>

{{-- Shape of the period and what it implies ------------------------------- --}}
<div class="analytics-forecast-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.busy']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="calendar-clock" size="15" /></span>
            <div>
                <h2>Expected Busy Period</h2>
                <p>How the projected volume is expected to fall across the next period.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.busy']) }}" aria-label="View Expected Busy Period details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if(! $hasBusy)
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="calendar-clock" size="19" /></span>
                    Insufficient history for busy-period forecasting.
                </p>
            @else
                <ul class="analytics-peak">
                    @foreach($busyPeriod['buckets'] as $bucket)
                        <li
                            class="{{ $busyPeak > 0 && $bucket['expected'] === $busyPeak ? 'is-peak' : '' }}"
                            tabindex="0"
                                                        data-chart-tip
                            data-tip-title="{{ $bucket['range'] }}"
                            data-tip-rows="{{ json_encode(array_values(array_filter([
                                ['Projected requests', (string) $bucket['expected']],
                                $busyPeak > 0 && $bucket['expected'] === $busyPeak ? ['Busiest period', 'Yes'] : null,
                            ]))) }}"
                            aria-label="{{ $bucket['range'] }}: {{ $bucket['expected'] }} projected {{ $bucket['expected'] === 1 ? 'request' : 'requests' }}"
                        >
                            <span class="analytics-peak-label">{{ $bucket['label'] }}</span>
                            <span class="analytics-peak-track">
                                <span class="analytics-peak-fill" style="width: {{ $busyPeak > 0 ? max(2, round($bucket['expected'] / $busyPeak * 100)) : 2 }}%"></span>
                            </span>
                            <span class="analytics-peak-value">
                                {{ $bucket['expected'] }}
                                @if($busyPeak > 0 && $bucket['expected'] === $busyPeak)
                                    <small class="analytics-peak-tag">Peak</small>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                <p class="analytics-insight-strip">
                    <x-icon name="lightbulb" size="14" aria-hidden="true" />
                    <span>{{ $busyPeriod['summary'] }}</span>
                </p>
            @endif
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.notes']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="lightbulb" size="15" /></span>
            <div>
                <h2>Planning Notes</h2>
                <p>What the figures above mean for the next period.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.notes']) }}" aria-label="View Planning Notes details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body is-flush">
            @if($notes === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="lightbulb" size="19" /></span>
                    No planning signals are available for this period yet.
                </p>
            @else
                <ul class="analytics-notes">
                    @foreach($notes as $note)
                        <li class="tone-{{ $note['tone'] }}">
                            <span class="analytics-notes-icon" aria-hidden="true"><x-icon :name="$note['icon']" size="15" /></span>
                            <span>{{ $note['text'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
</div>

{{-- How the numbers were produced ----------------------------------------- --}}
<section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.methodology']) }}"
        class="analytics-card">
    <header class="analytics-card-head">
        <span class="analytics-card-mark" aria-hidden="true"><x-icon name="help" size="15" /></span>
        <div>
            <h2>Forecast Methodology</h2>
        </div>
        <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'predictive', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'forecast.methodology']) }}" aria-label="View Forecast Methodology details"><x-icon name="chevron-right" size="15" /></a>
    </header>

    <div class="analytics-card-body">
        <div class="analytics-method">
            <div>
                <span>Method</span>
                <strong>{{ $readiness['method'] ?? 'Weighted Moving Average' }}</strong>
            </div>
            <div>
                <span>History used</span>
                <strong>{{ $readiness['periods_required'] ?? 3 }} completed periods</strong>
            </div>
            <div>
                <span>Weights</span>
                <strong>{{ implode(' · ', $readiness['weights'] ?? [3, 2, 1]) }}</strong>
            </div>
        </div>
    </div>

    <details class="analytics-fold">
        <summary>How forecasts are calculated</summary>
        <div class="analytics-fold-body">
            <p class="analytics-method-summary">{{ $forecastBasis['summary'] }}</p>
            <ul class="analytics-method-list">
                @foreach($forecastBasis['details'] as $detail)
                    <li>{{ $detail }}</li>
                @endforeach
            </ul>
        </div>
    </details>
</section>
