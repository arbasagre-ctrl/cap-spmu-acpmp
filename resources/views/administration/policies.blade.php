@extends('layouts.app', ['title' => 'Operational Configuration'])

@section('content')

@php
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

<section class="page-heading">
    <div>
        <p class="eyebrow">
            SPMU Head configuration
        </p>

        <h1>Operational Configuration</h1>

        <p>Manage SPMU policy settings, controlled document template versions, academic periods, and administrative sanction rules. User account administration remains under ICTU.</p>
    </div>
</section>

<section class="content-area">
    <div class="operational-config-grid">
        <a class="card operational-config-card ui-pressable" href="#academic-periods"><x-icon name="calendar" size="19" /><strong>Academic Period</strong><span>Semester and academic-year configuration</span></a>
        <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index') }}#setting-daily_overdue_tariff"><x-icon name="clock" size="19" /><strong>Late Return Fee</strong><span>Daily late-return assessment</span></a>
        <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index') }}#template-billing-statement"><x-icon name="requests" size="19" /><strong>Billing Statement Template</strong><span>Upload, version and activate approved template</span></a>
        <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index') }}#template-laundry-form"><x-icon name="custody" size="19" /><strong>Laundry Form Template</strong><span>Upload, version and activate approved template</span></a>
        <a class="card operational-config-card ui-pressable" href="{{ route('administration.settings.index') }}#template-gate-pass"><x-icon name="document" size="19" /><strong>Gate Pass Template</strong><span>Upload, version and activate approved template</span></a>
        <a class="card operational-config-card ui-pressable" href="#sanction-rules"><x-icon name="accountability" size="19" /><strong>Sanction Rules</strong><span>1st, 2nd and 3rd offense defaults</span></a>
        <div class="card operational-config-card operational-config-card-locked" aria-disabled="true"><x-icon name="lock" size="19" /><strong>User Account Management</strong><span>Managed by ICTU · SPMU has no account-edit access</span></div>
    </div>
</section>

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

    <section class="content-area" id="sanction-rules">
        <article class="card">
            <div class="card-header">
                <div><p class="eyebrow">Administrative accountability</p><h2>Sanction Rules</h2><p class="meta">Set the default administrative action for the 1st, 2nd, and 3rd confirmed offense within the applicable academic period. The SPMU Head still records the final case decision.</p></div>
            </div>
            <div class="sanction-rule-grid">
                @foreach([1 => '1st Offense', 2 => '2nd Offense', 3 => '3rd Offense'] as $offenseNo => $offenseLabel)
                    @php
                        $rule = $sanctionRules->get($offenseNo);
                        $defaultCode = $rule?->sanction_code ?: match($offenseNo) {1 => 'NOTICE', 2 => 'WRITTEN_REPRIMAND', default => 'BORROWING_SUSPENSION'};
                        $defaultLabel = $rule?->sanction_label ?: match($offenseNo) {1 => 'Notice', 2 => 'Written Reprimand', default => 'Borrowing Suspension'};
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
                        <label>Display label<input name="sanction_label" value="{{ $defaultLabel }}" maxlength="255" required></label>
                        <button class="button secondary small ui-pressable" type="submit">Save {{ $offenseLabel }}</button>
                    </form>
                @endforeach
            </div>
        </article>
    </section>
</div>

<style>
.operational-config-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.operational-config-card{display:grid;gap:6px;min-height:118px;padding:15px;text-decoration:none;color:inherit;align-content:start}
.operational-config-card strong{font-size:14px}.operational-config-card span{font-size:12px;line-height:1.4;color:var(--muted)}
.operational-config-card-locked{opacity:.72;background:var(--surface-subtle);cursor:not-allowed}
.sanction-rule-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.sanction-rule-card{display:grid;gap:10px;padding:14px;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface-subtle)}
.sanction-rule-card label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
@media(max-width:980px){.operational-config-grid{grid-template-columns:repeat(2,1fr)}.sanction-rule-grid{grid-template-columns:1fr}}
@media(max-width:600px){.operational-config-grid{grid-template-columns:1fr}}
</style>

@endsection
