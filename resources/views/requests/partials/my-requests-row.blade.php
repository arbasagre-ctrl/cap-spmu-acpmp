@php
    /*
    |--------------------------------------------------------------------------
    | Borrower "My Requests" row
    |--------------------------------------------------------------------------
    |
    | This row intentionally uses the same operational-record pattern used by
    | My Borrowings and SPMU transaction lists so request records feel like one
    | system instead of a separate card design.
    |
    */
    $version = $request->currentVersion;
    $custody = $request->custody;

    $requestStatus = $request->status;
    $custodyWorkflow = $custody?->workflowStatus();

    if ($custodyWorkflow) {
        [$statusKey, $statusLabel] = match ($custodyWorkflow['key']) {
            'BORROWED' => ['BORROWED', 'Released / On Custody'],
            'RETURN_PROCESSING' => ['RETURN_PROCESSING', 'Return Processing'],
            'OVERDUE' => ['OVERDUE', 'Overdue'],
            'INCIDENT_OPEN' => ['ACCOUNTABILITY_PENDING', 'Accountability Pending'],
            'OBLIGATION_OPEN' => ['OBLIGATION_OPEN', 'Accountability Pending'],
            'COMPLETED' => ['COMPLETED', 'Completed'],
            'BORROWER_CLEARED' => ['BORROWER_CLEARED', 'Borrower Cleared'],
            'CANCELLED' => ['CANCELLED', 'Cancelled'],
            'READY_FOR_RELEASE' => ['READY_FOR_RELEASE', 'Ready for Release'],
            'PICKUP_SCHEDULED' => ['PICKUP_SCHEDULED', 'Pickup Scheduled'],
            'ITEM_PREPARATION' => ['ITEM_PREPARATION', 'For Item Preparation'],
            'PICKUP_SCHEDULING' => ['PICKUP_SCHEDULING', 'For Pickup Scheduling'],
            'PICKUP_EXPIRED' => ['PICKUP_EXPIRED', 'Pickup Missed'],
            default => [$custodyWorkflow['key'], $custodyWorkflow['label']],
        };
    } else {
        [$statusKey, $statusLabel] = match ($requestStatus) {
            App\Enums\RequestStatus::Draft
                => ['DRAFT', 'Draft'],

            App\Enums\RequestStatus::ReturnedForRevision
                => ['RETURNED_FOR_REVISION', 'Returned for Revision'],

            App\Enums\RequestStatus::UnderSpmu,
            App\Enums\RequestStatus::UnderGsu,
            App\Enums\RequestStatus::UnderVpaf
                => ['UNDER_SPMU', 'Under SPMU Review'],

            App\Enums\RequestStatus::ApprovedReadyForRelease
                => ['APPROVED_READY_FOR_RELEASE', 'Ready for Release'],

            App\Enums\RequestStatus::FinalApprovedAwaitingDownload
                => ['FINAL_APPROVED_AWAITING_DOWNLOAD', 'Approved'],

            App\Enums\RequestStatus::Rejected
                => ['REJECTED', 'Rejected'],

            App\Enums\RequestStatus::Cancelled
                => ['CANCELLED', 'Cancelled'],

            App\Enums\RequestStatus::Expired
                => ['INACTIVE', 'Inactive'],

            default => ['SUBMITTED', 'In Progress'],
        };
    }

    $requiresAction = (
        ! $custody
        && in_array(
            $requestStatus,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        )
    ) || (($custodyWorkflow['key'] ?? null) === 'PICKUP_EXPIRED');

    $statusGroup = match (true) {
        ($custodyWorkflow['group'] ?? null) === 'completed' => 'completed',
        ($custodyWorkflow['group'] ?? null) === 'cancelled' => 'closed',
        ($custodyWorkflow['group'] ?? null) === 'attention' => 'attention',
        $requiresAction => 'action',
        in_array(($custodyWorkflow['group'] ?? null), ['custody', 'return'], true) => 'custody',
        ($custodyWorkflow['group'] ?? null) === 'release' => 'approved',

        in_array($requestStatus, [
            App\Enums\RequestStatus::UnderSpmu,
            App\Enums\RequestStatus::UnderGsu,
            App\Enums\RequestStatus::UnderVpaf,
        ], true) => 'review',

        in_array($requestStatus, [
            App\Enums\RequestStatus::FinalApprovedAwaitingDownload,
            App\Enums\RequestStatus::ApprovedReadyForRelease,
        ], true) => 'approved',

        in_array($requestStatus, [
            App\Enums\RequestStatus::Rejected,
            App\Enums\RequestStatus::Cancelled,
            App\Enums\RequestStatus::Expired,
        ], true) => 'closed',

        default => 'review',
    };

    $context = $version?->student_organization
        ?: ($version?->office_unit ?: $version?->location);

    $submittedAt = $version?->submitted_at ?: $request->created_at;
    $borrowingDate = $version?->schedule_date ?: $version?->needed_from;

    $itemTypes = $version?->items->count() ?? 0;
    $pieces = (int) ($version?->items->sum(
        fn ($item) => $item->approved_quantity ?? $item->requested_quantity
    ) ?? 0);

    $searchText = strtolower(trim(
        ($request->request_no ?? '')
        .' '.($version?->purpose_event ?? '')
        .' '.($context ?? '')
        .' '.$statusLabel
    ));

    /*
     * A DRAFT has not entered the workflow, so its card opens the editor
     * rather than a detail page with nothing to report. requests.show
     * redirects drafts to the same place, so a stale link still lands right.
     */
    $isDraft = $request->status === \App\Enums\RequestStatus::Draft;

    $rowToneClass = match (true) {
        ($custodyWorkflow['key'] ?? $statusKey) === 'OVERDUE' => 'is-danger',
        ($custodyWorkflow['group'] ?? null) === 'attention' => 'is-warning',
        $requiresAction || $statusKey === 'RETURN_PROCESSING' => 'is-warning',
        default => '',
    };
@endphp

<a
    class="operational-record ui-pressable {{ $rowToneClass }}"
    href="{{ $isDraft ? route('requests.edit', $request) : route('requests.show', $request) }}"
    data-request-card
    data-status-group="{{ $statusGroup }}"
    data-search="{{ $searchText }}"
    data-submitted="{{ optional($submittedAt)->timestamp ?? 0 }}"
>
    <span class="operational-record-primary">
        <strong>{{ $request->request_no }}</strong>
        <span>{{ $version?->purpose_event ?: 'Borrowing request' }}</span>
        <small>{{ $context ?: 'No additional details recorded' }}</small>
    </span>

    <span class="operational-record-facts">
        <span>
            <small>Submitted</small>
            <strong>{{ $submittedAt ? $submittedAt->format('d M Y, h:i A') : 'Not yet submitted' }}</strong>
        </span>

        <span>
            <small>Borrowing date</small>
            <strong>{{ $borrowingDate ? $borrowingDate->format('d M Y') : 'Schedule pending' }}</strong>
        </span>

        <span>
            <small>Items</small>
            <strong>
                {{ $itemTypes }} {{ $itemTypes === 1 ? 'item type' : 'item types' }}
                · {{ number_format($pieces) }} {{ $pieces === 1 ? 'piece' : 'pieces' }}
            </strong>
        </span>
    </span>

    <span class="operational-record-action">
        <x-status-badge
            :status="$statusKey"
            :label="$statusLabel"
        />
        <strong>{{ $isDraft ? 'Resume Draft' : 'View Request' }}<x-icon name="arrow-right" size="16" /></strong>
    </span>
</a>
