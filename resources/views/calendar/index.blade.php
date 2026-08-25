@extends('layouts.app', ['title' => 'Borrowing Calendar'])
@section('content')
@php
    $isBorrower = $workspace === 'BORROWER';
@endphp
<section class="page-heading calendar-page-heading">
    <div>
        <p class="eyebrow">Schedule overview</p>
        <h1>Borrowing Calendar</h1>
    </div>
    <div
        class="calendar-month-summary"
        aria-label="{{ $month->format('F Y') }} borrowing summary"
        @if($isBorrower) data-calendar-status-filters @endif
    >
        @if($isBorrower)
            <button
                type="button"
                class="calendar-summary-filter calendar-summary-active"
                data-calendar-status-filter="active"
                aria-pressed="false"
            >
                <strong>{{ $summary['active'] }}</strong>
                <span>Active</span>
            </button>
            <button
                type="button"
                class="calendar-summary-filter calendar-summary-due-soon"
                data-calendar-status-filter="due-soon"
                aria-pressed="false"
            >
                <strong>{{ $summary['due_soon'] }}</strong>
                <span>Due Soon</span>
            </button>
            <button
                type="button"
                class="calendar-summary-filter calendar-summary-overdue"
                data-calendar-status-filter="overdue"
                aria-pressed="false"
            >
                <strong>{{ $summary['overdue'] }}</strong>
                <span>Overdue</span>
            </button>
            <button
                type="button"
                class="calendar-summary-filter calendar-summary-returned"
                data-calendar-status-filter="returned"
                aria-pressed="false"
            >
                <strong>{{ $summary['returned'] }}</strong>
                <span>Returned</span>
            </button>
            <button
                type="button"
                class="calendar-summary-clear is-selected"
                data-calendar-status-filter=""
                aria-pressed="true"
            >
                All
            </button>
            <span class="visually-hidden" data-calendar-filter-live aria-live="polite">
                Showing all calendar records.
            </span>
        @else
            <span><strong>{{ $summary['active'] }}</strong> Active</span>
            <span><strong>{{ $summary['due_soon'] }}</strong> Due Soon</span>
            <span><strong>{{ $summary['overdue'] }}</strong> Overdue</span>
            <span><strong>{{ $summary['returned'] }}</strong> Returned</span>
        @endif
    </div>
</section>

<section class="content-area borrowing-calendar" data-borrowing-calendar>
    <div class="calendar-toolbar">
        <nav class="calendar-navigation" aria-label="Calendar month navigation">
            <a class="calendar-nav-button ui-pressable" href="{{ route('calendar.index', ['month' => $previousMonth->format('Y-m')]) }}" aria-label="Previous month" title="Previous month"><x-icon name="chevron-right" class="icon-reverse" /></a>
            <a class="button secondary small ui-pressable" href="{{ route('calendar.index', ['month' => now(config('app.timezone'))->format('Y-m')]) }}">Today</a>
            <a class="calendar-nav-button ui-pressable" href="{{ route('calendar.index', ['month' => $nextMonth->format('Y-m')]) }}" aria-label="Next month" title="Next month"><x-icon name="chevron-right" /></a>
        </nav>
        <h2 class="calendar-month-title">{{ $month->format('F Y') }}</h2>
        <div class="calendar-view-toggle" role="group" aria-label="Calendar view">
            <button type="button" class="calendar-view-control ui-pressable active" aria-pressed="true" data-calendar-view-button="month">Month</button>
            <button type="button" class="calendar-view-control ui-pressable" aria-pressed="false" data-calendar-view-button="list">List</button>
        </div>
    </div>

    <div class="calendar-legend" aria-label="Calendar legend">
        <span><i class="legend-mark scheduled" aria-hidden="true"></i>Borrowing starts</span>
        <span><i class="legend-mark due" aria-hidden="true"></i>Return due</span>
        <span><i class="legend-mark overdue" aria-hidden="true"></i>Overdue</span>
        <span><i class="legend-mark returned" aria-hidden="true"></i>Returned</span>
        @if($isBorrower)<span><i class="legend-mark own" aria-hidden="true"></i>Your record</span>@endif
    </div>

    <div data-calendar-view-panel="month">
        <div class="calendar-month-scroll">
            <div class="calendar-month" role="grid" aria-label="{{ $month->format('F Y') }} borrowing calendar">
                <div class="calendar-weekdays" role="row">
                    @foreach(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday)
                        <div role="columnheader"><span class="weekday-full">{{ $weekday }}</span><span class="weekday-short" aria-hidden="true">{{ substr($weekday, 0, 3) }}</span></div>
                    @endforeach
                </div>
                @foreach($calendarWeeks as $week)
                    <div class="calendar-week" role="row">
                        @foreach($week as $day)
                            <div class="calendar-day {{ !$day['in_month'] ? 'outside-month' : '' }} {{ $day['is_today'] ? 'is-today' : '' }}" role="gridcell" aria-label="{{ $day['date']->format('l, d F Y') }}">
                                <div class="calendar-day-heading">
                                    <time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->day }}</time>
                                    @if($day['is_today'])<span>Today</span>@endif
                                </div>
                                <div class="calendar-day-events" @if($isBorrower) data-calendar-day-events @endif>
                                    @if($isBorrower)
                                        @foreach($day['occurrences'] as $occurrence)
                                            <div
                                                class="calendar-day-occurrence"
                                                data-calendar-occurrence
                                                @if($loop->index >= 2) hidden @endif
                                            >
                                                <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" :filterable="true" />
                                            </div>
                                        @endforeach
                                    @else
                                        @foreach($day['occurrences']->take(2) as $occurrence)
                                            <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" />
                                        @endforeach
                                    @endif
                                    @if($day['occurrences']->count() > 2)
                                        <button class="calendar-more ui-pressable" type="button" data-calendar-day="{{ $day['date']->toDateString() }}">+{{ $day['occurrences']->count() - 2 }} more</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        @if($calendarEvents->isEmpty())
            <div class="calendar-empty-month" role="status" @if($isBorrower) data-calendar-default-empty @endif><strong>No borrowing activity this month.</strong><span>Use the month controls to review another period.</span></div>
        @endif
    </div>

    <div class="calendar-list-view" data-calendar-view-panel="list" hidden>
        @forelse($calendarEvents as $event)
            <x-calendar-event :event="$event" variant="list" :filterable="$isBorrower" />
        @empty
            <div class="empty-state" @if($isBorrower) data-calendar-default-empty @endif><strong>No borrowing activity this month.</strong><span>Use the month controls to review another period.</span></div>
        @endforelse
    </div>

    @if($isBorrower)
        <div class="calendar-filter-empty" role="status" data-calendar-filter-empty hidden>
            <strong>No matching records this month.</strong>
            <span>Select another status or choose All.</span>
        </div>
    @endif

    @foreach($calendarEvents as $event)
        <template id="calendar-detail-{{ $event['key'] }}">
            <article class="calendar-drawer-detail">
                <div class="calendar-drawer-reference">
                    <div><p class="eyebrow">Reservation / Request</p><h3>{{ $event['reference'] }}</h3></div>
                    <x-status-badge :status="$event['status']" />
                </div>
                <dl class="calendar-drawer-summary">
                    <div><dt>Borrowing period</dt><dd>{{ $event['start_at']->format('d M Y, g:i A') }} <span aria-hidden="true">&rarr;</span> {{ $event['due_at']->format('d M Y, g:i A') }}</dd></div>
                    <div><dt>Return deadline</dt><dd>{{ $event['due_at']->format('d M Y, g:i A') }}</dd></div>
                    @if($event['purpose'])<div><dt>Purpose / Event</dt><dd>{{ $event['purpose'] }}</dd></div>@endif
                    @if($event['office'])<div><dt>Office / Department</dt><dd>{{ $event['office'] }}</dd></div>@endif
                </dl>
                <div class="calendar-drawer-action {{ $event['is_overdue'] || $event['status'] === 'OBLIGATION_OPEN' ? 'warning' : '' }}">
                    <strong>{{ $event['own_record'] && str_starts_with($event['next_action'], 'Action required') ? 'Action required' : 'Current status' }}</strong>
                    <p>{{ $event['next_action'] }}</p>
                </div>
                <section class="calendar-drawer-items" aria-label="Items">
                    <div class="section-heading"><h4>Items</h4><span>{{ $event['item_count'] }} item {{ \Illuminate\Support\Str::plural('type', $event['item_count']) }}</span></div>
                    @if($event['details_visible'])
                        <div class="calendar-item-list">
                            @foreach($event['items'] as $item)
                                <div><span><strong>{{ $item['name'] }}</strong><small>{{ $item['quantity_label'] }}</small></span><span>{{ $item['quantity'] }} {{ $item['unit'] }}</span></div>
                            @endforeach
                        </div>
                    @else
                        <p class="calendar-private-note">Item and requester details remain private. This approved period is shown only to communicate possible availability impact.</p>
                    @endif
                </section>
                @if($event['request_url'])
                    <a class="button primary ui-pressable full" href="{{ $event['request_url'] }}">{{ $event['action_label'] }}</a>
                @endif
            </article>
        </template>
    @endforeach

    @foreach($calendarWeeks->flatten(1)->filter(fn ($day) => $day['occurrences']->count() > 2) as $day)
        <template id="calendar-day-{{ $day['date']->toDateString() }}">
            <div class="calendar-day-summary">
                <p class="eyebrow">Daily activity</p>
                <h3>{{ $day['date']->format('d F Y') }}</h3>
                <p>Select a reservation to see its complete schedule and items.</p>
                <div class="calendar-day-summary-list">
                    @foreach($day['occurrences'] as $occurrence)
                        <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" variant="drawer" :filterable="$isBorrower" />
                    @endforeach
                </div>
            </div>
        </template>
    @endforeach
</section>

<button class="calendar-drawer-backdrop" type="button" aria-label="Close borrowing details" data-calendar-drawer-close hidden></button>
<aside class="calendar-drawer" role="dialog" aria-labelledby="calendar-drawer-heading" aria-hidden="true" data-calendar-drawer hidden>
    <div class="calendar-drawer-header">
        <h2 id="calendar-drawer-heading">Borrowing Details</h2>
        <button class="icon-button" type="button" aria-label="Close borrowing details" title="Close borrowing details" data-calendar-drawer-close><x-icon name="close" /></button>
    </div>
    <div class="calendar-drawer-content" data-calendar-drawer-content></div>
</aside>
@endsection
