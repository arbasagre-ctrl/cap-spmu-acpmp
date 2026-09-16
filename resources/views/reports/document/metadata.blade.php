@php
    /*
    | Document metadata as institutional text, not cards.
    |
    | Applied Filters is omitted entirely when the report was generated
    | without filters, rather than printing an empty label.
    */
    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $scopeRows = \App\Reports\ReportScopeFormatter::rows($appliedFilters);
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

    @if(($options['filters'] ?? true) && ! empty($scopeRows))
        @foreach($scopeRows as $scope)
            <div>
                <dt>{{ $scope['label'] }}</dt>
                <dd>{{ $scope['value'] }}</dd>
            </div>
        @endforeach
    @endif
</dl>
