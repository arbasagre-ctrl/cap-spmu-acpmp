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
            {{--
                The preview is the document itself, on a sheet of paper, so
                what is reviewed on screen is what prints and what exports.
                Actions sit above it because they act on a report that
                already exists.
            --}}
            <div class="report-preview-bar">
                <p class="report-preview-label">Report preview</p>

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
                Filter feedback belongs to the page, not to the document: a
                rejected filter is something the operator must know about
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

            <div class="report-preview-sheet">
                @include('reports.document.sheet', ['rows' => $records])
            </div>

            @if($records->hasPages())
                <div class="report-records-footer">
                    @php
                        $recordSummary = 'Showing '.$records->firstItem().'–'.$records->lastItem()
                            .' of '.number_format($dataset->count())
                            .' '.($dataset->count() === 1 ? 'record' : 'records');
                    @endphp

                    <p role="status" aria-live="polite">{{ $recordSummary }}</p>

                    {{ $records->onEachSide(1)->links('reports.partials.report-pagination') }}
                </div>
            @endif

            {{--
                A contextual pointer, not a second Audit Trail: the dedicated
                module remains the authoritative place for who performed which
                system action and when.
            --}}
            <p class="report-audit-link">
                <a href="{{ route('reports.audit') }}">
                    View related audit history
                    <x-icon name="chevron-right" size="15" />
                </a>
            </p>
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

@include('reports.partials.export-options')
@include('reports.partials.export-options-script')
@endsection
