@extends('layouts.app', ['title' => $calendarTitle])
@section('content')
@php
    $isBorrower = $workspace === 'BORROWER';
@endphp
<section class="page-heading calendar-page-heading">
    <div>
        <p class="eyebrow">{{ $calendarEyebrow }}</p>
        <h1>{{ $calendarTitle }}</h1>
        <p class="calendar-role-description">{{ $isBorrower ? 'View your pickup, release, return dates, and SPMU operating days.' : $calendarDescription }}</p>
    </div>
    @if($isSpmuHead)
        <a class="button secondary ui-pressable" href="{{ route('policies.index', ['section' => 'transaction-schedule']) }}">Manage Operational Schedule</a>
    @endif
</section>

<section class="content-area borrowing-calendar" data-borrowing-calendar data-calendar-filter-own-only="{{ $isBorrower ? 'true' : 'false' }}">
    <nav class="calendar-status-tabs" aria-label="Borrowing status filters" data-calendar-status-filters>
            <button
                type="button"
                class="calendar-status-tab is-selected"
                data-calendar-status-filter=""
                data-calendar-status-count="{{ $calendarEvents->count() }}"
                aria-pressed="true"
            >
                <span>All</span>
                <span class="calendar-status-tab-count">{{ $calendarEvents->count() }}</span>
            </button>
            <button
                type="button"
                class="calendar-status-tab"
                data-calendar-status-filter="active"
                data-calendar-status-count="{{ $summary['active'] }}"
                aria-pressed="false"
            >
                <span>Active</span>
                <span class="calendar-status-tab-count">{{ $summary['active'] }}</span>
            </button>
            <button
                type="button"
                class="calendar-status-tab"
                data-calendar-status-filter="due-soon"
                data-calendar-status-count="{{ $summary['due_soon'] }}"
                aria-pressed="false"
            >
                <span>Due Soon</span>
                <span class="calendar-status-tab-count">{{ $summary['due_soon'] }}</span>
            </button>
            <button
                type="button"
                class="calendar-status-tab"
                data-calendar-status-filter="overdue"
                data-calendar-status-count="{{ $summary['overdue'] }}"
                aria-pressed="false"
            >
                <span>Overdue</span>
                <span class="calendar-status-tab-count">{{ $summary['overdue'] }}</span>
            </button>
            <button
                type="button"
                class="calendar-status-tab"
                data-calendar-status-filter="returned"
                data-calendar-status-count="{{ $summary['returned'] }}"
                aria-pressed="false"
            >
                <span>Returned</span>
                <span class="calendar-status-tab-count">{{ $summary['returned'] }}</span>
            </button>
            <span class="visually-hidden" data-calendar-filter-live aria-live="polite">Showing all calendar records.</span>
    </nav>

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
        <span><i class="legend-mark scheduled" aria-hidden="true"></i>Pickup / Release</span>
        <span><i class="legend-mark due" aria-hidden="true"></i>Return due</span>
        <span><i class="legend-mark adjusted" aria-hidden="true"></i>Adjusted return</span>
        <span><i class="legend-mark overdue" aria-hidden="true"></i>Overdue</span>
        <span><i class="legend-mark returned" aria-hidden="true"></i>Returned</span>
        <span><i class="legend-mark operational-closed" aria-hidden="true"></i>SPMU closed</span>
        <span><i class="legend-mark operational-special" aria-hidden="true"></i>Special open day</span>
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
                            <div
                                class="calendar-day {{ !$day['in_month'] ? 'outside-month' : '' }} {{ $day['is_today'] ? 'is-today' : '' }} calendar-operational-{{ $day['operational']['tone'] }}"
                                role="gridcell"
                                aria-label="{{ $day['date']->format('l, d F Y') }}. {{ $day['operational']['details'] }}"
                            >
                                <div class="calendar-day-heading">
                                    <time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->day }}</time>
                                    <div class="calendar-day-flags">
                                        @if($day['is_today'])<span class="calendar-today-label">Today</span>@endif
                                        @if($day['operational']['label'])
                                            <span
                                                class="calendar-operational-badge calendar-operational-badge-{{ $day['operational']['tone'] }}"
                                                title="{{ $day['operational']['details'] }}"
                                            >{{ $day['operational']['label'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="calendar-day-events" data-calendar-day-events>
                                    @foreach($day['occurrences'] as $occurrence)
                                        <div
                                            class="calendar-day-occurrence"
                                            data-calendar-occurrence
                                            data-calendar-occurrence-date="{{ $day['date']->toDateString() }}"
                                            @if($loop->index >= 2) hidden @endif
                                        >
                                            <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" :filterable="true" />
                                        </div>
                                    @endforeach
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
            <div class="calendar-empty-month" role="status" data-calendar-default-empty><strong>{{ $isBorrower ? 'No personal borrowing activity this month.' : 'No borrowing activity this month.' }}</strong><span>{{ $isBorrower ? 'SPMU operating days are still shown on the calendar.' : 'Use the month controls to review another period.' }}</span></div>
        @endif
    </div>

    <div class="calendar-list-view" data-calendar-view-panel="list" hidden>
        @forelse($calendarEvents as $event)
            <x-calendar-event :event="$event" variant="list" :filterable="true" />
        @empty
            <div class="empty-state" data-calendar-default-empty><strong>{{ $isBorrower ? 'No personal borrowing activity this month.' : 'No borrowing activity this month.' }}</strong><span>{{ $isBorrower ? 'SPMU operating days are still shown on the calendar.' : 'Use the month controls to review another period.' }}</span></div>
        @endforelse
    </div>

    <div class="calendar-filter-empty" role="status" data-calendar-filter-empty hidden>
        <strong>No matching records this month.</strong>
        <span>Select another status or choose All.</span>
    </div>

    @foreach($calendarEvents as $event)
        <template id="calendar-detail-{{ $event['key'] }}">
            <article class="calendar-drawer-detail">
                <div class="calendar-drawer-reference">
                    <div><p class="eyebrow">Reservation / Request</p><h3>{{ $event['reference'] }}</h3></div>
                    <x-status-badge :status="$event['status']" />
                </div>
                <dl class="calendar-drawer-summary">
                    <div><dt>Borrowing period</dt><dd>{{ $event['start_at']->format('d M Y') }} <span aria-hidden="true">&rarr;</span> {{ $event['due_at']->format('d M Y') }}</dd></div>
                    @if($event['return_adjusted'])
                        <div><dt>Original expected return</dt><dd>{{ $event['original_due_at']->format('d M Y') }}</dd></div>
                        <div><dt>Effective SPMU return date</dt><dd><strong>{{ $event['due_at']->format('d M Y') }}</strong></dd></div>
                        <div class="calendar-adjustment-reason"><dt>Schedule adjustment</dt><dd>{{ $event['due_adjustment_reason'] ?: 'Moved to the next open SPMU return date.' }}</dd></div>
                    @else
                        <div><dt>Effective return date</dt><dd>{{ $event['due_at']->format('d M Y') }}</dd></div>
                    @endif
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
                <p>Select a borrowing record to see its complete schedule and items.</p>
                <div class="calendar-day-summary-list">
                    @foreach($day['occurrences'] as $occurrence)
                        <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" variant="drawer" :filterable="true" />
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

<style>
.calendar-status-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:0 0 14px;padding:12px 14px;border:1px solid var(--border);border-radius:12px;background:var(--surface,#fff)}
.calendar-status-tab{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:7px 11px;border:1px solid var(--border);border-radius:999px;background:var(--surface,#fff);color:var(--text-muted);font:inherit;font-size:12px;font-weight:800;line-height:1;cursor:pointer;transition:border-color .16s ease,background .16s ease,color .16s ease,box-shadow .16s ease}
.calendar-status-tab:hover{border-color:#b8c9dc;color:var(--heading);background:var(--surface-hover,#f4f8fc)}
.calendar-status-tab:focus-visible{outline:3px solid rgba(31,111,235,.18);outline-offset:2px}
.calendar-status-tab.is-selected{border-color:#9fc8ec;background:#eaf5ff;color:#075ea8;box-shadow:inset 0 0 0 1px rgba(7,94,168,.03)}
.calendar-status-tab-count{display:inline-grid;place-items:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:rgba(15,42,67,.07);color:inherit;font-size:10px;font-weight:900}
.calendar-status-tab.is-selected .calendar-status-tab-count{background:rgba(7,94,168,.10)}
.calendar-role-description{margin:6px 0 0;max-width:760px;color:var(--text-muted);font-size:13px;line-height:1.55}
.calendar-day-flags{display:flex;align-items:center;justify-content:flex-end;gap:4px;flex-wrap:wrap;min-width:0}.calendar-day-heading .calendar-today-label{color:var(--interactive);font-size:8px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.calendar-day-heading .calendar-operational-badge{display:inline-flex;align-items:center;max-width:100%;padding:2px 5px;border-radius:999px;font-size:7px;font-weight:800;line-height:1.25;letter-spacing:.02em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.calendar-day-heading .calendar-operational-badge-closed{background:#f1f3f5;color:#667085;border:1px solid #d8dde5}.calendar-day-heading .calendar-operational-badge-special{background:#eaf3ff;color:#1556a8;border:1px solid #bdd8ff}.calendar-day-heading .calendar-operational-badge-limited{background:#fff7df;color:#8a5b00;border:1px solid #f2d48b}
.calendar-day.calendar-operational-closed{background:#f7f8fa}.calendar-day.calendar-operational-closed:not(.outside-month){box-shadow:inset 0 3px 0 #c9ced6}.calendar-day.calendar-operational-special:not(.outside-month){box-shadow:inset 0 3px 0 #4d91e8}.calendar-day.calendar-operational-limited:not(.outside-month){box-shadow:inset 0 3px 0 #d9a629}
.legend-mark.adjusted{background:#6f42c1}.legend-mark.operational-closed{background:#c9ced6}.legend-mark.operational-special{background:#4d91e8}
.calendar-adjustment-reason dd{color:#7a4f00}.calendar-adjustment-reason{background:#fff9e8;border-radius:8px;padding:7px 8px}
.calendar-status-tab[data-calendar-zero="true"]{opacity:.58}
.calendar-status-tab[data-calendar-zero="true"] .calendar-status-tab-count{background:rgba(15,42,67,.04)}
.calendar-day.calendar-status-jump-day{position:relative;z-index:1;box-shadow:inset 0 0 0 3px rgba(31,111,235,.28),0 0 0 3px rgba(31,111,235,.08)}
.calendar-event.calendar-status-jump-target{outline:3px solid rgba(31,111,235,.35);outline-offset:2px;box-shadow:0 8px 22px rgba(31,111,235,.15)}
.calendar-filter-empty.calendar-status-jump-empty{outline:3px solid rgba(31,111,235,.16);outline-offset:3px}
@media(prefers-reduced-motion:reduce){.calendar-day.calendar-status-jump-day,.calendar-event.calendar-status-jump-target{scroll-behavior:auto}}
@media(max-width:760px){.calendar-status-tabs{padding:10px;gap:6px}.calendar-status-tab{flex:1 1 auto;justify-content:center}.calendar-day-heading .calendar-operational-badge{font-size:6px;padding:2px 4px}}
</style>
@endsection
