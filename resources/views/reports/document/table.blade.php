@php
    /*
    | The formal report table.
    |
    | Cells are the dataset's own row values — the same ordered values the CSV
    | and the spreadsheet write — so no output can disagree with another. The
    | `_link` and `_tone_*` entries are presentation only and never printed.
    |
    | `$rows` is the paginated page on screen and the whole record set in the
    | printable and PDF copies.
    */
    $rows = $rows ?? $dataset->rows;
    $rows = $rows instanceof \Illuminate\Contracts\Pagination\Paginator
        ? collect($rows->items())
        : collect($rows);

    $columns = $columns ?? $dataset->columns;
@endphp

<table class="doc-table">
    <thead>
        <tr>
            @foreach($columns as $column)
                <th @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $column['label'] }}</th>
            @endforeach
        </tr>
    </thead>

    <tbody>
        @forelse($rows as $row)
            <tr>
                @foreach($columns as $column)
                    <td @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $row[$column['key']] ?? '' }}</td>
                @endforeach
            </tr>
        @empty
            <tr>
                <td class="doc-table-empty" colspan="{{ count($columns) }}">
                    {{ App\Reports\ReportCatalogue::emptyMessage($dataset->reportKey) }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
