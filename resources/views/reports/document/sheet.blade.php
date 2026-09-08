@php
    /*
    | One formal report sheet.
    |
    | The preview, the print view and the PDF all compose the document from
    | this single partial, which is what keeps the three looking identical.
    | `$options` toggles only presentation — never the records.
    */
    $options = $options ?? [];
@endphp

<article class="doc-sheet">
    @include('reports.document.header')
    @include('reports.document.title')
    @include('reports.document.metadata')

    <div class="doc-table-scroll">
        @include('reports.document.table')
    </div>

    @if($options['summary'] ?? true)
        @include('reports.document.summary')
    @endif

    @if($options['footer'] ?? true)
        @include('reports.document.footer')
    @endif
</article>
