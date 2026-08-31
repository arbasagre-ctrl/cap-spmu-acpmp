@extends('layouts.app', ['title' => $custody->custody_no, 'inlinePageNotices' => ($spmuMode ?? null) === 'return'])

@section('content')
@php
    $workspace = strtoupper((string) session('active_workspace'));
    $user = auth()->user();
    $isBorrower = $workspace === 'BORROWER' && auth()->id() === $custody->borrower_user_id;
    $isSpmu = $workspace === 'SPMU';
    $isSpmuOfficer = $isSpmu && $user?->access_classification === \App\Enums\AccessClassification::SpmuOfficer;
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

    [$borrowerStateTitle, $borrowerStateCopy, $borrowerStateTone] = match (true) {
        $custody->status === 'CLOSED' && $transactionFullyComplete => [
            'Borrowing completed',
            'All issued items were returned and reconciled. No further action is required.',
            'success',
        ],
        $custody->status === 'CLOSED' => [
            'Borrower cleared',
            'Your return has been accepted and your obligation is cleared. Any remaining laundry processing is handled by SPMU.',
            'success',
        ],
        $custody->status === 'OBLIGATION_OPEN' => [
            'Return completed with an open obligation',
            'An accountability or billing obligation still needs resolution. See My Obligations for the required action.',
            'warning',
        ],
        $custody->status === 'RETURN_PROCESSING' => [
            'Return processing is in progress',
            'SPMU is reconciling your returned items.',
            'info',
        ],
        $custody->status === 'OVERDUE' => [
            'This borrowing is overdue',
            'Coordinate the return with SPMU as soon as possible.',
            'danger',
        ],
        (bool) $custody->released_at => [
            'Items are currently on your custody',
            'Return the items to SPMU on or before the expected return date.',
            'info',
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
                <x-icon name="chevron-right" size="16" />
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
    <x-status-badge :status="$custody->status" :label="$operationalLabel" />
</section>
@endif

@if($activeEarlyReturn && ! $isBorrower)
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

        $borrowerGatePassLabel = match (true) {
            ! $hasOffCampusItem => null,
            $borrowerGatePassComplete => 'Completed',
            $custody->gatePass !== null => 'Pending',
            default => 'Applicable',
        };

        /*
         * The one date that matters right now, appended to the status line so
         * the borrower does not have to read the summary grid to find it.
         */
        [$borrowerStateFactLabel, $borrowerStateFactValue] = match (true) {
            (bool) $custody->closed_at
                => ['Closed', $custody->closed_at->format('d M Y')],
            $custody->status === 'OVERDUE' && $returnDate
                => ['Was due', $returnDate->format('d M Y')],
            $borrowerReleased && $returnDate
                => ['Expected return', $returnDate->format('d M Y')],
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

        $borrowerFacts[] = [
            $returnDateAdjusted ? 'Effective Return' : 'Expected Return',
            ($returnDateAdjusted ? $returnDate : $originalReturnDate)?->format('d M Y') ?: 'Not available',
            $returnDateAdjusted ? 'Original: '.$originalReturnDate?->format('d M Y') : null,
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
                'Closed',
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

                <a
                    class="button secondary small ui-pressable borrower-custody-status-action"
                    href="{{ route('requests.show', $custody->request) }}"
                >
                    View request
                    <x-icon name="chevron-right" size="15" />
                </a>
            </div>

            {{-- Coordination already sent: one line, not a card. --}}
            @if($activeEarlyReturn)
                <div class="borrower-early-return-notice">
                    <div>
                        <strong>Early return requested for {{ optional($activeEarlyReturn->proposed_return_at)->format('d M Y, g:i A') ?: 'a proposed schedule' }}</strong>
                        <small>
                            SPMU has your proposed handover schedule. Quantities and conditions are recorded during Return &amp; Inspection.
                        </small>
                    </div>
                    <span class="status-badge status-info">Awaiting SPMU</span>
                </div>
            @endif

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
                        <h2>{{ $borrowerReleased ? 'Issued and returned quantities' : 'Approved items for pickup' }}</h2>
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
                                        <td class="is-muted">Not issued yet</td>
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
                                        Off-campus release recorded{{ $custody->gatePass?->guard_signed_at ? ' on '.$custody->gatePass->guard_signed_at->format('d M Y, g:i A') : '' }}.
                                    @else
                                        Required for the approved off-campus use. Handled during physical release.
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
                </article>
            @endif

            {{--
                Optional coordination. Folded away by default so it never
                competes with the record itself, and opened automatically when
                a submission comes back with validation errors.
            --}}
            @if($canRequestEarlyReturn)
                <details
                    class="card borrower-early-return"
                    id="early-return-request"
                    @if($errors->any()) open @endif
                >
                    <summary class="borrower-early-return-summary">
                        <span class="borrower-card-icon" aria-hidden="true">
                            <x-icon name="custody" size="21" />
                        </span>

                        <span class="borrower-early-return-copy">
                            <strong>Request early return</strong>
                            <small>Returning the items sooner? Propose a handover schedule to SPMU.</small>
                        </span>

                        <span class="borrower-early-return-toggle">
                            <span class="is-open">Open form</span>
                            <span class="is-close">Close</span>
                            <x-icon name="chevron-down" size="16" />
                        </span>
                    </summary>

                    <form
                        method="post"
                        action="{{ route('custody.early-return', $custody) }}"
                        class="borrower-early-return-body"
                    >
                        @csrf

                        <label for="early-return-proposed-at">
                            Proposed handover date &amp; time
                            <input
                                id="early-return-proposed-at"
                                type="datetime-local"
                                name="proposed_return_at"
                                value="{{ old('proposed_return_at') }}"
                                min="{{ now()->addMinute()->format('Y-m-d\TH:i') }}"
                                max="{{ $custody->due_at->format('Y-m-d\TH:i') }}"
                                required
                            >
                            @error('proposed_return_at')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <div class="borrower-early-return-outstanding">
                            <table class="borrower-early-return-table" aria-label="Outstanding items currently on custody">
                                <thead>
                                    {{-- Wording is part of the early-return workflow contract:
                                         outstanding quantities are read-only context, never an
                                         input for a quantity to hand over. --}}
                                    <tr>
                                        <th scope="col">Outstanding Item</th>
                                        <th scope="col">Unit</th>
                                        <th scope="col">Currently On Custody</th>
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
                                            </td>
                                            <td>{{ $line->requestItem->unit_snapshot ?: '—' }}</td>
                                            <td class="is-quantity">{{ $outstanding + 0 }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="meta">
                            Quantities are shown for reference only. SPMU records the actual returned
                            quantities and conditions during Return &amp; Inspection.
                        </p>

                        <label for="early-return-reason">
                            Coordination note (optional)
                            <textarea id="early-return-reason" name="reason" maxlength="1000">{{ old('reason') }}</textarea>
                            @error('reason')
                                <small class="field-error">{{ $message }}</small>
                            @enderror
                        </label>

                        <div class="borrower-early-return-actions">
                            <button class="button primary ui-pressable">Send request</button>
                            <span class="meta">Coordination only. Inventory and custody quantities do not change.</span>
                        </div>
                    </form>
                </details>
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
