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
        <a class="button secondary ui-pressable" href="{{ route('policies.index', ['section' => 'transaction-schedule']) }}"><span>Manage Operational Schedule</span><x-icon name="arrow-right" size="15" /></a>
    @endif
</section>

<section class="content-area borrowing-calendar" data-borrowing-calendar data-calendar-filter-own-only="{{ $isBorrower ? 'true' : 'false' }}">
    <div class="calendar-toolbar">
        <nav class="calendar-navigation" aria-label="Calendar month navigation">
            <a class="calendar-nav-button ui-pressable" href="{{ route('calendar.index', ['month' => $previousMonth->format('Y-m')]) }}" aria-label="Previous month" title="Previous month"><x-icon name="arrow-left" /></a>
            <a class="button secondary small ui-pressable" href="{{ route('calendar.index', ['month' => now(config('app.timezone'))->format('Y-m')]) }}">Today</a>
            <a class="calendar-nav-button ui-pressable" href="{{ route('calendar.index', ['month' => $nextMonth->format('Y-m')]) }}" aria-label="Next month" title="Next month"><x-icon name="arrow-right" /></a>
        </nav>
        <h2 class="calendar-month-title">{{ $month->format('F Y') }}</h2>
        <div class="calendar-toolbar-actions">
            <div class="calendar-filter-menu" data-calendar-phase-filters>
                <button type="button" class="button secondary small calendar-filter-toggle ui-pressable" data-calendar-filter-toggle aria-expanded="false">
                    <span data-calendar-filter-label>All activities</span>
                    <span class="calendar-filter-chevron" aria-hidden="true"><x-icon name="chevron-down" /></span>
                </button>
                <div class="calendar-filter-popover" data-calendar-filter-popover hidden>
                    <div class="calendar-filter-popover-title">Filter activity</div>
                    <button type="button" class="calendar-filter-option is-selected" value="" data-calendar-phase-filter>All activities</button>
                    <button type="button" class="calendar-filter-option" value="pickup" data-calendar-phase-filter><span class="calendar-filter-dot pickup" aria-hidden="true"></span><span>Pickup / Release</span></button>
                    <button type="button" class="calendar-filter-option" value="return" data-calendar-phase-filter><span class="calendar-filter-dot return" aria-hidden="true"></span><span>Return Due</span></button>
                    <button type="button" class="calendar-filter-option" value="attention" data-calendar-phase-filter><span class="calendar-filter-dot attention" aria-hidden="true"></span><span>Needs Attention</span></button>
                    <button type="button" class="calendar-filter-option" value="returned" data-calendar-phase-filter><span class="calendar-filter-dot returned" aria-hidden="true"></span><span>Returned / Completed</span></button>
                </div>
                <span class="visually-hidden" data-calendar-phase-live aria-live="polite">Showing all calendar activity types.</span>
            </div>
            <div class="calendar-view-toggle" role="group" aria-label="Calendar view">
                <button type="button" class="calendar-view-control ui-pressable active" aria-pressed="true" data-calendar-view-button="month">Month</button>
                <button type="button" class="calendar-view-control ui-pressable" aria-pressed="false" data-calendar-view-button="list">List</button>
            </div>
        </div>
    </div>

    <div data-calendar-view-panel="month">
        <div class="calendar-color-legend" aria-label="Calendar color legend">
            <span><i class="calendar-filter-dot pickup" aria-hidden="true"></i>Pickup / Release</span>
            <span><i class="calendar-filter-dot return" aria-hidden="true"></i>Return Due</span>
            <span><i class="calendar-filter-dot attention" aria-hidden="true"></i>Needs Attention</span>
            <span><i class="calendar-filter-dot returned" aria-hidden="true"></i>Returned / Completed</span>
        </div>
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
        <div class="universal-record-toolbar calendar-list-filter-toolbar" data-calendar-list-filters>
            <label class="calendar-list-search">
                Search
                <span class="search-input-shell">
                    <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    <input
                        type="search"
                        data-calendar-list-search
                        placeholder="{{ $isBorrower ? 'Search request or event...' : 'Search request, event, or office...' }}"
                        autocomplete="off"
                    >
                </span>
            </label>
            <label>
                Status
                <select data-calendar-list-status aria-label="Filter list by status">
                    <option value="">All statuses ({{ $calendarEvents->count() }})</option>
                    <option value="active">Active ({{ $summary['active'] }})</option>
                    <option value="due-soon">Due Soon ({{ $summary['due_soon'] }})</option>
                    <option value="overdue">Overdue ({{ $summary['overdue'] }})</option>
                    <option value="returned">Returned ({{ $summary['returned'] }})</option>
                </select>
            </label>
            <label>
                Sort
                <select data-calendar-list-sort aria-label="Sort calendar list">
                    <option value="date-soonest">Date — Soonest</option>
                    <option value="date-latest">Date — Latest</option>
                </select>
            </label>
            <span class="visually-hidden" data-calendar-list-live aria-live="polite">Showing all calendar records.</span>
        </div>

        <div class="calendar-list-records" data-calendar-list-records>
            @forelse($calendarEvents as $event)
                <x-calendar-event :event="$event" variant="list" :filterable="true" />
            @empty
                <div class="empty-state" data-calendar-default-empty><strong>{{ $isBorrower ? 'No personal borrowing activity this month.' : 'No borrowing activity this month.' }}</strong><span>{{ $isBorrower ? 'SPMU operating days are still shown on the calendar.' : 'Use the month controls to review another period.' }}</span></div>
            @endforelse
        </div>
    </div>

    <div class="calendar-filter-empty" role="status" data-calendar-filter-empty hidden>
        <strong>No matching activity this month.</strong>
        <span data-calendar-filter-empty-copy>Adjust the selected calendar filters.</span>
    </div>

    @foreach($calendarEvents as $event)
        <template id="calendar-detail-{{ $event['key'] }}">
            <article class="calendar-preview-detail">
                <div class="calendar-preview-heading">
                    <div>
                        <p class="eyebrow">Borrowing schedule</p>
                        <h3>{{ $event['purpose'] ?: $event['reference'] }}</h3>
                        @if($event['purpose'])<p class="calendar-preview-reference">{{ $event['reference'] }}</p>@endif
                    </div>
                    <x-status-badge :status="$event['status']" />
                </div>
                <dl class="calendar-preview-summary">
                    <div><dt>Borrowing period</dt><dd>{{ $event['start_at']->format('d M Y') }} <span aria-hidden="true">→</span> {{ $event['due_at']->format('d M Y') }}</dd></div>
                    <div><dt>Items</dt><dd>{{ $event['item_count'] }} item {{ \Illuminate\Support\Str::plural('type', $event['item_count']) }}</dd></div>
                    @if($event['office'])<div><dt>Office / Department</dt><dd>{{ $event['office'] }}</dd></div>@endif
                </dl>
                <div class="calendar-preview-status {{ $event['is_overdue'] || $event['status'] === 'OBLIGATION_OPEN' ? 'warning' : '' }}">
                    <strong>{{ $event['own_record'] && str_starts_with($event['next_action'], 'Action required') ? 'Action required' : 'Current status' }}</strong>
                    <p>{{ $event['next_action'] }}</p>
                </div>
                @if($event['request_url'])
                    <a class="button primary ui-pressable full" href="{{ $event['request_url'] }}"><span>View details</span><x-icon name="arrow-right" size="15" /></a>
                @endif
            </article>
        </template>
    @endforeach

    @foreach($calendarWeeks->flatten(1)->filter(fn ($day) => $day['occurrences']->count() > 2) as $day)
        <template id="calendar-day-{{ $day['date']->toDateString() }}">
            <div class="calendar-day-summary">
                <p class="eyebrow">Daily activity</p>
                <h3>{{ $day['date']->format('d F Y') }}</h3>
                <p>Choose a record to preview it.</p>
                <div class="calendar-day-summary-list">
                    @foreach($day['occurrences'] as $occurrence)
                        <x-calendar-event :event="$occurrence['event']" :phase-label="$occurrence['phase_label']" variant="drawer" :filterable="true" />
                    @endforeach
                </div>
            </div>
        </template>
    @endforeach
</section>

<button class="calendar-preview-backdrop" type="button" aria-label="Close calendar preview" data-calendar-preview-close hidden></button>
<div class="calendar-preview-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-preview-heading" aria-hidden="true" data-calendar-preview hidden>
    <div class="calendar-preview-card">
        <div class="calendar-preview-header">
            <h2 id="calendar-preview-heading">Calendar details</h2>
            <button class="icon-button" type="button" aria-label="Close calendar details" title="Close" data-calendar-preview-close><x-icon name="close" /></button>
        </div>
        <div class="calendar-preview-content" data-calendar-preview-content></div>
    </div>
</div>

<style>
/* Calendar controls stay compact: one filter menu, a passive color legend, and a small details preview. */
.calendar-role-description{margin:6px 0 0;max-width:760px;color:var(--text-muted);font-size:13px;line-height:1.55}
.calendar-toolbar-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}
.calendar-filter-menu{position:relative}
.calendar-filter-toggle{display:inline-flex;align-items:center;justify-content:space-between;gap:8px;min-width:142px}
.calendar-filter-chevron{display:inline-grid;place-items:center;width:16px;height:16px;flex:0 0 16px;transform:none;transform-origin:center;transition:transform .15s ease}.calendar-filter-chevron svg{display:block;width:14px;height:14px}.calendar-filter-toggle[aria-expanded="true"] .calendar-filter-chevron{transform:rotate(180deg)}
.calendar-filter-popover{position:absolute;z-index:40;top:calc(100% + 7px);right:0;width:258px;padding:8px 0;background:var(--surface-elevated);border:1px solid var(--border);border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.16);overflow:hidden}
.calendar-filter-popover-title{padding:2px 16px 7px;color:var(--text-muted);font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.calendar-filter-option,button.calendar-filter-option{display:flex!important;width:100%!important;min-height:0!important;align-items:center!important;justify-content:flex-start!important;gap:8px!important;margin:0!important;padding:11px 16px!important;border:0!important;border-radius:0!important;background:transparent!important;color:var(--heading)!important;font:inherit!important;font-size:12.5px!important;font-weight:650!important;text-align:left!important;cursor:pointer;box-shadow:none!important;appearance:none}.calendar-filter-option:hover,.calendar-filter-option:focus-visible,button.calendar-filter-option:hover,button.calendar-filter-option:focus-visible{background:var(--surface-subtle)!important;border:0!important;border-radius:0!important;outline:none!important;box-shadow:none!important}.calendar-filter-option.is-selected,button.calendar-filter-option.is-selected{background:color-mix(in srgb, var(--info-bg) 55%, transparent)!important;color:var(--heading)!important;font-weight:750!important}
.calendar-filter-dot{display:inline-block;width:8px;height:8px;flex:0 0 8px;border-radius:50%}.calendar-filter-dot.pickup{background:#2f80ed}.calendar-filter-dot.return{background:#d99a16}.calendar-filter-dot.attention{background:#d14343}.calendar-filter-dot.returned{background:#2e9d62}
/* Keep the Month/List control compact and consistent with the universal dashboard controls. */
.calendar-view-toggle{display:inline-flex;align-items:center;gap:6px;padding:0;background:transparent;border:0;border-radius:0}
.calendar-view-control{min-height:34px;padding:6px 12px;border:1px solid var(--border);border-radius:8px;background:var(--surface-elevated);color:var(--text-muted);box-shadow:none}
.calendar-view-control:hover,.calendar-view-control:focus-visible{background:var(--surface-subtle);border-color:var(--border-strong);color:var(--heading);outline:none}
.calendar-view-control.active{background:color-mix(in srgb,var(--info-bg) 55%,var(--surface-elevated));border-color:color-mix(in srgb,var(--interactive) 45%,var(--border));color:var(--interactive);box-shadow:none}

/* Passive color key only: no pills/tabs/cards, just a compact line of labels. */
.calendar-color-legend{display:flex;align-items:center;gap:8px 18px;flex-wrap:wrap;padding:4px 2px 8px;color:var(--text-muted);font-size:9.5px;font-weight:650;line-height:1.25}
.calendar-color-legend span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.calendar-color-legend .calendar-filter-dot{width:7px;height:7px;flex-basis:7px}
.calendar-list-filter-toolbar{grid-template-columns:minmax(260px,1fr) minmax(170px,220px) minmax(160px,205px);margin-bottom:12px}.calendar-list-search{min-width:0}.calendar-list-records{display:grid;gap:8px}
.calendar-day-flags{display:flex;align-items:center;justify-content:flex-end;gap:4px;flex-wrap:wrap;min-width:0}.calendar-day-heading .calendar-today-label{color:var(--interactive);font-size:8px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.calendar-day-heading .calendar-operational-badge{display:inline-flex;align-items:center;max-width:100%;padding:2px 5px;border-radius:999px;font-size:7px;font-weight:800;line-height:1.25;letter-spacing:.02em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.calendar-day-heading .calendar-operational-badge-closed{background:#f1f3f5;color:#667085;border:1px solid #d8dde5}.calendar-day-heading .calendar-operational-badge-special{background:#eaf3ff;color:#1556a8;border:1px solid #bdd8ff}.calendar-day-heading .calendar-operational-badge-limited{background:#fff7df;color:#8a5b00;border:1px solid #f2d48b}
.calendar-day.calendar-operational-closed{background:#f7f8fa}.calendar-day.calendar-operational-closed:not(.outside-month){box-shadow:inset 0 3px 0 #c9ced6}.calendar-day.calendar-operational-special:not(.outside-month){box-shadow:inset 0 3px 0 #4d91e8}.calendar-day.calendar-operational-limited:not(.outside-month){box-shadow:inset 0 3px 0 #d9a629}
.legend-mark.adjusted{background:#6f42c1}.legend-mark.operational-closed{background:#c9ced6}.legend-mark.operational-special{background:#4d91e8}
.calendar-event.calendar-phase-pickup{background:#eef6ff;border-color:#cfe3fb;border-left-color:#2f80ed}.calendar-event.calendar-phase-return{background:#fff8e8;border-color:#f2ddb0;border-left-color:#d99a16}.calendar-event.calendar-phase-attention{background:#fff0f0;border-color:#f0c4c4;border-left-color:#d14343}.calendar-event.calendar-phase-returned{background:#edf8f1;border-color:#cce8d7;border-left-color:#2e9d62}
.calendar-preview-backdrop{position:fixed;inset:0;z-index:90;border:0;background:rgba(15,23,42,.34);opacity:0;transition:opacity .16s ease}.calendar-preview-backdrop.is-open{opacity:1}
.calendar-preview-modal{position:fixed;inset:0;z-index:91;display:grid;place-items:center;padding:20px;pointer-events:none;opacity:0;transition:opacity .16s ease}.calendar-preview-modal.is-open{opacity:1}.calendar-preview-card{width:min(500px,calc(100vw - 32px));max-height:min(680px,calc(100vh - 40px));overflow:auto;background:var(--surface-elevated);border:1px solid var(--border);border-radius:14px;box-shadow:0 24px 70px rgba(15,23,42,.28);pointer-events:auto;transform:translateY(8px) scale(.99);transition:transform .16s ease}.calendar-preview-modal.is-open .calendar-preview-card{transform:translateY(0) scale(1)}
.calendar-preview-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}.calendar-preview-header h2{margin:0;font-size:16px}
.calendar-preview-content{padding:16px}.calendar-preview-detail{display:grid;gap:14px}.calendar-preview-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.calendar-preview-heading h3{margin:2px 0 0;font-size:18px;line-height:1.3}.calendar-preview-reference{margin:4px 0 0;color:var(--text-muted);font-size:12px}
.calendar-preview-summary{display:grid;gap:0;margin:0;border-top:1px solid var(--border)}.calendar-preview-summary>div{display:grid;grid-template-columns:135px 1fr;gap:12px;padding:9px 0;border-bottom:1px solid var(--border)}.calendar-preview-summary dt{color:var(--text-muted);font-size:11px;font-weight:750}.calendar-preview-summary dd{margin:0;color:var(--text-primary);font-size:12px}
.calendar-preview-status{padding:10px 11px;border-left:3px solid var(--interactive);border-radius:8px;background:var(--surface-subtle)}.calendar-preview-status.warning{border-left-color:#d99a16;background:#fff8e8}.calendar-preview-status strong{font-size:12px}.calendar-preview-status p{margin:3px 0 0;color:var(--text-secondary);font-size:12px;line-height:1.45}
.calendar-day-summary>p:not(.eyebrow){margin:4px 0 12px;color:var(--text-muted);font-size:12px}.calendar-day-summary-list{display:grid;gap:7px;max-height:420px;overflow:auto}
body.calendar-preview-open{overflow:hidden}
@media(max-width:900px){.calendar-list-filter-toolbar{grid-template-columns:1fr 1fr}.calendar-list-search{grid-column:1 / -1}}
@media(max-width:760px){.calendar-toolbar{grid-template-columns:1fr auto}.calendar-month-title{grid-column:1 / -1;grid-row:1;text-align:center}.calendar-navigation{grid-row:2}.calendar-toolbar-actions{grid-row:2}.calendar-color-legend{gap:9px}.calendar-list-filter-toolbar{grid-template-columns:1fr}.calendar-preview-modal{padding:10px}.calendar-preview-card{width:100%;max-height:calc(100vh - 20px)}.calendar-preview-summary>div{grid-template-columns:1fr;gap:3px}}
</style>
@endsection
