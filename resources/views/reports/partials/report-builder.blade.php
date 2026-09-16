@php
    /*
    | The report builder.
    |
    | Visible by default: Report Type, Reporting Period, the resolved date range,
    | the report-specific filters, and the preview action. Filters stay visible
    | so the operator can understand the report scope without opening a hidden
    | accordion. The catalogue remains the authority on which filters apply.
    */
    use App\Reports\ReportCatalogue;
    use App\Reports\ReportFilters;

    $reportFilterDefinitions = ReportCatalogue::filtersFor($selectedReport);
    $appliedFilterValues = $reportFilters?->all() ?? [];
    $selectedDivision = $appliedFilterValues['division'] ?? null;
    $selectedBorrower = $appliedFilterValues['borrower'] ?? '';
    $selectedBorrowerLabel = 'All borrowers';

    if ($selectedBorrower !== '' && isset($reportFilterDefinitions['borrower'])) {
        $borrowerOptionsForLabel = ReportFilters::optionsFor(
            $reportFilterDefinitions['borrower'],
            $selectedDivision
        );
        $rawBorrowerLabel = $borrowerOptionsForLabel[$selectedBorrower] ?? 'Selected borrower';
        $selectedBorrowerLabel = trim((string) preg_replace('/\s*·\s*.+$/u', '', $rawBorrowerLabel));
    }

    $hasAppliedFilters = count($appliedFilterValues) > 0;
    $clearFiltersUrl = route('reports.index', array_filter([
        'report' => $selectedReport,
        'academic_period' => $periodSelection,
        'generated' => ($showPreview ?? false) ? 1 : null,
    ], static fn ($value) => $value !== null));

    /*
     * Three across is the layout the builder is designed for; a report that
     * declares fewer filters narrows the row rather than leaving a dead
     * column, and one that declares more wraps onto a second line.
     */
    $filterColumns = max(1, min(count($reportFilterDefinitions), 3));

    $periodNames = [
        'week' => 'This Week',
        'month' => 'This Month',
        'semester' => 'Current Semester',
        'academic_year' => 'Current Academic Year',
    ];
@endphp

<section class="content-area">
    <form method="get" class="card report-builder-card" aria-labelledby="report-builder-heading" id="report-builder" data-report-preview-form>
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
                <span>Date Range</span>
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

            {{--
                The empty span keeps the button on the same baseline as the
                controls beside it, which all sit one label-line down.
            --}}
            <div class="report-builder-field report-builder-submit">
                <span aria-hidden="true"></span>
                <button
                    class="button primary report-generate-button"
                    type="submit"
                    name="generated"
                    value="1"
                >
                    <x-icon name="report-document" size="17" />
                    <span data-preview-button-label>Preview Report</span>
                </button>
            </div>
        </div>

        @if(count($reportFilterDefinitions) > 0)
            <section class="report-filters-panel" aria-labelledby="report-filters-heading">
                <div class="report-filters-heading">
                    <div>
                        <p class="report-filters-title" id="report-filters-heading">
                            <x-icon name="settings" size="15" />
                            <span>Filters</span>
                            <span class="report-filter-optional">Optional</span>
                        </p>
                        <p class="report-filters-help">Narrow the report when needed. Leave a filter blank to include all matching records.</p>
                    </div>

                    @if($hasAppliedFilters)
                        <div class="report-filter-actions">
                            <span class="report-filter-count">{{ count($appliedFilterValues) }} applied</span>
                            <a class="report-clear-filters" href="{{ $clearFiltersUrl }}">Clear filters</a>
                        </div>
                    @endif
                </div>

                <div class="report-filter-grid" style="--report-filter-columns: {{ $filterColumns }};">
                    @foreach($reportFilterDefinitions as $filterKey => $definition)
                        @php
                            $current = $appliedFilterValues[$filterKey] ?? '';
                        @endphp

                        @if($filterKey === 'borrower')
                            <div class="report-builder-field report-borrower-field" data-borrower-picker>
                                <span>{{ $definition['label'] }}</span>
                                <input type="hidden" name="borrower" value="{{ $current }}" data-borrower-value>

                                <button
                                    type="button"
                                    class="report-borrower-trigger"
                                    data-borrower-trigger
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                >
                                    <span data-borrower-current>{{ $selectedBorrowerLabel }}</span>
                                    <svg
                                        class="report-borrower-chevron"
                                        width="16"
                                        height="16"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                        focusable="false"
                                    >
                                        <path d="m7 10 5 5 5-5" />
                                    </svg>
                                </button>

                                <div class="report-borrower-menu" data-borrower-menu hidden>
                                    <label class="report-borrower-search-wrap">
                                        <span class="sr-only">Search borrower</span>
                                        <input
                                            type="search"
                                            class="report-borrower-search"
                                            placeholder="Search borrower by name or email..."
                                            autocomplete="off"
                                            data-borrower-search
                                        >
                                    </label>

                                    <div class="report-borrower-options" role="listbox" data-borrower-options>
                                        <button
                                            type="button"
                                            class="report-borrower-option is-selected"
                                            data-borrower-option
                                            data-value=""
                                            data-name="All borrowers"
                                            role="option"
                                            aria-selected="{{ $current === '' ? 'true' : 'false' }}"
                                        >
                                            <strong>All borrowers</strong>
                                        </button>
                                    </div>

                                    <p class="report-borrower-message" data-borrower-message hidden></p>
                                </div>
                            </div>
                        @else
                            @php
                                /*
                                 * Render every Office / Unit option so the browser can
                                 * switch between divisions without a page reload. Server
                                 * validation still enforces the selected Division.
                                 */
                                $options = $filterKey === 'unit'
                                    ? ReportFilters::optionsFor($definition, null)
                                    : ReportFilters::optionsFor($definition, $selectedDivision);
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
                        @endif
                    @endforeach
                </div>
            </section>
        @else
            <p class="report-no-filters">No additional filters are needed for this report.</p>
        @endif

        <p class="report-description">{{ $selectedReportMeta['description'] }}</p>
    </form>
</section>


<style>
/*
 * Borrower stays visually aligned with the native Reports selects, but opens
 * a searchable scoped picker. No press/translate animation is allowed on
 * report filter controls so controls do not jump up/down when clicked.
 */
.report-borrower-field { position: relative; min-width: 0; }

.report-builder-card .report-builder-field select,
.report-builder-card .report-borrower-trigger,
.report-builder-card .report-borrower-search,
.report-builder-card .report-borrower-option {
    transform: none !important;
    translate: none !important;
}

.report-builder-card .report-builder-field select:active,
.report-builder-card .report-borrower-trigger:active,
.report-builder-card .report-borrower-option:active,
.report-builder-card button:active,
.report-filters-panel a:active {
    transform: none !important;
    translate: none !important;
    top: auto !important;
}

.report-borrower-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 52px;
    padding: 0 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: var(--surface-elevated);
    color: var(--heading);
    font: inherit;
    text-align: left;
    cursor: pointer;
    box-shadow: none;
}

.report-borrower-trigger:hover { border-color: var(--border-strong, var(--border)); }

.report-borrower-trigger:hover .report-borrower-chevron,
.report-borrower-trigger:focus .report-borrower-chevron,
.report-borrower-trigger:active .report-borrower-chevron,
.report-borrower-field.is-open .report-borrower-chevron {
    transform: none !important;
    translate: none !important;
}

.report-borrower-trigger:focus-visible,
.report-borrower-field.is-open .report-borrower-trigger {
    outline: 2px solid color-mix(in srgb, var(--primary-action) 28%, transparent);
    outline-offset: 1px;
    border-color: var(--primary-action);
}

.report-borrower-chevron {
    flex: 0 0 16px;
    width: 16px;
    height: 16px;
    display: block;
    pointer-events: none;
    transform: none !important;
    translate: none !important;
}

.report-borrower-menu {
    position: absolute;
    z-index: 80;
    top: calc(100% + 6px);
    left: 0;
    width: 100%;
    min-width: 320px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-elevated);
    box-shadow: 0 12px 30px rgba(15, 35, 58, .16);
    overflow: hidden;
}

.report-borrower-search-wrap {
    display: block;
    margin: 0 0 7px;
}

.report-borrower-search {
    width: 100%;
    min-height: 40px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-elevated);
    color: var(--heading);
    font: inherit;
    font-size: 12.5px;
    box-shadow: none;
}

.report-borrower-options {
    display: block;
    max-height: 280px;
    overflow-y: auto;
    overscroll-behavior: contain;
    border-top: 1px solid var(--border);
}

.report-borrower-menu .report-borrower-option {
    display: flex !important;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    width: 100%;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 10px 8px !important;
    border: 0 !important;
    border-bottom: 1px solid color-mix(in srgb, var(--border) 72%, transparent) !important;
    border-radius: 0 !important;
    background: transparent !important;
    color: var(--heading);
    text-align: left;
    cursor: pointer;
    box-shadow: none !important;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    transform: none !important;
    translate: none !important;
}

.report-borrower-menu .report-borrower-option:last-child {
    border-bottom: 0 !important;
}

.report-borrower-menu .report-borrower-option:hover,
.report-borrower-menu .report-borrower-option:focus-visible {
    background: var(--surface-subtle) !important;
    outline: none;
}

.report-borrower-menu .report-borrower-option.is-selected {
    background: color-mix(in srgb, var(--info-bg) 55%, transparent) !important;
}

.report-borrower-option strong {
    min-width: 0;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.35;
}

.report-borrower-option small {
    flex: 0 1 auto;
    max-width: 55%;
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1.35;
    text-align: right;
    overflow-wrap: anywhere;
}

.report-borrower-message {
    margin: 7px 4px 2px;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.4;
}

.report-borrower-field[aria-busy="true"] .report-borrower-trigger {
    cursor: progress;
}

@media (max-width: 620px) {
    .report-borrower-menu {
        min-width: 0;
        width: 100%;
    }
}


/* BORROWER_SELECT_VISUAL_MATCH_V1
   Make the closed Borrower picker visually match Division / Office selects.
   Search/cascade logic is untouched.
*/
.report-builder-card .report-builder-field select,
.report-builder-card .report-borrower-trigger {
    width: 100% !important;
    height: 52px !important;
    min-height: 52px !important;
    padding: 0 16px !important;
    border: 1px solid var(--border) !important;
    border-radius: 8px !important;
    background: var(--surface-elevated) !important;
    color: var(--heading) !important;
    font: inherit !important;
    font-size: 16px !important;
    font-weight: 400 !important;
    line-height: 1.2 !important;
    box-shadow: none !important;
}

.report-builder-card .report-borrower-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    text-align: left !important;
    cursor: pointer !important;
}

.report-builder-card .report-borrower-current {
    flex: 1 1 auto !important;
    min-width: 0 !important;
    text-align: left !important;
    font-weight: 400 !important;
}

.report-builder-card .report-borrower-chevron {
    flex: 0 0 16px !important;
    width: 16px !important;
    height: 16px !important;
    margin: 0 !important;
    display: block !important;
    pointer-events: none !important;
    transform: none !important;
    translate: none !important;
}

.report-builder-card .report-borrower-trigger:hover,
.report-builder-card .report-borrower-trigger:focus,
.report-builder-card .report-borrower-trigger:active,
.report-builder-card .report-borrower-field.is-open .report-borrower-trigger {
    transform: none !important;
    translate: none !important;
    top: auto !important;
}

.report-builder-card .report-borrower-trigger:hover .report-borrower-chevron,
.report-builder-card .report-borrower-trigger:focus .report-borrower-chevron,
.report-builder-card .report-borrower-trigger:active .report-borrower-chevron,
.report-builder-card .report-borrower-field.is-open .report-borrower-chevron {
    transform: none !important;
    translate: none !important;
}

/* Keep dropdown aligned exactly with the closed Borrower field */
.report-builder-card .report-borrower-menu {
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    min-width: 100% !important;
    box-sizing: border-box !important;
}







/* REPORTS_BUILDER_COMPACT_TEXT_V2
   Match compact typography used across Admin workspaces.
   UI only — no report/filter/cascade logic changes.
*/

/* Main field values */
.report-builder-card select,
.report-builder-card input,
.report-builder-card button,
.report-builder-card .report-borrower-trigger,
.report-builder-card .report-borrower-current {
    font-size: 13px !important;
    line-height: 1.3 !important;
}

/* Labels: Report Type, Reporting Period, Division, Office, etc. */
.report-builder-card .report-builder-field > span,
.report-builder-card label > span,
.report-builder-card .report-builder-label {
    font-size: 11px !important;
    line-height: 1.25 !important;
    font-weight: 600 !important;
}

/* Report Type / Period / Division / Office / Borrower / Status */
.report-builder-card .report-builder-field select,
.report-builder-card .report-borrower-trigger {
    font-size: 13px !important;
    font-weight: 400 !important;
}

/* Borrower searchable dropdown */
.report-builder-card .report-borrower-search {
    font-size: 12px !important;
}

.report-builder-card .report-borrower-option strong {
    font-size: 12px !important;
}

.report-builder-card .report-borrower-option small {
    font-size: 10px !important;
}

/* Date range */
.report-builder-card .report-date-range,
.report-builder-card [data-date-range] {
    font-size: 12px !important;
}

/* Preview Report button */
.report-builder-card .report-generate-button,
.report-builder-card [data-preview-button-label] {
    font-size: 12px !important;
}

/* Filters heading/helper text */
.report-builder-card .report-filters-title,
.report-builder-card .report-filter-title {
    font-size: 12px !important;
}

.report-builder-card .report-filters-description,
.report-builder-card .report-description {
    font-size: 11px !important;
}

/* Optional/applied indicators */
.report-builder-card .badge,
.report-builder-card .report-filter-count {
    font-size: 10px !important;
}



/* REPORTS_FILTER_ROW_AND_CHEVRON_V1
   Layout/visual only. No report/filter/cascade logic changes.
*/

/* Maximum 4 filters per row on desktop */
.report-builder-card .report-filter-grid {
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
    gap: 16px !important;
    align-items: end !important;
}

/* Native report selects: restore a stable visible chevron */
.report-builder-card .report-builder-field select {
    width: 100% !important;
    padding-right: 38px !important;

    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;

    background-image:
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%230f2742' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m7 10 5 5 5-5'/%3E%3C/svg%3E") !important;

    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
    background-size: 14px 14px !important;
}

/* Borrower custom picker arrow matches the native select arrows */
.report-builder-card .report-borrower-chevron {
    width: 14px !important;
    height: 14px !important;
    flex: 0 0 14px !important;
    margin-right: 0 !important;

    transform: none !important;
    translate: none !important;
    rotate: none !important;
}

/* Nothing should jump when clicked/opened */
.report-builder-card .report-builder-field select:active,
.report-builder-card .report-builder-field select:focus,
.report-builder-card .report-borrower-trigger:active,
.report-builder-card .report-borrower-trigger:focus,
.report-builder-card .report-borrower-field.is-open .report-borrower-trigger {
    transform: none !important;
    translate: none !important;
    top: auto !important;
}

/* Tablet */
@media (max-width: 1100px) {
    .report-builder-card .report-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

/* Mobile */
@media (max-width: 650px) {
    .report-builder-card .report-filter-grid {
        grid-template-columns: 1fr !important;
    }
}

</style>

<script>
(() => {
    const reportType = document.querySelector('[data-report-type]');
    const period = document.querySelector('#reports-academic-period');
    const division = document.querySelector('[data-filter-division]');
    const dependent = document.querySelector('[data-filter-depends-on-division]');
    const borrowerPicker = document.querySelector('[data-borrower-picker]');
    const borrowerValue = borrowerPicker?.querySelector('[data-borrower-value]');
    const borrowerTrigger = borrowerPicker?.querySelector('[data-borrower-trigger]');
    const borrowerCurrent = borrowerPicker?.querySelector('[data-borrower-current]');
    const borrowerMenu = borrowerPicker?.querySelector('[data-borrower-menu]');
    const borrowerSearch = borrowerPicker?.querySelector('[data-borrower-search]');
    const borrowerOptions = borrowerPicker?.querySelector('[data-borrower-options]');
    const borrowerMessage = borrowerPicker?.querySelector('[data-borrower-message]');
    const previewForm = document.querySelector('[data-report-preview-form]');
    const previewButton = previewForm?.querySelector('.report-generate-button');
    const previewLabel = previewForm?.querySelector('[data-preview-button-label]');

    reportType?.addEventListener('change', () => reportType.form?.submit());

    previewForm?.addEventListener('submit', (event) => {
        if (previewForm.dataset.previewSubmitting === '1') {
            event.preventDefault();
            return;
        }

        previewForm.dataset.previewSubmitting = '1';

        if (previewButton) {
            /*
             * Keep name="generated" value="1" successful for this GET submit.
             * The dataset flag blocks duplicate submits without disabling the
             * submitter and accidentally dropping the generated parameter.
             */
            previewButton.setAttribute('aria-disabled', 'true');
            previewButton.style.pointerEvents = 'none';
        }

        if (previewLabel) previewLabel.textContent = 'Loading Preview…';
    });

    /*
     * Cascade:
     * Reporting Period -> Division -> Office / Unit -> Borrower.
     *
     * Borrowers are requested from the server using the current upstream
     * scope. Only the first 50 are returned for a broad scope; typing a name
     * or email performs a server-side scoped search instead of rendering
     * hundreds of borrowers at once.
     */
    const unitOptions = dependent ? Array.from(dependent.options) : [];
    let borrowerRequestController = null;
    let borrowerSearchTimer = null;

    const setBorrower = (value = '', name = 'All borrowers') => {
        if (!borrowerValue || !borrowerCurrent) return;

        borrowerValue.value = value;
        borrowerCurrent.textContent = name;

        borrowerOptions?.querySelectorAll('[data-borrower-option]').forEach((option) => {
            const selected = option.dataset.value === value;
            option.classList.toggle('is-selected', selected);
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
    };

    const closeBorrowerPicker = () => {
        if (borrowerMenu) borrowerMenu.hidden = true;
        borrowerPicker?.classList.remove('is-open');
        borrowerTrigger?.setAttribute('aria-expanded', 'false');
    };

    const applyDivision = () => {
        if (!division || !dependent) return;

        const selectedDivision = division.value;
        let currentStillValid = dependent.value === '';

        unitOptions.forEach((option) => {
            if (!option.value) return;

            const matches = !selectedDivision || option.dataset.division === selectedDivision;
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

    const borrowerScopeUrl = (query = '') => {
        if (!previewForm) return null;

        const params = new URLSearchParams();
        params.set('borrower_options', '1');
        params.set('report', reportType?.value || 'borrowing');
        params.set('academic_period', period?.value || 'month');

        if (division?.value) params.set('division', division.value);
        if (dependent?.value) params.set('unit', dependent.value);
        if (query.trim() !== '') params.set('q', query.trim());
        if (borrowerValue?.value) params.set('selected_borrower', borrowerValue.value);

        /*
         * Do not concatenate "?..." onto previewForm.action.
         * When a form has no explicit action, HTMLFormElement.action may resolve
         * to the current document URL including its existing query string.
         * Appending another "?" makes borrower_options part of the old query
         * value instead of a real query parameter, so Laravel returns the full
         * HTML report page and response.json() fails.
         *
         * Build a fresh URL from the form's explicit action (if any) or the
         * current pathname, then replace its search parameters atomically.
         */
        const endpoint = new URL(
            previewForm.getAttribute('action') || window.location.pathname,
            window.location.origin
        );
        endpoint.search = params.toString();

        return endpoint.toString();
    };

    const makeBorrowerOption = (item) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'report-borrower-option';
        option.dataset.borrowerOption = '';
        option.dataset.value = String(item.value ?? '');
        option.dataset.name = item.name || 'Borrower';
        option.setAttribute('role', 'option');

        const name = document.createElement('strong');
        name.textContent = item.name || 'Borrower';
        option.appendChild(name);

        if (item.email) {
            const email = document.createElement('small');
            email.textContent = item.email;
            option.appendChild(email);
        }

        return option;
    };

    const renderBorrowers = (
        items,
        payload = {},
        { validateSelection = true, query = '' } = {}
    ) => {
        if (!borrowerOptions) return;

        borrowerOptions.innerHTML = '';

        const all = document.createElement('button');
        all.type = 'button';
        all.className = 'report-borrower-option';
        all.dataset.borrowerOption = '';
        all.dataset.value = '';
        all.dataset.name = 'All borrowers';
        all.setAttribute('role', 'option');

        const allLabel = document.createElement('strong');
        allLabel.textContent = 'All borrowers';
        all.appendChild(allLabel);
        borrowerOptions.appendChild(all);

        items.forEach((item) => borrowerOptions.appendChild(makeBorrowerOption(item)));

        const selected = borrowerValue?.value || '';
        const selectedOption = Array.from(
            borrowerOptions.querySelectorAll('[data-borrower-option]')
        ).find((option) => option.dataset.value === selected);

        if (validateSelection && selected !== '' && !selectedOption) {
            /*
             * Upstream scope changed and the selected borrower no longer
             * exists in that scope. Reset immediately to All borrowers.
             */
            setBorrower('', 'All borrowers');
        } else {
            setBorrower(
                selected,
                selectedOption?.dataset.name
                    || borrowerCurrent?.textContent
                    || 'All borrowers'
            );
        }

        if (!borrowerMessage) return;

        const trimmedQuery = query.trim();

        if (items.length === 0 && trimmedQuery !== '') {
            borrowerMessage.textContent = 'No borrower matches this search in the current report scope.';
            borrowerMessage.hidden = false;
            return;
        }

        if (items.length === 0 && trimmedQuery === '') {
            borrowerMessage.textContent = 'No borrowers are available in the current report scope.';
            borrowerMessage.hidden = false;
            return;
        }

        if (payload.has_more) {
            borrowerMessage.textContent = `Showing the first ${payload.shown || items.length} of ${payload.count} matching borrowers. Search by name or email to find more.`;
            borrowerMessage.hidden = false;
            return;
        }

        borrowerMessage.hidden = true;
        borrowerMessage.textContent = '';
    };

    const refreshBorrowers = async ({
        open = false,
        query = '',
        validateSelection = true,
    } = {}) => {
        if (!borrowerPicker) return;

        const url = borrowerScopeUrl(query);
        if (!url) return;

        borrowerRequestController?.abort();
        borrowerRequestController = new AbortController();
        borrowerPicker.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                signal: borrowerRequestController.signal,
            });

            if (!response.ok) {
                throw new Error(`Borrower scope request failed (${response.status})`);
            }

            const payload = await response.json();

            renderBorrowers(
                Array.isArray(payload.options) ? payload.options : [],
                payload,
                { validateSelection, query }
            );
        } catch (error) {
            if (error.name !== 'AbortError') {
                renderBorrowers([], {}, { validateSelection: false, query });
                if (borrowerMessage) {
                    borrowerMessage.textContent = 'Borrower options could not be loaded. Please try again.';
                    borrowerMessage.hidden = false;
                }
            }
        } finally {
            borrowerPicker.removeAttribute('aria-busy');

            if (open && borrowerMenu) {
                borrowerMenu.hidden = false;
                borrowerPicker.classList.add('is-open');
                borrowerTrigger?.setAttribute('aria-expanded', 'true');
                borrowerSearch?.focus();
            }
        }
    };

    borrowerTrigger?.addEventListener('click', async () => {
        const opening = borrowerMenu?.hidden !== false;

        if (!opening) {
            closeBorrowerPicker();
            return;
        }

        if (borrowerSearch) borrowerSearch.value = '';

        await refreshBorrowers({
            open: true,
            query: '',
            validateSelection: true,
        });
    });

    borrowerSearch?.addEventListener('input', () => {
        window.clearTimeout(borrowerSearchTimer);

        borrowerSearchTimer = window.setTimeout(() => {
            refreshBorrowers({
                query: borrowerSearch.value,
                validateSelection: false,
            });
        }, 250);
    });

    borrowerOptions?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-borrower-option]');
        if (!option) return;

        setBorrower(
            option.dataset.value || '',
            option.dataset.name || 'All borrowers'
        );

        closeBorrowerPicker();
    });

    division?.addEventListener('change', async () => {
        applyDivision();

        if (borrowerSearch) borrowerSearch.value = '';

        await refreshBorrowers({
            query: '',
            validateSelection: true,
        });
    });

    dependent?.addEventListener('change', () => {
        if (borrowerSearch) borrowerSearch.value = '';

        refreshBorrowers({
            query: '',
            validateSelection: true,
        });
    });

    period?.addEventListener('change', () => {
        if (borrowerSearch) borrowerSearch.value = '';

        refreshBorrowers({
            query: '',
            validateSelection: true,
        });
    });

    document.addEventListener('click', (event) => {
        if (!borrowerPicker || borrowerPicker.contains(event.target)) return;
        closeBorrowerPicker();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !borrowerPicker) return;

        closeBorrowerPicker();
        borrowerTrigger?.focus();
    });

    applyDivision();
})();
</script>
