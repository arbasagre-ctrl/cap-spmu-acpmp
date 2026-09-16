@extends('layouts.app', ['title' => 'Edit Template'])

@section('content')
@php
    $routeType = strtolower(str_replace('_', '-', $type));
    $updateRoute = route('administration.document-templates.minor-edit.update', ['type' => $routeType, 'template' => $template], false);
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Draft-only presentation edit</p>
        <h1>Edit Template · {{ $label }} {{ $template->version_label ?: 'v'.$template->template_version.'.0' }}</h1>
        <p>Update the wording, alignment, and other safe presentation details of this uploaded Draft. The approved source file is never changed, and nothing here affects the current official layout until you separately activate a Draft.</p>
    </div>
    <div class="preview-actions">
        <a class="button secondary ui-pressable" href="{{ $cancelRoute }}">Cancel</a>
    </div>
</section>

<section class="content-area minor-edit-page">
    @if($sections === [])
        <article class="card">
            <div class="callout compact">This Draft has no safe static text to edit. It contains only system-connected fields.</div>
        </article>
    @else
        <form method="post" action="{{ $updateRoute }}" class="minor-edit-form">
            @csrf

            <article class="card minor-edit-intro">
                <p>Only wording and alignment shown below can be changed here. Data fields that connect to system records, signatures, and anything the layout does not support are not listed and cannot be edited on this screen.</p>
            </article>

            @foreach($sections as $index => $section)
                <article class="card minor-edit-section">
                    <input type="hidden" name="edits[{{ $index }}][key]" value="{{ $section['key'] }}">
                    <label class="minor-edit-label">
                        <span>{{ $section['label'] }}</span>
                        <textarea name="edits[{{ $index }}][text]" maxlength="500" rows="2" required>{{ old("edits.$index.text", $section['text']) }}</textarea>
                    </label>
                    <label class="minor-edit-alignment">
                        <span>Alignment</span>
                        <select name="edits[{{ $index }}][alignment]">
                            @foreach(['left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'justify' => 'Justify'] as $value => $optionLabel)
                                <option value="{{ $value }}" @selected(old("edits.$index.alignment", $section['alignment']) === $value)>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                    </label>
                </article>
            @endforeach

            <div class="minor-edit-actions">
                <button class="button primary ui-pressable" type="submit">Save Draft</button>
                <a class="button secondary ui-pressable" href="{{ $cancelRoute }}">Cancel</a>
            </div>
        </form>
    @endif
</section>

<style>
.minor-edit-page{display:grid;gap:14px;max-width:760px}
.minor-edit-intro p{margin:0;color:var(--text-muted,#62758a);line-height:1.55}
.minor-edit-form{display:grid;gap:14px}
.minor-edit-section{display:grid;grid-template-columns:minmax(0,1fr) 160px;gap:14px;align-items:start}
.minor-edit-label,.minor-edit-alignment{display:grid;gap:6px;font-weight:700}
.minor-edit-label span,.minor-edit-alignment span{font-size:.78rem;color:var(--text-muted,#62758a);text-transform:uppercase;letter-spacing:.03em}
.minor-edit-label textarea{resize:vertical;min-height:44px}
.minor-edit-actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:640px){.minor-edit-section{grid-template-columns:1fr}}
</style>
@endsection
