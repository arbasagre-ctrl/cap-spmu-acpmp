@extends('layouts.app', ['title' => 'Analytics'])

@section('content')

@include('analytics.partials.analytics-styles')

<section class="page-heading">
    <div>
        <p class="eyebrow">Borrowing insights</p>
        <h1>Analytics</h1>
        <p>A plain-language summary of borrowing activity for the selected period. Detailed records and exports remain in Reports.</p>
    </div>
</section>

<section class="content-area">
    <div class="analytics-page">

        {{--
            Filters. Every control below re-runs the calculations on the
            server; nothing here is cosmetic.
        --}}
        <form class="analytics-filters" method="get" aria-label="Analytics filters">
            <label for="analytics-period">
                Reporting period
                <select id="analytics-period" name="academic_period" onchange="this.form.submit()">
                    <option value="week" @selected($periodSelection === 'week')>This week</option>
                    <option value="month" @selected($periodSelection === 'month')>This month</option>
                    <option value="semester" @selected($periodSelection === 'semester')>This semester</option>
                    <option value="academic_year" @selected($periodSelection === 'academic_year')>This academic year</option>
                </select>
            </label>

            <label for="analytics-group">
                Borrower group
                <select id="analytics-group" name="group" onchange="this.form.submit()">
                    <option value="all" @selected($selectedDivision === 'all')>All groups</option>
                    @foreach($divisions as $code => $label)
                        <option value="{{ $code }}" @selected($selectedDivision === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label for="analytics-unit">
                Unit
                <select id="analytics-unit" name="unit" onchange="this.form.submit()">
                    <option value="all" @selected($selectedUnit === 'all')>All units</option>
                    @foreach($selectableUnits as $unitName)
                        <option value="{{ $unitName }}" @selected($selectedUnit === $unitName)>{{ $unitName }}</option>
                    @endforeach
                </select>
            </label>

            <p class="analytics-period-note">
                Showing {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}.
                @if($selectedAcademicPeriod)
                    Academic period: {{ $selectedAcademicPeriod->term_label ?? $selectedAcademicPeriod->academic_year }}.
                @endif
            </p>
        </form>


        {{-- A. Overview --}}
        <article class="analytics-section">
            <h2>Overview</h2>

            <div class="analytics-figures">
                <div class="analytics-figure">
                    <strong>{{ $overview['total'] }}</strong>
                    <span>Requests filed</span>
                    <small>During the selected period</small>
                </div>

                <div class="analytics-figure">
                    <strong>{{ $overview['approved'] }}</strong>
                    <span>Approved requests</span>
                    <small>Reached an approved decision</small>
                </div>

                <div class="analytics-figure">
                    <strong>{{ $overview['on_custody'] }}</strong>
                    <span>Currently on custody</span>
                    <small>Released and not yet closed</small>
                </div>

                <div class="analytics-figure {{ $overview['needs_follow_up'] > 0 ? 'is-attention' : '' }}">
                    <strong>{{ $overview['needs_follow_up'] }}</strong>
                    <span>Need return follow-up</span>
                    <small>Past the expected return date</small>
                </div>
            </div>

            <p class="analytics-reading">{{ $overview['summary'] }}</p>
        </article>


        {{-- B. Borrower distribution --}}
        <article class="analytics-section {{ $groups['groups']->isEmpty() ? 'is-empty' : '' }}">
            <h2>Borrower Distribution</h2>

            @if($groups['groups']->isEmpty())
                <p class="analytics-empty">No borrowing requests were filed during this period, so there is nothing to compare yet.</p>
            @else
                <div class="analytics-section-body">
                    <div class="analytics-bars {{ $groups['groups']->count() === 1 ? 'is-single' : '' }}">
                        @foreach($groups['groups'] as $group)
                            <div class="analytics-bar-row is-{{ strtolower(explode('_', (string) $group['code'])[0] ?: 'other') }}">
                                <div class="analytics-bar-head">
                                    <span class="analytics-bar-name">{{ $group['label'] }}</span>
                                    <span class="analytics-bar-value">
                                        {{ $group['count'] }} {{ $group['count'] === 1 ? 'request' : 'requests' }}
                                        &middot; {{ $group['percentage'] }}%
                                    </span>
                                </div>

                                <div
                                    class="analytics-bar-track"
                                    role="img"
                                    aria-label="{{ $group['label'] }}: {{ $group['percentage'] }} percent of requests"
                                >
                                    <div class="analytics-bar-fill" style="width: {{ $group['percentage'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <p class="analytics-reading">{{ $groups['summary'] }}</p>
            @endif
        </article>


        {{-- C. Academic & administrative units --}}
        <article class="analytics-section {{ $units['columns']->isEmpty() ? 'is-empty' : '' }}">
            <h2>Academic &amp; Administrative Units</h2>

            @if($units['columns']->isEmpty())
                <p class="analytics-empty">No unit-level borrowing activity was recorded during this period.</p>
            @else
                <div class="analytics-split">
                    @foreach($units['columns'] as $column)
                        <section>
                            <h3>{{ $column['label'] }} units</h3>

                            <div class="analytics-bars {{ count($column['units']) === 1 ? 'is-single' : '' }}">
                                @foreach($column['units'] as $unitRow)
                                    <div class="analytics-bar-row is-{{ strtolower(explode('_', $column['code'])[0]) }}">
                                        <div class="analytics-bar-head">
                                            <span class="analytics-bar-name">{{ $unitRow['name'] }}</span>
                                            <span class="analytics-bar-value">
                                                {{ $unitRow['count'] }} {{ $unitRow['count'] === 1 ? 'request' : 'requests' }}
                                            </span>
                                        </div>

                                        <div
                                            class="analytics-bar-track"
                                            role="img"
                                            aria-label="{{ $unitRow['name'] }}: {{ $unitRow['count'] }} requests"
                                        >
                                            <div class="analytics-bar-fill" style="width: {{ $unitRow['share'] }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>

                @if($units['summary'])
                    <p class="analytics-reading">{{ implode(' ', $units['summary']) }}</p>
                @endif
            @endif
        </article>


        {{-- D. Most borrowed assets --}}
        <article class="analytics-section {{ $equipment['items'] === [] ? 'is-empty' : '' }}">
            <h2>Most Borrowed Assets</h2>

            @if($equipment['items'] === [])
                <p class="analytics-empty">No items were physically released during this period, so utilisation cannot be ranked yet.</p>
            @else
                <div class="analytics-section-body">
                    <div class="analytics-bars {{ count($equipment['items']) === 1 ? 'is-single' : '' }}">
                        @foreach($equipment['items'] as $item)
                            <div class="analytics-bar-row">
                                <div class="analytics-bar-head">
                                    <span class="analytics-bar-name">{{ $item['name'] }}</span>
                                    <span class="analytics-bar-value">
                                        {{ $item['released'] }} {{ $equipment['metric'] }}
                                    </span>
                                </div>

                                <div
                                    class="analytics-bar-track"
                                    role="img"
                                    aria-label="{{ $item['name'] }}: {{ $item['released'] }} units released"
                                >
                                    <div class="analytics-bar-fill" style="width: {{ $item['share'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <p class="analytics-reading">
                    {{ $equipment['summary'] }}
                    Ranking counts the quantity physically released, not the number of requests.
                </p>
            @endif
        </article>


        {{-- E. Borrowing trends --}}
        <article class="analytics-section {{ $overview['total'] === 0 ? 'is-empty' : '' }}">
            <h2>Borrowing Trends</h2>

            @if($overview['total'] === 0)
                <p class="analytics-empty">No borrowing requests were filed during this period.</p>
            @else
                <div class="analytics-section-body">
                    <div class="analytics-bars {{ count($trend['points']) === 1 ? 'is-single' : '' }}">
                        @foreach($trend['points'] as $point)
                            <div class="analytics-bar-row">
                                <div class="analytics-bar-head">
                                    <span class="analytics-bar-name">{{ $point['label'] }}</span>
                                    <span class="analytics-bar-value">
                                        {{ $point['count'] }} {{ $point['count'] === 1 ? 'request' : 'requests' }}
                                    </span>
                                </div>

                                <div
                                    class="analytics-bar-track"
                                    role="img"
                                    aria-label="{{ $point['label'] }}: {{ $point['count'] }} requests"
                                >
                                    <div class="analytics-bar-fill" style="width: {{ $point['share'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <p class="analytics-reading">{{ $trend['summary'] }}</p>
            @endif
        </article>


        {{-- F. Returns & accountability --}}
        <article class="analytics-section {{ $returns['has_data'] ? '' : 'is-empty' }}">
            <h2>Returns &amp; Accountability</h2>

            @unless($returns['has_data'])
                <p class="analytics-empty">No completed returns are available for this period yet.</p>
            @else
                <div class="analytics-figures">
                    <div class="analytics-figure">
                        <strong>{{ $returns['on_time'] }}</strong>
                        <span>Returned on time</span>
                        <small>Closed on or before the due date</small>
                    </div>

                    <div class="analytics-figure {{ $returns['late'] > 0 ? 'is-attention' : '' }}">
                        <strong>{{ $returns['late'] }}</strong>
                        <span>Returned late</span>
                        <small>Closed after the due date</small>
                    </div>

                    <div class="analytics-figure {{ $returns['overdue'] > 0 ? 'is-attention' : '' }}">
                        <strong>{{ $returns['overdue'] }}</strong>
                        <span>Currently overdue</span>
                        <small>Still out past the due date</small>
                    </div>

                    <div class="analytics-figure {{ $returns['open_cases'] > 0 ? 'is-attention' : '' }}">
                        <strong>{{ $returns['open_cases'] }}</strong>
                        <span>Open accountability</span>
                        <small>Unresolved cases or billings</small>
                    </div>
                </div>

                <p class="analytics-reading">{{ $returns['summary'] }}</p>
            @endunless
        </article>


        {{-- G. Inventory status --}}
        <article class="analytics-section {{ $inventory['item_count'] === 0 ? 'is-empty' : '' }}">
            <h2>Inventory Status</h2>

            @if($inventory['item_count'] === 0)
                <p class="analytics-empty">No active inventory items are recorded yet.</p>
            @else
                <div class="analytics-figures is-secondary">
                    <div class="analytics-figure">
                        <strong>{{ $inventory['totals']['available'] }}</strong>
                        <span>Available</span>
                        <small>Ready to be requested</small>
                    </div>

                    <div class="analytics-figure">
                        <strong>{{ $inventory['totals']['allocated'] }}</strong>
                        <span>Allocated</span>
                        <small>Reserved for approved requests</small>
                    </div>

                    <div class="analytics-figure">
                        <strong>{{ $inventory['totals']['on_custody'] }}</strong>
                        <span>On custody</span>
                        <small>Physically with borrowers</small>
                    </div>

                    <div class="analytics-figure {{ ($inventory['totals']['maintenance'] + $inventory['totals']['problem']) > 0 ? 'is-attention' : '' }}">
                        <strong>{{ $inventory['totals']['maintenance'] + $inventory['totals']['problem'] }}</strong>
                        <span>Needs attention</span>
                        <small>Maintenance, loss, or incident</small>
                    </div>
                </div>

                <p class="analytics-reading">
                    {{ $inventory['summary'] }}
                    Item-level detail stays in Inventory Overview.
                </p>
            @endif
        </article>


        {{-- Key insights --}}
        <article class="analytics-section {{ $insights === [] ? 'is-empty' : '' }}">
            <h2>Key Insights</h2>

            @if($insights === [])
                <p class="analytics-empty">There is not enough activity in this period to draw any conclusions yet. Insights appear once requests are filed.</p>
            @else
                <div class="analytics-insights">
                    @foreach($insights as $index => $insight)
                        <p class="analytics-insight">
                            <span class="analytics-insight-mark" aria-hidden="true">{{ $index + 1 }}</span>
                            <span>{{ $insight }}</span>
                        </p>
                    @endforeach
                </div>
            @endif
        </article>

    </div>
</section>

@endsection
