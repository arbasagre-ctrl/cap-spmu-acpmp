@php
    /*
    | Inventory Health: the present physical position of the catalogue.
    |
    | Everything on this tab is current-state. It deliberately does not follow
    | the reporting period, because stock is where it is today regardless of
    | which month is being reviewed; the two exceptions are Utilization and
    | Stock Coverage, which need a usage window and say so.
    |
    | Every figure comes from InventoryService through AnalyticsService, the
    | same authoritative availability rule the Inventory module and Reports
    | use. Nothing is computed from records here.
    |
    | WHY THERE IS NO DONUT
    | ---------------------
    | A donut states parts of one whole, so it is only honest when the parts do
    | not overlap. These do:
    |
    |   current_available = serviceable_total - borrowed - laundry - incident
    |
    | Reserved / Allocated is NOT subtracted, so every reserved unit is already
    | counted inside Available. Drawing both as slices would count those units
    | twice. Maintenance sits outside the ring for the opposite reason: a
    | damaged item has no serviceable total at all.
    |
    | What does partition exactly is Available + On Custody + In Laundry +
    | Incident, which sums to serviceable stock by construction. That is what
    | the composition bar below shows, with Reserved stated underneath as the
    | subset of Available that it actually is.
    */
    use App\Support\AnalyticsDetailLink;

    $totals = $inventory['totals'];
    $serviceable = (float) ($totals['serviceable'] ?? 0);

    /*
     | Maintenance and open incidents are the states the service's own summary
     | sentence already calls unavailable. Laundry is a normal step in the linen
     | cycle, not a fault, so it is reported separately and never added here.
     */
    $attention = (float) $totals['maintenance'] + (float) $totals['problem'];

    $thresholdPercent = (int) round(($inventory['threshold'] ?? 0.25) * 100);

    $num = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');

    /* The four states that partition serviceable stock, in operational order. */
    $composition = [
        ['key' => 'available', 'label' => 'Available', 'value' => (float) $totals['available']],
        ['key' => 'custody', 'label' => 'On Custody', 'value' => (float) $totals['on_custody']],
        ['key' => 'laundry', 'label' => 'In Laundry', 'value' => (float) $totals['laundry']],
        ['key' => 'incident', 'label' => 'Incident / Unavailable', 'value' => (float) $totals['incident']],
    ];

    $composition = array_map(function (array $row) use ($serviceable): array {
        /* Guarded: an empty catalogue must never produce a percentage. */
        $row['percent'] = $serviceable > 0 ? round($row['value'] / $serviceable * 100, 1) : 0;

        return $row;
    }, $composition);

    /*
     | Health-first ordering is already applied in the service. Six rows keeps
     | this card close to the natural height of the composition beside it;
     | eight left the shorter card with a long blank tail.
     */
    $availabilityRows = array_slice($inventory['availability'], 0, 6);

    $operational = [
        ['key' => 'laundry', 'icon' => 'linen', 'label' => 'In Laundry', 'value' => (float) $totals['laundry'], 'note' => 'Units in the laundry cycle'],
        ['key' => 'maintenance', 'icon' => 'settings', 'label' => 'Damaged / Maintenance', 'value' => (float) $totals['maintenance'], 'note' => 'Units held for repair'],
        ['key' => 'incident', 'icon' => 'warning', 'label' => 'Incident / Unavailable', 'value' => (float) $totals['problem'], 'note' => 'Units held by an open case'],
    ];

    $coverageRows = array_slice($coverage['items'], 0, 5);
@endphp

{{-- Current position ---------------------------------------------------- --}}
<div class="analytics-kpis">
    {{--
        Current-state readings with no record list of their own, so none of the
        four pretends to be clickable. The rows further down carry the links.
    --}}
    <a
        class="analytics-kpi-card tone-available"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.available']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
        <span class="analytics-kpi-card-label">Available Units</span>
        <strong class="analytics-kpi-card-value">{{ $num((float) $totals['available']) }}</strong>
        <span class="analytics-kpi-card-note">Units currently ready for release</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-quantity"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.reserved']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="bookmark" size="19" /></span>
        <span class="analytics-kpi-card-label">Reserved / Allocated</span>
        <strong class="analytics-kpi-card-value">{{ $num((float) $totals['allocated']) }}</strong>
        <span class="analytics-kpi-card-note">Allocated to upcoming requests, inside Available</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-requests"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.custody']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="custody" size="19" /></span>
        <span class="analytics-kpi-card-label">On Custody</span>
        <strong class="analytics-kpi-card-value">{{ $num((float) $totals['on_custody']) }}</strong>
        <span class="analytics-kpi-card-note">Units currently released to borrowers</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>

    <a
        class="analytics-kpi-card tone-attention"
        href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.attention']) }}"
    >
        <span class="analytics-kpi-card-icon" aria-hidden="true"><x-icon name="warning" size="19" /></span>
        <span class="analytics-kpi-card-label">Attention Needed</span>
        <strong class="analytics-kpi-card-value">{{ $num($attention) }}</strong>
        <span class="analytics-kpi-card-note">Maintenance or incident follow-up, not laundry</span>
            <x-icon name="chevron-right" size="16" class="analytics-kpi-card-arrow" />
    </a>
</div>

{{-- Availability and composition ---------------------------------------- --}}
<div class="analytics-inventory-main">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.availability']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="inventory" size="15" /></span>
            <div>
                <h2>Inventory Availability</h2>
                <p>Usable share of each item's serviceable stock, tightest first.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.availability']) }}" aria-label="View Inventory Availability details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($availabilityRows === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="inventory" size="19" /></span>
                    No borrowable equipment with serviceable stock is recorded yet.
                </p>
            @else
                <ul class="analytics-avail">
                    @foreach($availabilityRows as $row)
                        <li>
                            <a
                                href="{{ AnalyticsDetailLink::to('equipment', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Available', $num($row['available']).' / '.$num($row['stock'])],
                                    ['Availability', $row['share'].'%'],
                                    ['Status', $row['status']],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}: {{ $num($row['available']) }} of {{ $num($row['stock']) }} units available, {{ $row['share'] }} percent, {{ $row['status'] }}"
                            >
                                <span class="analytics-avail-name">{{ $row['name'] }}</span>

                                {{--
                                    One bar, one reading: the fill is the usable
                                    share of serviceable stock and the rail
                                    behind it is simply what is not usable. The
                                    earlier four-state split put a second strong
                                    colour beside the first, which read as a
                                    competing measure rather than a remainder.

                                    Status and threshold both come from the
                                    service; nothing is decided here.
                                --}}
                                <span class="analytics-avail-meter">
                                    <span class="analytics-avail-bar" aria-hidden="true">
                                        <span
                                            class="analytics-avail-fill @if($row['low']) is-low @endif"
                                            style="width: {{ $row['share'] }}%"
                                        ></span>
                                    </span>

                                    <span class="analytics-avail-figure">
                                        <span>{{ $num($row['available']) }} / {{ $num($row['stock']) }}</span>
                                        <strong class="{{ $row['low'] ? 'is-low' : '' }}">{{ $row['share'] }}%</strong>
                                        <small class="analytics-avail-tag @if($row['low']) is-low @endif">{{ $row['status'] }}</small>
                                    </span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <footer class="analytics-card-foot">
            Serviceable stock only. An item is Limited at or below {{ $thresholdPercent }}% usable.
        </footer>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.distribution']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="box" size="15" /></span>
            <div>
                <h2>Current Inventory Distribution</h2>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.distribution']) }}" aria-label="View Current Inventory Distribution details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        @if($serviceable <= 0)
            <div class="analytics-card-body">
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    No serviceable inventory is recorded yet.
                </p>
            </div>
        @else
            <div class="analytics-card-body">
                <p class="analytics-dist-total">
                    <strong>{{ $num($serviceable) }}</strong>
                    <span>serviceable units across {{ $inventory['item_count'] }} active items</span>
                </p>

                <span class="analytics-dist-bar" aria-hidden="true">
                    @foreach($composition as $slice)
                        @if($slice['value'] > 0)
                            <span
                                class="analytics-dist-seg is-{{ $slice['key'] }}"
                                style="width: {{ $slice['percent'] }}%"
                                tabindex="0"
                                role="img"
                                data-chart-tip
                                data-tip-title="{{ $slice['label'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Units', $num($slice['value'])],
                                    ['Share of serviceable stock', $slice['percent'].'%'],
                                ]) }}"
                                aria-label="{{ $slice['label'] }}: {{ $num($slice['value']) }} units, {{ $slice['percent'] }} percent of serviceable stock"
                            ></span>
                        @endif
                    @endforeach
                </span>

                <ul class="analytics-dist-legend">
                    @foreach($composition as $slice)
                        <li>
                            <span class="analytics-dist-key is-{{ $slice['key'] }}" aria-hidden="true"></span>
                            <span class="analytics-dist-name">{{ $slice['label'] }}</span>
                            <span class="analytics-dist-figure">{{ $num($slice['value']) }} <small>· {{ $slice['percent'] }}%</small></span>
                        </li>
                    @endforeach
                </ul>

                {{--
                    Stated rather than drawn: these two do not belong in the bar
                    above, and saying why is more use than leaving them out.
                --}}
                <dl class="analytics-dist-aside">
                    <div>
                        <dt>Of which reserved</dt>
                        <dd>{{ $num((float) $totals['allocated']) }} units allocated to upcoming requests, counted inside Available until they are released.</dd>
                    </div>
                    @if($totals['maintenance'] > 0)
                        <div>
                            <dt>Outside serviceable stock</dt>
                            <dd>{{ $num((float) $totals['maintenance']) }} units are damaged or under maintenance and carry no serviceable total.</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif
    </section>
</div>

{{-- Watchlist and usage -------------------------------------------------- --}}
<div class="analytics-inventory-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.low-availability']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="warning" size="15" /></span>
            <div>
                <h2>Low Availability Watch</h2>
            </div>
            @if($lowAvailability['count'] > 0)
                <span class="analytics-count-pill">{{ $lowAvailability['count'] }}</span>
            @endif
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.low-availability']) }}" aria-label="View Low Availability Watch details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($lowAvailability['items'] === [])
                <p class="analytics-blank is-positive">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="check-circle" size="19" /></span>
                    No equipment currently requires availability attention.
                </p>
            @else
                <ul class="analytics-watch">
                    @foreach($lowAvailability['items'] as $row)
                        <li>
                            <a
                                href="{{ AnalyticsDetailLink::to('equipment', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
                                aria-label="View details for {{ $row['name'] }}"
                            >
                                <span class="analytics-watch-main">
                                    <span class="analytics-watch-name">{{ $row['name'] }}</span>
                                    <span class="analytics-watch-sub">{{ $num($row['available']) }} of {{ $num($row['stock']) }} units available</span>
                                </span>
                                <span class="analytics-watch-figure">
                                    <strong>{{ $row['share'] }}%</strong>
                                    <small class="analytics-tag {{ $row['status'] === 'Unavailable' ? 'is-critical' : 'is-attention' }}">{{ $row['status'] }}</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.utilization']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="custody" size="15" /></span>
            <div>
                <h2>Utilization</h2>
                <p>Quantity of equipment physically released during this period.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.utilization']) }}" aria-label="View Utilization details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($released['items'] === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="box" size="19" /></span>
                    <span>
                        <strong>No equipment was physically released during this period.</strong>
                        Utilization becomes available once release activity occurs within the selected period.
                    </span>
                </p>
            @else
                <ol class="analytics-rank">
                    @foreach(array_slice($released['items'], 0, 5) as $index => $row)
                        <li>
                            <a
                                class="analytics-rank-row"
                                href="{{ AnalyticsDetailLink::to('equipment', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
                                data-chart-tip
                                data-tip-title="{{ $row['name'] }}"
                                data-tip-rows="{{ json_encode([
                                    ['Physically released', $num($row['released'])],
                                ]) }}"
                                aria-label="View details for {{ $row['name'] }}: {{ $num($row['released']) }} released"
                            >
                                <span class="analytics-rank-no">{{ $index + 1 }}</span>
                                <span class="analytics-rank-main">
                                    <span class="analytics-rank-name">{{ $row['name'] }}</span>
                                    <span class="analytics-rank-track">
                                        <span class="analytics-rank-fill is-released" style="width: {{ max(3, $row['share']) }}%"></span>
                                    </span>
                                </span>
                                <span class="analytics-rank-value">
                                    {{ $num($row['released']) }}
                                    <small>released</small>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>

{{-- Secondary states and coverage ---------------------------------------- --}}
<div class="analytics-inventory-pair">
    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.operational']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="settings" size="15" /></span>
            <div>
                <h2>Operational Inventory Status</h2>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.operational']) }}" aria-label="View Operational Inventory Status details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            <div class="analytics-tiles">
                @foreach($operational as $tile)
                    <div class="analytics-tile is-{{ $tile['key'] }}{{ $tile['value'] > 0 ? ' is-flagged' : '' }}">
                        <span class="analytics-tile-icon" aria-hidden="true"><x-icon :name="$tile['icon']" size="15" /></span>
                        <span class="analytics-tile-label">{{ $tile['label'] }}</span>
                        <strong class="analytics-tile-value">{{ $num($tile['value']) }}</strong>
                        <span class="analytics-tile-note">{{ $tile['note'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="analytics-insight-strip">
            <x-icon name="information" size="14" aria-hidden="true" />
            <span>{{ $inventory['summary'] }}</span>
        </p>
    </section>

    <section
        data-card-detail="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.coverage']) }}"
        class="analytics-card">
        <header class="analytics-card-head">
            <span class="analytics-card-mark" aria-hidden="true"><x-icon name="clock" size="15" /></span>
            <div>
                <h2>Stock Coverage &amp; Risk</h2>
                <p>Estimated coverage from current stock and release history in this period.</p>
            </div>
            <a class="analytics-card-open" href="{{ App\Support\AnalyticsDetailLink::to('card', 'inventory', $periodSelection, $selectedDivision === 'all' ? null : $selectedDivision, $selectedUnit === 'all' ? null : $selectedUnit, ['for' => 'inventory.coverage']) }}" aria-label="View Stock Coverage and Risk details"><x-icon name="chevron-right" size="15" /></a>
        </header>

        <div class="analytics-card-body">
            @if($coverageRows === [])
                <p class="analytics-blank">
                    <span class="analytics-blank-mark" aria-hidden="true"><x-icon name="clock" size="19" /></span>
                    <span>
                        <strong>Insufficient release history.</strong>
                        {{ $coverage['summary'] }}
                    </span>
                </p>
            @else
                <ul class="analytics-coverage">
                    @foreach($coverageRows as $row)
                        <li>
                            <a
                                href="{{ AnalyticsDetailLink::to('coverage', 'inventory', $periodSelection, null, null, ['item' => $row['item_id']]) }}"
                                aria-label="View coverage details for {{ $row['name'] }}"
                            >
                                <span class="analytics-coverage-main">
                                    <span class="analytics-coverage-name">{{ $row['name'] }}</span>
                                    <span class="analytics-coverage-sub">
                                        {{ $num($row['available']) }} available
                                        @if($row['sufficient'])
                                            · {{ $row['per_day'] }} per day
                                        @endif
                                    </span>
                                </span>

                                <span class="analytics-coverage-figure">
                                    {{--
                                        Rate, coverage and risk stand or fall
                                        together: a number beside "insufficient
                                        history" would invite the reader to do
                                        the division themselves.
                                    --}}
                                    @if(! $row['sufficient'])
                                        <small class="analytics-tag">Insufficient history</small>
                                    @elseif($row['risk'] === null)
                                        <small class="analytics-tag">No recent consumption</small>
                                    @else
                                        <strong>{{ $row['days_cover'] }}<small>d</small></strong>
                                        <small class="analytics-tag {{ $row['risk'] === 'High' ? 'is-critical' : ($row['risk'] === 'Medium' ? 'is-attention' : 'is-positive') }}">
                                            {{ $row['risk'] }}
                                        </small>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($coverageRows !== [])
            <footer class="analytics-card-foot">
                An estimate, not a probability. High is under {{ $coverage['thresholds']['high'] }} days,
                Medium up to {{ $coverage['thresholds']['medium'] }}.
            </footer>
        @endif
    </section>
</div>
