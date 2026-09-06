@extends('layouts.app', ['title' => 'Document Template Preview'])

@section('content')
@php
    $routeType = strtolower(str_replace('_', '-', $type));
    // Relative, route-generated URLs preserve the browser's current
    // localhost host and port. This keeps the protected PDF endpoints
    // same-origin with the authenticated preview page.
    $sourceRoute = route('administration.document-templates.review', ['type' => $routeType, 'template' => $template], false);
    $renderRoute = route('administration.document-templates.render', ['type' => $routeType, 'template' => $template], false);
    $downloadRoute = route('administration.document-templates.download', ['type' => $routeType, 'template' => $template], false);
    $sampleEmbedRoute = $sampleRoute
        ? route('administration.document-templates.sample', ['type' => $routeType, 'template' => $template], false)
        : null;
    $pageCount = max(1, (int) ($pageCount ?? $review['pages'] ?? 1));
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Generated sample review</p>
        <h1>{{ $label }} · {{ $template->version_label ?: 'v'.$template->template_version.'.0' }}</h1>
        <p>Review the approved source and the generated sample. This sample uses the same production renderer that will create future controlled documents.</p>
    </div>
    <div class="preview-actions">
        <a class="button secondary ui-pressable" href="{{ $sourceRoute }}" target="_blank" rel="noopener">Review Approved Source</a>
        <a class="button secondary ui-pressable" href="{{ $renderRoute }}" target="_blank" rel="noopener">Review Production Layout</a>
        <a class="button secondary ui-pressable" href="{{ $downloadRoute }}">Download</a>
    </div>
</section>

<section class="content-area template-preview-page">
    <article class="card template-preview-summary">
        <div><span>Format</span><strong>{{ $format }}</strong></div>
        <div><span>Production layout</span><strong>Prepared</strong></div>
        <div><span>System data</span><strong>Connected</strong></div>
        @if($format === 'PDF')<div><span>Pages</span><strong>{{ $pageCount }}</strong></div>@endif
        @if($format === 'XLSX')<div><span>Worksheets</span><strong>{{ count($review['worksheets'] ?? []) }}</strong></div>@endif
    </article>

    <article class="card template-preview-table-card">
        <h2>Generated sample</h2>
        <p>Check the finished document visually. Manual or wet-signature areas remain blank for their normal process.</p>
        @if($sampleEmbedRoute)
            <iframe class="template-production-sample" title="Production generated sample" src="{{ $sampleEmbedRoute }}"></iframe>
        @else
            <div class="callout compact">A generated sample is not available for this layout.</div>
        @endif
    </article>
</section>

<style>
.template-preview-page{display:grid;gap:16px}.preview-actions{display:flex;gap:8px;flex-wrap:wrap}.template-preview-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.template-preview-summary span{display:block;color:var(--muted,#62758a);font-size:.75rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase}.template-preview-summary strong{display:block;margin-top:5px;overflow-wrap:anywhere}.template-preview-table-card h2{margin:0 0 5px}.template-preview-table-card>p{margin:0 0 16px;color:var(--muted,#62758a)}.template-production-sample{width:100%;height:620px;border:1px solid var(--border,#d7e1eb);background:#fff}@media(max-width:700px){.template-production-sample{height:460px}}
</style>
@endsection
