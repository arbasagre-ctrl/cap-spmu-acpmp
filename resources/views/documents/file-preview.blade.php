@extends('layouts.app', ['title' => 'Document Preview'])
@section('content')
@php
    $fileTitle = trim((string) ($file->original_name ?? '')) ?: 'Document';
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Document preview</p>
        <h1>{{ $fileTitle }}</h1>
    </div>

    <a class="button secondary ui-pressable" href="{{ url()->previous() }}">
        <x-icon name="arrow-left" size="17" />
        Back
    </a>
</section>

<section class="content-area">
    <x-document-review-viewer
        :file="$file"
        :title="$fileTitle"
        :preview-url="route('files.show', $file, false)"
    />
</section>
@endsection
