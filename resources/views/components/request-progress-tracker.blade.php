@props(['request', 'showCurrentStatus' => true, 'compact' => false, 'releaseView' => false])

@php
    $status = $request->status instanceof App\Enums\RequestStatus
        ? $request->status
        : App\Enums\RequestStatus::tryFrom((string) $request->status);

    $statusValue = $status?->value ?: strtoupper((string) $request->status);

    $custody = $request->custody;

    $custodyStatus = $custody
        ? (
            $custody->status instanceof \BackedEnum
                ? $custody->status->value
                : strtoupper((string) $custody->status)
        )
        : null;

    $laundry = $custody?->laundryJob;

    $laundryStatus = $laundry
        ? (
            $laundry->status instanceof \BackedEnum
                ? $laundry->status->value
                : strtoupper((string) $laundry->status)
        )
        : null;

    $returns = $custody?->returns ?? collect();
    $history = $request->statusHistory ?? collect();
    $approvalSteps = $request->currentVersion?->approvalSteps ?? collect();

    $verificationStep = $approvalSteps
        ->where('sequence_no', 1)
        ->first();

    $headDecisionStep = $approvalSteps
        ->where('sequence_no', 2)
        ->first();

    /*
     * Only Gate Pass / off-campus requests pass through Action Officer
     * verification before the SPMU Head / Admin decision. Ordinary on-campus
     * requests, including linen and non-linen requests, go directly to the
     * SPMU Head / Admin review after borrower submission.
     */
    $requiresActionOfficerVerification = (bool) ($request->currentVersion?->off_campus);

    $waitingForActionOfficer =
        $requiresActionOfficerVerification
        && $statusValue === 'UNDER_SPMU'
        && (
            ! $verificationStep
            || in_array($verificationStep->decision, ['PENDING', 'RECEIVED'], true)
        );

    $waitingForHead =
        $statusValue === 'UNDER_SPMU'
        && $headDecisionStep
        && in_array($headDecisionStep->decision, ['PENDING', 'RECEIVED'], true)
        && (
            ! $requiresActionOfficerVerification
            || $verificationStep?->decision === 'VERIFIED'
        );

    $historyAt = static function (array $statuses) use ($history) {
        return $history
            ->whereIn('to_status', $statuses)
            ->sortBy('changed_at')
            ->first()?->changed_at;
    };

    $formatDateTime = static fn ($value) =>
        $value
            ? $value->format($compact ? 'd M, g:i A' : 'd M Y, g:i A')
            : null;

    /*
    |--------------------------------------------------------------------------
    | Important dates
    |--------------------------------------------------------------------------
    */

    $submittedAt = $historyAt([
        'SUBMITTED',
        'UNDER_SPMU',
    ]);

    $reviewStartedAt =
        $historyAt(['UNDER_SPMU'])
        ?: $submittedAt;

    $approvedAt = $request->final_approved_at;

    $pickupScheduledAt = $custody?->pickup_scheduled_at;

    $releasedAt = $custody?->released_at;

    $firstReturn = $returns
        ->sortBy(
            fn ($return) =>
                $return->received_at
                ?: $return->created_at
        )
        ->first();

    $returnStartedAt =
        $firstReturn?->received_at
        ?: $laundry?->worker_received_at;

    $completedAt = $custody?->closed_at;

    /*
    |--------------------------------------------------------------------------
    | Major workflow state
    |--------------------------------------------------------------------------
    */

    $isApproved =
        (bool) $approvedAt
        || in_array(
            $statusValue,
            [
                'FINAL_APPROVED_AWAITING_DOWNLOAD',
                'APPROVED_READY_FOR_RELEASE',
            ],
            true
        )
        || (bool) $custody;

    $hasActivePickupSchedule = (bool) (
        $custody?->scheduled_release_at
        && ! $custody?->pickup_expired_at
    );

    $preparationComplete =
        (bool) $custody?->prepared_at;

    $isReleased =
        (bool) $releasedAt;

    $isClosed =
        $custodyStatus === 'CLOSED';

    $pickupExpired =
        (bool) $custody?->pickup_expired_at
        && ! $releasedAt;

    $terminal = in_array(
        $statusValue,
        [
            'REJECTED',
            'CANCELLED',
            'EXPIRED',
        ],
        true
    );

    $returnedForRevision =
        $statusValue === 'RETURNED_FOR_REVISION';

    /*
    |--------------------------------------------------------------------------
    | Current major milestone
    |--------------------------------------------------------------------------
    |
    | Normal on-campus request:
    | Prepared -> Submitted -> SPMU Head / Admin Review -> Pickup -> Release
    |
    | Gate Pass / off-campus request:
    | Prepared -> Submitted -> Action Officer Verification ->
    | SPMU Head / Admin Review -> Pickup -> Release
    |
    */

    $actionOfficerStepIndex = $requiresActionOfficerVerification ? 3 : null;
    $headReviewStepIndex = $requiresActionOfficerVerification ? 4 : 3;
    $pickupStepIndex = $requiresActionOfficerVerification ? 5 : 4;
    $releaseStepIndex = $requiresActionOfficerVerification ? 6 : 5;
    $returnStepIndex = $requiresActionOfficerVerification ? 7 : 6;
    $completedStepIndex = $requiresActionOfficerVerification ? 8 : 7;

    $reviewStepIndex = match (true) {
        $requiresActionOfficerVerification
            && $verificationStep?->decision === 'RETURNED_FOR_REVISION'
            => $actionOfficerStepIndex,

        $waitingForActionOfficer
            => $actionOfficerStepIndex,

        default
            => $headReviewStepIndex,
    };

    $currentIndex = match (true) {
        $isClosed => $completedStepIndex,

        $isReleased => $returnStepIndex,

        $hasActivePickupSchedule => $releaseStepIndex,

        $isApproved => $pickupStepIndex,

        in_array(
            $statusValue,
            [
                'SUBMITTED',
                'UNDER_SPMU',
                'UNDER_GSU',
                'UNDER_VPAF',
                'RETURNED_FOR_REVISION',
                'REJECTED',
            ],
            true
        ) => $reviewStepIndex,

        $statusValue === 'SIGNED' => 2,

        default => 1,
    };

    if ($pickupExpired) {
        $currentIndex = $pickupStepIndex;
    }

    if (
        $terminal
        && $statusValue === 'CANCELLED'
        && $currentIndex === 1
    ) {
        $currentIndex = 2;
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic Return / Laundry description
    |--------------------------------------------------------------------------
    */

    $returnDescription =
        'Return processing starts after the issued items are brought back for the required return workflow.';

    /*
     * Completed transaction:
     *
     * Never show waiting/in-progress wording.
     */
    if ($isClosed) {
        $returnDescription = $laundry
            ? 'SPMU completed the borrower return reconciliation after the linen was physically turned over to Laundry. Internal washing may continue separately.'
            : 'SPMU completed the return inspection and final reconciliation.';
    }

    /*
     * Released but not yet completed.
     */
    elseif ($isReleased) {

        /*
         * Mixed / linen borrowing.
         */
        if ($laundry) {
            $returnDescription = match ($laundryStatus) {

                'FOR_LAUNDRY'
                    => $laundry->hasVerifiedAccomplishedForm()
                        ? 'Laundry Personnel received the linen and wet-signed Received by. SPMU is verifying the accomplished Laundry Form.'
                        : 'Return the linen to the Laundry Area first with the same printed Laundry Form. Laundry Personnel records the quantity and condition and wet-signs Received by, then you bring the accomplished form to SPMU.',

                'TURNED_OVER_TO_LAUNDRY'
                    => 'Laundry Personnel have physically received the linen. The borrower no longer waits for the washing cycle; processing continues internally in the Laundry Area.',

                'LAUNDRY_COMPLETED'
                    => 'Internal laundry processing is complete and clean linen has been restored to stock.',

                'IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'
                    => 'This Laundry record is being aligned to the simplified turnover workflow.',

                default
                    => 'The required linen/laundry return process is in progress.',
            };
        }

        /*
         * Ordinary non-linen return.
         */
        elseif ($firstReturn) {
            $returnDescription = match ($custodyStatus) {

                'RETURN_PROCESSING'
                    => 'SPMU is processing the returned items and completing final reconciliation.',

                'OBLIGATION_OPEN'
                    => 'Return inspection was recorded, but an outstanding obligation still requires resolution.',

                'OVERDUE'
                    => 'The borrowing is overdue and remains under SPMU monitoring.',

                default
                    => 'SPMU has received the returned items and is completing the return inspection.',
            };
        }

        /*
         * Still on custody.
         */
        elseif ($custody?->due_at) {
            $returnDescription =
                now()->greaterThan($custody->due_at)
                    ? 'Return is overdue since '
                        .$custody->due_at->format(
                            'd M Y, g:i A'
                        )
                        .'.'
                    : 'Items are currently on custody. Return due '
                        .$custody->due_at->format(
                            'd M Y, g:i A'
                        )
                        .'.';
        }

        else {
            $returnDescription =
                'Items are currently on custody and are awaiting return to SPMU.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tracker steps
    |--------------------------------------------------------------------------
    */

    $steps = [
        1 => [
            'label' => 'Request Prepared',
            'icon' => 'requests',
            'time' => $request->created_at,
            'description' =>
                $statusValue === 'DRAFT'
                    ? 'Complete the request and required documents before submission.'
                    : 'Borrowing request created.',
        ],

        2 => [
            'label' => 'Submitted',
            'icon' => 'upload',
            'time' => $submittedAt,
            'description' =>
                $submittedAt
                    ? ($requiresActionOfficerVerification
                        ? 'Gate Pass request and required supporting documents submitted for Action Officer verification.'
                        : 'Borrowing request and required supporting documents submitted directly for SPMU Head / Admin review.')
                    : 'Waiting for borrower submission.',
        ],
    ];

    if ($requiresActionOfficerVerification) {
        $steps[$actionOfficerStepIndex] = [
            'label' => 'Action Officer Verification',
            'icon' => 'eye',
            'time' => $verificationStep?->received_at ?: $reviewStartedAt,
            'description' => match (true) {
                $verificationStep?->decision === 'RETURNED_FOR_REVISION'
                    => 'The Action Officer returned the Gate Pass request for correction and resubmission.',

                $verificationStep?->decision === 'VERIFIED'
                    => 'The Action Officer verified the Gate Pass request and forwarded it for final review and decision.',

                $waitingForActionOfficer
                    => 'The Gate Pass request is awaiting verification by the Action Officer.',

                default
                    => 'Action Officer verification applies only to Gate Pass / off-campus requests.',
            },
        ];
    }

    $steps[$headReviewStepIndex] = [
        'label' => 'SPMU Head / Admin Review',
        'icon' => 'success',
        'time' => $headDecisionStep?->received_at ?: ($requiresActionOfficerVerification ? null : $reviewStartedAt),
        'description' => match (true) {
            $headDecisionStep?->decision === 'RETURNED_FOR_REVISION'
                => 'The SPMU Head / Admin returned the request for correction and resubmission.',

            $statusValue === 'REJECTED'
                => 'The SPMU Head / Admin completed the final review and rejected the request.',

            $isApproved
                => 'The SPMU Head / Admin approved the request for operational processing.',

            $waitingForHead && $requiresActionOfficerVerification
                => 'The Gate Pass request was verified by the Action Officer and is awaiting the SPMU Head / Admin final decision.',

            $waitingForHead
                => 'The submitted request is awaiting the SPMU Head / Admin final review and decision.',

            $waitingForActionOfficer
                => 'This stage becomes available after the Action Officer verifies the Gate Pass request.',

            default
                => 'Final review and decision are handled by the SPMU Head / Admin.',
        },
    ];

    $steps[$pickupStepIndex] = [
        'label' => 'Pickup Scheduled',
        'icon' => 'calendar',
        'time' => $pickupScheduledAt,
        'description' => match (true) {
            (bool) $pickupScheduledAt && (bool) $custody?->scheduled_release_at
                => 'Pickup was scheduled for '
                    .$custody->scheduled_release_at->format('d M Y, g:i A')
                    .($custody?->pickup_expires_at
                        ? ' · claim until '.$custody->pickup_expires_at->format('g:i A')
                        : '')
                    .'.',

            (bool) $pickupScheduledAt
                => 'SPMU set the pickup schedule.',

            $pickupExpired
                => 'The previous pickup window expired. Waiting for SPMU to schedule a new pickup window.',

            $isApproved
                => 'Waiting for SPMU operational processing and pickup scheduling.',

            default
                => 'Pickup scheduling becomes available after final approval.',
        },
    ];

    $steps[$releaseStepIndex] = [
        'label' => 'Items Released',
        'icon' => 'custody',
        'time' => $releasedAt,
        'description' =>
            $isReleased
                ? 'SPMU physically released the approved items to the borrower.'
                : match (true) {
                    ! $hasActivePickupSchedule
                        => 'Pickup must be scheduled before item preparation and release.',
                    ! $preparationComplete
                        => 'SPMU is preparing the approved items for release.',
                    default
                        => 'Item preparation is complete. Waiting for the scheduled physical handover.',
                },
    ];

    $steps[$returnStepIndex] = [
        'label' => 'Return Processing',
        'icon' => 'custody',
        'time' => $returnStartedAt,
        'description' => $returnDescription,
    ];

    $steps[$completedStepIndex] = [
        'label' => 'Completed',
        'icon' => 'success',
        'time' => $completedAt,
        'description' =>
            $isClosed
                ? 'All return requirements were completed and the borrowing transaction was closed.'
                : 'Completed after final SPMU return reconciliation.',
    ];

    ksort($steps);

    if ($compact) {
        $steps[1]['label'] = 'Prepared';
        $steps[$headReviewStepIndex]['label'] = $isApproved
            ? 'Reviewed'
            : $steps[$headReviewStepIndex]['label'];
    }

    if ($releaseView) {
        $steps[$releaseStepIndex]['label'] = 'Release';
        $steps[$releaseStepIndex]['icon'] = 'plus';
        $steps[$returnStepIndex]['icon'] = 'chevron-right';
    }

    /*
    |--------------------------------------------------------------------------
    | Tracker heading
    |--------------------------------------------------------------------------
    */

    $trackerEyebrow =
        $isClosed
            ? 'Request history'
            : 'Real-time progress';

    $trackerTitle =
        $isClosed
            ? 'Borrowing workflow'
            : 'Request progress';

    $trackerIntro =
        $isClosed
            ? 'Review the completed stages of this borrowing transaction.'
            : 'Follow your request from preparation through final return and reconciliation.';

    /*
    |--------------------------------------------------------------------------
    | Current status label
    |--------------------------------------------------------------------------
    */

    $currentLabel = match (true) {

        $statusValue === 'REJECTED'
            => 'Request Rejected',

        $statusValue === 'CANCELLED'
            => 'Request Cancelled',

        $statusValue === 'EXPIRED'
            => 'Request Expired',

        $returnedForRevision
            => 'Borrower Revision Required',

        $pickupExpired
            => 'Pickup Window Expired',

        $isClosed
            => 'Completed',

        $isReleased
            && $laundryStatus === 'FOR_LAUNDRY'
            => $laundry?->hasVerifiedAccomplishedForm()
                ? 'Awaiting SPMU Return Verification'
                : 'Awaiting Laundry Return',

        $isReleased
            && $laundryStatus === 'TURNED_OVER_TO_LAUNDRY'
            => 'Linen Turned Over to Laundry',

        $isReleased
            && in_array($laundryStatus, ['IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true)
            => 'Laundry Workflow Updating',

        $isReleased
            => 'Return / Custody In Progress',

        $preparationComplete
            && $hasActivePickupSchedule
            => 'Ready for Release',

        ! $hasActivePickupSchedule
            && $isApproved
            => 'Pickup Scheduling Required',

        $hasActivePickupSchedule
            && ! $preparationComplete
            => 'Preparing for Release',

        $preparationComplete
            && $isApproved
            => 'Pickup Scheduling Required',

        $waitingForActionOfficer
            => 'Waiting for Action Officer Verification',

        $waitingForHead
            => 'Waiting for SPMU Head / Admin Review',

        in_array(
            $statusValue,
            [
                'SUBMITTED',
                'UNDER_SPMU',
                'UNDER_GSU',
                'UNDER_VPAF',
            ],
            true
        )
            => $requiresActionOfficerVerification
                ? 'Gate Pass Verification in Progress'
                : 'Waiting for SPMU Head / Admin Review',

        $statusValue === 'SIGNED'
            => 'Ready for Submission',

        default
            => 'Preparing Request',
    };
@endphp


<section
    class="content-area request-tracker-card"
    aria-labelledby="request-progress-title"
>
    <article class="card request-tracker">

        {{-- ================================================================
             HEADER
        ================================================================= --}}

        <div class="request-tracker__header {{ $compact ? 'visually-hidden' : '' }}">

            <div>

                <p class="eyebrow">
                    {{ $trackerEyebrow }}
                </p>

                <h2 id="request-progress-title">
                    {{ $trackerTitle }}
                </h2>

                <p class="request-tracker__intro">
                    {{ $trackerIntro }}
                </p>

            </div>


            @if($showCurrentStatus)

                <span
                    class="
                        request-tracker__current
                        {{
                            $terminal
                                ? 'is-terminal'
                                : (
                                    $returnedForRevision
                                    || $pickupExpired
                                        ? 'is-warning'
                                        : ''
                                )
                        }}
                    "
                >

                    <span
                        class="request-tracker__current-dot"
                        aria-hidden="true"
                    ></span>

                    {{ $currentLabel }}

                </span>

            @endif

        </div>


        {{-- ================================================================
             TERMINAL / ATTENTION MESSAGES
        ================================================================= --}}

       @if($terminal && $statusValue !== 'CANCELLED')
            <div class="request-tracker__terminal-note" role="status">
                <x-icon name="information" size="18" />
                <span>
                    This request is {{ strtolower(str_replace('_', ' ', $statusValue)) }}.
                    The tracker shows the furthest workflow point reached before it was closed.
                </span>
            </div>
        @elseif($returnedForRevision)
            <div class="request-tracker__attention-note" role="status">
                <x-icon name="information" size="18" />
                <span>
                    SPMU returned this request for revision.
                    Update the required details/documents and submit it again to continue.
                </span>
            </div>
        @elseif($pickupExpired)
            <div class="request-tracker__attention-note" role="status">
                <x-icon name="information" size="18" />
                <span>
                    The previous pickup window expired.
                    The reservation remains in place while waiting for SPMU to schedule a new pickup window.
                </span>
            </div>
        @endif


        {{-- ================================================================
             TRACKER RAIL
        ================================================================= --}}

        <div
            class="request-tracker__scroll"
            tabindex="0"
            aria-label="Request progress steps"
        >

            <ol class="request-tracker__rail">

                @foreach($steps as $index => $step)

                    @php

                        $stepState = 'is-pending';

                        /*
                         * Completed transaction:
                         * everything becomes green/completed.
                         */
                        if ($isClosed) {

                            $stepState = 'is-complete';

                        }

                        /*
                         * Rejected / cancelled / expired.
                         */
                        elseif ($terminal) {

                            $stepState =
                                $index < $currentIndex
                                    ? 'is-complete'
                                    : (
                                        $index === $currentIndex
                                            ? 'is-stopped'
                                            : 'is-pending'
                                    );

                        }

                        /*
                         * Previous milestones.
                         */
                        elseif ($index < $currentIndex) {

                            $stepState = 'is-complete';

                        }

                        /*
                         * Current milestone.
                         */
                        elseif ($index === $currentIndex) {

                            $stepState =
                                (
                                    $returnedForRevision
                                    || $pickupExpired
                                )
                                    ? 'is-warning'
                                    : 'is-current';

                        }

                    @endphp


                    <li
                        class="
                            request-tracker__step
                            {{ $stepState }}
                        "

                        @if(
                            in_array(
                                $stepState,
                                [
                                    'is-current',
                                    'is-warning',
                                    'is-stopped',
                                ],
                                true
                            )
                        )
                            aria-current="step"
                        @endif
                    >

                        {{-- Marker --}}

                        <div
                            class="request-tracker__marker"
                            aria-hidden="true"
                        >

                            @if($stepState === 'is-complete')

                                <svg
                                    width="25"
                                    height="25"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M5 12.5L9.5 17L19 7.5"
                                        stroke="currentColor"
                                        stroke-width="3.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    />
                                </svg>

                            @elseif($stepState === 'is-stopped')

                                <x-icon
                                    name="error"
                                    size="22"
                                />

                            @else

                                <x-icon
                                    :name="$step['icon']"
                                    size="21"
                                />

                            @endif

                        </div>


                        {{-- Text --}}

                        <div
                            class="request-tracker__copy workflow-tracker__interactive"
                            data-workflow-step
                            data-workflow-title="{{ $step['label'] }}"
                            data-workflow-meta="{{ $step['time'] ? $formatDateTime($step['time']) : 'Pending' }}"
                            data-workflow-description="{{ $step['description'] }}"
                            tabindex="0"
                            aria-label="{{ $step['label'] }}. {{ $step['time'] ? $formatDateTime($step['time']) : 'Pending' }}. {{ $step['description'] }}"
                        >

                            <strong>
                                {{ $step['label'] }}
                            </strong>


                            @if($step['time'])

                                <time
                                    datetime="{{
                                        $step['time']
                                            ->toIso8601String()
                                    }}"
                                >

                                    {{
                                        $formatDateTime(
                                            $step['time']
                                        )
                                    }}

                                </time>

                            @else

                                <span
                                    class="
                                        request-tracker__pending-label
                                    "
                                >
                                    Pending
                                </span>

                            @endif

                        </div>

                    </li>

                @endforeach

            </ol>

        </div>


        {{-- ================================================================
             FOOTER / INFO
        ================================================================= --}}

        @unless($compact)
        <p class="request-tracker__hint">

            <x-icon
                name="information"
                size="16"
            />

            <span>

                @if($isClosed)

                    This completed workflow history is retained in the
                    request record for reference.

                @else

                    This progress updates as SPMU processes the request,
                    pickup, release, return, and final reconciliation.

                @endif

            </span>

        </p>
        @endunless

    </article>
</section>

<x-workflow-tracker-interactions />
