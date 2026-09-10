@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Obligations' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Accountability Oversight' : 'Accountability Processing')])
@section('content')
@php
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';
    $pageTitle = $workspace === 'BORROWER' ? 'My Obligations' : ($isHead ? 'Accountability Oversight' : 'Accountability Processing');

    $activeRestrictions = $restrictions->where('status', 'ACTIVE');
    $openOverdueCases = $overdueCases->whereNotIn('status', ['RESOLVED']);

    /* Only a confirmed assessment is waiting on the SPMU Head. */
    $headReviewOverdueCases = $overdueCases->where(
        'status',
        App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL
    );
    $currentlyOverdueCases = $openOverdueCases->where('status', 'OVERDUE');
    /* Every stage after the physical return counts as returned late. */
    $returnedLateCases = $openOverdueCases->whereIn('status', [
        App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION,
        App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL,
        App\Services\LateReturnService::STATUS_AWAITING_PAYMENT,
    ]);
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
    $headReviewCount = $pendingViolations->count()
        + $headReviewIncidents->count()
        + $headReviewOverdueCases->count();
    $headView = $isHead ? request('view', $headReviewCount > 0 ? 'head_review' : 'cases') : null;
    if ($isHead && ! in_array($headView, ['head_review', 'cases', 'billings', 'restrictions', 'resolved'], true)) {
        $headView = 'cases';
    }

    $officerView = $isOfficer ? request('view', 'all') : null;
    if ($isOfficer && ! in_array($officerView, ['all', 'overdue', 'property', 'billings', 'restrictions', 'resolved'], true)) {
        $officerView = 'all';
    }

    $officerSelectedCount = $isOfficer
        ? match ($officerView) {
            'overdue' => $openOverdueCases->count(),
            'property' => $openIncidents->count(),
            'billings' => $openBillings->count(),
            'restrictions' => $activeRestrictions->count(),
            default => $openOverdueCases->count() + $openIncidents->count() + $openBillings->count() + $activeRestrictions->count(),
        }
        : 0;

    /*
     * Resolved history is a separate destination. The active queues stay
     * untouched, and none of the four counters above ever include it.
     */
    $showResolvedHistory = ($isHead && $headView === 'resolved')
        || ($isOfficer && $officerView === 'resolved');

    $officerViewLabel = match ($officerView) {
        'overdue' => 'Overdue / Late Returns',
        'property' => 'Property Cases',
        'billings' => 'Open Billings',
        'restrictions' => 'Active Restrictions',
        'resolved' => 'Resolved History',
        default => 'All Matters',
    };

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

.officer-accountability-note {
    display: grid;
    gap: 3px;
    margin-top: 12px;
    padding: 11px 12px;
    border-left: 4px solid #1d6fb8;
    border-radius: 8px;
    background: #f5f9fd;
    color: var(--text-secondary);
}
.officer-accountability-note strong { color: var(--text-primary); }
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
@include('accountability.partials.oversight-styles')
@endonce

@if($isHead && ! $isBorrower)
    @include('accountability.partials.option-b-styles')
    <div class="accountability-option-b">
@endif

<section class="page-heading accountability-page-heading">
    <div>
        <p class="eyebrow">{{ $isBorrower ? 'My accountability' : 'Accountability' }}</p>
        <h1>{{ $pageTitle }}</h1>
        <p>
            {{ $workspace === 'BORROWER'
                ? 'See unresolved obligations that affect your borrowing eligibility and what you need to resolve next.'
                : 'Monitor overdue returns, late-return assessments, billings, and restrictions.' }}
        </p>
    </div>
    @unless($isBorrower)
        {{-- The figures on this page are read live, so the page says when. --}}
        <p class="accountability-asof">
            <x-icon name="calendar" size="14" />
            <span>As of {{ now()->format('d M Y, h:i A') }}</span>
        </p>
    @endunless
</section>

@unless($isBorrower)
@php
    /*
     | The summary row and the tab row answer two different questions.
     |
     | The tabs are the working queues: Head Review, Billing, Restrictions are
     | places to go and their counts belong on them. The cards above are the
     | state of accountability as a whole, so none of them repeats a tab.
     |
     | Nothing here queries. Every figure is a re-read of collections the
     | controller already loaded.
     */
    $accountabilityView = $isHead ? $headView : $officerView;

    /*
     | OPEN ACCOUNTABILITIES
     |
     | Counts cases, not their consequences. A billing and a restriction are
     | things a case produces, so including them would count one matter two or
     | three times; a case that has been billed is still its own overdue case
     | and is counted once, there. Violations already exclude any whose custody
     | carries an open incident, so a single custody is never counted twice.
     */
    $incidentCustodyIds = $openIncidents
        ->pluck('custody_transaction_id')
        ->filter()
        ->map(fn ($id): int => (int) $id);

    /*
     | An overdue case and a property incident raised against the same custody
     | are one accountability matter, not two, so the overdue side is dropped
     | where the incident already stands for it.
     */
    $openAccountabilities = $openIncidents->count()
        + $openOverdueCases->reject(
            fn ($case): bool => $case->custody_transaction_id !== null
                && $incidentCustodyIds->contains((int) $case->custody_transaction_id)
        )->count();

    /*
     | OUTSTANDING BALANCE
     |
     | What is genuinely still owed. A billing carries its penalties as lines,
     | so summing billings counts each obligation once - adding the penalties
     | or the overdue-case fees separately would count the same money twice.
     | Settled, waived and void billings are excluded outright, and a partly
     | paid billing contributes only what its verified payments have not yet
     | covered. Only a VERIFIED payment reduces the balance; one still awaiting
     | verification has not settled anything.
     */
    $outstandingBalance = $billings
        ->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID'])
        ->sum(function ($billing): float {
            $verified = $billing->payments
                ->where('status', 'VERIFIED')
                ->sum(fn ($payment): float => (float) $payment->amount);

            return max(0, (float) $billing->total_amount - $verified);
        });

    /*
     | RESOLVED THIS PERIOD
     |
     | This page carries no reporting-period control, so rather than invent one
     | the card reads the calendar month it is being viewed in and says so on
     | the card. resolvedHistory() already carries a real resolved_at for every
     | row: a verified payment date for a settled billing, otherwise the date
     | the record reached its final state.
     */
    $resolvedPeriodStart = now()->subDays(30)->startOfDay();
    $resolvedPeriodLabel = 'the last 30 days';

    $resolvedThisPeriod = $resolvedHistory
        ->filter(fn (array $row): bool => $row['resolved_at'] !== null
            && \Illuminate\Support\Carbon::parse($row['resolved_at'])->greaterThanOrEqualTo($resolvedPeriodStart))
        ->count();

    /*
     | Where each card goes. The first three open the Active Cases queue; the
     | two that describe a subset of it carry a focus hint the queue's own
     | client-side status filter reads, so the reader lands on the rows the
     | number was counted from. The hint narrows what is already on the page
     | and never changes which records the view returned.
     */
    /*
     | The four summary cards read across the workflow; the tabs below navigate
     | it. They deliberately do not share figures - a card that repeated a tab
     | count would be telling the reader something the tab already says.
     */
    $summaryCards = [
        [
            'tone' => 'open',
            'icon' => 'requests',
            'label' => 'Open Accountability Cases',
            'value' => $openAccountabilities,
            'note' => 'Unresolved cases',
            'meta' => 'Counted once per matter',
            'href' => route('accountability.index', ['view' => $isHead ? 'cases' : 'all']),
        ],
        [
            'tone' => 'overdue',
            'icon' => 'warning',
            'label' => 'Currently Overdue',
            'value' => $currentlyOverdueCases->count(),
            'note' => 'Awaiting return',
            'meta' => 'Not yet returned, as of today',
            'href' => route('accountability.index', ['view' => $isHead ? 'cases' : 'overdue', 'focus' => 'overdue']),
        ],
        [
            'tone' => 'balance',
            'icon' => 'coins',
            'label' => 'Outstanding Balance',
            'value' => 'PHP '.number_format($outstandingBalance, 2),
            'note' => 'Unpaid obligations',
            'meta' => 'Not covered by a verified payment',
            'href' => route('accountability.index', ['view' => 'billings']),
        ],
        [
            'tone' => 'resolved',
            'icon' => 'approval',
            'label' => 'Resolved This Period',
            'value' => $resolvedThisPeriod,
            'note' => 'Cleared cases',
            'meta' => 'Closed during '.$resolvedPeriodLabel,
            'href' => route('accountability.index', ['view' => 'resolved']),
        ],
    ];

    $accountabilityTabs = $isHead
        ? [
            ['view' => 'cases', 'label' => 'Active Cases', 'count' => $openCaseCount],
            ['view' => 'head_review', 'label' => 'Head Review', 'count' => $headReviewCount],
            ['view' => 'billings', 'label' => 'Billing', 'count' => $openBillings->count()],
            ['view' => 'restrictions', 'label' => 'Restrictions', 'count' => $activeRestrictions->count()],
            ['view' => 'resolved', 'label' => 'Resolved History', 'count' => $resolvedHistory->count()],
        ]
        : [
            ['view' => 'all', 'label' => 'All Matters', 'count' => $borrowerRecordCount],
            ['view' => 'overdue', 'label' => 'Overdue / Late Returns', 'count' => $openOverdueCases->count()],
            ['view' => 'property', 'label' => 'Property Cases', 'count' => $openIncidents->count()],
            ['view' => 'billings', 'label' => 'Billing', 'count' => $openBillings->count()],
            ['view' => 'restrictions', 'label' => 'Restrictions', 'count' => $activeRestrictions->count()],
            ['view' => 'resolved', 'label' => 'Resolved History', 'count' => $resolvedHistory->count()],
        ];
@endphp

<section class="accountability-overview" aria-label="Accountability summary">
    @foreach($summaryCards as $card)
        <a
            class="accountability-overview-card tone-{{ $card['tone'] }}"
            href="{{ $card['href'] }}"
            aria-label="{{ $card['label'] }}: {{ $card['value'] }}. {{ $card['note'] }}"
        >
            <span class="accountability-overview-icon" aria-hidden="true"><x-icon :name="$card['icon']" size="22" /></span>
            <span class="accountability-overview-label">{{ $card['label'] }}</span>
            <strong class="accountability-overview-value">{{ $card['value'] }}</strong>
            <span class="accountability-overview-note">{{ $card['note'] }}</span>
            <x-icon name="chevron-right" size="15" class="accountability-overview-arrow" />
        </a>
    @endforeach
</section>

@php
    /* One icon per destination. */
    $accountabilityTabIcons = [
        'all' => 'clipboard-check',
        'cases' => 'plus-circle',
        'overdue' => 'calendar-clock',
        'property' => 'accountability',
        'head_review' => 'warning',
        'billings' => 'requests',
        'restrictions' => 'lock',
        'resolved' => 'clipboard-check',
    ];
@endphp

<nav class="accountability-tabs" aria-label="Accountability views">
    @foreach($accountabilityTabs as $tab)
        <a
            class="accountability-tab {{ $accountabilityView === $tab['view'] ? 'is-active' : '' }}"
            href="{{ route('accountability.index', ['view' => $tab['view']]) }}"
            aria-current="{{ $accountabilityView === $tab['view'] ? 'page' : 'false' }}"
        >
            <x-icon :name="$accountabilityTabIcons[$tab['view']] ?? 'clipboard-check'" size="15" />
            <span>{{ $tab['label'] }}</span>
        </a>
    @endforeach
</nav>
@endunless

@if(! $isBorrower && $showResolvedHistory)
    @include('accountability.partials.resolved-history')
@endif

@if($isBorrower)
@include('accountability.partials.obligations-workspace')
@endif

@if(! $isBorrower && ! $hasOpenMatters && ! $showResolvedHistory)
<section class="content-area">
    <article class="card accountability-empty">
        <strong>No accountability matters need {{ $isOfficer ? 'processing' : 'attention' }}.</strong>
        <span>{{ $isOfficer
            ? 'No overdue returns, property cases, open billings, or active restrictions.'
            : 'No pending Head decisions, property cases, unpaid billings, or active restrictions.' }}</span>
    </article>
</section>
@endif

@if($isOfficer && $hasOpenMatters && $officerView !== 'all' && $officerSelectedCount === 0)
<section class="content-area">
    <article class="card accountability-empty">
        <strong>No {{ $officerViewLabel }} need processing.</strong>
        <span>This view is clear. Select another tab above.</span>
    </article>
</section>
@endif

@if($isHead && $headView === 'head_review' && $pendingViolations->isNotEmpty())
<section class="content-area">
    <div class="section-heading accountability-section-heading">
        <div>
            <p class="eyebrow">Administrative accountability</p>
            <h2>Administrative Review</h2>
            <p>Violations that require an SPMU Head decision. Property findings and financial follow-up stay in their own sections.</p>
        </div>
        <span class="status-badge status-neutral">
            {{ $pendingViolations->count() }}
            {{ $pendingViolations->count() === 1 ? 'violation' : 'violations' }}
        </span>
    </div>

    @foreach($pendingViolations as $violation)
        @php
            $violationDetails = is_array($violation->details_json) ? $violation->details_json : [];
            $violationReasons = collect($violationDetails['reasons'] ?? [])->map(fn ($reason) => strtoupper((string) $reason));
            $isLateReturnViolation = $violationReasons->contains('LATE_RETURN');
            $offensePreview = $violationOffensePreviews[$violation->id] ?? null;
            $effectiveReturnDate = data_get($violationDetails, 'effective_return_date');
            $actualReturnDate = data_get($violationDetails, 'actual_return_date');
            $lateDays = ($effectiveReturnDate && $actualReturnDate)
                ? max(0, \Carbon\CarbonImmutable::parse($effectiveReturnDate)->diffInDays(\Carbon\CarbonImmutable::parse($actualReturnDate)))
                : null;
        @endphp
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
                    <dd>{{ $violationReasons->map(fn ($reason) => str($reason)->replace('_', ' ')->title())->join(', ') ?: 'Borrowing violation' }}</dd>
                </div>
                <div>
                    <dt>Academic Period</dt>
                    <dd>{{ $offensePreview['academic_period_label'] ?? ($violation->academicPeriod ? $violation->academicPeriod->academic_year.' · '.$violation->academicPeriod->term_name : 'Uses active period when confirmed') }}</dd>
                </div>
                @if($isLateReturnViolation)
                    <div>
                        <dt>Expected Return</dt>
                        <dd>{{ $effectiveReturnDate ? \Carbon\CarbonImmutable::parse($effectiveReturnDate)->format('d M Y') : '—' }}</dd>
                    </div>
                    <div>
                        <dt>Actual Return</dt>
                        <dd>{{ $actualReturnDate ? \Carbon\CarbonImmutable::parse($actualReturnDate)->format('d M Y') : '—' }}{{ $lateDays !== null ? ' · '.$lateDays.' day'.($lateDays === 1 ? '' : 's').' late' : '' }}</dd>
                    </div>
                @endif
            </dl>

            @if($offensePreview)
                <div class="callout {{ $offensePreview['is_enabled'] ? 'info' : 'warning' }} top-gap">
                    @if($isLateReturnViolation)
                        <strong>Late return detected — Head review is required before it counts as an offense.</strong>
                        <p>The physical return is already recorded. If the SPMU Head confirms this violation, it will be recorded as the borrower's <strong>{{ $offensePreview['next_offense_label'] }}</strong> for this academic period, using the configured action <strong>{{ $offensePreview['configured_sanction_label'] }}</strong> unless an authorized case-specific override is selected.</p>
                        <p>Any date-based late-return fee is handled separately under Overdue / Late Return Cases. Confirming or dismissing the administrative offense does not remove a valid financial obligation.</p>
                    @else
                        <strong>Administrative offense review</strong>
                        <p>If confirmed, this case will be recorded as the borrower's <strong>{{ $offensePreview['next_offense_label'] }}</strong> for this academic period. Configured action: <strong>{{ $offensePreview['configured_sanction_label'] }}</strong>.</p>
                    @endif

                    @unless($offensePreview['is_enabled'])
                        <p>This violation type is currently not enabled under Operational Configuration → Sanction Rules → Offense Application, so it cannot be confirmed as an offense unless that policy is enabled.</p>
                    @endunless
                </div>
            @endif

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
                    <button class="button primary" name="decision" value="CONFIRMED" @disabled($offensePreview && ! $offensePreview['is_enabled'])>Confirm Violation & Record Sanction</button>
                    <button class="button secondary" name="decision" value="DISMISSED">Dismiss Violation</button>
                </div>
            </form>
        </article>
    @endforeach
</section>
@endif

@php
    /*
     * The Head Review view shows only what needs a Head decision, so it lists
     * the confirmed assessments rather than every open case.
     */
    $visibleOverdueCases = $isHead && $headView === 'head_review'
        ? $headReviewOverdueCases
        : $openOverdueCases;
@endphp
@php
    /* Column-width labels for the Status cell. Presentation only. */
    $caseShortLabels = [
        App\Services\LateReturnService::STATUS_OVERDUE => 'Overdue',
        App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION => 'Late Return',
        App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL => 'For Head Approval',
        App\Services\LateReturnService::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
    ];
@endphp
@if(! $isBorrower && $visibleOverdueCases->isNotEmpty() && (! $isHead || in_array($headView, ['cases', 'head_review'], true)) && (! $isOfficer || in_array($officerView, ['all', 'overdue'], true)))
<section class="content-area">
    <article class="card accountability-cases-card">
        <div class="accountability-cases-head">
            <h2>
                Active Accountability Cases
                <span class="accountability-count-chip">{{ $visibleOverdueCases->count() }}</span>
            </h2>

            {{--
                Both controls narrow the rows already on this page. Neither
                queries the server, so nothing here can change which cases the
                view returned.
            --}}
            <div class="accountability-cases-tools">
                <div class="accountability-search">
                    <x-icon name="search" size="15" />
                    <label class="visually-hidden" for="accountability-case-search">Search borrower or custody no.</label>
                    <input
                        id="accountability-case-search"
                        type="search"
                        autocomplete="off"
                        placeholder="Search borrower or custody no..."
                    >
                </div>

                <div class="accountability-filter">
                    <button
                        type="button"
                        id="accountability-filter-toggle"
                        class="icon-button accountability-filter-button"
                        aria-expanded="false"
                        aria-controls="accountability-filter-menu"
                        title="Filter cases by status"
                    >
                        <x-icon name="filter" size="15" />
                        <span class="visually-hidden">Filter cases by status</span>
                    </button>

                    <div id="accountability-filter-menu" class="accountability-filter-menu" hidden>
                        <p class="accountability-filter-menu__heading">Status</p>
                        @foreach($visibleOverdueCases->pluck('status')->unique()->values() as $caseStatus)
                            <label>
                                <input type="checkbox" value="{{ $caseStatus }}" checked>
                                <span>{{ App\Services\LateReturnService::label($caseStatus) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="table-wrap accountability-cases-table">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Reference / Borrower</th>
                        <th scope="col">Status</th>
                        <th scope="col">Expected Return</th>
                        <th scope="col">Est. Fee</th>
                        <th scope="col">Rate</th>
                        <th scope="col" class="is-numeric">Days</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody id="accountability-cases-body">
                    @foreach($visibleOverdueCases as $overdue)
                        @php
                            /*
                             * Overdue and Returned Late are different states. Only a case with
                             * a recorded physical return date carries a final assessment; an
                             * overdue one shows a growing estimate and no approval control.
                             */
                            $lateReturns = app(App\Services\LateReturnService::class);
                            $assessment = $lateReturns->assessment($overdue);

                            $isStillOverdue = $overdue->status === App\Services\LateReturnService::STATUS_OVERDUE;
                            $forOfficerConfirmation = $overdue->status === App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION;
                            $forHeadApproval = $overdue->status === App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL;
                            $hasBilling = $overdue->status === App\Services\LateReturnService::STATUS_AWAITING_PAYMENT;
                            $fromLaundry = $assessment['return_date_source'] === 'LAUNDRY_RECEIPT';

                            /* The Head's two forms are too large for a cell, so they sit in the detail row. */
                            $headCanDecide = $isHead && $forHeadApproval;
                        @endphp

                        <tr
                            class="accountability-case-row"
                            data-case
                            data-status="{{ $overdue->status }}"
                            data-search="{{ Str::lower($overdue->custody->custody_no.' '.$overdue->borrower->full_name) }}"
                        >
                            <td>
                                <span class="accountability-case-ref">{{ $overdue->custody->custody_no }}</span>
                                <span class="accountability-case-borrower">{{ $overdue->borrower->full_name }}</span>
                            </td>
                            <td>
                                {{--
                                    A cell needs the stage, not the sentence. The full
                                    label stays on the badge as its title, and the row
                                    detail below spells the stage out either way.
                                --}}
                                <x-status-badge
                                    :status="$overdue->status"
                                    :label="$caseShortLabels[$overdue->status] ?? App\Services\LateReturnService::label($overdue->status)"
                                    :title="App\Services\LateReturnService::label($overdue->status)"
                                    class="accountability-status-pill"
                                />
                            </td>
                            <td>{{ $overdue->custody->due_at->format('d M Y') }}</td>
                            <td>
                                {{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}
                                <small>{{ $assessment['is_estimate'] ? 'Estimated Fee So Far' : 'Final Late Return Fee' }}</small>
                            </td>
                            <td>{{ $assessment['rate'] === null ? 'Not set' : 'PHP '.number_format($assessment['rate'], 2).'/day' }}</td>
                            <td class="is-numeric">{{ $assessment['late_days'] }}</td>
                            <td>
                                @if($isOfficer && $forOfficerConfirmation)
                                    <form method="post" action="{{ route('overdue.confirm-late-return', $overdue) }}">
                                        @csrf
                                        <button class="button primary ui-pressable accountability-row-action">Confirm Late Return</button>
                                    </form>
                                @elseif($headCanDecide)
                                    <span class="accountability-row-note">Decide below</span>
                                @else
                                    <span class="accountability-row-none">&mdash;</span>
                                @endif
                            </td>
                        </tr>

                        {{-- Key detail for the row above: what the figures mean and what happens next. --}}
                        <tr class="accountability-case-detail-row">
                            <td colspan="7">
                                @if($isStillOverdue)
                                    <div class="accountability-detail-panel is-warning">
                                        <x-icon name="warning" size="17" />
                                        <div>
                                            <strong>Still overdue &mdash; figures are an estimate.</strong>
                                            <p>
                                                Awaiting borrower return. The fee shown may increase
                                                for each additional late day.
                                            </p>
                                            <small>A final Late Return Fee Form cannot be issued yet.</small>
                                        </div>
                                    </div>
                                @elseif($forOfficerConfirmation)
                                    <div class="accountability-detail-panel is-info">
                                        <x-icon name="information" size="17" />
                                        <div>
                                            <strong>Late return recorded &mdash; figures are final for this return date.</strong>
                                            <p>
                                                {{ $fromLaundry ? 'The return date is the date Laundry Operations received the linen, not the date the accomplished Laundry Form reached SPMU.' : '' }}
                                                Confirm the assessment so the SPMU Head can approve it.
                                            </p>
                                            @if($overdue->correction_remarks)
                                                <small>Returned for correction: {{ $overdue->correction_remarks }}</small>
                                            @else
                                                <small>
                                                    {{ $fromLaundry ? 'Laundry Received' : 'Actual Return' }}:
                                                    {{ $assessment['actual_return_date']?->format('d M Y') ?? 'date not recorded' }}
                                                    &middot; Late Days: {{ $assessment['late_days'] }}
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                @elseif($forHeadApproval)
                                    <div class="accountability-detail-panel is-info">
                                        <x-icon name="information" size="17" />
                                        <div>
                                            <strong>Awaiting Head approval.</strong>
                                            <p>
                                                Confirmed by {{ $overdue->confirmedBy?->full_name ?? 'the Action Officer' }}
                                                on {{ $overdue->ao_confirmed_at?->format('d M Y, h:i A') }}.
                                                Approving generates the Late Return Fee Form for the recorded amount.
                                            </p>
                                            <small>Returned {{ $assessment['actual_return_date']?->format('d M Y') ?? 'date not recorded' }}.</small>
                                        </div>
                                    </div>
                                @elseif($hasBilling)
                                    <div class="accountability-detail-panel is-info">
                                        <x-icon name="information" size="17" />
                                        <div>
                                            <strong>Approved &mdash; awaiting payment.</strong>
                                            <p>The Late Return Fee Form has been issued. Continue payment processing under Billing.</p>
                                            <small>Awaiting Cashier payment.</small>
                                        </div>
                                    </div>
                                @endif

                                {{-- The Head decides here; the Action cell above only points to it. --}}
                                @if($headCanDecide)
                                    <div class="accountability-detail-forms">
                                        <form method="post" action="{{ route('overdue.bill', $overdue) }}" class="form-grid">
                                            @csrf
                                            <label>
                                                Approval Basis
                                                <textarea name="basis" required placeholder="State the applicable late-return fee basis."></textarea>
                                            </label>
                                            <label>
                                                Payment Due Date
                                                <input type="date" name="due_at">
                                            </label>
                                            <button class="button primary ui-pressable accountability-primary-action">Approve Late Return Assessment</button>
                                        </form>

                                        <form method="post" action="{{ route('overdue.return-for-correction', $overdue) }}" class="form-grid">
                                            @csrf
                                            <label>
                                                Reason for Correction
                                                <textarea name="remarks" required placeholder="State what the Action Officer must recheck."></textarea>
                                            </label>
                                            <button class="button secondary ui-pressable">Return for Correction</button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p id="accountability-cases-none" class="accountability-cases-none" hidden>
            No case matches the current search or status filter.
        </p>
    </article>

    @include('accountability.partials.cases-interactions')

    @unless($isHead)
    <article class="card accountability-scope-note">
        <x-icon name="information" size="17" />
        <div>
            <strong>Only active cases are shown here.</strong>
            <p>View completed cases in Resolved History.</p>
            <small>Overdue means the item is not yet returned. Returned Late means the return is recorded and the final fee can be processed.</small>
        </div>
    </article>
    @endunless
</section>
@endif

@php
    $displayIncidents = $isHead && $headView === 'head_review'
        ? $headReviewIncidents
        : $openIncidents;
@endphp
@if(! $isBorrower && $displayIncidents->isNotEmpty() && (! $isHead || in_array($headView, ['head_review', 'cases'], true)) && (! $isOfficer || in_array($officerView, ['all', 'property'], true)))
<section class="content-area">
    <div class="section-heading head-control-heading accountability-section-heading">
        <div>
            <p class="eyebrow">Property accountability</p>
            <h2>{{ $isHead && $headView === 'head_review' ? 'Cases Awaiting Head Decision' : 'Property Accountability Cases' }}</h2>
            <p>{{ $isHead && $headView === 'head_review'
                ? 'Enter the formal SPMU Head decision. Cases already routed to billing or compliance stay under Active Cases.'
                : ($isOfficer
                    ? 'Recorded findings and evidence. Open cases wait for the SPMU Head decision; after it, complete only the assigned follow-up.'
                    : 'Open until the required decision, compliance, billing settlement, or formal clearance is completed.') }}</p>
        </div>
        <span class="status-badge status-neutral">
            {{ $displayIncidents->count() }}
            {{ $displayIncidents->count() === 1 ? 'case' : 'cases' }}
        </span>
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
        <article class="card top-gap head-case-card accountability-list-card" id="incident-{{ $incident->id }}">
            <div class="card-header">
                <div>
                    <p class="eyebrow">Property Case</p>
                    <strong>{{ $incident->incident_no }}</strong>
                    <h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3>
                    <small>Reported {{ optional($incident->reported_at)->format('d M Y, g:i A') ?: '—' }}</small>
                </div>
                <x-status-badge :status="$incident->status" :label="$isOfficer && $incident->status === 'OPEN' ? 'Awaiting Head Decision' : null" />
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

            @if($isOfficer && $incident->status === 'OPEN')
                <div class="officer-accountability-note">
                    <strong>Awaiting Head Decision</strong>
                    <span>The physical findings and supporting evidence are recorded. The Action Officer does not decide borrower liability, administrative offense, sanction, or restriction. Wait for the SPMU Head decision before continuing financial or compliance processing.</span>
                </div>
            @elseif($isOfficer && $incident->status === 'COMPLIANCE_REQUIRED')
                <div class="officer-accountability-note">
                    <strong>Head decision: compliance required</strong>
                    <span>Coordinate the required repair, replacement, or compliance with SPMU. Final confirmation that the case is resolved remains a Head-level action in the current workflow.</span>
                </div>
            @elseif($isOfficer && $incidentHasBilling)
                <div class="officer-accountability-note">
                    <strong>Billing Statement already issued</strong>
                    <span>Continue payment-evidence processing under Open Billings. Upload the paid CSPC Cashier receipt and verify the recorded payment evidence there.</span>
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
{{--
    An empty property queue is only worth stating when the view would otherwise
    be blank. With active cases on screen the page already says what it holds.
--}}
@elseif($isHead && in_array($headView, ['head_review', 'cases'], true) && $visibleOverdueCases->isEmpty())
<section class="content-area">
    <article class="card accountability-empty">
        <strong>{{ $headView === 'head_review' ? 'No property cases await a Head decision.' : 'No open property accountability cases.' }}</strong>
        <span>{{ $headView === 'head_review' ? 'Cases routed to billing or compliance stay under Active Cases.' : 'Nothing unresolved in this view.' }}</span>
    </article>
</section>
@endif

@if(! $isBorrower && $openBillings->isNotEmpty() && (! $isHead || $headView === 'billings') && (! $isOfficer || in_array($officerView, ['all', 'billings'], true)))
<section class="content-area">
    <div class="section-heading accountability-section-heading">
        <div>
            <p class="eyebrow">Cashier payment evidence</p>
            <h2>Open Billing Statements</h2>
            @if($isOfficer)
                <p>Check the official CSPC Cashier receipt, record its details, and confirm the payment in one step.</p>
            @endif
        </div>
        <span class="status-badge status-neutral">
            {{ $openBillings->count() }}
            {{ $openBillings->count() === 1 ? 'billing' : 'billings' }}
        </span>
    </div>
    @foreach($openBillings as $billing)
<article class="card top-gap accountability-list-card"><div class="card-header"><div><strong>{{ $billing->billing_no }}</strong><h3>PHP {{ number_format((float)$billing->total_amount,2) }}</h3><small>{{ $billing->borrower->full_name }}</small></div><x-status-badge :status="$billing->status" /></div>
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement / Assessment</a>@endforeach</div>
<p class="meta">The borrower pays at the CSPC Cashier and presents the official receipt to the Action Officer. Confirm the payment only after checking the receipt.</p>
@if($isOfficer && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Record Cashier Payment</h4><small>Check the official receipt against the billing before verifying. Verifying settles this billing and resolves the accountability case.</small></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks <small>(Optional)</small><textarea name="remarks"></textarea></label><button class="button primary ui-pressable accountability-primary-action">Verify and Mark as Paid</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">View scanned Cashier receipt</a>@endif</div>
@if($payment->status==='PENDING_VERIFICATION')<small class="meta">Legacy payment record from the previous two-step workflow.</small>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
@if($isHead && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))<details class="top-gap"><summary>Authorized billing waiver</summary><form method="post" action="{{ route('billings.waive',$billing) }}" class="form-grid top-gap">@csrf<label>Waiver reason<textarea name="reason" required></textarea></label><button class="button danger">Record Authorized Waiver</button></form></details>@endif
</article>
    @endforeach
</section>
@endif

@if($isHead && $headView === 'billings' && $openBillings->isEmpty())
<section class="content-area">
    <article class="card accountability-empty">
        <strong>No open Billing Statements.</strong>
        <span>Charges appear here once the approved amount is recorded and the statement is generated.</span>
    </article>
</section>
@endif

@if(! $isBorrower && $activeRestrictions->isNotEmpty() && (! $isHead || $headView === 'restrictions') && (! $isOfficer || in_array($officerView, ['all', 'restrictions'], true)))
<section class="content-area">
    <article class="card accountability-table-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Borrowing eligibility</p>
                <h2>Active Restrictions</h2>
                @if($isOfficer)
                    <p class="accountability-case-meta">Reference only. Action Officers cannot create, extend, lift, or override a restriction.</p>
                @endif
            </div>
            <span class="status-badge status-neutral">
                {{ $activeRestrictions->count() }}
                {{ $activeRestrictions->count() === 1 ? 'restriction' : 'restrictions' }}
            </span>
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
    <article class="card accountability-empty">
        <strong>No active borrowing restrictions.</strong>
        <span>Restrictions linked to resolved, settled, waived, or cleared obligations drop off this list.</span>
    </article>
</section>
@endif

@if((($isHead && $headView === 'head_review') || $workspace === 'BORROWER') && $sanctions->isNotEmpty())
<section class="content-area">
    <article class="card accountability-table-card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Administrative history</p>
                <h2>{{ $workspace === 'BORROWER' ? 'My Sanctions' : 'Sanction History' }}</h2>
                <p class="accountability-case-meta">Case decisions recorded by the SPMU Head. Financial charges stay separate under Billing.</p>
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

{{-- BORROWER_ACCOUNTABILITY_CLEAN_FILTER_LAYOUT --}}
@if($isHead && ! $isBorrower)
    @if($headView === 'cases')
        <aside class="accountability-scope-note">
            <x-icon name="information" size="20" />
            <div>
                <strong>Only active cases are shown here.</strong>
                <p>View completed cases in <a href="{{ route('accountability.index', ['view' => 'resolved']) }}">Resolved History</a>.</p>
            </div>
        </aside>
    @endif
    </div>
@endif

<style>
@media (min-width: 901px) {
    .borrower-accountability-toolbar {
        grid-template-columns:
            minmax(320px, 1fr)
            minmax(170px, 220px)
            minmax(145px, 180px) !important;
    }
}

@media (min-width: 621px) and (max-width: 900px) {
    .borrower-accountability-toolbar {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .borrower-accountability-search {
        grid-column: 1 / -1;
    }
}

@media (max-width: 620px) {
    .borrower-accountability-toolbar {
        grid-template-columns: 1fr !important;
    }
}
</style>
@endsection
