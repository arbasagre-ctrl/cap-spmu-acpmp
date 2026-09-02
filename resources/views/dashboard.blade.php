@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')

@if($dashboardMode === 'BORROWER')
    @include('dashboard.partials.borrower-styles')
@endif

<style>
    .dashboard-heading { align-items: center; }
    .dashboard-kpi-card {
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(7, 27, 53, .05);
    }
    .dashboard-kpi-card:hover {
        box-shadow: 0 10px 24px rgba(7, 27, 53, .08);
    }
    .dashboard-balanced-grid > .card {
        border-radius: 12px;
    }

    .dashboard-stat-grid.dashboard-stat-grid-five {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }
    .dashboard-stat-grid.dashboard-stat-grid-five .dashboard-kpi-card {
        min-width: 0;
    }
    @media (max-width: 1300px) {
        .dashboard-stat-grid.dashboard-stat-grid-five { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .dashboard-stat-grid.dashboard-stat-grid-five { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 620px) {
        .dashboard-stat-grid.dashboard-stat-grid-five { grid-template-columns: 1fr; }
    }
    html[data-theme="dark"] .dashboard-kpi-card,
    html[data-theme="dark"] .dashboard-balanced-grid > .card {
        box-shadow: 0 1px 2px rgba(0, 0, 0, .22);
    }
    .dashboard-balanced-grid.borrower-actions-only { grid-template-columns: minmax(0, 1fr); }
</style>

@php
    $firstName = str($user->full_name)->before(' ')->value();

    $copy = match($dashboardMode) {
        'BORROWER' => [
            'eyebrow' => 'Borrower overview',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'See all ongoing requests at a glance and focus only on actions that require your attention.',
            'taskEyebrow' => 'What needs your attention',
            'taskTitle' => 'Your next actions',
        ],
        'SPMU_OFFICER' => [
            'eyebrow' => 'SPMU Action Officer',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Verify submitted requests and documents before Head review, then prepare and physically release only finally approved property.',
            'taskEyebrow' => 'Verification queue',
            'taskTitle' => 'Requests requiring verification',
        ],
        'SPMU_HEAD' => [
            'eyebrow' => 'SPMU Head / Admin',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Review submitted borrowing requests, make the final approval decision, and monitor borrowing, accountability, and inventory oversight.',
            'taskEyebrow' => 'Approval queue',
            'taskTitle' => 'Requests needing your approval decision',
        ],
        'ICTU' => [
            'eyebrow' => 'ICTU Maintainer',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Manage accounts, system settings, audit records, and failed notification deliveries without entering borrowing operations.',
            'taskEyebrow' => 'System administration',
            'taskTitle' => 'Recent account activity',
        ],
        default => [
            'eyebrow' => 'Overview',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Open the functions assigned to your account.',
            'taskEyebrow' => 'Tasks',
            'taskTitle' => 'Current activity',
        ],
    };

    $statMeta = match($dashboardMode) {
        'BORROWER' => [
            'Open Requests' => ['requests', 'info', route('requests.index')],
            'Active Borrowings' => ['custody', 'warning', route('custody.index')],
            'Due for Return' => ['calendar', 'danger', route('custody.index')],
            'Needs My Action' => ['information', 'warning', route('dashboard').'#borrower-actions'],
        ],
        'SPMU_OFFICER' => [
            'For Verification' => ['approval', 'warning', route('verifications.index')],
            'For Pickup Scheduling' => ['calendar', 'info', route('custody.index')],
            'Ready for Release' => ['custody', 'success', route('custody.index')],
            'For Return Check' => ['custody', 'warning', route('custody.index')],
            'Laundry Operations' => ['approval', 'info', route('laundry.index')],
        ],
        'SPMU_HEAD' => [
            'For Approval' => ['approval', 'warning', route('approvals.index')],
            'Approved Today' => ['success', 'success', route('requests.index')],
            'Active Borrowings' => ['custody', 'info', route('custody.index')],
            'Overdue / Issues' => ['accountability', 'danger', route('accountability.index')],
        ],
        'ICTU' => [
            'Active Accounts' => ['users', 'success', route('administration.users.index')],
            'Failed Notifications' => ['notifications', 'danger', route('reports.notifications')],
            'Active Delegations' => ['delegation', 'warning', route('administration.delegations.index')],
            'Inactive Accounts' => ['users', 'neutral', route('administration.users.index')],
        ],
        default => [],
    };
@endphp

<div class="{{ $dashboardMode === 'BORROWER' ? 'is-borrower-dashboard' : '' }}">

<section class="page-heading dashboard-heading">
    <div>
        <p class="eyebrow">{{ $copy['eyebrow'] }}</p>
        <h1>{{ $copy['title'] }}</h1>
        <p>{{ $copy['subtitle'] }}</p>
    </div>

    @if($dashboardMode === 'BORROWER')
        <a class="button primary ui-pressable" href="{{ route('requests.create') }}">
            <x-icon name="plus" size="16" />
            New borrowing request
        </a>
    @elseif($dashboardMode === 'SPMU_OFFICER')
        <a class="button primary ui-pressable" href="{{ route('verifications.index') }}">Open Verification Queue</a>
    @elseif($dashboardMode === 'SPMU_HEAD')
        <a class="button primary ui-pressable" href="{{ route('approvals.index') }}">Open Approval Queue</a>
    @elseif($dashboardMode === 'ICTU')
        <a class="button primary ui-pressable" href="{{ route('administration.users.index') }}">Manage Accounts</a>
    @endif
</section>

<section class="stat-grid dashboard-stat-grid {{ $dashboardMode === 'SPMU_OFFICER' ? 'dashboard-stat-grid-five' : '' }}" aria-label="Current totals">
    @foreach($statistics as $label => $value)
        @php
            [$icon, $tone, $link] = $statMeta[$label] ?? [
                'dashboard',
                'neutral',
                route('dashboard'),
            ];
        @endphp
        <a class="card stat-card stat-card-link kpi-card dashboard-kpi-card kpi-accent-{{ $tone }} ui-pressable" href="{{ $link }}">
            <span class="kpi-icon" aria-hidden="true"><x-icon :name="$icon" size="18" /></span>
            <strong class="kpi-value">{{ number_format($value) }}</strong>
            <span class="kpi-label">{{ $label }}</span>
            <span class="stat-card-arrow" aria-hidden="true"><x-icon name="chevron-right" /></span>
        </a>
    @endforeach
</section>

@if($dashboardMode === 'BORROWER')
    <article class="borrower-dash-card" aria-labelledby="active-requests-title">
        <div class="card-header">
            <div>
                <h2 id="active-requests-title">Active requests</h2>
                <p class="meta">Ongoing requests are prioritized by urgency. Open My Requests for complete tracking and history.</p>
            </div>
            <a class="dashboard-view-all" href="{{ route('requests.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
        </div>

        @if($activeRequestBars->isNotEmpty())
            <div class="borrower-active-scroll">
                <table class="borrower-active-table">
                    <thead>
                        <tr>
                            <th scope="col">Request ID</th>
                            <th scope="col">Event / Charter</th>
                            <th scope="col">Status</th>
                            <th scope="col">Pickup schedule</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                @foreach($activeRequestBars as $activeRequest)
                    @php
                        $activeCustody = $activeRequest->custody;
                        $activeCustodyStatus = strtoupper((string) ($activeCustody?->status ?? ''));

                        [$activeStateLabel, $activeStateTone] = match (true) {
                            $activeCustodyStatus === 'OVERDUE' => ['Overdue', 'danger'],
                            $activeCustodyStatus === 'OBLIGATION_OPEN' => ['Obligation Open', 'danger'],
                            $activeCustodyStatus === 'INCIDENT_OPEN' => ['Property Case Open', 'danger'],
                            in_array($activeCustodyStatus, ['RETURN_PROCESSING', 'PARTIALLY_RETURNED'], true) => ['Return Processing', 'warning'],
                            $activeCustody?->released_at !== null && $activeCustodyStatus !== 'CLOSED' => ['Items Released', 'success'],
                            $activeRequest->status === App\Enums\RequestStatus::ReturnedForRevision => ['Revision Required', 'warning'],
                            $activeCustody?->scheduled_release_at !== null && $activeCustody?->released_at === null => ['Pickup Scheduled', 'info'],
                            $activeCustodyStatus === 'PREPARING_RELEASE' => ['Preparing Release', 'info'],
                            in_array($activeRequest->status, [App\Enums\RequestStatus::FinalApprovedAwaitingDownload, App\Enums\RequestStatus::ApprovedReadyForRelease], true) => ['Approved', 'success'],
                            $activeRequest->status === App\Enums\RequestStatus::UnderSpmu => ['Under SPMU Review', 'info'],
                            in_array($activeRequest->status, [App\Enums\RequestStatus::Submitted, App\Enums\RequestStatus::Signed], true) => ['Submitted', 'info'],
                            $activeRequest->status === App\Enums\RequestStatus::Draft => ['Draft', 'neutral'],
                            default => [$activeRequest->status?->label() ?? 'In Progress', 'neutral'],
                        };

                        [$activeDateLabel, $activeDateValue] = match (true) {
                            $activeCustody?->released_at !== null && $activeCustody?->due_at !== null => ['Return due', $activeCustody->due_at->format('d M Y')],
                            $activeCustody?->scheduled_release_at !== null && $activeCustody?->released_at === null => ['Pickup', $activeCustody->scheduled_release_at->format('d M Y, g:i A')],
                            $activeRequest->currentVersion?->return_date !== null => ['Expected return', $activeRequest->currentVersion->return_date->format('d M Y')],
                            $activeRequest->currentVersion?->needed_from !== null => ['Needed from', $activeRequest->currentVersion->needed_from->format('d M Y')],
                            default => ['Updated', $activeRequest->updated_at->format('d M Y')],
                        };

                        $activeActionLabel = in_array($activeRequest->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true)
                            ? 'Continue'
                            : 'View';
                    @endphp
                    <tr>
                        <td class="borrower-active-cell-id">
                            <span class="borrower-active-id">{{ $activeRequest->request_no }}</span>
                        </td>

                        <td data-label="Event / Charter">
                            <span class="borrower-active-purpose">{{ $activeRequest->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                        </td>

                        <td data-label="Status">
                            <span class="status-badge status-{{ $activeStateTone }}">{{ $activeStateLabel }}</span>
                        </td>

                        <td class="borrower-active-schedule" data-label="Pickup schedule">
                            <strong>{{ $activeDateValue }}</strong>
                            <small>{{ $activeDateLabel }}</small>
                        </td>

                        <td data-label="Action">
                            <a
                                class="button secondary small ui-pressable borrower-active-action"
                                href="{{ route('requests.show', $activeRequest) }}"
                            >
                                {{ $activeActionLabel }}
                            </a>
                        </td>
                    </tr>
                @endforeach
                    </tbody>
                </table>
            </div>

            @if($activeRequestTotal > $activeRequestBars->count())
                <p class="borrower-active-overflow">
                    {{ $activeRequestTotal - $activeRequestBars->count() }} more ongoing {{ ($activeRequestTotal - $activeRequestBars->count()) === 1 ? 'request is' : 'requests are' }} available in My Requests.
                </p>
            @endif
        @else
            <div class="borrower-dash-empty">
                <x-icon name="requests" size="26" />
                <strong>No active borrowing request.</strong>
                <span>Start a new borrowing request when you need SPMU items.</span>
            </div>
        @endif
    </article>
@endif

<section id="{{ $dashboardMode === 'BORROWER' ? 'borrower-actions' : 'dashboard-actions' }}" class="dashboard-grid dashboard-balanced-grid {{ $dashboardMode === 'BORROWER' ? 'borrower-actions-only' : '' }}">
    <article class="card queue-card dashboard-panel-equal {{ $dashboardMode === 'BORROWER' ? 'borrower-dash-card' : '' }}">
        <div class="card-header">
            <div>
                @if($dashboardMode === 'BORROWER')
                    <h2>{{ $copy['taskTitle'] }}</h2>
                    <p class="meta">Actions that need your attention to keep your requests moving.</p>
                @else
                    <p class="eyebrow">{{ $copy['taskEyebrow'] }}</p>
                    <h2>{{ $copy['taskTitle'] }}</h2>
                @endif
            </div>
            @if($dashboardMode === 'SPMU_OFFICER')
                <a class="dashboard-view-all" href="{{ route('verifications.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @elseif($dashboardMode === 'SPMU_HEAD')
                <a class="dashboard-view-all" href="{{ route('approvals.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @endif
        </div>

        <div class="queue-list {{ $dashboardMode === 'BORROWER' ? 'borrower-next-list' : '' }}">
            @forelse($queue as $record)
                @if($dashboardMode === 'BORROWER')
                    @php
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;
                        $nextAction = match(true) {
                            $record->status === App\Enums\RequestStatus::Draft => 'Complete the draft and submit the required signed documents to SPMU.',
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => 'Review the SPMU remarks, correct the request, and resubmit.',
                            $record->status === App\Enums\RequestStatus::UnderSpmu => 'No action now. Your request is under SPMU review.',
                            $custody?->status === 'CLOSED' && $laundry?->status === 'TURNED_OVER_TO_LAUNDRY' => 'Completed. Your linen turnover is settled; actual washing now continues internally in the Laundry Area.',
                            $custody?->status === 'CLOSED' => 'Completed. No further borrower action is required.',
                            $laundry?->status === 'FOR_LAUNDRY' && $laundry->hasVerifiedAccomplishedForm() => 'Your linen was received and signed for at the Laundry Area. SPMU is verifying the accomplished Laundry Form; no further borrower action is required for the linen.',
                            $laundry?->status === 'FOR_LAUNDRY' => 'Return the linen to the Laundry Area first with the same printed Laundry Form. Laundry Personnel checks the quantity and condition and wet-signs Received by, then you bring the accomplished form to SPMU.',
                            $laundry?->status === 'TURNED_OVER_TO_LAUNDRY' => 'Your linen turnover is complete. Any remaining non-laundry obligation is still being resolved.',
                            in_array($laundry?->status, ['IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true) => 'No borrower action is required while SPMU aligns this Laundry record to the simplified turnover workflow.',
                            $custody?->scheduled_release_at && ! $custody?->released_at => 'Pick up the approved items on the scheduled pickup window.',
                            $custody?->released_at && $custody?->status !== 'CLOSED' => 'Keep track of the return deadline and return the items to SPMU.',
                            default => 'Wait for the next SPMU update.',
                        };

                        $actionLabel = in_array($record->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true)
                            ? 'Continue'
                            : 'View';

                        /* Presentation only: an icon and a date drawn from the
                           same record state that already produced $nextAction. */
                        [$actionIcon, $actionTone] = match (true) {
                            $record->status === App\Enums\RequestStatus::Draft => ['edit', 'warning'],
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => ['warning', 'danger'],
                            $record->status === App\Enums\RequestStatus::UnderSpmu => ['clock', 'info'],
                            $custody?->status === 'CLOSED' => ['check-circle', 'success'],
                            $laundry !== null => ['cycle', 'info'],
                            $custody?->scheduled_release_at && ! $custody?->released_at => ['calendar', 'info'],
                            $custody?->released_at => ['custody', 'warning'],
                            default => ['information', 'info'],
                        };

                        $actionWhen = match (true) {
                            $custody?->scheduled_release_at !== null && $custody?->released_at === null
                                => $custody->scheduled_release_at->format('d M Y, g:i A'),
                            $custody?->released_at !== null && $custody?->due_at !== null
                                => $custody->due_at->format('d M Y'),
                            $record->currentVersion?->return_date !== null
                                => $record->currentVersion->return_date->format('d M Y'),
                            default => null,
                        };
                    @endphp
                    <article class="borrower-next-row">
                        <span class="borrower-next-icon tone-{{ $actionTone }}" aria-hidden="true">
                            <x-icon :name="$actionIcon" size="17" />
                        </span>

                        <div class="borrower-next-copy">
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                            <small>{{ $nextAction }}</small>
                        </div>

                        @if($actionWhen)
                            <span class="borrower-next-when">{{ $actionWhen }}</span>
                        @else
                            <span></span>
                        @endif

                        <a class="button secondary small ui-pressable borrower-active-action" href="{{ route('requests.show', $record) }}">{{ $actionLabel }}</a>
                    </article>
                @elseif($dashboardMode === 'SPMU_OFFICER')
                    <article>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Verify the request and required supporting documents, or return it for correction.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}">Verify</a>
                    </article>
                @elseif($dashboardMode === 'SPMU_HEAD')
                    <article>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Review the signed request, required documents, quantities, dates, and current availability.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}">Review</a>
                    </article>
                @elseif($dashboardMode === 'ICTU')
                    <article>
                        <div>
                            <strong>{{ $record->full_name }}</strong>
                            <span>{{ $record->access_classification?->label() ?: 'User account' }}</span>
                            <small>{{ $record->email }}{{ $record->organizationalUnit ? ' · '.$record->organizationalUnit->unit_name : '' }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('administration.users.edit', $record) }}">Manage</a>
                    </article>
                @endif
            @empty
                @if($dashboardMode === 'BORROWER')
                    <div class="borrower-dash-empty">
                        <x-icon name="check-circle" size="26" />
                        <strong>You're all caught up.</strong>
                        <span>No requests currently require your action.</span>
                    </div>
                @else
                    <div class="empty-state">
                        <strong>Nothing needs attention right now.</strong>
                        <span>The next required action will appear here automatically.</span>
                    </div>
                @endif
            @endforelse
        </div>
    </article>

    @if($dashboardMode !== 'BORROWER')
    <article class="card dashboard-panel-equal">
        @if($dashboardMode === 'SPMU_OFFICER')
            <div class="card-header"><div><p class="eyebrow">Action Officer workflow</p><h2>Verification through release</h2></div></div>
            <div class="workflow-mini-list">
                <span><strong>1</strong> Verify the submitted request and required documents</span>
                <span><strong>2</strong> Route VERIFIED requests to the SPMU Head for decision</span>
                <span><strong>3</strong> After approval, schedule pickup and validate the generated documents</span>
                <span><strong>4</strong> Prepare and physically release the approved items</span>
                <span><strong>5</strong> Monitor custody and receive returns</span>
                <span><strong>6</strong> Keep Laundry and final reconciliation in their separate existing paths</span>
            </div>
        @elseif($dashboardMode === 'SPMU_HEAD')
            <div class="card-header"><div><p class="eyebrow">Approval authority</p><h2>Head responsibility</h2></div></div>
            <div class="workflow-mini-list">
                <span><strong>1</strong> Review the submitted request and signed documents</span>
                <span><strong>2</strong> Check requested quantities, dates, and current availability</span>
                <span><strong>3</strong> Approve & reserve, return for revision, or reject</span>
                <span><strong>4</strong> Monitor custody, issues, and inventory oversight</span>
            </div>
            <p class="meta top-gap">After approval, pickup scheduling, Gate Pass preparation, physical release, return inspection, and applicable Laundry final acceptance move to the Action Officer.</p>
        @elseif($dashboardMode === 'ICTU')
            <div class="card-header"><div><p class="eyebrow">Technical scope</p><h2>ICTU responsibility</h2></div></div>
            <div class="workflow-mini-list">
                <span><strong>1</strong> Manage active user accounts</span>
                <span><strong>2</strong> Maintain system settings</span>
                <span><strong>3</strong> Review audit trail</span>
                <span><strong>4</strong> Monitor email/SMS delivery records</span>
            </div>
            <p class="meta top-gap">ICTU does not approve borrowing, release items, inspect returns, or process laundry.</p>
        @endif
    </article>
    @endif
</section>

</div>
@endsection
