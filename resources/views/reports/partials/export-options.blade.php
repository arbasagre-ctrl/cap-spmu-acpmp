@php
    /*
    | Universal report export dialog.
    |
    | The dataset is already fixed by the preview. This dialog only controls
    | the output format and presentation. Formal provenance is automatic so
    | an official report cannot accidentally lose its generated-by metadata,
    | applied filters, footer or repeating table headers.
    */
    use App\Reports\ReportExportOptions;

    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $scopeRows = App\Reports\ReportScopeFormatter::rows($appliedFilters);
    $periodMode = App\Reports\ReportCatalogue::periodMode($selectedReport);
@endphp

<dialog class="report-options-dialog" id="report-options" aria-labelledby="report-options-title">
    <form method="get" action="{{ route('reports.export', ['type' => $selectedReport]) }}" data-export-form>
        @foreach($reportFilters->toQuery() as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <input type="hidden" name="options_submitted" value="1">

        <header class="report-options-header">
            <div>
                <p class="report-options-eyebrow">Report output</p>
                <h2 id="report-options-title">Export / Print Report</h2>
            </div>
            <button class="icon-button" type="button" aria-label="Close export options" data-export-close>
                <x-icon name="close" size="18" />
            </button>
        </header>

        <div class="report-options-body">
            <p class="report-options-scope">
                <span>{{ $dataset->label }}</span>
                <small>
                    @if($periodMode === 'as_of')
                        As of {{ $meta['as_of_long'] ?? '' }}
                    @else
                        {{ $meta['period_long'] ?? '' }}
                    @endif
                    · {{ number_format($dataset->count()) }} {{ $dataset->count() === 1 ? 'record' : 'records' }}
                    @if(! empty($scopeRows))
                        · {{ implode(' • ', array_column($scopeRows, 'value')) }}
                    @endif
                </small>
            </p>

            <label class="report-options-field report-options-format" for="export-format">
                <span>Export Format</span>
                <select id="export-format" name="format" data-export-format>
                    @foreach(ReportExportOptions::FORMATS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <section class="report-options-section" data-page-settings aria-labelledby="report-page-layout-title">
                <div class="report-options-section-heading">
                    <h3 id="report-page-layout-title">Page Layout</h3>
                    <small>Used for PDF, Word and Print Preview.</small>
                </div>

                <div class="report-options-grid">
                    <label class="report-options-field" for="export-page-size">
                        <span>Page Size</span>
                        <select id="export-page-size" name="page_size">
                            @foreach(ReportExportOptions::PAGE_SIZES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="report-options-field" for="export-orientation">
                        <span>Orientation</span>
                        <select id="export-orientation" name="orientation">
                            @foreach(ReportExportOptions::ORIENTATIONS as $value => $label)
                                <option value="{{ $value }}" @selected($value === 'automatic')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </section>

            <fieldset class="report-options-content" data-summary-setting>
                <legend>Report Content</legend>
                <label class="checkbox">
                    <input type="checkbox" name="include_summary" value="1" checked>
                    <span>Include report summary</span>
                </label>
            </fieldset>

            <div class="report-options-auto-note" data-paginated-inclusions>
                <x-icon name="information" size="17" />
                <div>
                    <strong>Included automatically in formal reports</strong>
                    <span>Generated-by information, applied filters, the system-generated footer, and repeating table headers.</span>
                </div>
            </div>

            <div class="report-options-auto-note" data-spreadsheet-inclusions hidden>
                <x-icon name="information" size="17" />
                <div>
                    <strong>Excel keeps the report context automatically</strong>
                    <span>Report title, period, generated-by information, applied filters, and a frozen table header are included.</span>
                </div>
            </div>

            <div class="report-options-auto-note" data-raw-export-note hidden>
                <x-icon name="information" size="17" />
                <div>
                    <strong>CSV exports structured data only</strong>
                    <span>Page layout, document styling, and the report summary are not included.</span>
                </div>
            </div>

            <details class="report-options-advanced" data-page-settings>
                <summary>Advanced Print Settings</summary>
                <div class="report-options-advanced-body">
                    <label class="report-options-field" for="export-margins">
                        <span>Margins</span>
                        <select id="export-margins" name="margins" data-export-margins>
                            @foreach(ReportExportOptions::MARGIN_PRESETS as $value => $preset)
                                <option value="{{ $value }}">{{ $preset['label'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="report-options-field" for="export-font-size">
                        <span>Table Font Size</span>
                        <select id="export-font-size" name="font_size">
                            <option value="">Automatic (Recommended)</option>
                            @foreach([8, 9, 10, 11, 12] as $size)
                                <option value="{{ $size }}">{{ $size }} pt</option>
                            @endforeach
                        </select>
                    </label>

                    <div class="report-options-custom-margins" data-custom-margins hidden>
                        @foreach(['top' => 'Top', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'] as $edge => $label)
                            <label class="report-options-field" for="export-margin-{{ $edge }}">
                                <span>{{ $label }} (mm)</span>
                                <input id="export-margin-{{ $edge }}" type="number" name="margin_{{ $edge }}" value="20" min="5" max="60" step="1">
                            </label>
                        @endforeach
                    </div>
                </div>
            </details>

            <p class="report-options-error" data-export-error hidden role="alert"></p>
        </div>

        <footer class="report-options-footer">
            <button class="button secondary ui-pressable" type="button" data-export-close>Cancel</button>
            <button class="button primary ui-pressable" type="submit" data-export-submit>Export PDF</button>
        </footer>
    </form>
</dialog>
