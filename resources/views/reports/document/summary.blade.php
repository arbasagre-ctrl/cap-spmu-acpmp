@php
    /*
    | The report summary.
    |
    | Every figure comes from the dataset the table above was built from, so a
    | total can never disagree with the records it counts.
    */
    $summary = $dataset->summary;
@endphp

@if(! empty($summary))
    <section class="doc-summary" aria-label="Report summary">
        <h2 class="doc-summary-heading">Summary</h2>

        <dl>
            @foreach($summary as $label => $value)
                <div>
                    <dt>{{ $label }}</dt>
                    <dd>{{ is_numeric($value) ? number_format((float) $value) : $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
