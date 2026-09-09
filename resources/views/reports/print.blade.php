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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $dataset->label }}</title>
@include('reports.document.styles')

<style>
@page {
    size: {{ $pageSize }} {{ $exportOptions->orientation }};
    margin: {{ $margins['top'] }}mm {{ $margins['right'] }}mm {{ $margins['bottom'] }}mm {{ $margins['left'] }}mm;
}

html, body { margin: 0; padding: 0; background: #fff; }
.report-print-page { padding: 24px; }

@media print {
    .report-print-page { padding: 0; }
    .doc-sheet { border: 0 !important; }
    @if(! ($options['repeat_headers'] ?? true))
    .doc-table thead { display: table-row-group; }
    @endif
}
</style>
</head>
<body>

<div class="report-print-page">
    @include('reports.document.sheet')
</div>

<script>
window.addEventListener('load', () => window.print());
</script>
</body>
</html>
