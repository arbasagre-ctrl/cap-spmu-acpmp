@extends('layouts.app', ['title' => $custody->custody_no])

@section('content')
@php
    $workspace = strtoupper((string) session('active_workspace'));
    $user = auth()->user();
    $isBorrower = $workspace === 'BORROWER' && auth()->id() === $custody->borrower_user_id;
    $isSpmu = $workspace === 'SPMU';
    $isSpmuOfficer = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuOfficer;
    $isSpmuHead = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuHead;
    $spmuMode = $spmuMode ?? null;
    $showReleaseWorkflow = ! ($isSpmuOfficer && $spmuMode === 'return');
    $showReturnWorkflow = ! ($isSpmuOfficer && $spmuMode === 'release');

    $version = $custody->request?->currentVersion;
    $scheduleDateValue = $version?->getAttribute('schedule_date') ?: $version?->getAttribute('needed_from');
    $originalReturnDateValue = $custody->original_due_at
        ?: $version?->getAttribute('return_date')
        ?: $version?->getAttribute('return_due_at')
        ?: $custody->due_at;
    $returnDateValue = $custody->due_at ?: $originalReturnDateValue;

    $scheduleDate = $scheduleDateValue ? \Illuminate\Support\Carbon::parse($scheduleDateValue) : null;
    $originalReturnDate = $originalReturnDateValue ? \Illuminate\Support\Carbon::parse($originalReturnDateValue) : null;
    $returnDate = $returnDateValue ? \Illuminate\Support\Carbon::parse($returnDateValue) : null;
    $returnDateAdjusted = $originalReturnDate && $returnDate
        && ! $originalReturnDate->isSameDay($returnDate);

    $outstandingTotal = $custody->lines->sum(
        fn ($line) => max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )
    );

    $activeEarlyReturn = $custody->earlyReturnRequests
        ->where('status', 'REQUESTED')
        ->sortByDesc(fn ($notice) => $notice->requested_at?->timestamp ?? 0)
        ->first();

    $earlyReturnEligibleLines = $custody->lines->filter(
        fn ($line) => floor(max(
            0,
            (float) $line->actual_released_quantity - (float) $line->returned_quantity
        )) >= 1
    );

    $canRequestEarlyReturn = $isBorrower
        && ! $activeEarlyReturn
        && (bool) $custody->released_at
        && $custody->status === 'ACTIVE'
        && ! $custody->closed_at
        && $earlyReturnEligibleLines->isNotEmpty()
        && (bool) $custody->due_at
        && now()->lt($custody->due_at);

    $preparationComplete = (bool) $custody->prepared_at;
    $hasPickupSchedule = (bool) $custody->scheduled_release_at
        && (bool) $custody->pickup_expires_at
        && ! $custody->pickup_expired_at;

    $hasOffCampusItem = $custody->lines->contains(
        fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
    );

    $hasLaundryItem = $custody->lines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $laundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;

    $operationalLabel = match (true) {
        $custody->status === 'CLOSED' => 'Completed',
        $custody->status === 'OBLIGATION_OPEN' => 'Return Reconciliation / Obligation Open',
        $custody->status === 'RETURN_PROCESSING' => 'Return Processing',
        $custody->status === 'OVERDUE' => 'Overdue',
        (bool) $custody->released_at => 'Items Released / On Custody',
        $preparationComplete && $hasPickupSchedule => 'Ready for Release',
        ! $hasPickupSchedule && $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
        $hasPickupSchedule && ! $preparationComplete => 'For Item Preparation',
        $custody->status === 'PREPARING_RELEASE' => 'Preparing for Release',
        default => null,
    };

    [$borrowerStateTitle, $borrowerStateCopy] = match (true) {
        $custody->status === 'CLOSED' => [
            'Borrowing completed',
            'All issued items have been returned and reconciled. No further borrower action is required for this borrowing.',
        ],
        $custody->status === 'OBLIGATION_OPEN' => [
            'Return completed with an open obligation',
            'The physical return has been recorded, but an accountability or billing obligation still needs resolution. Check My Obligations for the required action.',
        ],
        $custody->status === 'RETURN_PROCESSING' => [
            'Return processing is in progress',
            'SPMU is reconciling returned items. Review the quantities not yet returned below; no split item-quantity returns are recorded.',
        ],
        $custody->status === 'OVERDUE' => [
            'This borrowing is overdue',
            'Review the expected return date and overdue items below, then coordinate the return with SPMU.',
        ],
        (bool) $custody->released_at => [
            'Items are currently on your custody',
            'Use this record for your issued items, return due date, and quantities currently on custody.',
        ],
        $preparationComplete && $hasPickupSchedule => [
            'Ready for scheduled release',
            'Your pickup is scheduled and SPMU has confirmed item preparation. Go to SPMU during the pickup window for the physical handover and Borrower Slip.',
        ],
        $hasPickupSchedule => [
            'Pickup scheduled — preparation in progress',
            'SPMU has set your pickup window and is preparing the approved items. No quantity input is required from you.',
        ],
        default => [
            'Waiting for pickup scheduling',
            'SPMU will schedule the pickup after approval. Request approval and signed-document history remain under My Requests.',
        ],
    };
@endphp

<section class="page-heading">
    <div>
        <p class="eyebrow">
            {{ $isBorrower
                ? 'My borrowing'
                : ($isSpmuOfficer && $spmuMode === 'release'
                    ? 'Release transaction'
                    : ($isSpmuOfficer && $spmuMode === 'return' ? 'Return transaction' : 'Pickup / custody transaction')) }}
        </p>
        <h1>{{ $custody->custody_no }}</h1>
        <p>
            Request {{ $custody->request?->request_no }}
            @if(!$isBorrower && $custody->borrower)
                · {{ $custody->borrower->full_name }}
            @endif
        </p>
    </div>
    <x-status-badge :status="$custody->status" :label="$operationalLabel" />
</section>

@if($activeEarlyReturn)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Return coordination</p>
                    <h2>Early Return Scheduled</h2>
                </div>
                <span class="status-badge status-info">Active Notice</span>
            </div>

            <dl class="detail-list compact-detail-list">
                <dt>Handover Schedule</dt>
                <dd>{{ optional($activeEarlyReturn->proposed_return_at)->format('d F Y, g:i A') ?: '—' }}</dd>

                <dt>Coordination Note</dt>
                <dd>{{ $activeEarlyReturn->reason ?: 'No coordination note provided.' }}</dd>
            </dl>

            <div class="table-wrap top-gap">
                <table>
                    <thead>
                        <tr>
                            <th>Selected Item</th>
                            <th>Planned Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activeEarlyReturn->lines as $noticeLine)
                            <tr>
                                <td>
                                    <strong>{{ $noticeLine->custodyLine?->requestItem?->description_snapshot ?: 'Custody item' }}</strong>
                                    <small>{{ $noticeLine->custodyLine?->requestItem?->unit_snapshot ?: '—' }}</small>
                                </td>
                                <td>{{ $noticeLine->proposed_quantity + 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="meta top-gap">
                This is a notice for coordination only. Inventory, on-custody quantities, and return status change only after SPMU physically receives and inspects the items through Return &amp; Inspection.
            </p>
        </article>
    </section>
@endif

@if($isBorrower)
    @php
        $borrowerItemCount = $custody->lines->count();
        $borrowerCustodyLabel = $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody';

        $borrowerLaundryComplete = $hasLaundryItem
            && $laundryJob
            && $laundryJob->status === 'LAUNDRY_COMPLETED';

        $borrowerLaundryLabel = match (true) {
            ! $hasLaundryItem => null,
            $borrowerLaundryComplete => 'Completed',
            $laundryJob !== null => 'In Progress',
            default => 'Applicable',
        };

        $borrowerGatePassComplete = $hasOffCampusItem
            && $custody->gatePass
            && $custody->gatePass->status === 'VERIFIED';

        $borrowerGatePassLabel = match (true) {
            ! $hasOffCampusItem => null,
            $borrowerGatePassComplete => 'Completed',
            $custody->gatePass !== null => 'Pending',
            default => 'Applicable',
        };
    @endphp

    <style>
        .borrower-custody-stack {
            display: grid;
            gap: 14px;
        }

        .borrower-custody-status {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 14px 18px;
            border: 1px solid var(--border, #d7e0ea);
            border-left: 4px solid #1877d2;
            border-radius: 12px;
            background: var(--surface, #fff);
        }

        .borrower-custody-status-copy {
            min-width: 0;
        }

        .borrower-custody-status-copy .eyebrow {
            margin-bottom: 3px;
        }

        .borrower-custody-status-copy h2 {
            margin: 0;
            font-size: 18px;
        }

        .borrower-custody-status-copy p:last-child {
            margin: 4px 0 0;
            color: var(--text-muted, #64748b);
        }

        .borrower-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border-top: 1px solid var(--border, #d7e0ea);
        }

        .borrower-summary-fact {
            min-width: 0;
            padding: 13px 16px;
            border-right: 1px solid var(--border, #d7e0ea);
            border-bottom: 1px solid var(--border, #d7e0ea);
        }

        .borrower-summary-fact:nth-child(4n) {
            border-right: 0;
        }

        .borrower-summary-fact small {
            display: block;
            margin-bottom: 4px;
            color: var(--text-muted, #64748b);
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .borrower-summary-fact strong {
            display: block;
            overflow-wrap: anywhere;
            color: var(--text, #102a43);
        }

        .borrower-summary-fact span {
            display: block;
            margin-top: 3px;
            color: var(--text-muted, #64748b);
            font-size: 11px;
            line-height: 1.35;
        }

        .borrower-items-header-meta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted, #64748b);
            font-size: 12px;
            font-weight: 800;
        }

        .borrower-items-scroll {
            max-height: 330px;
            overflow: auto;
            overscroll-behavior: contain;
        }

        .borrower-items-scroll table {
            margin: 0;
        }

        .borrower-items-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--surface-subtle, #eef3f8);
        }

        .borrower-processing-list {
            display: grid;
        }

        .borrower-processing-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 14px;
            align-items: center;
            padding: 14px 0;
            border-top: 1px solid var(--border, #d7e0ea);
        }

        .borrower-processing-row:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .borrower-processing-row:last-child {
            padding-bottom: 0;
        }

        .borrower-processing-copy {
            min-width: 0;
        }

        .borrower-processing-copy strong,
        .borrower-processing-copy small {
            display: block;
        }

        .borrower-processing-copy small {
            margin-top: 3px;
            color: var(--text-muted, #64748b);
            line-height: 1.4;
        }

        @media (max-width: 1050px) {
            .borrower-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .borrower-summary-fact:nth-child(4n) {
                border-right: 1px solid var(--border, #d7e0ea);
            }

            .borrower-summary-fact:nth-child(2n) {
                border-right: 0;
            }
        }

        @media (max-width: 700px) {
            .borrower-custody-status {
                align-items: flex-start;
                flex-direction: column;
            }

            .borrower-custody-status .button {
                width: 100%;
                justify-content: center;
            }

            .borrower-summary-grid {
                grid-template-columns: 1fr;
            }

            .borrower-summary-fact,
            .borrower-summary-fact:nth-child(2n),
            .borrower-summary-fact:nth-child(4n) {
                border-right: 0;
            }

            .borrower-processing-row {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .borrower-processing-row .button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <div class="borrower-custody-stack">
        <section class="content-area">
            <div class="borrower-custody-status">
                <div class="borrower-custody-status-copy">
                    <p class="eyebrow">Current borrowing status</p>
                    <h2>{{ $borrowerStateTitle }}</h2>
                    <p>{{ $borrowerStateCopy }}</p>
                </div>
                <a class="button secondary ui-pressable" href="{{ route('requests.show', $custody->request) }}">
                    View Request
                </a>
            </div>
        </section>

        <section class="content-area">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Borrowing summary</p>
                        <h2>{{ $version?->purpose_event ?: 'Borrowing details' }}</h2>
                    </div>
                </div>

                <div class="borrower-summary-grid">
                    <div class="borrower-summary-fact">
                        <small>Scheduled Use</small>
                        <strong>{{ $scheduleDate?->format('d M Y') ?: 'Not available' }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>{{ $returnDateAdjusted ? 'Effective Return' : 'Expected Return' }}</small>
                        <strong>{{ ($returnDateAdjusted ? $returnDate : $originalReturnDate)?->format('d M Y') ?: 'Not available' }}</strong>
                        @if($returnDateAdjusted)
                            <span>Original: {{ $originalReturnDate?->format('d M Y') }}</span>
                        @endif
                    </div>

                    <div class="borrower-summary-fact">
                        <small>Pickup</small>
                        <strong>{{ optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled' }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>Released</small>
                        <strong>{{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'Not issued yet' }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>{{ $borrowerCustodyLabel }}</small>
                        <strong>{{ $outstandingTotal + 0 }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>Use Location</small>
                        <strong>{{ $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only' }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>Completed</small>
                        <strong>{{ optional($custody->closed_at)->format('d M Y, g:i A') ?: 'Not completed yet' }}</strong>
                    </div>

                    <div class="borrower-summary-fact">
                        <small>Items</small>
                        <strong>{{ $borrowerItemCount }} {{ $borrowerItemCount === 1 ? 'item' : 'items' }}</strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="content-area">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Your items</p>
                        <h2>{{ $custody->released_at ? 'Issued and returned quantities' : 'Approved items for pickup' }}</h2>
                    </div>
                    <span class="borrower-items-header-meta">
                        {{ $borrowerItemCount }} {{ $borrowerItemCount === 1 ? 'item' : 'items' }}
                    </span>
                </div>

                <div class="table-wrap borrower-items-scroll">
                    <table>
                        <thead>
                            @if($custody->released_at)
                                <tr>
                                    <th>Item</th>
                                    <th>Issued</th>
                                    <th>Returned</th>
                                    <th>{{ $borrowerCustodyLabel }}</th>
                                </tr>
                            @else
                                <tr>
                                    <th>Item</th>
                                    <th>Approved Quantity</th>
                                    <th>Release Status</th>
                                </tr>
                            @endif
                        </thead>
                        <tbody>
                            @foreach($custody->lines as $line)
                                @php
                                    $lineOnCustody = max(
                                        0,
                                        (float) $line->actual_released_quantity - (float) $line->returned_quantity
                                    );
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->requestItem->unit_snapshot }}</small>
                                    </td>
                                    @if($custody->released_at)
                                        <td>{{ $line->actual_released_quantity + 0 }}</td>
                                        <td>{{ $line->returned_quantity + 0 }}</td>
                                        <td>{{ $lineOnCustody + 0 }}</td>
                                    @else
                                        <td>{{ $line->approved_quantity + 0 }}</td>
                                        <td>Not issued yet</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

        @if($hasLaundryItem || $hasOffCampusItem)
            <section class="content-area">
                <article class="card">
                    <div class="card-header">
                        <div>
                            <p class="eyebrow">Additional processing</p>
                            <h2>Applicable physical records</h2>
                        </div>
                    </div>

                    <div class="borrower-processing-list">
                        @if($hasLaundryItem)
                            <div class="borrower-processing-row">
                                <div class="borrower-processing-copy">
                                    <strong>Linen processing</strong>
                                    <small>
                                        {{ $borrowerLaundryComplete
                                            ? 'Internal linen processing is complete. No further borrower action is required.'
                                            : 'Linen processing is handled internally by SPMU after the physical return. No internal personnel or condition details are shown here.' }}
                                    </small>
                                </div>

                                <x-status-badge
                                    :status="$borrowerLaundryComplete ? 'COMPLETED' : 'RETURN_PROCESSING'"
                                    :label="$borrowerLaundryLabel"
                                />

                                @if($laundryJob?->latestEvidence?->file)
                                    <a
                                        class="button secondary small ui-pressable"
                                        href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View Form
                                    </a>
                                @endif
                            </div>
                        @endif

                        @if($hasOffCampusItem)
                            <div class="borrower-processing-row">
                                <div class="borrower-processing-copy">
                                    <strong>Gate Pass</strong>
                                    <small>
                                        @if($borrowerGatePassComplete)
                                            Off-campus release was recorded{{ $custody->gatePass?->guard_signed_at ? ' on '.$custody->gatePass->guard_signed_at->format('d M Y, g:i A') : '' }}.
                                        @else
                                            This borrowing includes approved off-campus use. The applicable Gate Pass is handled through the physical release process.
                                        @endif
                                    </small>
                                </div>

                                <x-status-badge
                                    :status="$borrowerGatePassComplete ? 'COMPLETED' : 'PREPARING_RELEASE'"
                                    :label="$borrowerGatePassLabel"
                                />

                                @if($custody->gatePass?->accomplished_file_id)
                                    <a
                                        class="button secondary small ui-pressable"
                                        href="{{ route('files.show', $custody->gatePass->accomplished_file_id, false) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        View Gate Pass
                                    </a>
                                @endif
                            </div>
                        @endif
                    </div>
                </article>
            </section>
        @endif
    </div>

    @if($canRequestEarlyReturn)
        <section class="content-area" id="early-return-request">
            <form method="post" action="{{ route('custody.early-return', $custody) }}" class="card form-grid">
                @csrf

                <div class="card-header">
                    <div>
                        <p class="eyebrow">Optional coordination</p>
                        <h2>Request Early Return</h2>
                    </div>
                </div>

                <p class="meta">
                    Propose an earlier physical handover to SPMU. This notice does not return items or change inventory; SPMU records the actual receipt through Return &amp; Inspection.
                </p>

                <label>
                    Proposed Handover Date &amp; Time
                    <input
                        type="datetime-local"
                        name="proposed_return_at"
                        value="{{ old('proposed_return_at') }}"
                        min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}"
                        max="{{ $custody->due_at->format('Y-m-d\TH:i') }}"
                        required
                    >
                </label>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Outstanding Item</th>
                                <th>Outstanding</th>
                                <th>Quantity to Hand Over</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($earlyReturnEligibleLines as $line)
                                @php
                                    $outstanding = max(
                                        0,
                                        (float) $line->actual_released_quantity - (float) $line->returned_quantity
                                    );
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                        <small>{{ $line->requestItem->unit_snapshot }}</small>
                                    </td>
                                    <td>{{ $outstanding + 0 }}</td>
                                    <td>
                                        <input
                                            type="number"
                                            name="quantities[{{ $line->id }}]"
                                            value="{{ old('quantities.'.$line->id, 0) }}"
                                            min="0"
                                            max="{{ floor($outstanding) }}"
                                            step="1"
                                            inputmode="numeric"
                                            required
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <label>
                    Reason / Coordination Note
                    <textarea name="reason" maxlength="1000">{{ old('reason') }}</textarea>
                </label>

                <button class="button primary ui-pressable">Send Early Return Request</button>
            </form>
        </section>
    @endif

@else
<style>
.workflow-focus-target { scroll-margin-top: 96px; }

[data-preparation-result].is-unchecked {
    color: var(--text-muted, #64748b);
}

[data-preparation-result].is-match {
    color: var(--success, #16794b);
}

[data-preparation-result].is-mismatch {
    color: var(--danger, #b42318);
}

.preparation-match-message {
    margin: 12px 0 0;
}
.return-inspection-scroll {
    max-height: clamp(460px, 62vh, 760px);
    overflow: auto;
}
.return-inspection-scroll table { min-width: 1420px; }
.return-inspection-scroll thead th {
    position: sticky;
    top: 0;
    z-index: 3;
    background: var(--surface-subtle, #eef3f8);
}
</style>
@if($showReleaseWorkflow)
    <x-request-progress-tracker :request="$custody->request" />

<section class="content-grid two">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Approved request summary</p>
                <h2>Borrowing schedule</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Purpose / Event</dt>
            <dd>{{ $version?->purpose_event ?: '—' }}</dd>

            <dt>Schedule Date</dt>
            <dd>{{ $scheduleDate?->format('d F Y') ?: 'Not available' }}</dd>

            <dt>Expected Return Date</dt>
            <dd>{{ $returnDate?->format('d F Y') ?: 'Not available' }}</dd>
        </dl>
    </article>

    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Operational requirements</p>
                <h2>Before physical release</h2>
            </div>
        </div>

        <dl class="detail-list">
            <dt>Use Location</dt>
            <dd>{{ $hasOffCampusItem ? 'Off-campus item included' : 'On-campus only' }}</dd>

            <dt>Gate Pass</dt>
            <dd>{{ $hasOffCampusItem ? 'Required before off-campus exit' : 'Not required' }}</dd>

            <dt>Laundry Form</dt>
            <dd>{{ $hasLaundryItem ? 'Required for applicable linen' : 'Not required' }}</dd>

        </dl>
    </article>
</section>

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && !$custody->released_at)
<section class="content-grid two release-prep-grid">
<article class="card release-property-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Approved property</p>
                <h2>Approved and issued quantities</h2>
            </div>
        </div>

        <div class="table-wrap release-items-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Approved Quantity</th>
                        <th>Issued</th>
                        <th>Returned</th>
                        <th>Outstanding</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td>{{ $line->actual_released_quantity + 0 }}</td>
                            <td>{{ $line->returned_quantity + 0 }}</td>
                            <td>
                                {{ max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity) + 0 }}
                            </td>
                            <td>{{ $line->release_condition ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>

<form method="post" action="{{ route('custody.schedule-pickup', $custody) }}" class="card form-grid release-schedule-card">
            @csrf
            <div class="card-header">
                <div>
                    <p class="eyebrow">1. Pickup schedule</p>
                    <h2>Schedule pickup and notify the borrower</h2>
                </div>
                <x-status-badge
                    :status="$hasPickupSchedule ? 'VERIFIED' : 'PENDING'"
                    :label="$hasPickupSchedule ? 'Pickup Scheduled' : 'Schedule Required'"
                />
            </div>

            <div class="content-grid two">
                <label>
                    Pickup Date &amp; Time
                    <input
                        type="datetime-local"
                        name="pickup_at"
                        value="{{ old('pickup_at', optional($custody->scheduled_release_at)->format('Y-m-d\\TH:i')) }}"
                        required
                    >
                </label>

                <label>
                    Claim Until
                    <input
                        type="datetime-local"
                        name="pickup_expires_at"
                        value="{{ old('pickup_expires_at', optional($custody->pickup_expires_at)->format('Y-m-d\\TH:i')) }}"
                        required
                    >
                </label>
            </div>

            <p class="meta">
                Set the pickup window first. Saving the schedule notifies the borrower. The approved quantities stay reserved,
                then the Action Officer confirms the actual prepared quantities once before release.
            </p>

            <button class="button primary ui-pressable">
                {{ $hasPickupSchedule ? 'Update Pickup Schedule & Notify Borrower' : 'Schedule Pickup & Notify Borrower' }}
            </button>
        </form>
</section>
@else
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Approved property</p>
                <h2>Approved and issued quantities</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Approved Quantity</th>
                        <th>Issued</th>
                        <th>Returned</th>
                        <th>Outstanding</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td>{{ $line->actual_released_quantity + 0 }}</td>
                            <td>{{ $line->returned_quantity + 0 }}</td>
                            <td>
                                {{ max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity) + 0 }}
                            </td>
                            <td>{{ $line->release_condition ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif

@if($isSpmuHead)
    <section class="content-area">
        <article class="card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">SPMU Head oversight</p>
                    <h2>Operational processing is assigned to the Action Officer</h2>
                </div>
            </div>
            <p>
                This request is already approved. The Action Officer schedules pickup first, then physically prepares
                and confirms the exact approved quantities once, generates the required physical forms, records the
                handover, and processes the return.
            </p>
        </article>
    </section>
@endif

@if($isSpmuOfficer && $custody->status === 'PREPARING_RELEASE' && !$custody->released_at)

    @if($hasPickupSchedule)
        <section class="content-area workflow-focus-target" id="item-preparation">
            <form
                method="post"
                action="{{ route('custody.prepare', $custody) }}"
                class="card form-grid"
                data-item-preparation-form
            >
                @csrf
                <div class="card-header">
                    <div>
                        <p class="eyebrow">2. Item preparation</p>
                        <h2>Confirm the quantities prepared for release</h2>
                    </div>
                    <x-status-badge
                        :status="$preparationComplete ? 'VERIFIED' : 'PENDING'"
                        :label="$preparationComplete ? 'Preparation Confirmed' : 'Preparation Confirmation Required'"
                    />
                </div>

                @if(!$preparationComplete)
                    <p>
                        Prepare the approved items for the scheduled pickup, then enter the actual quantity prepared for each item.
                        The system compares each entry with the approved quantity. Enter these quantities once; all items must match
                        before preparation can be confirmed.
                    </p>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Approved Qty</th>
                                    <th>Actual Prepared</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($custody->lines as $line)
                                    <tr>
                                        <td>
                                            <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                            <small>{{ $line->requestItem->unit_snapshot }}</small>
                                        </td>
                                        <td data-approved-display>{{ $line->approved_quantity + 0 }}</td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                name="quantities[{{ $line->id }}]"
                                                value="{{ old('quantities.'.$line->id) }}"
                                                placeholder="Enter actual count"
                                                data-prepared-quantity
                                                data-approved="{{ (float) $line->approved_quantity }}"
                                                required
                                            >
                                        </td>
                                        <td>
                                            <strong data-preparation-result class="is-unchecked">Not Checked</strong>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="callout info preparation-match-message" data-preparation-message role="status">
                        Enter the actual prepared quantity for every item. Confirmation stays disabled until all entries match.
                    </div>

                    <button class="button primary ui-pressable" data-confirm-preparation disabled>
                        Confirm Preparation
                    </button>
                @else
                    <div class="empty-state compact">
                        <strong>Preparation confirmed.</strong>
                        <span>
                            The actual prepared quantities were confirmed once against the approved quantities.
                            No quantity re-entry is required for the scheduled release.
                        </span>
                    </div>
                @endif
            </form>
        </section>
    @else
        <section class="content-area">
            <div class="callout info">
                <strong>Next: Item Preparation</strong>
                <p>Save an active pickup schedule first. Item Preparation will open after the borrower has a pickup window.</p>
            </div>
        </section>
    @endif

    <script>
    (() => {
        const form = document.querySelector('[data-item-preparation-form]');
        if (!form) return;

        const inputs = [...form.querySelectorAll('[data-prepared-quantity]')];
        const confirmButton = form.querySelector('[data-confirm-preparation]');
        const message = form.querySelector('[data-preparation-message]');

        if (inputs.length === 0 || !confirmButton) return;

        const epsilon = 0.0005;

        const refreshPreparation = () => {
            let allEntered = true;
            let allMatched = true;

            inputs.forEach((input) => {
                const row = input.closest('tr');
                const result = row?.querySelector('[data-preparation-result]');

                if (!result) return;

                const rawValue = input.value.trim();

                result.classList.remove(
                    'is-unchecked',
                    'is-match',
                    'is-mismatch'
                );

                if (rawValue === '') {
                    allEntered = false;
                    allMatched = false;

                    result.textContent = 'Not Checked';
                    result.classList.add('is-unchecked');

                    return;
                }

                const actual = Number.parseFloat(rawValue);
                const approved = Number.parseFloat(input.dataset.approved || '0');

                const matched =
                    Number.isFinite(actual)
                    && Number.isFinite(approved)
                    && Math.abs(actual - approved) <= epsilon;

                if (matched) {
                    result.textContent = '✓ Match';
                    result.classList.add('is-match');
                } else {
                    result.textContent = 'Mismatch';
                    result.classList.add('is-mismatch');
                    allMatched = false;
                }
            });

            const canConfirm = allEntered && allMatched;
            confirmButton.disabled = !canConfirm;

            if (!message) return;

            message.classList.remove('info', 'warning', 'success');

            if (canConfirm) {
                message.classList.add('success');
                message.textContent =
                    'All prepared quantities match the approved quantities. You may confirm preparation and continue to the physical documents step.';
            } else if (!allEntered) {
                message.classList.add('info');
                message.textContent =
                    'Enter the actual prepared quantity for every item. Confirmation stays disabled until all entries are entered and match the approved quantities.';
            } else {
                message.classList.add('warning');
                message.textContent =
                    'Preparation discrepancy: one or more physical counts do not match the approved quantities. Do not proceed with release. Recheck the physical stock and reconcile the discrepancy before confirming preparation.';
            }
        };

        inputs.forEach((input) => {
            input.addEventListener('input', refreshPreparation);
            input.addEventListener('change', refreshPreparation);
        });

        refreshPreparation();
    })();
    </script>

    @if($preparationComplete && $hasPickupSchedule)
        <section class="content-area workflow-focus-target" id="release-actions">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">3. Document verification</p>
                        <h2>Verify the borrower's printed operational forms</h2>
                    </div>
                </div>

                @forelse($documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document)
                    @php
                        $operationalDocumentTypes = [
                            'BORROWER_SLIP',
                            'GATE_PASS',
                            'LAUNDRY_FORM',
                        ];

                        $isReleaseOperationalDocument = in_array(
                            $document->document_type,
                            $operationalDocumentTypes,
                            true
                        );

                        $documentDisplayStatus = $isReleaseOperationalDocument
                            ? 'BORROWER COPY / REFERENCE'
                            : str($document->status)->replace('_', ' ')->upper();
                    @endphp

                    <div class="evidence-row">
                        <div>
                            <strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong>
                            <small>{{ $documentDisplayStatus }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('documents.download', $document) }}">
                            View reference
                        </a>
                    </div>
                @empty
                    <div class="empty-state">
                        <strong>No operational form generated yet.</strong>
                    </div>
                @endforelse

                <p class="meta top-gap">
                    These forms were generated after SPMU approval and are also available in the Borrower request page for printing. Verify that the borrower brought the correct Borrower Slip and any applicable Gate Pass / Laundry Form. Do not generate a duplicate copy here. Required handwritten signatures are completed through the applicable physical workflow.
                </p>
            </article>
        </section>

        <section class="content-area">
            <form method="post" action="{{ route('custody.release', $custody) }}" class="card form-grid">
                @csrf
                <div class="card-header">
                    <div>
                        <p class="eyebrow">4. Physical release</p>
                        <h2>Record the actual handover</h2>
                    </div>
                    <x-status-badge status="READY_FOR_RELEASE" label="Ready for Physical Release" />
                </div>

                <p>
                    Release is recorded only when the borrower is physically present, the approved items are handed over,
                    the Borrower Slip is signed by hand, and any required physical Gate Pass is completed.
                    The confirmed prepared quantities become the issued quantities automatically; do not enter them again.
                </p>

                <label class="checkbox">
                    <input
                        id="physical-signatures-confirmed"
                        type="checkbox"
                        name="physical_signatures_confirmed"
                        value="1"
                        required
                        autocomplete="off"
                    >
                    I confirm that the borrower physically received the approved items and the Borrower Slip,
                    and Gate Pass if applicable, were completed with the required handwritten/wet signatures.
                </label>

                <label>
                    Release Remarks
                    <textarea
                        name="remarks"
                        placeholder="Optional physical handover note"
                    ></textarea>
                </label>

                <button
                    id="confirm-physical-release-button"
                    class="button primary ui-pressable"
                    type="submit"
                    disabled
                >
                    Confirm Physical Release
                </button>
            </form>

            <script>
            (() => {
                const confirmation = document.getElementById('physical-signatures-confirmed');
                const releaseButton = document.getElementById('confirm-physical-release-button');

                if (!confirmation || !releaseButton) return;

                const refreshReleaseConfirmation = () => {
                    releaseButton.disabled = !confirmation.checked;
                };

                /*
                 * Operational safety: browsers can restore checkbox state when
                 * navigating back/forward. A physical handover confirmation must
                 * always be made deliberately for the current release action.
                 */
                const resetReleaseConfirmation = () => {
                    confirmation.checked = false;
                    refreshReleaseConfirmation();
                };

                confirmation.addEventListener('change', refreshReleaseConfirmation);
                window.addEventListener('pageshow', resetReleaseConfirmation);

                resetReleaseConfirmation();
            })();
            </script>
        </section>
    @endif
@endif

@endif {{-- showReleaseWorkflow --}}

@if($showReturnWorkflow)
<div id="return-workflow" class="workflow-focus-target">

@if($isSpmuOfficer && $custody->released_at)
    @php
        /*
         * LINEN SPMU READONLY ACCEPTANCE V4 20260823
         *
         * The generic editable Fine/Damaged/etc table is NON-LINEN only.
         * SPMU Action Officer already records the linen quantities/findings.
         */
        $eligibleReturnLines = $custody->lines->filter(function ($line) {
            $outstanding = max(
                0,
                (float) $line->actual_released_quantity
                    - (float) $line->returned_quantity
            );

            return $outstanding > 0
                && ! (bool) $line->requestItem?->inventoryItem?->laundry_required;
        });
        $nonLinenLines = $custody->lines->filter(
            fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
        );

        $linenLines = $custody->lines->filter(
            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
        );

        $nonLinenOutstanding = $nonLinenLines->sum(
            fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
        );

        $linenOutstanding = $linenLines->sum(
            fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
        );

        $linenReadyForSpmuAcceptance =
            $linenOutstanding > 0
            && $laundryJob
            && $laundryJob->status === 'READY_FOR_SPMU_RETURN';

        /*
         * Require a complete laundry-processing record before final SPMU acceptance.
         * SPMU sees these values read-only and does not retype them.
         */
        $linenRecordsComplete =
            $linenReadyForSpmuAcceptance
            && (bool) $laundryJob?->worker_received_at
            && (bool) $laundryJob?->worker_completed_at
            && $linenLines
                ->filter(
                    fn ($line) =>
                        max(
                            0,
                            (float) $line->actual_released_quantity
                                - (float) $line->returned_quantity
                        ) > 0
                )
                ->every(function ($line) use ($laundryJob) {
                    $laundryLine = $laundryJob->lines->firstWhere(
                        'custody_line_id',
                        $line->id
                    );

                    if (! $laundryLine || $laundryLine->received_quantity === null) {
                        return false;
                    }

                    $outstanding = max(
                        0,
                        (float) $line->actual_released_quantity
                            - (float) $line->returned_quantity
                    );

                    $accounted =
                        (float) ($laundryLine->fine_quantity ?? 0)
                        + (float) ($laundryLine->damaged_quantity ?? 0)
                        + (float) ($laundryLine->missing_lost_quantity ?? 0)
                        + (float) ($laundryLine->stolen_quantity ?? 0)
                        + (float) ($laundryLine->destroyed_quantity ?? 0);

                    return abs($accounted - $outstanding) <= 0.0005;
                });

        $linenOperationalStatus = match (true) {
            $linenLines->isEmpty() => 'Not applicable',
            $linenOutstanding <= 0 => 'Fully accounted',
            ! $laundryJob => 'Awaiting Laundry workflow',
            $laundryJob->status === 'FOR_LAUNDRY' =>
                'Awaiting borrower turnover to Laundry',
            $laundryJob->status === 'IN_PROCESS' =>
                'In Laundry process',
            $laundryJob->status === 'READY_FOR_SPMU_RETURN' =>
                'Cleaned linen awaiting SPMU acceptance',
            $laundryJob->status === 'AWAITING_FINAL_FORM_UPLOAD' =>
                'SPMU accepted linen â€” final form upload pending',
            $laundryJob->status === 'FORM_REPLACEMENT_REQUIRED' =>
                'Final Laundry Form replacement required',
            $laundryJob->status === 'LAUNDRY_COMPLETED' =>
                'Laundry completed / settled',
            default => 'Return processing',
        };

        [$linenNextTitle, $linenNextCopy, $linenNextTone] =
            match ($laundryJob?->status) {
                'FOR_LAUNDRY' => [
                    'Waiting for Laundry turnover',
                    'The borrower must hand the used linen and borrower-signed physical Laundry Form to the SPMU Action Officer.',
                    'warning',
                ],
                'IN_PROCESS' => [
                    'Laundry is processing the linen',
                    'Final SPMU linen acceptance is not required yet. Complete the recorded laundry-processing stage first.',
                    'info',
                ],
                'READY_FOR_SPMU_RETURN' => [
                    'Cleaned linen is awaiting SPMU acceptance',
                    'Review the laundry-processing record below and physically verify the cleaned linen and physical form. Do not re-enter the Laundry quantities.',
                    'success',
                ],
                'AWAITING_FINAL_FORM_UPLOAD' => [
                    'Final Laundry Form upload pending',
                    'SPMU final acceptance is complete. The SPMU Action Officer archives the fully signed scan to settle the Laundry transaction.',
                    'warning',
                ],
                'FORM_REPLACEMENT_REQUIRED' => [
                    'Laundry Form replacement required',
                    'The SPMU Action Officer must upload a clear replacement copy of the fully signed Laundry Form.',
                    'warning',
                ],
                'LAUNDRY_COMPLETED' => [
                    'Laundry completed',
                    'The cleaned linen was accepted by SPMU and the fully signed Laundry Form is archived.',
                    'success',
                ],
                default => [
                    'No separate Laundry action',
                    'Review the current return state for the next required action.',
                    'info',
                ],
            };
        $returnAvailableToday = (bool) $activeEarlyReturn
            || ! $returnDate
            || ! now()->startOfDay()->lt($returnDate->copy()->startOfDay());
        $hasRecordedReturns = $custody->returns->isNotEmpty();
    @endphp

    <section class="content-grid two return-context-grid" id="return-summary">
        <article class="card return-context-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Transaction summary</p>
                    <h2>Borrowing context</h2>
                </div>
            </div>

            <dl class="detail-list compact-detail-list">
                <dt>Purpose / Event</dt>
                <dd>{{ $version?->purpose_event ?: '—' }}</dd>

                <dt>Scheduled Use</dt>
                <dd>{{ $scheduleDate?->format('d F Y') ?: '—' }}</dd>

                <dt>Expected Return</dt>
                <dd>{{ $returnDate?->format('d F Y') ?: '—' }}</dd>

                <dt>Use Location</dt>
                <dd>{{ $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only' }}</dd>
            </dl>
        </article>

        <article class="card return-context-card return-documents-card">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Operational documents</p>
                    <h2>Accomplished return documents</h2>
                </div>
            </div>

            <div class="return-document-list">
                <div class="return-document-row">
                    <div>
                        <strong>Laundry Form</strong>
                        <small>{{ $hasLaundryItem ? 'Required for linen included in this borrowing.' : 'Not applicable — this borrowing has no linen items.' }}</small>
                    </div>
                    @if(!$hasLaundryItem)
                        <span class="status-badge status-neutral">Locked</span>
                    @elseif($laundryJob?->latestEvidence?->file)
                        <a class="button secondary small ui-pressable" href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}" target="_blank" rel="noopener">View uploaded form</a>
                    @else
                        <span class="status-badge status-warning">Pending accomplished scan</span>
                    @endif
                </div>

                <div class="return-document-row">
                    <div>
                        <strong>Gate Pass</strong>
                        <small>{{ $hasOffCampusItem ? 'Required for approved off-campus use.' : 'Not applicable — this borrowing is on-campus only.' }}</small>
                    </div>
                    @if(!$hasOffCampusItem)
                        <span class="status-badge status-neutral">Locked</span>
                    @elseif($custody->gatePass?->accomplishedFile)
                        <a class="button secondary small ui-pressable" href="{{ route('files.show', $custody->gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View uploaded gate pass</a>
                    @else
                        <span class="status-badge status-warning">Pending accomplished scan</span>
                    @endif
                </div>

                <div class="return-document-row">
                    <div>
                        <strong>Receipt</strong>
                        <small>{{ $relatedBillings->isNotEmpty() ? 'Payment evidence for a billing linked to this borrowing.' : 'Not applicable — no billing obligation is linked to this borrowing.' }}</small>
                    </div>
                    @if($relatedBillings->isEmpty())
                        <span class="status-badge status-neutral">Locked</span>
                    @elseif($latestReceipt?->evidence_file_id)
                        <a class="button secondary small ui-pressable" href="{{ route('files.show', $latestReceipt->evidence_file_id, false) }}" target="_blank" rel="noopener">View receipt</a>
                    @else
                        <span class="status-badge status-warning">Pending receipt upload</span>
                    @endif
                </div>
            </div>
        </article>
    </section>

    <section class="content-area return-primary-section" id="return-primary">
        <div class="return-workspace-grid">
            <div class="return-primary-stack">

                {{-- ================================================= --}}
                {{-- NON-LINEN â€” editable SPMU physical inspection     --}}
                {{-- ================================================= --}}
                @if(!$returnAvailableToday && $eligibleReturnLines->isNotEmpty())
                    <div class="callout info">
                        <strong>Return inspection opens on the effective SPMU return date.</strong>
                        <p>
                            {{ $returnDateAdjusted ? 'Original Expected Return: '.$originalReturnDate?->format('d F Y').'. ' : '' }}
                            Effective Return: {{ $returnDate?->format('d F Y') }}.
                            @if($returnDateAdjusted)
                                The original date is closed under the Operational Calendar, so the borrower is protected from an automatic late status until this adjusted date.
                            @endif
                            If issued items remain outstanding after the effective return date, the transaction becomes overdue on the following calendar day.
                        </p>
                    </div>
                @elseif($eligibleReturnLines->isNotEmpty())
                    <form
                        method="post"
                        action="{{ route('custody.return', $custody) }}"
                        enctype="multipart/form-data"
                        class="card form-grid return-inspection-card"
                        id="full-return-accounting-form"
                    >
                        @csrf

                        <div class="card-header return-inspection-header">
                            <div>
                                <p class="eyebrow">Physical return inspection</p>
                                <h2>Account non-linen returned quantities</h2>
                                <p class="meta">
                                    For each non-linen item inspected now, account its
                                    complete outstanding quantity in one inspection.
                                    Split item-quantity returns are not allowed.
                                </p>
                            </div>

                            <span class="status-badge status-info">
                                {{ $eligibleReturnLines->count() }} non-linen item type(s) ready
                            </span>
                        </div>

                        <div class="table-wrap return-inspection-scroll">
                            <table class="return-inspection-table">
                                <thead>
                                    <tr>
                                        <th>Item / Outstanding</th>
                                        <th>Fine / Good</th>
                                        <th>Damaged</th>
                                        <th>Destroyed</th>
                                        <th>Missing</th>
                                        <th>Lost</th>
                                        <th>Stolen</th>
                                        <th>Accounted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($eligibleReturnLines as $line)
                                        @php
                                            $outstanding = max(
                                                0,
                                                (float) $line->actual_released_quantity
                                                    - (float) $line->returned_quantity
                                            );

                                            $oldBreakdown = old(
                                                'accounting.'.$line->id,
                                                []
                                            );

                                            $oldNonFine = collect([
                                                'DAMAGED',
                                                'DESTROYED',
                                                'MISSING',
                                                'LOST',
                                                'STOLEN',
                                            ])->sum(
                                                fn ($code) =>
                                                    (float) ($oldBreakdown[$code] ?? 0)
                                            );

                                            $oldStolen =
                                                (float) ($oldBreakdown['STOLEN'] ?? 0);
                                        @endphp

                                        <tr
                                            class="return-accounting-row"
                                            data-outstanding="{{ $outstanding }}"
                                        >
                                            <td class="return-item-cell">
                                                <strong>
                                                    {{ $line->requestItem->description_snapshot }}
                                                </strong>
                                                <small>
                                                    {{ $line->requestItem->unit_snapshot }}
                                                    Â· Outstanding: {{ $outstanding + 0 }}
                                                </small>
                                            </td>

                                            @foreach([
                                                'FINE',
                                                'DAMAGED',
                                                'DESTROYED',
                                                'MISSING',
                                                'LOST',
                                                'STOLEN',
                                            ] as $conditionCode)
                                                <td>
                                                    <input
                                                        type="number"
                                                        step="0.001"
                                                        min="0"
                                                        max="{{ $outstanding }}"
                                                        class="return-accounting-input"
                                                        data-condition="{{ $conditionCode }}"
                                                        name="accounting[{{ $line->id }}][{{ $conditionCode }}]"
                                                        value="{{ old('accounting.'.$line->id.'.'.$conditionCode, 0) }}"
                                                        aria-label="{{ str($conditionCode)->replace('_', ' ')->title() }} quantity for {{ $line->requestItem->description_snapshot }}"
                                                    >
                                                </td>
                                            @endforeach

                                            <td>
                                                <strong class="return-accounted-total">
                                                    0 / {{ $outstanding + 0 }}
                                                </strong>
                                                <small class="return-accounted-state">
                                                    Not selected
                                                </small>
                                            </td>
                                        </tr>

                                        <tr
                                            class="return-issue-details"
                                            data-return-issue-details
                                            @if($oldNonFine <= 0) hidden @endif
                                        >
                                            <td colspan="8">
                                                <div class="return-issue-details__grid">
                                                    <label data-evidence-wrap>
                                                        Evidence for non-good quantity
                                                        <input
                                                            type="file"
                                                            class="return-evidence-input"
                                                            name="evidence_files[{{ $line->id }}]"
                                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                                        >
                                                        <small>
                                                            Required only when Damaged,
                                                            Destroyed, Missing, Lost, or
                                                            Stolen is greater than zero.
                                                        </small>
                                                    </label>

                                                    <label
                                                        data-police-wrap
                                                        @if($oldStolen <= 0) hidden @endif
                                                    >
                                                        Police / blotter reference
                                                        <input
                                                            class="return-police-input"
                                                            name="police_blotter_references[{{ $line->id }}]"
                                                            value="{{ old('police_blotter_references.'.$line->id) }}"
                                                        >
                                                        <small>
                                                            Required only when Stolen is
                                                            greater than zero.
                                                        </small>
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="return-action-area">
                            <div
                                class="callout warning return-accounting-message"
                                id="return-accounting-message"
                                role="status"
                            >
                                Select an item and account its complete outstanding quantity.
                            </div>

                            <label>
                                Inspection Remarks
                                <textarea
                                    name="remarks"
                                    rows="3"
                                    placeholder="Optional inspection note"
                                >{{ old('remarks') }}</textarea>
                            </label>

                            <button
                                class="button primary ui-pressable"
                                id="record-return-button"
                                disabled
                            >
                                Record Return Inspection
                            </button>
                        </div>
                    </form>
                @endif

                {{-- ================================================= --}}
                {{-- LINEN â€” read-only Laundry record + SPMU accept    --}}
                {{-- ================================================= --}}
                @if($linenReadyForSpmuAcceptance && $laundryJob)
                    <form
                        method="post"
                        action="{{ route('custody.return', $custody) }}"
                        class="card form-grid linen-spmu-acceptance-card"
                    >
                        @csrf

                        <div class="card-header">
                            <div>
                                <p class="eyebrow">Cleaned linen return</p>
                                <h2>Verify Laundry Worker record &amp; accept linen</h2>
                                <p class="meta">
                                    Laundry Request {{ $laundryJob->laundry_request_id }} was encoded by
                                    {{ $laundryJob->worker_name ?: 'the Laundry Worker' }}.
                                    The quantities below are read-only for SPMU.
                                </p>
                            </div>

                            <span class="status-badge status-info">
                                SPMU Acceptance Required
                            </span>
                        </div>

                        <div class="callout info linen-no-reentry-callout">
                            <strong>No duplicate quantity encoding</strong>
                            <p>
                                Compare the cleaned linen and physical Laundry Form with
                                the read-only laundry-processing record below. If anything
                                does not match, stop and have Laundry correct the record
                                before confirming acceptance.
                            </p>
                        </div>

                        @unless($linenRecordsComplete)
                            <div class="callout danger">
                                <strong>Laundry record is incomplete.</strong>
                                <p>
                                    SPMU acceptance is blocked until Laundry records the receipt date,
                                    completion date, actual quantity received, and a complete condition
                                    breakdown for every outstanding linen item.
                                </p>
                            </div>
                        @endunless

                        <dl class="detail-list compact-detail-list">
                            <dt>Laundry Request ID</dt>
                            <dd>{{ $laundryJob->laundry_request_id }}</dd>
                            <dt>Laundry Worker</dt>
                            <dd>{{ $laundryJob->worker_name ?: 'Not recorded' }}</dd>
                            <dt>Date received by Laundry</dt>
                            <dd>{{ optional($laundryJob->worker_received_at)->format('d M Y, g:i A') ?: 'Not recorded' }}</dd>
                            <dt>Laundry completed</dt>
                            <dd>{{ optional($laundryJob->worker_completed_at)->format('d M Y, g:i A') ?: 'Not recorded' }}</dd>
                        </dl>

                        <div class="table-wrap linen-spmu-verification-scroll">
                            <table class="linen-spmu-verification-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Outstanding</th>
                                        <th>Received by Laundry</th>
                                        <th>Fine / Good</th>
                                        <th>Damaged</th>
                                        <th>Lost / Missing</th>
                                        <th>Stolen</th>
                                        <th>Destroyed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($linenLines as $line)
                                        @php
                                            $outstanding = max(
                                                0,
                                                (float) $line->actual_released_quantity
                                                    - (float) $line->returned_quantity
                                            );

                                            $laundryLine = $laundryJob->lines->firstWhere(
                                                'custody_line_id',
                                                $line->id
                                            );

                                            $received = max(
                                                0,
                                                (float) ($laundryLine?->received_quantity ?? 0)
                                            );
                                            $fine = max(
                                                0,
                                                (float) ($laundryLine?->fine_quantity ?? 0)
                                            );
                                            $damaged = max(
                                                0,
                                                (float) ($laundryLine?->damaged_quantity ?? 0)
                                            );
                                            $missingLost = max(
                                                0,
                                                (float) ($laundryLine?->missing_lost_quantity ?? 0)
                                            );
                                            $stolen = max(
                                                0,
                                                (float) ($laundryLine?->stolen_quantity ?? 0)
                                            );
                                            $destroyed = max(
                                                0,
                                                (float) ($laundryLine?->destroyed_quantity ?? 0)
                                            );
                                        @endphp

                                        @if($outstanding > 0)
                                            <tr>
                                                <td>
                                                    <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                                    <small>{{ $line->requestItem->unit_snapshot }}</small>
                                                </td>
                                                <td>{{ $outstanding + 0 }}</td>
                                                <td>{{ $received + 0 }}</td>
                                                <td>{{ $fine + 0 }}</td>
                                                <td>{{ $damaged + 0 }}</td>
                                                <td>{{ $missingLost + 0 }}</td>
                                                <td>{{ $stolen + 0 }}</td>
                                                <td>{{ $destroyed + 0 }}</td>
                                            </tr>

                                            {{-- Hidden backend accounting derived from Laundry Worker record. --}}
                                            <input type="hidden" name="accounting[{{ $line->id }}][FINE]" value="{{ $fine }}">
                                            <input type="hidden" name="accounting[{{ $line->id }}][DAMAGED]" value="{{ $damaged }}">
                                            <input type="hidden" name="accounting[{{ $line->id }}][DESTROYED]" value="{{ $destroyed }}">
                                            <input type="hidden" name="accounting[{{ $line->id }}][MISSING]" value="{{ $missingLost }}">
                                            <input type="hidden" name="accounting[{{ $line->id }}][LOST]" value="0">
                                            <input type="hidden" name="accounting[{{ $line->id }}][STOLEN]" value="{{ $stolen }}">

                                            @if($stolen > 0)
                                                <tr class="linen-evidence-row">
                                                    <td colspan="8">
                                                        <label>
                                                            Police / blotter reference for
                                                            {{ $line->requestItem->description_snapshot }}
                                                            <input
                                                                name="police_blotter_references[{{ $line->id }}]"
                                                                value="{{ old('police_blotter_references.'.$line->id) }}"
                                                                required
                                                            >
                                                            <small>
                                                                Required only because Laundry recorded a stolen quantity.
                                                                SPMU does not re-enter the stolen quantity itself.
                                                            </small>
                                                        </label>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <label>
                            SPMU Acceptance Remarks
                            <textarea
                                name="remarks"
                                rows="3"
                                placeholder="Optional physical verification note"
                            >{{ old('remarks') }}</textarea>
                        </label>

                        <label class="checkbox linen-spmu-confirmation">
                            <input
                                type="checkbox"
                                name="physical_verified"
                                value="1"
                                required
                                @disabled(!$linenRecordsComplete)
                            >
                            I confirm that SPMU physically verified and accepted
                            the cleaned linen, the actual handover matches the
                            laundry-processing record, and the same physical Laundry
                            Form has the required SPMU wet signature.
                        </label>

                        <button
                            class="button primary ui-pressable"
                            @disabled(!$linenRecordsComplete)
                        >
                            Confirm SPMU Acceptance
                        </button>

                        <p class="meta">
                            This uses the existing audited physical-return backend.
                            After all linen is accepted, the transaction moves to
                            <strong>Awaiting Final Form Upload</strong>. The physical
                            form remains with the SPMU Action Officer, who scans and uploads
                            the fully signed form to settle the Laundry transaction.
                        </p>
                    </form>
                @elseif($eligibleReturnLines->isEmpty())
                    <article class="card return-empty-state">
                        <div class="empty-state">
                            <strong>
                                No item needs SPMU quantity encoding right now.
                            </strong>
                            <span>
                                Review the Return Status panel for the next Laundry
                                or return action.
                            </span>
                        </div>
                    </article>
                @endif
            </div>

            <article class="card return-status-card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Return status</p>
                        <h2>What needs attention</h2>
                    </div>

                    @unless($custody->status === 'CLOSED')
                        <x-status-badge :status="$custody->status" />
                    @endunless
                </div>

                <div class="return-status-scroll">
                    <dl class="detail-list compact-detail-list">
                        <dt>Issued</dt>
                        <dd>
                            {{ optional($custody->released_at)->format('d M Y, g:i A') ?: 'â€”' }}
                        </dd>

                        <dt>Expected Return</dt>
                        <dd>{{ $returnDate?->format('d M Y') ?: 'â€”' }}</dd>

                        <dt>{{ $custody->status === 'OVERDUE' ? 'Total Overdue' : 'Total On Custody' }}</dt>
                        <dd>{{ $outstandingTotal + 0 }}</dd>

                        @if($nonLinenLines->isNotEmpty())
                            <dt>Non-linen</dt>
                            <dd>
                                {{
                                    $nonLinenOutstanding <= 0
                                        ? 'Fully returned'
                                        : ($nonLinenOutstanding + 0).($custody->status === 'OVERDUE' ? ' overdue' : ' on custody')
                                }}
                            </dd>
                        @endif

                        @if($linenLines->isNotEmpty())
                            <dt>Linen</dt>
                            <dd>
                                {{ $linenOperationalStatus }}
                                @if(
                                    $linenOutstanding > 0
                                    && $laundryJob?->status !== 'AWAITING_FINAL_FORM_UPLOAD'
                                )
                                    Â· {{ $linenOutstanding + 0 }} outstanding
                                @endif
                            </dd>
                        @endif
                    </dl>

                    @if($linenLines->isNotEmpty())
                        <div
                            class="callout {{ $linenNextTone }} return-next-callout"
                        >
                            <strong>{{ $linenNextTitle }}</strong>
                            <p>{{ $linenNextCopy }}</p>
                        </div>

                        @if($laundryJob?->latestEvidence?->file)
                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                                target="_blank"
                                rel="noopener"
                            >
                                View Final Signed Laundry Form
                            </a>
                        @endif
                    @endif
                </div>
            </article>
        </div>
    </section>

    <style>
        /* LINEN SPMU READONLY ACCEPTANCE V4 20260823 */
        .return-primary-stack {
            display: grid;
            gap: 18px;
            min-width: 0;
        }

        .linen-spmu-acceptance-card {
            min-width: 0;
        }

        .linen-no-reentry-callout {
            margin: 0;
        }

        .linen-no-reentry-callout p {
            margin-bottom: 0;
        }

        .linen-spmu-verification-scroll {
            max-height: 340px;
            overflow: auto;
            overscroll-behavior: contain;
        }

        .linen-spmu-verification-table {
            min-width: 760px;
        }

        .linen-spmu-verification-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--surface-subtle, #eef3f8);
        }

        .linen-spmu-verification-table td small {
            display: block;
            margin-top: 3px;
            color: var(--text-muted, #64748b);
        }

        .linen-accounting-preview {
            margin-top: 7px !important;
            font-weight: 700;
            color: var(--text, #1f3b5b) !important;
        }

        .linen-evidence-row td {
            background: var(--surface-subtle, #f7f9fc);
        }

        .linen-evidence-row label {
            display: grid;
            gap: 7px;
        }
    </style>
    @if($hasRecordedReturns)
        <section class="content-area return-history-section">
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Return history</p>
                        <h2>Recorded inspections</h2>
                    </div>
                </div>

                <div class="table-wrap return-history-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Return</th>
                                <th>Received</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($custody->returns->sortByDesc('id') as $return)
                                <tr>
                                    <td>{{ $return->return_no }}</td>
                                    <td>{{ optional($return->received_at)->format('d M Y, g:i A') ?: '—' }}</td>
                                    <td>{{ str($return->return_type ?: 'NORMAL')->replace('_', ' ')->title() }}</td>
                                    <td><x-status-badge :status="$return->status" /></td>
                                    <td>{{ $return->remarks ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    @endif

    <style>
        .workflow-focus-target,
        #return-primary {
            scroll-margin-top: 96px;
        }

        .return-primary-section {
            margin-top: 24px;
        }

        .return-context-grid {
            align-items: stretch;
            gap: 22px;
            margin-bottom: 0;
        }

        .return-history-section {
            margin-top: 24px;
        }

        .return-context-card {
            min-width: 0;
            height: 100%;
        }

        .return-document-list {
            display: grid;
            gap: 0;
            padding-top: 2px;
        }

        .return-document-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border, #d7dee8);
        }

        .return-document-row:first-child {
            padding-top: 0;
        }

        .return-document-row:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .return-document-row > div:first-child {
            display: grid;
            gap: 3px;
            min-width: 0;
        }

        .return-document-row small {
            color: var(--text-muted, #64748b);
            line-height: 1.4;
        }

        .return-document-row--stack {
            align-items: flex-start;
        }

        .return-document-actions {
            display: flex !important;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px !important;
        }

        .return-workspace-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(320px, .75fr);
            gap: 24px;
            align-items: stretch;
        }

        .return-workspace-grid > * {
            min-width: 0;
        }

        .return-inspection-card,
        .return-status-card {
            height: 100%;
            min-height: 0;
        }

        .return-inspection-card {
            display: flex;
            flex-direction: column;
        }

        .return-inspection-header {
            align-items: flex-start;
        }

        .return-inspection-header .meta {
            max-width: 820px;
            margin: 5px 0 0;
        }

        .return-inspection-scroll {
            max-height: clamp(250px, 31vh, 360px);
            overflow: auto;
            overscroll-behavior: contain;
        }

        .return-inspection-table {
            min-width: 980px;
        }

        .return-inspection-table thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            background: var(--surface-subtle, #eef3f8);
        }

        .return-inspection-table th:first-child,
        .return-inspection-table td:first-child {
            min-width: 190px;
        }

        .return-inspection-table input[type="number"] {
            min-width: 78px;
        }

        .return-item-cell {
            position: sticky;
            left: 0;
            z-index: 2;
            background: var(--surface, #fff);
        }

        .return-inspection-table thead th:first-child {
            z-index: 4;
            background: var(--surface-subtle, #eef3f8);
        }

        .return-issue-details[hidden],
        [data-police-wrap][hidden] {
            display: none !important;
        }

        .return-issue-details td {
            padding: 12px 16px 16px;
            background: rgba(15, 23, 42, .025);
        }

        .return-issue-details__grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(240px, .75fr);
            gap: 14px;
        }

        .return-action-area {
            display: grid;
            gap: 12px;
            margin-top: 14px;
        }

        .return-accounting-message {
            margin: 0;
            padding-block: 11px;
        }

        .return-action-area textarea {
            min-height: 82px;
        }

        .return-early-option {
            margin: 0;
        }

        .return-status-card {
            display: flex;
            flex-direction: column;
        }

        .return-status-scroll {
            flex: 1 1 auto;
            min-height: 0;
            max-height: clamp(430px, 55vh, 620px);
            overflow: auto;
            padding: 2px 4px 6px 0;
        }

        .compact-detail-list {
            margin-bottom: 18px;
        }

        .return-next-callout {
            margin: 4px 0 18px;
        }

        .return-next-callout p {
            margin-bottom: 0;
        }

        .return-empty-state {
            min-height: 280px;
            display: grid;
            place-content: center;
        }

        .return-history-scroll {
            max-height: 320px;
            overflow: auto;
        }

        .return-history-scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: var(--surface-subtle, #eef3f8);
        }

        @media (max-width: 1180px) {
            .return-workspace-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .return-context-grid {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            .return-primary-section,
            .return-history-section {
                margin-top: 18px;
            }

            .return-status-scroll {
                max-height: none;
                overflow: visible;
            }

            .return-inspection-scroll {
                max-height: 380px;
            }
        }

        @media (max-width: 700px) {
            .return-issue-details__grid {
                grid-template-columns: 1fr;
            }

            .return-document-row {
                align-items: stretch;
                flex-direction: column;
            }

            .return-document-actions {
                justify-content: flex-start;
            }

            .return-inspection-scroll {
                max-height: 330px;
            }
        }
    </style>

    @if($eligibleReturnLines->isNotEmpty())
        <script>
        (() => {
            const form = document.getElementById('full-return-accounting-form');
            if (!form) return;

            const rows = [...form.querySelectorAll('.return-accounting-row')];
            const button = document.getElementById('record-return-button');
            const message = document.getElementById('return-accounting-message');
            const epsilon = 0.0005;

            const numberValue = (input) => {
                const value = Number.parseFloat(input?.value || '0');
                return Number.isFinite(value) && value > 0 ? value : 0;
            };

            const refresh = () => {
                let selected = 0;
                let allValid = true;

                rows.forEach((row) => {
                    const outstanding = Number.parseFloat(row.dataset.outstanding || '0');
                    const inputs = [...row.querySelectorAll('.return-accounting-input')];
                    const total = inputs.reduce((sum, input) => sum + numberValue(input), 0);
                    const nonFine = inputs
                        .filter((input) => input.dataset.condition !== 'FINE')
                        .reduce((sum, input) => sum + numberValue(input), 0);
                    const stolen = numberValue(row.querySelector('[data-condition="STOLEN"]'));
                    const detailsRow = row.nextElementSibling?.matches('[data-return-issue-details]')
                        ? row.nextElementSibling
                        : null;
                    const evidence = detailsRow?.querySelector('.return-evidence-input');
                    const police = detailsRow?.querySelector('.return-police-input');
                    const policeWrap = detailsRow?.querySelector('[data-police-wrap]');
                    const totalLabel = row.querySelector('.return-accounted-total');
                    const stateLabel = row.querySelector('.return-accounted-state');

                    if (detailsRow) detailsRow.hidden = nonFine <= epsilon;
                    if (policeWrap) policeWrap.hidden = stolen <= epsilon;
                    if (evidence) evidence.required = nonFine > epsilon;
                    if (police) police.required = stolen > epsilon;

                    if (totalLabel) totalLabel.textContent = `${total} / ${outstanding}`;

                    if (total <= epsilon) {
                        if (stateLabel) stateLabel.textContent = 'Not selected';
                        return;
                    }

                    selected++;
                    const complete = Math.abs(total - outstanding) <= epsilon;
                    if (!complete) allValid = false;
                    if (stateLabel) {
                        stateLabel.textContent = complete
                            ? 'Fully accounted'
                            : 'Incomplete — classify the balance';
                    }
                });

                const ready = selected > 0 && allValid;
                button.disabled = !ready;
                message.classList.toggle('warning', !ready);
                message.classList.toggle('success', ready);
                message.textContent = ready
                    ? 'Selected item quantities are fully accounted. You may record the SPMU inspection.'
                    : 'For each selected item, Fine + Damaged + Destroyed + Missing + Lost + Stolen must equal its full outstanding quantity.';
            };

            rows.forEach((row) => {
                row.querySelectorAll('.return-accounting-input').forEach((input) => {
                    input.addEventListener('input', refresh);
                });
            });

            refresh();
        })();
        </script>
    @endif
@elseif($isSpmuOfficer)
    <section class="content-area">
        <div class="callout info">
            <strong>This transaction has not been physically released yet.</strong>
            <p>Complete the Release workflow before recording a return.</p>
        </div>
    </section>
@endif

</div>
@endif {{-- showReturnWorkflow --}}

@endif
@endsection
