@extends('layouts.app', ['title' => $dataset->label])

@section('content')
@php
    /*
    | The printable copy.
    |
    | It composes the same document partials the preview and the PDF use, and
    | renders the whole record set from the same ReportService call, so a
    | printed report is never one page of a larger result and never a
    | different design.
    */
    $margins = $exportOptions->marginsMm;
    $pageSize = match ($exportOptions->pageSize) {
        'LETTER' => 'letter',
        'LEGAL' => 'legal',
        default => 'A4',
    };
@endphp

@include('reports.document.styles')

<style>
@page {
    size: {{ $pageSize }} {{ $exportOptions->orientation }};
    margin: {{ $margins['top'] }}mm {{ $margins['right'] }}mm {{ $margins['bottom'] }}mm {{ $margins['left'] }}mm;
}

/* On paper the application does not exist - only the document does. */
@media print {
    .app-sidebar, .app-topbar, .sidebar-brand-row, .report-print-actions { display: none !important; }
    .app-shell, .app-main, .app-content { display: block !important; margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; }
    .report-print-page .doc-sheet { border: 0 !important; }
}

.report-print-actions { display: flex; justify-content: flex-end; gap: 10px; max-width: 1180px; margin: 0 auto 12px; }
.report-print-page { padding: 0 0 24px; }
.report-print-page .doc-sheet { border: 1px solid var(--border); }
</style>

<div class="report-print-actions">
    <button class="button primary ui-pressable" type="button" onclick="window.print()">
        <x-icon name="printer" size="16" />
        Print
    </button>
</div>

<div class="report-print-page">
    @include('reports.document.sheet')
</div>
@endsection
