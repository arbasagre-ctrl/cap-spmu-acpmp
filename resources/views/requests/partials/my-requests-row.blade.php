@php
    /*
    |--------------------------------------------------------------------------
    | Borrower "My Requests" row
    |--------------------------------------------------------------------------
    |
    | Custody status is authoritative once the items are physically released.
    | Legacy GSU/VPAF and awaiting-download records stay readable, but the
    | borrower-facing wording follows the current SPMU-only flow.
    |
    */
    $version = $request->currentVersion;
    $custody = $request->custody;

    $custodyStatus = $custody?->status;

    $effectiveCustodyStatus = ($custody && ($custodyStatus === 'CLOSED' || $custody->closed_at !== null))
        ? 'CLOSED'
        : $custodyStatus;

    $requestStatus = $request->status;

    /*
     * [badge label, badge tone, tile tone, tile icon]
     */
    [$statusLabel, $statusTone, $tileTone, $tileIcon] = match ($effectiveCustodyStatus) {
        'ACTIVE' => ['Released / On Custody', 'blue', 'blue', 'custody'],
        'RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'EARLY_RETURN' => ['Return Processing', 'amber', 'amber', 'cycle'],
        'OVERDUE' => ['Overdue', 'red', 'red', 'warning'],
        'INCIDENT_OPEN' => ['Incident Open', 'red', 'red', 'warning'],
        'OBLIGATION_OPEN' => ['Obligation Open', 'amber', 'amber', 'receipt'],
        'CLOSED' => ['Completed', 'green', 'green', 'check-circle'],

        default => match ($requestStatus) {
            App\Enums\RequestStatus::Draft
                => ['Draft', 'neutral', 'neutral', 'edit'],

            App\Enums\RequestStatus::ReturnedForRevision
                => ['Returned for Revision', 'red', 'red', 'edit'],

            App\Enums\RequestStatus::UnderSpmu,
            App\Enums\RequestStatus::UnderGsu,
            App\Enums\RequestStatus::UnderVpaf
                => ['For Approval', 'amber', 'blue', 'requests'],

            App\Enums\RequestStatus::ApprovedReadyForRelease
                => ['Ready for Release', 'green', 'green', 'check-circle'],

            App\Enums\RequestStatus::FinalApprovedAwaitingDownload
                => ['Approved', 'blue', 'violet', 'requests'],

            App\Enums\RequestStatus::Rejected
                => ['Rejected', 'red', 'red', 'error'],

            App\Enums\RequestStatus::Cancelled
                => ['Cancelled', 'neutral', 'neutral', 'close'],

            App\Enums\RequestStatus::Expired
                => ['Inactive', 'neutral', 'neutral', 'clock'],

            default => ['In Progress', 'blue', 'blue', 'requests'],
        },
    };

    $requiresAction = ! $custody
        && in_array(
            $requestStatus,
            [
                App\Enums\RequestStatus::Draft,
                App\Enums\RequestStatus::ReturnedForRevision,
            ],
            true
        );

    $statusGroup = match (true) {
        $effectiveCustodyStatus === 'CLOSED' => 'completed',

        in_array($effectiveCustodyStatus, [
            'ACTIVE',
            'RETURN_PROCESSING',
            'PARTIALLY_RETURNED',
            'OVERDUE',
            'EARLY_RETURN',
            'INCIDENT_OPEN',
            'OBLIGATION_OPEN',
        ], true) => 'custody',

        $requiresAction => 'action',

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
@endphp

<article
    class="mr-row {{ $requiresAction ? 'is-action-required' : '' }}"
    data-request-card
    data-status-group="{{ $statusGroup }}"
    data-search="{{ $searchText }}"
    data-submitted="{{ optional($submittedAt)->timestamp ?? 0 }}"
>
    <span class="mr-row-tile is-{{ $tileTone }}" aria-hidden="true">
        <x-icon :name="$tileIcon" size="21" />
    </span>

    <div class="mr-row-identity">
        <a class="mr-row-reference" href="{{ route('requests.show', $request) }}">
            {{ $request->request_no }}
        </a>

        <p class="mr-row-purpose">{{ $version?->purpose_event ?: 'Borrowing request' }}</p>

        <p class="mr-row-context">{{ $context ?: 'No additional details recorded' }}</p>
    </div>

    <div class="mr-row-meta">
        <div class="mr-row-fact">
            <span class="mr-row-fact-label">Submitted</span>
            <span class="mr-row-fact-value">
                <x-icon name="calendar" size="14" />
                {{ $submittedAt ? $submittedAt->format('d M Y, h:i A') : 'Not yet submitted' }}
            </span>
        </div>

        <div class="mr-row-fact">
            <span class="mr-row-fact-label">Borrowing Date</span>
            <span class="mr-row-fact-value">
                <x-icon name="calendar" size="14" />
                {{ $borrowingDate ? $borrowingDate->format('d M Y') : 'Schedule pending' }}
            </span>
        </div>

        <div class="mr-row-fact">
            <span class="mr-row-fact-label">Items</span>
            <span class="mr-row-fact-value">
                <x-icon name="box" size="14" />
                {{ $itemTypes }} {{ $itemTypes === 1 ? 'item type' : 'item types' }}
                <span class="mr-row-dot" aria-hidden="true">&bull;</span>
                {{ number_format($pieces) }} {{ $pieces === 1 ? 'piece' : 'pieces' }}
            </span>
        </div>
    </div>

    <div class="mr-row-action">
        <span class="mr-badge is-{{ $statusTone }}">{{ $statusLabel }}</span>

        <a class="mr-row-view" href="{{ route('requests.show', $request) }}">
            View request
            <svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" /></svg>
        </a>
    </div>

    <div class="mr-row-menu" data-request-menu>
        <button
            class="mr-row-menu-trigger ui-pressable"
            type="button"
            aria-haspopup="true"
            aria-expanded="false"
            aria-label="More actions for {{ $request->request_no }}"
            data-request-menu-trigger
        >
            <x-icon name="more" size="18" />
        </button>

        <div class="mr-row-menu-panel" data-request-menu-panel hidden>
            <a href="{{ route('requests.show', $request) }}">View request</a>

            @if($requiresAction)
                <a href="{{ route('requests.edit', $request) }}">
                    {{ $requestStatus === App\Enums\RequestStatus::Draft ? 'Continue request' : 'Revise request' }}
                </a>
            @endif

            <button type="button" data-copy-reference="{{ $request->request_no }}">
                Copy request number
            </button>
        </div>
    </div>
</article>
