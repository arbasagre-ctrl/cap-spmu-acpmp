@extends('layouts.app', ['title' => 'Request '.$borrowingRequest->request_no])
@section('content')
@php
    $v = $borrowingRequest->currentVersion;
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $isSpmu = $workspace === 'SPMU';
    $hasCurrentESignature = auth()->user()->currentSignature()->whereHas('file')->exists();
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
    $actionOfficerStep = $v->approvalSteps->firstWhere('sequence_no', 1);
    $actionOfficerVerified = $actionOfficerStep?->decision === 'VERIFIED';
    $actionOfficerDocumentsVerified = $actionOfficerVerified
        && $currentDocs->isNotEmpty()
        && $currentDocs->every(
            fn ($document) => $document->verification_status === App\Models\RequestSupportingDocument::STATUS_VERIFIED
        );

    // A request remains APPROVED_READY_FOR_RELEASE at the request-record level
    // after approval, while the custody transaction continues through release,
    // return, and completion. Use custody state for the visible operational
    // badge so the request detail never shows a stale "Ready for Release".
    $custody = $borrowingRequest->custody;
    $isOperationalRequestLayout = $isSpmu && (bool) $custody && ! $isUnderSpmuReview;
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
    $requiresNewRevisionVersion = $borrowingRequest->status === App\Enums\RequestStatus::ReturnedForRevision;

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

    $gatePassFinalized = $requestHasOffCampus
        && in_array(
            $custody?->gatePass?->status,
            ['READY_FOR_PRINTING', 'VERIFIED'],
            true
        )
        && (bool) $gatePassDocument;

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

@if($isOperationalRequestLayout)
    @include('requests.partials.operational-styles')
    <div class="request-operational-page">
    @include('requests.partials.operational-heading')
@else
<section class="page-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'My request' : 'Borrowing request' }}</p>
        <h1>{{ $borrowingRequest->request_no }}</h1>
        <p>{{ $v->purpose_event }}</p>
        @if($isBorrower)
            <p class="meta">Review the request, documents, and current progress.</p>
        @else
            {{-- Identity line: who filed it and when, so the reviewer does not
                 have to scroll to Request Details to place the record. --}}
            <p class="meta request-heading-meta">
                <span>Borrower: <strong>{{ $borrowingRequest->borrower->full_name }}</strong></span>
                <span>Submitted: <strong>{{ optional($v->submitted_at ?: $borrowingRequest->created_at)->format('d M Y, g:i A') }}</strong></span>
            </p>
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
@endif

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

            @if($requiresNewRevisionVersion)
                <h2>Create the corrected request version</h2>
                <p>Open the returned request, apply the required corrections, and save a new version before E-signing and resubmitting. The prior signed version remains unchanged.</p>
            @elseif(!$submissionReady)
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
                <a
                    class="button secondary ui-pressable"
                    href="{{ route('requests.edit', $borrowingRequest) }}"
                >
                    {{ $requiresNewRevisionVersion ? 'Create Corrected Version' : ($submissionReady ? 'Review / Edit Draft' : 'Edit Draft & Upload Documents') }}
                </a>

                @if($submissionReady && !$requiresNewRevisionVersion)
                    <form method="post" action="{{ route('requests.submit', $borrowingRequest) }}" class="form-grid">
                        @csrf
                        @if($hasCurrentESignature)
                            <label class="checkbox">
                                <input type="checkbox" name="confirm_e_signature" value="1" required>
                                I confirm this submission and authorize use of my registered E-signature for this request version.
                            </label>
                            <button class="button primary ui-pressable" type="submit">
                                E-sign &amp; Submit to SPMU
                            </button>
                        @else
                            <div class="callout warning">
                                <strong>E-signature required</strong>
                                <p><a href="{{ route('profile.show') }}">Register your E-signature in Account Settings</a> before submitting.</p>
                            </div>
                        @endif
                    </form>
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
<x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" :compact="$isOperationalRequestLayout" />
@endunless

@if($isBorrower)
<style>
.request-tracker-card {
    margin-top: 14px;
}

.request-tracker-card .request-tracker {
    overflow: visible;
    padding: 18px 20px;
}

.request-tracker-card .request-tracker__header {
    gap: 12px;
    padding-bottom: 13px;
}

.request-tracker-card .request-tracker__header h2 {
    margin: 1px 0 2px;
}

.request-tracker-card .request-tracker__scroll {
    width: 100%;
    margin: 0;
    padding: 18px 0 12px;
    overflow: visible;
    overscroll-behavior-inline: auto;
    scrollbar-width: auto;
}

.request-tracker-card .request-tracker__rail {
    width: 100%;
    min-width: 0;
    grid-template-columns: repeat(8, minmax(0, 1fr));
}

.request-tracker-card .request-tracker__step {
    padding-inline: 6px;
}

.request-tracker-card .request-tracker__copy small {
    display: block;
    overflow: visible;
    max-width: 150px;
    -webkit-line-clamp: unset;
}

.request-tracker-card .request-tracker__step.is-complete::after {
    background: linear-gradient(90deg, #31B700 0%, #159000 100%);
}

.request-tracker-card .request-tracker__step.is-complete .request-tracker__marker {
    color: #fff;
    background: linear-gradient(180deg, #39E600 0%, #31B700 55%, #159000 100%);
    border-color: #159000;
    box-shadow: none;
}

.request-tracker-card .request-tracker__step.is-complete .request-tracker__marker .ui-icon {
    color: #fff;
}

.request-tracker-card .request-tracker__hint {
    margin-top: 10px;
    padding: 11px 0 0;
    background: transparent;
    border: 0;
    border-top: 1px solid var(--row-border);
    border-radius: 0;
    box-shadow: none;
}

@media (max-width: 1024px) {
    .request-tracker-card .request-tracker__rail {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        row-gap: 26px;
    }

    .request-tracker-card .request-tracker__step:nth-child(4n)::after {
        display: none;
    }
}

@media (max-width: 620px) {
    .request-tracker-card .request-tracker__header {
        gap: 6px;
    }

    .request-tracker-card .request-tracker__scroll {
        padding-top: 14px;
    }

    .request-tracker-card .request-tracker__rail {
        grid-template-columns: 1fr;
        row-gap: 0;
    }

    .request-tracker-card .request-tracker__step {
        display: grid;
        grid-template-columns: 40px minmax(0, 1fr);
        gap: 11px;
        padding: 0 0 18px;
        text-align: left;
    }

    .request-tracker-card .request-tracker__marker {
        width: 38px;
        height: 38px;
        margin: 0;
    }

    .request-tracker-card .request-tracker__copy {
        justify-items: start;
        gap: 2px;
        padding-top: 1px;
    }

    .request-tracker-card .request-tracker__copy small {
        max-width: none;
    }

    .request-tracker-card .request-tracker__step::after,
    .request-tracker-card .request-tracker__step:nth-child(4n)::after {
        display: block;
        top: 38px;
        left: 18px;
        width: 2px;
        height: calc(100% - 38px);
    }

    .request-tracker-card .request-tracker__step:last-child::after {
        display: none;
    }
}
</style>
@endif

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
            @if($canVerify || $canDecide)
                <div class="card-header">
                    <div>
                        <p class="eyebrow">{{ $canVerify ? 'SPMU Action Officer' : 'SPMU Head / Admin' }}</p>
                        <h2>{{ $canVerify ? 'Verify request and documents' : 'Review and decide' }}</h2>
                    </div>
                    <span class="status-badge status-info">{{ $canVerify ? 'For verification' : 'For approval' }}</span>
                </div>

                <p class="meta spmu-review-summary">
                    @if($canVerify)
                        Review the request and required documents before forwarding it for final approval.
                    @elseif($v->off_campus)
                        Review the Action Officer verification and make the final decision.
                    @else
                        Review the request and make the final decision.
                    @endif
                </p>

                @if($canDecide && $v->off_campus && $actionOfficerVerified)
                    <section class="spmu-ao-verification-summary" aria-label="Action Officer verification record">
                        <div class="spmu-ao-verification-summary__header">
                            <div>
                                <p class="eyebrow">Action Officer verification</p>
                                <strong>Verification record</strong>
                            </div>
                            <span class="status-badge status-success">Verified</span>
                        </div>

                        <dl class="spmu-ao-verification-summary__details">
                            <div>
                                <dt>Verified by</dt>
                                <dd>{{ $actionOfficerStep?->approver?->full_name ?? 'SPMU Action Officer' }}</dd>
                            </div>
                            <div>
                                <dt>Verified on</dt>
                                <dd>{{ $actionOfficerStep?->decided_at?->format('d M Y, g:i A') ?? 'Not recorded' }}</dd>
                            </div>
                            <div>
                                <dt>E-signature</dt>
                                <dd>{{ $actionOfficerStep?->signature_snapshot_id ? 'Applied' : 'Not recorded' }}</dd>
                            </div>
                            <div>
                                <dt>Documents</dt>
                                <dd>{{ $actionOfficerDocumentsVerified ? 'Verified' : 'Review required' }}</dd>
                            </div>
                        </dl>

                        @if(filled($actionOfficerStep?->remarks))
                            <div class="spmu-ao-verification-summary__remarks">
                                <span>Remarks</span>
                                <p>{{ $actionOfficerStep->remarks }}</p>
                            </div>
                        @endif
                    </section>
                @endif

                @if($v->represents_student_activity)
                    <div class="spmu-supporting-document">
                        <div>
                            <strong>Permission to Conduct Letter</strong>
                            <small>Required for this student activity.</small>
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
                    action="{{ $canVerify ? route('verifications.verify', $borrowingRequest) : route('approvals.decide', $borrowingRequest) }}"
                    class="spmu-verification-form top-gap"
                    data-verification-form
                    data-required-supporting-present="{{ ($requestLetterDoc && (!$v->represents_student_activity || $permissionToConductDoc)) ? '1' : '0' }}"
                    data-has-current-e-signature="{{ $hasCurrentESignature ? '1' : '0' }}"
                >
                    @csrf

                    <input type="hidden" name="decision" value="" data-verification-decision>
                    <input type="hidden" name="remarks" value="{{ old('remarks') }}" data-verification-remarks>
                    <input type="hidden" name="confirm_e_signature" value="" data-verification-e-signature>

                    <div class="spmu-checklist">
                        @if($canVerify)
                            <label class="spmu-check-row">
                                <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                                <span>
                                    <strong>Request details are consistent with the submitted letter</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the submitted request matches the signed request letter.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="documents_complete" value="1" data-verification-check @checked(old('documents_complete'))>
                                <span>
                                    <strong>Required supporting documents are complete</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Verify the required attachments, including PTC when applicable.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                                <span>
                                    <strong>Off-campus requirements are verified</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that Gate Pass requirements are complete and valid.</small>
                                </span>
                            </label>
                        @elseif($v->off_campus)
                            <label class="spmu-check-row">
                                <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                                <span>
                                    <strong>Action Officer verification has been reviewed</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Review the completed verification before making the final decision.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="documents_complete" value="1" data-verification-check @checked(old('documents_complete'))>
                                <span>
                                    <strong>Request is appropriate for approval</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the purpose, schedule, and intended use may be authorized.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                                <span>
                                    <strong>Inventory availability is verified</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the requested quantities may be allocated without conflict.</small>
                                </span>
                            </label>
                        @else
                            <label class="spmu-check-row">
                                <input type="checkbox" name="details_complete" value="1" data-verification-check @checked(old('details_complete'))>
                                <span>
                                    <strong>Request details and required documents are complete</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Review the submitted request and required supporting documents.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="documents_complete" value="1" data-verification-check @checked(old('documents_complete'))>
                                <span>
                                    <strong>Request is appropriate for approval</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the purpose, schedule, and intended use may be authorized.</small>
                                </span>
                            </label>
                            <label class="spmu-check-row">
                                <input type="checkbox" name="availability_verified" value="1" data-verification-check @checked(old('availability_verified'))>
                                <span>
                                    <strong>Inventory availability is verified</strong>
                                    <small class="meta" style="display:block; margin-top:4px;">Confirm that the requested quantities may be allocated without conflict.</small>
                                </span>
                            </label>
                        @endif
                    </div>

                    @unless($hasCurrentESignature)
                        <p class="field-error top-gap">
                            <a href="{{ route('profile.show') }}">Register your E-signature in Account Settings</a> to enable {{ $canVerify ? 'verification' : 'approval' }}.
                        </p>
                    @endunless

                    <p class="field-error top-gap" data-verification-inline-error hidden></p>

                    <div class="spmu-review-footer">
                        <div class="spmu-decision-actions">
                            <button
                                class="button primary ui-pressable"
                                type="button"
                                data-decision-trigger="{{ $canVerify ? 'VERIFIED' : 'APPROVED' }}"
                                data-approve-button
                                disabled
                            >
                                {{ $canVerify ? 'E-sign & Mark VERIFIED' : 'E-sign & Approve' }}
                            </button>

                            <button
                                class="button secondary ui-pressable"
                                type="button"
                                data-decision-trigger="RETURNED_FOR_REVISION"
                            >
                                Return for Revision
                            </button>

                            @if($canDecide)
                                <button
                                    class="button danger ui-pressable"
                                    type="button"
                                    data-decision-trigger="REJECTED"
                                >
                                    Reject
                                </button>
                            @endif
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
                        <h2>{{ $actionOfficerVerified ? 'Waiting for Head decision' : 'Waiting for Action Officer verification' }}</h2>
                    </div>
                </div>

                <div class="empty-state">
                    @if($actionOfficerVerified)
                        <strong>This VERIFIED request is awaiting the SPMU Head decision.</strong>
                        <span>Verification is not approval; operational preparation starts only after the Head's final approval.</span>
                    @else
                        <strong>This request is awaiting Action Officer verification.</strong>
                        <span>The SPMU Head decision workflow becomes available only after the Action Officer marks the request VERIFIED.</span>
                    @endif
                </div>
            @endif
            </article>
        </div>

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
                    <span>Scheduled Use</span>
                    <strong>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</strong>
                </div>

                <div class="spmu-left-borrowing-cell">
                    <span>Expected Return</span>
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
</section>
@endif

@if($isBorrower)
@include('requests.partials.borrower-detail-styles')
<div class="borrower-progress-always" aria-label="Current request progress">
    <x-request-progress-tracker :request="$borrowingRequest" :show-current-status="false" />
</div>

<div class="content-area borrower-request-detail">
    <div class="borrower-detail-grid">
        <article class="borrower-detail-card">
            <h2 class="borrower-card-title">
                <span class="borrower-card-icon" aria-hidden="true"><x-icon name="requests" size="20" /></span>
                Borrowing Information
            </h2>

            <div class="borrower-fact-grid">
                <div>
                    <span>Office / Department</span>
                    <strong>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '&mdash;' }}</strong>
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
                <div>
                    <span>Premises</span>
                    <strong>{{ $v->off_campus ? 'Off-campus · Gate Pass required' : 'On-campus' }}</strong>
                </div>
            </div>
        </article>

        <article class="borrower-detail-card">
            @php
                $awaitingItemDecision = $v->items->every(
                    fn ($item) => $item->approved_quantity === null
                );
            @endphp

            <h2 class="borrower-card-title">
                <span class="borrower-card-icon" aria-hidden="true"><x-icon name="box" size="20" /></span>
                Requested Items
                <span class="borrower-card-note">{{ $awaitingItemDecision ? 'Awaiting SPMU review' : 'Approved quantities' }}</span>
            </h2>

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
                        </div>

                        <div class="borrower-item-quantity">
                            <strong>{{ $approvedQty ?? $requestedQty }} {{ $item->unit_snapshot }}</strong>

                            @if($quantityChanged)
                                <small>Requested {{ $requestedQty }} {{ $item->unit_snapshot }}</small>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </article>
    </div>

    <article class="borrower-detail-card">
        <h2 class="borrower-card-title">
            <span class="borrower-card-icon" aria-hidden="true"><x-icon name="requests" size="20" /></span>
            Submitted Documents
        </h2>

        @if($currentDocs->isEmpty())
            <p class="borrower-documents-empty">No supporting documents have been uploaded yet.</p>
        @else
            <div class="borrower-documents-scroll">
                <table class="borrower-documents-table">
                    <thead>
                        <tr>
                            <th scope="col">Document</th>
                            <th scope="col">Description</th>
                            <th scope="col">Status</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach($currentDocs as $doc)
                            @php
                                $isRequestLetter = $doc->document_type
                                    === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER;

                                [$docStatusLabel, $docStatusTone] = match ($doc->verification_status) {
                                    App\Models\RequestSupportingDocument::STATUS_VERIFIED => ['Verified', 'success'],
                                    App\Models\RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION => ['Needs Replacement', 'warning'],
                                    App\Models\RequestSupportingDocument::STATUS_REJECTED => ['Rejected', 'danger'],
                                    default => ['Submitted', 'success'],
                                };
                            @endphp

                            <tr>
                                <td>
                                    <span class="borrower-document-name">
                                        <x-icon name="requests" size="18" />
                                        {{ $isRequestLetter ? 'Borrowing Request Letter' : 'Permission to Conduct Letter' }}
                                    </span>
                                </td>

                                <td>
                                    {{ $isRequestLetter
                                        ? 'Official request letter for borrowing items.'
                                        : 'Approval to conduct the activity.' }}
                                </td>

                                <td>
                                    <span class="status-badge status-{{ $docStatusTone }}">{{ $docStatusLabel }}</span>
                                </td>

                                <td>
                                    <a
                                        class="borrower-document-view"
                                        href="{{ route('files.show', $doc->file, false) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>

    @if($borrowingRequest->custody && $borrowingRequest->final_approved_at)
        <article class="borrower-detail-card">
            <h2 class="borrower-card-title">
                <span class="borrower-card-icon" aria-hidden="true"><x-icon name="printer" size="20" /></span>
                Forms for Physical Processing
                <span class="borrower-card-note">View and download the approved forms before pickup.</span>
            </h2>

            <div class="callout info">
                <strong>Bring the generated documents to SPMU.</strong>
                <p>Proceed to SPMU on the scheduled pickup date with the Borrower Slip{{ $requestHasOffCampus ? ' and the generated Gate Pass for this off-campus request' : '' }}.</p>
            </div>

            <div class="borrower-item-list">
                <div class="borrower-item-row">
                    <div>
                        <strong>Borrower Slip</strong>
                        <small>Required for physical handover.</small>
                    </div>
                    <div class="borrower-item-quantity">
                        @if($borrowerSlipDocument)
                            <span class="inline-actions">
                                <a class="button secondary small ui-pressable" href="{{ route('documents.view', $borrowerSlipDocument) }}" target="_blank" rel="noopener">View</a>
                                <a class="button primary small ui-pressable" href="{{ route('documents.download', $borrowerSlipDocument) }}">Download</a>
                            </span>
                        @else
                            <span class="status-badge status-neutral">Preparing</span>
                        @endif
                    </div>
                </div>

                <div class="borrower-item-row">
                    <div>
                        <strong>Laundry Form</strong>
                        <small>{{ $requestHasLaundry ? 'Applicable to this borrowing.' : 'Not applicable.' }}</small>
                    </div>
                    <div class="borrower-item-quantity">
                        @if(!$requestHasLaundry)
                            <span class="status-badge status-neutral">Not applicable</span>
                        @elseif($laundryFormDocument)
                            <span class="inline-actions">
                                <a class="button secondary small ui-pressable" href="{{ route('documents.view', $laundryFormDocument) }}" target="_blank" rel="noopener">View</a>
                                <a class="button primary small ui-pressable" href="{{ route('documents.download', $laundryFormDocument) }}">Download</a>
                            </span>
                        @else
                            <span class="status-badge status-neutral">Preparing</span>
                        @endif
                    </div>
                </div>

                <div class="borrower-item-row">
                    <div>
                        <strong>Gate Pass</strong>
                        <small>
                            @if(!$requestHasOffCampus)
                                Not applicable.
                            @elseif($gatePassFinalized)
                                Generated automatically after SPMU Head approval.
                            @else
                                Required, but the approved Gate Pass is not available. Contact SPMU; do not proceed with release.
                            @endif
                        </small>
                    </div>
                    <div class="borrower-item-quantity">
                        @if(!$requestHasOffCampus)
                            <span class="status-badge status-neutral">Not applicable</span>
                        @elseif($gatePassFinalized)
                            <span class="inline-actions">
                                <a class="button secondary small ui-pressable" href="{{ route('documents.view', $gatePassDocument) }}" target="_blank" rel="noopener">View</a>
                                <a class="button primary small ui-pressable" href="{{ route('documents.download', $gatePassDocument) }}">Download</a>
                            </span>
                        @else
                            <span class="status-badge status-danger">Missing approved document</span>
                        @endif
                    </div>
                </div>
            </div>
        </article>
    @endif
</div>
@elseif($isOperationalRequestLayout)
    @include('requests.partials.operational-details')
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



@if($borrowingRequest->custody && !$isBorrower && !$isOperationalRequestLayout)
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
<div class="content-area borrower-request-detail">
    <article class="request-cancel-card" data-request-cancel-workspace>
        <div class="borrower-cancel-copy">
            <h2 class="borrower-cancel-title">
                <x-icon name="warning" size="20" />
                Request Actions
            </h2>

            <p>
                You may cancel this request until items are physically released.
                @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                    Any approved but unreleased allocation returns to Available inventory, and any active pickup window, Borrower Slip, and Gate Pass prepared for this request will no longer be valid.
                @endif
            </p>
        </div>

        <button
            class="button ui-pressable borrower-cancel-button"
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
                        This request will be cancelled immediately. Cancellation is allowed only before physical release.
                        @if($borrowingRequest->status === App\Enums\RequestStatus::ApprovedReadyForRelease)
                            The approved allocation returns to Available inventory and any pending pickup documents will be invalidated.
                        @endif
                    </p>

                    <div class="spmu-dialog-remarks">
                        <label for="borrower-cancellation-reason">Cancellation reason *</label>
                        <textarea
                            id="borrower-cancellation-reason"
                            rows="4"
                            maxlength="500"
                            data-request-cancel-reason-field
                            placeholder="Briefly explain why this request is being cancelled..."
                        ></textarea>
                        <div class="spmu-dialog-remarks__footer">
                            <small>This reason will be recorded in the request history.</small>
                            <small class="spmu-dialog-remarks__counter" data-request-cancel-counter>0 / 500</small>
                        </div>
                        <p class="field-error" data-request-cancel-error hidden></p>
                    </div>
                </div>

                <div class="spmu-confirm-dialog__actions">
                    <button class="button secondary ui-pressable" type="button" data-request-cancel-back>Go Back</button>
                    <button class="button danger ui-pressable" type="button" data-request-cancel-confirm>Cancel Request</button>
                </div>
            </div>
        </dialog>
    </article>
</div>
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

        const counter = workspace.querySelector(
            '[data-request-cancel-counter]'
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

        const limit = Number(reasonField.getAttribute('maxlength')) || 500;

        const updateCounter = () => {
            if (!counter) {
                return;
            }

            const used = reasonField.value.length;

            counter.textContent = used + ' / ' + limit;
            counter.dataset.limitNear = used >= limit ? '1' : '0';
        };

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
            updateCounter();

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
            () => {
                clearError();
                updateCounter();
            }
        );

        updateCounter();

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
    @include('requests.partials.audit-history')
</section>
@endunless

@if($isUnderSpmuReview)
@include('requests.partials.spmu-review-styles')

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
    const eSignatureInput = form.querySelector('[data-verification-e-signature]');
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
    const hasCurrentESignature = form.dataset.hasCurrentESignature === '1';
    let pendingDecision = '';

    const decisionCopy = {
        VERIFIED: {
            title: 'Mark this request VERIFIED?',
            message: 'Your registered E-signature will certify Action Officer verification and route the request to the SPMU Head. This does not approve the request, reserve inventory, or generate release documents.',
            confirm: 'Yes, Mark VERIFIED',
            tone: 'primary',
        },
        APPROVED: {
            title: 'E-sign and approve this verified request?',
            message: 'Your registered E-signature will be bound to the separate Head approval step. The approved quantities will be reserved and the applicable release documents will be generated, but the property is not yet physically issued.',
            confirm: 'Yes, E-sign & Approve',
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

    const isPositiveDecision = (decision) =>
        decision === 'VERIFIED' || decision === 'APPROVED';

    const checklistComplete = () =>
        requiredSupportingPresent &&
        hasCurrentESignature &&
        checks.length === 3 &&
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
        const needsRemarks = !isPositiveDecision(decision);
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
            if (!isPositiveDecision(decision)) {
                window.setTimeout(() => remarksField.focus(), 0);
            }
            return;
        }

        if (isPositiveDecision(decision)) {
            if (window.confirm(`${copy.title}\n\n${copy.message}`)) {
                if (eSignatureInput) eSignatureInput.value = '1';
                form.submit();
            }
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

            if (isPositiveDecision(decision) && !checklistComplete()) {
                showInlineError(
                    !requiredSupportingPresent
                        ? 'A required supporting document is missing. This action is unavailable until the required document is attached.'
                        : !hasCurrentESignature
                            ? 'Register your E-signature in Account Settings before continuing.'
                            : 'Complete all checks before continuing.'
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

        if (!isPositiveDecision(pendingDecision)) {
            if (eSignatureInput) eSignatureInput.value = '';
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
            if (eSignatureInput) eSignatureInput.value = '1';
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



</script>
@endif





















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

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__footer {
    display: flex !important;
    align-items: baseline !important;
    justify-content: space-between !important;

    gap: 12px !important;

    margin-top: 6px !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks small {
    display: block !important;

    color: #708196 !important;

    font-size: 11.5px !important;
    line-height: 1.4 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__counter {
    flex-shrink: 0 !important;

    color: #8394a6 !important;

    font-variant-numeric: tabular-nums !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__counter[data-limit-near="1"] {
    color: #b42318 !important;
    font-weight: 700 !important;
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

.borrower-document-list article {
    min-height: 64px;
}


@media (max-width: 680px) {
    .request-heading-actions {
        justify-content: flex-start;
    }

}
</style>
@endif

@if($isOperationalRequestLayout)
    </div>
@endif
@endsection
