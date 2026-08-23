@props(['request', 'showCurrentStatus' => true])

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

    $spmuStep = $approvalSteps
        ->where('sequence_no', 1)
        ->first();

    $waitingForHead =
        $statusValue === 'UNDER_SPMU'
        && (
            ! $spmuStep
            || in_array(
                $spmuStep->decision,
                ['PENDING', 'RECEIVED'],
                true
            )
        );

    $historyAt = static function (array $statuses) use ($history) {
        return $history
            ->whereIn('to_status', $statuses)
            ->sortBy('changed_at')
            ->first()?->changed_at;
    };

    $formatDateTime = static fn ($value) =>
        $value
            ? $value->format('d M Y, g:i A')
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
    | Blue/current means:
    | "Ito ang kasalukuyang milestone na tinatrabaho."
    |
    | Hindi ibig sabihin na completed na ang milestone.
    |
    */

    $currentIndex = match (true) {
        $isClosed => 8,

        $isReleased => 7,

        $hasActivePickupSchedule => 6,

        $isApproved => 5,

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
        ) => 3,

        $statusValue === 'SIGNED' => 2,

        default => 1,
    };

    if ($pickupExpired) {
        $currentIndex = 5;
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
            ? 'SPMU completed final return reconciliation after the required linen/laundry process was settled.'
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
                    => 'Waiting for the borrower to turn over the used linen and borrower-signed physical Laundry Form to the Laundry Worker.',

                'IN_PROCESS'
                    => 'The Laundry Worker is processing the linen. No borrower system input is required.',

                'READY_FOR_SPMU_RETURN'
                    => 'Laundry processing is complete. The Laundry Worker will return the cleaned linen and physical form directly to SPMU.',

                'AWAITING_FINAL_FORM_UPLOAD'
                    => 'SPMU final physical acceptance is complete. Waiting for the Laundry Worker to upload the fully signed Laundry Form.',

                'FORM_REPLACEMENT_REQUIRED'
                    => 'A clear fully signed Laundry Form must be uploaded by the Laundry Worker.',

                'LAUNDRY_COMPLETED'
                    => 'The linen/laundry process is complete and the final signed Laundry Form is archived.',

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
    |
    | IMPORTANT:
    |
    | Kapag nangyari na ang milestone, historical wording na.
    |
    | Example:
    |
    | WRONG kapag Completed na:
    | "Waiting for SPMU to set the pickup schedule."
    |
    | CORRECT:
    | "Pickup was scheduled for 23 Aug 2026, 7:00 AM."
    |
    */

    $steps = [

        /*
        |--------------------------------------------------------------------------
        | 1. Request Prepared
        |--------------------------------------------------------------------------
        */

        1 => [
            'label' => 'Request Prepared',

            'icon' => 'requests',

            'time' => $request->created_at,

            'description' =>
                $statusValue === 'DRAFT'
                    ? 'Complete the request and required documents before submission.'
                    : 'Borrowing request created.',
        ],

        /*
        |--------------------------------------------------------------------------
        | 2. Submitted
        |--------------------------------------------------------------------------
        */

        2 => [
            'label' => 'Submitted',

            'icon' => 'upload',

            'time' => $submittedAt,

            'description' =>
                $submittedAt
                    ? 'Signed request and required supporting documents submitted to SPMU.'
                    : 'Waiting for borrower submission.',
        ],

        /*
        |--------------------------------------------------------------------------
        | 3. SPMU Review
        |--------------------------------------------------------------------------
        */

        3 => [
            'label' => 'SPMU Review',

            'icon' => 'eye',

            'time' => $reviewStartedAt,

            'description' => match (true) {

                $returnedForRevision
                    => 'SPMU returned the request for correction and resubmission.',

                $statusValue === 'REJECTED'
                    => 'SPMU completed the review and rejected the request.',

                $isApproved
                    => 'SPMU reviewed and verified the request.',

                $waitingForHead
                    => 'The request is waiting for the SPMU Head review.',

                default
                    => 'SPMU review is in progress.',
            },
        ],

        /*
        |--------------------------------------------------------------------------
        | 4. Approved
        |--------------------------------------------------------------------------
        */

        4 => [
            'label' => 'Approved',

            'icon' => 'success',

            'time' => $approvedAt,

            'description' =>
                $isApproved
                    ? 'SPMU approved the request and reserved the approved quantities for pickup.'
                    : 'Waiting for SPMU approval.',
        ],

        /*
        |--------------------------------------------------------------------------
        | 5. Pickup Scheduled
        |--------------------------------------------------------------------------
        */

        5 => [
            'label' => 'Pickup Scheduled',

            'icon' => 'calendar',

            'time' => $pickupScheduledAt,

            'description' => match (true) {

                /*
                 * Already scheduled.
                 *
                 * Historical wording.
                 */
                (bool) $pickupScheduledAt
                    && (bool) $custody?->scheduled_release_at
                    => 'Pickup was scheduled for '
                        .$custody->scheduled_release_at->format(
                            'd M Y, g:i A'
                        )
                        .(
                            $custody?->pickup_expires_at
                                ? ' · claim until '
                                    .$custody->pickup_expires_at->format(
                                        'g:i A'
                                    )
                                : ''
                        )
                        .'.',

                /*
                 * We have evidence that scheduling happened,
                 * even if scheduled_release_at is no longer available.
                 */
                (bool) $pickupScheduledAt
                    => 'SPMU set the pickup schedule.',

                /*
                 * Current pending states.
                 */
                $pickupExpired
                    => 'The previous pickup window expired. Waiting for SPMU to schedule a new pickup window.',

                $isApproved
                    => 'Waiting for SPMU to schedule the pickup.',

                default
                    => 'Pickup scheduling becomes available after approval.',
            },
        ],

        /*
        |--------------------------------------------------------------------------
        | 6. Items Released
        |--------------------------------------------------------------------------
        */

        6 => [
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
        ],

        /*
        |--------------------------------------------------------------------------
        | 7. Return Processing
        |--------------------------------------------------------------------------
        */

        7 => [
            'label' => 'Return Processing',

            'icon' => 'custody',

            'time' => $returnStartedAt,

            'description' =>
                $returnDescription,
        ],

        /*
        |--------------------------------------------------------------------------
        | 8. Completed
        |--------------------------------------------------------------------------
        */

        8 => [
            'label' => 'Completed',

            'icon' => 'success',

            'time' => $completedAt,

            'description' =>
                $isClosed
                    ? 'All return requirements were completed and the borrowing transaction was closed.'
                    : 'Completed after final SPMU return reconciliation.',
        ],
    ];

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
            => 'Awaiting Laundry Turnover',

        $isReleased
            && $laundryStatus === 'IN_PROCESS'
            => 'In Laundry Process',

        $isReleased
            && $laundryStatus === 'READY_FOR_SPMU_RETURN'
            => 'Laundry Returning to SPMU',

        $isReleased
            && $laundryStatus === 'AWAITING_FINAL_FORM_UPLOAD'
            => 'Awaiting Final Laundry Form',

        $isReleased
            && $laundryStatus === 'FORM_REPLACEMENT_REQUIRED'
            => 'Final Laundry Form Required',

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

        $waitingForHead
            => 'Waiting for SPMU Head Approval',

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
            => 'Under SPMU Review',

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

        <div class="request-tracker__header">

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

                                <x-icon
                                    name="success"
                                    size="22"
                                />

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

                        <div class="request-tracker__copy">

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


                            <small>
                                {{ $step['description'] }}
                            </small>

                        </div>

                    </li>

                @endforeach

            </ol>

        </div>


        {{-- ================================================================
             FOOTER / INFO
        ================================================================= --}}

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

    </article>
</section>