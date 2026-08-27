@extends('layouts.app', ['title' => 'Request '.$borrowingRequest->request_no])
@section('content')
@php
    $v = $borrowingRequest->currentVersion;
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
    $currentDocs = $v->supportingDocuments->where('is_current', true);
    $requestLetterDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
    );
    $permissionToConductDoc = $currentDocs->firstWhere(
        'document_type',
        App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
    );
    $pendingCancellation = $borrowingRequest->pendingCancellation;
    $isUnderSpmuReview = $isSpmu
        && $borrowingRequest->status === App\Enums\RequestStatus::UnderSpmu;

    // A request remains APPROVED_READY_FOR_RELEASE at the request-record level
    // after approval, while the custody transaction continues through release,
    // return, and completion. Use custody state for the visible operational
    // badge so the request detail never shows a stale "Ready for Release".
    $custody = $borrowingRequest->custody;
    $requestIsCompleted = $custody?->status === 'CLOSED';
    $detailStatus = $borrowingRequest->status->value;
    $detailStatusLabel = null;

    $isBorrowerDraftWorkflow = $isBorrower
        && in_array(
            $borrowingRequest->status,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        );

    $signedRequestLetterReady = (bool) $requestLetterDoc;
    $ptcRequired = (bool) $v->represents_student_activity;
    $ptcReady = ! $ptcRequired || (bool) $permissionToConductDoc;
    $submissionReady = $signedRequestLetterReady && $ptcReady;

    $operationalDocuments = $custody
        ? $v->documents
            ->where('subject_type', App\Models\CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('status', 'FINAL')
        : collect();

    $borrowerSlipDocument = $operationalDocuments
        ->where('document_type', 'BORROWER_SLIP')
        ->sortByDesc('id')
        ->first();

    $laundryFormDocument = $operationalDocuments
        ->where('document_type', 'LAUNDRY_FORM')
        ->sortByDesc('id')
        ->first();

    $gatePassDocument = $operationalDocuments
        ->where('document_type', 'GATE_PASS')
        ->sortByDesc('id')
        ->first();

    $requestHasLaundry = $custody?->lines?->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    ) ?? false;

    $requestHasOffCampus = $custody?->lines?->contains(
        fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
    ) ?? false;

    if ($custody) {
        $preparationComplete = (bool) $custody->prepared_at;
        $hasPickupSchedule = (bool) $custody->scheduled_release_at
            && (bool) $custody->pickup_expires_at
            && ! $custody->pickup_expired_at;

        [$detailStatus, $detailStatusLabel] = match (true) {
            $custody->status === 'CLOSED' => ['CLOSED', 'Completed'],
            $custody->status === 'PREPARING_RELEASE' && $custody->pickup_expired_at => ['PREPARING_RELEASE', 'Pickup Window Expired'],
            $custody->status === 'PREPARING_RELEASE' && $preparationComplete && $hasPickupSchedule => ['READY_FOR_RELEASE', 'Ready for Release'],
            $custody->status === 'PREPARING_RELEASE' && ! $hasPickupSchedule => ['PREPARING_RELEASE', 'For Pickup Scheduling'],
            $custody->status === 'PREPARING_RELEASE' && $hasPickupSchedule && ! $preparationComplete => ['PREPARING_RELEASE', 'For Item Preparation'],
            $custody->status === 'ACTIVE' && (bool) $custody->released_at => ['BORROWED', 'Items Released / On Custody'],
            default => [$custody->status, null],
        };
    }
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'My request' : 'Borrowing request' }}</p>
        <h1>{{ $borrowingRequest->request_no }}</h1>
        <p>{{ $v->purpose_event }}</p>
        @if($isBorrower)
            <p class="meta">Review the request, documents, and current progress.</p>
        @endif
    </div>

    <div class="request-heading-actions">
        <x-status-badge :status="$detailStatus" :label="$detailStatusLabel" />

        @if($isBorrower && $borrowingRequest->custody)
            <a
                class="button primary ui-pressable"
                href="{{ route('custody.show', $borrowingRequest->custody) }}"
            >
                View Borrowing
            </a>
        @endif
    </div>
</section>

@if($errors->any())
<section class="content-area">
    <div class="callout danger">
        <strong>Please review:</strong>
        <ul>
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
</section>
@endif

@if($isBorrowerDraftWorkflow)
<section class="content-area" id="borrower-next-action">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Current action</p>

            @if(!$submissionReady)
                <h2>Complete the required request documents</h2>
                <p>
                    Your draft is saved. Upload the already approved and fully signed Borrowing Request Letter
                    {{ $ptcRequired ? 'and the Permission to Conduct Letter' : '' }} before submitting to SPMU.
                    The system does not generate or re-print the Borrowing Request Letter.
                </p>
            @else
                <h2>Ready to submit to SPMU</h2>
                <p>
                    The required scanned document(s) are complete. Review the draft if needed, then submit it to SPMU.
                </p>
            @endif

            <div class="inline-actions top-gap">
                @if($submissionReady)
                    <a
                        class="button primary ui-pressable"
                        href="{{ route('requests.edit', $borrowingRequest) }}"
                    >
                        Review & Submit
                    </a>
                @else
                    <a
                        class="button secondary ui-pressable"
                        href="{{ route('requests.edit', $borrowingRequest) }}"
                    >
                        Edit Draft & Upload Documents
                    </a>
                @endif
            </div>

            @if(!$submissionReady)
                <p class="meta top-gap">
                    Submit to SPMU becomes available after the fully signed Borrowing Request Letter
                    {{ $ptcRequired ? 'and Permission to Conduct Letter are' : 'is' }} uploaded.
                </p>
            @endif
        </div>
    </div>
</section>
@endif

@unless($isBorrower)
<x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" />
@endunless

@if($isUnderSpmuReview)
<section class="content-area spmu-verification-workspace" data-spmu-verification-workspace>
    <div class="spmu-review-layout">
        <div class="spmu-review-top-row">
            <div class="spmu-scan-slot">
                <x-document-review-viewer
            :file="$requestLetterDoc?->file"
            title="Inspect the signed Borrowing Request Letter"
        />
            </div>

            <article class="card spmu-checklist-panel">
            @if($canDecide)
                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU Head / Admin</p>
                        <h2>Review and decide</h2>
                    </div>
                    <span class="status-badge status-info">For approval</span>
                </div>

                <p class="meta spmu-review-summary">
                    Verify the signed documents, request details, dates, quantities, and system availability.
                    <strong>Verify &amp; Approve</strong> reserves the approved quantities for pickup; it does not physically issue the items.
                </p>

                @if($v->represents_student_activity)
                    <div class="spmu-supporting-document">
                        <div>
                            <strong>Permission to Conduct Letter</strong>
                            <small>Required for this student activity / organization request.</small>
                        </div>
                        @if($permissionToConductDoc)
                            <a class="button secondary small ui-pressable" href="{{ route('files.show', $permissionToConductDoc->file, false) }}" target="_blank" rel="noopener">View Attachment</a>
                        @else
                            <span class="status-badge status-danger">Missing</span>
                        @endif
                    </div>
                @endif

                <form
                    method="post"
                    action="{{ route('approvals.decide', $borrowingRequest) }}"
                    class="spmu-verification-form top-gap"
                    data-verification-form
                    data-required-supporting-present="{{ ($requestLetterDoc && (!$v->represents_student_activity || $permissionToConductDoc)) ? '1' : '0' }}"
                >
                    @csrf

                    <input type="hidden" name="decision" value="" data-verification-decision>
                    <input type="hidden" name="remarks" value="{{ old('remarks') }}" data-verification-remarks>

                    <div class="spmu-checklist">
                        <label class="spmu-check-row">
                            <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                            <span><strong>Request details match the signed letter</strong><small>Borrower, event, dates, location, items, and quantities are consistent.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="signatures_present" value="1" data-verification-check @checked(old('signatures_present'))>
                            <span><strong>Required wet signatures are present</strong><small>Required handwritten signatures / endorsements are visible on the scan.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="document_readable" value="1" data-verification-check @checked(old('document_readable'))>
                            <span><strong>Uploaded document is clear and readable</strong><small>The scan can be verified without guessing or missing content.</small></span>
                        </label>
                        <label class="spmu-check-row">
                            <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                            <span><strong>Requested quantities and availability checked</strong><small>Current inventory and selected schedule can support the signed requested quantities.</small></span>
                        </label>
                    </div>

                    <p class="field-error top-gap" data-verification-inline-error hidden></p>

                    <div class="spmu-review-footer">
                        <div class="spmu-decision-actions">
                            <button
                                class="button primary ui-pressable"
                                type="button"
                                data-decision-trigger="APPROVED"
                                data-approve-button
                                disabled
                            >
                                Verify &amp; Approve
                            </button>

                            <button
                                class="button secondary ui-pressable"
                                type="button"
                                data-decision-trigger="RETURNED_FOR_REVISION"
                            >
                                Return for Revision
                            </button>

                            <button
                                class="button danger ui-pressable"
                                type="button"
                                data-decision-trigger="REJECTED"
                            >
                                Reject
                            </button>
                        </div>
                    </div>
                </form>

                <dialog class="spmu-confirm-dialog" data-verification-confirm-dialog>
                    <div class="spmu-confirm-dialog__surface">
                        <div class="spmu-confirm-dialog__icon" aria-hidden="true">?</div>

                        <div>
                            <h2 data-confirm-title>Confirm decision</h2>
                            <p class="meta" data-confirm-message></p>

                            <div class="spmu-dialog-remarks" data-confirm-remarks-wrap hidden>
                                <label for="spmu-decision-remarks" data-confirm-remarks-label>Remarks</label>
                                <textarea
                                    id="spmu-decision-remarks"
                                    rows="5"
                                    maxlength="2000"
                                    data-confirm-remarks
                                    placeholder="Enter the reason or instructions for the borrower."
                                ></textarea>
                                <small data-confirm-remarks-help></small>
                                <p class="field-error" data-confirm-remarks-error hidden></p>
                            </div>
                        </div>

                        <div class="spmu-confirm-dialog__actions">
                            <button class="button secondary ui-pressable" type="button" data-confirm-cancel>Go Back</button>
                            <button class="button primary ui-pressable" type="button" data-confirm-submit>Confirm</button>
                        </div>
                    </div>
                </dialog>
            @else
                <div class="card-header">
                    <div>
                        <p class="eyebrow">SPMU review</p>
                        <h2>Waiting for Head decision</h2>
                    </div>
                </div>

                <div class="empty-state">
                    <strong>This request is awaiting SPMU Head approval.</strong>
                    <span>Operational processing by the Action Officer starts only after SPMU Head approval and inventory allocation for pickup.</span>
                </div>
            @endif
        </article>
        </div>

        <div class="spmu-review-bottom-row">
            <article class="card spmu-left-borrowing-info">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Request details</p>
                    <h2>Borrowing information</h2>
                </div>
            </div>

            <div class="spmu-left-borrowing-grid">
                <div class="spmu-left-borrowing-cell">
                    <span>Borrower</span>
                    <strong>{{ $borrowingRequest->borrower->full_name }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Office / Department</span>
                    <strong>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Event Details</span>
                    <strong>{{ $v->purpose_event }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Location</span>
                    <strong>{{ $v->location }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Schedule Date</span>
                    <strong>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Expected Return Date</span>
                    <strong>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Student Activity</span>
                    <strong>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</strong>
                </div>

</div>
        </article>

            <div class="spmu-inventory-slot">
                @include('requests.partials.spmu-availability-review')
            </div>
        </div>
    </div>
</section>
@endif

@if($isBorrower)
<div class="borrower-progress-always" aria-label="Current request progress">
    <x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" />
</div>

<section class="content-area borrower-request-workspace" data-borrower-request-tabs>
    <div class="borrower-request-tabs" role="tablist" aria-label="Request details">
        <button
            type="button"
            class="borrower-request-tab is-active"
            role="tab"
            aria-selected="true"
            aria-controls="borrower-request-overview"
            data-request-tab="overview"
        >
            Overview
        </button>

        <button
            type="button"
            class="borrower-request-tab"
            role="tab"
            aria-selected="false"
            aria-controls="borrower-request-documents"
            data-request-tab="documents"
        >
            Documents
        </button>

    </div>

    <div
        id="borrower-request-overview"
        class="borrower-request-panel"
        role="tabpanel"
        data-request-panel="overview"
    >
        <div class="borrower-overview-grid">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Request details</p>
                        <h2>Borrowing information</h2>
                    </div>
                </div>

                <div class="borrower-fact-grid">
                    <div>
                        <span>Office / Department</span>
                        <strong>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</strong>
                    </div>
                    <div>
                        <span>Location</span>
                        <strong>{{ $v->location }}</strong>
                    </div>
                    <div>
                        <span>Scheduled Use</span>
                        <strong>{{ optional($v->schedule_date ?: $v->needed_from)->format('d M Y') }}</strong>
                    </div>
                    <div>
                        <span>Expected Return</span>
                        <strong>{{ optional($v->return_date ?: $v->return_due_at)->format('d M Y') }}</strong>
                    </div>
                    <div>
                        <span>Student Activity</span>
                        <strong>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</strong>
                    </div>
                </div>
            </article>

            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Requested property</p>
                        <div class="borrower-section-title-inline">
                            <h2>Items</h2>
                            <span class="borrower-item-count">{{ $v->items->count() }} {{ $v->items->count() === 1 ? 'item' : 'items' }}</span>
                        </div>
                    </div>
                </div>

                <div class="borrower-item-list">
                    @foreach($v->items as $item)
                        @php
                            $requestedQty = $item->requested_quantity + 0;
                            $approvedQty = $item->approved_quantity === null
                                ? null
                                : $item->approved_quantity + 0;
                            $quantityChanged = $approvedQty !== null
                                && (float) $approvedQty !== (float) $requestedQty;
                        @endphp

                        <div class="borrower-item-row">
                            <div>
                                <strong>{{ $item->description_snapshot }}</strong>
                                <small>{{ str($item->use_location)->replace('_',' ')->title() }}</small>
                            </div>

                            <div class="borrower-item-quantity">
                                @if($approvedQty === null)
                                    <strong>{{ $requestedQty }} {{ $item->unit_snapshot }}</strong>
                                    <small>Pending SPMU approval</small>
                                @elseif($quantityChanged)
                                    <strong>{{ $approvedQty }} {{ $item->unit_snapshot }}</strong>
                                    <small>Requested {{ $requestedQty }} {{ $item->unit_snapshot }}</small>
                                @else
                                    <strong>{{ $approvedQty }} {{ $item->unit_snapshot }}</strong>
                                    <small>Approved quantity</small>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>
        </div>
    </div>

    <div
        id="borrower-request-documents"
        class="borrower-request-panel"
        role="tabpanel"
        data-request-panel="documents"
        hidden
    >
        <div class="borrower-documents-grid">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Supporting documents</p>
                        <h2>Uploaded scans</h2>
                    </div>
                </div>

                @forelse($currentDocs as $doc)
                    <div class="evidence-row">
                        <div>
                            <strong>
                                {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                                    ? 'Approved Borrowing Request Letter'
                                    : 'Permission to Conduct Letter' }}
                            </strong>
                            <small>
                                Version {{ $doc->version_no }}
                                ·
                                {{ str($doc->verification_status)->replace('_',' ')->title() }}
                            </small>
                        </div>

                        <a
                            class="button secondary small ui-pressable"
                            href="{{ route('files.show', $doc->file, false) }}"
                            target="_blank"
                            rel="noopener"
                        >
                            View
                        </a>
                    </div>
                @empty
                    <div class="empty-state">
                        <strong>No uploaded supporting documents.</strong>
                    </div>
                @endforelse
            </article>

            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Operational documents</p>
                        <div class="borrower-section-title-inline">
                            <h2>Forms for physical processing</h2>
                            <p class="meta">Download the applicable forms.</p>
                        </div>
                    </div>
                </div>

                @if($borrowingRequest->custody && $borrowingRequest->final_approved_at)
                    <div class="document-list borrower-document-list">
                        <article>
                            <div>
                                <strong>Borrower Slip</strong>
                                <small>Required for physical handover.</small>
                            </div>
                            @if($borrowerSlipDocument)
                                <a class="button primary small ui-pressable" href="{{ route('documents.download', $borrowerSlipDocument) }}">Download / Print</a>
                            @else
                                <span class="status-badge status-neutral">Preparing</span>
                            @endif
                        </article>

                        <article>
                            <div>
                                <strong>Laundry Form</strong>
                                <small>{{ $requestHasLaundry ? 'Applicable to this borrowing.' : 'Not applicable.' }}</small>
                            </div>
                            @if(!$requestHasLaundry)
                                <span class="status-badge status-neutral">Not applicable</span>
                            @elseif($laundryFormDocument)
                                <a class="button secondary small ui-pressable" href="{{ route('documents.download', $laundryFormDocument) }}">Download / Print</a>
                            @else
                                <span class="status-badge status-neutral">Preparing</span>
                            @endif
                        </article>

                        <article>
                            <div>
                                <strong>Gate Pass</strong>
                                <small>{{ $requestHasOffCampus ? 'Applicable to this borrowing.' : 'Not applicable.' }}</small>
                            </div>
                            @if(!$requestHasOffCampus)
                                <span class="status-badge status-neutral">Not applicable</span>
                            @elseif($gatePassDocument)
                                <a class="button secondary small ui-pressable" href="{{ route('documents.download', $gatePassDocument) }}">Download / Print</a>
                            @else
                                <span class="status-badge status-neutral">Preparing</span>
                            @endif
                        </article>
                    </div>
                @else
                    <div class="empty-state">
                        <strong>Operational forms are available after SPMU approval.</strong>
                    </div>
                @endif
            </article>
        </div>
    </div>

</section>
@elseif(!$isUnderSpmuReview)
<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Request details</p>
                <h2>Borrowing information</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>

            <dt>Office / Department</dt>
            <dd>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</dd>

            <dt>Event Details</dt>
            <dd>{{ $v->purpose_event }}</dd>

            <dt>Location</dt>
            <dd>{{ $v->location }}</dd>

            <dt>Schedule Date</dt>
            <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</dd>

            <dt>Student Activity</dt>
            <dd>{{ $v->represents_student_activity ? 'Yes' : 'No' }}</dd>
        </dl>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Document verification</p>
                <h2>Uploaded scanned documents</h2>
            </div>
        </div>

        @forelse($currentDocs as $doc)
            <div class="evidence-row">
                <div>
                    <strong>
                        {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}
                    </strong>
                    <small>
                        Version {{ $doc->version_no }}
                        ·
                        {{ str($doc->verification_status)->replace('_',' ')->title() }}
                    </small>
                </div>

                <a
                    class="button secondary small ui-pressable"
                    href="{{ route('files.show', $doc->file, false) }}"
                    target="_blank"
                    rel="noopener"
                >
                    View Scan
                </a>
            </div>
        @empty
            <div class="empty-state">
                <strong>No current scanned supporting document.</strong>
            </div>
        @endforelse
    </article>
</section>

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Requested property</p>
                <h2>Items and quantities</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Requested</th>
                        <th>Approved Quantity</th>
                        <th>Premises</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($v->items as $item)
                    <tr>
                        <td>{{ $item->description_snapshot }}</td>
                        <td>{{ $item->requested_quantity + 0 }} {{ $item->unit_snapshot }}</td>
                        <td>
                            {{ $item->approved_quantity === null
                                ? 'Not approved yet'
                                : ($item->approved_quantity + 0).' '.$item->unit_snapshot }}
                        </td>
                        <td>{{ str($item->use_location)->replace('_',' ')->title() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif



@if($borrowingRequest->custody && !$isBorrower)
<section class="content-area">
    <div class="action-panel action-neutral">
        <div>
            <p class="eyebrow">Operational record</p>
            <h2>{{ $requestIsCompleted ? 'Completed custody record' : 'Release & Return Record' }}</h2>
            <p>Open the linked custody record for release, physical return processing, and final reconciliation.</p>
        </div>

        <a
            class="button primary ui-pressable"
            href="{{ route('custody.show', $borrowingRequest->custody) }}"
        >
            View Custody Record
        </a>
    </div>
</section>
@endif

@if($pendingCancellation)
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Cancellation requested</p>
                <h2>SPMU confirmation required</h2>
            </div>
            <x-status-badge status="PENDING_SPMU" />
        </div>

        <p><strong>Reason:</strong> {{ $pendingCancellation->reason }}</p>
        <p class="meta">The reservation remains active until SPMU approves this cancellation.</p>

        @if($isSpmu)
            <form
                method="post"
                action="{{ route('requests.cancellation.review', $borrowingRequest) }}"
                class="form-grid"
            >
                @csrf
                <label>
                    Review remarks
                    <textarea name="remarks"></textarea>
                </label>
                <div class="inline-actions">
                    <button
                        class="button primary"
                        name="decision"
                        value="APPROVED"
                    >
                        Confirm Cancellation & Restore Reservation
                    </button>
                    <button
                        class="button danger"
                        name="decision"
                        value="REJECTED"
                    >
                        Reject Cancellation
                    </button>
                </div>
            </form>
        @endif
    </article>
</section>
@endif

@if(
    $isBorrower
    && !in_array(
        $borrowingRequest->status,
        [
            App\Enums\RequestStatus::Draft,
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Expired,
        ],
        true
    )
    && !$borrowingRequest->custody?->released_at
    && !$pendingCancellation
)
<section class="content-area">
    <article class="card request-cancel-card" data-request-cancel-workspace>
        <div class="card-header">
            <div>
                <p class="eyebrow">Request action</p>
                <h2>Cancel this request</h2>
            </div>
        </div>

        <p class="meta">
            You may cancel this request until the items are physically released to you.
            @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                Any approved but unreleased allocation will return to Available inventory. Any active pickup window, Borrower Slip, and Gate Pass prepared for this request will no longer be valid.
            @endif
        </p>

        <button
            class="button danger ui-pressable"
            type="button"
            data-request-cancel-trigger
        >
            Cancel Request
        </button>

        <form
            method="post"
            action="{{ route('requests.cancel', $borrowingRequest) }}"
            data-request-cancel-form
        >
            @csrf
            <input type="hidden" name="reason" value="" data-request-cancel-reason>
        </form>

        <dialog class="spmu-confirm-dialog" data-request-cancel-dialog>
            <div class="spmu-confirm-dialog__surface">
                <div class="spmu-confirm-dialog__icon spmu-confirm-dialog__icon--danger" aria-hidden="true">!</div>

                <div>
                    <h2>Cancel this borrowing request?</h2>
                    <p class="meta">
                        This action takes effect immediately because the items have not been physically released yet.
                        @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                            The approved allocation will be released back to Available inventory and any pending pickup documents will be invalidated.
                        @endif
                    </p>

                    <div class="spmu-dialog-remarks">
                        <label for="borrower-cancellation-reason">Cancellation reason *</label>
                        <textarea
                            id="borrower-cancellation-reason"
                            rows="5"
                            maxlength="1000"
                            data-request-cancel-reason-field
                            placeholder="Example: The activity was cancelled or the items are no longer needed."
                        ></textarea>
                        <small>Provide a clear reason. It will be saved in the request history.</small>
                        <p class="field-error" data-request-cancel-error hidden></p>
                    </div>
                </div>

                <div class="spmu-confirm-dialog__actions">
                    <button class="button secondary ui-pressable" type="button" data-request-cancel-back>Go Back</button>
                    <button class="button danger ui-pressable" type="button" data-request-cancel-confirm>Yes, Cancel Request</button>
                </div>
            </div>
        </dialog>
    </article>
</section>
@endif



@if($isBorrower)
<script>
(() => {
    const initializeBorrowerRequestTabs = () => {
        const workspace = document.querySelector('[data-borrower-request-tabs]');

        if (!workspace || workspace.dataset.tabsInitialized === '1') {
            return;
        }

        const tabs = [...workspace.querySelectorAll('[data-request-tab]')];
        const panels = [...workspace.querySelectorAll('[data-request-panel]')];

        if (!tabs.length || !panels.length) {
            return;
        }

        workspace.dataset.tabsInitialized = '1';

        const activate = (name, updateHash = true) => {
            const target = panels.find((panel) => panel.dataset.requestPanel === name);

            if (!target) {
                return;
            }

            tabs.forEach((tab) => {
                const active = tab.dataset.requestTab === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panels.forEach((panel) => {
                panel.hidden = panel !== target;
            });

            if (updateHash && window.history?.replaceState) {
                window.history.replaceState(null, '', `#request-${name}`);
            }
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => activate(tab.dataset.requestTab));
        });

        const hashMatch = window.location.hash.match(/^#request-(overview|documents)$/);

        if (hashMatch) {
            activate(hashMatch[1], false);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeBorrowerRequestTabs, { once: true });
    } else {
        initializeBorrowerRequestTabs();
    }
})();
</script>
@endif

{{-- BORROWER_REQUEST_CANCEL_FIX_V1_START --}}
<script>
(() => {
    const initializeBorrowerRequestCancellation = () => {
        const workspace = document.querySelector(
            '[data-request-cancel-workspace]'
        );

        if (!workspace || workspace.dataset.cancelInitialized === '1') {
            return;
        }

        const trigger = workspace.querySelector(
            '[data-request-cancel-trigger]'
        );

        const dialog = workspace.querySelector(
            '[data-request-cancel-dialog]'
        );

        const form = workspace.querySelector(
            '[data-request-cancel-form]'
        );

        const hiddenReason = workspace.querySelector(
            '[data-request-cancel-reason]'
        );

        const reasonField = workspace.querySelector(
            '[data-request-cancel-reason-field]'
        );

        const error = workspace.querySelector(
            '[data-request-cancel-error]'
        );

        const back = workspace.querySelector(
            '[data-request-cancel-back]'
        );

        const confirm = workspace.querySelector(
            '[data-request-cancel-confirm]'
        );

        if (
            !trigger
            || !dialog
            || !form
            || !hiddenReason
            || !reasonField
            || !confirm
        ) {
            return;
        }

        workspace.dataset.cancelInitialized = '1';

        const clearError = () => {
            if (!error) {
                return;
            }

            error.textContent = '';
            error.hidden = true;
        };

        const closeDialog = () => {
            clearError();

            if (
                dialog.open
                && typeof dialog.close === 'function'
            ) {
                dialog.close();
            }
        };

        const submitCancellation = (reason) => {
            const cleanReason = (reason || '').trim();

            if (!cleanReason) {
                if (error) {
                    error.textContent =
                        'Please provide a cancellation reason.';
                    error.hidden = false;
                }

                reasonField.focus();
                return;
            }

            hiddenReason.value = cleanReason;
            confirm.disabled = true;
            form.submit();
        };

        trigger.addEventListener('click', () => {
            clearError();
            reasonField.value = '';

            if (typeof dialog.showModal === 'function') {
                dialog.showModal();

                window.setTimeout(
                    () => reasonField.focus(),
                    0
                );

                return;
            }

            const fallbackReason = window.prompt(
                'Enter the cancellation reason:'
            );

            if (fallbackReason !== null) {
                submitCancellation(fallbackReason);
            }
        });

        back?.addEventListener(
            'click',
            closeDialog
        );

        confirm.addEventListener('click', () => {
            submitCancellation(
                reasonField.value
            );
        });

        reasonField.addEventListener(
            'input',
            clearError
        );

        dialog.addEventListener(
            'cancel',
            (event) => {
                event.preventDefault();
                closeDialog();
            }
        );

        dialog.addEventListener(
            'click',
            (event) => {
                if (event.target === dialog) {
                    closeDialog();
                }
            }
        );
    };

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeBorrowerRequestCancellation,
            { once: true }
        );
    } else {
        initializeBorrowerRequestCancellation();
    }
})();
</script>
{{-- BORROWER_REQUEST_CANCEL_FIX_V1_END --}}


@unless($isBorrower)
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Audit history</p>
                <h2>Status changes</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>When</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Actor</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($borrowingRequest->statusHistory as $history)
                    <tr>
                        <td>{{ optional($history->changed_at)->format('d M Y, g:i A') }}</td>
                        @php
                            $fromStatus = match($history->from_status) {
                                'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                                default => $history->from_status,
                            };
                            $toStatus = match($history->to_status) {
                                'UNDER_GSU', 'UNDER_VPAF' => 'LEGACY_REVIEW',
                                default => $history->to_status,
                            };
                        @endphp
                        <td>{{ $fromStatus ?: '—' }}</td>
                        <td>{{ $toStatus }}</td>
                        <td>{{ $history->actor?->full_name ?: 'System' }}</td>
                        <td>{{ $history->reason ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No status history.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>
@endunless

@if($isUnderSpmuReview)
<style>
.spmu-verification-grid {
    display: grid;
    width: 100%;
    min-width: 0;
    grid-template-columns: minmax(0, 1.35fr) minmax(400px, .65fr);
    gap: 24px;
    align-items: stretch;
}

.spmu-verification-grid > * {
    min-width: 0;
}

.spmu-document-panel,
.spmu-checklist-panel,
.spmu-verification-grid > .scanned-document-card {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
}

/* Keep the document preview and decision panel aligned as one review row. */
.spmu-verification-grid > .scanned-document-card,
.spmu-checklist-panel {
    height: 100%;
    align-self: stretch;
}

.spmu-verification-grid > .scanned-document-card {
    display: flex;
    flex-direction: column;
}

.spmu-verification-grid > .scanned-document-card .scanned-pdf-stage {
    flex: 1 1 auto;
    height: auto;
    min-height: clamp(620px, 70vh, 820px);
}

.spmu-verification-grid > .scanned-document-card .scanned-image-viewer {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
}

.spmu-verification-grid > .scanned-document-card .scanned-image-stage {
    flex: 1 1 auto;
    height: auto;
    min-height: clamp(620px, 70vh, 820px);
}

.spmu-checklist-panel {
    display: flex;
    flex-direction: column;
}

.spmu-verification-form {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
}

.spmu-review-summary {
    margin-bottom: 2px;
}

.spmu-review-footer {
    margin-top: auto;
    padding-top: 18px;
    border-top: 1px solid var(--border, #d7dee8);
}

.spmu-review-secondary-grid {
    align-items: stretch;
}

.review-column-card {
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.review-scroll-area {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
}

.review-detail-scroll,
.review-table-scroll {
    max-height: clamp(420px, 48vh, 620px);
}

.review-detail-scroll {
    padding-right: 4px;
}

.review-table-scroll table thead th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--surface-subtle, #f5f7fb);
}

.review-detail-scroll .detail-list {
    margin-bottom: 0;
}

.spmu-review-footer__note {
    margin: 12px 0 0;
}

.spmu-preview {
    display: grid;
    gap: 12px;
}

.spmu-preview-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.spmu-preview-toolbar__group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.spmu-preview-zoom,
.spmu-preview-page {
    min-width: 58px;
    text-align: center;
    font-size: .875rem;
    font-weight: 700;
    color: var(--text-muted, #64748b);
}

.spmu-preview-stage {
    min-height: 620px;
    height: min(72vh, 820px);
    border: 1px solid var(--border, #d7dee8);
    border-radius: 14px;
    overflow: hidden;
    background: #eef2f7;
}

.spmu-preview-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: #fff;
}

.spmu-preview-image-scroll {
    width: 100%;
    height: 100%;
    overflow: auto;
    display: grid;
    place-items: start center;
    padding: 18px;
}

.spmu-preview-image {
    display: block;
    max-width: 100%;
    height: auto;
    transform-origin: top center;
    transition: transform 120ms ease;
}

.spmu-supporting-document {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    margin: 16px 0;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 12px;
}

.spmu-supporting-document div {
    display: grid;
    gap: 3px;
}

.spmu-supporting-document small,
.spmu-check-row small {
    color: var(--text-muted, #64748b);
}

.spmu-checklist {
    display: grid;
    gap: 10px;
    margin: 18px 0;
}

.spmu-check-row {
    display: grid;
    grid-template-columns: 22px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 14px;
    border: 1px solid var(--border, #d7dee8);
    border-radius: 12px;
    cursor: pointer;
}

.spmu-check-row:has(input:checked) {
    border-color: #8ca5c5;
    background: rgba(24, 61, 105, .045);
}

.spmu-check-row input {
    width: 18px;
    height: 18px;
    margin-top: 2px;
}

.spmu-check-row span {
    display: grid;
    gap: 3px;
}

.spmu-check-row strong,
.spmu-check-row small,
.spmu-checklist-panel p,
.spmu-supporting-document strong,
.spmu-supporting-document small {
    overflow-wrap: anywhere;
    word-break: normal;
}

.spmu-preview-help {
    margin: 0;
    color: var(--text-muted, #64748b);
    font-size: .8rem;
    line-height: 1.45;
}

.spmu-document-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px 0;
    border-top: 1px solid var(--border, #d7dee8);
    border-bottom: 1px solid var(--border, #d7dee8);
}

.spmu-remarks {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}

.spmu-remarks label {
    display: grid;
    gap: 3px;
}

.spmu-decision-actions {
    display: grid;
    gap: 10px;
    margin-top: 0;
}

.spmu-decision-actions .button {
    width: 100%;
}

.spmu-decision-actions .button:disabled {
    opacity: .48;
    cursor: not-allowed;
    transform: none;
}

.spmu-dialog-remarks {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}

.spmu-dialog-remarks textarea {
    width: 100%;
    resize: vertical;
}

.field-error {
    margin: 0;
    color: #b42318;
    font-size: .875rem;
}

.spmu-confirm-dialog {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    right: auto !important;
    bottom: auto !important;
    transform: translate(-50%, -50%);
    width: min(520px, calc(100vw - 32px));
    max-height: calc(100dvh - 32px);
    margin: 0 !important;
    padding: 0;
    overflow: auto;
    border: 0;
    border-radius: 18px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .25);
    z-index: 10000;
}

.spmu-confirm-dialog::backdrop {
    background: rgba(15, 23, 42, .52);
}

.spmu-confirm-dialog__surface {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr);
    gap: 16px;
    padding: 24px;
    background: var(--surface, #fff);
}

.spmu-confirm-dialog__icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    font-size: 1.2rem;
    font-weight: 800;
    background: #fff4db;
    color: #8a5a00;
}

.spmu-confirm-dialog__icon--danger {
    background: #feeceb;
    color: #b42318;
}

.request-cancel-card {
    display: grid;
    gap: 14px;
}

.request-cancel-card > .button {
    justify-self: start;
}

.spmu-confirm-dialog__surface h2 {
    margin-top: 2px;
}

.spmu-confirm-dialog__actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
}

@media (max-width: 1180px) {
    .spmu-verification-grid {
        grid-template-columns: 1fr;
    }

    .spmu-review-secondary-grid {
        grid-template-columns: 1fr;
    }

    .review-detail-scroll,
    .review-table-scroll {
        max-height: none;
        overflow: visible;
    }

    .spmu-verification-grid > .scanned-document-card,
    .spmu-checklist-panel {
        height: auto;
    }

    .spmu-verification-grid > .scanned-document-card .scanned-pdf-stage,
    .spmu-verification-grid > .scanned-document-card .scanned-image-stage {
        min-height: 520px;
        height: 64vh;
    }

    .spmu-review-footer {
        margin-top: 18px;
    }

    .spmu-preview-stage {
        min-height: 480px;
        height: 62vh;
    }
}

@media (max-width: 620px) {
    .spmu-preview-toolbar,
    .spmu-supporting-document,
    .spmu-confirm-dialog__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .spmu-preview-toolbar__group {
        justify-content: center;
    }

    .spmu-preview-stage {
        min-height: 400px;
        height: 56vh;
    }

    .spmu-confirm-dialog__actions .button {
        width: 100%;
    }
}
</style>

<script>
(() => {
    const workspace = document.querySelector('[data-spmu-verification-workspace]');

    if (!workspace) return;

    const form = workspace.querySelector('[data-verification-form]');
    const dialog = workspace.querySelector('[data-verification-confirm-dialog]');

    if (!form || !dialog) return;

    const checks = [...form.querySelectorAll('[data-verification-check]')];
    const approveButton = form.querySelector('[data-approve-button]');
    const decisionInput = form.querySelector('[data-verification-decision]');
    const remarksInput = form.querySelector('[data-verification-remarks]');
    const inlineError = form.querySelector('[data-verification-inline-error]');
    const triggers = [...form.querySelectorAll('[data-decision-trigger]')];

    const confirmTitle = dialog.querySelector('[data-confirm-title]');
    const confirmMessage = dialog.querySelector('[data-confirm-message]');
    const confirmSubmit = dialog.querySelector('[data-confirm-submit]');
    const confirmCancel = dialog.querySelector('[data-confirm-cancel]');
    const remarksWrap = dialog.querySelector('[data-confirm-remarks-wrap]');
    const remarksLabel = dialog.querySelector('[data-confirm-remarks-label]');
    const remarksField = dialog.querySelector('[data-confirm-remarks]');
    const remarksHelp = dialog.querySelector('[data-confirm-remarks-help]');
    const remarksError = dialog.querySelector('[data-confirm-remarks-error]');

    const requiredSupportingPresent = form.dataset.requiredSupportingPresent === '1';
    let pendingDecision = '';

    const decisionCopy = {
        APPROVED: {
            title: 'Verify and approve this request?',
            message: 'The request will be approved and the approved quantities will be allocated/held for this borrower. They are not yet physically issued. The SPMU Action Officer will schedule pickup and complete the physical handover.',
            confirm: 'Yes, Verify & Approve',
            tone: 'primary',
        },
        RETURNED_FOR_REVISION: {
            title: 'Return this request for revision?',
            message: 'The borrower will receive your remarks and must correct the request or supporting documents before resubmitting.',
            confirm: 'Return for Revision',
            tone: 'secondary',
        },
        REJECTED: {
            title: 'Reject this borrowing request?',
            message: 'This closes the request as rejected. The borrower will receive the reason you provide below.',
            confirm: 'Reject Request',
            tone: 'danger',
        },
    };

    const checklistComplete = () =>
        requiredSupportingPresent &&
        checks.length === 4 &&
        checks.every((checkbox) => checkbox.checked);

    const updateApproveState = () => {
        if (approveButton) approveButton.disabled = !checklistComplete();
    };

    const showInlineError = (message = '') => {
        if (!inlineError) return;
        inlineError.textContent = message;
        inlineError.hidden = message === '';
    };

    const clearRemarksError = () => {
        if (!remarksError) return;
        remarksError.textContent = '';
        remarksError.hidden = true;
    };

    const configureRemarks = (decision) => {
        const needsRemarks = decision !== 'APPROVED';
        remarksWrap.hidden = !needsRemarks;
        clearRemarksError();

        if (!needsRemarks) {
            remarksField.value = '';
            return;
        }

        remarksField.value = remarksInput.value || '';

        if (decision === 'RETURNED_FOR_REVISION') {
            remarksLabel.textContent = 'Revision instructions *';
            remarksHelp.textContent = 'State exactly what the borrower needs to correct before resubmitting.';
            remarksField.placeholder = 'Example: Upload a clearer signed BR Letter and correct the requested quantity.';
        } else {
            remarksLabel.textContent = 'Reason for rejection *';
            remarksHelp.textContent = 'State a clear reason that can be shown to the borrower.';
            remarksField.placeholder = 'Example: The request cannot be approved for the selected activity or schedule.';
        }
    };

    const openConfirmation = (decision) => {
        const copy = decisionCopy[decision];
        pendingDecision = decision;
        decisionInput.value = decision;
        confirmTitle.textContent = copy.title;
        confirmMessage.textContent = copy.message;
        confirmSubmit.textContent = copy.confirm;
        confirmSubmit.className = `button ${copy.tone} ui-pressable`;
        configureRemarks(decision);

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            if (decision !== 'APPROVED') {
                window.setTimeout(() => remarksField.focus(), 0);
            }
            return;
        }

        if (decision === 'APPROVED') {
            if (window.confirm(`${copy.title}\n\n${copy.message}`)) form.submit();
            return;
        }

        const fallbackRemarks = window.prompt(
            decision === 'REJECTED'
                ? 'Enter the reason for rejection:'
                : 'Enter the revision instructions:'
        );

        if (fallbackRemarks && fallbackRemarks.trim()) {
            remarksInput.value = fallbackRemarks.trim();
            form.submit();
        }
    };

    checks.forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            showInlineError('');
            updateApproveState();
        });
    });

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const decision = trigger.dataset.decisionTrigger;
            showInlineError('');

            if (decision === 'APPROVED' && !checklistComplete()) {
                showInlineError(
                    requiredSupportingPresent
                        ? 'Complete all four verification checks before approving.'
                        : 'The required supporting document is missing. Approval is unavailable until the required document is attached.'
                );
                return;
            }

            openConfirmation(decision);
        });
    });

    confirmCancel?.addEventListener('click', () => {
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    confirmSubmit?.addEventListener('click', () => {
        if (!pendingDecision) return;

        if (pendingDecision !== 'APPROVED') {
            const value = remarksField.value.trim();

            if (!value) {
                remarksError.textContent =
                    pendingDecision === 'REJECTED'
                        ? 'Enter the reason for rejection before continuing.'
                        : 'Enter revision instructions before continuing.';
                remarksError.hidden = false;
                remarksField.focus();
                return;
            }

            remarksInput.value = value;
        } else {
            remarksInput.value = '';
        }

        confirmSubmit.disabled = true;
        form.submit();
    });

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        pendingDecision = '';
        decisionInput.value = '';
        clearRemarksError();
        dialog.close();
    });

    remarksField?.addEventListener('input', clearRemarksError);
    updateApproveState();
})();


(() => {
    const workspace = document.querySelector('[data-request-cancel-workspace]');
    if (!workspace) return;

    const trigger = workspace.querySelector('[data-request-cancel-trigger]');
    const dialog = workspace.querySelector('[data-request-cancel-dialog]');
    const form = workspace.querySelector('[data-request-cancel-form]');
    const hiddenReason = workspace.querySelector('[data-request-cancel-reason]');
    const reasonField = workspace.querySelector('[data-request-cancel-reason-field]');
    const error = workspace.querySelector('[data-request-cancel-error]');
    const back = workspace.querySelector('[data-request-cancel-back]');
    const confirm = workspace.querySelector('[data-request-cancel-confirm]');

    if (!trigger || !dialog || !form || !hiddenReason || !reasonField || !confirm) return;

    const clearError = () => {
        if (!error) return;
        error.textContent = '';
        error.hidden = true;
    };

    const closeDialog = () => {
        clearError();
        if (dialog.open && typeof dialog.close === 'function') dialog.close();
    };

    trigger.addEventListener('click', () => {
        clearError();
        reasonField.value = '';

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            window.setTimeout(() => reasonField.focus(), 0);
            return;
        }

        const fallbackReason = window.prompt('Enter the cancellation reason:');
        if (fallbackReason && fallbackReason.trim()) {
            hiddenReason.value = fallbackReason.trim();
            form.submit();
        }
    });

    back?.addEventListener('click', closeDialog);

    confirm.addEventListener('click', () => {
        const reason = reasonField.value.trim();
        if (!reason) {
            if (error) {
                error.textContent = 'Please provide a cancellation reason.';
                error.hidden = false;
            }
            reasonField.focus();
            return;
        }

        hiddenReason.value = reason;
        confirm.disabled = true;
        form.submit();
    });

    reasonField.addEventListener('input', clearError);

    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });
})();

</script>
@endif

















<style>
/* SPMU_CANONICAL_REVIEW_LAYOUT_START */

@media (min-width: 1181px) {
    .spmu-verification-grid {
        display: block !important;
    }

    .spmu-review-layout {
        display: grid;
        gap: 20px;
        width: 100%;
        min-width: 0;
    }

    .spmu-review-top-row,
    .spmu-review-bottom-row {
        display: grid;
        grid-template-columns: minmax(0, 52%) minmax(0, 48%);
        gap: 20px;
        width: 100%;
        min-width: 0;
        align-items: stretch;
    }

    .spmu-review-top-row > *,
    .spmu-review-bottom-row > * {
        min-width: 0;
    }

    /* Scanned request: show the complete page immediately; users may zoom only when needed. */
    .spmu-scan-slot {
        min-width: 0;
    }

    .spmu-scan-slot > .scanned-document-card,
    .spmu-scan-slot > .formal-document-review-card {
        width: 100%;
        min-width: 0;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .spmu-scan-slot .scanned-pdf-stage,
    .spmu-scan-slot .scanned-image-stage,
    .spmu-scan-slot .formal-document-review-stage,
    .spmu-scan-slot .formal-pdf-stage {
        height: clamp(620px, 68vh, 760px) !important;
        min-height: 620px !important;
        max-height: 760px !important;
        overflow: hidden !important;
    }

    .spmu-scan-slot iframe,
    .spmu-scan-slot embed,
    .spmu-scan-slot object,
    .spmu-scan-slot .scanned-pdf-frame,
    .spmu-scan-slot .formal-document-review-frame,
    .spmu-scan-slot .formal-pdf-frame {
        display: block;
        width: 100% !important;
        height: 100% !important;
        min-width: 0 !important;
        min-height: 0 !important;
        border: 0;
    }

    /*
     * Bottom row: CSS Grid provides the synchronization.
     * Whichever side is naturally taller determines the row height.
     * The other card stretches to exactly the same outer height.
     */
    .spmu-review-bottom-row {
        align-items: stretch;
    }

    .spmu-left-borrowing-info,
    .spmu-inventory-slot {
        height: 100%;
        min-height: 0;
        align-self: stretch !important;
    }

    .spmu-left-borrowing-info {
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .spmu-left-borrowing-info .card-header {
        flex: 0 0 auto;
        margin-bottom: 0;
    }

    .spmu-left-borrowing-grid {
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        grid-auto-rows: auto !important;
        align-content: start;
        min-height: 0;
        border-top: 1px solid var(--border, #d7dee8);
    }

    .spmu-left-borrowing-cell {
        min-width: 0;
        min-height: 68px;
        height: auto !important;
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 4px;
        border-right: 1px solid var(--border, #d7dee8);
        border-bottom: 1px solid var(--border, #d7dee8);
    }

    .spmu-left-borrowing-cell:nth-child(even) {
        border-right: 0;
    }

    .spmu-left-borrowing-cell span {
        color: var(--text-muted, #64748b);
        font-size: .88rem;
        line-height: 1.25;
    }

    .spmu-left-borrowing-cell strong {
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: normal;
        line-height: 1.35;
    }

    /*
     * Inventory slot fills the same bottom-row height.
     * If many requested items exist, only the table region scrolls.
     */
    .spmu-inventory-slot {
        display: flex;
        min-width: 0;
    }

    .spmu-inventory-slot > [data-spmu-availability-review],
    .spmu-inventory-slot > .spmu-availability-review {
        flex: 1 1 auto;
        width: 100%;
        min-width: 0;
        min-height: 0;
        height: 100%;
        max-height: none !important;
        overflow: hidden !important;
        box-sizing: border-box;
        display: flex !important;
        flex-direction: column !important;
    }

    .spmu-inventory-slot .spmu-availability-table-wrap {
        flex: 1 1 auto !important;
        min-height: 112px !important;
        max-height: 240px !important;
        overflow-y: auto !important;
        overflow-x: hidden !important;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    .spmu-inventory-slot .spmu-availability-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }

    .spmu-inventory-slot .spmu-availability-table thead th {
        position: sticky !important;
        top: 0;
        z-index: 3;
    }

    .spmu-inventory-slot .spmu-availability-table th,
    .spmu-inventory-slot .spmu-availability-table td {
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
        vertical-align: middle !important;
    }

    .spmu-inventory-slot .status-badge {
        max-width: 100%;
        white-space: normal;
        overflow-wrap: anywhere;
        text-align: center;
        line-height: 1.1;
    }

    /* Keep Review and decide/checklist/button design unchanged. */
    .spmu-review-top-row > .spmu-checklist-panel {
        width: 100%;
        min-width: 0;
        align-self: start;
    }
}

@media (max-width: 1180px) {
    .spmu-review-layout,
    .spmu-review-top-row,
    .spmu-review-bottom-row {
        display: grid;
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .spmu-scan-slot .scanned-pdf-stage,
    .spmu-scan-slot .scanned-image-stage,
    .spmu-scan-slot .formal-document-review-stage,
    .spmu-scan-slot .formal-pdf-stage {
        height: clamp(500px, 65vh, 680px) !important;
        min-height: 500px !important;
    }

    .spmu-left-borrowing-info,
    .spmu-inventory-slot,
    .spmu-inventory-slot > [data-spmu-availability-review],
    .spmu-inventory-slot > .spmu-availability-review {
        height: auto !important;
        max-height: none !important;
    }

    .spmu-inventory-slot .spmu-availability-table-wrap {
        max-height: 300px !important;
        overflow-y: auto !important;
    }
}

@media (max-width: 620px) {
    .spmu-left-borrowing-grid {
        grid-template-columns: 1fr !important;
    }

    .spmu-left-borrowing-cell {
        border-right: 0;
    }
}

/* SPMU_CANONICAL_REVIEW_LAYOUT_END */
</style>

{{-- SPMU_CANONICAL_PDF_ZOOM_START --}}
<script>
(() => {
    const applyReadablePdfZoom = () => {
        const scope = document.querySelector('.spmu-scan-slot');
        if (!scope) return;

        const viewer = scope.querySelector('iframe[src], embed[src], object[data]');
        if (!viewer || viewer.dataset.spmuReadableZoomApplied === '1') return;

        const attr = viewer.tagName === 'OBJECT' ? 'data' : 'src';
        const current = viewer.getAttribute(attr);
        if (!current) return;

        viewer.dataset.spmuReadableZoomApplied = '1';
        const base = current.split('#')[0];
        viewer.setAttribute(
            attr,
            `${base}#page=1&zoom=page-fit&toolbar=1&navpanes=0&scrollbar=1&view=Fit`
        );
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyReadablePdfZoom, { once: true });
    } else {
        applyReadablePdfZoom();
    }
})();
</script>
{{-- SPMU_CANONICAL_PDF_ZOOM_END --}}


<style>
/* SPMU_REVIEW_ACTIONS_UNCLIP_START */

/*
 * The action buttons already exist in the Blade markup.
 * Older review CSS forced the right card/form into a constrained flex height
 * and clipped the footer with overflow:hidden.
 *
 * This block only restores natural flow for Review and decide.
 * Checklist markup, button markup, Inventory Check, Borrowing Information,
 * and the scanned document layout are not changed.
 */

.spmu-review-top-row {
    align-items: start !important;
}

.spmu-review-top-row > .spmu-checklist-panel {
    width: 100% !important;
    min-width: 0 !important;

    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;

    align-self: start !important;

    display: block !important;
    overflow: visible !important;
}

.spmu-review-top-row .spmu-verification-form {
    display: block !important;

    flex: none !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;

    overflow: visible !important;
}

.spmu-review-top-row .spmu-checklist {
    height: auto !important;
    max-height: none !important;
    overflow: visible !important;
}

.spmu-review-top-row .spmu-review-footer {
    display: block !important;

    position: static !important;
    inset: auto !important;

    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;

    margin-top: 18px !important;
    padding-top: 18px !important;

    overflow: visible !important;
    visibility: visible !important;
}

.spmu-review-top-row .spmu-decision-actions {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 10px !important;

    height: auto !important;
    overflow: visible !important;
    visibility: visible !important;
}

.spmu-review-top-row .spmu-decision-actions > .button {
    display: inline-flex !important;
    width: 100% !important;
    min-height: 44px !important;

    opacity: 1;
    visibility: visible !important;
}

/*
 * Verify & Approve must still visually show disabled until all four
 * verification checks are complete. Restore the intended disabled opacity.
 */
.spmu-review-top-row .spmu-decision-actions > .button:disabled {
    opacity: .48 !important;
}

/* SPMU_REVIEW_ACTIONS_UNCLIP_END */
</style>

{{-- BORROWER_CANCEL_DIALOG_POSITION_FIX_START --}}
<style>
/*
|--------------------------------------------------------------------------
| Borrower Cancel Request Dialog
|--------------------------------------------------------------------------
| This block is intentionally outside the SPMU-only review condition so
| Borrower users receive the modal positioning and styling too.
*/

dialog[data-request-cancel-dialog] {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    right: auto !important;
    bottom: auto !important;

    transform: translate(-50%, -50%) !important;

    width: min(560px, calc(100vw - 32px)) !important;
    max-width: 560px !important;
    max-height: min(680px, calc(100dvh - 40px)) !important;

    margin: 0 !important;
    padding: 0 !important;

    overflow: hidden !important;

    border: 1px solid #d8e1eb !important;
    border-radius: 16px !important;

    background: #ffffff !important;

    box-shadow:
        0 24px 60px rgba(15, 23, 42, 0.22),
        0 8px 24px rgba(15, 23, 42, 0.12) !important;

    z-index: 99999 !important;
}

dialog[data-request-cancel-dialog]::backdrop {
    background: rgba(10, 27, 47, 0.48) !important;
    backdrop-filter: blur(2px);
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__surface {
    display: grid !important;
    grid-template-columns: 42px minmax(0, 1fr) !important;
    gap: 14px !important;

    width: 100% !important;
    max-height: min(680px, calc(100dvh - 40px)) !important;

    padding: 22px !important;
    box-sizing: border-box !important;

    overflow-y: auto !important;

    background: #ffffff !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__icon {
    display: grid !important;
    place-items: center !important;

    width: 42px !important;
    height: 42px !important;

    margin: 0 !important;

    border-radius: 50% !important;

    background: #fff1f0 !important;
    color: #b42318 !important;

    font-size: 18px !important;
    font-weight: 800 !important;
}

dialog[data-request-cancel-dialog] h2 {
    margin: 1px 0 7px !important;

    color: #102a43 !important;

    font-size: 20px !important;
    line-height: 1.3 !important;
}

dialog[data-request-cancel-dialog] h2 + .meta {
    margin: 0 !important;

    color: #63768a !important;

    font-size: 13px !important;
    line-height: 1.5 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks {
    margin-top: 18px !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks label {
    display: block !important;

    margin-bottom: 7px !important;

    color: #30445a !important;

    font-size: 13px !important;
    font-weight: 700 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks textarea {
    display: block !important;

    width: 100% !important;
    min-height: 120px !important;
    max-height: 220px !important;

    padding: 11px 12px !important;

    box-sizing: border-box !important;

    resize: vertical !important;

    border: 1px solid #b8c6d5 !important;
    border-radius: 9px !important;

    background: #ffffff !important;
    color: #102a43 !important;

    font: inherit !important;
    font-size: 13px !important;
    line-height: 1.5 !important;

    outline: none !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks textarea:focus {
    border-color: #1769e0 !important;

    box-shadow:
        0 0 0 3px rgba(23, 105, 224, 0.12) !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks small {
    display: block !important;

    margin-top: 6px !important;

    color: #708196 !important;

    font-size: 11px !important;
    line-height: 1.4 !important;
}

dialog[data-request-cancel-dialog] .field-error {
    margin-top: 6px !important;

    color: #b42318 !important;

    font-size: 12px !important;
    font-weight: 600 !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions {
    grid-column: 1 / -1 !important;

    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;

    gap: 9px !important;

    margin-top: 4px !important;
    padding-top: 16px !important;

    border-top: 1px solid #e4eaf0 !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions .button {
    min-height: 38px !important;
    padding: 8px 14px !important;

    border-radius: 8px !important;
}

@media (max-width: 620px) {
    dialog[data-request-cancel-dialog] {
        width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__surface {
        grid-template-columns: 38px minmax(0, 1fr) !important;

        max-height: calc(100dvh - 24px) !important;

        gap: 12px !important;
        padding: 18px !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__icon {
        width: 38px !important;
        height: 38px !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions {
        flex-direction: column-reverse !important;
        align-items: stretch !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions .button {
        width: 100% !important;
        justify-content: center !important;
    }
}
</style>
{{-- BORROWER_CANCEL_DIALOG_POSITION_FIX_END --}}


@if($isBorrower)
<style>
.request-heading-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.borrower-request-workspace {
    display: grid;
    gap: 14px;
}

/* Borrower monitoring is always visible above the detail tabs. */
.borrower-progress-always > .request-tracker-card {
    margin-top: 0;
}

.borrower-progress-always .request-tracker__header {
    padding-bottom: 12px;
}

.borrower-progress-always .request-tracker__intro {
    display: none;
}

.borrower-progress-always .request-tracker__scroll {
    padding-top: 18px;
    padding-bottom: 8px;
}

.borrower-progress-always .request-tracker__marker {
    width: 40px;
    height: 40px;
    margin-bottom: 8px;
}

.borrower-progress-always .request-tracker__step::after {
    top: 20px;
    left: calc(50% + 20px);
    width: calc(100% - 40px);
}

.borrower-progress-always .request-tracker__copy small {
    display: none;
}

.borrower-progress-always .request-tracker__step.is-current .request-tracker__copy small,
.borrower-progress-always .request-tracker__step.is-warning .request-tracker__copy small,
.borrower-progress-always .request-tracker__step.is-stopped .request-tracker__copy small {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.borrower-progress-always .request-tracker__hint {
    margin-top: 0;
    padding-top: 9px;
}

.borrower-request-tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    padding: 7px;
    border: 1px solid #cbd8e6;
    border-radius: 14px;
    background: #f4f8fc;
}

.borrower-request-tab {
    min-height: 46px;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    color: #30465f;
    font: inherit;
    font-weight: 700;
    cursor: pointer;
    transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease, color .16s ease;
}

.borrower-request-tab:hover {
    border-color: #b9cce0;
    background: #ffffff;
    color: #0b4f92;
}

.borrower-request-tab.is-active {
    border-color: #1769e0;
    background: #ffffff;
    color: #0b5cab;
    box-shadow: 0 2px 8px rgba(16, 61, 103, .08);
}

.borrower-request-panel[hidden] {
    display: none !important;
}

.borrower-overview-grid,
.borrower-documents-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    align-items: stretch;
}

/* Keep paired borrower cards equal in height. The taller card sets the row height,
   while long office/item/document text wraps instead of widening the column. */
.borrower-overview-grid > .card,
.borrower-documents-grid > .card {
    min-width: 0;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.borrower-overview-grid > .card *,
.borrower-documents-grid > .card * {
    min-width: 0;
}

.borrower-fact-grid strong,
.borrower-item-row strong,
.borrower-item-row small,
.borrower-documents-grid strong,
.borrower-documents-grid small {
    overflow-wrap: anywhere;
    word-break: normal;
}

.borrower-documents-grid > .card > .empty-state {
    flex: 1;
    display: grid;
    place-items: center;
    align-content: center;
}

.borrower-fact-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0;
}

.borrower-fact-grid > div {
    display: grid;
    gap: 4px;
    padding: 12px 0;
    border-bottom: 1px solid #e1e8f0;
}

.borrower-fact-grid > div:nth-child(odd) {
    padding-right: 18px;
}

.borrower-fact-grid > div:nth-child(even) {
    padding-left: 18px;
    border-left: 1px solid #e1e8f0;
}

.borrower-fact-grid span,
.borrower-item-row small {
    color: #667a91;
    font-size: 12px;
}

.borrower-fact-grid strong {
    color: #102b4e;
    font-size: 15px;
    font-weight: 700;
}

.borrower-item-list {
    display: grid;
    max-height: 330px;
    overflow-y: auto;
    overflow-x: hidden;
    padding-right: 6px;
    scrollbar-gutter: stable;
    overscroll-behavior: contain;
}

/* The card header remains fixed while only long item lists scroll.
   With a few items there is no scrollbar; it appears only when needed. */
.borrower-item-list::-webkit-scrollbar {
    width: 8px;
}

.borrower-item-list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #c5d2e0;
}

.borrower-item-list::-webkit-scrollbar-track {
    background: transparent;
}

.borrower-section-title-inline {
    display: flex;
    align-items: baseline;
    gap: 10px;
    min-width: 0;
    flex-wrap: wrap;
}

.borrower-section-title-inline h2,
.borrower-section-title-inline .meta {
    margin: 0;
}

.borrower-item-count {
    color: #667a91;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.borrower-item-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    padding: 13px 0;
    border-bottom: 1px solid #e1e8f0;
}

.borrower-item-row:last-child {
    border-bottom: 0;
}

.borrower-item-row > div:first-child,
.borrower-item-quantity {
    display: grid;
    gap: 3px;
}

.borrower-item-quantity {
    min-width: 150px;
    text-align: right;
}

.borrower-document-list article {
    min-height: 64px;
}


@media (max-width: 980px) {
    .borrower-overview-grid,
    .borrower-documents-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 680px) {
    .request-heading-actions {
        justify-content: flex-start;
    }

    .borrower-request-tabs {
        grid-template-columns: 1fr;
    }

    .borrower-fact-grid {
        grid-template-columns: 1fr;
    }

    .borrower-fact-grid > div:nth-child(odd),
    .borrower-fact-grid > div:nth-child(even) {
        padding-left: 0;
        padding-right: 0;
        border-left: 0;
    }

    .borrower-item-row {
        grid-template-columns: 1fr;
    }

    .borrower-item-quantity {
        min-width: 0;
        text-align: left;
    }
}
</style>
@endif

@endsection