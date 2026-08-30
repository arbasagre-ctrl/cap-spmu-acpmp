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

    $pickupWindowStartsAt = $custody->scheduled_release_at;
    $pickupWindowEndsAt = $custody->pickup_expires_at;
    $pickupWindowUpcoming = $hasPickupSchedule
        && now()->lt($pickupWindowStartsAt);

    $pickupWindowPassed = $hasPickupSchedule
        && now()->gt($pickupWindowEndsAt);

    $pickupWindowOpen = $hasPickupSchedule
        && ! $pickupWindowUpcoming
        && ! $pickupWindowPassed;


    $hasOffCampusItem = $custody->lines->contains(
        fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
    );

    $hasLaundryItem = $custody->lines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $laundryJob = $custody->relationLoaded('laundryJob') ? $custody->laundryJob : null;

    /*
     * Borrower Cleared vs. Completed
     * ------------------------------
     * Borrower Cleared = custody.status === 'CLOSED': the borrower has
     * physically returned everything required and no unresolved
     * accountability remains (linen at least turned over to Laundry).
     * Completed = Borrower Cleared AND, only when linen is involved,
     * internal Laundry processing has actually finished AND the
     * accomplished Laundry Form has been archived. Archival alone (without
     * LAUNDRY_COMPLETED) is NOT enough to call the transaction Completed —
     * it stays Borrower Cleared until internal processing is also done.
     */
    $transactionFullyComplete = $custody->status === 'CLOSED'
        && (
            ! $hasLaundryItem
            || ($laundryJob?->status === 'LAUNDRY_COMPLETED' && $laundryJob?->latestEvidence?->file)
        );

    $operationalLabel = match (true) {
        $custody->status === 'CLOSED' => $transactionFullyComplete ? 'Completed' : 'Borrower Cleared',
        $custody->status === 'OBLIGATION_OPEN' => 'Return Reconciliation / Obligation Open',
        $custody->status === 'RETURN_PROCESSING' => 'Return Processing',
        $custody->status === 'OVERDUE' => 'Overdue',
        (bool) $custody->released_at => 'Items Released / On Custody',
        $preparationComplete && $pickupWindowOpen => 'Ready for Release',
        $preparationComplete && $pickupWindowUpcoming => 'Scheduled for Pickup',
        $pickupWindowPassed => 'Pickup Window Expired',
        ! $hasPickupSchedule && $custody->status === 'PREPARING_RELEASE' => 'For Pickup Scheduling',
        $hasPickupSchedule && ! $preparationComplete => 'For Item Preparation',
        $custody->status === 'PREPARING_RELEASE' => 'Preparing for Release',
        default => null,
    };

    [$borrowerStateTitle, $borrowerStateCopy] = match (true) {
        $custody->status === 'CLOSED' && $transactionFullyComplete => [
            'Borrowing completed',
            'All issued items have been returned and reconciled. No further borrower action is required for this borrowing.',
        ],
        $custody->status === 'CLOSED' => [
            'Borrower cleared',
            'Your returned items have been accepted and your borrowing obligation is now cleared. No further action is required from you. Any remaining laundry processing and internal documentation will be handled by SPMU.',
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
        $preparationComplete && $pickupWindowOpen => [
            'Ready for physical release',
            'Your pickup window is open and SPMU has confirmed item preparation. Proceed to SPMU for the physical handover and Borrower Slip.',
        ],
        $preparationComplete && $pickupWindowUpcoming => [
            'Pickup scheduled',
            'SPMU has confirmed item preparation. Physical release becomes available only when your scheduled pickup window starts.',
        ],
        $pickupWindowPassed => [
            'Pickup window ended',
            'The scheduled claim window has ended without physical release. Coordinate with SPMU for a new pickup schedule.',
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
                    <h2>Early Return Coordination</h2>
                </div>
                <span class="status-badge status-info">Active Notice</span>
            </div>

            <dl class="detail-list compact-detail-list">
                <dt>Handover Schedule</dt>
                <dd>{{ optional($activeEarlyReturn->proposed_return_at)->format('d F Y, g:i A') ?: '—' }}</dd>

                <dt>Coordination Note</dt>
                <dd>{{ $activeEarlyReturn->reason ?: 'No coordination note provided.' }}</dd>
            </dl>

            <div class="callout info top-gap">
                <strong>Coordination only</strong>
                <p>
                    This notice only tells SPMU when the borrower plans to hand items back.
                    Actual returned quantities and conditions are recorded by the Action Officer during physical Return &amp; Inspection.
                    Inventory and custody quantities do not change from this notice.
                </p>
            </div>
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
                    Propose an earlier physical handover schedule to SPMU. This is coordination only; it does not record item quantities, return items, or change inventory.
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
                                <th>Currently On Custody</th>
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
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="meta">
                    These quantities are read-only context. Do not enter planned return quantities here;
                    the Action Officer records the actual quantities and conditions during physical Return &amp; Inspection.
                </p>

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
                                                step="1"
                                                min="0"
                                                inputmode="numeric"
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
                        <h2>Verify release documents</h2>
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

                        $documentDisplayStatus = match (true) {
                            $document->document_type === 'GATE_PASS'
                                && in_array($custody->gatePass?->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
                                    => 'FINAL SPMU GATE PASS',
                            $document->document_type === 'GATE_PASS'
                                    => 'SPMU PREVIEW — NOT FOR BORROWER PRINTING',
                            $isReleaseOperationalDocument
                                    => 'BORROWER COPY / REFERENCE',
                            default => str($document->status)->replace('_', ' ')->upper(),
                        };
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
                    The Borrower Slip and applicable Laundry Form may be prepared before pickup. For off-campus borrowing, the borrower does not print a Gate Pass in advance: the Action Officer verifies it with the registered E-signature during Physical Release, then SPMU prints the single FINAL Gate Pass for the borrower to present to the guard.
                </p>
            </article>
        </section>

        <section class="content-area">
            @if($pickupWindowUpcoming)
                <article class="card">
                    <div class="card-header">
                        <div>
                            <p class="eyebrow">4. Physical release</p>
                            <h2>Wait for the scheduled pickup window</h2>
                        </div>
                        <x-status-badge status="PENDING" label="Scheduled for Pickup" />
                    </div>

                    <div class="callout info">
                        <strong>Physical release is not available yet.</strong>
                        <p>
                            The handover may be recorded starting
                            <strong>{{ optional($pickupWindowStartsAt)->format('d F Y, g:i A') }}</strong>
                            and must be completed by
                            <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>.
                            Refresh this page when the pickup window starts.
                        </p>
                    </div>
                </article>
            @elseif($pickupWindowPassed)
                <article class="card">
                    <div class="card-header">
                        <div>
                            <p class="eyebrow">4. Physical release</p>
                            <h2>Pickup window has ended</h2>
                        </div>
                        <x-status-badge status="EXPIRED" label="Pickup Window Expired" />
                    </div>

                    <div class="callout warning">
                        <strong>Do not record a late physical release.</strong>
                        <p>
                            The claim window ended on
                            <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>.
                            Set a new pickup schedule before handing over the items.
                        </p>
                    </div>
                </article>
            @else
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
                    Release is recorded only when the borrower is physically present and the approved items are actually handed over.
                    The confirmed prepared quantities become the issued quantities automatically; do not enter them again.
                    For off-campus borrowing, confirming this release also captures the Action Officer's registered E-signature and finalizes the Gate Pass for SPMU printing.
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
                    I confirm that the borrower physically received the approved items and that all applicable physical handover acknowledgements were completed.
                </label>

                @error('signature')
                    <p class="form-error">{{ $message }}</p>
                @enderror

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
            @endif
        </section>
    @endif
@endif

@endif {{-- showReleaseWorkflow --}}

@if($showReturnWorkflow)
<div id="return-workflow" class="workflow-focus-target">

@if($isSpmuOfficer && $custody->released_at)
    @php
        /*
         * Revised return workflow:
         * linen and non-linen are inspected together when the borrower
         * physically returns them. Laundry washing is a later internal stock
         * process and does not delay the SPMU return inspection.
         */
        $eligibleReturnLines = $custody->lines->filter(function ($line) {
            return max(
                0,
                (float) $line->actual_released_quantity
                    - (float) $line->returned_quantity
            ) > 0;
        });
        $linenLines = $custody->lines->filter(
            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
        );

        $linenOutstanding = $linenLines->sum(
            fn ($line) => max(0, (float) $line->actual_released_quantity - (float) $line->returned_quantity)
        );

        $linenOperationalStatus = match (true) {
            $linenLines->isEmpty() => 'Not applicable',
            $linenOutstanding > 0 => 'Awaiting borrower return inspection',
            ! $laundryJob => 'Returned / accounted',
            $laundryJob->status === 'FOR_LAUNDRY' =>
                'SPMU inspected linen — awaiting Laundry receipt signature',
            $laundryJob->status === 'TURNED_OVER_TO_LAUNDRY' =>
                'Turned over to Laundry — borrower no longer waits for washing',
            $laundryJob->status === 'LAUNDRY_COMPLETED' =>
                'Laundry completed / available in Laundry Area',
            default => 'Laundry turnover / internal processing',
        };

        [$linenNextTitle, $linenNextCopy, $linenNextTone] = match (true) {
            $linenLines->isEmpty() => [
                'No Laundry requirement',
                'This custody does not contain laundry-required linen.',
                'info',
            ],
            $linenOutstanding > 0 => [
                'Return linen with the same printed Laundry Form',
                'Record the linen condition in this Return Inspection. Laundry Personnel will later wet-sign Received by on the same printed Laundry Form.',
                'warning',
            ],
            $laundryJob?->status === 'FOR_LAUNDRY' => [
                'Confirm physical Laundry turnover',
                'SPMU return inspection is complete. The borrower brings the linen and same printed Laundry Form to the Laundry Area. After Laundry Personnel wet-signs Received by, confirm the turnover in Laundry Operations.',
                'warning',
            ],
            $laundryJob?->status === 'TURNED_OVER_TO_LAUNDRY' => [
                'Borrower linen turnover completed',
                'Laundry Personnel have received the linen. Washing may be completed later inside the Laundry Area; the borrower has no further linen action.',
                'success',
            ],
            $laundryJob?->status === 'LAUNDRY_COMPLETED' => [
                'Linen available in Laundry Area',
                'Internal laundry processing is complete. Clean/serviceable linen is now Available for future borrowing in the Laundry Area.',
                'success',
            ],
            default => [
                'Review Laundry Operations',
                'Review the current Laundry case for the next internal action.',
                'info',
            ],
        };
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
                {{-- RETURN ITEMS — editable SPMU physical inspection     --}}
                {{-- ================================================= --}}
                @if($eligibleReturnLines->isNotEmpty())
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
                                <h2>Account returned quantities</h2>
                                <p class="meta">
                                    Use this single table for every item that still needs physical return inspection.
                                    Account each selected item's complete outstanding quantity once.
                                    Linen leaves this table after inspection and continues in Laundry Operations.
                                </p>
                            </div>

                            <span class="status-badge status-info">
                                {{ $eligibleReturnLines->count() }} item type(s) outstanding
                            </span>
                        </div>

                        @if($eligibleReturnLines->contains(fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required))
                            <div class="callout info">
                                <strong>Linen note</strong>
                                <p>Use <strong>Inspection Remarks</strong> for observations such as stained or soiled linen (for example, “2 table cloths stained”). Use the quantity classifications above for damaged, destroyed, missing, lost, or stolen property.</p>
                            </div>
                        @endif

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
                                                    {{ (bool) $line->requestItem?->inventoryItem?->laundry_required ? 'Linen' : 'Non-linen' }}
                                                    · {{ $line->requestItem->unit_snapshot }}
                                                    · Outstanding: {{ $outstanding + 0 }}
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
                                                        step="1"
                                                        min="0"
                                                        max="{{ $outstanding }}"
                                                        inputmode="numeric"
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
                                    placeholder="Optional inspection note (e.g., 2 table cloths stained, minor marks observed)"
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

                @if($eligibleReturnLines->isEmpty())
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
                            {{ optional($custody->released_at)->format('d M Y, g:i A') ?: '—' }}
                        </dd>

                        <dt>Expected Return</dt>
                        <dd>{{ $returnDate?->format('d M Y') ?: '—' }}</dd>

                        <dt>{{ $custody->status === 'OVERDUE' ? 'Total Overdue' : 'Total On Custody' }}</dt>
                        <dd>{{ $outstandingTotal + 0 }}</dd>

                        @if($linenLines->isNotEmpty())
                            <dt>Linen</dt>
                            <dd>
                                {{ $linenOperationalStatus }}
                                @if($linenOutstanding > 0)
                                    · {{ $linenOutstanding + 0 }} outstanding
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

                        @if($laundryJob && in_array($laundryJob->status, ['FOR_LAUNDRY', 'TURNED_OVER_TO_LAUNDRY'], true))
                            <a
                                class="button primary small ui-pressable"
                                href="{{ route('laundry.show', $laundryJob) }}"
                            >
                                Open Laundry Operations
                            </a>
                        @endif

                        @if($laundryJob?->latestEvidence?->file)
                            <a
                                class="button secondary small ui-pressable"
                                href="{{ route('files.show', $laundryJob->latestEvidence->file, false) }}"
                                target="_blank"
                                rel="noopener"
                            >
                                View Archived Laundry Form
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
@if($custody->returns->isNotEmpty())
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
