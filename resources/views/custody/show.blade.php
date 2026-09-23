@extends('layouts.app', [
    'title' => $custody->custody_no,
    'topbarTitle' => session('active_workspace') === 'BORROWER'
        ? 'My Borrowings'
        : (auth()->user()?->access_classification?->value === 'SPMU_HEAD'
            ? 'Release & Return Oversight'
            : (($spmuMode ?? null) === 'return' ? 'Return' : 'Release')),
    'inlinePageNotices' => ($spmuMode ?? null) === 'return',
])

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
    $useReleaseProcessLayout = $isSpmuOfficer
        && $showReleaseWorkflow
        && $custody->status === 'PREPARING_RELEASE'
        && ! $custody->released_at;

    $useReturnProcessLayout = $isSpmuOfficer
        && $spmuMode === 'return'
        && (bool) $custody->released_at;

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

    $outstandingLines = $custody->lines->filter(
        fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
    );
    $hasOutstandingLinen = $outstandingLines->contains(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );
    $hasOutstandingNonLinen = $outstandingLines->contains(
        fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $preparationComplete = (bool) $custody->prepared_at;
    $preparationIssueRecords = collect($preparationIssues ?? []);
    $hasOpenPreparationIssue = $preparationIssueRecords->where('is_resolved', false)->isNotEmpty();
    $hasPreparationExceptionPendingRelease = $custody->status === 'PREPARING_RELEASE'
        && ! $custody->released_at
        && ! $preparationComplete
        && $preparationIssueRecords->isNotEmpty();
    $pickupWindowStartsAt = $custody->scheduled_release_at;
    $pickupWindowEndsAt = $custody->pickup_expires_at;
    $pickupWindowPassed = (bool) $pickupWindowEndsAt
        && now()->gt($pickupWindowEndsAt);
    $pickupHeldForPreparationIssue = ! $custody->released_at
        && $hasPreparationExceptionPendingRelease
        && $pickupWindowPassed;
    $pickupMissed = ! $pickupHeldForPreparationIssue
        && ! $custody->released_at
        && (bool) $custody->pickup_scheduled_at
        && ((bool) $custody->pickup_expired_at || $pickupWindowPassed);
    $hasPickupSchedule = (bool) $pickupWindowStartsAt
        && (bool) $pickupWindowEndsAt
        && ! $pickupMissed;

    $pickupWindowUpcoming = $hasPickupSchedule
        && now()->lt($pickupWindowStartsAt);

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
     * Keep the summary table honest after return: release condition and return
     * finding are different facts. The old generic "Condition" column used
     * release_condition, which could show SERVICEABLE even when the recorded
     * return finding was DAMAGED.
     */
    $returnFindingsByCustodyLine = ($custody->returns ?? collect())
        ->flatMap(fn ($return) => $return->lines ?? collect())
        ->groupBy('custody_line_id')
        ->map(function ($returnLines): string {
            return $returnLines
                ->groupBy(fn ($line) => strtoupper((string) ($line->condition_code ?: 'FINE')))
                ->map(function ($lines, $condition): string {
                    $label = match ($condition) {
                        'FINE' => 'Good',
                        'DAMAGED' => 'Damaged',
                        'MISSING', 'LOST' => 'Missing',
                        'DESTROYED' => 'Destroyed',
                        'STOLEN' => 'Stolen',
                        default => str($condition)->replace('_', ' ')->lower()->title()->toString(),
                    };

                    return $label.': '.((float) $lines->sum('quantity_received') + 0);
                })
                ->values()
                ->implode('; ');
        });

    $workflowStatus = $custody->workflowStatus();
    $operationalStatusKey = $workflowStatus['key'];
    $obligationSummary = $custody->openObligationSummary();
    $accountabilityIndicator = in_array((string) $custody->status, ['OBLIGATION_OPEN', 'INCIDENT_OPEN'], true)
        ? ($obligationSummary ?? ['label' => 'Accountability Pending'])
        : null;
    $operationalLabel = $workflowStatus['label'];
    $transactionFullyComplete = $operationalStatusKey === 'COMPLETED';
    $transactionCancelled = $operationalStatusKey === 'CANCELLED';

    [$borrowerStateTitle, $borrowerStateCopy, $borrowerStateTone] = match (true) {
        $transactionCancelled => [
            'Borrowing cancelled',
            'This borrowing was cancelled before completion. No pickup or release action is required.',
            'neutral',
        ],
        $operationalStatusKey === 'COMPLETED' => [
            'Borrowing completed',
            'All issued items were returned and reconciled. No further action is required.',
            'success',
        ],
        $operationalStatusKey === 'BORROWER_CLEARED' => [
            'Borrower cleared',
            'Your return has been accepted and your obligation is cleared. Any remaining internal processing is handled by SPMU.',
            'success',
        ],
        $accountabilityIndicator !== null => [
            'Accountability processing',
            ($obligationSummary['copy'] ?? null) ?: $accountabilityIndicator['label'].' requires resolution. See My Obligations for the current action.',
            'warning',
        ],
        $custody->status === 'OBLIGATION_OPEN' => [
            $obligationSummary ? 'Return completed — '.$obligationSummary['title'] : 'Return completed with an open obligation',
            $obligationSummary
                ? $obligationSummary['copy'].' See My Obligations for the required action.'
                : 'An accountability or billing obligation still needs resolution. See My Obligations for the required action.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'Return processing is in progress',
            'A return branch has been recorded, but both return channels still show outstanding property. Return every outstanding non-linen item to SPMU and every outstanding linen item, with the same printed Laundry Form, to the Laundry Area.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingLinen => [
            'Linen return still required',
            'Return every outstanding linen item and the same printed Laundry Form to the Laundry Area. Laundry RECEIVED BY is the physical return date used for timeliness.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' && $hasOutstandingNonLinen => [
            'Non-linen return still required',
            'Return every outstanding non-linen item to SPMU in one complete AO return inspection.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' => [
            'Return processing is in progress',
            'All physical quantities have been recorded. SPMU is completing reconciliation and any linked accountability processing.',
            'info',
        ],
        $custody->status === 'OVERDUE' && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'This borrowing is overdue',
            'Return every outstanding non-linen item to SPMU and every outstanding linen item, with the same printed Laundry Form, to the Laundry Area immediately.',
            'danger',
        ],
        $custody->status === 'OVERDUE' && $hasOutstandingLinen => [
            'This borrowing is overdue',
            'Return every outstanding linen item and the same printed Laundry Form to the Laundry Area immediately. Laundry RECEIVED BY is the physical return date used for timeliness.',
            'danger',
        ],
        $custody->status === 'OVERDUE' => [
            'This borrowing is overdue',
            'Return every outstanding non-linen item to SPMU immediately for official return inspection.',
            'danger',
        ],
        (bool) $custody->released_at && $hasOutstandingLinen && $hasOutstandingNonLinen => [
            'Items are currently on your custody',
            'On or before the expected return date, return all non-linen items to SPMU and all linen items, with the same printed Laundry Form, to the Laundry Area.',
            'info',
        ],
        (bool) $custody->released_at && $hasOutstandingLinen => [
            'Linen is currently on your custody',
            'On or before the expected return date, return the linen and the same printed Laundry Form to the Laundry Area.',
            'info',
        ],
        (bool) $custody->released_at => [
            'Items are currently on your custody',
            'Return all outstanding non-linen items to SPMU on or before the expected return date.',
            'info',
        ],
        $pickupMissed && $pickupRescheduleRequested => [
            'Pickup reschedule requested',
            'SPMU will set the next valid pickup schedule for this same approved request.',
            'warning',
        ],
        $pickupMissed && ! $pickupRescheduleAvailable => [
            'Pickup missed',
            'Your pickup schedule has passed and no valid pickup window remains before the expected return date. Cancel the request or coordinate an approved date revision with SPMU.',
            'warning',
        ],
        $pickupMissed => [
            'Pickup missed',
            'Your pickup schedule has passed and the items were not claimed. Choose what you want to do with this approved request.',
            'warning',
        ],
        $preparationComplete && $pickupWindowOpen => [
            'Ready for physical release',
            'Your pickup window is open. Proceed to SPMU for the handover and Borrower Slip.',
            'info',
        ],
        $preparationComplete && $pickupWindowUpcoming => [
            'Pickup scheduled',
            'Physical release becomes available when your pickup window starts.',
            'info',
        ],
        $pickupWindowPassed => [
            'Pickup window ended',
            'Coordinate with SPMU for a new pickup schedule.',
            'warning',
        ],
        $hasPickupSchedule => [
            'Pickup scheduled - preparation in progress',
            'SPMU is preparing your approved items. No action is required from you.',
            'info',
        ],
        default => [
            'Waiting for pickup scheduling',
            'SPMU will schedule your pickup after approval.',
            'info',
        ],
    };
@endphp

@if($useReleaseProcessLayout)
    @include('custody.partials.release-process-styles')
    <div class="release-flow-page" data-release-process>
    @include('custody.partials.release-heading')
@elseif(! $useReturnProcessLayout)
<section class="page-heading">
    <div>
        @if($isBorrower)
            <a class="borrower-custody-back" href="{{ route('custody.index') }}">
                <x-icon name="arrow-right" size="16" />
                My Borrowings
            </a>
        @else
            <p class="eyebrow">
                {{ $isSpmuOfficer && $spmuMode === 'release'
                    ? 'Release transaction'
                    : ($isSpmuOfficer && $spmuMode === 'return' ? 'Return transaction' : 'Pickup / custody transaction') }}
            </p>
        @endif
        <h1>{{ $custody->custody_no }}</h1>
        <p>
            Request {{ $custody->request?->request_no }}
            @if(!$isBorrower && $custody->borrower)
                · {{ $custody->borrower->full_name }}
            @endif
        </p>
    </div>
    <x-status-badge
        :status="$operationalStatusKey"
        :label="$isBorrower && $pickupMissed
            ? ($pickupRescheduleRequested ? 'Reschedule Requested' : 'Pickup Missed')
            : $operationalLabel"
    />
</section>
@endif

@if($isBorrower)
    @php
        $borrowerItemCount = $custody->lines->count();
        $borrowerCustodyLabel = $custody->status === 'OVERDUE' ? 'Overdue' : 'On Custody';
        $borrowerReleased = (bool) $custody->released_at;

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
        $borrowerGatePassStatus = $custody->gatePass?->workflowStatus();
        $borrowerGatePassLabel = match (true) {
            ! $hasOffCampusItem => null,
            $borrowerGatePassStatus !== null => $borrowerGatePassStatus['label'],
            default => 'Applicable',
        };

        $borrowerCanCancelRequest = $custody->request
            && ! in_array(
                $custody->request->status,
                [
                    \App\Enums\RequestStatus::Draft,
                    \App\Enums\RequestStatus::Cancelled,
                    \App\Enums\RequestStatus::Rejected,
                    \App\Enums\RequestStatus::Expired,
                ],
                true
            )
            && ! $custody->released_at;

        /*
         * The one date that matters right now, appended to the status line so
         * the borrower does not have to read the summary grid to find it.
         */
        [$borrowerStateFactLabel, $borrowerStateFactValue] = match (true) {
            $transactionCancelled && (bool) $custody->closed_at
                => ['Cancelled', $custody->closed_at->format('d M Y')],
            (bool) $custody->closed_at
                => ['Closed', $custody->closed_at->format('d M Y')],
            $custody->status === 'OVERDUE' && $returnDate
                => ['Was due', $returnDate->format('d M Y')],
            $borrowerReleased && $returnDate
                => [$returnDateAdjusted ? 'Effective return' : 'Expected return', $returnDate->format('d M Y')],
            $pickupWindowOpen && $pickupWindowEndsAt
                => ['Pickup window closes', $pickupWindowEndsAt->format('d M Y, g:i A')],
            $pickupWindowUpcoming && $pickupWindowStartsAt
                => ['Pickup opens', $pickupWindowStartsAt->format('d M Y, g:i A')],
            default => [null, null],
        };

        /*
         * Facts are assembled rather than hard-coded into cells so the grid
         * never carries a row that says nothing - "Completed: not completed
         * yet" while the status already reads "on your custody", for example.
         */
        $borrowerFacts = [];

        $borrowerFacts[] = [
            'Scheduled Use',
            $scheduleDate?->format('d M Y') ?: 'Not available',
            null,
        ];

        $borrowerReturnNote = null;
        if ($returnDateAdjusted) {
            $borrowerReturnNote = 'Adjusted from '.$originalReturnDate?->format('d M Y');

            if ($custody->due_adjustment_reason) {
                $borrowerReturnNote .= ' · '.$custody->due_adjustment_reason;
            }
        }

        $borrowerFacts[] = [
            $returnDateAdjusted ? 'Effective Return' : 'Expected Return',
            ($returnDateAdjusted ? $returnDate : $originalReturnDate)?->format('d M Y') ?: 'Not available',
            $borrowerReturnNote,
        ];

        $borrowerFacts[] = $borrowerReleased
            ? ['Released', $custody->released_at->format('d M Y, g:i A'), null]
            : ['Pickup', optional($custody->scheduled_release_at)->format('d M Y, g:i A') ?: 'Not scheduled', null];

        if ($borrowerReleased) {
            $borrowerFacts[] = [
                $borrowerCustodyLabel,
                $outstandingTotal + 0,
                $outstandingTotal > 0 ? 'Still to be returned' : 'Nothing outstanding',
            ];
        }

        $borrowerFacts[] = [
            'Use Location',
            $hasOffCampusItem ? 'Includes off-campus use' : 'On-campus only',
            null,
        ];

        $borrowerFacts[] = [
            'Item Types',
            $borrowerItemCount.' '.($borrowerItemCount === 1 ? 'item' : 'items'),
            null,
        ];

        if ($custody->closed_at) {
            $borrowerFacts[] = [
                $transactionCancelled ? 'Cancelled' : 'Closed',
                $custody->closed_at->format('d M Y, g:i A'),
                null,
            ];
        }
    @endphp

    @include('custody.partials.borrower-custody-styles')

    <section class="content-area">
        <div class="borrower-custody-stack">

            {{-- Current state --}}
            <div class="borrower-custody-status is-{{ $borrowerStateTone }}">
                <span class="borrower-custody-status-icon" aria-hidden="true">
                    <x-icon name="information" size="24" />
                </span>

                <div class="borrower-custody-status-copy">
                    <h2>{{ $borrowerStateTitle }}</h2>
                    <p>{{ $borrowerStateCopy }}</p>
                </div>

                @if($borrowerStateFactLabel)
                    <div class="borrower-custody-status-fact">
                        <small>{{ $borrowerStateFactLabel }}</small>
                        <strong>
                            <x-icon name="calendar" size="17" />
                            {{ $borrowerStateFactValue }}
                        </strong>
                    </div>
                @endif

                <div class="borrower-custody-status-actions">
                    @if($pickupMissed && ! $pickupRescheduleRequested && $pickupRescheduleAvailable)
                        <form method="post" action="{{ route('custody.request-pickup-reschedule', $custody) }}">
                            @csrf
                            <button class="button primary small ui-pressable borrower-custody-status-action" type="submit">
                                Request Reschedule
                            </button>
                        </form>
                    @endif

                    @if($pickupMissed && $borrowerCanCancelRequest)
                        @include('requests.partials.borrower-cancel-control', [
                            'borrowingRequest' => $custody->request,
                            'cancelTriggerClass' => 'button secondary small ui-pressable borrower-custody-status-action borrower-cancel-trigger is-danger',
                            'cancelTriggerLabel' => 'Cancel Request',
                            'cancelDialogTitle' => 'Cancel this approved request?',
                            'cancelDialogCopy' => 'The reserved items will return to Available inventory and this approved request will close. You can no longer continue this pickup after cancellation.',
                            'cancelReasonPlaceholder' => 'Briefly explain why you no longer need this approved request...',
                        ])
                    @elseif($accountabilityIndicator)
                        <a
                            class="button secondary small ui-pressable borrower-custody-status-action"
                            href="{{ route('accountability.index') }}"
                        >
                            View Obligation
                            <x-icon name="arrow-right" size="15" />
                        </a>
                    @elseif(! $pickupMissed)
                        <a
                            class="button secondary small ui-pressable borrower-custody-status-action"
                            href="{{ route('requests.show', $custody->request) }}"
                        >
                            View Request
                            <x-icon name="arrow-right" size="15" />
                        </a>
                    @endif
                </div>
            </div>

            {{-- Transaction facts --}}
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Borrowing summary</p>
                        <h2>{{ $version?->purpose_event ?: 'Borrowing details' }}</h2>
                    </div>
                </div>

                <div class="borrower-summary-grid">
                    @foreach($borrowerFacts as [$factLabel, $factValue, $factNote])
                        <div class="borrower-summary-fact">
                            <small>{{ $factLabel }}</small>
                            <strong>{{ $factValue }}</strong>
                            @if($factNote)
                                <span>{{ $factNote }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>

            {{-- Items --}}
            <article class="card">
                <div class="card-header">
                    <div>
                        <p class="eyebrow">Your items</p>
                        <h2>{{ $borrowerReleased ? 'Issued and returned quantities' : ($transactionCancelled ? 'Approved items — cancelled' : 'Approved items for pickup') }}</h2>
                    </div>
                    <span class="borrower-section-note">
                        {{ $borrowerItemCount }} {{ $borrowerItemCount === 1 ? 'item' : 'items' }}
                    </span>
                </div>

                <div class="table-wrap">
                    <table class="borrower-items-table">
                        <thead>
                            @if($borrowerReleased)
                                <tr>
                                    <th>Item</th>
                                    <th>Issued</th>
                                    <th>Returned</th>
                                    <th>{{ $borrowerCustodyLabel }}</th>
                                </tr>
                            @else
                                <tr>
                                    <th>Item</th>
                                    <th>Approved</th>
                                    <th>Status</th>
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
                                    @if($borrowerReleased)
                                        <td>{{ $line->actual_released_quantity + 0 }}</td>
                                        <td>{{ $line->returned_quantity + 0 }}</td>
                                        <td class="is-quantity"><strong>{{ $lineOnCustody + 0 }}</strong></td>
                                    @else
                                        <td>{{ $line->approved_quantity + 0 }}</td>
                                        <td class="is-muted">{{ $transactionCancelled ? 'Not issued — cancelled' : 'Not issued yet' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>

            {{-- Processing that continues outside the borrower's hands --}}
            @if($hasLaundryItem || $hasOffCampusItem)
                <article class="card">
                    <div class="card-header">
                        <span class="borrower-card-icon" aria-hidden="true">
                            <x-icon name="requests" size="21" />
                        </span>
                        <div>
                            <p class="eyebrow">Additional processing</p>
                            <h2>Applicable physical records</h2>
                        </div>
                    </div>

                    @if($hasLaundryItem)
                        <div class="borrower-processing-row">
                            <div class="borrower-processing-copy">
                                <strong>Linen processing</strong>
                                <small>
                                    {{ $borrowerLaundryComplete
                                        ? 'Complete. No further action is required.'
                                        : 'Handled internally by SPMU after the physical return.' }}
                                </small>
                            </div>

                            <x-status-badge
                                :status="$borrowerLaundryComplete ? 'COMPLETED' : 'RETURN_PROCESSING'"
                                :label="$borrowerLaundryLabel"
                            />

                            @if($laundryJob?->latestEvidence?->file)
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('files.preview', $laundryJob->latestEvidence->file, false) }}"
                                >
                                    Preview
                                </a>
                            @endif
                        </div>
                    @endif

                    @if($hasOffCampusItem)
                        <div class="borrower-processing-row">
                            <div class="borrower-processing-copy">
                                <strong>Gate Pass</strong>
                                <small>
                                    @if(($borrowerGatePassStatus['key'] ?? null) === 'VOID')
                                        Voided because the borrowing was cancelled.
                                    @elseif($borrowerGatePassComplete)
                                        Off-campus release recorded{{ $custody->gatePass?->guard_signed_at ? ' on '.$custody->gatePass->guard_signed_at->format('d M Y, g:i A') : '' }}.
                                    @else
                                        Required for the approved off-campus use. Handled during physical release.
                                    @endif
                                </small>
                            </div>

                            <x-status-badge
                                :status="$borrowerGatePassStatus['key'] ?? ($borrowerGatePassComplete ? 'COMPLETED' : 'PREPARING_RELEASE')"
                                :label="$borrowerGatePassLabel"
                            />

                            @if($custody->gatePass?->accomplished_file_id)
                                <a
                                    class="button secondary small ui-pressable"
                                    href="{{ route('files.preview', $custody->gatePass->accomplished_file_id, false) }}"
                                >
                                    Preview
                                </a>
                            @endif
                        </div>
                    @endif
                </article>
            @endif

        </div>
    </section>
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
</style>
@if($showReleaseWorkflow)
@if($useReleaseProcessLayout)
    @include('custody.partials.release-process')
@else
    <x-request-progress-tracker :request="$custody->request" :show-current-status="false" />

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
                <p class="eyebrow">Operational context</p>
                <h2>{{ $custody->released_at ? 'Release & return requirements' : 'Before physical release' }}</h2>
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

<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Property record</p>
                <h2>{{ $custody->released_at ? 'Issued and returned quantities' : 'Approved and issued quantities' }}</h2>
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
                        <th>Release Condition</th>
                        <th>Return Condition</th>
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
                            <td>{{ $line->release_condition ? str($line->release_condition)->replace('_', ' ')->lower()->title() : '—' }}</td>
                            <td>{{ $returnFindingsByCustodyLine->get($line->id) ?: ((float) $line->returned_quantity > 0 ? 'Recorded' : '—') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>

@if($isSpmuHead && collect($preparationIssues ?? [])->isNotEmpty() && ! $custody->released_at)
<section class="content-area" id="preparation-issues">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Item preparation</p>
                <h2>Inventory Discrepancy Review</h2>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Reported Discrepancy</th>
                        <th>Physical Observation</th>
                        <th>Reported By</th>
                        <th>Status / Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preparationIssues as $issue)
                        <tr>
                            <td>
                                <strong>{{ $issue['item_name'] }}</strong>
                                <small>{{ $issue['approved_quantity'] + 0 }} {{ $issue['unit'] }} approved</small>
                            </td>
                            <td>
                                <strong>{{ $issue['issue_label'] }}</strong>
                                @if(!empty($issue['condition_observed']))
                                    <small>{{ $issue['condition_observed'] }}</small>
                                @endif
                                @if(!empty($issue['details']))
                                    <small>{{ $issue['details'] }}</small>
                                @endif
                            </td>
                            <td>
                                @if($issue['observed_usable_quantity'] !== null)
                                    {{ $issue['observed_usable_quantity'] + 0 }} of {{ $issue['approved_quantity'] + 0 }} {{ $issue['unit'] }} physically ready
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                {{ $issue['reported_by'] }}
                                <small>{{ $issue['reported_at']?->format('d M Y, g:i A') }}</small>
                            </td>
                            <td>
                                @if($issue['is_resolved'])
                                    @if($issue['resolution_outcome'] === 'UNABLE_TO_FULFILL_APPROVED_REQUEST')
                                        <strong>Unable to Fulfill — Request Cancelled</strong>
                                        @if(!empty($issue['resolution_notes']))
                                            <small>{{ $issue['resolution_notes'] }}</small>
                                        @endif
                                        <small>No borrower missed-pickup record was created.</small>
                                    @else
                                        <strong>Inventory Reviewed — AO Recheck Required</strong>
                                        @if(!empty($issue['resolution_notes']))
                                            <small>{{ $issue['resolution_notes'] }}</small>
                                        @endif

                                        @if($pickupWindowPassed && ! $preparationComplete && $custody->status === 'PREPARING_RELEASE')
                                            <form
                                                method="post"
                                                action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                                class="form-grid"
                                            >
                                                @csrf
                                                <input type="hidden" name="resolution_type" value="UNABLE_TO_FULFILL_APPROVED_REQUEST">
                                                <label>
                                                    Reason
                                                    <textarea
                                                        name="resolution_notes"
                                                        maxlength="1000"
                                                        required
                                                        placeholder="State why the complete approved quantity can no longer be released under the approved pickup schedule."
                                                    ></textarea>
                                                </label>
                                                <label class="checkbox-line">
                                                    <input type="checkbox" name="confirm_unable_to_fulfill" value="1" required>
                                                    <span>I confirm that the approved release can no longer proceed and partial release is not allowed.</span>
                                                </label>
                                                <button class="button danger ui-pressable" type="submit">
                                                    Unable to Fulfill Approved Request
                                                </button>
                                            </form>
                                        @endif
                                    @endif
                                    @if($issue['resolved_at'])
                                        <small>{{ $issue['resolved_at']->format('d M Y, g:i A') }}</small>
                                    @endif
                                @else
                                    @if($issue['inventory_item_id'])
                                        <a
                                            class="button secondary small ui-pressable"
                                            href="{{ route('inventory.show', $issue['inventory_item_id']) }}"
                                        >
                                            Review Inventory
                                        </a>
                                    @endif

                                    @if($pickupWindowPassed)
                                        <form
                                            method="post"
                                            action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                            class="form-grid"
                                        >
                                            @csrf
                                            <input type="hidden" name="resolution_type" value="UNABLE_TO_FULFILL_APPROVED_REQUEST">
                                            <label>
                                                Reason
                                                <textarea
                                                    name="resolution_notes"
                                                    maxlength="1000"
                                                    required
                                                    placeholder="State why SPMU cannot provide the complete approved quantity."
                                                ></textarea>
                                            </label>
                                            <label class="checkbox-line">
                                                <input type="checkbox" name="confirm_unable_to_fulfill" value="1" required>
                                                <span>I confirm that the complete approved quantity cannot be provided and partial release is not allowed.</span>
                                            </label>
                                            <button class="button danger ui-pressable" type="submit">
                                                Unable to Fulfill Approved Request
                                            </button>
                                        </form>
                                    @else
                                        <form
                                            method="post"
                                            action="{{ route('custody.resolve-preparation-issue', [$custody, $issue['event']]) }}"
                                            class="form-grid"
                                            data-preparation-resolution-form
                                        >
                                            @csrf
                                            <label>
                                                Resolution
                                                <select name="resolution_type" required data-preparation-resolution-type>
                                                    <option value="INVENTORY_REVIEW_COMPLETE">Resolved — AO Recheck Required</option>
                                                    <option value="UNABLE_TO_FULFILL_APPROVED_REQUEST">Unable to Fulfill Approved Request</option>
                                                </select>
                                            </label>

                                            <label data-preparation-resolution-notes>
                                                <span data-preparation-resolution-notes-label>Remarks (Optional)</span>
                                                <textarea
                                                    name="resolution_notes"
                                                    maxlength="1000"
                                                    placeholder="Add a short note only if needed."
                                                    data-preparation-resolution-notes-input
                                                ></textarea>
                                            </label>

                                            <label class="checkbox-line" data-preparation-unfulfillable-confirmation hidden>
                                                <input type="checkbox" name="confirm_unable_to_fulfill" value="1" disabled>
                                                <span>I confirm that the complete approved quantity cannot be provided and partial release is not allowed.</span>
                                            </label>

                                            <button class="button primary ui-pressable" type="submit" data-preparation-resolution-submit>
                                                Confirm Inventory Reviewed
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
<script>
(() => {
    document.querySelectorAll('[data-preparation-resolution-form]').forEach((form) => {
        const type = form.querySelector('[data-preparation-resolution-type]');
        const notes = form.querySelector('[data-preparation-resolution-notes]');
        const notesLabel = form.querySelector('[data-preparation-resolution-notes-label]');
        const notesInput = form.querySelector('[data-preparation-resolution-notes-input]');
        const confirmation = form.querySelector('[data-preparation-unfulfillable-confirmation]');
        const confirmationInput = confirmation?.querySelector('input');
        const submit = form.querySelector('[data-preparation-resolution-submit]');

        const sync = () => {
            const unable = type?.value === 'UNABLE_TO_FULFILL_APPROVED_REQUEST';
            if (notesLabel) notesLabel.textContent = unable ? 'Reason' : 'Remarks (Optional)';
            if (notesInput) {
                notesInput.required = unable;
                notesInput.placeholder = unable
                    ? 'State why SPMU cannot provide the complete approved quantity.'
                    : 'Add a short note only if needed.';
            }
            if (confirmation) confirmation.hidden = !unable;
            if (confirmationInput) {
                confirmationInput.disabled = !unable;
                confirmationInput.required = unable;
            }
            if (submit) {
                submit.textContent = unable ? 'Unable to Fulfill Approved Request' : 'Confirm Inventory Reviewed';
                submit.classList.toggle('danger', unable);
                submit.classList.toggle('primary', !unable);
            }
        };

        type?.addEventListener('change', sync);
        sync();
    });
})();
</script>
@endif

@endif {{-- useReleaseProcessLayout --}}
@endif {{-- showReleaseWorkflow --}}

@if($showReturnWorkflow)
<div id="return-workflow" class="workflow-focus-target return-flow-page">

@if($isSpmuOfficer && $custody->released_at)
    @include('custody.partials.return-workspace')
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
@if($useReleaseProcessLayout)
    </div>
@endif
@endsection
