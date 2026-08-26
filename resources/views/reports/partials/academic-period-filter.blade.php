@php
    $periodSelectId = $periodSelectId ?? 'report-period';
    $periodNames = [
        'week' => 'This Week',
        'month' => 'This Month',
        'semester' => 'Current Semester',
        'academic_year' => 'Current Academic Year',
    ];
@endphp

<label class="report-period-field">
    <span>Reporting Period</span>
    <select id="{{ $periodSelectId }}" name="academic_period">
        @foreach($periodNames as $value => $label)
            <option value="{{ $value }}" @selected($periodSelection === $value)>{{ $label }}</option>
        @endforeach
    </select>
</label>

<div class="report-period-context">
    <span class="report-period-context-badge">{{ $periodNames[$periodSelection] ?? 'Reporting Period' }}</span>
    <span>
        <strong>{{ $from->format('d M Y') }}</strong> – <strong>{{ $to->format('d M Y') }}</strong>
        @if(in_array($periodSelection, ['semester','academic_year'], true) && !$activeAcademicPeriod)
            · No active academic period is configured; current month is used.
        @elseif($periodSelection === 'semester' && $activeAcademicPeriod)
            · {{ $activeAcademicPeriod->academic_year }} · {{ $activeAcademicPeriod->term_name }}
        @elseif($periodSelection === 'academic_year' && $activeAcademicPeriod)
            · {{ $activeAcademicPeriod->academic_year }}
        @endif
    </span>
</div>
