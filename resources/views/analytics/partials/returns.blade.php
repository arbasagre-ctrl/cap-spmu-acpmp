{{--
    Returns: are borrowings being closed properly, and what needs action.

    Custody is authoritative once it exists, so every figure here comes from
    the custody and accountability records rather than request status.
--}}

<div class="analytics-kpis">
    <article class="analytics-kpi">
        <span class="analytics-kpi-label">Returned On Time</span>
        <strong>{{ $returns['on_time'] }}</strong>
        <small>Closed on or before the expected return date</small>
    </article>

    <article class="analytics-kpi{{ $returns['late'] > 0 ? ' is-attention' : '' }}">
        <span class="analytics-kpi-label">Returned Late</span>
        <strong>{{ $returns['late'] }}</strong>
        <small>Closed after the expected return date</small>
    </article>

    <article class="analytics-kpi{{ $returns['overdue'] > 0 ? ' is-attention' : '' }}">
        <span class="analytics-kpi-label">Currently Overdue</span>
        <strong>{{ $returns['overdue'] }}</strong>
        <small>Still out past the return date</small>
    </article>

    <article class="analytics-kpi{{ $returns['open_cases'] > 0 ? ' is-attention' : '' }}">
        <span class="analytics-kpi-label">Open Accountability</span>
        <strong>{{ $returns['open_cases'] }}</strong>
        <small>Unresolved incident or billing case</small>
    </article>
</div>

<section class="analytics-section{{ $returns['has_data'] ? '' : ' is-empty' }}">
    <h2>Return Performance</h2>
    <div class="analytics-section-body">
        @if(! $returns['has_data'])
            <p class="analytics-empty">No completed returns are available for this period yet.</p>
        @else
            <p class="analytics-reading">{{ $returns['summary'] }}</p>

            @if($returns['overdue'] > 0 || $returns['open_cases'] > 0)
                <p class="analytics-action">
                    <a href="{{ route('custody.index') }}">View returns needing attention</a>
                </p>
            @endif
        @endif
    </div>
</section>

<section class="analytics-section{{ $units['columns']->isEmpty() ? ' is-empty' : '' }}">
    <h2>Borrowing Activity by Unit</h2>
    <div class="analytics-section-body">
        @if($units['columns']->isEmpty())
            <p class="analytics-empty">No unit recorded a borrowing request during this period.</p>
        @else
            <p class="analytics-metric-note">
                Requests filed per unit during this period. Individual borrowers are not ranked.
            </p>

            <div class="analytics-split">
                @foreach($units['columns'] as $column)
                    <section>
                        <h3>{{ $column['label'] }}</h3>
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
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</section>
