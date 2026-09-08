@extends('layouts.app', ['title' => 'Analytics'])

@section('content')

@include('analytics.partials.analytics-styles')

@php
    /* Filters carry across the sub-navigation so switching tab keeps context. */
    $carry = array_filter([
        'academic_period' => $periodSelection,
        'group' => $selectedDivision !== 'all' ? $selectedDivision : null,
        'unit' => $selectedUnit !== 'all' ? $selectedUnit : null,
    ]);
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Borrowing insights</p>
        <h1>Analytics</h1>
        <p>Understand borrowing activity, equipment usage, returns, and future demand.</p>
    </div>
</section>

<section class="content-area">
    <div class="analytics-page">

        <nav class="analytics-tabs" aria-label="Analytics sections">
            @foreach($sections as $key => $label)
                <a
                    class="analytics-tab{{ $section === $key ? ' is-active' : '' }}"
                    href="{{ route('analytics.index', $carry + ['section' => $key]) }}"
                    @if($section === $key) aria-current="page" @endif
                >{{ $label }}</a>
            @endforeach
        </nav>

        {{--
            Filters. Every control below re-runs the calculations on the
            server; nothing here is cosmetic.
        --}}
        <form class="analytics-filters" method="get" aria-label="Analytics filters">
            <input type="hidden" name="section" value="{{ $section }}">

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

        {{--
            A month of data labelled as a semester would be a false reading,
            not a small one, so the fallback is stated rather than hidden.
        --}}
        @if($academicPeriodMissing)
            <p class="analytics-notice" role="status">
                <x-icon name="warning" size="17" />
                <span>
                    No academic period is configured, so semester and academic-year analytics cannot be
                    calculated yet. The figures below cover
                    {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} instead.
                    Configure academic periods in Operational Configuration to enable them.
                </span>
            </p>
        @endif


        @include('analytics.partials.'.$sectionPartial)

        {{-- The detail opens over the section that produced the figure. --}}
        @if($detail)
            @include('analytics.partials.detail-panel')
        @endif
    </div>
</section>
@endsection
