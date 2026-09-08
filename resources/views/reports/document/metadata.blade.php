@php
    /*
    | Document metadata as institutional text, not cards.
    |
    | Applied Filters is omitted entirely when the report was generated
    | without filters, rather than printing an empty label.
    */
    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $options = $options ?? [];
@endphp

<dl class="doc-meta">
    @if(($options['generated_by'] ?? true) && ! empty($meta['generated_by']))
        <div>
            <dt>Prepared by</dt>
            <dd>{{ $meta['generated_by'] }}</dd>
        </div>
    @endif

    <div>
        <dt>Date Generated</dt>
        <dd>{{ $meta['generated_long'] ?? ($meta['generated_at'] ?? '') }}</dd>
    </div>

    @if(($options['filters'] ?? true) && ! empty($appliedFilters))
        <div>
            <dt>Applied Filters</dt>
            <dd>{{ implode('; ', array_map(
                fn ($label, $value): string => $label.': '.$value,
                array_keys($appliedFilters),
                array_values($appliedFilters)
            )) }}</dd>
        </div>
    @endif
</dl>
