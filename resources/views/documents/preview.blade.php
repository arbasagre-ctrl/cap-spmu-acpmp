@extends('layouts.app', ['title' => 'Document Preview'])
@section('content')
@php
    $documentLabels = [
        'LATE_RETURN_NOTICE' => 'Late Return Notice',
        'BILLING_STATEMENT' => 'Billing Statement',
        'RSLDDP' => 'RSLDDP',
        'RESTRICTION_NOTICE' => 'Restriction Notice',
        'ADMINISTRATIVE_SANCTION_NOTICE' => 'Administrative Sanction Notice',
        'ACCOUNTABILITY_COMPLIANCE_NOTICE' => 'Accountability / Compliance Notice',
    ];

    $documentLabel = $documentLabels[$document->document_type] ?? str($document->document_type)->replace('_', ' ')->title()->toString();

    if ($document->document_type === 'BILLING_STATEMENT'
        && $document->subject instanceof \App\Models\BillingStatement
        && $document->subject->isLateReturnBilling()) {
        $documentLabel = 'Late Return Billing Statement';
    }
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">Document preview</p>
        <h1>{{ $documentLabel }}</h1>
    </div>

    <a class="button secondary ui-pressable" href="{{ url()->previous() }}">
        <x-icon name="arrow-left" size="17" />
        Back
    </a>
</section>

<section class="content-area">
    <x-document-review-viewer
        :file="$document->file"
        :title="$documentLabel"
        :eyebrow="$document->document_no"
        empty-text="This document has no readable file on record."
        :preview-url="route('documents.view', $document, false)"
    />
</section>
@endsection
