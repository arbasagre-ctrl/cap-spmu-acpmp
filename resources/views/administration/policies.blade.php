@extends('layouts.app', ['title' => 'Operational Configuration'])

@section('content')

@php
    $configurationSection = request()->query('section');
    $allowedConfigurationSections = [
        'transaction-schedule',
        'special-dates',
        'academic-periods',
        'sanction-rules',
    ];

    if (! in_array($configurationSection, $allowedConfigurationSections, true)) {
        $configurationSection = null;
    }

    $sectionTitles = [
        'transaction-schedule' => ['Operational Calendar', 'Transaction Schedule', 'Configure weekly request, pickup/release, and return availability.'],
        'special-dates' => ['Operational Calendar', 'Special Dates & Closures', 'Manage holidays, typhoons, emergency closures, and approved special working days.'],
        'academic-periods' => ['Academic Calendar', 'Academic Period', 'Manage the active semester and historical academic periods used by the system.'],
        'sanction-rules' => ['Administrative Accountability', 'Sanction Rules', 'Configure the default consequence for the 1st, 2nd, and 3rd confirmed offense.'],
    ];

    $activeSectionHeading = $configurationSection ? $sectionTitles[$configurationSection] : null;
    $offenseApplicationTypes = $offenseApplicationTypes ?? [
        'LATE_RETURN',
        'DAMAGED',
        'LOST_MISSING',
        'STOLEN',
        'DESTROYED',
    ];

    $weekdayLabels = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    $effectiveStatus = function ($period): string {
        if ($period->status === 'ACTIVE') {
            return 'ACTIVE';
        }

        if (
            $period->end_date
            && $period->end_date
                ->copy()
                ->endOfDay()
                ->isPast()
        ) {
            return 'COMPLETED';
        }

        return 'UPCOMING';
    };
@endphp

<style>
    .academic-period-config {
        --period-line: var(--border, #d7e0ea);
        --period-muted: var(--text-muted, #64748b);
        --period-ink: var(--text, #18324a);
        --period-soft: var(--surface-subtle, #f7f9fc);
        display: grid;
        gap: 16px;
    }

    .current-period-card {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        padding: 18px 20px;
        text-align: left;
    }

    .current-period-main {
        min-width: 0;
        flex: 1 1 auto;
        text-align: left;
    }

    .current-period-value {
        margin: 3px 0 4px;
        color: var(--period-ink);
        font-size: clamp(20px, 2vw, 27px);
        font-weight: 850;
        line-height: 1.2;
    }

    .current-period-dates {
        color: var(--period-muted);
        font-size: 13px;
    }

    .current-period-empty {
        margin: 4px 0 0;
        color: var(--period-muted);
        font-size: 13px;
        line-height: 1.45;
    }

    .academic-period-form {
        display: grid;
        gap: 14px;
    }

    .academic-period-form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .academic-period-form-grid label {
        min-width: 0;
        margin: 0;
    }

    .academic-period-form-grid input,
    .academic-period-form-grid select {
        width: 100%;
        margin-top: 7px;
    }

        .periods-table .period-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        white-space: nowrap;
    }

    .periods-table .period-current {
        color: #157347;
        font-size: 11px;
        font-weight: 800;
    }

    .periods-table .period-no-action {
        color: var(--period-muted);
        font-size: 11px;
    }

    .period-status-copy {
        display: block;
        margin-top: 4px;
        color: var(--period-muted);
        font-size: 10px;
        line-height: 1.35;
    }

    @media (max-width: 760px) {
        .current-period-card {
            align-items: flex-start;
            flex-direction: column;
        }

        .academic-period-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="page-heading operational-config-heading {{ $configurationSection ? 'operational-config-section-heading' : '' }}">
    <div>
        <p class="eyebrow">{{ $activeSectionHeading[0] ?? 'SPMU Head configuration' }}</p>
        <h1>{{ $activeSectionHeading[1] ?? 'Operational Configuration' }}</h1>
        <p>{{ $activeSectionHeading[2] ?? 'Manage schedules, operational policies, and official document templates.' }}</p>
    </div>

    @if($configurationSection)
        <a class="button secondary ui-pressable config-back-button" href="{{ route('policies.index') }}">
            <x-icon name="arrow-left" size="17" />
            Back to Operational Configuration
        </a>
    @endif
</section>

@if(!$configurationSection)
<section class="content-area operational-config-hub">
    <div class="operational-config-group">
        <h2 class="operational-config-group-title">Schedule &amp; availability</h2>

        <div class="operational-config-grid operational-config-grid-2">
            <a class="operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'transaction-schedule']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="calendar" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Transaction Schedule</strong>
                    <span>Weekly request, pickup, release &amp; return hours</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>

            <a class="operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'special-dates']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="calendar-clock" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Special Dates &amp; Closures</strong>
                    <span>Holidays, closures, and special working days</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>
        </div>
    </div>

    <div class="operational-config-group">
        <h2 class="operational-config-group-title">Policies</h2>

        <div class="operational-config-grid operational-config-grid-3">
            <a class="operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'academic-periods']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="calendar" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Academic Period</strong>
                    <span>Semester and academic year configuration</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>

            <a class="operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'late-return-fee']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="clock" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Late Return Policy</strong>
                    <span>Return deadlines and financial assessment</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>

            <a class="operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'sanction-rules']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="accountability" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Sanction Rules</strong>
                    <span>Confirmed offense rules and defaults</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>
        </div>
    </div>

    <div class="operational-config-group">
        <h2 class="operational-config-group-title">Document templates</h2>

        <div class="operational-config-grid operational-config-grid-3">
            <a class="operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-billing-statement']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="requests" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Billing Statement Template</strong>
                    <span>Manage approved billing statement template</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>

            <a class="operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-laundry-form']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="custody" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Laundry Form Template</strong>
                    <span>Manage approved Laundry Form template</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>

            <a class="operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-gate-pass']) }}">
                <span class="operational-config-card-icon" aria-hidden="true"><x-icon name="id-badge" size="24" /></span>
                <span class="operational-config-card-text">
                    <strong>Gate Pass Template</strong>
                    <span>Manage approved Gate Pass template</span>
                </span>
                <x-icon name="chevron-right" size="20" class="operational-config-card-chevron" />
            </a>
        </div>
    </div>

    <div class="operational-config-footnote">
        <x-icon name="lock" size="18" />
        <span>User accounts and access administration are managed by ICTU.</span>
    </div>
</section>
@endif

@if($configurationSection === 'transaction-schedule')
    @include('administration.partials.transaction-schedule')
@endif

@if($configurationSection === 'special-dates')
@include('administration.partials.special-dates-styles')

<section class="content-area special-dates-config" id="special-dates" data-special-dates>
    <article class="card special-dates-card">
        <header class="special-dates-header">
            <span class="special-dates-header-icon" aria-hidden="true"><x-icon name="calendar" size="26" /></span>

            <div>
                <p class="eyebrow">Schedule exceptions</p>
                <h2>Special Dates &amp; Closures</h2>
                <p class="meta">Override the normal weekly schedule for holidays, typhoons, emergency campus closures, semester breaks, or approved special working days.</p>
            </div>
        </header>

        <form method="post" action="{{ route('policies.date-exceptions.store') }}" class="special-date-form">
            @csrf

            <div class="special-date-fields">
                <label for="special-date-date">Date
                    <input id="special-date-date" type="date" name="exception_date" required>
                </label>
                <label for="special-date-status">Status
                    <select id="special-date-status" name="status" required>
                        <option value="CLOSED">Closed</option>
                        <option value="OPEN">Open / Special Working Day</option>
                    </select>
                </label>
                <label for="special-date-open-time">Open time
                    <input id="special-date-open-time" type="time" name="open_time">
                </label>
                <label for="special-date-close-time">Close time
                    <input id="special-date-close-time" type="time" name="close_time">
                </label>
            </div>

            <div class="special-date-capabilities">
                <label class="special-date-check"><input type="hidden" name="accepts_requests" value="0"><input type="checkbox" name="accepts_requests" value="1"><span>Accept requests</span></label>
                <label class="special-date-check"><input type="hidden" name="allows_pickup" value="0"><input type="checkbox" name="allows_pickup" value="1"><span>Pickup / Release</span></label>
                <label class="special-date-check"><input type="hidden" name="allows_return" value="0"><input type="checkbox" name="allows_return" value="1"><span>Returns</span></label>
            </div>

            <label class="special-date-reason" for="special-date-reason">Reason
                <input id="special-date-reason" type="text" name="reason" maxlength="500" required placeholder="e.g., Typhoon suspension, institutional holiday, special working Saturday">
            </label>

            <button class="button secondary ui-pressable special-date-submit" type="submit">
                <x-icon name="plus-circle" size="17" />
                Save Special Date
            </button>
        </form>

        <section class="special-dates-records" aria-labelledby="special-dates-records-title">
            <div class="special-dates-records-header">
                <h3 id="special-dates-records-title">
                    Scheduled Exceptions
                    <span class="special-dates-count" data-special-dates-count>{{ $dateExceptions->count() }}</span>
                </h3>

                <label class="special-dates-search" for="special-dates-search">
                    <span class="visually-hidden">Search special dates</span>
                    <span class="search-input-shell special-dates-search-shell">
                        <input
                            id="special-dates-search"
                            type="search"
                            placeholder="Search special dates..."
                            autocomplete="off"
                            data-special-dates-search
                        >
                        <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="17" /></span>
                    </span>
                </label>
            </div>

            <div class="table-wrap special-dates-table-wrap">
                <table>
                    <thead>
                        <tr>
                            @foreach(['date' => 'Date', 'status' => 'Status', 'transactions' => 'Transactions', 'hours' => 'Hours', 'reason' => 'Reason'] as $sortKey => $columnLabel)
                                <th scope="col" aria-sort="none">
                                    <button class="special-dates-sort" type="button" data-special-dates-sort="{{ $sortKey }}">
                                        {{ $columnLabel }}
                                        <x-icon name="sort" size="12" />
                                    </button>
                                </th>
                            @endforeach
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody data-special-dates-body>
                        @forelse($dateExceptions as $exception)
                            @php
                                $transactionSummary = $exception->status === 'CLOSED'
                                    ? 'All SPMU transactions closed'
                                    : (collect([
                                        $exception->accepts_requests ? 'Requests' : null,
                                        $exception->allows_pickup ? 'Pickup / Release' : null,
                                        $exception->allows_return ? 'Returns' : null,
                                    ])->filter()->join(', ') ?: 'Open day with no enabled transaction type');

                                $hoursSummary = $exception->open_time && $exception->close_time
                                    ? substr((string) $exception->open_time, 0, 5).' – '.substr((string) $exception->close_time, 0, 5)
                                    : 'Date-based';

                                $statusLabel = $exception->status === 'OPEN' ? 'Open' : 'Closed';
                            @endphp
                            <tr
                                data-special-date-row
                                data-search="{{ $exception->exception_date->format('d M Y l') }} {{ $statusLabel }} {{ $transactionSummary }} {{ $hoursSummary }} {{ $exception->reason }}"
                            >
                                <td data-sort="date" data-sort-value="{{ $exception->exception_date->getTimestamp() }}">
                                    <strong>{{ $exception->exception_date->format('d M Y') }}</strong>
                                    <small>{{ $exception->exception_date->format('l') }}</small>
                                </td>
                                <td data-sort="status" data-sort-value="{{ strtolower($statusLabel) }}">
                                    <x-status-badge :status="$exception->status" :label="$statusLabel" />
                                </td>
                                <td data-sort="transactions">{{ $transactionSummary }}</td>
                                <td data-sort="hours">{{ $hoursSummary }}</td>
                                <td data-sort="reason">{{ $exception->reason }}</td>
                                <td>
                                    <form method="post" action="{{ route('policies.date-exceptions.destroy', $exception) }}" onsubmit="return confirm('Remove this operational date exception?')">
                                        @csrf @method('DELETE')
                                        <button class="button secondary small ui-pressable" type="submit">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="empty-state special-dates-empty">
                                    @include('administration.partials.special-dates-empty')
                                </td>
                            </tr>
                        @endforelse

                        <tr data-special-dates-no-results hidden>
                            <td colspan="6" class="empty-state special-dates-empty">
                                @include('administration.partials.special-dates-empty', [
                                    'emptyTitle' => 'No special dates match your search.',
                                    'emptyMessage' => 'Try a different date, status, or reason keyword.',
                                ])
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </article>
</section>

@include('administration.partials.special-dates-interactions')
@endif

@if($configurationSection === 'academic-periods')
@include('administration.partials.academic-period-styles')
<div class="academic-period-config" id="academic-periods">

    <section class="content-area">
        <article class="card current-period-card">
            <span class="current-period-icon" aria-hidden="true">
                <x-icon name="calendar" size="34" />
            </span>

            <div class="current-period-main">
                <p class="eyebrow">
                    Current academic period
                </p>

                @if($activePeriod)
                    <div class="current-period-value">
                        {{ $activePeriod->academic_year }}
                        ·
                        {{ $activePeriod->term_name }}
                    </div>

                    <div class="current-period-dates">
                        {{ $activePeriod->start_date->format('d F Y') }}
                        –
                        {{ $activePeriod->end_date->format('d F Y') }}
                    </div>
                @else
                    <div class="current-period-value">
                        No active academic period
                    </div>

                    <p class="current-period-empty">
                        Add an academic period below, then activate the one
                        currently in effect.
                    </p>
                @endif
            </div>

            @if($activePeriod)
                <x-status-badge
                    status="ACTIVE"
                    label="Active"
                />
            @endif
        </article>
    </section>

    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">
                        Academic calendar
                    </p>

                    <h2>
                        Add academic period
                    </h2>

                    <p class="meta">
                        Enter the academic year, semester, and official start and end dates.
                    </p>
                </div>
            </div>

            <form
                method="post"
                action="{{ route('policies.academic-periods.store') }}"
                class="academic-period-form"
            >
                @csrf

                <div class="academic-period-form-grid">
                    <label>
                        Academic Year

                        <input
                            name="academic_year"
                            placeholder="2026-2027"
                            value="{{ old('academic_year') }}"
                            inputmode="numeric"
                            required
                        >

                        @error('academic_year')
                            <small class="field-error">
                                {{ $message }}
                            </small>
                        @enderror
                    </label>

                    <label>
                        Semester / Term

                        <select
                            name="term_code"
                            required
                        >
                            <option
                                value="FIRST_SEMESTER"
                                @selected(old('term_code') === 'FIRST_SEMESTER')
                            >
                                1st Semester
                            </option>

                            <option
                                value="SECOND_SEMESTER"
                                @selected(old('term_code') === 'SECOND_SEMESTER')
                            >
                                2nd Semester
                            </option>

                            <option
                                value="SUMMER_MIDYEAR"
                                @selected(old('term_code') === 'SUMMER_MIDYEAR')
                            >
                                Summer / Midyear
                            </option>
                        </select>
                    </label>

                    <label>
                        Start Date

                        <input
                            type="date"
                            name="start_date"
                            value="{{ old('start_date') }}"
                            required
                        >

                        @error('start_date')
                            <small class="field-error">
                                {{ $message }}
                            </small>
                        @enderror
                    </label>

                    <label>
                        End Date

                        <input
                            type="date"
                            name="end_date"
                            value="{{ old('end_date') }}"
                            required
                        >

                        @error('end_date')
                            <small class="field-error">
                                {{ $message }}
                            </small>
                        @enderror
                    </label>
                </div>

                <div class="academic-period-form-actions">
                    <button
                        class="button primary ui-pressable academic-period-save"
                        type="submit"
                    >
                        <svg class="ui-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 3h11l3 3v15H5z" /><path d="M8 3v6h8V3M8 21v-7h8v7" /></svg>
                        Save Academic Period
                    </button>
                </div>
            </form>
        </article>
    </section>

    <section class="content-area">
        <article class="card">
            <div class="card-header periods-card-header">
                <div>
                    <p class="eyebrow">
                        Periods
                    </p>

                    <h2>
                        Configured Academic Periods
                    </h2>

                    <p class="meta">
                        Review saved periods and activate the academic period
                        currently in effect. Previous periods remain available for reference.
                    </p>
                </div>

                <div class="periods-toolbar">
                    <label class="periods-search">
                        <span class="visually-hidden">Search academic periods</span>
                        <x-icon name="search" size="17" />
                        <input
                            id="academic-periods-search"
                            type="search"
                            placeholder="Search academic periods..."
                            autocomplete="off"
                        >
                    </label>

                    <label class="periods-sort">
                        <span class="visually-hidden">Sort academic periods</span>
                        <select id="academic-periods-sort">
                            <option value="newest">Sort: Newest first</option>
                            <option value="oldest">Sort: Oldest first</option>
                        </select>
                    </label>
                </div>
            </div>

            <div class="table-wrap periods-table">
                <table>
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Semester / Term</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody id="academic-periods-body">
                        @forelse($academicPeriods as $period)
                            @php
                                $status =
                                    $effectiveStatus(
                                        $period
                                    );

                                $canActivate =
                                    $status !== 'ACTIVE'
                                    && $status !== 'COMPLETED';

                                $periodSearch = strtolower(
                                    $period->academic_year.' '.
                                    $period->term_name.' '.
                                    $period->start_date->format('d M Y').' '.
                                    $period->end_date->format('d M Y')
                                );
                            @endphp

                            <tr
                                data-period-row
                                data-period-search="{{ $periodSearch }}"
                                data-period-start="{{ $period->start_date->timestamp }}"
                            >
                                <td>
                                    <strong>
                                        {{ $period->academic_year }}
                                    </strong>
                                </td>

                                <td>
                                    {{ $period->term_name }}
                                </td>

                                <td>
                                    {{ $period->start_date->format('d M Y') }}
                                    –
                                    {{ $period->end_date->format('d M Y') }}
                                </td>

                                <td>
                                    <x-status-badge
                                        :status="$status"
                                        :label="match($status) {
                                            'ACTIVE' => 'Active',
                                            'COMPLETED' => 'Completed',
                                            default => 'Upcoming',
                                        }"
                                    />

                                    @if($status === 'ACTIVE')
                                        <span class="period-status-copy">
                                            Current period used by the system.
                                        </span>
                                    @elseif($status === 'COMPLETED')
                                        <span class="period-status-copy">
                                            Historical period retained for records.
                                        </span>
                                    @else
                                        <span class="period-status-copy">
                                            Saved and available for activation.
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <div class="period-actions">
                                        @if($status === 'ACTIVE')
                                            <span class="period-current">
                                                Current
                                            </span>

                                        @elseif($canActivate)
                                            <form
                                                method="post"
                                                action="{{ route(
                                                    'policies.academic-periods.update',
                                                    $period
                                                ) }}"
                                            >
                                                @csrf
                                                @method('PUT')

                                                <input
                                                    type="hidden"
                                                    name="activate"
                                                    value="1"
                                                >

                                                <button
                                                    class="button secondary small ui-pressable"
                                                    type="submit"
                                                    onclick="return confirm(
                                                        'Activate {{ $period->academic_year }} · {{ $period->term_name }}? The current active period will be moved to Completed.'
                                                    )"
                                                >
                                                    Activate
                                                </button>
                                            </form>

                                        @else
                                            <span class="period-no-action">
                                                Historical
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr data-period-empty>
                                <td colspan="5" class="periods-empty-cell">
                                    @include('administration.partials.academic-period-empty')
                                </td>
                            </tr>
                        @endforelse

                        @if($academicPeriods->isNotEmpty())
                            <tr data-period-no-results hidden>
                                <td colspan="5" class="periods-empty-cell">
                                    <div class="periods-empty">
                                        <strong>No matching academic period.</strong>
                                        <span>Try another academic year or term.</span>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </article>
    </section>

</div>

@include('administration.partials.academic-period-interactions')
@endif

@if($configurationSection === 'sanction-rules')
@include('administration.partials.sanction-rules-styles')

<section class="content-area sanction-rules-config" id="sanction-rules">
    <article class="card sanction-offense-card" id="offense-application">
        <div class="offense-application-panel">
            <div class="offense-application-copy">
                <span class="offense-application-icon" aria-hidden="true"><x-icon name="clipboard-check" size="26" /></span>
                <p class="eyebrow">Offense application</p>
                <h2>Which cases may count as an administrative offense?</h2>
                <p>The system may detect these findings, but it does not automatically add an offense to the borrower. Final case confirmation is handled in Accountability Oversight.</p>
            </div>

            <form method="post" action="{{ route('policies.offense-application.update') }}" class="offense-application-form">
                @csrf @method('PUT')

                <div class="offense-type-grid">
                    @foreach([
                        'LATE_RETURN' => ['Late Return', 'Return remained outstanding after the effective return deadline.', 'calendar'],
                        'DAMAGED' => ['Damaged', 'Returned property recorded with a damaged finding.', 'shield-check'],
                        'LOST_MISSING' => ['Lost / Missing', 'Returned accountability records identify missing or lost property.', 'file-search'],
                        'STOLEN' => ['Stolen', 'Property is formally recorded as stolen.', 'mask'],
                        'DESTROYED' => ['Destroyed', 'Property is formally recorded as destroyed.', 'trash'],
                    ] as $caseType => [$caseLabel, $caseHelp, $caseIcon])
                        <label class="offense-type-option">
                            <input
                                type="checkbox"
                                name="case_types[]"
                                value="{{ $caseType }}"
                                @checked(in_array($caseType, $offenseApplicationTypes, true))
                            >
                            <span class="offense-type-icon" aria-hidden="true"><x-icon :name="$caseIcon" size="20" /></span>
                            <span class="offense-type-copy">
                                <strong>{{ $caseLabel }}</strong>
                                <small>{{ $caseHelp }}</small>
                            </span>
                        </label>
                    @endforeach
                </div>

                <button class="button secondary ui-pressable offense-application-save" type="submit">
                    <x-icon name="save" size="17" />
                    Save Offense Application
                </button>
            </form>
        </div>

        <div class="offense-confirmation-rule">
            <x-icon name="information" size="22" />
            <div>
                <strong>Final offense confirmation: SPMU Head decision required</strong>
                <span>Eligible does not mean automatically guilty. In Accountability Oversight, the Head separately decides the property/financial resolution and whether the case should count as a confirmed offense.</span>
            </div>
        </div>
    </article>

    <div class="sanction-rule-grid">
        @foreach([1 => '1st Offense', 2 => '2nd Offense', 3 => '3rd Offense'] as $offenseNo => $offenseLabel)
            @php
                $rule = $sanctionRules->get($offenseNo);
                $defaultCode = $rule?->sanction_code ?: match($offenseNo) {
                    1 => 'WRITTEN_REPRIMAND',
                    default => 'BORROWING_SUSPENSION',
                };
                $defaultLabel = $rule?->sanction_label ?: match($offenseNo) {
                    1 => 'Written Reprimand',
                    2 => '1-Month Borrowing Suspension',
                    default => 'Borrowing Suspension Until End of Current Semester',
                };
                $durationMode = $rule?->duration_mode ?: match($offenseNo) {
                    1 => 'NONE',
                    2 => 'MONTHS',
                    default => 'UNTIL_ACADEMIC_PERIOD_END',
                };
                $durationValue = $rule?->duration_value ?: ($offenseNo === 2 ? 1 : null);
            @endphp
            <form method="post" action="{{ route('policies.sanctions.update', $offenseNo) }}" class="card sanction-rule-card">
                @csrf @method('PUT')

                <h3 class="sanction-rule-title">
                    <span class="sanction-rule-badge" aria-hidden="true">{{ $offenseNo }}</span>
                    {{ $offenseLabel }}
                </h3>

                <label>Default action
                    <select name="sanction_code" required>
                        @foreach(['NOTICE'=>'Notice','WRITTEN_REPRIMAND'=>'Written Reprimand','BORROWING_SUSPENSION'=>'Borrowing Suspension','OTHER'=>'Other'] as $code=>$label)
                            <option value="{{ $code }}" @selected($defaultCode === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label>Duration
                    <select name="duration_mode" required data-duration-mode>
                        <option value="NONE" @selected($durationMode === 'NONE')>No borrowing suspension</option>
                        <option value="MONTHS" @selected($durationMode === 'MONTHS')>Fixed number of months</option>
                        <option value="UNTIL_ACADEMIC_PERIOD_END" @selected($durationMode === 'UNTIL_ACADEMIC_PERIOD_END')>Until end of current semester</option>
                        <option value="MANUAL_DATE" @selected($durationMode === 'MANUAL_DATE')>Head sets end date per case</option>
                    </select>
                </label>

                <label data-duration-months @if($durationMode !== 'MONTHS') hidden @endif>Months
                    <input type="number" name="duration_value" min="1" max="24" value="{{ $durationValue }}" placeholder="Number of months">
                </label>

                <label>Display label
                    <input name="sanction_label" value="{{ $defaultLabel }}" maxlength="255" required>
                </label>

                <button class="button secondary ui-pressable sanction-rule-save" type="submit">
                    <x-icon name="save" size="17" />
                    Save {{ $offenseLabel }}
                </button>
            </form>
        @endforeach
    </div>

    <p class="sanction-rules-note">
        <x-icon name="warning" size="18" />
        <span><strong>Note:</strong> These defaults are applied when the Head confirms a violation in Accountability Oversight. You can update the values at any time.</span>
    </p>
</section>
@endif

<style>
.operational-config-heading{align-items:flex-end}.operational-config-heading .button{flex:0 0 auto}
.operational-config-section-heading .eyebrow{color:var(--interactive)}
.operational-config-hub{--config-blue:#0f62d6}
.operational-config-group{display:grid;gap:12px;margin-bottom:26px}
.operational-config-group-title{margin:0;color:var(--config-blue);font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.operational-config-grid{display:grid;gap:14px}.operational-config-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.operational-config-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.operational-config-card{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:16px;min-height:86px;padding:18px 20px;border:1px solid var(--border);border-radius:10px;background:var(--surface-elevated);box-shadow:var(--shadow-sm);color:inherit;text-decoration:none;transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease}
.operational-config-card-icon{display:grid;place-items:center;flex-shrink:0;color:var(--config-blue)}
.operational-config-card-text{display:grid;gap:4px;min-width:0}
.operational-config-card-text strong{color:var(--heading);font-size:15px;font-weight:700;line-height:1.35}
.operational-config-card-text>span{color:var(--text-muted);font-size:12.5px;line-height:1.45}
.operational-config-card-chevron{flex-shrink:0;color:var(--text-secondary);transition:color .16s ease,transform .16s ease}
.operational-config-card:hover,.operational-config-card:focus-visible{border-color:var(--config-blue);transform:translateY(-1px);box-shadow:0 8px 18px rgba(15,55,95,.09)}
.operational-config-card:hover .operational-config-card-chevron,.operational-config-card:focus-visible .operational-config-card-chevron{color:var(--config-blue);transform:translateX(2px)}
.operational-config-footnote{display:flex;align-items:center;gap:11px;margin-top:4px;padding:14px 16px;border:1px solid var(--border);border-radius:10px;background:var(--surface-elevated);color:var(--text-secondary);font-size:12.5px}
.operational-config-footnote>.ui-icon{flex-shrink:0;color:var(--text-muted)}
html[data-theme="dark"] .operational-config-hub{--config-blue:#72b7f4}
@media(prefers-reduced-motion:reduce){.operational-config-card,.operational-config-card-chevron{transition:none}}
.schedule-check{display:flex!important;align-items:center;gap:7px!important;min-height:38px;margin:0!important;padding:8px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);font-size:11px!important;color:var(--text)!important}.schedule-check input[type=checkbox]{width:16px;height:16px;margin:0}
.special-date-form{display:grid;grid-template-columns:1fr 1fr .8fr .8fr;gap:12px;align-items:end}.special-date-form label{margin:0}.special-date-capabilities{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.special-date-reason{grid-column:1/-1}.special-date-form>.button{justify-self:start}
.table-wrap td small{display:block;margin-top:3px;color:var(--muted)}
@media(max-width:1180px){.special-date-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:980px){.operational-config-grid-2,.operational-config-grid-3{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.operational-config-heading{align-items:flex-start}.operational-config-grid-2,.operational-config-grid-3{grid-template-columns:1fr}.special-date-form{grid-template-columns:1fr}.special-date-capabilities,.special-date-reason{grid-column:auto}}
</style>

@if($configurationSection === 'sanction-rules')
<script>
    document.querySelectorAll('.sanction-rule-card').forEach(function (form) {
        const duration = form.querySelector('[data-duration-mode]');
        const months = form.querySelector('[data-duration-months]');
        if (!duration || !months) return;

        const syncMonths = function () {
            const showMonths = duration.value === 'MONTHS';
            months.hidden = !showMonths;
            const input = months.querySelector('input');
            if (input) {
                input.required = showMonths;
                if (!showMonths) input.value = '';
            }
        };

        duration.addEventListener('change', syncMonths);
        syncMonths();
    });
</script>
@endif

@endsection
