@extends('layouts.app', ['title' => 'Dashboard'])
@section('content')
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
    html[data-theme="dark"] .dashboard-kpi-card,
    html[data-theme="dark"] .dashboard-balanced-grid > .card {
        box-shadow: 0 1px 2px rgba(0, 0, 0, .22);
    }
</style>

@php
    $firstName = str($user->full_name)->before(' ')->value();

    $copy = match($dashboardMode) {
        'BORROWER' => [
            'eyebrow' => 'Borrower overview',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'See what needs your attention, where your request is now, and when you need to pick up or return items.',
            'taskEyebrow' => 'What needs your attention',
            'taskTitle' => 'Your next actions',
        ],
        'SPMU_OFFICER' => [
            'eyebrow' => 'SPMU Action Officer',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Process only requests already approved by the SPMU Head: schedule pickup, release items, inspect returns, and complete final Laundry acceptance when applicable.',
            'taskEyebrow' => "Today's operations",
            'taskTitle' => 'Tasks requiring your action',
        ],
        'SPMU_HEAD' => [
            'eyebrow' => 'SPMU Head / Admin',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'Review submitted borrowing requests, make the final approval decision, and monitor borrowing, accountability, and inventory oversight.',
            'taskEyebrow' => 'Approval queue',
            'taskTitle' => 'Requests needing your approval decision',
        ],
        'LAUNDRY' => [
            'eyebrow' => 'Laundry Worker',
            'title' => 'Welcome, '.$firstName,
            'subtitle' => 'See which linen is awaiting borrower turnover, being processed, ready to bring to SPMU, or waiting for the final signed-form upload.',
            'taskEyebrow' => 'Needs your action',
            'taskTitle' => 'Laundry cases to process',
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
            'Needs My Action' => ['information', 'warning', route('requests.index')],
        ],
        'SPMU_OFFICER' => [
            'For Pickup Scheduling' => ['calendar', 'info', route('custody.index')],
            'Ready for Release' => ['custody', 'success', route('custody.index')],
            'For Return Check' => ['custody', 'warning', route('custody.index')],
            'Laundry Final Acceptance' => ['approval', 'info', route('laundry.spmu.index')],
        ],
        'SPMU_HEAD' => [
            'For Approval' => ['approval', 'warning', route('approvals.index')],
            'Approved Today' => ['success', 'success', route('requests.index')],
            'Active Borrowings' => ['custody', 'info', route('custody.index')],
            'Overdue / Issues' => ['accountability', 'danger', route('accountability.index')],
        ],
        'LAUNDRY' => [
            'Awaiting Drop-off' => ['calendar', 'warning', route('laundry.index')],
            'In Process' => ['custody', 'info', route('laundry.index')],
            'For SPMU Return' => ['success', 'success', route('laundry.index')],
            'Final Form Upload' => ['approval', 'warning', route('laundry.index')],
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
        <a class="button primary ui-pressable" href="{{ route('custody.index') }}">Open Release &amp; Return</a>
    @elseif($dashboardMode === 'SPMU_HEAD')
        <a class="button primary ui-pressable" href="{{ route('approvals.index') }}">Open Approval Queue</a>
    @elseif($dashboardMode === 'LAUNDRY')
        <a class="button primary ui-pressable" href="{{ route('laundry.index') }}">Open Laundry Requests</a>
    @elseif($dashboardMode === 'ICTU')
        <a class="button primary ui-pressable" href="{{ route('administration.users.index') }}">Manage Accounts</a>
    @endif
</section>

<section class="stat-grid dashboard-stat-grid" aria-label="Current totals">
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

@if($dashboardMode === 'BORROWER' && $latestRequest)
    <x-request-progress-tracker :request="$latestRequest" />
@endif

<section class="dashboard-grid dashboard-balanced-grid">
    <article class="card queue-card dashboard-panel-equal">
        <div class="card-header">
            <div>
                <p class="eyebrow">{{ $copy['taskEyebrow'] }}</p>
                <h2>{{ $copy['taskTitle'] }}</h2>
            </div>
            @if($dashboardMode === 'BORROWER')
                <a class="dashboard-view-all" href="{{ route('requests.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @elseif($dashboardMode === 'SPMU_OFFICER')
                <a class="dashboard-view-all" href="{{ route('custody.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @elseif($dashboardMode === 'SPMU_HEAD')
                <a class="dashboard-view-all" href="{{ route('approvals.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @elseif($dashboardMode === 'LAUNDRY')
                <a class="dashboard-view-all" href="{{ route('laundry.index') }}">View all <x-icon name="chevron-right" size="16" /></a>
            @endif
        </div>

        <div class="queue-list">
            @forelse($queue as $record)
                @if($dashboardMode === 'BORROWER')
                    @php
                        $custody = $record->custody;
                        $laundry = $custody?->laundryJob;
                        $nextAction = match(true) {
                            $record->status === App\Enums\RequestStatus::Draft => 'Complete the draft, print the BR Letter, and upload the fully signed scan.',
                            $record->status === App\Enums\RequestStatus::ReturnedForRevision => 'Review the SPMU remarks, correct the request, and resubmit.',
                            $record->status === App\Enums\RequestStatus::UnderSpmu => 'No action now. Your request is under SPMU review.',
                            $laundry?->status === 'FOR_LAUNDRY' => 'Bring the used linen and printed Laundry Form to the Laundry Worker.',
                            $laundry?->status === 'IN_PROCESS' => 'No action now. Laundry is processing your linen.',
                            $laundry?->status === 'READY_FOR_SPMU_RETURN' => 'No action now. The Laundry Worker will bring the cleaned linen directly to SPMU.',
                            $laundry?->status === 'AWAITING_FINAL_FORM_UPLOAD' => 'No action now. SPMU has accepted the linen; Laundry is uploading the fully signed form.',
                            $custody?->scheduled_release_at && ! $custody?->released_at => 'Pick up the approved items on the scheduled pickup window.',
                            $custody?->released_at && $custody?->status !== 'CLOSED' => 'Keep track of the return deadline and return the items to SPMU.',
                            $custody?->status === 'CLOSED' => 'Completed. No further action is required.',
                            default => 'Wait for the next SPMU update.',
                        };
                    @endphp
                    <article>
                        <div>
                            <strong>{{ $record->request_no }}</strong>
                            <span>{{ $record->currentVersion?->purpose_event ?: 'Borrowing request' }}</span>
                            <small>{{ $nextAction }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('requests.show', $record) }}">View</a>
                    </article>
                @elseif($dashboardMode === 'SPMU_OFFICER')
                    <article>
                        <div>
                            <strong>{{ $record->request?->request_no ?: $record->custody_no }}</strong>
                            <span>{{ $record->borrower?->full_name }}</span>
                            <small>
                                {{ $record->scheduled_release_at
                                    ? 'Pickup is scheduled. Prepare the approved items and record physical release when claimed.'
                                    : 'Approved by the SPMU Head. Set the pickup schedule and prepare any required Gate Pass.' }}
                            </small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('custody.show', $record) }}">Process</a>
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
                @elseif($dashboardMode === 'LAUNDRY')
                    @php
                        $jobLabel = match($record->status) {
                            'FOR_LAUNDRY' => 'Wait for borrower turnover, confirm the signed physical form, and record actual linen received.',
                            'IN_PROCESS' => 'Finish laundry, record completed quantities/condition, sign the form, then prepare to bring linen to SPMU.',
                            'READY_FOR_SPMU_RETURN' => 'Bring the cleaned linen and same physical Laundry Form directly to SPMU for final acceptance.',
                            'AWAITING_FINAL_FORM_UPLOAD' => 'SPMU final acceptance is complete. Upload the fully signed Laundry Form to settle the case.',
                            'FORM_REPLACEMENT_REQUIRED' => 'Upload a clear replacement copy of the fully signed Laundry Form.',
                            default => 'Open the laundry case for the next required action.',
                        };
                    @endphp
                    <article>
                        <div>
                            <strong>{{ $record->custody?->request?->request_no ?: $record->custody?->custody_no }}</strong>
                            <span>{{ $record->custody?->borrower?->full_name }}</span>
                            <small>{{ $jobLabel }}</small>
                        </div>
                        <a class="button primary small ui-pressable" href="{{ route('laundry.show', $record) }}">Open</a>
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
                <div class="empty-state">
                    <strong>Nothing needs attention right now.</strong>
                    <span>The next required action will appear here automatically.</span>
                </div>
            @endforelse
        </div>
    </article>

    <article class="card dashboard-panel-equal">
        @if($dashboardMode === 'BORROWER')
            <div class="card-header"><div><p class="eyebrow">Schedule</p><h2>Pickup and return dates</h2></div></div>
            <div class="queue-list">
                @forelse($nextCustodies as $custody)
                    <article>
                        <div>
                            <strong>{{ $custody->request?->request_no }}</strong>
                            <span>{{ $custody->scheduled_release_at ? 'Pickup '.$custody->scheduled_release_at->format('d M Y, g:i A') : 'Pickup not yet scheduled' }}</span>
                            <small>{{ $custody->due_at ? 'Return due '.$custody->due_at->format('d M Y') : 'Return deadline will appear after release.' }}</small>
                        </div>
                        <a class="table-action" href="{{ route('custody.show', $custody) }}">View</a>
                    </article>
                @empty
                    <div class="empty-state"><strong>No upcoming pickup or return schedule.</strong></div>
                @endforelse
            </div>
        @elseif($dashboardMode === 'SPMU_OFFICER')
            <div class="card-header"><div><p class="eyebrow">Operational guide</p><h2>What happens after Head approval?</h2></div></div>
            <div class="workflow-mini-list">
                <span><strong>1</strong> Receive the verified and approved request</span>
                <span><strong>2</strong> Schedule pickup and prepare Gate Pass when off-campus</span>
                <span><strong>3</strong> Release approved items physically</span>
                <span><strong>4</strong> Monitor custody and receive returns</span>
                <span><strong>5</strong> Receive cleaned linen directly from Laundry and complete final physical acceptance</span>
                <span><strong>6</strong> Complete final return reconciliation</span>
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
        @elseif($dashboardMode === 'LAUNDRY')
            <div class="card-header"><div><p class="eyebrow">Laundry tracker</p><h2>Five-stage linen return process</h2></div></div>
            <ol class="laundry-dashboard-tracker" aria-label="Laundry process">
                <li><span>1</span><strong>Borrower Turnover</strong><small>Borrower signs and hands used linen + physical Laundry Form to Laundry.</small></li>
                <li><span>2</span><strong>Laundry Processing</strong><small>Laundry records receipt, processes the linen, completes quantities/condition, and signs.</small></li>
                <li><span>3</span><strong>Return to SPMU</strong><small>Laundry Worker brings cleaned linen + the same physical form directly to SPMU.</small></li>
                <li><span>4</span><strong>SPMU Final Acceptance</strong><small>SPMU checks the linen and signs the final receiving/acceptance portion.</small></li>
                <li><span>5</span><strong>Final Form Upload</strong><small>Laundry uploads the fully signed form; the Laundry transaction is completed/settled.</small></li>
            </ol>
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
</section>
@endsection
