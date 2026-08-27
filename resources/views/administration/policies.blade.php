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

<section class="page-heading operational-config-heading">
    <div>
        <p class="eyebrow">{{ $activeSectionHeading[0] ?? 'SPMU Head configuration' }}</p>
        <h1>{{ $activeSectionHeading[1] ?? 'Operational Configuration' }}</h1>
        <p>{{ $activeSectionHeading[2] ?? 'Choose the area you need to configure. Each operational setting opens in its own focused workspace so you do not have to search through one long page.' }}</p>
    </div>

    @if($configurationSection)
        <a class="button secondary ui-pressable" href="{{ route('policies.index') }}">Back to Operational Configuration</a>
    @endif
</section>

@if(!$configurationSection)
<section class="content-area">
    <div class="operational-config-group">
        <div class="section-heading compact-section-heading">
            <div>
                <p class="eyebrow">Operations</p>
                <h2>Schedule &amp; availability</h2>
            </div>
        </div>
        <div class="operational-config-grid operational-config-grid-2">
            <a class="card operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'transaction-schedule']) }}">
                <x-icon name="calendar" size="20" />
                <strong>Transaction Schedule</strong>
                <span>Weekly request, pickup/release, and return availability</span>
            </a>
            <a class="card operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'special-dates']) }}">
                <x-icon name="clock" size="20" />
                <strong>Special Dates &amp; Closures</strong>
                <span>Typhoons, holidays, closures, and special working days</span>
            </a>
        </div>
    </div>

    <div class="operational-config-group">
        <div class="section-heading compact-section-heading">
            <div>
                <p class="eyebrow">Academic &amp; accountability</p>
                <h2>Policies</h2>
            </div>
        </div>
        <div class="operational-config-grid operational-config-grid-3">
            <a class="card operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'academic-periods']) }}">
                <x-icon name="calendar" size="20" />
                <strong>Academic Period</strong>
                <span>Semester and academic-year configuration</span>
            </a>
            <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'late-return-fee']) }}">
                <x-icon name="clock" size="20" />
                <strong>Late Return Policy</strong>
                <span>Effective return deadline and late-return financial assessment</span>
            </a>
            <a class="card operational-config-card ui-pressable" href="{{ route('policies.index', ['section' => 'sanction-rules']) }}">
                <x-icon name="accountability" size="20" />
                <strong>Sanction Rules</strong>
                <span>1st, 2nd, and 3rd confirmed offense defaults</span>
            </a>
        </div>
    </div>

    <div class="operational-config-group">
        <div class="section-heading compact-section-heading">
            <div>
                <p class="eyebrow">Controlled documents</p>
                <h2>Document templates</h2>
            </div>
        </div>
        <div class="operational-config-grid operational-config-grid-3">
            <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-billing-statement']) }}">
                <x-icon name="requests" size="20" />
                <strong>Billing Statement Template</strong>
                <span>Upload, version, and activate the approved billing template</span>
            </a>
            <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-laundry-form']) }}">
                <x-icon name="custody" size="20" />
                <strong>Laundry Form Template</strong>
                <span>Upload, version, and activate the approved laundry form</span>
            </a>
            <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index', ['section' => 'template-gate-pass']) }}">
                <x-icon name="document" size="20" />
                <strong>Gate Pass Template</strong>
                <span>Upload, version, and activate the approved gate pass</span>
            </a>
        </div>
    </div>

    <div class="operational-config-footnote">
        <x-icon name="lock" size="17" />
        <span>User account and access administration remains under ICTU.</span>
    </div>
</section>
@endif

@if($configurationSection === 'transaction-schedule')
<section class="content-area" id="transaction-schedule">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Operational calendar</p>
                <h2>Weekly Transaction Schedule</h2>
                <p class="meta">Set which weekdays accept request submissions, pickup/release transactions, and physical returns. Saturday and Sunday are closed by default but may be opened when the institution declares a working day.</p>
            </div>
        </div>

        <div class="operational-policy-note">
            <strong>Return protection:</strong>
            If an Expected Return Date becomes a closed return day, the system keeps the original date for audit and automatically moves the effective return deadline to the next open SPMU return day. The borrower is not marked late because of an approved closure.
        </div>

        <div class="weekly-schedule-list">
            @foreach($weekdayLabels as $weekday => $weekdayLabel)
                @php
                    $weekly = $weeklySchedules->get($weekday);
                    $isOpen = (bool) ($weekly?->is_open ?? ($weekday <= 5));
                    $acceptsRequests = (bool) ($weekly?->accepts_requests ?? ($weekday <= 5));
                    $allowsPickup = (bool) ($weekly?->allows_pickup ?? ($weekday <= 5));
                    $allowsReturn = (bool) ($weekly?->allows_return ?? ($weekday <= 5));
                @endphp
                <form method="post" action="{{ route('policies.weekly-schedule.update', $weekday) }}" class="weekly-schedule-row">
                    @csrf
                    @method('PUT')
                    <div class="weekly-day-name">
                        <strong>{{ $weekdayLabel }}</strong>
                        <span>{{ $isOpen ? 'Operational day' : 'Closed day' }}</span>
                    </div>

                    <label class="schedule-check">
                        <input type="hidden" name="is_open" value="0">
                        <input type="checkbox" name="is_open" value="1" @checked($isOpen)>
                        <span>Open</span>
                    </label>
                    <label class="schedule-check">
                        <input type="hidden" name="accepts_requests" value="0">
                        <input type="checkbox" name="accepts_requests" value="1" @checked($acceptsRequests)>
                        <span>Requests</span>
                    </label>
                    <label class="schedule-check">
                        <input type="hidden" name="allows_pickup" value="0">
                        <input type="checkbox" name="allows_pickup" value="1" @checked($allowsPickup)>
                        <span>Pickup / Release</span>
                    </label>
                    <label class="schedule-check">
                        <input type="hidden" name="allows_return" value="0">
                        <input type="checkbox" name="allows_return" value="1" @checked($allowsReturn)>
                        <span>Returns</span>
                    </label>
                    <label class="schedule-time">Open time
                        <input type="time" name="open_time" value="{{ $weekly?->open_time ? substr((string) $weekly->open_time, 0, 5) : '' }}">
                    </label>
                    <label class="schedule-time">Close time
                        <input type="time" name="close_time" value="{{ $weekly?->close_time ? substr((string) $weekly->close_time, 0, 5) : '' }}">
                    </label>
                    <button class="button secondary small ui-pressable" type="submit">Save</button>
                </form>
            @endforeach
        </div>
        <p class="meta top-gap">Opening and closing times are optional. Leave both blank when the policy is date-based only. When times are configured, physical pickup/release and return submissions are accepted only inside that operational window.</p>
    </article>
</section>
@endif

@if($configurationSection === 'special-dates')
<section class="content-area" id="special-dates">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Schedule exceptions</p>
                <h2>Special Dates & Closures</h2>
                <p class="meta">Override the normal weekly schedule for holidays, typhoons, emergency campus closures, suspension of work, or an approved special working day.</p>
            </div>
        </div>

        <form method="post" action="{{ route('policies.date-exceptions.store') }}" class="special-date-form">
            @csrf
            <label>Date
                <input type="date" name="exception_date" required>
            </label>
            <label>Status
                <select name="status" required>
                    <option value="CLOSED">Closed</option>
                    <option value="OPEN">Open / Special Working Day</option>
                </select>
            </label>
            <label>Open time
                <input type="time" name="open_time">
            </label>
            <label>Close time
                <input type="time" name="close_time">
            </label>
            <div class="special-date-capabilities">
                <label class="schedule-check"><input type="hidden" name="accepts_requests" value="0"><input type="checkbox" name="accepts_requests" value="1"><span>Accept requests</span></label>
                <label class="schedule-check"><input type="hidden" name="allows_pickup" value="0"><input type="checkbox" name="allows_pickup" value="1"><span>Pickup / Release</span></label>
                <label class="schedule-check"><input type="hidden" name="allows_return" value="0"><input type="checkbox" name="allows_return" value="1"><span>Returns</span></label>
            </div>
            <label class="special-date-reason">Reason
                <input type="text" name="reason" maxlength="500" required placeholder="e.g. Typhoon suspension, institutional holiday, special working Saturday">
            </label>
            <button class="button primary ui-pressable" type="submit">Save Special Date</button>
        </form>

        <div class="table-wrap top-gap">
            <table>
                <thead><tr><th>Date</th><th>Status</th><th>Transactions</th><th>Hours</th><th>Reason</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse($dateExceptions as $exception)
                        <tr>
                            <td><strong>{{ $exception->exception_date->format('d M Y') }}</strong><small>{{ $exception->exception_date->format('l') }}</small></td>
                            <td><x-status-badge :status="$exception->status" :label="$exception->status === 'OPEN' ? 'Open' : 'Closed'" /></td>
                            <td>
                                @if($exception->status === 'CLOSED')
                                    All SPMU transactions closed
                                @else
                                    {{ collect([
                                        $exception->accepts_requests ? 'Requests' : null,
                                        $exception->allows_pickup ? 'Pickup / Release' : null,
                                        $exception->allows_return ? 'Returns' : null,
                                    ])->filter()->join(', ') ?: 'Open day with no enabled transaction type' }}
                                @endif
                            </td>
                            <td>{{ $exception->open_time && $exception->close_time ? substr((string)$exception->open_time,0,5).' – '.substr((string)$exception->close_time,0,5) : 'Date-based' }}</td>
                            <td>{{ $exception->reason }}</td>
                            <td>
                                <form method="post" action="{{ route('policies.date-exceptions.destroy', $exception) }}" onsubmit="return confirm('Remove this operational date exception?')">
                                    @csrf @method('DELETE')
                                    <button class="button secondary small ui-pressable" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">No special operational dates configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif

@if($configurationSection === 'academic-periods')
<div class="academic-period-config" id="academic-periods">

    <section class="content-area">
        <article class="card current-period-card">
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

                <button
                    class="button primary ui-pressable"
                    type="submit"
                >
                    Save Academic Period
                </button>
            </form>
        </article>
    </section>

    <section class="content-area">
        <article class="card">
            <div class="card-header">
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
            </div>

            <div class="table-wrap periods-table">
                <table>
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Semester / Term</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>
                                <span class="visually-hidden">
                                    Action
                                </span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($academicPeriods as $period)
                            @php
                                $status =
                                    $effectiveStatus(
                                        $period
                                    );

                                $canActivate =
                                    $status !== 'ACTIVE'
                                    && $status !== 'COMPLETED';
                            @endphp

                            <tr>
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
                            <tr>
                                <td
                                    colspan="5"
                                    class="empty-state"
                                >
                                    No academic periods configured.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>

</div>
@endif

@if($configurationSection === 'sanction-rules')
<section class="content-area" id="sanction-rules">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">Administrative accountability</p><h2>Sanction Rules</h2><p class="meta">Configure the default consequence for each confirmed offense within the applicable academic period. These rules are connected to Administrative Review: when the Head confirms a violation and leaves the action as the configured default, the system applies the matching 1st/2nd/3rd offense rule and calculates the suspension end date automatically.</p></div>
            </div>
            <div class="offense-application-panel" id="offense-application">
                <div class="offense-application-copy">
                    <p class="eyebrow">Offense application</p>
                    <h3>Which cases may count as an administrative offense?</h3>
                    <p>The system may detect these findings, but it does <strong>not</strong> automatically add an offense to the borrower. The SPMU Head makes the final case-by-case confirmation in Accountability Oversight.</p>
                </div>

                <form method="post" action="{{ route('policies.offense-application.update') }}" class="offense-application-form">
                    @csrf @method('PUT')
                    <div class="offense-type-grid">
                        @foreach([
                            'LATE_RETURN' => ['Late Return', 'Return remained outstanding after the effective open return deadline.'],
                            'DAMAGED' => ['Damaged', 'Returned property recorded with a damaged finding.'],
                            'LOST_MISSING' => ['Lost / Missing', 'Returned accountability records identify missing or lost property.'],
                            'STOLEN' => ['Stolen', 'Property is formally recorded as stolen.'],
                            'DESTROYED' => ['Destroyed', 'Property is formally recorded as destroyed.'],
                        ] as $caseType => [$caseLabel, $caseHelp])
                            <label class="offense-type-option">
                                <input
                                    type="checkbox"
                                    name="case_types[]"
                                    value="{{ $caseType }}"
                                    @checked(in_array($caseType, $offenseApplicationTypes, true))
                                >
                                <span>
                                    <strong>{{ $caseLabel }}</strong>
                                    <small>{{ $caseHelp }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="offense-confirmation-rule">
                        <x-icon name="approval" size="18" />
                        <div>
                            <strong>Final offense confirmation: SPMU Head decision required</strong>
                            <span>Eligible does not mean automatically guilty. In Accountability Oversight, the Head separately decides the property/financial resolution and whether the case should count as a confirmed offense.</span>
                        </div>
                    </div>

                    <button class="button secondary small ui-pressable" type="submit">Save Offense Application</button>
                </form>
            </div>

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
                    <form method="post" action="{{ route('policies.sanctions.update', $offenseNo) }}" class="sanction-rule-card">
                        @csrf @method('PUT')
                        <strong>{{ $offenseLabel }}</strong>
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
                        <label>Display label<input name="sanction_label" value="{{ $defaultLabel }}" maxlength="255" required></label>
                        <div class="sanction-rule-summary">
                            @if($offenseNo === 1)
                                Default: written reprimand; no borrowing suspension.
                            @elseif($offenseNo === 2)
                                Default: one-month borrowing suspension.
                            @else
                                Default: borrowing suspension until the active semester ends.
                            @endif
                        </div>
                        <button class="button secondary small ui-pressable" type="submit">Save {{ $offenseLabel }}</button>
                    </form>
                @endforeach
            </div>
        </article>
</section>
@endif

<style>
.operational-config-heading{align-items:flex-end}.operational-config-heading .button{flex:0 0 auto}
.operational-config-group{display:grid;gap:10px;margin-bottom:22px}.compact-section-heading{margin-bottom:0}.compact-section-heading h2{font-size:18px}
.operational-config-grid{display:grid;gap:12px}.operational-config-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.operational-config-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.operational-config-card{position:relative;display:grid;gap:7px;min-height:128px;padding:17px;text-decoration:none;color:inherit;align-content:start;border-top:3px solid transparent;transition:border-color .16s ease,background .16s ease,transform .16s ease,box-shadow .16s ease}
.operational-config-card strong{font-size:15px}.operational-config-card span{font-size:12px;line-height:1.45;color:var(--muted)}
.operational-config-card:hover,.operational-config-card:focus-visible{border-top-color:var(--primary,#1769e0);background:#f7fbff;transform:translateY(-1px);box-shadow:0 8px 18px rgba(15,55,95,.08)}
.operational-config-footnote{display:flex;align-items:center;gap:9px;margin-top:4px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;background:var(--surface-subtle);color:var(--muted);font-size:12px}
.offense-application-panel{display:grid;grid-template-columns:minmax(220px,.8fr) minmax(0,2.2fr);gap:16px;margin:0 0 16px;padding:15px;border:1px solid #cfe0ef;border-radius:var(--radius);background:#f7fbff}
.offense-application-copy{display:grid;align-content:start;gap:5px}.offense-application-copy h3{margin:0;font-size:16px}.offense-application-copy p{margin:0;color:var(--muted);font-size:11px;line-height:1.5}
.offense-application-form{display:grid;gap:12px}.offense-type-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.offense-type-option{display:flex!important;align-items:flex-start;gap:9px!important;margin:0!important;padding:10px 11px;border:1px solid var(--border);border-radius:9px;background:#fff;color:var(--text)!important;font-weight:400!important}.offense-type-option input{width:17px!important;height:17px;margin:1px 0 0!important;flex:0 0 auto}.offense-type-option span{display:grid;gap:2px}.offense-type-option strong{font-size:12px}.offense-type-option small{color:var(--muted);font-size:10px;line-height:1.4}
.offense-confirmation-rule{display:flex;align-items:flex-start;gap:9px;padding:10px 11px;border-left:4px solid #1d6fb8;border-radius:8px;background:#eef6fd}.offense-confirmation-rule>div{display:grid;gap:2px}.offense-confirmation-rule strong{font-size:11px}.offense-confirmation-rule span{color:var(--muted);font-size:10px;line-height:1.45}
.sanction-rule-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.sanction-rule-card{display:grid;gap:10px;padding:14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface-subtle)}
.sanction-rule-card label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
.sanction-rule-summary{padding:9px 10px;border-radius:9px;background:#eef6ff;color:#36536f;font-size:11px;line-height:1.45}
.operational-policy-note{margin:0 0 14px;padding:12px 14px;border:1px solid #b9d8f4;border-radius:10px;background:#eef7ff;color:#244c70;font-size:12px;line-height:1.55}
.weekly-schedule-list{display:grid;gap:8px}
.weekly-schedule-row{display:grid;grid-template-columns:minmax(130px,1.1fr) repeat(4,minmax(100px,.8fr)) minmax(105px,.8fr) minmax(105px,.8fr) auto;gap:10px;align-items:end;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-subtle)}
.weekly-day-name{display:grid;gap:3px;align-self:center}.weekly-day-name span{font-size:11px;color:var(--muted)}
.schedule-check{display:flex!important;align-items:center;gap:7px!important;min-height:38px;margin:0!important;padding:8px 9px;border:1px solid var(--border);border-radius:8px;background:var(--surface);font-size:11px!important;color:var(--text)!important}.schedule-check input[type=checkbox]{width:16px;height:16px;margin:0}
.schedule-time{display:grid;gap:5px;margin:0!important;font-size:10px!important;color:var(--muted)!important}.schedule-time input{margin:0!important}
.special-date-form{display:grid;grid-template-columns:1fr 1fr .8fr .8fr;gap:12px;align-items:end}.special-date-form label{margin:0}.special-date-capabilities{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.special-date-reason{grid-column:1/-1}.special-date-form>.button{justify-self:start}
.table-wrap td small{display:block;margin-top:3px;color:var(--muted)}
@media(max-width:1180px){.weekly-schedule-row{grid-template-columns:repeat(4,minmax(0,1fr))}.weekly-day-name{grid-column:1/-1}.special-date-form{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:980px){.operational-config-grid-2,.operational-config-grid-3{grid-template-columns:repeat(2,minmax(0,1fr))}.offense-application-panel{grid-template-columns:1fr}.sanction-rule-grid{grid-template-columns:1fr}}
@media(max-width:700px){.operational-config-heading{align-items:flex-start}.operational-config-grid-2,.operational-config-grid-3,.offense-type-grid{grid-template-columns:1fr}.weekly-schedule-row,.special-date-form{grid-template-columns:1fr}.weekly-day-name,.special-date-capabilities,.special-date-reason{grid-column:auto}}
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
