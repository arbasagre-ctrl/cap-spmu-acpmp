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
    |
    | The trailing action column is opt-in via $options['interactive'] (set
    | only by the web preview) and only appears when at least one visible row
    | actually carries a `_link` -- print and PDF never set it, so they never
    | render a column of unusable links, and a report with no linkable rows
    | never gets an empty column either.
    */
    $rows = $rows ?? $dataset->rows;
    $rows = $rows instanceof \Illuminate\Contracts\Pagination\Paginator
        ? collect($rows->items())
        : collect($rows);

    $columns = $columns ?? $dataset->columns;

    $showLinkColumn = ($options['interactive'] ?? false)
        && $rows->contains(fn (array $row): bool => filled($row['_link'] ?? null));
@endphp

<table class="doc-table">
    <thead>
        <tr>
            @foreach($columns as $column)
                <th @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $column['label'] }}</th>
            @endforeach
            @if($showLinkColumn)
                <th class="doc-table-action-column">Action</th>
            @endif
        </tr>
    </thead>

    <tbody>
        @forelse($rows as $row)
            <tr>
                @foreach($columns as $column)
                    <td @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $row[$column['key']] ?? '' }}</td>
                @endforeach
                @if($showLinkColumn)
                    <td class="doc-table-action-column">
                        @if(filled($row['_link'] ?? null))
                            <a class="doc-table-record-link" href="{{ $row['_link'] }}">View Record</a>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td class="doc-table-empty" colspan="{{ count($columns) + ($showLinkColumn ? 1 : 0) }}">
                    {{ App\Reports\ReportCatalogue::emptyMessage($dataset->reportKey) }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
