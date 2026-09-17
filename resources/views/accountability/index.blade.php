@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Obligations' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Accountability Oversight' : 'Accountability Processing')])
@section('content')
@php
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';
    $pageTitle = $workspace === 'BORROWER' ? 'My Obligations' : ($isHead ? 'Accountability Oversight' : 'Accountability Processing');

    $activeRestrictions = $restrictions->filter(
        fn ($restriction) => $restriction->status === 'ACTIVE'
            && ($restriction->effective_from === null || $restriction->effective_from->lte(now()))
            && ($restriction->effective_to === null || $restriction->effective_to->gt(now()))
    );
    $openOverdueCases = $overdueCases->whereNotIn('status', ['RESOLVED']);

    /* Only a finalized late-return assessment is waiting on the SPMU Head. */
    $headReviewOverdueCases = $overdueCases->where(
        'status',
        App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL
    );
    $currentlyOverdueCases = $openOverdueCases->where(
        'status',
        App\Services\LateReturnService::STATUS_OVERDUE
    );
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
    /*
     * Keep the Accountability workspace simple: broad queues here, detailed
     * progression inside each case's numbered stepper. Legacy view parameters
     * are normalized so old links continue to open the correct workspace.
     */
    $headView = $isHead ? request('view', 'cases') : null;
    if ($isHead && in_array($headView, ['head_review', 'billings'], true)) {
        $headView = 'cases';
    }
    if ($isHead && ! in_array($headView, ['cases', 'restrictions', 'resolved'], true)) {
        $headView = 'cases';
    }

    $officerView = $isOfficer ? request('view', 'cases') : null;
    if ($isOfficer && in_array($officerView, ['all', 'property', 'billings'], true)) {
        $officerView = 'cases';
    }
    if ($isOfficer && ! in_array($officerView, ['cases', 'overdue', 'restrictions', 'resolved'], true)) {
        $officerView = 'cases';
    }

    /*
     * One custody can produce both a property incident and a late-return case.
     * Count that as one accountability matter. A billing linked to either case
     * is a consequence of that matter, not a second case; only genuinely
     * standalone billing statements add another row to the Cases workspace.
     */
    $incidentCustodyIdsForCount = $openIncidents
        ->pluck('custody_transaction_id')
        ->filter()
        ->map(fn ($id): int => (int) $id)
        ->unique();
    $openCaseCount = $openIncidents->count()
        + $openOverdueCases->reject(
            fn ($case): bool => $case->custody_transaction_id !== null
                && $incidentCustodyIdsForCount->contains((int) $case->custody_transaction_id)
        )->count();

    $openIncidentIdsForCount = $openIncidents->pluck('id')->map(fn ($id): int => (int) $id);
    $openOverdueIdsForCount = $openOverdueCases->pluck('id')->map(fn ($id): int => (int) $id);
    $standaloneOpenBillingCount = $openBillings->reject(function ($billing) use ($openIncidentIdsForCount, $openOverdueIdsForCount): bool {
        return $billing->lines->contains(function ($line) use ($openIncidentIdsForCount, $openOverdueIdsForCount): bool {
            $incidentId = (int) ($line->incident_id ?? 0);
            $overdueId = (int) ($line->penalty?->overdue_case_id ?? 0);

            return ($incidentId > 0 && $openIncidentIdsForCount->contains($incidentId))
                || ($overdueId > 0 && $openOverdueIdsForCount->contains($overdueId));
        });
    })->count();
    $casesTabCount = $openCaseCount + $standaloneOpenBillingCount;

    $officerSelectedCount = $isOfficer
        ? match ($officerView) {
            'overdue' => $openOverdueCases->count(),
            'restrictions' => $activeRestrictions->count(),
            'cases' => $casesTabCount,
            default => 0,
        }
        : 0;

    /* Resolved history remains a separate destination from all active queues. */
    $showResolvedHistory = ($isHead && $headView === 'resolved')
        || ($isOfficer && $officerView === 'resolved');

    $officerViewLabel = match ($officerView) {
        'overdue' => 'Overdue / Late Returns',
        'restrictions' => 'Active Restrictions',
        'resolved' => 'Resolved History',
        default => 'Cases',
    };

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
.officer-accountability-card {
    position: relative;
    display: grid;
    gap: 6px;
    min-height: 150px;
    padding: 18px 20px;
    color: inherit;
    text-decoration: none;
    transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}
.officer-accountability-card:hover {
    transform: translateY(-1px);
    border-color: #8abbe8;
    box-shadow: 0 8px 20px rgba(15, 74, 125, .08);
}
.officer-accountability-card.is-active {
    border-color: #1d6fb8;
    background: #f4f9fe;
    box-shadow: inset 0 3px 0 #1d6fb8;
}
.officer-accountability-card .kpi-icon { margin-bottom: 4px; }
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
.officer-view-actions { margin-top: 12px; }
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
.head-offense-review { display:grid; gap:10px; padding:12px; border:1px solid #cfe0ef; border-radius:10px; background:#fff; }
.head-offense-review__heading { display:grid; gap:3px; }
.head-offense-review__heading > span { color:#155d9d; font-size:9px; font-weight:850; letter-spacing:.06em; text-transform:uppercase; }
.head-offense-review__heading > strong { color:var(--text-primary); font-size:12px; }
.head-offense-review__heading > p { margin:0; color:var(--text-secondary); font-size:10px; line-height:1.5; }
.head-offense-choices { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
.head-offense-choice { position:relative; display:grid !important; grid-template-columns:18px minmax(0,1fr); align-items:start; gap:9px !important; margin:0 !important; padding:11px 12px; border:1px solid var(--border); border-radius:9px; background:var(--surface); cursor:pointer; }
.head-offense-choice:hover { border-color:#8abbe8; background:#f8fbfe; }
.head-offense-choice:has(input:checked) { border-color:#1d6fb8; background:#f4f9fe; box-shadow:inset 0 0 0 1px #1d6fb8; }
.head-offense-choice input { width:17px !important; height:17px; margin:2px 0 0 !important; }
.head-offense-choice__copy { display:grid; gap:2px; }
.head-offense-choice__copy strong { color:var(--text-primary); font-size:11px; }
.head-offense-choice__copy small { color:var(--text-muted); font-size:9px; line-height:1.4; }
.head-offense-choice.is-disabled { opacity:.62; cursor:not-allowed; }
.head-offense-preview { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1px; border:1px solid var(--border); border-radius:9px; overflow:hidden; background:var(--border); }
.head-offense-preview > div { display:grid; gap:3px; padding:10px 11px; background:var(--surface); }
.head-offense-preview small { color:var(--text-muted); font-size:9px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.head-offense-preview strong { font-size:11px; overflow-wrap:anywhere; }
.head-offense-note { margin:0; color:var(--text-secondary); font-size:10px; line-height:1.5; }
.head-offense-state { padding:11px 12px; border-left:4px solid #1d6fb8; border-radius:8px; background:#f5f9fd; }
.head-offense-state strong { display:block; margin-bottom:3px; }

.accountability-case-workflow { display:grid; gap:14px; }
.accountability-case-identity { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; }
.accountability-case-identity h3 { margin:2px 0 2px; }
.accountability-case-ref { display:flex; flex-wrap:wrap; gap:6px 14px; margin-top:7px; color:var(--text-muted); font-size:10px; }
.accountability-case-ref strong { color:var(--text-secondary); }
.accountability-stepper {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    border:1px solid var(--border);
    border-radius:11px;
    overflow:hidden;
    background:var(--surface);
}
.accountability-step {
    position:relative;
    display:grid;
    grid-template-columns:30px minmax(0,1fr);
    align-items:center;
    gap:9px;
    min-height:66px;
    padding:11px 13px;
    border-right:1px solid var(--border);
}
.accountability-step:last-child { border-right:0; }
.accountability-step__number {
    display:grid;
    width:30px;
    height:30px;
    place-items:center;
    border:1px solid var(--border-strong);
    border-radius:50%;
    color:var(--text-muted);
    background:var(--surface-subtle);
    font-size:11px;
    font-weight:850;
}
.accountability-step__copy { display:grid; min-width:0; gap:2px; }
.accountability-step__copy strong { color:var(--text-primary); font-size:11px; }
.accountability-step__copy small { color:var(--text-muted); font-size:9px; line-height:1.3; }
.accountability-step.is-done .accountability-step__number { border-color:#159447; background:#159447; color:#fff; }
.accountability-step.is-active { background:#f4f9fe; box-shadow:inset 0 -3px 0 #1d6fb8; }
.accountability-step.is-active .accountability-step__number { border-color:#1d6fb8; background:#1d6fb8; color:#fff; }
.accountability-current-step {
    display:grid;
    gap:10px;
    padding:13px 14px;
    border:1px solid #c9dff2;
    border-radius:10px;
    background:#f8fbfe;
}
.accountability-current-step__heading { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
.accountability-current-step__heading > div { display:grid; gap:2px; }
.accountability-current-step__heading small { color:var(--text-muted); font-size:9px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
.accountability-current-step__heading strong { color:var(--text-primary); font-size:13px; }
.accountability-current-step__heading p { margin:0; color:var(--text-secondary); font-size:10px; }
.accountability-action-disclosure { margin:0; }
.accountability-action-disclosure > summary {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    min-height:38px;
    padding:0 14px;
    border:1px solid #1d6fb8;
    border-radius:8px;
    color:#155d9d;
    background:#fff;
    cursor:pointer;
    font-weight:800;
    font-size:11px;
    list-style:none;
}
.accountability-action-disclosure > summary::-webkit-details-marker { display:none; }
.accountability-action-disclosure[open] > summary { background:#eef6fd; }
.accountability-disclosure-chevron { flex:0 0 auto; transition:transform .16s ease; }
.accountability-action-disclosure[open] > summary .accountability-disclosure-chevron,
.accountability-case-details[open] > summary .accountability-disclosure-chevron { transform:rotate(180deg); }
.accountability-action-body { margin-top:10px; padding-top:12px; border-top:1px solid var(--border); }
.accountability-action-body .form-grid { gap:11px; }
.accountability-compact-offense { display:grid; gap:7px; padding:10px 11px; border:1px solid var(--border); border-radius:8px; background:var(--surface); }
.accountability-compact-offense label { margin:0; }
.accountability-compact-offense small { color:var(--text-muted); font-size:10px; line-height:1.45; }
.accountability-compact-offense strong { color:var(--text-secondary); }
.accountability-case-details { border-top:1px solid var(--border); padding-top:11px; }
.accountability-case-details > summary { display:inline-flex; align-items:center; gap:6px; color:#155d9d; cursor:pointer; font-size:10px; font-weight:800; list-style:none; }
.accountability-case-details > summary::-webkit-details-marker { display:none; }
.accountability-case-details .head-case-summary { margin-top:10px; }
.accountability-billing-brief { display:flex; flex-wrap:wrap; gap:6px 16px; color:var(--text-secondary); font-size:10px; }
.accountability-billing-brief strong { color:var(--text-primary); }
.accountability-decision-summary {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    align-items:stretch;
    gap:1px;
    margin:2px 0 0;
    border:1px solid var(--border);
    border-radius:9px;
    overflow:hidden;
    background:var(--border);
}
.accountability-decision-summary > div { display:grid; align-content:start; gap:3px; min-width:0; height:100%; padding:10px 11px; background:var(--surface); }
.accountability-decision-summary dt,
.accountability-compliance-brief small,
.accountability-head-instruction small { color:var(--text-muted); font-size:9px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.accountability-decision-summary dd { margin:0; color:var(--text-primary); font-size:10px; font-weight:750; line-height:1.4; overflow-wrap:anywhere; }
.accountability-owner-note,
.accountability-administrative-record,
.accountability-resolution-note { margin:0; color:var(--text-muted); font-size:9.5px; line-height:1.45; }
.accountability-administrative-record strong { color:var(--text-secondary); }
.accountability-compliance-brief {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    align-items:stretch;
    gap:1px;
    border:1px solid var(--border);
    border-radius:9px;
    overflow:hidden;
    background:var(--border);
}
.accountability-compliance-brief > div { display:grid; align-content:start; gap:3px; min-width:0; height:100%; padding:10px 11px; background:var(--surface); }
.accountability-compliance-brief strong { color:var(--text-primary); font-size:10.5px; line-height:1.4; overflow-wrap:anywhere; }
.accountability-head-instruction { padding:10px 11px; border-left:3px solid #1d6fb8; border-radius:7px; background:var(--surface-subtle); }
.accountability-head-instruction p { margin:3px 0 0; color:var(--text-secondary); font-size:10px; line-height:1.5; }
.accountability-current-step--compliance .accountability-action-disclosure { margin-top:1px; }
@media (max-width: 900px) {
    .accountability-stepper { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .accountability-decision-summary { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .accountability-compliance-brief { grid-template-columns:1fr; }
    .accountability-step:nth-child(2) { border-right:0; }
    .accountability-step:nth-child(-n+2) { border-bottom:1px solid var(--border); }
}
@media (max-width: 560px) {
    .accountability-stepper { grid-template-columns:1fr; }
    .accountability-decision-summary { grid-template-columns:1fr; }
    .accountability-step { border-right:0; border-bottom:1px solid var(--border); }
    .accountability-step:last-child { border-bottom:0; }
    .accountability-case-identity, .accountability-current-step__heading { flex-direction:column; }
}

@media (max-width: 900px) { .head-case-summary, .head-offense-preview, .head-offense-choices { grid-template-columns:1fr 1fr; } }
@media (max-width: 560px) { .head-case-summary, .head-offense-preview, .head-offense-choices { grid-template-columns:1fr; } }

</style>
@include('accountability.partials.oversight-styles')
<style>
/* Universal accountability visual language --------------------------------
   Shares its metrics with the dashboard KPI card (.dashboard-kpi-card) and
   the borrower summary card (.ob-summary-card): 46px icon column, 122px
   min-height, 12px radius, 3px top accent, 25/11/10px value/label/note
   type. Keep this the single definition - a second pass here previously
   re-declared the same rules to layer the accent on top, which just made
   the source harder to trust as the story of what renders. */
.accountability-overview {
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    margin-bottom:16px;
}
.accountability-overview-card {
    --accountability-card-accent: var(--info);
    --accountability-card-hover: rgba(23, 105, 224, .035);

    position:relative;
    display:grid;
    grid-template-columns:46px minmax(0,1fr);
    grid-template-rows:auto auto auto;
    column-gap:12px;
    row-gap:2px;
    min-height:122px;
    padding:16px 18px;
    border:1px solid var(--border);
    border-top:3px solid var(--accountability-card-accent);
    border-radius:12px;
    background:var(--surface);
    color:inherit;
    text-decoration:none;
    box-shadow:0 1px 2px rgba(7, 27, 53, .05);
    transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}
.accountability-overview-card.tone-open {
    --accountability-card-accent:var(--info);
    --accountability-card-hover:rgba(23, 105, 224, .075);
}
.accountability-overview-card.tone-overdue {
    --accountability-card-accent:var(--danger);
    --accountability-card-hover:rgba(220, 53, 69, .070);
}
.accountability-overview-card.tone-balance {
    --accountability-card-accent:var(--warning);
    --accountability-card-hover:rgba(217, 155, 22, .085);
}
.accountability-overview-card.tone-resolved {
    --accountability-card-accent:var(--success);
    --accountability-card-hover:rgba(21, 148, 71, .070);
}
.accountability-overview-card:hover,
.accountability-overview-card:focus-visible {
    transform:translateY(-1px);
    background:linear-gradient(var(--accountability-card-hover), var(--accountability-card-hover)), var(--surface);
    border-color:var(--border-strong);
    border-top-color:var(--accountability-card-accent);
    box-shadow:0 10px 24px rgba(7, 27, 53, .08);
}
.accountability-overview-card:focus-visible {
    outline:none;
    box-shadow:var(--focus-ring), 0 10px 24px rgba(7, 27, 53, .08);
}
html[data-theme="dark"] .accountability-overview-card:hover,
html[data-theme="dark"] .accountability-overview-card:focus-visible {
    background:var(--surface-hover);
}
/* No real destination for this role: same card language, no click affordance. */
.accountability-overview-card.is-static { cursor:default; }
.accountability-overview-card.is-static:hover,
.accountability-overview-card.is-static:focus-visible {
    transform:none;
    background:var(--surface);
    border-color:var(--border);
    border-top-color:var(--accountability-card-accent);
    box-shadow:0 1px 2px rgba(7, 27, 53, .05);
}
.accountability-overview-icon {
    grid-column:1;
    grid-row:1 / span 3;
    display:grid;
    width:44px;
    height:44px;
    place-items:center;
    align-self:center;
    border:1px solid var(--border);
    border-radius:50%;
    background:var(--surface-subtle);
    color:var(--text-secondary);
}
.accountability-overview-label {
    grid-column:2;
    grid-row:1;
    align-self:end;
    color:var(--text-secondary);
    font-size:11px;
    font-weight:800;
}
.accountability-overview-value {
    grid-column:2;
    grid-row:2;
    color:var(--heading);
    font-size:25px;
    line-height:1.15;
}
.accountability-overview-note {
    grid-column:2;
    grid-row:3;
    color:var(--text-muted);
    font-size:10px;
    line-height:1.35;
}
.accountability-overview-arrow {
    position:absolute;
    top:14px;
    right:14px;
    color:var(--text-soft);
    transition:opacity .16s ease, transform .16s ease;
}
.accountability-overview-card:hover .accountability-overview-arrow,
.accountability-overview-card:focus-visible .accountability-overview-arrow {
    opacity:.9;
    transform:translateX(2px);
}
.accountability-overview-card.tone-open .accountability-overview-icon {
    border-color:#cfe0f3;
    background:#edf5fd;
    color:#1d6fb8;
}
.accountability-overview-card.tone-overdue .accountability-overview-icon {
    border-color:#f1c6c2;
    background:#fff1ef;
    color:#c4493d;
}
.accountability-overview-card.tone-balance .accountability-overview-icon {
    border-color:#ead5ab;
    background:#fff8e9;
    color:#b9770e;
}
.accountability-overview-card.tone-resolved .accountability-overview-icon {
    border-color:#c3e5d0;
    background:#eefaf3;
    color:#159447;
}

/* Tabs are navigation, not primary buttons. */
.accountability-tabs {
    display:grid;
    grid-auto-flow:column;
    grid-auto-columns:minmax(max-content,1fr);
    gap:0;
    margin-bottom:20px;
    padding:0 8px;
    overflow-x:auto;
    border:1px solid var(--border);
    border-radius:11px;
    background:var(--surface);
}
.accountability-tab {
    position:relative;
    display:inline-flex;
    min-height:48px;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:0 14px;
    border:0;
    border-radius:0;
    background:transparent;
    color:var(--text-secondary);
    font-size:11px;
    font-weight:800;
    text-decoration:none;
    white-space:nowrap;
    box-shadow:none;
}
.accountability-tab:hover { color:var(--interactive); background:var(--surface-hover); }
.accountability-tab.is-active {
    color:var(--interactive);
    background:transparent;
    box-shadow:inset 0 -3px 0 var(--interactive);
}
.accountability-tab.is-active::after { content:none; }

/* Case identity: make borrower/reference/status scannable instead of one bold line. */
.accountability-case-ref {
    align-items:center;
    gap:5px 0;
    margin-top:8px;
    font-size:10.5px;
}
.accountability-case-ref > span:not(:last-child)::after {
    content:'·';
    margin:0 8px;
    color:var(--text-soft);
}
.accountability-case-ref__borrower strong { color:var(--heading); }
.accountability-case-ref__restriction {
    display:inline-flex;
    align-items:center;
    min-height:22px;
    padding:0 8px;
    border:1px solid #ead5ab;
    border-radius:999px;
    background:#fff8e9;
    color:#8a5a08;
    font-size:9.5px;
    font-weight:800;
}
.accountability-case-ref > .accountability-case-ref__restriction::after { content:none; }

:root[data-theme="dark"] .accountability-overview-card.tone-open .accountability-overview-icon,
html[data-theme="dark"] .accountability-overview-card.tone-open .accountability-overview-icon,
:root[data-theme="dark"] .accountability-overview-card.tone-overdue .accountability-overview-icon,
html[data-theme="dark"] .accountability-overview-card.tone-overdue .accountability-overview-icon,
:root[data-theme="dark"] .accountability-overview-card.tone-balance .accountability-overview-icon,
html[data-theme="dark"] .accountability-overview-card.tone-balance .accountability-overview-icon,
:root[data-theme="dark"] .accountability-overview-card.tone-resolved .accountability-overview-icon,
html[data-theme="dark"] .accountability-overview-card.tone-resolved .accountability-overview-icon,
:root[data-theme="dark"] .accountability-case-ref__restriction,
html[data-theme="dark"] .accountability-case-ref__restriction {
    background:var(--surface-subtle);
}

/* A current action exposes one true CTA. The billing form does not need a
   second disclosure button repeating the same action name. */
.accountability-action-body--direct {
    margin-top:10px;
    padding-top:12px;
    border-top:1px solid var(--border);
}
.accountability-action-body--direct .button.primary {
    width:auto;
    justify-self:start;
}

@media (max-width:1180px) {
    .accountability-overview { grid-template-columns:repeat(2,minmax(0,1fr)); }
}

@media (max-width:760px) {
    .accountability-overview { grid-template-columns:1fr; }
    .accountability-tabs { grid-auto-columns:max-content; justify-content:start; }
}
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
                    ? 'Review accountability cases, decisions, billing, and restrictions.'
                    : 'Verify property compliance, process accountability follow-up, and record confirmed CSPC Cashier receipts.') }}
        </p>
        @if($isOfficer && $officerView !== 'cases')
            <div class="actions officer-view-actions">
                <a class="button secondary small" href="{{ route('accountability.index') }}"><span>View Accountability Cases</span><x-icon name="arrow-right" size="14" /></a>
            </div>
        @endif
    </div>
</section>

@unless($isBorrower)
@php
    /*
     | The summary row and the tab row answer two different questions.
     |
     | Tabs are broad record workspaces only. Detailed processing stages such as
     | Head Decision and Billing stay inside each case's numbered workflow.
     | This avoids showing the same stage as both a tab and a stepper step.
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
    $openAccountabilities = $openCaseCount;

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
     | Summary cards are shortcuts into the broad workspaces below. Processing
     | stages remain inside the numbered case stepper.
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
            'href' => route('accountability.index', ['view' => 'cases']),
        ],
        [
            'tone' => 'overdue',
            'icon' => 'warning',
            'label' => 'Currently Overdue',
            'value' => $currentlyOverdueCases->count(),
            'note' => 'Awaiting return',
            'meta' => 'Not yet returned, as of today',
            'emphasis' => $currentlyOverdueCases->count() > 0,
            /*
             * The Officer workspace has a genuinely filtered "Overdue / Late
             * Returns" tab (hides property cases and billings). The Head
             * workspace has no equivalent dedicated destination - "Cases" is
             * one unified queue - so linking there would just be a scroll
             * inside the same mixed list, not a distinct result. Left
             * non-clickable for Head rather than faking a destination.
             */
            'href' => $isHead ? null : route('accountability.index', ['view' => 'overdue']),
        ],
        [
            'tone' => 'balance',
            'icon' => 'coins',
            'label' => 'Outstanding Balance',
            'value' => 'PHP '.number_format($outstandingBalance, 2),
            'note' => 'Unpaid obligations',
            'meta' => 'Not covered by a verified payment',
            'emphasis' => $outstandingBalance > 0,
            'href' => route('accountability.index', ['view' => 'cases']).'#open-billing-statements',
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
            ['view' => 'cases', 'label' => 'Cases', 'count' => $casesTabCount],
            ['view' => 'restrictions', 'label' => 'Restrictions', 'count' => $activeRestrictions->count()],
            ['view' => 'resolved', 'label' => 'Resolved History', 'count' => $resolvedHistory->count()],
        ]
        : [
            ['view' => 'cases', 'label' => 'Cases', 'count' => $casesTabCount],
            ['view' => 'overdue', 'label' => 'Overdue / Late Returns', 'count' => $openOverdueCases->count()],
            ['view' => 'restrictions', 'label' => 'Restrictions', 'count' => $activeRestrictions->count()],
            ['view' => 'resolved', 'label' => 'Resolved History', 'count' => $resolvedHistory->count()],
        ];
@endphp

<section class="accountability-overview" aria-label="Accountability summary">
    @foreach($summaryCards as $card)
        @if($card['href'])
            <a
                class="accountability-overview-card tone-{{ $card['tone'] }} has-emphasis"
                href="{{ $card['href'] }}"
                aria-label="{{ $card['label'] }}: {{ $card['value'] }}. {{ $card['note'] }}"
            >
                <span class="accountability-overview-icon" aria-hidden="true"><x-icon :name="$card['icon']" size="22" /></span>
                <span class="accountability-overview-label">{{ $card['label'] }}</span>
                <strong class="accountability-overview-value">{{ $card['value'] }}</strong>
                <span class="accountability-overview-note">{{ $card['note'] }}</span>
                <x-icon name="arrow-right" size="15" class="accountability-overview-arrow" />
            </a>
        @else
            {{-- No distinct destination exists for this card in this role, so it is informational only: no href, no chevron, no hover/click affordance. --}}
            <div
                class="accountability-overview-card tone-{{ $card['tone'] }} has-emphasis is-static"
                aria-label="{{ $card['label'] }}: {{ $card['value'] }}. {{ $card['note'] }}"
            >
                <span class="accountability-overview-icon" aria-hidden="true"><x-icon :name="$card['icon']" size="22" /></span>
                <span class="accountability-overview-label">{{ $card['label'] }}</span>
                <strong class="accountability-overview-value">{{ $card['value'] }}</strong>
                <span class="accountability-overview-note">{{ $card['note'] }}</span>
            </div>
        @endif
    @endforeach
</section>

@php
    /* One icon per destination. */
    $accountabilityTabIcons = [
        'cases' => 'clipboard-check',
        'overdue' => 'calendar-clock',
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
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>No accountability matters need {{ $isOfficer ? 'processing' : 'attention' }}.</strong>
                <p>{{ $isOfficer
                    ? 'There are no overdue returns, property cases requiring action, open billings, or active restrictions to reference.'
                    : 'There are no pending Head decisions, open property cases, unpaid billings, or active restrictions.' }}</p>
            </div>
        </div>
    </article>
</section>
@endif

@if($isOfficer && $hasOpenMatters && $officerView !== 'cases' && $officerSelectedCount === 0)
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>No {{ $officerViewLabel }} need processing.</strong>
                <p>This filtered view is clear. Select another accountability card above or view all matters.</p>
            </div>
        </div>
    </article>
</section>
@endif

@if($isHead && $headView === 'cases' && $pendingViolations->isNotEmpty())
<section class="content-area">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Administrative accountability</p>
            <h2>Administrative Review</h2>
            <p>Review only violations that require an SPMU Head decision. Property return findings and financial follow-up stay in their own sections below.</p>
        </div>
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
    /* Cases is the single Head workspace; the current status/step identifies what needs review. */
    $visibleOverdueCases = $openOverdueCases;

    /* Compact labels for the status column; processing still uses LateReturnService constants. */
    $caseShortLabels = [
        App\Services\LateReturnService::STATUS_OVERDUE => 'Overdue',
        App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION => 'Late Return',
        App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL => 'For Head Review',
        App\Services\LateReturnService::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
    ];
@endphp
@if(! $isBorrower && $visibleOverdueCases->isNotEmpty() && (! $isHead || $headView === 'cases') && (! $isOfficer || in_array($officerView, ['cases', 'overdue'], true)))
<section class="content-area" id="accountability-cases-section">
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
                            /*
                             * The assessment is entirely system-derived, so there is no
                             * Action Officer confirmation step. This status is legacy
                             * only - no action anywhere can put a case here anymore,
                             * since no route ever existed to correct an authoritative
                             * physical return record. Kept read-only so any pre-existing
                             * row still renders instead of erroring.
                             */
                            $returnedForCorrection = $overdue->status === App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION;
                            $forHeadApproval = $overdue->status === App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL;
                            $hasBilling = $overdue->status === App\Services\LateReturnService::STATUS_AWAITING_PAYMENT;
                            $fromLaundry = $assessment['return_date_source'] === 'LAUNDRY_RECEIPT';

                            /*
                             * Approve/Bill is the Head's only decision - reachable
                             * whether the case is freshly finalized or (for a legacy
                             * row only) already sitting in the retired correction
                             * status. There is no "return for correction" action.
                             */
                            $headCanDecide = $isHead && ($forHeadApproval || $returnedForCorrection);
                        @endphp

                        <tr
                            id="late-return-{{ $overdue->id }}"
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
                                @if($headCanDecide)
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
                                            <small>A formal Late Return Notice cannot be issued until the physical return is recorded and the SPMU Head approves the assessment.</small>
                                        </div>
                                    </div>
                                @elseif($returnedForCorrection)
                                    <div class="accountability-detail-panel is-info">
                                        <x-icon name="information" size="17" />
                                        <div>
                                            <strong>Returned for correction &mdash; read-only for the Action Officer.</strong>
                                            <p>
                                                {{ $fromLaundry ? 'The return date is the date Laundry Operations received the linen, not the date the accomplished Laundry Form reached SPMU. ' : '' }}
                                                The assessment was calculated from the recorded physical return and is awaiting SPMU Head review.
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
                                            <strong>Awaiting Head review.</strong>
                                            <p>
                                                Action by: SPMU Head/Admin.
                                                {{ $overdue->ao_confirmed_at
                                                    ? 'Confirmed by '.($overdue->confirmedBy?->full_name ?? 'the Action Officer').' on '.$overdue->ao_confirmed_at->format('d M Y, h:i A').'.'
                                                    : 'The assessment was automatically finalized from the recorded physical return.' }}
                                                Approving generates the formal Late Return Notice and, when payment is required, the separate Billing Statement for the recorded amount.
                                            </p>
                                            <small>
                                                Expected Return: {{ $overdue->custody->due_at->format('d M Y') }}
                                                &middot; {{ $fromLaundry ? 'Laundry Received' : 'Actual Return' }}: {{ $assessment['actual_return_date']?->format('d M Y') ?? 'date not recorded' }}
                                                &middot; Late Days: {{ $assessment['late_days'] }}
                                            </small>
                                        </div>
                                    </div>
                                @elseif($hasBilling)
                                    <div class="accountability-detail-panel is-info">
                                        <x-icon name="information" size="17" />
                                        <div>
                                            <strong>Approved &mdash; awaiting payment.</strong>
                                            <p>The Late Return Notice and Billing Statement have been issued. Continue payment processing using the Billing Statement.</p>
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
    $displayIncidents = $openIncidents;

    // Prevent one underlying property-case billing from rendering two editable
    // payment workflows in the Action Officer's combined "All" view.
    // The numbered property-case stepper owns the action whenever that case is visible.
    $propertyCaseSectionVisible = ! $isBorrower
        && $displayIncidents->isNotEmpty()
        && (! $isHead || $headView === 'cases')
        && (! $isOfficer || $officerView === 'cases');

    $visibleIncidentIds = collect();
    $visiblePropertyBillingIds = collect();
    if ($propertyCaseSectionVisible) {
        $visibleIncidentIds = $displayIncidents->pluck('id')->filter()->values();
        if ($visibleIncidentIds->isNotEmpty()) {
            $visiblePropertyBillingIds = Illuminate\Support\Facades\DB::table('billing_lines')
                ->whereIn('incident_id', $visibleIncidentIds)
                ->pluck('billing_statement_id')
                ->filter()
                ->unique()
                ->values();
        }
    }

    // Linked billing records stay inside the visible property-case stepper so
    // the same action is never rendered twice. Any standalone billing remains
    // in Cases. Restrictions keep their own broad queue.
    $standaloneOpenBillings = (($isOfficer && $officerView === 'cases') || ($isHead && $headView === 'cases'))
        ? $openBillings->reject(fn ($billing) => $visiblePropertyBillingIds->contains($billing->id))->values()
        : $openBillings;
    $standaloneActiveRestrictions = $activeRestrictions;
@endphp
@if($propertyCaseSectionVisible)
<section class="content-area">
    <div class="section-heading head-control-heading">
        <div>
            <p class="eyebrow">Property accountability</p>
            <h2>Property Accountability Cases</h2>
            <p>{{ $isOfficer
                ? 'Each case shows its current step and the next action.'
                : 'Review each case and complete only the active step.' }}</p>
        </div>
    </div>

    @foreach($displayIncidents as $incident)
        @php
            $incidentBillingLine = Illuminate\Support\Facades\DB::table('billing_lines')
                ->where('incident_id', $incident->id)
                ->latest('id')
                ->first();
            $incidentBilling = $incidentBillingLine
                ? $billings->firstWhere('id', $incidentBillingLine->billing_statement_id)
                : null;
            $incidentHasBilling = (bool) $incidentBillingLine;
            $requestNo = $incident->custody?->request?->request_no ?: '—';
            $custodyNo = $incident->custody?->custody_no ?: '—';
            $incidentRestriction = $activeRestrictions->firstWhere('incident_id', $incident->id);
            $isAwaitingDecision = $isHead && $headReviewIncidents->contains('id', $incident->id);
            $statusKey = strtoupper((string) $incident->status);
            $isForBilling = $statusKey === 'FOR_BILLING';
            $isBillingPending = $statusKey === 'BILLING_PENDING';
            $isComplianceRequired = $statusKey === 'COMPLIANCE_REQUIRED';
            $isResolvedIncident = in_array($statusKey, ['RESOLVED', 'CLOSED'], true);
            $decisionDone = $statusKey !== 'OPEN' || (bool) $incident->head_decided_at;
            $offensePreview = $incidentOffensePreviews[$incident->id] ?? null;
            $incidentSanction = $sanctions->first(
                fn ($sanction) => (int) ($sanction->violation?->custody_transaction_id ?? 0) === (int) $incident->custody_transaction_id
            );
            $incidentOffenseLabel = $incidentSanction
                ? match ((int) $incidentSanction->offense_no) {
                    1 => '1st Offense',
                    2 => '2nd Offense',
                    3 => '3rd Offense',
                    default => $incidentSanction->offense_no.'th Offense',
                }
                : null;
            $headDecisionNote = collect(preg_split('/\R/', trim((string) $incident->remarks)))
                ->filter(fn ($line) => str_starts_with(trim((string) $line), 'SPMU Head decision:'))
                ->last();
            $complianceDocument = $incident->documents
                ->where('document_type', 'ACCOUNTABILITY_COMPLIANCE_NOTICE')
                ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                ->sortByDesc('generated_at')
                ->first();

            $stepTwoState = $decisionDone ? 'is-done' : 'is-active';
            $stepThreeState = $isResolvedIncident
                ? 'is-done'
                : ($statusKey === 'OPEN' ? '' : 'is-active');
            $stepFourState = $isResolvedIncident ? 'is-done' : '';

            $stepThreeLabel = match ($statusKey) {
                'FOR_BILLING' => 'Billing Statement',
                'BILLING_PENDING' => 'Cashier Payment',
                'COMPLIANCE_REQUIRED' => 'Property Compliance',
                'RESOLVED', 'CLOSED' => 'Completed',
                default => 'Required Action',
            };
            $stepThreeSub = match ($statusKey) {
                'FOR_BILLING' => 'Generate & issue',
                'BILLING_PENDING' => 'AO receipt recording',
                'COMPLIANCE_REQUIRED' => 'Borrower compliance → AO verification',
                'RESOLVED', 'CLOSED' => 'Requirement cleared',
                default => 'Depends on decision',
            };
            $statusBadgeLabel = match (true) {
                $isOfficer && $statusKey === 'OPEN' => 'Awaiting Head Decision',
                $isOfficer && $statusKey === 'FOR_BILLING' => 'Waiting for Billing',
                $statusKey === 'COMPLIANCE_REQUIRED' => 'Compliance Required',
                default => null,
            };

            $affectedPropertyItems = $incident->lines
                ->map(function ($line) use ($incident) {
                    $custodyLine = $incident->custody?->lines?->firstWhere('id', $line->custody_line_id);
                    $description = $custodyLine?->requestItem?->description_snapshot ?: 'Inventory item';
                    $quantity = (float) $line->quantity;
                    $quantityLabel = fmod($quantity, 1.0) === 0.0 ? (string) (int) $quantity : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
                    return $description.' · '.$quantityLabel;
                })
                ->filter()
                ->values();
            $affectedPropertySummary = $affectedPropertyItems->take(2)->implode(', ');
            if ($affectedPropertyItems->count() > 2) {
                $affectedPropertySummary .= ' +'.($affectedPropertyItems->count() - 2).' more';
            }
            $complianceActionSummary = $incident->lines
                ->map(fn ($line) => match (strtoupper((string) $line->disposition_state)) {
                    'DAMAGED_MAINTENANCE' => 'Repair / Maintenance',
                    'REPAIR_REQUIRED' => 'Repair',
                    'REPLACEMENT_REQUIRED' => 'Replacement',
                    default => null,
                })
                ->filter()
                ->unique()
                ->implode(' / ');
            $complianceActionSummary = $complianceActionSummary ?: 'Repair / Replacement / Required Compliance';
        @endphp

        <article class="card top-gap head-case-card accountability-case-workflow" id="incident-{{ $incident->id }}">
            <div class="accountability-case-identity">
                <div>
                    <p class="eyebrow">Property Case</p>
                    <strong>{{ $incident->incident_no }}</strong>
                    <h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3>
                    <div class="accountability-case-ref">
                        <span class="accountability-case-ref__borrower"><strong>{{ $incident->borrower?->full_name ?: '—' }}</strong></span>
                        <span>{{ $requestNo }}</span>
                        <span>{{ $custodyNo }}</span>
                        @if($incidentRestriction)<span class="accountability-case-ref__restriction">Restriction Active</span>@endif
                    </div>
                </div>
                <x-status-badge :status="$incident->status" :label="$statusBadgeLabel" />
            </div>

            <div class="accountability-stepper" aria-label="Accountability case progress">
                <div class="accountability-step is-done">
                    <span class="accountability-step__number">1</span>
                    <span class="accountability-step__copy">
                        <strong>Case Recorded</strong>
                        <small>Finding captured</small>
                    </span>
                </div>
                <div class="accountability-step {{ $stepTwoState }}">
                    <span class="accountability-step__number">2</span>
                    <span class="accountability-step__copy">
                        <strong>Head Decision</strong>
                        <small>{{ $decisionDone ? 'Decision recorded' : 'Awaiting decision' }}</small>
                    </span>
                </div>
                <div class="accountability-step {{ $stepThreeState }}">
                    <span class="accountability-step__number">3</span>
                    <span class="accountability-step__copy">
                        <strong>{{ $stepThreeLabel }}</strong>
                        <small>{{ $stepThreeSub }}</small>
                    </span>
                </div>
                <div class="accountability-step {{ $stepFourState }}">
                    <span class="accountability-step__number">4</span>
                    <span class="accountability-step__copy">
                        <strong>Resolved</strong>
                        <small>{{ $isResolvedIncident ? 'Case closed' : 'Pending clearance' }}</small>
                    </span>
                </div>
            </div>

            @if($isOfficer && $statusKey === 'OPEN')
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>Current status</small>
                            <strong>Waiting for Head Decision</strong>
                            <p>Action by: SPMU Head/Admin. No Action Officer action is required until the Head records a decision.</p>
                        </div>
                    </div>
                </div>
            @elseif($isHead && $isAwaitingDecision)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>Your current action</small>
                            <strong>Head Decision</strong>
                            <p>Select the property resolution, then review whether the detected finding should also count as an administrative offense under the configured Sanction Rules.</p>
                        </div>
                    </div>
                    <details class="accountability-action-disclosure">
                        <summary><span>Review & Decide</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                        <div class="accountability-action-body">
                            <form method="post" action="{{ route('incidents.resolve', $incident) }}" class="form-grid">
                                @csrf
                                <label>
                                    Required Resolution
                                    <select name="resolution_outcome" required>
                                        <option value="">Select decision</option>
                                        <option value="NO_BORROWER_CHARGE">No Liability / Clear Case</option>
                                        <option value="COMPLIANCE_REQUIRED">Repair / Replacement / Compliance Required</option>
                                        <option value="BILLING_REQUIRED">Billing / Payment Required</option>
                                        <option value="ADMINISTRATIVELY_CLEARED">Administratively Cleared</option>
                                    </select>
                                    <small>Billing opens the billing step. Compliance stays open until verified.</small>
                                </label>

                                @if($offensePreview)
                                    <div class="head-offense-review">
                                        <div class="head-offense-review__heading">
                                            <span>Administrative Offense Review</span>
                                            @if($offensePreview['existing_sanction'])
                                                <strong>Administrative offense already recorded</strong>
                                                <p>This borrowing transaction already has a confirmed sanction. The property resolution below will not create another offense.</p>
                                            @elseif($offensePreview['is_eligible'])
                                                <strong>Does this incident count as an administrative offense?</strong>
                                                <p>The finding is eligible because it is enabled in Operational Configuration → Sanction Rules → Offense Application. Eligibility does not automatically make the borrower guilty.</p>
                                            @else
                                                <strong>No administrative offense decision is required</strong>
                                                <p>The detected property finding is not enabled under the current Offense Application rules. Continue with the property resolution only.</p>
                                            @endif
                                        </div>

                                        @if($offensePreview['existing_sanction'])
                                            <div class="head-offense-state">
                                                <strong>{{ $offensePreview['existing_sanction']->offense_no }}{{ $offensePreview['existing_sanction']->offense_no == 1 ? 'st' : ($offensePreview['existing_sanction']->offense_no == 2 ? 'nd' : ($offensePreview['existing_sanction']->offense_no == 3 ? 'rd' : 'th')) }} Offense · {{ $offensePreview['existing_sanction']->sanction_label }}</strong>
                                                <small>No duplicate offense or sanction will be created from this Head decision.</small>
                                            </div>
                                        @elseif($offensePreview['is_eligible'])
                                            <div class="head-offense-choices" role="radiogroup" aria-label="Administrative offense decision">
                                                <label class="head-offense-choice">
                                                    <input type="radio" name="count_as_offense" value="0" required>
                                                    <span class="head-offense-choice__copy">
                                                        <strong>No — Property accountability only</strong>
                                                        <small>Resolve the property issue without increasing the borrower's administrative offense count.</small>
                                                    </span>
                                                </label>
                                                <label class="head-offense-choice {{ $offensePreview['can_confirm'] ? '' : 'is-disabled' }}">
                                                    <input type="radio" name="count_as_offense" value="1" required @disabled(! $offensePreview['can_confirm'])>
                                                    <span class="head-offense-choice__copy">
                                                        <strong>Yes — Count as administrative offense</strong>
                                                        <small>Apply the next offense level and the active sanction configured for that level.</small>
                                                    </span>
                                                </label>
                                            </div>

                                            <div class="head-offense-preview">
                                                <div>
                                                    <small>Detected finding</small>
                                                    <strong>{{ collect($offensePreview['eligible_types'])->map(fn ($type) => str($type)->replace('_', ' ')->title())->join(', ') }}</strong>
                                                </div>
                                                <div>
                                                    <small>Previous confirmed</small>
                                                    <strong>{{ $offensePreview['previous_confirmed_offenses'] }}</strong>
                                                </div>
                                                <div>
                                                    <small>If confirmed</small>
                                                    <strong>{{ $offensePreview['next_offense_label'] }}</strong>
                                                </div>
                                                <div>
                                                    <small>Configured sanction</small>
                                                    <strong>{{ $offensePreview['configured_sanction_label'] }}</strong>
                                                </div>
                                            </div>

                                            <p class="head-offense-note">
                                                {{ $offensePreview['restriction_preview'] }}
                                                @if(! $offensePreview['can_confirm'])
                                                    Administrative offense confirmation is unavailable until the required academic-period and sanction configuration is complete, or if this transaction was already dismissed for offense purposes.
                                                @else
                                                    The offense level and sanction are system-calculated; the Head only confirms whether this eligible incident should count.
                                                @endif
                                            </p>
                                        @endif
                                    </div>
                                @endif

                                <label>
                                    Decision Basis / Instructions
                                    <textarea name="resolution_remarks" rows="3" maxlength="2000" required placeholder="State the decision basis and required next action."></textarea>
                                </label>
                                <button class="button primary">Confirm Head Decision</button>
                            </form>
                        </div>
                    </details>
                </div>
            @elseif($isForBilling)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            @if($isHead)
                                <small>Your current action</small>
                                <strong>Generate & Issue Billing Statement</strong>
                                <p>The Head decision requires payment. Prepare the approved assessment and issue the official Billing Statement to the borrower.</p>
                            @else
                                <small>Current status</small>
                                <strong>Waiting for Billing Statement</strong>
                                <p>Action by: SPMU Head/Admin. The SPMU Head/Admin decision requires billing. No Action Officer action is required until the Billing Statement is issued.</p>
                            @endif
                        </div>
                    </div>
                    @if($isHead)
                        <div class="accountability-action-body accountability-action-body--direct">
                            <form method="post" action="{{ route('incidents.bill',$incident) }}" class="form-grid">
                                @csrf
                                <div class="form-columns">
                                    <label>Assessment Amount<input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00"></label>
                                    <label>Payment Due Date<input type="date" name="due_at"></label>
                                </div>
                                <label>Assessment Basis<textarea name="basis" rows="3" maxlength="2000" required placeholder="State the approved assessment basis."></textarea></label>
                                <button class="button primary">Generate & Issue Billing Statement</button>
                            </form>
                        </div>
                    @endif
                </div>
            @elseif($isComplianceRequired)
                <div class="accountability-current-step accountability-current-step--compliance">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isOfficer ? 'Your current action' : 'Current status' }}</small>
                            <strong>{{ $isOfficer ? 'Verify Property Compliance' : 'Compliance Required' }}</strong>
                            <p>{{ $isOfficer
                                ? 'Physically verify the repair, replacement, or required compliance when the borrower presents the property. The Head/Admin decision and sanction, if any, are already recorded.'
                                : 'Next action: the borrower completes the required repair, replacement, or compliance, then presents the property to the SPMU Action Officer for physical verification.' }}</p>
                        </div>
                        @if($complianceDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.view', $complianceDocument) }}" target="_blank">Open Compliance Notice</a>
                            </div>
                        @endif
                    </div>

                    @if($isHead)
                        <dl class="accountability-decision-summary" aria-label="Recorded accountability decision summary">
                            <div>
                                <dt>Affected Property</dt>
                                <dd>{{ $affectedPropertySummary ?: 'See case details' }}</dd>
                            </div>
                            <div>
                                <dt>Finding</dt>
                                <dd>{{ str($incident->incident_type)->replace('_',' ')->title() }}</dd>
                            </div>
                            <div>
                                <dt>Required Resolution</dt>
                                <dd>{{ $complianceActionSummary }}</dd>
                            </div>
                            @if($incidentSanction)
                                <div>
                                    <dt>Administrative Result</dt>
                                    <dd>{{ $incidentOffenseLabel }} — {{ $incidentSanction->sanction_label }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>Restriction</dt>
                                <dd>{{ $incidentRestriction ? 'Active until property compliance is verified' : 'No active linked restriction' }}</dd>
                            </div>
                        </dl>
                        <p class="accountability-owner-note">No Head/Admin action is required at this stage. The next staff action belongs to the SPMU Action Officer after the borrower presents the completed compliance.</p>
                    @else
                        <div class="accountability-compliance-brief">
                            <div>
                                <small>Property</small>
                                <strong>{{ $affectedPropertySummary ?: 'See case details' }}</strong>
                            </div>
                            <div>
                                <small>Finding</small>
                                <strong>{{ str($incident->incident_type)->replace('_',' ')->title() }}</strong>
                            </div>
                            <div>
                                <small>Required Compliance</small>
                                <strong>{{ $complianceActionSummary }}</strong>
                            </div>
                        </div>

                        <div class="accountability-head-instruction">
                            <small>Head Instruction</small>
                            <p>{{ $headDecisionNote ?: 'Follow the issued Compliance Notice and verify the completed repair, replacement, or required compliance.' }}</p>
                        </div>

                        @if($incidentSanction)
                            <p class="accountability-administrative-record">
                                Administrative record: <strong>{{ $incidentOffenseLabel }} — {{ $incidentSanction->sanction_label }}</strong>. No AO re-verification is required for this sanction.
                            </p>
                        @endif

                        <details class="accountability-action-disclosure">
                            <summary><span>Verify Property Compliance</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.resolve', $incident) }}" class="form-grid">
                                    @csrf
                                    <input type="hidden" name="resolution_outcome" value="COMPLIANCE_COMPLETED">
                                    <label>
                                        Property Compliance Verification Remarks
                                        <textarea name="resolution_remarks" rows="3" maxlength="2000" required placeholder="State what the borrower completed and what you physically verified (for example: repaired Rectangular Table inspected and found acceptable for service)."></textarea>
                                    </label>
                                    <button class="button primary">Confirm Property Compliance</button>
                                    <small class="accountability-resolution-note">If no other property obligation remains, the system will resolve the case and clear the linked incident restriction automatically.</small>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isBillingPending)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isOfficer ? 'Your current action' : 'Current status' }}</small>
                            <strong>{{ $isOfficer ? 'Record Cashier Payment' : 'Waiting for Cashier Payment' }}</strong>
                            <p>{{ $isOfficer ? 'Record the official Cashier receipt once, then confirm the payment.' : 'Action by: SPMU Action Officer. Billing Statement issued. The Action Officer records the official Cashier receipt after the borrower pays.' }}</p>
                        </div>
                    </div>
                    @if($incidentBilling)
                        <div class="accountability-billing-brief">
                            <span><strong>{{ $incidentBilling->billing_no }}</strong></span>
                            <span>PHP {{ number_format((float) $incidentBilling->total_amount, 2) }}</span>
                            <x-status-badge :status="$incidentBilling->status" />
                            @if($incidentBilling->due_at)<span>Due {{ optional($incidentBilling->due_at)->format('d M Y') }}</span>@endif
                        </div>
                        <div class="actions">
                            @foreach($incidentBilling->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)
                                <a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement</a>
                            @endforeach
                        </div>

                        @if($isOfficer && !in_array($incidentBilling->status,['SETTLED','WAIVED','VOID'],true))
                            <details class="accountability-action-disclosure">
                                <summary><span>Record Cashier Payment</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                                <div class="accountability-action-body">
                                    <form method="post" action="{{ route('payments.store',$incidentBilling) }}" enctype="multipart/form-data" class="form-grid">
                                        @csrf
                                        <div class="form-columns">
                                            <label>Cashier Receipt No.<input name="official_receipt_no" required></label>
                                            <label>Receipt Date<input type="date" name="receipt_date" required></label>
                                            <label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label>
                                            <label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                        </div>
                                        <label>Remarks <small>(Optional)</small><textarea name="remarks" rows="2"></textarea></label>
                                        <button class="button primary">Record & Confirm Payment</button>
                                    </form>
                                </div>
                            </details>
                        @elseif($isHead && !in_array($incidentBilling->status,['SETTLED','WAIVED','VOID'],true))
                            <p class="meta top-gap">No further Head/Admin action is required while payment is pending.</p>
                        @endif
                    @else
                        <p class="meta">Billing record is being prepared.</p>
                    @endif
                </div>
            @endif

            <details class="accountability-case-details">
                <summary><span>Case details</span><x-icon name="chevron-down" size="14" class="accountability-disclosure-chevron" /></summary>
                <p class="meta top-gap">Reported {{ optional($incident->reported_at)->format('d M Y, g:i A') ?: '—' }}</p>
                <dl class="head-case-summary">
                    <div><dt>Borrower</dt><dd>{{ $incident->borrower?->full_name ?: '—' }}</dd></div>
                    <div><dt>Request</dt><dd>{{ $requestNo }}</dd></div>
                    <div><dt>Custody</dt><dd>{{ $custodyNo }}</dd></div>
                    <div><dt>Restriction</dt><dd>{{ $incidentRestriction ? 'Active until resolved' : 'No active linked restriction' }}</dd></div>
                </dl>

                <div class="table-wrap head-case-lines">
                    <table>
                        <thead><tr><th>Item</th><th>Qty</th><th>Finding</th><th>Required Action</th></tr></thead>
                        <tbody>
                            @foreach($incident->lines as $line)
                                @php
                                    $custodyLine = $incident->custody?->lines?->firstWhere('id', $line->custody_line_id);
                                    $itemDescription = $custodyLine?->requestItem?->description_snapshot ?: 'Inventory item';
                                    $dispositionLabel = match (strtoupper((string) $line->disposition_state)) {
                                        'DAMAGED_MAINTENANCE' => 'Repair / Maintenance',
                                        'REPAIR_REQUIRED' => 'Repair Required',
                                        'REPLACEMENT_REQUIRED' => 'Replacement Required',
                                        default => str($line->disposition_state)->replace('_',' ')->title(),
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $itemDescription }}</td>
                                    <td>{{ $line->quantity + 0 }}</td>
                                    <td>{{ str($line->observed_condition)->replace('_',' ')->title() }}</td>
                                    <td>{{ $dispositionLabel }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($incident->remarks)
                    <div class="head-status-note">
                        <strong>Case notes</strong>
                        <span>{!! nl2br(e($incident->remarks)) !!}</span>
                    </div>
                @endif

                @if($incident->supporting_evidence_file_id || $incident->police_blotter_reference || $complianceDocument)
                    <div class="actions top-gap">
                        @if($incident->supporting_evidence_file_id)
                            <a class="button secondary small" href="{{ route('files.show', $incident->supporting_evidence_file_id, false) }}" target="_blank">Supporting Evidence</a>
                        @endif
                        @if($complianceDocument)
                            <a class="button secondary small" href="{{ route('documents.download', $complianceDocument) }}">Compliance Notice</a>
                        @endif
                        @if($incident->police_blotter_reference)
                            <span class="meta">Blotter: <strong>{{ $incident->police_blotter_reference }}</strong></span>
                        @endif
                    </div>
                @endif
            </details>
        </article>
    @endforeach
</section>
@elseif($isHead && $headView === 'cases')
<section class="content-area">
    <article class="card">
        <div class="empty-state">
            <div>
                <strong>No open property accountability cases.</strong>
                <p>There are no unresolved property cases in this workspace.</p>
            </div>
        </div>
    </article>
</section>
@endif

@if(! $isBorrower && $standaloneOpenBillings->isNotEmpty() && (! $isHead || $headView === 'cases') && (! $isOfficer || $officerView === 'cases'))
<section class="content-area" id="open-billing-statements">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Cashier payment evidence</p>
            <h2>Open Billing Statements</h2>
            @if($isOfficer)
                <p>Check the official CSPC Cashier receipt, record its details, and confirm the payment in one step. Administrative offense decisions remain Head-level actions.</p>
            @endif
        </div>
    </div>
    @foreach($standaloneOpenBillings as $billing)
<article class="card top-gap"><div class="card-header"><div><strong>{{ $billing->billing_no }}</strong><h3>PHP {{ number_format((float)$billing->total_amount,2) }}</h3><small>{{ $billing->borrower->full_name }}</small></div><x-status-badge :status="$billing->status" /></div>
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="actions">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<a class="button secondary small" href="{{ route('documents.download',$document) }}">Download Billing Statement / Assessment Notice</a>@endforeach</div>
<p class="meta">The borrower pays at the CSPC Cashier and presents the official receipt to the Action Officer. Confirm the payment only after checking the receipt.</p>
@if($isOfficer && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Record Cashier Payment</h4><small>Check the official receipt, encode it once, then confirm.</small></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks <small>(Optional)</small><textarea name="remarks"></textarea></label><button class="button primary">Record & Confirm Payment</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.show', $payment->evidence_file_id, false) }}" target="_blank">Open Scanned Cashier Receipt</a>@endif</div>
@if($payment->status==='PENDING_VERIFICATION')<small class="meta">Legacy payment record from the previous two-step workflow.</small>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
</article>
    @endforeach
</section>
@endif

@if(! $isBorrower && $standaloneActiveRestrictions->isNotEmpty() && (! $isHead || $headView === 'restrictions') && (! $isOfficer || $officerView === 'restrictions'))
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Borrowing eligibility</p>
                <h2>Active Restrictions</h2>
                <p class="meta">Restrictions are borrowing controls linked to accountability cases. They are not separate accountability cases.</p>
                @if($isOfficer)
                    <p class="meta">Reference only. Action Officers can see active restrictions but cannot create, extend, lift, or override them.</p>
                @endif
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Restriction</th><th>Linked Case</th><th>Reason</th><th>Effective</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @foreach($standaloneActiveRestrictions as $restriction)
                        @php
                            $linkedIncident = $restriction->incident_id ? $incidents->firstWhere('id', $restriction->incident_id) : null;
                            $linkedOverdue = !$linkedIncident && $restriction->custody_transaction_id
                                ? $overdueCases->firstWhere('custody_transaction_id', $restriction->custody_transaction_id)
                                : null;
                        @endphp
                        <tr>
                            <td>{{ str($restriction->restriction_type)->replace('_',' ')->title() }}</td>
                            <td>
                                @if($linkedIncident)
                                    <a class="head-linked-case" href="{{ route('accountability.index', ['view' => 'cases']).'#incident-'.$linkedIncident->id }}">{{ $linkedIncident->incident_no }}</a>
                                @elseif($linkedOverdue)
                                    <a class="head-linked-case" href="{{ route('accountability.index', ['view' => 'cases']).'#late-return-'.$linkedOverdue->id }}">{{ $linkedOverdue->custody?->custody_no ?: 'Late Return #'.$linkedOverdue->id }}</a>
                                @elseif($restriction->custody)
                                    <a class="head-linked-case" href="{{ route('custody.show', $restriction->custody) }}">{{ $restriction->custody->custody_no }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $restriction->reason }}</td>
                            <td>{{ optional($restriction->effective_from)->format('d M Y') }}{{ $restriction->effective_to ? ' – '.$restriction->effective_to->format('d M Y') : ' until resolved' }}</td>
                            <td><x-status-badge status="RESTRICTED" label="Active" /></td>
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

@if((($isHead && $headView === 'resolved') || $workspace === 'BORROWER') && $sanctions->isNotEmpty())
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <div>
                <p class="eyebrow">Administrative history</p>
                <h2>{{ $workspace === 'BORROWER' ? 'My Sanctions' : 'Sanction History' }}</h2>
                <p class="meta">Confirmed administrative sanctions. Billing and property obligations are tracked separately.</p>
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
                        <th>Notice</th>
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
                            @php
                                $sanctionStatusLabel = match (true) {
                                    $sanction->status !== 'ACTIVE'
                                        => str($sanction->status)->replace('_', ' ')->title()->toString(),
                                    $sanction->sanction_code === 'BORROWING_SUSPENSION' && $sanction->effective_to && now()->gt($sanction->effective_to)
                                        => 'Completed',
                                    $sanction->sanction_code === 'BORROWING_SUSPENSION'
                                        => 'In Effect',
                                    default
                                        => 'Recorded',
                                };
                                $sanctionStatusTone = $sanctionStatusLabel === 'Completed'
                                    ? 'COMPLETED'
                                    : ($sanctionStatusLabel === 'In Effect' ? 'ACTIVE' : 'COMPLETED');
                            @endphp
                            <td><x-status-badge :status="$sanctionStatusTone" :label="$sanctionStatusLabel" /></td>
                            <td>
                                @php
                                    $sanctionNotice = $sanction->documents
                                        ->where('document_type', 'ADMINISTRATIVE_SANCTION_NOTICE')
                                        ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                                        ->sortByDesc('generated_at')
                                        ->first();
                                @endphp
                                @if($sanctionNotice)
                                    <a class="table-action" href="{{ route('documents.view', $sanctionNotice) }}" target="_blank">Open Notice</a>
                                    <a class="table-action" href="{{ route('documents.download', $sanctionNotice) }}">Download</a>
                                @else
                                    <span class="meta">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>
</section>
@endif

{{-- BORROWER_ACCOUNTABILITY_CLEAN_FILTER_LAYOUT --}}
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

<style>
.head-decision-inline-help{display:block;margin-top:6px;color:var(--text-muted);font-size:11.5px;font-weight:500;line-height:1.45}
</style>


<style>
.head-status-note-compact {
    gap: 3px;
}
.head-status-note-compact span {
    line-height: 1.4;
}
</style>

@endsection
