@php
    /*
    | The PDF copy.
    |
    | dompdf understands table layout far better than grid or flex, so the
    | institutional header is a table here while the screen uses CSS grid. The
    | content, wording and order are identical to the preview; only the layout
    | mechanism differs, because the renderer demands it.
    */
    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $margins = $exportOptions->marginsInPoints();
    $periodMode = App\Reports\ReportCatalogue::periodMode($dataset->reportKey);
    $fontSize = $exportOptions->fontSize ?: 7.5;
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $dataset->label }}</title>
<style>
    @page {
        margin: {{ $margins['top'] }}pt {{ $margins['right'] }}pt {{ $margins['bottom'] }}pt {{ $margins['left'] }}pt;
    }

    body {
        margin: 0;
        font-family: Helvetica, Arial, sans-serif;
        font-size: {{ $fontSize }}pt;
        line-height: 1.35;
        color: #111;
    }

    .doc-header { width: 100%; border-collapse: collapse; border-bottom: 1px solid #222; }
    .doc-header td { padding: 0 0 6pt; vertical-align: middle; }
    .doc-header-seal { width: 46pt; }
    .doc-header-seal img { width: 44pt; height: 44pt; }
    .doc-header-republic, .doc-header-address { font-size: 8pt; line-height: 1.15; }
    .doc-header-institution { font-size: 11pt; font-weight: bold; line-height: 1.15; }

    .doc-title { margin: 14pt 0 0; text-align: center; font-size: 11pt; font-weight: bold; letter-spacing: 0.4pt; }
    .doc-period { margin: 2pt 0 14pt; text-align: center; font-size: 8pt; }

    .doc-meta { margin: 0 0 10pt; }
    .doc-meta div { margin: 0 0 1pt; font-size: 8pt; }
    .doc-meta span { display: inline-block; width: 96pt; }

    table.doc-table { width: 100%; border-collapse: collapse; }
    @if(! ($options['repeat_headers'] ?? true))
    table.doc-table thead { display: table-row-group; }
    @endif
    table.doc-table th {
        padding: 4pt 4pt;
        background: #f2f2f2;
        border: 0.6pt solid #999;
        font-size: {{ max(6, $fontSize - 1.2) }}pt;
        font-weight: bold;
        text-align: left;
        text-transform: uppercase;
    }
    table.doc-table td { padding: 3.5pt 4pt; border: 0.6pt solid #bbb; vertical-align: top; }
    table.doc-table td.numeric, table.doc-table th.numeric { text-align: right; }
    .doc-table-empty { text-align: center; padding: 14pt 4pt; color: #555; }

    .doc-summary { margin-top: 12pt; }
    .doc-summary-heading { font-size: 8.5pt; font-weight: bold; text-transform: uppercase; margin: 0 0 4pt; }
    .doc-summary div { font-size: 8pt; margin: 0 0 1pt; }
    .doc-summary span { display: inline-block; width: 170pt; }
    .doc-summary strong { font-weight: bold; }

    .doc-footer { margin-top: 16pt; padding-top: 5pt; border-top: 0.6pt solid #999; }
    .doc-footer div { font-size: 7pt; color: #333; }
</style>
</head>
<body>

<table class="doc-header">
    <tr>
        <td class="doc-header-seal">
            @if($sealSrc)
                <img src="{{ $sealSrc }}" alt="Camarines Sur Polytechnic Colleges">
            @endif
        </td>
        <td>
            <div class="doc-header-republic">Republic of the Philippines</div>
            <div class="doc-header-institution">CAMARINES SUR POLYTECHNIC COLLEGES</div>
            <div class="doc-header-address">Nabua, Camarines Sur</div>
        </td>
    </tr>
</table>

<div class="doc-title">{{ mb_strtoupper($dataset->label) }}</div>

<div class="doc-period">
    @if($periodMode === 'as_of')
        As of {{ $meta['as_of_long'] ?? '' }}
    @else
        For the period {{ $meta['period_long'] ?? '' }}
    @endif
</div>

<div class="doc-meta">
    @if(($options['generated_by'] ?? true) && ! empty($meta['generated_by']))
        <div><span>Prepared by</span>: {{ $meta['generated_by'] }}</div>
    @endif

    <div><span>Date Generated</span>: {{ $meta['generated_long'] ?? '' }}</div>

    @if(($options['filters'] ?? true) && ! empty($appliedFilters))
        <div><span>Applied Filters</span>:
            {{ implode('; ', array_map(
                fn ($label, $value): string => $label.': '.$value,
                array_keys($appliedFilters),
                array_values($appliedFilters)
            )) }}
        </div>
    @endif
</div>

<table class="doc-table">
    <thead>
        <tr>
            @foreach($dataset->columns as $column)
                <th @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse($dataset->rows as $record)
            <tr>
                @foreach($dataset->columns as $column)
                    <td @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $record[$column['key']] ?? '' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td class="doc-table-empty" colspan="{{ max(1, count($dataset->columns)) }}">
                    {{ App\Reports\ReportCatalogue::emptyMessage($dataset->reportKey) }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

@if(($options['summary'] ?? true) && ! empty($dataset->summary))
    <div class="doc-summary">
        <div class="doc-summary-heading">Summary</div>
        @foreach($dataset->summary as $label => $value)
            <div><span>{{ $label }}</span>: <strong>{{ is_numeric($value) ? number_format((float) $value) : $value }}</strong></div>
        @endforeach
    </div>
@endif

@if($options['footer'] ?? true)
    <div class="doc-footer">
        <div>Prepared from official SPMU-ACPMP operational records.</div>
        <div>This is a system-generated report. No signature is required.</div>
    </div>
@endif

</body>
</html>
