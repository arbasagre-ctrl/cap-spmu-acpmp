@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')

@if($dashboardMode === 'BORROWER')
    @include('dashboard.partials.borrower-styles')
@endif

<style>
    .dashboard-heading { align-items: center; }

    /* Universal dashboard summary cards — aligned with Accountability. */
    .dashboard-stat-grid {
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:12px;
        width:100%;
    }
    .dashboard-kpi-card {
        --dashboard-card-accent: var(--info);
        --dashboard-card-hover: rgba(23,105,224,.075);
        position:relative;
        min-width:0;
        display:grid;
        grid-template-columns:46px minmax(0,1fr);
        grid-template-rows:auto auto auto;
        column-gap:12px;
        row-gap:2px;
        min-height:122px;
        padding:16px 18px;
        border:1px solid var(--border);
        border-top:3px solid var(--dashboard-card-accent);
        border-radius:12px;
        background:var(--surface);
        color:inherit;
        text-decoration:none;
        box-shadow:0 1px 2px rgba(7,27,53,.05);
        transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
    }
    .dashboard-kpi-card.kpi-accent-info {
        --dashboard-card-accent:var(--info);
        --dashboard-card-hover:rgba(23,105,224,.075);
    }
    .dashboard-kpi-card.kpi-accent-warning {
        --dashboard-card-accent:var(--warning);
        --dashboard-card-hover:rgba(217,155,22,.085);
    }
    .dashboard-kpi-card.kpi-accent-danger {
        --dashboard-card-accent:var(--danger);
        --dashboard-card-hover:rgba(220,53,69,.070);
    }
    .dashboard-kpi-card.kpi-accent-success {
        --dashboard-card-accent:var(--success);
        --dashboard-card-hover:rgba(21,148,71,.070);
    }
    .dashboard-kpi-card.kpi-accent-neutral {
        --dashboard-card-accent:var(--border-strong);
        --dashboard-card-hover:rgba(89,105,123,.035);
    }
    .dashboard-kpi-card:hover,
    .dashboard-kpi-card:focus-visible {
        transform:translateY(-1px);
        background:linear-gradient(var(--dashboard-card-hover),var(--dashboard-card-hover)),var(--surface);
        border-color:var(--border-strong);
        border-top-color:var(--dashboard-card-accent);
        box-shadow:0 10px 24px rgba(7,27,53,.08);
    }
    .dashboard-kpi-card:focus-visible {
        outline:none;
        box-shadow:var(--focus-ring),0 10px 24px rgba(7,27,53,.08);
    }
    .dashboard-kpi-card .kpi-icon {
        grid-column:1;
        grid-row:1 / span 3;
        display:grid;
        width:44px;
        height:44px;
        place-items:center;
        align-self:center;
        margin:0;
        border:1px solid var(--border);
        border-radius:50%;
        background:var(--surface-subtle);
        color:var(--text-secondary);
    }
    .dashboard-kpi-card.kpi-accent-info .kpi-icon {
        border-color:#cfe0f3;
        background:#edf5fd;
        color:#1d6fb8;
    }
    .dashboard-kpi-card.kpi-accent-warning .kpi-icon {
        border-color:#ead5ab;
        background:#fff8e9;
        color:#a66a06;
    }
    .dashboard-kpi-card.kpi-accent-danger .kpi-icon {
        border-color:#f1c6c2;
        background:#fff1ef;
        color:#c4493d;
    }
    .dashboard-kpi-card.kpi-accent-success .kpi-icon {
        border-color:#c3e5d0;
        background:#eefaf3;
        color:#159447;
    }
    .dashboard-kpi-card.kpi-accent-neutral .kpi-icon {
        border-color:var(--border);
        background:var(--surface-subtle);
        color:var(--text-muted);
    }
    .dashboard-kpi-card .kpi-label {
        grid-column:2;
        grid-row:1;
        align-self:end;
        color:var(--text-secondary);
        font-size:11px;
        font-weight:800;
    }
    .dashboard-kpi-card .kpi-value {
        grid-column:2;
        grid-row:2;
        color:var(--heading);
        font-size:25px;
        line-height:1.15;
    }
    .dashboard-kpi-card .kpi-note {
        grid-column:2;
        grid-row:3;
        color:var(--text-muted);
        font-size:10px;
        line-height:1.35;
    }
    .dashboard-kpi-card .stat-card-arrow {
        position:absolute;
        top:14px;
        right:14px;
        color:var(--text-soft);
        transition:opacity .16s ease, transform .16s ease;
    }
    .dashboard-kpi-card:hover .stat-card-arrow,
    .dashboard-kpi-card:focus-visible .stat-card-arrow {
        opacity:.9;
        transform:translateX(2px);
    }

    .dashboard-balanced-grid > .card { border-radius:12px; }
    .dashboard-balanced-grid.borrower-actions-only { grid-template-columns:minmax(0,1fr); }
    .dashboard-balanced-grid.dashboard-single-panel { grid-template-columns:minmax(0,1fr); }
    .dashboard-single-panel > .dashboard-panel-equal { min-height:0; height:auto; }

    .dashboard-role-section-note {
        margin-top:4px;
        max-width:760px;
        color:var(--text-muted);
    }

    @media (max-width:1300px) {
        .dashboard-stat-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:680px) {
        .dashboard-stat-grid { grid-template-columns:1fr; }
    }
    html[data-theme="dark"] .dashboard-kpi-card:hover,
    html[data-theme="dark"] .dashboard-kpi-card:focus-visible { background:var(--surface-hover); }
    html[data-theme="dark"] .dashboard-kpi-card,
    html[data-theme="dark"] .dashboard-balanced-grid > .card { box-shadow:0 1px 2px rgba(0,0,0,.22); }
</style>

@php
    $firstName = str($user->full_name)->before(' ')->value();

    $copy = match($dashboardMode) {
        'BORROWER' => [
            'eyebrow' => 'Borrower Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Monitor your borrowing requests, scheduled pickup and return requirements, and outstanding obligations.',
            'taskEyebrow' => 'Required Actions',
            'taskTitle' => 'Actions Requiring Your Attention',
            'taskDescription' => 'Only transactions requiring a direct borrower response are listed in this section.',
        ],
        'SPMU_OFFICER' => [
            'eyebrow' => 'SPMU Action Officer Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Process requests pending verification and monitor approved transactions requiring release, return, or accountability action.',
            'taskEyebrow' => 'Operational Action Queue',
            'taskTitle' => 'Requests Awaiting SPMU Verification',
            'taskDescription' => 'Release, return, laundry, and accountability workloads are available through the summary cards above.',
        ],
        'SPMU_HEAD' => [
            'eyebrow' => 'SPMU Head / Administrator Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Review requests pending final approval and monitor active custody, accountability cases, borrowing restrictions, and inventory oversight.',
            'taskEyebrow' => 'Administrative Action Queue',
            'taskTitle' => 'Requests Awaiting Final Approval',
            'taskDescription' => 'Accountability and restriction matters requiring administrative oversight are available through the summary cards above.',
        ],
        'ICTU' => [
            'eyebrow' => 'ICTU System Administrator Dashboard',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Administer user accounts, system configuration, audit records, delegations, and notification delivery exceptions.',
            'taskEyebrow' => 'System Administration',
            'taskTitle' => 'Recent Account Activity',
            'taskDescription' => 'Use the summary cards for account status, notification exceptions, and active delegation review.',
        ],
        default => [
            'eyebrow' => 'Overview',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Open the functions assigned to your account.',
            'taskEyebrow' => 'Tasks',
            'taskTitle' => 'Current Activity',
            'taskDescription' => 'Records requiring action under the assigned role are listed in this section.',
        ],
    };

    $statMeta = match($dashboardMode) {
        'BORROWER' => [
            'Open Requests' => ['requests', 'info', route('requests.index'), 'Requests still in progress'],
            'Active Borrowings' => ['custody', 'info', route('custody.index', ['kpi' => 'active_borrowings']), 'Released property currently on custody'],
            'Upcoming Pickup' => ['calendar', 'warning', route('custody.index', ['kpi' => 'upcoming_pickup']), 'Approved transactions scheduled for pickup'],
            'Due for Return' => ['calendar', 'danger', route('custody.index', ['kpi' => 'returns_due']), 'Borrowings due for return'],
            'Returns Due' => ['calendar', 'danger', route('custody.index', ['kpi' => 'returns_due']), 'Borrowings due for return'],
            'Needs My Action' => ['warning', 'warning', route('dashboard').'#borrower-actions', 'Transactions requiring your response'],
            'Active Obligations' => ['warning', 'warning', route('accountability.index'), 'Outstanding accountability obligations'],
        ],
        'SPMU_OFFICER' => [
            'For Verification' => ['approval', 'warning', route('verifications.index'), 'Requests awaiting SPMU verification'],
            'For Pickup Scheduling' => ['calendar', 'info', route('custody.index'), 'Approved transactions requiring a pickup schedule'],
            'For Release' => ['custody', 'info', route('custody.release.index'), 'Transactions requiring release processing'],
            'Ready for Release' => ['custody', 'info', route('custody.index'), 'Approved property ready for physical release'],
            'For Return' => ['calendar', 'warning', route('custody.return.index'), 'Transactions with physically outstanding property to return'],
            'For Return Check' => ['calendar', 'warning', route('custody.return.index'), 'Transactions with physically outstanding property to return'],
            'Accountability Cases' => ['accountability', 'danger', route('accountability.index'), 'Open accountability matters requiring processing or follow-up'],
            'Laundry Operations' => ['approval', 'info', route('laundry.index'), 'Linen transactions requiring SPMU processing'],
        ],
        'SPMU_HEAD' => [
            'For Approval' => ['approval', 'warning', route('approvals.index'), 'Requests awaiting final approval'],
            'Requests for Approval' => ['approval', 'warning', route('approvals.index'), 'Requests awaiting final approval'],
            'Approved Today' => ['success', 'success', route('requests.index'), 'Requests approved during the current day'],
            'Active Borrowings' => ['custody', 'info', route('custody.index'), 'Released property currently on custody'],
            'Active Custodies' => ['custody', 'info', route('custody.index'), 'Ongoing custody transactions'],
            'Open Accountability Cases' => ['accountability', 'danger', route('accountability.index'), 'Unresolved accountability matters'],
            'Active Restrictions' => ['warning', 'warning', route('accountability.index', ['view' => 'restrictions']).'#accountability-cases-section', 'Borrowing restrictions currently in effect'],
            'Overdue / Issues' => ['accountability', 'danger', route('accountability.index'), 'Transactions requiring accountability oversight'],
        ],
        'ICTU' => [
            'Active Accounts' => ['users', 'success', route('administration.users.index'), 'User accounts currently enabled'],
            'Failed Notifications' => ['notifications', 'danger', route('reports.notifications'), 'Delivery records requiring technical review'],
            'Active Delegations' => ['delegation', 'warning', route('administration.delegations.index'), 'Temporary delegations currently in effect'],
            'Inactive Accounts' => ['users', 'neutral', route('administration.users.index'), 'User accounts currently inactive'],
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
            Create Borrowing Request
        </a>
    @elseif($dashboardMode === 'SPMU_OFFICER')
        <a class="button primary ui-pressable" href="{{ route('verifications.index') }}"><span>Open Verification Queue</span><x-icon name="arrow-right" size="15" /></a>
    @elseif($dashboardMode === 'SPMU_HEAD')
        <a class="button primary ui-pressable" href="{{ route('approvals.index') }}"><span>Open Approval Queue</span><x-icon name="arrow-right" size="15" /></a>
    @elseif($dashboardMode === 'ICTU')
        <a class="button primary ui-pressable" href="{{ route('administration.users.index') }}"><span>Manage User Accounts</span><x-icon name="arrow-right" size="15" /></a>
    @endif
</section>

<section class="stat-grid dashboard-stat-grid" aria-label="Current totals">
    @foreach($statistics as $label => $value)
        @php
            [$icon, $tone, $link, $note] = $statMeta[$label] ?? [
                'dashboard',
                'neutral',
                route('dashboard'),
                'Current system total',
            ];

            /* Keep each dashboard metric's role color visible even when its
               current total is zero. This matches the Head/Admin dashboard and
               keeps the same visual language across every role. */
            $effectiveTone = $tone;
        @endphp
        <a class="card stat-card stat-card-link kpi-card dashboard-kpi-card kpi-accent-{{ $effectiveTone }} ui-pressable" href="{{ $link }}">
            <span class="kpi-icon" aria-hidden="true"><x-icon :name="$icon" size="18" /></span>
            <span class="kpi-label">{{ $label }}</span>
            <strong class="kpi-value">{{ number_format($value) }}</strong>
            <span class="kpi-note">{{ $note }}</span>
            @unless($dashboardMode === 'BORROWER')
                <span class="stat-card-arrow" aria-hidden="true"><x-icon name="arrow-right" /></span>
            @endunless
        </a>
    @endforeach
</section>

<section id="{{ $dashboardMode === 'BORROWER' ? 'borrower-actions' : 'dashboard-actions' }}" class="dashboard-grid dashboard-balanced-grid dashboard-single-panel {{ $dashboardMode === 'BORROWER' ? 'borrower-actions-only' : '' }}">
    <article class="card queue-card dashboard-panel-equal {{ $dashboardMode === 'BORROWER' ? 'borrower-dash-card' : '' }}">
        <div class="card-header">
            <div>
                @if($dashboardMode === 'BORROWER')
                    <h2>{{ $copy['taskTitle'] }}</h2>
                    <p class="meta">{{ $copy['taskDescription'] }}</p>
                @else
                    <p class="eyebrow">{{ $copy['taskEyebrow'] }}</p>
                    <h2>{{ $copy['taskTitle'] }}</h2>
                    <p class="meta dashboard-role-section-note">{{ $copy['taskDescription'] }}</p>
                @endif
            </div>
            @if($dashboardMode === 'SPMU_OFFICER')
                <a class="dashboard-view-all" href="{{ route('verifications.index') }}">View all <x-icon name="arrow-right" size="16" /></a>
            @elseif($dashboardMode === 'SPMU_HEAD')
                <a class="dashboard-view-all" href="{{ route('approvals.index') }}">View all <x-icon name="arrow-right" size="16" /></a>
            @endif
        </div>

        <div class="queue-list {{ $dashboardMode === 'BORROWER' ? 'borrower-next-list' : '' }}">
            @forelse($queue as $record)
                @if($dashboardMode === 'BORROWER')
                    @php
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;
                        $pickupMissed = $custody?->released_at === null
                            && (
                                $custody?->pickup_expired_at !== null
                                || ($custody?->pickup_expires_at !== null && now()->gt($custody->pickup_expires_at))
                            );
                        $hasOutstandingProperty = $custody?->hasOutstandingProperty() ?? false;
                        $outstandingLines = $custody?->lines?->filter(
                            fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
                        ) ?? collect();
                        $hasOutstandingLinen = $outstandingLines->contains(
                            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                        );
                        $hasOutstandingNonLinen = $outstandingLines->contains(
                            fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
                        );
                        $hasAccountabilityMatter = $custody?->activeAccountabilityIndicator() !== null;

                        $nextAction = match(true) {
                            $record->status === App\Enums\RequestStatus::Draft => 'Complete the draft and submit the required signed documents to SPMU.',
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => 'Review the SPMU remarks, correct the request, and resubmit.',
                            $record->status === App\Enums\RequestStatus::UnderSpmu => 'No borrower action is required while the request remains under SPMU review.',
                            $custody?->status === 'CLOSED' && $laundry?->status === 'TURNED_OVER_TO_LAUNDRY' => 'No further borrower action is required. Linen turnover has been recorded and subsequent laundry processing is handled internally.',
                            $custody?->status === 'CLOSED' => 'Completed. No further borrower action is required.',
                            $pickupMissed => 'The pickup window was missed. Open the request to ask for a valid reschedule, when available, or cancel the unreleased request.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingLinen && $hasOutstandingNonLinen => 'Return all outstanding non-linen property to SPMU and the overdue linen to the Laundry Area immediately. Any late-return assessment will continue in My Obligations after the physical returns are recorded.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingLinen => 'Return the overdue linen and the same printed Laundry Form to the Laundry Area immediately. Laundry RECEIVED BY is the physical return date used for timeliness.',
                            $custody?->status === 'OVERDUE' && $hasOutstandingProperty => 'Return all outstanding property to SPMU immediately. Any late-return assessment will continue in My Obligations after the physical return is recorded.',
                            $laundry?->status === 'FOR_LAUNDRY' && $laundry->hasVerifiedAccomplishedForm() => 'No further borrower action is required for the linen. SPMU is verifying the accomplished Laundry Form received from the Laundry Area.',
                            $laundry?->status === 'FOR_LAUNDRY' => 'Return the linen and the same printed Laundry Form to the Laundry Area. Laundry Personnel will record the receipt and later deliver the accomplished form directly to SPMU; you are not responsible for that document handoff.',
                            $laundry?->status === 'TURNED_OVER_TO_LAUNDRY' => 'Linen turnover has been completed. Any remaining non-linen accountability matter continues under the applicable SPMU process.',
                            in_array($laundry?->status, ['IN_PROCESS', 'READY_FOR_SPMU_RETURN', 'AWAITING_FINAL_FORM_UPLOAD', 'FORM_REPLACEMENT_REQUIRED'], true) => 'No borrower action is required while SPMU completes the applicable laundry record processing.',
                            $hasAccountabilityMatter && $hasOutstandingProperty => 'Return any still-outstanding property and review My Obligations for the linked accountability requirement.',
                            $hasAccountabilityMatter => 'Review My Obligations and complete the required accountability, payment, repair, replacement, or compliance step shown there.',
                            $custody?->scheduled_release_at && ! $custody?->released_at => 'Claim the approved items within the confirmed pickup schedule.',
                            $custody?->released_at && $custody?->status !== 'CLOSED' => 'Return the issued property to SPMU on or before the recorded due date.',
                            default => 'Await the next official SPMU status update.',
                        };

                        $actionLabel = match (true) {
                            in_array($record->status, [App\Enums\RequestStatus::Draft, App\Enums\RequestStatus::ReturnedForRevision], true) => 'Continue',
                            $hasAccountabilityMatter => 'View Obligations',
                            $custody?->status === 'OVERDUE' => 'View Return',
                            default => 'View',
                        };
                        $actionHref = $hasAccountabilityMatter
                            ? route('accountability.index')
                            : ($custody?->status === 'OVERDUE'
                                ? route('custody.show', $custody)
                                : route('requests.show', $record));

                        /* Presentation only: an icon and a date drawn from the
                           same record state that already produced $nextAction. */
                        [$actionIcon, $actionTone] = match (true) {
                            $record->status === App\Enums\RequestStatus::Draft => ['edit', 'warning'],
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => ['warning', 'danger'],
                            $record->status === App\Enums\RequestStatus::UnderSpmu => ['clock', 'info'],
                            $custody?->status === 'CLOSED' => ['check-circle', 'success'],
                            $pickupMissed => ['clock', 'warning'],
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

                        {{--
                            View Obligations must reuse the exact same
                            action pattern as View Request/View Record
                            elsewhere - the bespoke borrower-active-action
                            skin is skipped for it so nothing here can
                            diverge from that universal look, and the
                            button sizes to its own label instead of a
                            fixed width that a longer label can overflow.
                        --}}
                        <a class="button secondary small ui-pressable {{ $hasAccountabilityMatter ? '' : 'borrower-active-action' }}" href="{{ $actionHref }}"><span>{{ $actionLabel }}</span><x-icon name="arrow-right" size="14" /></a>
                    </article>
                @elseif($dashboardMode === 'SPMU_OFFICER')
                    <article>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Verify the request and required supporting documents, or return it for revision.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}"><span>Verify</span><x-icon name="arrow-right" size="14" /></a>
                    </article>
                @elseif($dashboardMode === 'SPMU_HEAD')
                    <article>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>Review the verified request, required documents, approved quantities, borrowing period, and current availability before recording the final decision.</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('requests.show', $record) }}"><span>Review</span><x-icon name="arrow-right" size="14" /></a>
                    </article>
                @elseif($dashboardMode === 'ICTU')
                    <article>
                        <div>
                            <strong>{{ $record->full_name }}</strong>
                            <span>{{ $record->access_classification?->label() ?: 'User account' }}</span>
                            <small>{{ $record->email }}{{ $record->organizationalUnit ? ' · '.$record->organizationalUnit->unit_name : '' }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('administration.users.edit', $record) }}"><span>Manage</span><x-icon name="arrow-right" size="14" /></a>
                    </article>
                @endif
            @empty
                @if($dashboardMode === 'BORROWER')
                    @if($borrowerRestrictionActions->isEmpty())
                        <div class="borrower-dash-empty">
                            <x-icon name="check-circle" size="26" />
                            <strong>No current action is required.</strong>
                            <span>No borrowing transaction currently requires your response.</span>
                        </div>
                    @endif
                @else
                    <div class="empty-state">
                        <strong>No records currently require action.</strong>
                        <span>Records requiring action under your assigned role will appear here automatically.</span>
                    </div>
                @endif
            @endforelse

            @if($dashboardMode === 'BORROWER')
                @foreach($borrowerRestrictionActions as $obligationRow)
                    <article class="borrower-next-row">
                        <span class="borrower-next-icon tone-warning" aria-hidden="true">
                            <x-icon name="lock" size="17" />
                        </span>

                        <div class="borrower-next-copy">
                            <strong>{{ $obligationRow['type'] }}</strong>
                            <span>{{ $obligationRow['summary'] }}</span>
                            <small>{{ $obligationRow['next_action'] }}</small>
                        </div>

                        <span class="borrower-next-when">{{ $obligationRow['badge'] }}</span>

                        <a class="button secondary small ui-pressable" href="{{ route('accountability.index') }}"><span>View Obligation</span><x-icon name="arrow-right" size="14" /></a>
                    </article>
                @endforeach
            @endif
        </div>
    </article>

</section>

@if($dashboardMode === 'BORROWER')
    <article class="borrower-dash-card" aria-labelledby="active-requests-title">
        <div class="card-header">
            <div>
                <h2 id="active-requests-title">Current Borrowing Requests</h2>
                <p class="meta">Current requests are listed by priority. Open My Requests for complete status and transaction history.</p>
            </div>
            <a class="dashboard-view-all" href="{{ route('requests.index') }}">View all <x-icon name="arrow-right" size="16" /></a>
        </div>

        @if($activeRequestBars->isNotEmpty())
            <div class="borrower-active-scroll">
                <table class="borrower-active-table">
                    <thead>
                        <tr>
                            <th scope="col">Request ID</th>
                            <th scope="col">Event / Charter</th>
                            <th scope="col">Status</th>
                            <th scope="col">Schedule / Due Date</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                @foreach($activeRequestBars as $activeRequest)
                    @php
                        $activeCustody = $activeRequest->custody;
                        $activeCustodyStatus = strtoupper((string) ($activeCustody?->status ?? ''));
                        $activeAccountability = $activeCustody?->activeAccountabilityIndicator();
                        $activeWorkflow = $activeCustody?->workflowStatus();

                        /*
                         * Same key/label resolution as My Requests
                         * (requests/partials/my-requests-row.blade.php), so a
                         * request in a given state never reads differently on
                         * the two pages.
                         */
                        if ($activeWorkflow) {
                            [$activeStateKey, $activeStateLabel] = match ($activeWorkflow['key']) {
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
                                default => [$activeWorkflow['key'], $activeWorkflow['label']],
                            };
                        } else {
                            [$activeStateKey, $activeStateLabel] = match ($activeRequest->status) {
                                App\Enums\RequestStatus::Draft => ['DRAFT', 'Draft'],
                                App\Enums\RequestStatus::ReturnedForRevision => ['RETURNED_FOR_REVISION', 'Returned for Revision'],
                                App\Enums\RequestStatus::UnderSpmu,
                                App\Enums\RequestStatus::UnderGsu,
                                App\Enums\RequestStatus::UnderVpaf => ['UNDER_SPMU', 'Under SPMU Review'],
                                App\Enums\RequestStatus::ApprovedReadyForRelease => ['APPROVED_READY_FOR_RELEASE', 'Ready for Release'],
                                App\Enums\RequestStatus::FinalApprovedAwaitingDownload => ['FINAL_APPROVED_AWAITING_DOWNLOAD', 'Approved'],
                                App\Enums\RequestStatus::Rejected => ['REJECTED', 'Rejected'],
                                App\Enums\RequestStatus::Cancelled => ['CANCELLED', 'Cancelled'],
                                App\Enums\RequestStatus::Expired => ['INACTIVE', 'Inactive'],
                                default => ['SUBMITTED', 'In Progress'],
                            };
                        }

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
                            <x-status-badge :status="$activeStateKey" :label="$activeStateLabel" />
                            @if($activeAccountability)
                                <small class="borrower-active-obligation">{{ $activeAccountability['label'] }}</small>
                            @endif
                        </td>

                        <td class="borrower-active-schedule" data-label="Schedule / Due Date">
                            <strong>{{ $activeDateValue }}</strong>
                            <small>{{ $activeDateLabel }}</small>
                        </td>

                        <td data-label="Action">
                            <a
                                class="button secondary small ui-pressable borrower-active-action"
                                href="{{ route('requests.show', $activeRequest) }}"
                            >
                                <span>{{ $activeActionLabel }}</span><x-icon name="arrow-right" size="14" />
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
                <strong>No other active requests to monitor.</strong>
                <span>Requests that need your response are shown above under Actions Requiring Your Attention.</span>
            </div>
        @endif
    </article>
@endif

</div>
@endsection
