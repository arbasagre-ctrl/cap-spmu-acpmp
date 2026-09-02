{{--
    Borrowers: who is borrowing SPMU assets.

    Divisions come from the canonical organisational structure. Research,
    Innovation and Collaboration is a peer division and is never folded into
    Academic or Administrative.
--}}

<section class="analytics-section{{ $groups['total'] === 0 ? ' is-empty' : '' }}">
    <h2>Borrower Groups</h2>
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

@forelse($units['columns'] as $column)
    <section class="analytics-section">
        <h2>{{ $column['label'] }} Units</h2>
        <div class="analytics-section-body">
            <div class="analytics-bars">
                @foreach($column['units'] as $row)
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

            <p class="analytics-reading">
                {{ $column['leader'] }} recorded the highest borrowing activity among
                {{ strtolower($column['label']) }} units ({{ $column['leader_count'] }}
                {{ $column['leader_count'] === 1 ? 'request' : 'requests' }}).
            </p>
        </div>
    </section>
@empty
    <section class="analytics-section is-empty">
        <h2>Units</h2>
        <div class="analytics-section-body">
            <p class="analytics-empty">No unit recorded a borrowing request during this period.</p>
        </div>
    </section>
@endforelse

<section class="analytics-section{{ $unitEquipment === null ? ' is-empty' : '' }}">
    <h2>Commonly Requested by Unit</h2>
    <div class="analytics-section-body">
        @if($unitEquipment === null)
            <p class="analytics-empty">
                Choose a unit in the filters above to see the equipment it requests most.
            </p>
        @elseif($unitEquipment['items'] === [])
            <p class="analytics-empty">{{ $unitEquipment['summary'] }}</p>
        @else
            <p class="analytics-metric-note">
                Counted as {{ strtolower($unitEquipment['metric']) }}. The quantity requested is shown alongside.
            </p>

            <div class="analytics-bars">
                @foreach($unitEquipment['items'] as $row)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">
                                {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
                                · {{ $row['quantity'] + 0 }} {{ $row['unit'] }}
                            </span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $unitEquipment['summary'] }}</p>
        @endif
    </div>
</section>
