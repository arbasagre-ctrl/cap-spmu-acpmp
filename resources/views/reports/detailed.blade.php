@extends('layouts.app', ['title' => 'Reports'])

@section('content')

@include('reports.partials.workspace-styles')
@include('reports.partials.detail-styles')
@include('reports.document.styles')

<div class="reporting-workspace reporting-detail">

    <section class="page-heading">
        <div>
            <p class="eyebrow">Formal reporting</p>
            <h1>Reports</h1>
            <p>Generate detailed operational reports for review, documentation, printing, and export.</p>
        </div>
    </section>

    <div class="reports-detail-page">

        @include('reports.partials.report-builder')

        <section class="content-area">
            @if($showPreview)
                {{--
                    The preview is the document itself, on a sheet of paper,
                    so what is reviewed on screen is what prints and exports.
                --}}
                <div class="report-preview-bar">
                    <div>
                        <p class="report-preview-label">Report preview</p>
                        <p class="report-preview-context">Web preview only. Record navigation, when needed, stays below this sheet and is excluded from formal output.</p>
                    </div>

                    <button
                        class="button primary ui-pressable"
                        type="button"
                        data-open-export-options
                    >
                        <x-icon name="upload" size="16" />
                        Export / Print
                    </button>
                </div>

                {{--
                    Filter feedback belongs to the page, not to the document:
                    a rejected filter is something the operator must know
                    while working, and never part of the official record.
                --}}
                @if(! empty($dataset->meta['rejected_filters']))
                    <p class="report-filter-warning" role="status">
                        <x-icon name="warning" size="16" />
                        <span>
                            These filters were not recognised and were ignored:
                            {{ implode(', ', array_keys($dataset->meta['rejected_filters'])) }}.
                        </span>
                    </p>
                @endif

                <div class="report-preview-sheet report-preview-sheet--{{ App\Reports\ReportCatalogue::orientation($selectedReport) }}">
                    @include('reports.document.sheet', ['rows' => $records])
                </div>

                @if($records->hasPages())
                    <div class="report-records-footer">
                        @php
                            $recordSummary = 'Showing '.$records->firstItem().'–'.$records->lastItem()
                                .' of '.number_format($dataset->count())
                                .' '.($dataset->count() === 1 ? 'record' : 'records');
                        @endphp

                        <p class="report-records-label" role="status" aria-live="polite">
                            <strong>Web record navigation</strong>
                            <span>{{ $recordSummary }}. PDF and print include all {{ number_format($dataset->count()) }} filtered {{ $dataset->count() === 1 ? 'record' : 'records' }}.</span>
                        </p>

                        {{ $records->onEachSide(1)->links('reports.partials.report-pagination') }}
                    </div>
                @endif
            @else
                <section class="report-preview-empty" aria-labelledby="report-preview-empty-heading">
                    <span class="report-preview-empty-icon" aria-hidden="true">
                        <x-icon name="report-document" size="19" />
                    </span>
                    <div>
                        <p class="report-preview-label" id="report-preview-empty-heading">Report preview</p>
                        <p>Select your report options and click Generate Report to preview the report.</p>
                    </div>
                </section>
            @endif

        </section>

        @if($showPreview)
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

@if($showPreview)
    @include('reports.partials.export-options')
    @include('reports.partials.export-options-script')
@endif
@endsection
