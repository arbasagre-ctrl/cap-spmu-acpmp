@extends('layouts.app', ['title' => 'Reports'])

@section('content')

@include('reports.partials.workspace-styles')
@include('reports.partials.detail-styles')
@include('reports.document.styles')

@php
    $reportCount = $showPreview ? $dataset->count() : 0;
    $appliedFilters = $showPreview ? ($dataset->meta['applied_filters'] ?? []) : [];
    $scopeRows = $showPreview
        ? \App\Reports\ReportScopeFormatter::rows($appliedFilters)
        : [];
    $clearFiltersUrl = route('reports.index', [
        'report' => $selectedReport,
        'academic_period' => $periodSelection,
        'generated' => 1,
    ]);
@endphp

<div class="reporting-workspace reporting-detail">
    <section class="page-heading">
        <div>
            <p class="eyebrow">Formal reporting</p>
            <h1>Reports</h1>
            <p>Preview official operational reports, then print or export the exact records you reviewed.</p>
        </div>
    </section>

    <div class="reports-detail-page">
        @include('reports.partials.report-builder')

        <section class="content-area">
            @if($showPreview)
                <div class="report-preview-bar">
                    <div>
                        <p class="report-preview-label">Report preview</p>
                        <h2 class="report-preview-title">{{ $selectedReportMeta['label'] }}</h2>
                        <div class="report-preview-meta">
                            <span>{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</span>
                            <span>{{ number_format($reportCount) }} {{ $reportCount === 1 ? 'record' : 'records' }}</span>
                            @if(! empty($dataset->meta['generated_long']))
                                <span>Prepared {{ $dataset->meta['generated_long'] }}</span>
                            @endif
                        </div>

                        @if(! empty($scopeRows))
                            <p class="report-preview-filters" aria-label="Selected report scope">
                                @foreach($scopeRows as $scope)
                                    <span>
                                        <strong>{{ $scope['label'] }}:</strong>
                                        {{ $scope['value'] }}
                                    </span>
                                    @unless($loop->last)
                                        <span aria-hidden="true"> · </span>
                                    @endunless
                                @endforeach
                            </p>
                        @endif
                    </div>

                    @if($reportCount > 0)
                        <button class="button primary ui-pressable" type="button" data-open-export-options>
                            <x-icon name="upload" size="16" />
                            Export / Print
                        </button>
                    @endif
                </div>

                @if(! empty($dataset->meta['rejected_filters']))
                    <p class="report-filter-warning" role="status">
                        <x-icon name="warning" size="16" />
                        <span>
                            These filters were not recognised and were ignored:
                            {{ implode(', ', array_keys($dataset->meta['rejected_filters'])) }}.
                        </span>
                    </p>
                @endif

                @if($reportCount > 0)
                    <div class="report-preview-sheet report-preview-sheet--{{ App\Reports\ReportCatalogue::orientation($selectedReport) }}">
                        @include('reports.document.sheet', ['rows' => $records])
                    </div>

                    @if($records->hasPages())
                        <div class="report-records-footer">
                            @php
                                $recordSummary = 'Showing '.$records->firstItem().'–'.$records->lastItem()
                                    .' of '.number_format($reportCount)
                                    .' '.($reportCount === 1 ? 'record' : 'records');
                            @endphp

                            <p class="report-records-label" role="status" aria-live="polite">
                                <strong>Web record navigation</strong>
                                <span>{{ $recordSummary }}. Exports and print include all {{ number_format($reportCount) }} filtered {{ $reportCount === 1 ? 'record' : 'records' }}.</span>
                            </p>

                            {{ $records->onEachSide(1)->links('reports.partials.report-pagination') }}
                        </div>
                    @endif
                @else
                    <section class="report-no-results" aria-labelledby="report-no-results-title">
                        <span class="report-no-results-icon" aria-hidden="true">
                            <x-icon name="report-document" size="22" />
                        </span>
                        <div>
                            <p class="report-preview-label">No matching records</p>
                            <h3 id="report-no-results-title">Nothing to preview for this report scope.</h3>
                            <p>{{ App\Reports\ReportCatalogue::emptyMessage($selectedReport) }}</p>
                            <div class="report-no-results-actions">
                                @if(! empty($appliedFilters))
                                    <a class="button secondary ui-pressable" href="{{ $clearFiltersUrl }}">Clear Filters</a>
                                @endif
                                <a class="button secondary ui-pressable" href="#report-builder">Change Period or Filters</a>
                            </div>
                        </div>
                    </section>
                @endif
            @else
                <section class="report-preview-empty" aria-labelledby="report-preview-empty-heading">
                    <span class="report-preview-empty-icon" aria-hidden="true">
                        <x-icon name="report-document" size="19" />
                    </span>
                    <div>
                        <p class="report-preview-label" id="report-preview-empty-heading">Report preview</p>
                        <p>Choose the report options above, then select Preview Report to review the records here.</p>
                    </div>
                </section>
            @endif
        </section>

        @if($showPreview && $reportCount > 0)
            <section class="content-area">
                <p class="report-boundary-note">
                    <x-icon name="information" size="18" />
                    <span>
                        All reports are based on official operational records.
                        For analysis, insights, and forecasting, use the Analytics module.
                    </span>
                </p>
            </section>
        @endif
    </div>
</div>

@if($showPreview && $reportCount > 0)
    @include('reports.partials.export-options')
    @include('reports.partials.export-options-script')
@endif
@endsection
