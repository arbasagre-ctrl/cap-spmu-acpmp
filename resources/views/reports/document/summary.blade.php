@php
    /*
    | The report summary.
    |
    | Every figure comes from the same dataset as the detailed records below,
    | so the summary and detailed table always describe the same scope.
    */
    $summary = $dataset->summary;
@endphp

@if(! empty($summary))
    <section class="doc-summary" aria-label="Report summary" style="margin: 12px 0 16px;">
        <h2 class="doc-summary-heading" style="margin: 0 0 6px;">Report Summary</h2>

        <table
            aria-label="Report summary values"
            style="width: 100%; max-width: 440px; border-collapse: collapse; font-size: 11.5px;"
        >
            <tbody>
                @foreach($summary as $label => $value)
                    <tr>
                        <td
                            style="width: 68%; padding: 3px 10px 3px 0; border-bottom: 1px solid #dddddd; vertical-align: top;"
                        >
                            {{ $label }}
                        </td>
                        <td
                            style="width: 32%; padding: 3px 0 3px 10px; border-bottom: 1px solid #dddddd; text-align: right; vertical-align: top; font-weight: 700; font-variant-numeric: tabular-nums;"
                        >
                            {{ App\Reports\ReportSummaryFormatter::display((string) $label, $value) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif
