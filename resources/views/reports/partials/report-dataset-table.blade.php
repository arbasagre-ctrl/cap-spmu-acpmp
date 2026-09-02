@php
    /*
    | Renders one page of a ReportDataset.
    |
    | Cells come from the dataset's own row values, the same ordered record
    | values the CSV writes and the printed copy renders, so the three cannot
    | drift. A row's `_link` is presentation only and is not a record field.
    |
    | `$rows` defaults to the paginated page; the printable copy passes the
    | whole record set instead.
    */
    /*
     * A paginator is not a plain collection of rows, so unwrap it before
     * inspecting the records it holds.
     */
    $rows = $rows ?? $records;
    $rows = $rows instanceof \Illuminate\Contracts\Pagination\Paginator
        ? collect($rows->items())
        : collect($rows);

    $showActions = $showActions ?? true;

    $hasRowLinks = $showActions
        && $rows->contains(fn (array $row): bool => ! empty($row['_link']));

    $columnCount = count($dataset->columns) + ($hasRowLinks ? 1 : 0);
@endphp

<div class="report-table-scroll">
    <table class="report-table">
        <thead>
            <tr>
                @foreach($dataset->columns as $column)
                    <th @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>{{ $column['label'] }}</th>
                @endforeach

                @if($hasRowLinks)
                    <th class="report-table-action-column">Action</th>
                @endif
            </tr>
        </thead>

        <tbody>
            @foreach($rows as $row)
                <tr>
                    @foreach($dataset->columns as $index => $column)
                        @php $value = $row[$column['key']] ?? ''; @endphp

                        <td @class(['numeric' => ($column['align'] ?? null) === 'numeric'])>
                            @if(($column['badge'] ?? false) && $value !== '')
                                {{-- Status reads as text first; the badge is restraint, not the message. --}}
                                <span class="report-status-badge tone-{{ $row['_tone_'.$column['key']] ?? 'neutral' }}">{{ $value }}</span>
                            @elseif($index === 0)
                                <strong>{{ $value }}</strong>
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach

                    @if($hasRowLinks)
                        <td class="report-table-action-column">
                            @if(! empty($row['_link']))
                                <a class="table-action" href="{{ $row['_link'] }}">View</a>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
