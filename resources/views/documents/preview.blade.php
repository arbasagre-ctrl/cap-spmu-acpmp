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

<section class="page-heading document-preview-heading">
    <div>
        <h1>{{ $documentLabel }}</h1>

        @if(filled($document->document_no))
            <p class="document-preview-reference-line">
                <span>Document no.</span>
                <strong>{{ $document->document_no }}</strong>
            </p>
        @endif
    </div>

    <a class="button secondary ui-pressable" href="{{ url()->previous() }}">
        <x-icon name="arrow-left" size="17" />
        Back
    </a>
</section>

<section class="content-area document-preview-area">
    <x-document-review-viewer
        :file="$document->file"
        :title="$documentLabel"
        :eyebrow="null"
        empty-text="This document has no readable file on record."
        :preview-url="route('documents.view', $document, false)"
        :expanded="true"
        :compact-header="true"
    />
</section>

<style>
    /*
     * Standalone document preview hierarchy:
     * one page title, one document reference, then the document itself.
     * The application header already says "Document Preview", so repeating
     * that label inside the page adds noise without adding information.
     */
    .document-preview-heading {
        margin-bottom: 14px;
    }

    .document-preview-heading h1 {
        margin-bottom: 6px;
    }

    .document-preview-reference-line {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 5px 9px;
        max-width: 100% !important;
        margin: 0 !important;
        color: var(--text-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .document-preview-reference-line span {
        white-space: nowrap;
    }

    .document-preview-reference-line strong {
        min-width: 0;
        color: var(--text-secondary, #334155);
        font-size: 12px;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .document-preview-area {
        margin-top: 0;
    }

    /* The viewer card has no second title/reference strip on this page. */
    .document-preview-area .scanned-document-card--compact-header > .scanned-document-reference {
        display: none;
    }

    @media (max-width: 720px) {
        .document-preview-heading {
            align-items: flex-start;
        }

        .document-preview-reference-line {
            font-size: 11.5px;
        }
    }
</style>
@endsection
