@php
    /*
    | The report builder.
    |
    | Visible by default: Report Type, Reporting Period, the resolved dates,
    | More Filters, and Generate Report. Nothing else. Filters are
    | report-specific and come from the catalogue, so a report never renders a
    | control that does not apply to it.
    */
    use App\Reports\ReportCatalogue;
    use App\Reports\ReportFilters;

    $reportFilterDefinitions = ReportCatalogue::filtersFor($selectedReport);
    $appliedFilterValues = $reportFilters?->all() ?? [];
    $selectedDivision = $appliedFilterValues['division'] ?? null;
    $hasAppliedFilters = count($appliedFilterValues) > 0;

    $periodNames = [
        'week' => 'This Week',
        'month' => 'This Month',
        'semester' => 'Current Semester',
        'academic_year' => 'Current Academic Year',
    ];
@endphp

<section class="content-area">
    <form method="get" class="card report-builder-card" aria-labelledby="report-builder-heading">
        <p class="eyebrow" id="report-builder-heading">Report builder</p>

        <div class="report-builder-primary">
            <label class="report-builder-field" for="report-type">
                <span>Report Type</span>
                <select id="report-type" name="report" data-report-type>
                    @foreach($reportGroups as $group => $reports)
                        <optgroup label="{{ $group }}">
                            @foreach($reports as $key => $label)
                                <option value="{{ $key }}" @selected($selectedReport === $key)>{{ $label }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </label>

            <label class="report-builder-field" for="reports-academic-period">
                <span>Reporting Period</span>
                <select id="reports-academic-period" name="academic_period">
                    @foreach($periodNames as $value => $label)
                        <option value="{{ $value }}" @selected($periodSelection === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <div class="report-builder-field report-resolved-period">
                <span>Resolved Period</span>
                <p>
                    <x-icon name="calendar" size="15" />
                    <strong>{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</strong>
                </p>
                @if(in_array($periodSelection, ['semester', 'academic_year'], true) && ! $activeAcademicPeriod)
                    <small>No active academic period is configured; the current month is used.</small>
                @elseif($periodSelection === 'semester' && $activeAcademicPeriod)
                    <small>{{ $activeAcademicPeriod->academic_year }} · {{ $activeAcademicPeriod->term_name }}</small>
                @elseif($periodSelection === 'academic_year' && $activeAcademicPeriod)
                    <small>{{ $activeAcademicPeriod->academic_year }}</small>
                @endif
            </div>
        </div>

        <div class="report-builder-actions">
            @if(count($reportFilterDefinitions) > 0)
                <details class="report-more-filters" @if($hasAppliedFilters) open @endif>
                    <summary>
                        <x-icon name="settings" size="15" />
                        <span>More Filters</span>
                        @if($hasAppliedFilters)
                            <span class="report-filter-count">{{ count($appliedFilterValues) }}</span>
                        @endif
                    </summary>

                    <div class="report-more-filters-body">
                        <div class="report-filter-grid">
                            @foreach($reportFilterDefinitions as $filterKey => $definition)
                                @php
                                    $options = ReportFilters::optionsFor($definition, $selectedDivision);
                                    $current = $appliedFilterValues[$filterKey] ?? '';
                                @endphp

                                <label class="report-builder-field" for="report-filter-{{ $filterKey }}">
                                    <span>{{ $definition['label'] }}</span>
                                    <select
                                        id="report-filter-{{ $filterKey }}"
                                        name="{{ $filterKey }}"
                                        @if($filterKey === 'division') data-filter-division @endif
                                        @if(($definition['depends_on'] ?? null) === 'division') data-filter-depends-on-division @endif
                                    >
                                        <option value="">{{ $definition['placeholder'] ?? 'All' }}</option>
                                        @foreach($options as $value => $label)
                                            <option
                                                value="{{ $value }}"
                                                @selected((string) $current === (string) $value)
                                                @if(($definition['depends_on'] ?? null) === 'division')
                                                    data-division="{{ App\Support\OrganizationalStructure::divisionAndUnitFor($label)[0] }}"
                                                @endif
                                            >{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>

                        <div class="report-more-filters-actions">
                            <a
                                class="button secondary small"
                                href="{{ route('reports.index', ['report' => $selectedReport, 'academic_period' => $periodSelection]) }}"
                            >Clear filters</a>
                        </div>
                    </div>
                </details>
            @else
                <span class="report-no-filters">This report has no additional filters.</span>
            @endif

            <button class="button primary report-generate-button" type="submit">
                <x-icon name="report-document" size="17" />
                Generate Report
            </button>
        </div>

        <p class="report-description">{{ $selectedReportMeta['description'] }}</p>
    </form>
</section>

<script>
(() => {
    const reportType = document.querySelector('[data-report-type]');
    const division = document.querySelector('[data-filter-division]');
    const dependent = document.querySelector('[data-filter-depends-on-division]');

    /*
     * Changing the report reloads the builder, because each report declares
     * its own filters and the server is the authority on which apply.
     */
    reportType?.addEventListener('change', () => reportType.form?.submit());

    /*
     * Division narrows Unit in the browser for responsiveness. The server
     * still rejects a unit that does not belong to the chosen division, so
     * this is convenience, not validation.
     */
    if (division && dependent) {
        const options = Array.from(dependent.options);

        const applyDivision = () => {
            const selected = division.value;
            let currentStillValid = false;

            options.forEach((option) => {
                if (!option.value) {
                    return;
                }

                const matches = !selected || option.dataset.division === selected;
                option.hidden = !matches;
                option.disabled = !matches;

                if (matches && option.value === dependent.value) {
                    currentStillValid = true;
                }
            });

            if (!currentStillValid) {
                dependent.value = '';
            }
        };

        division.addEventListener('change', applyDivision);
        applyDivision();
    }
})();
</script>
