@extends('layouts.app', ['title' => 'Reports'])

@section('content')

@include('reports.partials.workspace-styles')
@include('reports.partials.detail-styles')

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
            <article class="card report-output-card">

                <div class="report-output-header">
                    <div>
                        <p class="eyebrow">Generated report</p>
                        <h2>{{ $dataset->label }}</h2>
                        <p>{{ $dataset->meta['period_label'] ?? '' }}</p>
                    </div>

                    {{--
                        Export and Print are actions on a report that already
                        exists, so they live here rather than in the page
                        header: generate, review, then export or print.
                    --}}
                    <div class="report-output-actions">
                        <a
                            class="button secondary ui-pressable"
                            href="{{ route('reports.export', array_merge(
                                ['type' => $exportType],
                                $reportFilters->toQuery()
                            )) }}"
                        >
                            <x-icon name="upload" size="16" class="report-download-icon" />
                            Export CSV
                        </a>

                        <a
                            class="button secondary ui-pressable"
                            href="{{ route('reports.print', array_merge(
                                ['type' => $selectedReport],
                                $reportFilters->toQuery()
                            )) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            <x-icon name="printer" size="16" />
                            Print
                        </a>
                    </div>
                </div>

                <div class="report-output-body">

                    @include('reports.partials.report-metadata')

                    @if($dataset->isEmpty())
                        <p class="report-empty-state">
                            {{ App\Reports\ReportCatalogue::emptyMessage($selectedReport) }}
                        </p>
                    @else
                        @include('reports.partials.report-dataset-table')

                        <div class="report-records-footer">
                            @php
                                $recordSummary = 'Showing '.$records->firstItem().'–'.$records->lastItem()
                                    .' of '.number_format($dataset->count())
                                    .' '.($dataset->count() === 1 ? 'record' : 'records');
                            @endphp

                            <p role="status" aria-live="polite">{{ $recordSummary }}</p>

                            @if($records->hasPages())
                                {{ $records->onEachSide(1)->links('reports.partials.report-pagination') }}
                            @endif
                        </div>
                    @endif

                    {{--
                        A contextual pointer, not a second Audit Trail: the
                        dedicated module remains the authoritative place for
                        who performed which system action and when.
                    --}}
                    <p class="report-audit-link">
                        <a href="{{ route('reports.audit') }}">
                            View related audit history
                            <x-icon name="chevron-right" size="15" />
                        </a>
                    </p>
                </div>
            </article>
        </section>

        <section class="content-area">
            <p class="report-boundary-note">
                <x-icon name="information" size="18" />
                <span>
                    All reports are based on official operational records.
                    For analysis, insights, and forecasting, use the Analytics module.
                </span>
            </p>
        </section>

    </div>
</div>
@endsection
