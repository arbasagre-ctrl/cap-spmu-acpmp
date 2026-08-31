@php
    $periodSelectId = $periodSelectId ?? 'report-period';
    $periodNames = ['week' => 'This Week', 'month' => 'This Month', 'semester' => 'Current Semester', 'academic_year' => 'Current Academic Year'];
@endphp
<label class="report-period-field" for="{{ $periodSelectId }}">
    <span>Reporting Period</span>
    <span class="report-period-control">
        <x-icon name="calendar" size="16" />
        <select id="{{ $periodSelectId }}" name="academic_period">
            @foreach($periodNames as $value => $label)
                <option value="{{ $value }}" @selected($periodSelection === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </span>
</label>
<div class="report-period-context">
    <x-icon name="calendar" size="16" />
    <div><strong>{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</strong>
        @if(in_array($periodSelection, ['semester', 'academic_year'], true) && !$activeAcademicPeriod)
            <small>No active academic period is configured; current month is used.</small>
        @elseif($periodSelection === 'semester' && $activeAcademicPeriod)
            <small>{{ $activeAcademicPeriod->academic_year }} · {{ $activeAcademicPeriod->term_name }}</small>
        @elseif($periodSelection === 'academic_year' && $activeAcademicPeriod)
            <small>{{ $activeAcademicPeriod->academic_year }}</small>
        @endif
    </div>
</div>
