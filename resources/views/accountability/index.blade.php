@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Obligations' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Accountability Oversight' : 'Return Issues')])
@section('content')
@php
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';
    $pageTitle = $workspace === 'BORROWER' ? 'My Obligations' : ($isHead ? 'Accountability Oversight' : 'Return Issues');

    $activeRestrictions = $restrictions->where('status', 'ACTIVE');
    $openOverdueCases = $overdueCases->whereNotIn('status', ['RESOLVED']);
    $openIncidents = $incidents->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION']);
    $openBillings = $billings->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID']);
    $propertyCustodyIds = $openIncidents
        ->pluck('custody_transaction_id')
        ->filter()
        ->map(fn ($id) => (int) $id)
        ->unique();
    $pendingViolations = $isHead
        ? $violations->where('status', 'PENDING_REVIEW')->reject(
            fn ($violation) => $propertyCustodyIds->contains((int) $violation->custody_transaction_id)
        )
        : collect();
    $headReviewIncidents = $isHead
        ? $openIncidents->whereNotIn('status', ['BILLING_PENDING', 'FOR_BILLING', 'COMPLIANCE_REQUIRED'])
        : collect();
    $headReviewCount = $pendingViolations->count() + $headReviewIncidents->count();
    $headView = $isHead ? request('view', $headReviewCount > 0 ? 'head_review' : 'cases') : null;
    if ($isHead && ! in_array($headView, ['head_review', 'cases', 'billings', 'restrictions'], true)) {
        $headView = 'cases';
    }
    $openCaseCount = $openOverdueCases->count() + $openIncidents->count();
    $borrowerRecordCount = $openOverdueCases->count()
        + $openIncidents->count()
        + $openBillings->count()
        + $activeRestrictions->count();
    $borrowerStatuses = collect()
        ->concat($openOverdueCases->pluck('status'))
        ->concat($openIncidents->pluck('status'))
        ->concat($openBillings->pluck('status'))
        ->concat($activeRestrictions->pluck('status'))
        ->filter()
        ->unique()
        ->sort()
        ->values();
    $hasOpenMatters = $openCaseCount > 0
        || $openBillings->isNotEmpty()
        || $activeRestrictions->isNotEmpty()
        || $pendingViolations->isNotEmpty();
@endphp

@once
<style>
.borrower-obligation-banner { border-left: 4px solid #d08a16; }
.borrower-next-action {
    display: grid;
    gap: 6px;
    margin-top: 2px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-left-width: 4px;
    border-radius: 8px;
    background: var(--surface-subtle);
}
.borrower-next-action__heading { display: grid; gap: 2px; }
.borrower-next-action__heading > span {
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.borrower-next-action__heading > strong { color: var(--text-primary); font-size: 12px; }
.borrower-next-action > p { margin: 0; color: var(--text-secondary); font-size: 11px; line-height: 1.55; }
.borrower-next-action > small { color: var(--text-muted); font-size: 10px; }
.borrower-next-action--info { border-left-color: #2b78c5; background: #f5f9fd; }
.borrower-next-action--warning { border-left-color: #d08a16; background: #fffaf0; }
.borrower-next-action--danger { border-left-color: #c4493d; background: #fff7f6; }

.head-accountability-card {
    position: relative;
    display: grid;
    gap: 6px;
    min-height: 150px;
    padding: 18px 20px;
    color: inherit;
    text-decoration: none;
    transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}
.head-accountability-card:hover {
    transform: translateY(-1px);
    border-color: #8abbe8;
    box-shadow: 0 8px 20px rgba(15, 74, 125, .08);
}
.head-accountability-card.is-active {
    border-color: #1d6fb8;
    background: #f4f9fe;
    box-shadow: inset 0 3px 0 #1d6fb8;
}
.head-accountability-card .kpi-icon { margin-bottom: 4px; }
.head-control-heading { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; }
.head-control-heading > div { min-width:0; }
.head-control-heading h2 { margin:2px 0 4px; }
.head-control-heading p { margin:0; color:var(--text-muted); }
.head-case-card { overflow:hidden; }
.head-case-summary {
    display:grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap:1px;
    margin:14px 0 0;
    border:1px solid var(--border);
    border-radius:10px;
    overflow:hidden;
    background:var(--border);
}
.head-case-summary > div { padding:12px 14px; background:var(--surface); min-width:0; }
.head-case-summary dt { margin:0 0 4px; color:var(--text-muted); font-size:10px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.head-case-summary dd { margin:0; color:var(--text-primary); font-weight:700; overflow-wrap:anywhere; }
.head-case-lines { margin-top:14px; }
.head-review-disclosure { margin-top:14px; border-top:1px solid var(--border); padding-top:14px; }
.head-review-disclosure > summary {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 16px;
    border:1px solid #1d6fb8;
    border-radius:9px;
    color:#155d9d;
    background:#fff;
    cursor:pointer;
    font-weight:800;
    list-style:none;
}
.head-review-disclosure > summary::-webkit-details-marker { display:none; }
.head-review-disclosure[open] > summary { background:#eef6fd; }
.head-decision-panel { margin-top:14px; padding:16px; border:1px solid #c9dff2; border-radius:10px; background:#f8fbfe; }
.head-decision-panel h4 { margin:0 0 5px; }
.head-decision-panel > p { margin:0 0 14px; color:var(--text-secondary); }
.head-decision-hint { display:grid; gap:5px; margin-top:12px; padding:11px 12px; border-left:4px solid #d08a16; background:#fffaf0; border-radius:7px; color:var(--text-secondary); font-size:11px; }
.head-status-note { margin-top:12px; padding:11px 12px; border:1px solid var(--border); border-radius:8px; background:var(--surface-subtle); }
.head-status-note strong { display:block; margin-bottom:3px; }
.head-linked-case { font-weight:800; color:#155d9d; }
.head-offense-panel { display:grid; gap:11px; margin-top:14px; padding:14px; border:1px solid #d6e1ec; border-radius:10px; background:#fff; }
.head-offense-panel__heading { display:grid; gap:3px; }
.head-offense-panel__heading span { color:var(--text-muted); font-size:10px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
.head-offense-toggle { display:flex !important; align-items:flex-start; gap:9px !important; padding:11px 12px; border:1px solid #b9d4ec; border-radius:9px; background:#f5f9fd; color:var(--text-primary) !important; font-weight:800 !important; }
.head-offense-toggle input { width:18px !important; height:18px; margin:1px 0 0 !important; flex:0 0 auto; }
.head-offense-preview { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; border:1px solid var(--border); border-radius:9px; overflow:hidden; background:var(--border); }
.head-offense-preview > div { display:grid; gap:3px; padding:10px 11px; background:var(--surface); }
.head-offense-preview small { color:var(--text-muted); font-size:9px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.head-offense-preview strong { font-size:11px; overflow-wrap:anywhere; }
.head-offense-note { margin:0; color:var(--text-secondary); font-size:11px; line-height:1.5; }
.head-offense-state { padding:11px 12px; border-left:4px solid #1d6fb8; border-radius:8px; background:#f5f9fd; }
.head-offense-state strong { display:block; margin-bottom:3px; }
@media (max-width: 900px) { .head-case-summary, .head-offense-preview { grid-template-columns:1fr 1fr; } }
@media (max-width: 560px) { .head-case-summary, .head-offense-preview { grid-template-columns:1fr; } }
</style>
@endonce

<section class="page-heading">
    <div>
        <p class="eyebrow">Financial and property accountability</p>
        <h1>{{ $pageTitle }}</h1>
        <p>
            {{ $workspace === 'BORROWER'
                ? 'See unresolved obligations that affect your borrowing eligibility and what you need to resolve next.'
                : ($isHead
                    ? 'Focus on matters that need Head-level oversight or a formal administrative decision.'
                    : 'Process unresolved return, property, billing, and payment-evidence issues.') }}
        </p>
    </div>
</section>

@if($workspace === 'BORROWER' && $activeRestrictions->isNotEmpty())
@php
    $borrowerGuidanceTitle = 'Borrowing is currently restricted';
    $borrowerGuidanceText = 'Review the open obligation below. Your borrowing access will remain restricted until SPMU records the case as resolved.';

    if ($openBillings->isNotEmpty()) {
        $borrowerGuidanceTitle = 'Action required: settle the open billing';
        $borrowerGuidanceText = 'Download the Billing Statement below, settle it through the CSPC Cashier, then present the paid official receipt to SPMU. SPMU uploads and verifies the receipt; you do not upload payment evidence yourself.';
    } elseif ($openOverdueCases->isNotEmpty()) {
        $borrowerGuidanceTitle = 'Action required: return the outstanding items';
        $borrowerGuidanceText = 'Bring all outstanding issued items to SPMU for physical return inspection. Any applicable late-return billing is determined through the accountability process after the return is physically recorded.';
    } elseif ($openIncidents->isNotEmpty()) {
        $borrowerGuidanceTitle = 'No payment action required yet';
        $borrowerGuidanceText = 'An unresolved property case is under SPMU processing. Wait for the SPMU decision. If no charge is required, the SPMU Head resolves the case and its linked restriction is lifted. If a Billing Statement is issued, the restriction is lifted after verified settlement or an authorized waiver.';
    }
@endphp
<section class="content-area">
    <div class="action-panel action-warning borrower-obligation-banner">
        <div>
            <p class="eyebrow">Borrowing eligibility</p>
            <h2>{{ $borrowerGuidanceTitle }}</h2>
            <p>{{ $borrowerGuidanceText }}</p>
        </div>
        <x-status-badge status="ACTIVE" />
    </div>
</section>
@endif

<section
    class="stat-grid dashboard-stat-grid {{ $isBorrower ? 'accountability-filter-grid' : '' }}"
    aria-label="Accountability overview"
    @if($isBorrower) data-accountability-card-filters @endif
>
    @if($isBorrower)
        <button
            type="button"
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-danger accountability-filter-card"
            data-accountability-card-filter="overdue"
            aria-pressed="false"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="calendar" size="18" /></span>
            <strong class="kpi-value">{{ $openOverdueCases->count() }}</strong>
            <span class="kpi-label">Overdue Returns</span>
            <small>Unresolved date-based lateness</small>
        </button>
        <button
            type="button"
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning accountability-filter-card"
            data-accountability-card-filter="property"
            aria-pressed="false"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ $openIncidents->count() }}</strong>
            <span class="kpi-label">Property Cases</span>
            <small>Damage, loss, or accountability findings</small>
        </button>
        <button
            type="button"
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-info accountability-filter-card"
            data-accountability-card-filter="billing"
            aria-pressed="false"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="requests" size="18" /></span>
            <strong class="kpi-value">{{ $openBillings->count() }}</strong>
            <span class="kpi-label">Open Billings</span>
            <small>Awaiting settlement or disposition</small>
        </button>
        <button
            type="button"
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning accountability-filter-card"
            data-accountability-card-filter="restriction"
            aria-pressed="false"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="lock" size="18" /></span>
            <strong class="kpi-value">{{ $activeRestrictions->count() }}</strong>
            <span class="kpi-label">Active Restrictions</span>
            <small>Borrowing restrictions currently in force</small>
        </button>
    @elseif($isHead)
        <a
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning head-accountability-card {{ $headView === 'head_review' ? 'is-active' : '' }}"
            href="{{ route('accountability.index', ['view' => 'head_review']) }}"
            aria-current="{{ $headView === 'head_review' ? 'page' : 'false' }}"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ $headReviewCount }}</strong>
            <span class="kpi-label">Needs Head Review</span>
            <small>{{ $headReviewCount ? 'Formal case decision pending' : 'No pending administrative decision' }}</small>
        </a>
        <a
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-danger head-accountability-card {{ $headView === 'cases' ? 'is-active' : '' }}"
            href="{{ route('accountability.index', ['view' => 'cases']) }}"
            aria-current="{{ $headView === 'cases' ? 'page' : 'false' }}"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="custody" size="18" /></span>
            <strong class="kpi-value">{{ $openCaseCount }}</strong>
            <span class="kpi-label">Open Cases</span>
            <small>{{ $openOverdueCases->count() }} overdue · {{ $openIncidents->count() }} property</small>
        </a>
        <a
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-info head-accountability-card {{ $headView === 'billings' ? 'is-active' : '' }}"
            href="{{ route('accountability.index', ['view' => 'billings']) }}"
            aria-current="{{ $headView === 'billings' ? 'page' : 'false' }}"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="requests" size="18" /></span>
            <strong class="kpi-value">{{ $openBillings->count() }}</strong>
            <span class="kpi-label">Open Billings</span>
            <small>Awaiting settlement or disposition</small>
        </a>
        <a
            class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning head-accountability-card {{ $headView === 'restrictions' ? 'is-active' : '' }}"
            href="{{ route('accountability.index', ['view' => 'restrictions']) }}"
            aria-current="{{ $headView === 'restrictions' ? 'page' : 'false' }}"
        >
            <span class="kpi-icon" aria-hidden="true"><x-icon name="lock" size="18" /></span>
            <strong class="kpi-value">{{ $activeRestrictions->count() }}</strong>
            <span class="kpi-label">Active Restrictions</span>
            <small>Borrowing restrictions currently in force</small>
        </a>
    @else
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-danger">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="calendar" size="18" /></span>
            <strong class="kpi-value">{{ $openOverdueCases->count() }}</strong>
            <span class="kpi-label">Overdue Returns</span>
            <small>Unresolved date-based lateness</small>
        </article>
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="18" /></span>
            <strong class="kpi-value">{{ $openIncidents->count() }}</strong>
            <span class="kpi-label">Property Cases</span>
            <small>Damage, loss, or accountability findings</small>
        </article>
    @endif

    @if(! $isBorrower && ! $isHead)
        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-info">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="requests" size="18" /></span>
            <strong class="kpi-value">{{ $openBillings->count() }}</strong>
            <span class="kpi-label">Open Billings</span>
            <small>Awaiting settlement or disposition</small>
        </article>

        <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
            <span class="kpi-icon" aria-hidden="true"><x-icon name="lock" size="18" /></span>
            <strong class="kpi-value">{{ $activeRestrictions->count() }}</strong>
            <span class="kpi-label">Active Restrictions</span>
            <small>Borrowing restrictions currently in force</small>
        </article>
    @endif
</section>

@if($isBorrower)
<section class="content-area borrower-accountability-browser" data-borrower-accountability>
    <div class="borrower-accountability-toolbar" aria-label="Search and filter accountability records">
        <label class="borrower-accountability-search">
            Search
            <input
                type="search"
                placeholder="Search reference, type, status, or details..."
                autocomplete="off"
                data-accountability-search
            >
        </label>
        <label>
            Status
            <select data-accountability-status>
                <option value="">All Statuses</option>
                @foreach($borrowerStatuses as $status)
                    <option value="{{ $status }}">
                        {{ str($status)->replace('_', ' ')->title() }}
                    </option>
                @endforeach
            </select>
        </label>
        <label>
            Sort
            <select data-accountability-sort>
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
            </select>
        </label>
        <button class="button secondary small borrower-accountability-clear" type="button" data-accountability-clear>
            Clear filters
        </button>
    </div>

    <div class="borrower-accountability-results" aria-live="polite">
        <span data-accountability-result-count>
            Showing {{ $borrowerRecordCount }} {{ \Illuminate\Support\Str::plural('record', $borrowerRecordCount) }}
        </span>
        <span>Open accountability records</span>
    </div>

    <div class="borrower-accountability-records" data-accountability-records>
        @foreach($openOverdueCases as $overdue)
            @php
                $recordDate = $overdue->overdue_started_at ?: $overdue->created_at;
                $searchText = strtolower(implode(' ', [
                    'overdue return',
                    $overdue->custody?->custody_no,
                    $overdue->borrower?->full_name,
                    $overdue->status,
                    $overdue->accrued_amount,
                ]));
            @endphp
            <article
                class="card accountability-browser-record"
                data-accountability-record
                data-category="overdue"
                data-status="{{ $overdue->status }}"
                data-date="{{ optional($recordDate)->timestamp ?? 0 }}"
                data-search="{{ $searchText }}"
            >
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">Overdue Return</p>
                        <h3>{{ $overdue->custody?->custody_no ?: 'No custody reference' }}</h3>
                        <small>Recorded {{ optional($recordDate)->format('d M Y, g:i A') ?: '—' }}</small>
                    </div>
                    <x-status-badge :status="$overdue->status" />
                </div>
                <div class="accountability-browser-facts">
                    <span><small>Expected return</small><strong>{{ optional($overdue->custody?->due_at)->format('d M Y') ?: '—' }}</strong></span>
                    <span><small>Late fee rate</small><strong>{{ $overdue->rate_snapshot === null ? 'Not configured' : 'PHP '.number_format((float) $overdue->rate_snapshot, 2) }}</strong></span>
                    <span><small>Accrued amount</small><strong>{{ $overdue->rate_snapshot === null ? 'Not determined' : 'PHP '.number_format((float) $overdue->accrued_amount, 2) }}</strong></span>
                </div>
                <div class="borrower-next-action borrower-next-action--warning">
                    <div class="borrower-next-action__heading">
                        <span>What you need to do</span>
                        <strong>Return the outstanding items to SPMU</strong>
                    </div>
                    <p>Bring the issued items to SPMU for physical return inspection as soon as possible. Do not record the return yourself; the SPMU Action Officer confirms the actual quantities and condition during handover.</p>
                </div>
                <p class="meta">Late status begins on the calendar day after the Expected Return Date.</p>
            </article>
        @endforeach

        @foreach($openIncidents as $incident)
            @php
                $recordDate = $incident->reported_at ?: $incident->created_at;
                $incidentType = str($incident->incident_type)->replace('_', ' ')->title();
                $incidentBilling = $openBillings->first(fn ($billing) => $billing->lines->contains(
                    fn ($line) => (int) $line->incident_id === (int) $incident->id
                ));
                $incidentRestriction = $activeRestrictions->first(
                    fn ($restriction) => (int) $restriction->incident_id === (int) $incident->id
                );
                $incidentNextActionTitle = 'No action required yet';
                $incidentNextActionText = 'SPMU is processing this property case. Wait for a formal billing, waiver, compliance, or case-resolution instruction before taking any payment action.';
                $incidentNextActionClass = 'borrower-next-action--info';

                if ($incident->status === 'COMPLIANCE_REQUIRED') {
                    $incidentNextActionTitle = 'Coordinate the required compliance with SPMU';
                    $incidentNextActionText = 'The SPMU Head requires repair, replacement, or another compliance action for this property case. Coordinate directly with SPMU and complete the required action. Your linked borrowing restriction remains active until SPMU verifies completion.';
                    $incidentNextActionClass = 'borrower-next-action--warning';
                } elseif ($incident->status === 'FOR_BILLING') {
                    $incidentNextActionTitle = 'Wait for the Billing Statement';
                    $incidentNextActionText = 'The SPMU Head determined that this case requires billing/payment processing. No payment is due until SPMU issues the Billing Statement with the approved amount and basis.';
                    $incidentNextActionClass = 'borrower-next-action--warning';
                }

                if ($incidentBilling) {
                    if ($incidentBilling->status === 'RECEIPT_SUBMITTED') {
                        $incidentNextActionTitle = 'Wait for SPMU receipt verification';
                        $incidentNextActionText = 'The paid CSPC Cashier receipt has already been recorded by SPMU and is awaiting verification. No additional borrower upload is required.';
                        $incidentNextActionClass = 'borrower-next-action--info';
                    } else {
                        $incidentNextActionTitle = 'Settle the issued Billing Statement';
                        $incidentNextActionText = 'Download the Billing Statement, pay through the CSPC Cashier, and present the paid official receipt to SPMU. SPMU will scan, upload, and verify the receipt.';
                        $incidentNextActionClass = 'borrower-next-action--warning';
                    }
                }

                $searchText = strtolower(implode(' ', [
                    'property case',
                    $incident->incident_no,
                    (string) $incidentType,
                    $incident->status,
                    $incident->remarks,
                    $incident->custody?->custody_no,
                    $incident->lines->pluck('observed_condition')->join(' '),
                    $incident->lines->pluck('disposition_state')->join(' '),
                ]));
            @endphp
            <article
                class="card accountability-browser-record"
                data-accountability-record
                data-category="property"
                data-status="{{ $incident->status }}"
                data-date="{{ optional($recordDate)->timestamp ?? 0 }}"
                data-search="{{ $searchText }}"
            >
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">Property Case</p>
                        <h3>{{ $incident->incident_no }}</h3>
                        <small>Reported {{ optional($recordDate)->format('d M Y, g:i A') ?: '—' }}</small>
                    </div>
                    <x-status-badge :status="$incident->status" />
                </div>
                <div class="accountability-browser-facts">
                    <span><small>Finding</small><strong>{{ $incidentType }}</strong></span>
                    <span><small>Custody</small><strong>{{ $incident->custody?->custody_no ?: '—' }}</strong></span>
                    <span><small>Affected lines</small><strong>{{ $incident->lines->count() }}</strong></span>
                </div>
                @if($incident->lines->isNotEmpty())
                    <div class="incident-outcomes">
                        @foreach($incident->lines as $line)
                            <span>
                                {{ $line->quantity + 0 }} ×
                                {{ str($line->observed_condition)->replace('_', ' ')->title() }} ·
                                {{ str($line->disposition_state)->replace('_', ' ')->title() }}
                            </span>
                        @endforeach
                    </div>
                @endif
                <div class="borrower-next-action {{ $incidentNextActionClass }}">
                    <div class="borrower-next-action__heading">
                        <span>What you need to do</span>
                        <strong>{{ $incidentNextActionTitle }}</strong>
                    </div>
                    <p>{{ $incidentNextActionText }}</p>
                    @if($incidentRestriction)
                        <small>Borrowing restriction: active until this accountability case is resolved.</small>
                    @endif
                </div>
                <p class="meta">{{ $incident->remarks ?: 'No additional remarks.' }}</p>
            </article>
        @endforeach

        @foreach($openBillings as $billing)
            @php
                $recordDate = $billing->issued_at ?: $billing->created_at;
                $latestPayment = $billing->payments->sortByDesc(fn ($payment) => $payment->submitted_at ?: $payment->created_at)->first();
                $billingActionTitle = 'Settle this Billing Statement';
                $billingActionText = 'Download the Billing Statement, pay the amount through the CSPC Cashier, then present the paid official receipt to SPMU. SPMU records and verifies the receipt in the system.';
                $billingActionClass = 'borrower-next-action--warning';

                if ($billing->status === 'RECEIPT_SUBMITTED') {
                    $billingActionTitle = 'Receipt submitted — wait for verification';
                    $billingActionText = 'SPMU has recorded the paid CSPC Cashier receipt. No borrower upload is required while the payment evidence is being verified.';
                    $billingActionClass = 'borrower-next-action--info';
                } elseif ($latestPayment?->status === 'REJECTED') {
                    $billingActionTitle = 'Present the corrected paid receipt to SPMU';
                    $billingActionText = 'The previous receipt record requires correction. Bring the correct CSPC Cashier official receipt to SPMU so the payment evidence can be recorded again.';
                    $billingActionClass = 'borrower-next-action--danger';
                }

                $searchText = strtolower(implode(' ', [
                    'billing statement',
                    $billing->billing_no,
                    $billing->status,
                    $billing->total_amount,
                    $billing->remarks,
                    $billing->lines->pluck('description')->join(' '),
                    $billing->payments->pluck('official_receipt_no')->join(' '),
                ]));
            @endphp
            <article
                class="card accountability-browser-record"
                data-accountability-record
                data-category="billing"
                data-status="{{ $billing->status }}"
                data-date="{{ optional($recordDate)->timestamp ?? 0 }}"
                data-search="{{ $searchText }}"
            >
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">Billing Statement</p>
                        <h3>{{ $billing->billing_no }}</h3>
                        <small>Issued {{ optional($recordDate)->format('d M Y, g:i A') ?: '—' }}</small>
                    </div>
                    <x-status-badge :status="$billing->status" />
                </div>
                <div class="accountability-browser-facts">
                    <span><small>Total amount</small><strong>PHP {{ number_format((float) $billing->total_amount, 2) }}</strong></span>
                    <span><small>Payment due</small><strong>{{ optional($billing->due_at)->format('d M Y') ?: 'Not specified' }}</strong></span>
                    <span><small>Payments</small><strong>{{ $billing->payments->count() }}</strong></span>
                </div>
                <div class="billing-lines">
                    @foreach($billing->lines as $line)
                        <p>
                            <strong>{{ str($line->line_type)->replace('_', ' ')->title() }}</strong>
                            <span>{{ $line->description }}</span>
                            <small>PHP {{ number_format((float) $line->amount, 2) }}</small>
                        </p>
                    @endforeach
                </div>
                <div class="borrower-next-action {{ $billingActionClass }}">
                    <div class="borrower-next-action__heading">
                        <span>What you need to do</span>
                        <strong>{{ $billingActionTitle }}</strong>
                    </div>
                    <p>{{ $billingActionText }}</p>
                    <small>Payment evidence is handled by SPMU after you present the paid CSPC Cashier official receipt.</small>
                </div>
                <div class="actions">
                    @foreach($billing->documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document)
                        <a class="button secondary small" href="{{ route('documents.download', $document) }}">Download Billing Statement</a>
                    @endforeach
                </div>
                <div class="payment-history">
                    @foreach($billing->payments as $payment)
                        <div class="evidence-row">
                            <div>
                                <x-status-badge :status="$payment->status" />
                                <strong>{{ $payment->official_receipt_no }}</strong>
                                <small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float) $payment->amount, 2) }}</small>
                                @if($payment->evidence_file_id)
                                    <a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">View scanned Cashier receipt</a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </article>
        @endforeach

        @foreach($activeRestrictions as $restriction)
            @php
                $recordDate = $restriction->effective_from ?: $restriction->created_at;
                $restrictionType = str($restriction->restriction_type)->replace('_', ' ')->title();
                $searchText = strtolower(implode(' ', [
                    'active restriction',
                    (string) $restrictionType,
                    $restriction->status,
                    $restriction->reason,
                ]));
            @endphp
            <article
                class="card accountability-browser-record"
                data-accountability-record
                data-category="restriction"
                data-status="{{ $restriction->status }}"
                data-date="{{ optional($recordDate)->timestamp ?? 0 }}"
                data-search="{{ $searchText }}"
            >
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">Borrowing Restriction</p>
                        <h3>{{ $restrictionType }}</h3>
                        <small>Effective {{ optional($recordDate)->format('d M Y') ?: '—' }}</small>
                    </div>
                    <x-status-badge :status="$restriction->status" />
                </div>
                <div class="accountability-browser-facts">
                    <span><small>Reason</small><strong>{{ $restriction->reason }}</strong></span>
                    <span><small>Effective period</small><strong>{{ optional($restriction->effective_from)->format('d M Y') ?: '—' }}{{ $restriction->effective_to ? ' – '.$restriction->effective_to->format('d M Y') : ' until resolved' }}</strong></span>
                </div>
                <div class="borrower-next-action borrower-next-action--info">
                    <div class="borrower-next-action__heading">
                        <span>Restriction status</span>
                        <strong>Temporary until the related obligation is cleared</strong>
                    </div>
                    <p>You cannot submit a new borrowing request while this restriction is active. Your borrowing eligibility returns when SPMU resolves the linked case, or when any related Billing Statement is verified as settled or formally waived. Other active restrictions, if any, remain in effect.</p>
                </div>
            </article>
        @endforeach
    </div>

    <div class="borrower-accountability-empty" data-accountability-empty @if($borrowerRecordCount > 0) hidden @endif>
        <strong>{{ $borrowerRecordCount > 0 ? 'No records match the current filters.' : 'You have no unresolved obligations.' }}</strong>
        <span>{{ $borrowerRecordCount > 0 ? 'Adjust the search or filters to see other records.' : 'There are no open overdue, property, billing, or restriction records on your account.' }}</span>
    </div>
</section>
@endif

@if(! $isBorrower && ! $hasOpenMatters)
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>{{ $workspace === 'BORROWER' ? 'You have no unresolved obligations.' : 'No accountability matters need attention.' }}</strong>
                <p>{{ $workspace === 'BORROWER' ? 'There are no open overdue, property, billing, or restriction records on your account.' : 'There are no pending Head decisions, open property cases, unpaid billings, or active restrictions.' }}</p>
            </div>
        </div>
    </article>
</section>
@endif

@if($isHead && $headView === 'head_review' && $pendingViolations->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Administrative accountability</p>
            <h2>Administrative Review</h2>
            <p>Review only violations that require an SPMU Head decision. Property return findings and financial follow-up stay in their own sections below.</p>
        </div>
    </div>

    @foreach($pendingViolations as $violation)
        <article class="card top-gap">
            <div class="card-header">
                <div>
                    <strong>{{ $violation->custody?->custody_no ?: 'No custody reference' }}</strong>
                    <h3>{{ $violation->borrower->full_name }}</h3>
                    <small>Detected {{ optional($violation->detected_at)->format('d M Y, g:i A') }}</small>
                </div>
                <x-status-badge :status="$violation->status" />
            </div>

            <dl class="summary-grid compact">
                <div>
                    <dt>Finding(s)</dt>
                    <dd>{{ collect(data_get($violation->details_json, 'reasons', []))->map(fn ($reason) => str($reason)->replace('_', ' ')->title())->join(', ') ?: 'Borrowing violation' }}</dd>
                </div>
                <div>
                    <dt>Academic Period</dt>
                    <dd>{{ $violation->academicPeriod ? $violation->academicPeriod->academic_year.' · '.$violation->academicPeriod->term_name : 'Uses active period when confirmed' }}</dd>
                </div>
            </dl>

            <div class="callout info top-gap">
                <strong>Connected to Operational Configuration → Sanction Rules.</strong>
                <p>Leave Administrative Action on the configured-rule option to apply the active 1st, 2nd, or 3rd offense default. The SPMU Head may still record a justified case-specific override.</p>
            </div>

            <form method="post" action="{{ route('accountability.violations.review', $violation) }}" class="form-grid top-gap">
                @csrf
                <div class="form-columns">
                    <label>
                        Administrative Action
                        <select name="sanction_code">
                            <option value="">Use configured 1st / 2nd / 3rd offense rule</option>
                            <option value="NOTICE">Notice</option>
                            <option value="WRITTEN_REPRIMAND">Written Reprimand</option>
                            <option value="BORROWING_SUSPENSION">Borrowing Suspension</option>
                            <option value="OTHER">Other Administrative Action</option>
                        </select>
                    </label>
                    <label>
                        Suspension Until
                        <input type="date" name="effective_to" min="{{ now()->toDateString() }}">
                        <small>Optional override. Leave blank to use the configured duration (for example: 2nd offense = 1 month; 3rd offense = until semester end).</small>
                    </label>
                </div>
                <label>
                    Other Action Label
                    <input name="custom_sanction_label" maxlength="255" placeholder="Complete only when Other is selected">
                </label>
                <label>
                    Review Remarks
                    <textarea name="remarks" maxlength="2000" placeholder="Record the basis for the SPMU Head decision."></textarea>
                </label>
                <div class="inline-actions">
                    <button class="button primary" name="decision" value="CONFIRMED">Confirm Violation & Record Sanction</button>
                    <button class="button secondary" name="decision" value="DISMISSED">Dismiss Violation</button>
                </div>
            </form>
        </article>
    @endforeach
</section>
@endif

@if(! $isBorrower && $openOverdueCases->isNotEmpty() && (! $isHead || $headView === 'cases'))
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Date-based lateness</p>
            <h2>Overdue Returns</h2>
        </div>
    </div>
    @foreach($openOverdueCases as $overdue)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $overdue->custody->custody_no }}</strong><h3>{{ $overdue->borrower->full_name }}</h3></div><x-status-badge :status="$overdue->status" /></div>
<dl class="summary-grid compact"><div><dt>Expected Return Date</dt><dd>{{ $overdue->custody->due_at->format('d M Y') }}</dd></div><div><dt>Late fee rate</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not configured' : 'PHP '.number_format((float)$overdue->rate_snapshot,2) }}</dd></div><div><dt>Accrued amount</dt><dd>{{ $overdue->rate_snapshot === null ? 'Not determined' : 'PHP '.number_format((float)$overdue->accrued_amount,2) }}</dd></div></dl>
<p class="meta">Late status begins on the calendar day after the Expected Return Date. Time of day is not used to determine lateness.</p>
@if($isOfficer && !in_array($overdue->status,['BILLED','RESOLVED'],true))
<form method="post" action="{{ route('overdue.bill',$overdue) }}" class="form-grid top-gap">@csrf<label>Billing basis<textarea name="basis" required placeholder="Use the configured client-approved late fee policy."></textarea></label><label>Payment due date <input type="date" name="due_at"></label><button class="button primary">Generate Billing Statement / Payment Assessment</button></form>
@endif
</article>
    @endforeach
</section>
@endif

@php
    $displayIncidents = $isHead && $headView === 'head_review'
        ? $headReviewIncidents
        : $openIncidents;
@endphp
@if(! $isBorrower && $displayIncidents->isNotEmpty() && (! $isHead || in_array($headView, ['head_review', 'cases'], true)))
<section class="content-area">
    <div class="section-heading head-control-heading">
        <div>
            <p class="eyebrow">Property accountability</p>
            <h2>{{ $isHead && $headView === 'head_review' ? 'Cases Awaiting Head Decision' : 'Property Accountability Cases' }}</h2>
            <p>{{ $isHead && $headView === 'head_review'
                ? 'Review the recorded physical findings and enter the formal SPMU Head decision. Cases already routed to billing or compliance are shown under Open Cases.'
                : 'Open property cases remain visible until the required decision, compliance, billing settlement, or formal clearance is completed.' }}</p>
        </div>
    </div>

    @foreach($displayIncidents as $incident)
        @php
            $incidentHasBilling = Illuminate\Support\Facades\DB::table('billing_lines')->where('incident_id', $incident->id)->exists();
            $requestNo = $incident->custody?->request?->request_no ?: '—';
            $custodyNo = $incident->custody?->custody_no ?: '—';
            $incidentRestriction = $activeRestrictions->firstWhere('incident_id', $incident->id);
            $isAwaitingDecision = $isHead && $headReviewIncidents->contains('id', $incident->id);
            $isForBilling = $incident->status === 'FOR_BILLING';
            $isComplianceRequired = $incident->status === 'COMPLIANCE_REQUIRED';
            $offensePreview = $incidentOffensePreviews[$incident->id] ?? null;
        @endphp
        <article class="card top-gap head-case-card" id="incident-{{ $incident->id }}">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Property Case</p>
                    <strong>{{ $incident->incident_no }}</strong>
                    <h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3>
                    <small>Reported {{ optional($incident->reported_at)->format('d M Y, g:i A') ?: '—' }}</small>
                </div>
                <x-status-badge :status="$incident->status" />
            </div>

            <dl class="head-case-summary">
                <div>
                    <dt>Borrower</dt>
                    <dd>{{ $incident->borrower?->full_name ?: '—' }}</dd>
                </div>
                <div>
                    <dt>Request</dt>
                    <dd>{{ $requestNo }}</dd>
                </div>
                <div>
                    <dt>Custody</dt>
                    <dd>{{ $custodyNo }}</dd>
                </div>
                <div>
                    <dt>Restriction</dt>
                    <dd>{{ $incidentRestriction ? 'Active until resolved' : 'No active linked restriction' }}</dd>
                </div>
            </dl>

            <div class="table-wrap head-case-lines">
                <table>
                    <thead>
                        <tr><th>Item</th><th>Qty</th><th>Finding</th><th>Disposition</th></tr>
                    </thead>
                    <tbody>
                        @foreach($incident->lines as $line)
                            @php
                                $custodyLine = $incident->custody?->lines?->firstWhere('id', $line->custody_line_id);
                                $itemDescription = $custodyLine?->requestItem?->description_snapshot ?: 'Inventory item';
                            @endphp
                            <tr>
                                <td>{{ $itemDescription }}</td>
                                <td>{{ $line->quantity + 0 }}</td>
                                <td>{{ str($line->observed_condition)->replace('_',' ')->title() }}</td>
                                <td>{{ str($line->disposition_state)->replace('_',' ')->title() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($incident->supporting_evidence_file_id || $incident->police_blotter_reference)
                <div class="actions top-gap">
                    @if($incident->supporting_evidence_file_id)
                        <a class="button secondary small" href="{{ route('files.show', $incident->supporting_evidence_file_id, false) }}" target="_blank">View Supporting Evidence</a>
                    @endif
                    @if($incident->police_blotter_reference)
                        <span class="meta">Police blotter reference: <strong>{{ $incident->police_blotter_reference }}</strong></span>
                    @endif
                </div>
            @endif

            @if($incident->remarks)
                <div class="head-status-note">
                    <strong>Recorded remarks</strong>
                    <span>{!! nl2br(e($incident->remarks)) !!}</span>
                </div>
            @endif

            @if($isOfficer && ! $incidentHasBilling && $incident->status === 'FOR_BILLING')
                <div class="action-panel top-gap">
                    <div>
                        <p class="eyebrow">Action Officer</p>
                        <h4>{{ $isForBilling ? 'Head decision: billing / payment required' : 'Financial assessment, when required' }}</h4>
                        <p>{{ $isForBilling
                            ? 'The SPMU Head has routed this case for billing. Record the approved accountability amount and basis, then generate the Billing Statement.'
                            : 'Generate a Billing Statement only when the approved accountability decision requires payment. Cases without a financial charge remain for Head resolution.' }}</p>
                    </div>
                </div>
                <form method="post" action="{{ route('incidents.bill',$incident) }}" class="form-grid top-gap">
                    @csrf
                    <div class="form-columns">
                        <label>Accountability charge<input type="number" step="0.01" min="0.01" name="amount" required></label>
                        <label>Payment due date<input type="date" name="due_at"></label>
                    </div>
                    <label>Assessment basis<textarea name="basis" required></textarea></label>
                    <button class="button primary">Generate Billing Statement</button>
                </form>
            @endif

            @if($isHead && ! $incidentHasBilling && !in_array($incident->status,['RESOLVED','CLOSED','VOID_CORRECTION'],true))
                @if($isComplianceRequired)
                    <div class="head-status-note">
                        <strong>Current Head decision: Compliance required</strong>
                        <span>The borrower remains restricted while repair, replacement, or another required compliance action is outstanding.</span>
                    </div>
                    <details class="head-review-disclosure">
                        <summary>Verify Compliance</summary>
                        <div class="head-decision-panel">
                            <h4>Confirm that the required compliance is complete</h4>
                            <p>Use this only after SPMU has physically verified the repair, replacement, or other required compliance. Completing this action resolves the case and lifts only the restriction linked to this incident.</p>
                            <form method="post" action="{{ route('incidents.resolve', $incident) }}" class="form-grid">
                                @csrf
                                <input type="hidden" name="resolution_outcome" value="COMPLIANCE_COMPLETED">
                                <label>
                                    Verification / resolution remarks
                                    <textarea name="resolution_remarks" maxlength="2000" required placeholder="Describe what was completed and how SPMU verified it."></textarea>
                                </label>
                                <button class="button primary">Confirm Compliance & Resolve Case</button>
                            </form>
                        </div>
                    </details>
                @elseif($isForBilling)
                    <div class="head-status-note">
                        <strong>Current Head decision: Billing / payment required</strong>
                        <span>The case has been routed for financial assessment. The linked restriction remains active until the Billing Statement is settled or formally waived.</span>
                    </div>
                @elseif($isAwaitingDecision)
                    <details class="head-review-disclosure">
                        <summary>Review Case</summary>
                        <div class="head-decision-panel">
                            <p class="eyebrow">SPMU Head Decision</p>
                            <h4>Record the formal accountability decision</h4>
                            <p>Choose the required outcome based on the physical findings and supporting evidence. The system will keep or lift the linked borrowing restriction according to the selected decision.</p>
                            <form method="post" action="{{ route('incidents.resolve', $incident) }}" class="form-grid">
                                @csrf
                                <label>
                                    Decision / Required Action
                                    <select name="resolution_outcome" required>
                                        <option value="">Select decision</option>
                                        <option value="NO_BORROWER_CHARGE">No borrower liability / no charge</option>
                                        <option value="COMPLIANCE_REQUIRED">Repair / replacement / compliance required</option>
                                        <option value="BILLING_REQUIRED">Billing / payment required</option>
                                        <option value="ADMINISTRATIVELY_CLEARED">Administratively cleared</option>
                                    </select>
                                </label>
                                @if($offensePreview)
                                    <div class="head-offense-panel">
                                        <div class="head-offense-panel__heading">
                                            <span>Administrative Offense</span>
                                            <strong>Decide separately whether this property case counts toward the borrower's offense history.</strong>
                                        </div>

                                        @if($offensePreview['existing_sanction'])
                                            <div class="head-offense-state">
                                                <strong>Already counted as {{ $offensePreview['existing_sanction']->offense_no }} offense</strong>
                                                <span>{{ $offensePreview['existing_sanction']->sanction_label }}. This property decision will not create another offense for the same borrowing transaction.</span>
                                            </div>
                                        @elseif($offensePreview['is_eligible'])
                                            <input type="hidden" name="count_as_offense" value="0">
                                            <label class="head-offense-toggle">
                                                <input type="checkbox" name="count_as_offense" value="1" @disabled(! $offensePreview['can_confirm'])>
                                                <span>Count this case as a confirmed administrative offense</span>
                                            </label>

                                            <div class="head-offense-preview">
                                                <div>
                                                    <small>Previous confirmed offenses</small>
                                                    <strong>{{ $offensePreview['previous_confirmed_offenses'] }} this academic period</strong>
                                                </div>
                                                <div>
                                                    <small>If confirmed</small>
                                                    <strong>{{ $offensePreview['next_offense_label'] }}</strong>
                                                </div>
                                                <div>
                                                    <small>Configured sanction</small>
                                                    <strong>{{ $offensePreview['configured_sanction_label'] }}</strong>
                                                </div>
                                                <div>
                                                    <small>Restriction effect</small>
                                                    <strong>{{ $offensePreview['restriction_preview'] }}</strong>
                                                </div>
                                            </div>

                                            <p class="head-offense-note">
                                                Applicable finding(s):
                                                <strong>{{ collect($offensePreview['eligible_types'])->map(fn ($type) => str($type)->replace('_', ' ')->title())->join(', ') }}</strong>.
                                                Academic period: <strong>{{ $offensePreview['academic_period_label'] }}</strong>.
                                                The property accountability outcome above remains separate from this administrative sanction decision.
                                            </p>

                                            @if(! $offensePreview['can_confirm'])
                                                <div class="head-status-note">
                                                    <strong>Administrative offense cannot be confirmed yet.</strong>
                                                    <span>Activate the applicable Academic Period or review the existing administrative decision first.</span>
                                                </div>
                                            @endif
                                        @else
                                            <div class="head-offense-state">
                                                <strong>Not enabled as an administrative offense type</strong>
                                                <span>This property case can still be resolved normally. To allow this finding to count as an offense, enable the corresponding case type under Operational Configuration → Sanction Rules → Offense Application.</span>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                                <label>
                                    Decision remarks
                                    <textarea name="resolution_remarks" maxlength="2000" required placeholder="Record the basis, instruction, or reason for the Head decision."></textarea>
                                </label>
                                <div class="head-decision-hint">
                                    <strong>Decision effect</strong>
                                    <span>No liability / Administratively cleared → resolves the case and lifts its linked restriction.</span>
                                    <span>Compliance required / Billing required → keeps the case and restriction open until the required follow-up is verified.</span>
                                    <span>Administrative offense checkbox → separately applies the configured 1st / 2nd / 3rd offense sanction when explicitly confirmed by the Head.</span>
                                </div>
                                <button class="button primary">Confirm Head Decision</button>
                            </form>
                        </div>
                    </details>
                @endif
            @endif
        </article>
    @endforeach
</section>
@elseif($isHead && in_array($headView, ['head_review', 'cases'], true))
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>{{ $headView === 'head_review' ? 'No property cases are waiting for a Head decision.' : 'No open property accountability cases.' }}</strong>
                <p>{{ $headView === 'head_review' ? 'Cases already routed to billing or compliance remain under Open Cases.' : 'There are no unresolved property cases in this view.' }}</p>
            </div>
        </div>
    </article>
</section>
@endif

@if(! $isBorrower && $openBillings->isNotEmpty() && (! $isHead || $headView === 'billings'))
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Cashier payment evidence</p>
            <h2>Open Billing Statements</h2>
        </div>
    </div>
    @foreach($openBillings as $billing)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $billing->billing_no }}</strong><h3>PHP {{ number_format((float)$billing->total_amount,2) }}</h3><small>{{ $billing->borrower->full_name }}</small></div><x-status-badge :status="$billing->status" /></div>
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement / Assessment</a>@endforeach</div>
<p class="meta">The system-generated document is not an Official Receipt. The borrower pays at the CSPC Cashier; SPMU then receives and uploads the paid Cashier receipt.</p>
@if($isOfficer && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Upload paid CSPC Cashier receipt</h4></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks<textarea name="remarks"></textarea></label><button class="button secondary">Upload Paid Receipt</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">View scanned Cashier receipt</a>@endif</div>
@if($isOfficer && $payment->status==='PENDING_VERIFICATION')<form method="post" action="{{ route('payments.verify',$payment) }}" class="form-grid">@csrf<label>Verification remarks<textarea name="remarks" required></textarea></label><div class="inline-actions"><button class="button primary small" name="decision" value="VERIFIED">Verify Paid</button><button class="button danger small" name="decision" value="REJECTED">Return for Correction</button></div></form>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
@if($isHead && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))<details class="top-gap"><summary>Authorized billing waiver</summary><form method="post" action="{{ route('billings.waive',$billing) }}" class="form-grid top-gap">@csrf<label>Waiver reason<textarea name="reason" required></textarea></label><button class="button danger">Record Authorized Waiver</button></form></details>@endif
</article>
    @endforeach
</section>
@endif

@if($isHead && $headView === 'billings' && $openBillings->isEmpty())
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>No open Billing Statements.</strong>
                <p>Property cases routed for payment will appear here after the Action Officer records the approved charge and generates the Billing Statement.</p>
            </div>
        </div>
    </article>
</section>
@endif

@if(! $isBorrower && $activeRestrictions->isNotEmpty() && (! $isHead || $headView === 'restrictions'))
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Borrowing eligibility</p>
                <h2>Active Restrictions</h2>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Restriction</th>@if($isHead)<th>Linked Case</th>@endif<th>Reason</th><th>Effective</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($activeRestrictions as $restriction)
                        @php
                            $linkedIncident = $restriction->incident_id ? $incidents->firstWhere('id', $restriction->incident_id) : null;
                        @endphp
                        <tr>
                            <td>{{ str($restriction->restriction_type)->replace('_',' ')->title() }}</td>
                            @if($isHead)
                                <td>
                                    @if($linkedIncident)
                                        <a class="head-linked-case" href="{{ route('accountability.index', ['view' => 'cases']).'#incident-'.$linkedIncident->id }}">{{ $linkedIncident->incident_no }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td>{{ $restriction->reason }}</td>
                            <td>{{ optional($restriction->effective_from)->format('d M Y') }}{{ $restriction->effective_to ? ' – '.$restriction->effective_to->format('d M Y') : ' until resolved' }}</td>
                            <td><x-status-badge :status="$restriction->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif

@if($isHead && $headView === 'restrictions' && $activeRestrictions->isEmpty())
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>No active borrowing restrictions.</strong>
                <p>Restrictions linked to resolved, settled, waived, or cleared obligations no longer appear in this active list.</p>
            </div>
        </div>
    </article>
</section>
@endif

@if((($isHead && $headView === 'head_review') || $workspace === 'BORROWER') && $sanctions->isNotEmpty())
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Administrative history</p>
                <h2>{{ $workspace === 'BORROWER' ? 'My Sanctions' : 'Sanction History' }}</h2>
                <p class="meta">Sanctions shown here are case decisions recorded by the SPMU Head. Financial charges remain separate under Billing Statements.</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        @if($isHead)<th>Borrower</th>@endif
                        <th>Offense</th>
                        <th>Administrative Action</th>
                        <th>Academic Period</th>
                        <th>Effective</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sanctions as $sanction)
                        <tr>
                            @if($isHead)<td>{{ $sanction->borrower->full_name }}</td>@endif
                            <td>{{ $sanction->offense_no }}</td>
                            <td>
                                <strong>{{ $sanction->sanction_label }}</strong>
                                @if($sanction->remarks)<small>{{ $sanction->remarks }}</small>@endif
                            </td>
                            <td>{{ $sanction->academicPeriod?->academic_year }} {{ $sanction->academicPeriod?->term_name }}</td>
                            <td>
                                {{ optional($sanction->effective_from)->format('d M Y') }}
                                {{ $sanction->effective_to ? ' – '.$sanction->effective_to->format('d M Y') : '' }}
                            </td>
                            <td><x-status-badge :status="$sanction->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif
@endsection
