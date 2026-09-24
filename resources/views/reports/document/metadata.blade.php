@php
    /*
    | Document metadata as institutional text, not cards.
    |
    | Report Scope is omitted entirely when the report was generated without
    | a meaningful filter (a default such as "All divisions" is never a
    | scope value in applied_filters to begin with), rather than printing an
    | empty label.
    */
    $meta = $dataset->meta;
    $appliedFilters = $meta['applied_filters'] ?? [];
    $scopeRows = \App\Reports\ReportScopeFormatter::rows($appliedFilters);
    $scopeSummary = implode(' · ', array_column($scopeRows, 'value'));
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

    @if(($options['filters'] ?? true) && $scopeSummary !== '')
        <div>
            <dt>Report Scope</dt>
            <dd>{{ $scopeSummary }}</dd>
        </div>
    @endif
</dl>
