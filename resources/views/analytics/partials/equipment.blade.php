{{--
    Equipment: what is requested, what actually went out, and what is free.

    Requested and released are deliberately separate rankings. A request that
    was never approved still expresses demand, but it is not utilisation.
--}}

<section class="analytics-section{{ $requested['items'] === [] ? ' is-empty' : '' }}">
    <h2>Most Requested Equipment</h2>
    <div class="analytics-section-body">
        @if($requested['items'] === [])
            <p class="analytics-empty">No equipment was requested during this period.</p>
        @else
            <p class="analytics-metric-note">Counted as {{ strtolower($requested['metric']) }}.</p>

            <div class="analytics-bars">
                @foreach($requested['items'] as $row)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">
                                {{ $row['requests'] }} {{ $row['requests'] === 1 ? 'request' : 'requests' }}
                                &middot; {{ $row['quantity'] + 0 }} {{ $row['unit'] }}
                            </span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $requested['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $released['items'] === [] ? ' is-empty' : '' }}">
    <h2>Actually Released</h2>
    <div class="analytics-section-body">
        @if($released['items'] === [])
            <p class="analytics-empty">No equipment was physically released during this period.</p>
        @else
            <p class="analytics-metric-note">
                Counted as {{ strtolower($released['metric']) }} from physical release records, not from approved requests.
            </p>

            <div class="analytics-bars">
                @foreach($released['items'] as $row)
                    <div class="analytics-bar-row">
                        <div class="analytics-bar-head">
                            <span class="analytics-bar-name">{{ $row['name'] }}</span>
                            <span class="analytics-bar-value">{{ $row['released'] + 0 }} {{ $row['unit'] }}</span>
                        </div>
                        <div class="analytics-bar-track">
                            <span class="analytics-bar-fill" style="width: {{ $row['share'] }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="analytics-reading">{{ $released['summary'] }}</p>
        @endif
    </div>
</section>

<section class="analytics-section{{ $lowAvailability['items'] === [] ? ' is-empty' : '' }}">
    <h2>Availability</h2>
    <div class="analytics-section-body">
        @if($lowAvailability['items'] === [])
            <p class="analytics-empty">No equipment currently requires availability attention.</p>
        @else
            <div class="analytics-table-scroll">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th scope="col">Equipment</th>
                            <th scope="col" class="is-numeric">Available</th>
                            <th scope="col" class="is-numeric">Stock</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lowAvailability['items'] as $row)
                            <tr>
                                <th scope="row">
                                    {{ $row['name'] }}
                                    @if($row['laundry_required'] && $row['laundry'] > 0)
                                        <small>{{ $row['laundry'] + 0 }} under Laundry Operations</small>
                                    @endif
                                </th>
                                <td class="is-numeric">{{ $row['available'] + 0 }}</td>
                                <td class="is-numeric">{{ $row['stock'] + 0 }}</td>
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

            <p class="analytics-reading">{{ $lowAvailability['summary'] }}</p>
        @endif
    </div>
</section>
