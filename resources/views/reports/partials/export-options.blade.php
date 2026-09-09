@php
    /*
    | Report Options.
    |
    | This dialog governs export presentation only. It never re-asks for the
    | report, the period or the filters: those already produced the dataset on
    | screen, and the modal simply states the scope it is about to render so
    | the operator can confirm what they are exporting.
    |
    | The institutional header is not offered as a toggle. A formal copy
    | without it would not be an institutional document.
    */
    use App\Reports\ReportExportOptions;

    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $defaultOrientation = App\Reports\ReportCatalogue::orientation($selectedReport);
    $periodMode = App\Reports\ReportCatalogue::periodMode($selectedReport);
@endphp

<dialog class="report-options-dialog" id="report-options" aria-labelledby="report-options-title">
    <form method="get" action="{{ route('reports.export', ['type' => $selectedReport]) }}" data-export-form>
        {{-- The dataset scope travels with the export so it cannot drift. --}}
        @foreach($reportFilters->toQuery() as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <input type="hidden" name="options_submitted" value="1">

        <header class="report-options-header">
            <h2 id="report-options-title">Report Options</h2>
            <button
                class="icon-button"
                type="button"
                aria-label="Close report options"
                data-export-close
            ><x-icon name="close" size="18" /></button>
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
                    @if(! empty($appliedFilters))
                        · {{ implode(' • ', array_values($appliedFilters)) }}
                    @endif
                </small>
            </p>

            <div class="report-options-grid">
                <label class="report-options-field" for="export-format">
                    <span>Export As</span>
                    <select id="export-format" name="format" data-export-format>
                        @foreach(ReportExportOptions::FORMATS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="report-options-field" for="export-page-size" data-page-setting>
                    <span>Page Size</span>
                    <select id="export-page-size" name="page_size">
                        @foreach(ReportExportOptions::PAGE_SIZES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="report-options-field" for="export-orientation" data-page-setting>
                    <span>Orientation</span>
                    <select id="export-orientation" name="orientation">
                        @foreach(ReportExportOptions::ORIENTATIONS as $value => $label)
                            <option value="{{ $value }}" @selected($defaultOrientation === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="report-options-field" for="export-margins" data-page-setting>
                    <span>Margins</span>
                    <select id="export-margins" name="margins" data-export-margins>
                        @foreach(ReportExportOptions::MARGIN_PRESETS as $value => $preset)
                            <option value="{{ $value }}">{{ $preset['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <div class="report-options-custom-margins" data-custom-margins hidden>
                @foreach(['top' => 'Top', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'] as $edge => $label)
                    <label class="report-options-field" for="export-margin-{{ $edge }}">
                        <span>{{ $label }} (mm)</span>
                        <input
                            id="export-margin-{{ $edge }}"
                            type="number"
                            name="margin_{{ $edge }}"
                            value="20"
                            min="5"
                            max="60"
                            step="1"
                        >
                    </label>
                @endforeach
            </div>

            <fieldset class="report-options-content" data-content-settings>
                <legend>Content</legend>

                <label class="checkbox">
                    <input type="checkbox" name="include_summary" value="1" checked>
                    <span>Include report summary</span>
                </label>

                <label class="checkbox">
                    <input type="checkbox" name="include_generated_by" value="1" checked>
                    <span>Include generated-by information</span>
                </label>

                @if(! empty($appliedFilters))
                    <label class="checkbox">
                        <input type="checkbox" name="include_filters" value="1" checked>
                        <span>Include applied filters</span>
                    </label>
                @endif

                <label class="checkbox">
                    <input type="checkbox" name="include_footer" value="1" checked>
                    <span>Include system-generated footer</span>
                </label>

                <label class="checkbox" data-repeat-header-setting>
                    <input type="checkbox" name="repeat_headers" value="1" checked>
                    <span>Repeat table headers on each PDF/printed page</span>
                </label>
            </fieldset>

            <details class="report-options-advanced" data-page-setting>
                <summary>Advanced Options</summary>
                <div>
                    <label class="report-options-field" for="export-font-size">
                        <span>Table font size (pt)</span>
                        <input
                            id="export-font-size"
                            type="number"
                            name="font_size"
                            min="6"
                            max="14"
                            step="0.5"
                            placeholder="Automatic"
                        >
                    </label>
                    <p>Leave blank to let the report choose a size that fits the page.</p>
                </div>
            </details>

            <p class="report-options-error" data-export-error hidden role="alert"></p>
        </div>

        <footer class="report-options-footer">
            <button class="button secondary ui-pressable" type="button" data-export-close>Cancel</button>
            <button class="button primary ui-pressable" type="submit" data-export-submit>Generate PDF</button>
        </footer>
    </form>
</dialog>
