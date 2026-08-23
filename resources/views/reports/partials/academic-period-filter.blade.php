@php
    $periodSelectId = $periodSelectId ?? 'report-academic-period';
    $showPeriodContext = $showPeriodContext ?? true;

    $periodStatusLabel = function ($period): string {
        if ($period->status === 'ACTIVE') {
            return 'Active';
        }

        if ($period->end_date && $period->end_date->copy()->endOfDay()->isPast()) {
            return 'Completed';
        }

        return 'Upcoming';
    };
@endphp

<style>
    .report-period-field { min-width: 0; }
    .report-period-field select,
    .report-period-field input { width: 100%; }

    .report-period-context {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 8px;
        color: var(--text-muted, #64748b);
        font-size: 11px;
        line-height: 1.4;
    }

    .report-period-context strong { color: var(--text, #18324a); }

    .report-period-context-badge {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 3px 8px;
        border: 1px solid var(--border, #d7e0ea);
        border-radius: 999px;
        background: var(--surface-subtle, #f7f9fc);
        color: var(--text-muted, #64748b);
        font-size: 10px;
        font-weight: 800;
    }

    .report-period-context-badge.is-active {
        border-color: #b7dfca;
        background: #edf9f2;
        color: #157347;
    }

    .report-period-date-input.is-period-locked {
        background: var(--surface-subtle, #f7f9fc);
        color: var(--text-muted, #64748b);
        cursor: default;
    }
</style>

<label class="report-period-field">
    <span>Academic Period</span>

    <select
        id="{{ $periodSelectId }}"
        name="academic_period"
        data-report-period-select
    >
        @if($activeAcademicPeriod)
            <option
                value="{{ $activeAcademicPeriod->id }}"
                data-from="{{ $activeAcademicPeriod->start_date->toDateString() }}"
                data-to="{{ $activeAcademicPeriod->end_date->toDateString() }}"
                data-label="{{ $activeAcademicPeriod->academic_year }} · {{ $activeAcademicPeriod->term_name }}"
                @selected((string) $periodSelection === (string) $activeAcademicPeriod->id)
            >
                Current: {{ $activeAcademicPeriod->academic_year }}
                · {{ $activeAcademicPeriod->term_name }}
            </option>
        @endif

        @foreach($academicPeriods as $period)
            @if(! $activeAcademicPeriod || $period->id !== $activeAcademicPeriod->id)
                <option
                    value="{{ $period->id }}"
                    data-from="{{ $period->start_date->toDateString() }}"
                    data-to="{{ $period->end_date->toDateString() }}"
                    data-label="{{ $period->academic_year }} · {{ $period->term_name }}"
                    @selected((string) $periodSelection === (string) $period->id)
                >
                    {{ $period->academic_year }}
                    · {{ $period->term_name }}
                    — {{ $periodStatusLabel($period) }}
                </option>
            @endif
        @endforeach

        <option value="custom" @selected($periodSelection === 'custom')>
            Custom Date Range
        </option>
    </select>
</label>

<label class="report-period-field">
    <span>From</span>
    <input
        class="report-period-date-input"
        type="date"
        name="from"
        value="{{ $from->toDateString() }}"
        data-report-period-from
    >
</label>

<label class="report-period-field">
    <span>To</span>
    <input
        class="report-period-date-input"
        type="date"
        name="to"
        value="{{ $to->toDateString() }}"
        data-report-period-to
    >
</label>

@if($showPeriodContext)
    <div class="report-period-context" data-report-period-context>
        @if($selectedAcademicPeriod)
            <span
                class="report-period-context-badge {{ $selectedAcademicPeriod->status === 'ACTIVE' ? 'is-active' : '' }}"
            >
                {{ $periodStatusLabel($selectedAcademicPeriod) }}
            </span>

            <span>
                <strong>
                    {{ $selectedAcademicPeriod->academic_year }}
                    · {{ $selectedAcademicPeriod->term_name }}
                </strong>
                ·
                {{ $selectedAcademicPeriod->start_date->format('d M Y') }}
                –
                {{ $selectedAcademicPeriod->end_date->format('d M Y') }}
            </span>
        @else
            <span class="report-period-context-badge">Custom</span>
            <span>Using a manually selected date range.</span>
        @endif
    </div>
@endif

@once
<script>
(() => {
    const initAcademicPeriodFilters = () => {
        document.querySelectorAll('[data-report-period-select]').forEach((select) => {
            const form = select.closest('form');
            if (!form) return;

            const from = form.querySelector('[data-report-period-from]');
            const to = form.querySelector('[data-report-period-to]');
            const context = form.querySelector('[data-report-period-context]');

            const sync = () => {
                const option = select.options[select.selectedIndex];
                const custom = select.value === 'custom';

                if (!custom) {
                    if (from && option?.dataset.from) from.value = option.dataset.from;
                    if (to && option?.dataset.to) to.value = option.dataset.to;
                }

                [from, to].forEach((input) => {
                    if (!input) return;
                    input.readOnly = !custom;
                    input.classList.toggle('is-period-locked', !custom);
                });

                if (context) {
                    if (custom) {
                        context.innerHTML =
                            '<span class="report-period-context-badge">Custom</span>'
                            + '<span>Using a manually selected date range.</span>';
                    } else {
                        const label = option?.dataset.label || 'Academic period';
                        context.innerHTML =
                            '<span class="report-period-context-badge">Academic period</span>'
                            + '<span><strong>' + label
                            + '</strong> · Dates are set from Operational Configuration.</span>';
                    }
                }
            };

            select.addEventListener('change', sync);
            sync();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAcademicPeriodFilters, { once: true });
    } else {
        initAcademicPeriodFilters();
    }
})();
</script>
@endonce
