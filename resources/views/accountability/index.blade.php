@extends('layouts.app', ['title' => session('active_workspace') === 'BORROWER' ? 'My Obligations' : (auth()->user()?->access_classification?->value === 'SPMU_HEAD' ? 'Accountability Oversight' : 'Accountability Processing')])
@section('content')
@php
    $workspace = session('active_workspace');
    $isBorrower = $workspace === 'BORROWER';
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';
    $pageTitle = $workspace === 'BORROWER' ? 'My Obligations' : ($isHead ? 'Accountability Oversight' : 'Accountability Processing');

    /*
     * The Oversight overview is borrower-centered: by default it lists each
     * borrower once, never a flat per-case row. This deep-link parameter is
     * how a "Next Action" link from the borrower workspace reaches the
     * existing, unmodified per-case detail/decision block below for one
     * specific borrower - it never changes what that block does, only which
     * borrower's cases it is scoped to.
     */
    $borrowerFilterId = ! $isBorrower ? (int) request('borrower') ?: null : null;

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
     * The one canonical case tally, shared with the AO/Head dashboard KPI
     * cards via AccountabilityCaseTally: an Incident and an OverdueCase on
     * the same custody remain two separate cases, never deduplicated by
     * custody. A linked billing/restriction is a detail of its case; only a
     * genuinely standalone one (no linked open case) counts as its own.
     */
    $accountabilityCaseTally = App\Services\AccountabilityCaseTally::forOpenRecords(
        $openIncidents,
        $openOverdueCases,
        $openBillings,
        $activeRestrictions
    );

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
    border-radius:12px;
    background:var(--surface-elevated);
    color:inherit;
    text-decoration:none;
    box-shadow:inset 0 3px 0 var(--accountability-card-accent), var(--shadow-sm);
    cursor:default;
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
/* Summary cards are static on every role/page. */
.accountability-overview-card:hover,
.accountability-overview-card:focus-visible,
.accountability-overview-card.is-static:hover,
.accountability-overview-card.is-static:focus-visible {
    transform:none;
    border-color:var(--border);
    box-shadow:inset 0 3px 0 var(--accountability-card-accent), var(--shadow-sm);
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
.accountability-overview-arrow { display:none; }
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

/* Concise flag on the collapsed row - full reason/dates live in the expanded Borrowing Restriction panel instead. */
.accountability-restriction-flag {
    display:block;
    margin-top:2px;
    color:#8a5a08;
    font-size:10px;
    font-weight:800;
}
:root[data-theme="dark"] .accountability-restriction-flag,
html[data-theme="dark"] .accountability-restriction-flag { color:#e0b354; }

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

.accountability-case-type-label { color:var(--text-secondary); font-size:11.5px; font-weight:700; }

/* Every row's one action toggles its own detail row - a chevron shows which way it currently points. */
.accountability-toggle-chevron { flex:0 0 auto; margin-left:2px; transition:transform var(--motion) ease; }
[aria-expanded="true"] .accountability-toggle-chevron { transform:rotate(180deg); }

@media (max-width:1180px) {
    .accountability-overview { grid-template-columns:repeat(2,minmax(0,1fr)); }
}

@media (max-width:760px) {
    .accountability-overview { grid-template-columns:1fr; }
}

/*
 | The heading action sits at the upper right of this heading only. The global
 | .page-heading is already a space-between flex row, so the button needs no
 | float or absolute positioning - only the cross-axis start alignment that
 | levels it with the title block instead of the subtitle baseline. Below
 | 768px app.css turns .page-heading into a column, so it wraps under the
 | heading on its own; flex-shrink keeps the label on one line above that.
 */
.accountability-heading { align-items: flex-start; }
.accountability-heading > .accountability-heading-action { flex-shrink: 0; }
</style>
@endonce

<section class="page-heading accountability-heading">
    <div>
        <p class="eyebrow">Financial and property accountability</p>
        <h1>{{ $pageTitle }}</h1>
        <p>
            {{ $workspace === 'BORROWER'
                ? 'View active obligations and required next steps.'
                : ($isHead
                    ? 'Review cases, decisions, billing, and restrictions.'
                    : 'Process property, late-return, billing, and payment follow-up.') }}
        </p>
    </div>

    @if($isOfficer)
        <a class="button secondary small accountability-heading-action" href="#accountability-cases-section">
            <span>View Accountability Cases</span>
            <x-icon name="arrow-right" size="14" />
        </a>
    @endif
</section>

@unless($isBorrower)
@php
    /*
     | OPEN ACCOUNTABILITIES
     |
     | Counts cases, not their consequences. A billing and a restriction are
     | things a case produces, so including them would count one matter two or
     | three times; a case that has been billed is still its own overdue case
     | and is counted once, there. Violations already exclude any whose custody
     | carries an open incident, so a single custody is never counted twice.
     */
    $openAccountabilities = $accountabilityCaseTally->count();

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
     | Summary cards are informational only. Navigation and processing stay
     | in the explicit case lists, filters, and action controls below.
     */
    $summaryCards = [
        [
            'tone' => 'open',
            'icon' => 'requests',
            'label' => 'Open Accountability Cases',
            'value' => $openAccountabilities,
            'note' => 'Unresolved cases',
            'meta' => 'Counted once per matter',
            'href' => '#accountability-cases-section',
        ],
        [
            'tone' => 'overdue',
            'icon' => 'warning',
            'label' => 'Items Still Overdue',
            'value' => $currentlyOverdueCases->count(),
            'note' => 'Awaiting return',
            'meta' => 'Not yet returned, as of today',
            'emphasis' => $currentlyOverdueCases->count() > 0,
            /*
             * cases-interactions.blade.php reads ?focus=overdue to narrow
             * the rows already on the page to the Late Return type and the
             * OVERDUE status - it never queries the server again.
             */
            'href' => route('accountability.index', ['focus' => 'overdue']).'#accountability-cases-section',
        ],
        [
            'tone' => 'balance',
            'icon' => 'coins',
            'label' => 'Outstanding Balance',
            'value' => 'PHP '.number_format($outstandingBalance, 2),
            'note' => 'Unpaid obligations',
            'meta' => 'Not covered by a verified payment',
            'emphasis' => $outstandingBalance > 0,
            /* Same destination as "Open Accountability Cases" above - stays static rather than duplicating that link. */
            'href' => null,
        ],
        [
            'tone' => 'resolved',
            'icon' => 'approval',
            'label' => 'Resolved This Period',
            'value' => $resolvedThisPeriod,
            'note' => 'Cleared cases',
            'meta' => 'Closed during '.$resolvedPeriodLabel,
            'href' => '#resolved-history',
        ],
    ];
@endphp

<section class="accountability-overview" aria-label="Accountability summary">
    @foreach($summaryCards as $card)
        <div
            class="accountability-overview-card tone-{{ $card['tone'] }} has-emphasis is-static"
            aria-label="{{ $card['label'] }}: {{ $card['value'] }}. {{ $card['note'] }}"
        >
            <span class="accountability-overview-icon" aria-hidden="true"><x-icon :name="$card['icon']" size="18" /></span>
            <span class="accountability-overview-label">{{ $card['label'] }}</span>
            <strong class="accountability-overview-value">{{ $card['value'] }}</strong>
            <span class="accountability-overview-note">{{ $card['note'] }}</span>
        </div>
    @endforeach
</section>

@endunless

@if($isBorrower)
@include('accountability.partials.obligations-workspace')
@endif

@if($isHead && $pendingViolations->isNotEmpty())
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

    /*
     * One unified queue: every open property incident and every open
     * late-return case is one row in the same table. A billing linked to
     * either stays inside that same case's row instead of a second section -
     * only a genuinely standalone billing (no linked incident or overdue
     * case) gets its own row.
     */
    $displayIncidents = $openIncidents;
    $casesSectionVisible = ! $isBorrower;

    // Linked billing records stay inside their originating case row so the
    // same action is never rendered twice. Only a genuinely standalone
    // billing (no linked open Incident/OverdueCase) remains a row of its
    // own - the same AccountabilityCaseTally the summary card above and the
    // AO/Head dashboard KPI cards already use, so this table and those
    // counts can never drift apart again.
    $standaloneOpenBillings = $casesSectionVisible ? $accountabilityCaseTally->standaloneBillings() : collect();

    /*
     | Restriction <-> case linkage, so a restriction caused by a case
     | already in this table shows as an indicator on that case instead of a
     | second, separate row. Matches the exact relationship each restriction
     | record itself carries (incident_id, or custody_transaction_id when no
     | incident claims it first) - the same fields the old standalone
     | Restrictions table used to resolve its own "Linked Case" column, just
     | resolved case-first here instead of restriction-first. A restriction
     | pointing at a case that is not currently open/visible above (already
     | resolved, for example) is treated as unclaimed, so it is never
     | silently dropped - it still gets its own row below.
     */
    $restrictionForOverdueCase = static function ($overdue) use ($activeRestrictions) {
        if (! $overdue->custody_transaction_id) {
            return null;
        }

        return $activeRestrictions->first(
            fn ($restriction) => ! $restriction->incident_id
                && (int) ($restriction->custody_transaction_id ?? 0) === (int) $overdue->custody_transaction_id
        );
    };

    // Same canonical tally as the billings above: a restriction linked to an
    // open Incident/OverdueCase is a detail of that case, not counted again.
    $standaloneActiveRestrictions = $casesSectionVisible ? $accountabilityCaseTally->standaloneRestrictions() : collect();

    /*
     | BORROWER-CENTERED OVERVIEW
     |
     | Rolled up from the exact same open-case sources above, before any
     | ?borrower= narrowing, so every borrower with at least one open matter
     | appears exactly once. "Active Cases" counts each open Incident/
     | OverdueCase/standalone Billing/standalone Restriction once (the same
     | definition AccountabilityCaseTally already uses); "Current Attention"
     | is how many distinct custody transactions those matters touch - the
     | same grouping key the borrower workspace groups its Active Cases by.
     | This is presentation-only: no record is merged, only counted.
     */
    $borrowerRollups = $casesSectionVisible
        ? collect()
            ->concat($displayIncidents)
            ->concat($visibleOverdueCases)
            ->concat($standaloneOpenBillings)
            ->concat($standaloneActiveRestrictions)
            ->filter(fn ($record) => $record->borrower_user_id)
            ->groupBy('borrower_user_id')
            ->map(function ($records) use ($activeRestrictions) {
                $borrowerId = (int) $records->first()->borrower_user_id;

                return [
                    'borrower' => $records->first()->borrower,
                    'case_count' => $records->count(),
                    'custody_count' => $records->pluck('custody_transaction_id')->filter()->unique()->count(),
                    'restricted' => $activeRestrictions->contains(
                        fn ($restriction) => (int) $restriction->borrower_user_id === $borrowerId
                    ),
                ];
            })
            /* Preserves the Head/Admin dashboard's "Active Restrictions" ?view=restrictions deep link. */
            ->when($restrictionsFocusRequested, fn ($rows) => $rows->where('restricted', true))
            ->sortBy(fn (array $row) => Str::lower($row['borrower']?->full_name ?? ''))
            ->values()
        : collect();

    if ($borrowerFilterId) {
        $displayIncidents = $displayIncidents->where('borrower_user_id', $borrowerFilterId)->values();
        $visibleOverdueCases = $visibleOverdueCases->where('borrower_user_id', $borrowerFilterId)->values();
        $standaloneOpenBillings = $standaloneOpenBillings->where('borrower_user_id', $borrowerFilterId)->values();
        $standaloneActiveRestrictions = $standaloneActiveRestrictions->where('borrower_user_id', $borrowerFilterId)->values();
    }

    /* Whether there is any actual case to show - the section itself always renders. */
    $hasAnyCase = $visibleOverdueCases->isNotEmpty()
        || $displayIncidents->isNotEmpty()
        || $standaloneOpenBillings->isNotEmpty()
        || $standaloneActiveRestrictions->isNotEmpty();

    /* Every distinct status across all rendered case sources feeds the one status filter. */
    $caseStatusOptions = $visibleOverdueCases->pluck('status')
        ->merge($displayIncidents->pluck('status'))
        ->merge($standaloneOpenBillings->pluck('status'))
        ->merge($standaloneActiveRestrictions->pluck('status'))
        ->unique()
        ->values();

    /* Keep filter wording short but accurate to the actual accountability stage. */
    $caseStatusLabels = static function (string $status) use ($caseShortLabels): string {
        return match (strtoupper($status)) {
            App\Services\LateReturnService::STATUS_OVERDUE => 'Overdue',
            App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION => 'Returned Late',
            App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL => 'For Head Review',
            App\Services\LateReturnService::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
            'OPEN' => 'Awaiting Decision',
            'FOR_BILLING' => 'For Billing',
            'BILLING_PENDING' => 'Awaiting Payment',
            'COMPLIANCE_REQUIRED' => 'Compliance Required',
            'COMPLIANCE_RSLDDP_PENDING' => 'Compliance Pending',
            'RSLDDP_AWAITING_UPLOAD' => 'RSLDDP Upload',
            'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'For Accounting',
            'RSLDDP_PAYMENT_REQUIRED' => 'Payment Required',
            'RSLDDP_FOR_RESOLUTION' => 'For Resolution',
            'ISSUED' => 'Awaiting Payment',
            'PENDING_VERIFICATION' => 'Payment Verification',
            'ACTIVE' => 'Restricted',
            default => $caseShortLabels[$status]
                ?? (App\Support\StatusLabels::label($status) ?? str($status)->replace('_', ' ')->title()->toString()),
        };
    };

    /* Do not render controls that cannot narrow anything. */
    $availableCaseTypes = collect([
        $displayIncidents->isNotEmpty() ? 'PROPERTY' : null,
        $visibleOverdueCases->isNotEmpty() ? 'LATE_RETURN' : null,
        $standaloneOpenBillings->isNotEmpty() ? 'BILLING' : null,
        $standaloneActiveRestrictions->isNotEmpty() ? 'RESTRICTION' : null,
    ])->filter()->values();
    $showCaseSearch = $accountabilityCaseTally->count() > 5;
    $showCaseTypeFilter = $availableCaseTypes->count() > 1;
    $showCaseStatusFilter = $caseStatusOptions->count() > 1;
    $showCaseToolbar = $hasAnyCase && ($showCaseSearch || $showCaseTypeFilter || $showCaseStatusFilter || $restrictionsFocusRequested);
@endphp
@unless($isBorrower)
<section class="content-area accountability-case-workspace" id="accountability-cases-section">
@if($borrowerFilterId)
    <article class="card accountability-cases-card">
        <div class="accountability-cases-head">
            <h2>
                Active Accountability Cases
                <span class="accountability-count-chip" id="accountability-case-count" data-total="{{ $accountabilityCaseTally->count() }}">{{ $accountabilityCaseTally->count() }}</span>
            </h2>
        </div>

        @if($showCaseToolbar)
            <div class="accountability-browser-toolbar" aria-label="Search and filter active accountability cases">
                @if($showCaseSearch)
                    <label class="accountability-browser-search">
                        <span>Search</span>
                        <span class="search-input-shell">
                            <span class="search-input-icon" aria-hidden="true"><x-icon name="search" size="16" /></span>
                            <input
                                id="accountability-case-search"
                                type="search"
                                autocomplete="off"
                                placeholder="Borrower or reference"
                            >
                        </span>
                    </label>
                @endif

                @if($showCaseTypeFilter || $restrictionsFocusRequested)
                    <label class="accountability-browser-filter">
                        <span>Case Type</span>
                        <select id="accountability-case-type">
                            <option value="all" @selected(! $restrictionsFocusRequested)>All cases</option>
                            @if($displayIncidents->isNotEmpty())
                                <option value="PROPERTY">Property</option>
                            @endif
                            @if($visibleOverdueCases->isNotEmpty())
                                <option value="LATE_RETURN">Late Return</option>
                            @endif
                            @if($standaloneOpenBillings->isNotEmpty())
                                <option value="BILLING">Billing</option>
                            @endif
                            @if($activeRestrictions->isNotEmpty())
                                <option value="RESTRICTION" @selected($restrictionsFocusRequested)>Restricted</option>
                            @endif
                        </select>
                    </label>
                @endif

                @if($showCaseStatusFilter)
                    <label class="accountability-browser-filter">
                        <span>Status</span>
                        <select id="accountability-case-status">
                            <option value="all">All statuses</option>
                            @foreach($caseStatusOptions as $caseStatus)
                                <option value="{{ $caseStatus }}">{{ $caseStatusLabels($caseStatus) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        @endif

        @unless($hasAnyCase)
            <div class="empty-state">
                <div>
                    <strong>No active accountability cases.</strong>
                    <p>{{ $isOfficer
                        ? 'There are no overdue returns, property cases, standalone billings, or borrowing restrictions needing action right now.'
                        : 'There are no property cases, late-return assessments, or borrowing restrictions waiting on a decision right now.' }}</p>
                </div>
            </div>
        @else
        <div class="table-wrap accountability-cases-table">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Reference / Borrower</th>
                        <th scope="col">Case Type</th>
                        <th scope="col">Current Status</th>
                        <th scope="col">Case Summary</th>
                        <th scope="col" class="is-numeric">Amount</th>
                        <th scope="col">Next Action</th>
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

                            /* The billing this late-return case produced, if any - shown inside this same row, not a second section. */
                            $overdueBilling = null;
                            if ($hasBilling) {
                                $overdueBillingLine = Illuminate\Support\Facades\DB::table('billing_lines')
                                    ->join('penalties', 'billing_lines.penalty_id', '=', 'penalties.id')
                                    ->where('penalties.overdue_case_id', $overdue->id)
                                    ->latest('billing_lines.id')
                                    ->select('billing_lines.billing_statement_id')
                                    ->first();
                                $overdueBilling = $overdueBillingLine
                                    ? $billings->firstWhere('id', $overdueBillingLine->billing_statement_id)
                                    : null;
                            }
                            $overdueBillingPayable = $overdueBilling && ! in_array($overdueBilling->status, ['SETTLED', 'WAIVED', 'VOID'], true);

                            /* The restriction this late-return case caused, if any - shown as a flag on this row, not a second table. */
                            $overdueRestriction = $restrictionForOverdueCase($overdue);

                            /* Staff can audit the same official documents the borrower receives. */
                            $lateReturnNotice = $overdue->documents
                                ->where('document_type', 'LATE_RETURN_NOTICE')
                                ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                                ->sortByDesc('generated_at')
                                ->first();
                            $lateReturnBillingDocument = $overdueBilling?->documents
                                ?->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
                                ->sortByDesc('generated_at')
                                ->first();
                        @endphp

                        <tr
                            id="late-return-{{ $overdue->id }}"
                            class="accountability-case-row"
                            data-case
                            data-case-type="LATE_RETURN"
                            data-status="{{ $overdue->status }}"
                            data-has-restriction="{{ $overdueRestriction ? '1' : '0' }}"
                            data-search="{{ Str::lower($overdue->custody->custody_no.' '.$overdue->borrower->full_name) }}"
                            @if($restrictionsFocusRequested && ! $overdueRestriction) hidden @endif
                        >
                            <td>
                                <span class="accountability-case-ref">{{ $overdue->custody->custody_no }}</span>
                                <span class="accountability-case-borrower">{{ $overdue->borrower->full_name }}</span>
                            </td>
                            <td><span class="accountability-case-type-label">Late Return</span></td>
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
                            <td>
                                @if($isStillOverdue)
                                    <span>Awaiting return</span>
                                @else
                                    <span>{{ $assessment['late_days'] }} day{{ (int) $assessment['late_days'] === 1 ? '' : 's' }} late</span>
                                @endif
                                @if($overdueRestriction)
                                    <small class="accountability-restriction-flag">Borrowing restricted until resolved</small>
                                @endif
                            </td>
                            <td class="is-numeric">
                                {{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}
                                <small>{{ $assessment['is_estimate'] ? 'Estimated Fee So Far' : 'Final Late Return Fee' }}</small>
                            </td>
                            <td>
                                @if($isStillOverdue && $isOfficer)
                                    <a class="table-action accountability-next-link" href="{{ route('custody.return.show', $overdue->custody) }}">
                                        <span>Record Return</span>
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @elseif($headCanDecide)
                                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                                        <span>Review Assessment</span>
                                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                    </button>
                                @elseif($isOfficer && $hasBilling && $overdueBillingPayable)
                                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                                        <span>Record Payment</span>
                                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                    </button>
                                @elseif($isStillOverdue && $isHead)
                                    <div class="accountability-next-state-group">
                                        <span class="accountability-next-state">Waiting for AO</span>
                                        <button type="button" class="table-action accountability-view-detail" data-case-toggle aria-expanded="false">
                                            <span>View</span>
                                            <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                        </button>
                                    </div>
                                @elseif($isHead && $hasBilling && $overdueBilling)
                                    <div class="accountability-next-state-group">
                                        <span class="accountability-next-state">Waiting for payment</span>
                                        <button type="button" class="table-action accountability-view-detail" data-case-toggle aria-expanded="false">
                                            <span>View</span>
                                            <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                        </button>
                                    </div>
                                @elseif($hasBilling && $overdueBilling)
                                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                                        <span>View Billing</span>
                                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                    </button>
                                @else
                                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                                        <span>View Case</span>
                                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                                    </button>
                                @endif
                            </td>
                        </tr>

                        {{-- Collapsed by default; the action button above reveals only what is not already in the row. --}}
                        <tr class="accountability-case-detail-row" hidden>
                            <td colspan="6">
                                <div class="accountability-detail-stack">
                                    @if($isStillOverdue)
                                        <div class="accountability-detail-note">
                                            <strong>Still overdue</strong>
                                            <p>
                                                @if($isHead)
                                                    Next: the SPMU Action Officer records the physical return. Head review becomes available after the complete return is recorded.
                                                @else
                                                    Record the physical return from Return & Inspection. The final late-return assessment is created after the complete return.
                                                @endif
                                            </p>
                                        </div>
                                        <dl class="accountability-detail-grid">
                                            <div><dt>Expected Return</dt><dd>{{ $overdue->custody->due_at->format('d M Y') }}</dd></div>
                                            <div><dt>Status</dt><dd>Awaiting return</dd></div>
                                            <div><dt>Estimated Fee</dt><dd>{{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}</dd></div>
                                            <div><dt>Restriction</dt><dd>{{ $overdueRestriction ? 'Active' : 'None' }}</dd></div>
                                        </dl>
                                    @elseif($returnedForCorrection || $forHeadApproval)
                                        <div class="accountability-detail-note">
                                            <strong>{{ $returnedForCorrection ? 'Returned late' : 'Awaiting Head review' }}</strong>
                                            <p>{{ $fromLaundry ? 'Laundry received date is used as the return date.' : 'Assessment is based on the recorded physical return.' }}</p>
                                            @if($overdue->correction_remarks)
                                                <small>{{ $overdue->correction_remarks }}</small>
                                            @endif
                                        </div>
                                        <dl class="accountability-detail-grid">
                                            <div><dt>Expected Return</dt><dd>{{ $overdue->custody->due_at->format('d M Y') }}</dd></div>
                                            <div><dt>{{ $fromLaundry ? 'Laundry Received' : 'Actual Return' }}</dt><dd>{{ $assessment['actual_return_date']?->format('d M Y') ?? '—' }}</dd></div>
                                            <div><dt>Late Days</dt><dd>{{ $assessment['late_days'] ?? '—' }}</dd></div>
                                            <div><dt>{{ $assessment['is_estimate'] ? 'Estimated Fee' : 'Late Return Fee' }}</dt><dd>{{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}</dd></div>
                                        </dl>
                                    @elseif($hasBilling && $overdueBilling)
                                        <dl class="accountability-detail-grid">
                                            <div><dt>Billing Reference</dt><dd>{{ $overdueBilling->billing_no }}</dd></div>
                                            <div><dt>Payment Status</dt><dd><x-status-badge :status="$overdueBilling->status" /></dd></div>
                                            <div><dt>Due Date</dt><dd>{{ $overdueBilling->due_at ? $overdueBilling->due_at->format('d M Y') : '—' }}</dd></div>
                                            <div><dt>Amount</dt><dd>PHP {{ number_format((float) $overdueBilling->total_amount, 2) }}</dd></div>
                                        </dl>
                                        @if($lateReturnNotice || $lateReturnBillingDocument)
                                            <div class="accountability-document-list">
                                                @if($lateReturnNotice)
                                                    <div class="accountability-document-item">
                                                        <span>Late Return Notice</span>
                                                        <a class="table-action" href="{{ route('documents.preview', $lateReturnNotice) }}">Preview <span aria-hidden="true">→</span></a>
                                                    </div>
                                                @endif
                                                @if($lateReturnBillingDocument)
                                                    <div class="accountability-document-item">
                                                        <span>Late Return Billing Statement</span>
                                                        <a class="table-action" href="{{ route('documents.preview', $lateReturnBillingDocument) }}">Preview <span aria-hidden="true">→</span></a>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                    @endif

                                    @if($overdueRestriction)
                                        <dl class="accountability-detail-grid">
                                            <div><dt>Restriction</dt><dd>{{ $overdueRestriction->reason ?: str($overdueRestriction->restriction_type)->replace('_', ' ')->title() }}</dd></div>
                                            <div><dt>Effective From</dt><dd>{{ optional($overdueRestriction->effective_from)->format('d M Y') ?: '—' }}</dd></div>
                                            <div><dt>Until</dt><dd>{{ $overdueRestriction->effective_to ? $overdueRestriction->effective_to->format('d M Y') : 'Until resolved' }}</dd></div>
                                            <div><dt>Status</dt><dd>Active</dd></div>
                                        </dl>
                                    @endif
                                @if($headCanDecide)
                                    <div class="accountability-action-body accountability-action-body--direct">
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
                                    @if($assessment['rate'] === null)
                                        <div class="accountability-action-body accountability-action-body--direct">
                                            <p class="meta">No late-return fee policy applied when this case was assessed, so a Billing Statement cannot be issued. Resolve the case without a charge instead.</p>
                                            <form method="post" action="{{ route('overdue.resolve-without-charge', $overdue) }}" class="form-grid">
                                                @csrf
                                                <label>
                                                    Resolution Remarks
                                                    <textarea name="resolution_remarks" required placeholder="State why this case is resolved without a charge."></textarea>
                                                </label>
                                                <button class="button secondary ui-pressable">Resolve Without Charge</button>
                                            </form>
                                        </div>
                                    @endif
                                @endif

                                @if($isOfficer && $hasBilling && $overdueBillingPayable && $overdueBilling)
                                    <div class="accountability-action-body accountability-action-body--direct">
                                        <p class="eyebrow">Cashier Payment</p>
                                        <form method="post" action="{{ route('payments.store', $overdueBilling) }}" enctype="multipart/form-data" class="form-grid">
                                            @csrf
                                            <div class="form-columns">
                                                <label>Cashier Receipt No.<input name="official_receipt_no" required></label>
                                                <label>Receipt Date<input type="date" name="receipt_date" required></label>
                                                <label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label>
                                                <label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                            </div>
                                            <label>Remarks <small>(Optional)</small><textarea name="remarks" rows="2"></textarea></label>
                                            <button class="button primary ui-pressable accountability-primary-action">Record & Confirm Payment</button>
                                        </form>
                                    </div>
                                @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach

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
            $statusKey = strtoupper((string) $incident->status);
            // Precisely the current binary Confirm/Clear decision (spec
            // Section C) - a fresh OPEN incident only. Every other open
            // status (including every RSLDDP stage) has its own specific
            // branch below and must never fall into this one.
            $isAwaitingDecision = $isHead && $statusKey === 'OPEN';
            $isForBilling = $statusKey === 'FOR_BILLING';
            $isBillingPending = $statusKey === 'BILLING_PENDING';
            $isComplianceRequired = $statusKey === 'COMPLIANCE_REQUIRED';
            $isComplianceRsldppPending = $statusKey === 'COMPLIANCE_RSLDDP_PENDING';
            $isRsldppAwaitingUpload = $statusKey === 'RSLDDP_AWAITING_UPLOAD';
            $isRsldppDispositionPending = $statusKey === 'RSLDDP_DISPOSITION_PENDING';
            $isRsldppComplianceVerification = $statusKey === 'RSLDDP_COMPLIANCE_VERIFICATION';
            $isRsldppForAccountingProcessing = $statusKey === 'RSLDDP_FOR_ACCOUNTING_PROCESSING';
            $isRsldppPaymentRequired = $statusKey === 'RSLDDP_PAYMENT_REQUIRED';
            $isRsldppForResolution = $statusKey === 'RSLDDP_FOR_RESOLUTION';
            $hasUnpaidOfficialBilling = $incidentBilling
                && $incidentBilling->source === 'ACCOUNTING_OFFICE'
                && $incidentBilling->payments->isEmpty();
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
            $rsldppDocument = $incident->documents
                ->where('document_type', 'RSLDDP')
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
                'COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING' => 'Property Compliance',
                'RSLDDP_AWAITING_UPLOAD', 'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'RSLDDP & Settlement',
                'RSLDDP_DISPOSITION_PENDING' => 'Official Disposition',
                'RSLDDP_COMPLIANCE_VERIFICATION' => 'Compliance Verification',
                'RSLDDP_PAYMENT_REQUIRED' => 'Cashier Payment',
                'RSLDDP_FOR_RESOLUTION' => 'Admin Verification',
                'RESOLVED', 'CLOSED' => 'Completed',
                default => 'Accountability Confirmation',
            };
            $stepThreeSub = match ($statusKey) {
                'FOR_BILLING' => 'Generate & issue',
                'BILLING_PENDING' => 'AO receipt recording',
                'COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING' => 'Borrower compliance → AO verification',
                'RSLDDP_AWAITING_UPLOAD' => 'Head/Admin uploads accomplished RSLDDP',
                'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'Official Billing Statement pending',
                'RSLDDP_DISPOSITION_PENDING' => 'Head/Admin records the stated disposition',
                'RSLDDP_COMPLIANCE_VERIFICATION' => \App\Support\AccountabilityDispositionLabels::subStatusLabel($incident),
                'RSLDDP_PAYMENT_REQUIRED' => 'AO receipt recording',
                'RSLDDP_FOR_RESOLUTION' => 'Head/Admin reviews & resolves',
                'RESOLVED', 'CLOSED' => 'Requirement cleared',
                default => 'Confirm or clear the finding',
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
            $incidentDispositionStates = $incident->lines
                ->pluck('disposition_state')
                ->map(fn ($state) => strtoupper((string) $state))
                ->filter()
                ->unique()
                ->values();
            $complianceActionSummary = match (strtoupper((string) $incident->compliance_action)) {
                'REPAIR' => 'Repair and Return to Service',
                'REPLACEMENT' => 'One-for-One Replacement',
                'RECOVERY' => 'Item Recovery / Return',
                default => $incident->lines
                    ->map(fn ($line) => match (strtoupper((string) $line->disposition_state)) {
                        'DAMAGED_MAINTENANCE', 'REPAIR_REQUIRED' => 'Repair and Return to Service',
                        'LOST', 'STOLEN' => 'Item Recovery / Return or Replacement',
                        'DESTROYED', 'REPLACEMENT_REQUIRED' => 'One-for-One Replacement',
                        default => null,
                    })
                    ->filter()
                    ->unique()
                    ->implode(' / '),
            };
            $complianceActionSummary = $complianceActionSummary ?: 'Property Compliance';
            $allowRepairCompliance = $incidentDispositionStates->isNotEmpty()
                && $incidentDispositionStates->every(fn ($state) => in_array($state, ['DAMAGED_MAINTENANCE'], true));
            $allowRecoveryCompliance = $incidentDispositionStates->isNotEmpty()
                && $incidentDispositionStates->every(fn ($state) => in_array($state, ['LOST', 'STOLEN'], true));
            $allowReplacementCompliance = $incidentDispositionStates->isEmpty()
                || $incidentDispositionStates->every(fn ($state) => in_array($state, ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED', 'REPLACEMENT_REQUIRED'], true));
            $complianceVerifyLabel = match (strtoupper((string) $incident->compliance_action)) {
                'REPAIR' => 'Confirm Repair',
                'REPLACEMENT' => 'Confirm Replacement Received',
                'RECOVERY' => 'Confirm Item Recovery',
                default => 'Confirm Property Compliance',
            };
        @endphp

        <tr
            id="incident-{{ $incident->id }}"
            class="accountability-case-row"
            data-case
            data-case-type="PROPERTY"
            data-status="{{ $statusKey }}"
            data-has-restriction="{{ $incidentRestriction ? '1' : '0' }}"
            data-search="{{ Str::lower($custodyNo.' '.$requestNo.' '.($incident->borrower?->full_name ?? '')) }}"
            @if($restrictionsFocusRequested && ! $incidentRestriction) hidden @endif
        >
            <td>
                <span class="accountability-case-ref">{{ $custodyNo }}</span>
                @if(! $isBorrower && $incident->borrower_user_id)
                    <a class="accountability-case-borrower" href="{{ route('accountability.borrower', $incident->borrower_user_id) }}">{{ $incident->borrower?->full_name ?: '—' }}</a>
                @else
                    <span class="accountability-case-borrower">{{ $incident->borrower?->full_name ?: '—' }}</span>
                @endif
            </td>
            <td><span class="accountability-case-type-label">Property Damage</span></td>
            <td><x-status-badge :status="$incident->status" :label="$statusBadgeLabel" /></td>
            <td>
                <span>{{ $affectedPropertySummary ?: str($incident->incident_type)->replace('_', ' ')->title() }}</span>
                @if($incidentRestriction)
                    <small class="accountability-restriction-flag">Borrowing restricted until resolved</small>
                @endif
            </td>
            <td class="is-numeric">
                @if($incidentBilling)
                    PHP {{ number_format((float) $incidentBilling->total_amount, 2) }}
                @else
                    &mdash;
                @endif
            </td>
            <td>
                @php
                    $incidentNext = match(true) {
                        $isHead && $isAwaitingDecision => ['action', 'Review & Decide'],
                        $isOfficer && $statusKey === 'OPEN' => ['waiting', 'Waiting for Head'],
                        $isHead && $isForBilling => ['action', 'Issue Billing'],
                        $isOfficer && $isForBilling => ['waiting', 'Waiting for Head'],
                        $isOfficer && $isComplianceRequired => ['action', 'Verify Compliance'],
                        $isHead && $isComplianceRequired => ['waiting', 'Waiting for AO'],
                        $isHead && ($isComplianceRsldppPending || $isRsldppAwaitingUpload) => ['action', 'Upload RSLDDP'],
                        $isOfficer && ($isComplianceRsldppPending || $isRsldppAwaitingUpload) => ['waiting', 'Waiting for Head'],
                        $isHead && $isRsldppForAccountingProcessing => ['action', 'Record Billing'],
                        $isOfficer && $isRsldppForAccountingProcessing => ['waiting', 'Waiting for Head'],
                        $isOfficer && ($isBillingPending || $isRsldppPaymentRequired) && $incidentBilling && ! in_array($incidentBilling->status, ['SETTLED', 'WAIVED', 'VOID'], true) => ['action', 'Record Payment'],
                        $isHead && ($isBillingPending || $isRsldppPaymentRequired) => ['waiting', 'Waiting for payment'],
                        $isHead && $isRsldppForResolution => ['action', 'Review & Resolve'],
                        $isOfficer && $isRsldppForResolution => ['waiting', 'Waiting for Head'],
                        ($isBillingPending || $isRsldppPaymentRequired) && $incidentBilling => ['view', 'View Billing'],
                        default => ['view', 'View Case'],
                    };
                @endphp
                @if($incidentNext[0] === 'waiting')
                    <span class="accountability-next-state">{{ $incidentNext[1] }}</span>
                @else
                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                        <span>{{ $incidentNext[1] }}</span>
                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                    </button>
                @endif
            </td>
        </tr>
        <tr class="accountability-case-detail-row" hidden>
            <td colspan="6">
        <article class="card head-case-card accountability-case-workflow">
            <div class="accountability-case-identity">
                <div>
                    <p class="eyebrow">Property Case</p>
                    <strong>{{ $incident->incident_no }}</strong>
                    <h3>{{ str($incident->incident_type)->replace('_',' ')->title() }}</h3>
                    <div class="accountability-case-ref">
                        <span>{{ $requestNo }}</span>
                    </div>
                </div>
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
                            <strong>Accountability Confirmation</strong>
                            <p>Confirm the recorded finding is a valid accountability, or clear it. The offense and sanction, when applicable, apply automatically - this is not a manual choice.</p>
                        </div>
                    </div>

                    <dl class="accountability-decision-summary" aria-label="Accountability confirmation details">
                        <div>
                            <dt>Finding</dt>
                            <dd>{{ str($incident->incident_type)->replace('_',' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt>Item</dt>
                            <dd>{{ $affectedPropertySummary ?: 'See case details' }}</dd>
                        </div>
                        <div>
                            <dt>Recorded By</dt>
                            <dd>{{ $incident->reportedBy?->full_name ?: 'SPMU Action Officer' }}</dd>
                        </div>
                        @if($offensePreview)
                            <div>
                                <dt>Offense</dt>
                                <dd>
                                    @if($offensePreview['existing_sanction'])
                                        Already recorded — {{ $offensePreview['existing_sanction']->sanction_label }}
                                    @elseif($offensePreview['is_eligible'] && $offensePreview['can_confirm'])
                                        If Confirmed: {{ $offensePreview['next_offense_label'] }}
                                    @else
                                        Not applicable
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Sanction</dt>
                                <dd>
                                    @if($offensePreview['existing_sanction'] || ($offensePreview['is_eligible'] && $offensePreview['can_confirm']))
                                        {{ $offensePreview['configured_sanction_label'] }}
                                    @else
                                        Not applicable
                                    @endif
                                </dd>
                            </div>
                        @endif
                    </dl>

                    <details class="accountability-action-disclosure">
                        <summary><span>Confirm or Clear</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                        <div class="accountability-action-body">
                            <form method="post" action="{{ route('incidents.resolve', $incident) }}" class="form-grid">
                                @csrf
                                <div class="head-offense-choices" role="radiogroup" aria-label="Accountability decision">
                                    <label class="head-offense-choice">
                                        <input type="radio" name="decision" value="CONFIRM" required>
                                        <span class="head-offense-choice__copy">
                                            <strong>Confirm Accountability</strong>
                                            <small>Generates the RSLDDP and moves the case to RSLDDP Processing. The offense/sanction above, if applicable, applies automatically.</small>
                                        </span>
                                    </label>
                                    <label class="head-offense-choice">
                                        <input type="radio" name="decision" value="CLEAR" required>
                                        <span class="head-offense-choice__copy">
                                            <strong>Clear Finding / No Accountability</strong>
                                            <small>Resolves the case with no offense and no RSLDDP; the linked restriction is lifted.</small>
                                        </span>
                                    </label>
                                </div>
                                <label>
                                    Remarks
                                    <textarea name="resolution_remarks" rows="3" maxlength="2000" required placeholder="State the basis for this decision."></textarea>
                                </label>
                                <button class="button primary">Confirm Decision</button>
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
                                ? 'Physically verify the recorded compliance action when the borrower presents the property.'
                                : 'The borrower completes the recorded compliance action, then presents the property to the SPMU Action Officer for verification.' }}</p>
                        </div>
                        @if($complianceDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $complianceDocument) }}">Preview</a>
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
                            <p>{{ $headDecisionNote ?: 'Verify the recorded compliance action when the property is presented.' }}</p>
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
                                    <button class="button primary">{{ $complianceVerifyLabel }}</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isComplianceRsldppPending)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isHead ? 'Your current action' : 'Current status' }}</small>
                            <strong>RSLDDP Pending Upload</strong>
                            <p>{{ $isHead
                                ? 'This confirmed compliance case requires an RSLDDP. Upload the accomplished/notarized scan once external signing is complete to resume Action Officer compliance verification.'
                                : 'Action by: SPMU Head/Admin. An RSLDDP is being processed for this case alongside the required compliance. No borrower or Action Officer action is required until it is uploaded.' }}</p>
                        </div>
                        @if($rsldppDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                    @if($isHead)
                        <details class="accountability-action-disclosure">
                            <summary><span>Upload Accomplished RSLDDP</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.rslddp.upload', $incident) }}" enctype="multipart/form-data" class="form-grid">
                                    @csrf
                                    <label>Accomplished/Notarized RSLDDP Scan<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                    <button class="button primary">Upload Accomplished RSLDDP</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isRsldppAwaitingUpload)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isHead ? 'Your current action' : 'Current status' }}</small>
                            <strong>RSLDDP Processing</strong>
                            <p>{{ $isHead
                                ? 'Upload the accomplished/notarized RSLDDP scan once external processing is complete.'
                                : 'Action by: SPMU Head/Admin. External RSLDDP processing is required. Borrowing Status: Restricted. No borrower or Action Officer action is required until the accomplished RSLDDP is uploaded.' }}</p>
                        </div>
                        @if($rsldppDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                    @if($isHead)
                        <details class="accountability-action-disclosure">
                            <summary><span>Upload Accomplished RSLDDP</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.rslddp.upload', $incident) }}" enctype="multipart/form-data" class="form-grid">
                                    @csrf
                                    <label>Accomplished/Notarized RSLDDP Scan<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                    <button class="button primary">Upload Accomplished RSLDDP</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isRsldppDispositionPending)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isHead ? 'Your current action' : 'Current status' }}</small>
                            <strong>Official Disposition Pending</strong>
                            <p>{{ $isHead
                                ? 'Record the official disposition stated in the accomplished RSLDDP.'
                                : 'Action by: SPMU Head/Admin. The accomplished RSLDDP has been received. Borrowing Status: Restricted.' }}</p>
                        </div>
                        @if($rsldppDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                    @if($isHead)
                        <details class="accountability-action-disclosure">
                            <summary><span>Record Official Disposition</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.disposition.record', $incident) }}" class="form-grid">
                                    @csrf
                                    <label>
                                        Official Disposition
                                        <select name="official_disposition" required data-official-disposition>
                                            <option value="">Select the disposition stated in the RSLDDP</option>
                                            <option value="MONETARY_SETTLEMENT">Monetary Settlement</option>
                                            @if($allowRepairCompliance)
                                                <option value="REPAIR">Repair</option>
                                            @endif
                                            @if($allowReplacementCompliance)
                                                <option value="REPLACEMENT">Replacement</option>
                                            @endif
                                            @if($allowRecoveryCompliance)
                                                <option value="RETURN_RECOVERY">Return / Recovery</option>
                                            @endif
                                            <option value="OTHER">Other</option>
                                        </select>
                                    </label>
                                    <label data-official-disposition-amount hidden>
                                        Amount
                                        <input type="number" step="0.01" min="0.01" name="official_disposition_amount" placeholder="0.00">
                                    </label>
                                    <label data-official-disposition-details hidden>
                                        Details
                                        <textarea name="official_disposition_details" rows="2" maxlength="2000" placeholder="Describe the disposition stated in the RSLDDP."></textarea>
                                    </label>
                                    <label>
                                        Remarks
                                        <textarea name="resolution_remarks" rows="2" maxlength="2000" required placeholder="Note where this disposition is stated in the accomplished RSLDDP."></textarea>
                                    </label>
                                    <button class="button primary">Record Official Disposition</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isRsldppComplianceVerification)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ ($isOfficer && $incident->official_disposition !== 'MONETARY_SETTLEMENT') ? 'Your current action' : 'Current status' }}</small>
                            <strong>{{ \App\Support\AccountabilityDispositionLabels::subStatusLabel($incident) }}</strong>
                            <p>
                                Official Disposition: {{ \App\Support\AccountabilityDispositionLabels::officialDispositionLabel($incident->official_disposition) }}.
                                @if($incident->official_disposition === 'MONETARY_SETTLEMENT')
                                    Settle the assessed amount through the CSPC Cashier; the Action Officer records the official receipt.
                                @else
                                    The Action Officer verifies the presented requirement; the Action Officer does not choose the disposition.
                                @endif
                            </p>
                        </div>
                    </div>
                    @if($incident->official_disposition === 'MONETARY_SETTLEMENT' && $incidentBilling)
                        <div class="accountability-billing-brief">
                            <span><strong>{{ $incidentBilling->billing_no }}</strong></span>
                            <span>PHP {{ number_format((float) $incidentBilling->total_amount, 2) }}</span>
                            <x-status-badge :status="$incidentBilling->status" />
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
                        @endif
                        @if($isHead)
                            <details class="accountability-action-disclosure">
                                <summary><span>Attach External Billing Evidence</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                                <div class="accountability-action-body">
                                    <p class="accountability-owner-note">Optional. Use only if the Accounting Office separately issued its own official Billing Statement/SOA distinct from the accomplished RSLDDP.</p>
                                    <form method="post" action="{{ route('incidents.rslddp.billing', $incident) }}" enctype="multipart/form-data" class="form-grid">
                                        @csrf
                                        <div class="form-columns">
                                            <label>Amount<input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00"></label>
                                            <label>Accounting Billing/SOA Reference No.<input name="billing_reference" required></label>
                                            <label>Payment Due Date<input type="date" name="due_at"></label>
                                        </div>
                                        <label>External Billing Statement (PDF/image received from Accounting)<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                        <button class="button secondary">Attach External Billing Evidence</button>
                                    </form>
                                </div>
                            </details>
                        @endif
                    @elseif($isOfficer && $incident->official_disposition !== 'MONETARY_SETTLEMENT')
                        <details class="accountability-action-disclosure">
                            <summary><span>Record Verification</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.disposition.verify', $incident) }}" class="form-grid">
                                    @csrf
                                    <div class="inline-actions">
                                        <button class="button primary" name="decision" value="ACCEPTED">Accepted</button>
                                        <button class="button secondary" name="decision" value="NOT_ACCEPTED">Not Accepted</button>
                                    </div>
                                    <label>Remarks <small>(Required if Not Accepted)</small><textarea name="remarks" rows="2" maxlength="2000"></textarea></label>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isRsldppForAccountingProcessing)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isHead ? 'Your current action' : 'Current status' }}</small>
                            <strong>For Accounting Processing</strong>
                            <p>{{ $isHead
                                ? 'The accomplished RSLDDP was forwarded for Accounting processing. Record the official Billing Statement once it is received from the Accounting Office.'
                                : 'Action by: SPMU Head/Admin. The accomplished RSLDDP is being processed by the Accounting Office. No Action Officer action is required until the official Billing Statement is recorded.' }}</p>
                        </div>
                        @if($rsldppDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                    @if($isHead)
                        <details class="accountability-action-disclosure">
                            <summary><span>Record Official Billing Statement</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.rslddp.billing', $incident) }}" enctype="multipart/form-data" class="form-grid">
                                    @csrf
                                    <div class="form-columns">
                                        <label>Amount<input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00"></label>
                                        <label>Accounting Billing/SOA Reference No.<input name="billing_reference" required></label>
                                        <label>Payment Due Date<input type="date" name="due_at"></label>
                                    </div>
                                    <label>Official Billing Statement (PDF/image received from Accounting)<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label>
                                    <button class="button primary">Record Official Billing Statement</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            @elseif($isBillingPending || $isRsldppPaymentRequired)
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
                            @if($incidentBilling->source === 'ACCOUNTING_OFFICE')
                                @foreach($incidentBilling->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)
                                    <a class="button secondary small" href="{{ route('documents.preview',$document) }}">Preview</a>
                                @endforeach
                            @else
                                @foreach($incidentBilling->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)
                                    <a class="button secondary small" href="{{ route('documents.preview',$document) }}">Preview</a>
                                @endforeach
                            @endif
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
                            <p class="meta top-gap">
                                {{ $hasUnpaidOfficialBilling
                                    ? 'No further Head/Admin action is required while payment is pending. This Official Billing Statement can still be corrected (replaced) at this stage if needed.'
                                    : 'No further Head/Admin action is required while payment is pending.' }}
                            </p>
                        @endif
                    @else
                        <p class="meta">Billing record is being prepared.</p>
                    @endif
                </div>
            @elseif($isRsldppForResolution)
                <div class="accountability-current-step">
                    <div class="accountability-current-step__heading">
                        <div>
                            <small>{{ $isHead ? 'Your current action' : 'Current status' }}</small>
                            <strong>For Resolution</strong>
                            <p>{{ $isHead
                                ? 'The official Billing Statement is fully settled through a confirmed Cashier payment. Verify the accomplished RSLDDP and resolve this case.'
                                : 'Action by: SPMU Head/Admin. Payment has been confirmed. The case awaits final SPMU Head/Admin verification and resolution.' }}</p>
                        </div>
                        @if($rsldppDocument)
                            <div class="actions">
                                <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                    @if($isHead)
                        <details class="accountability-action-disclosure">
                            <summary><span>Verify & Resolve</span><x-icon name="chevron-down" size="15" class="accountability-disclosure-chevron" /></summary>
                            <div class="accountability-action-body">
                                <form method="post" action="{{ route('incidents.rslddp.resolve', $incident) }}" class="form-grid">
                                    @csrf
                                    <label>
                                        Resolution Remarks
                                        <textarea name="resolution_remarks" rows="3" maxlength="2000" required placeholder="Confirm the accomplished RSLDDP and settlement, and state the resolution basis."></textarea>
                                    </label>
                                    <button class="button primary">Verify & Resolve</button>
                                    <small class="accountability-resolution-note">The system will resolve the case and clear the linked restriction automatically.</small>
                                </form>
                            </div>
                        </details>
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
                </dl>

                @if($incidentRestriction)
                    <div class="top-gap">
                        <p class="eyebrow">Borrowing Restriction</p>
                        <dl class="head-case-summary">
                            <div><dt>Reason</dt><dd>{{ $incidentRestriction->reason ?: str($incidentRestriction->restriction_type)->replace('_', ' ')->title() }}</dd></div>
                            <div><dt>Effective From</dt><dd>{{ optional($incidentRestriction->effective_from)->format('d M Y') ?: '—' }}</dd></div>
                            <div><dt>Until</dt><dd>{{ $incidentRestriction->effective_to ? $incidentRestriction->effective_to->format('d M Y') : 'Until resolved' }}</dd></div>
                            <div><dt>Status</dt><dd><x-status-badge status="RESTRICTED" label="Active" /></dd></div>
                        </dl>
                        @if($incidentRestriction->incident_id)
                            <div class="actions top-gap">
                                <a class="button secondary small" href="{{ route('restrictions.notice', $incidentRestriction) }}">Preview</a>
                            </div>
                        @endif
                    </div>
                @endif

                @if($rsldppDocument)
                    <div class="actions top-gap">
                        <a class="button secondary small" href="{{ route('documents.preview', $rsldppDocument) }}">Preview</a>
                    </div>
                @endif

                <div class="table-wrap head-case-lines">
                    <table>
                        <thead><tr><th>Item</th><th>Qty</th><th>Finding</th><th>Physical Disposition</th></tr></thead>
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
                            <a class="button secondary small" href="{{ route('files.preview', $incident->supporting_evidence_file_id, false) }}">Preview</a>
                        @endif
                        @if($complianceDocument)
                            <a class="button secondary small" href="{{ route('documents.preview', $complianceDocument) }}">Preview</a>
                        @endif
                        @if($incident->police_blotter_reference)
                            <span class="meta">Blotter: <strong>{{ $incident->police_blotter_reference }}</strong></span>
                        @endif
                    </div>
                @endif
            </details>
        </article>
            </td>
        </tr>
    @endforeach

    @foreach($standaloneOpenBillings as $billing)
        <tr
            id="billing-{{ $billing->id }}"
            class="accountability-case-row"
            data-case
            data-case-type="BILLING"
            data-status="{{ $billing->status }}"
            data-has-restriction="0"
            data-search="{{ Str::lower($billing->billing_no.' '.$billing->borrower->full_name) }}"
            @if($restrictionsFocusRequested) hidden @endif
        >
            <td>
                <span class="accountability-case-ref">{{ $billing->billing_no }}</span>
                <span class="accountability-case-borrower">{{ $billing->borrower->full_name }}</span>
            </td>
            <td><span class="accountability-case-type-label">Billing</span></td>
            <td><x-status-badge :status="$billing->status" /></td>
            <td><span>{{ $billing->lines->map(fn ($line) => str($line->line_type)->replace('_', ' ')->title())->unique()->implode(', ') ?: 'Billing statement' }}</span></td>
            <td class="is-numeric">PHP {{ number_format((float) $billing->total_amount, 2) }}</td>
            <td>
                @if($isOfficer && ! in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true))
                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                        <span>Record Payment</span>
                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                    </button>
                @elseif($isHead && ! in_array($billing->status, ['SETTLED', 'WAIVED', 'VOID'], true))
                    <span class="accountability-next-state">Waiting for payment</span>
                @else
                    <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                        <span>View Billing</span>
                        <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                    </button>
                @endif
            </td>
        </tr>
        <tr class="accountability-case-detail-row" hidden>
            <td colspan="6">
<article class="card">
<div class="billing-lines">@foreach($billing->lines as $line)<p><strong>{{ str($line->line_type)->replace('_',' ')->title() }}</strong><span>{{ $line->description }}</span><small>PHP {{ number_format((float)$line->amount,2) }}</small></p>@endforeach</div>
<div class="accountability-document-list">@foreach($billing->documents->whereNotIn('status',['SUPERSEDED','INVALIDATED','EXPIRED']) as $document)<div class="accountability-document-item"><span>{{ $billing->isLateReturnBilling() ? 'Late Return Billing Statement' : 'Billing Statement' }}</span><a class="table-action" href="{{ route('documents.preview',$document) }}">Preview <span aria-hidden="true">→</span></a></div>@endforeach</div>
<p class="meta">The borrower pays at the CSPC Cashier and presents the official receipt to the Action Officer. Confirm the payment only after checking the receipt.</p>
@if($isOfficer && !in_array($billing->status,['SETTLED','WAIVED','VOID'],true))
<form method="post" action="{{ route('payments.store',$billing) }}" enctype="multipart/form-data" class="form-grid top-gap">@csrf<div class="card-header"><div><h4>Record Cashier Payment</h4><small>Check the official receipt, encode it once, then confirm.</small></div></div><div class="form-columns"><label>Cashier Receipt No.<input name="official_receipt_no" required></label><label>Receipt Date<input type="date" name="receipt_date" required></label><label>Amount Paid<input type="number" step="0.01" min="0.01" name="amount" required></label><label>Scanned Paid Receipt<input type="file" name="evidence" accept="application/pdf,image/png,image/jpeg,image/webp" required></label></div><label>Remarks <small>(Optional)</small><textarea name="remarks"></textarea></label><button class="button primary">Record & Confirm Payment</button></form>
@endif
<div class="top-gap">
@forelse($billing->payments as $payment)
<div class="evidence-row"><div><x-status-badge :status="$payment->status" /><strong>{{ $payment->official_receipt_no }}</strong><small>{{ optional($payment->receipt_date)->format('d M Y') }} · PHP {{ number_format((float)$payment->amount,2) }}</small>@if($payment->evidence_file_id)<a class="table-action" href="{{ route('files.preview', $payment->evidence_file_id, false) }}">Preview</a>@endif</div>
@if($payment->status==='PENDING_VERIFICATION')<small class="meta">Legacy payment record from the previous two-step workflow.</small>@endif</div>
@empty<p class="meta">No paid Cashier receipt uploaded.</p>@endforelse
</div>
</article>
            </td>
        </tr>
    @endforeach

    {{--
        A restriction not claimed by any case above (a standalone
        administrative restriction, or one whose case is no longer open) is
        its own row here instead of a separate page-wide Restrictions module.
    --}}
    @foreach($standaloneActiveRestrictions as $restriction)
        @php
            $restrictionTypeLabel = str($restriction->restriction_type)->replace('_', ' ')->title();
        @endphp
        <tr
            id="restriction-{{ $restriction->id }}"
            class="accountability-case-row"
            data-case
            data-case-type="RESTRICTION"
            data-status="{{ $restriction->status }}"
            data-has-restriction="1"
            data-search="{{ Str::lower(($restriction->custody?->custody_no ?? '').' '.($restriction->borrower?->full_name ?? '')) }}"
        >
            <td>
                <span class="accountability-case-ref">Restriction Record</span>
                <span class="accountability-case-borrower">{{ $restriction->borrower?->full_name ?: '—' }}</span>
            </td>
            <td><span class="accountability-case-type-label">Administrative Restriction</span></td>
            <td><x-status-badge status="RESTRICTED" label="Active" /></td>
            <td><span>{{ $restriction->reason ?: $restrictionTypeLabel }}</span></td>
            <td class="is-numeric">&mdash;</td>
            <td>
                <button type="button" class="table-action" data-case-toggle aria-expanded="false">
                    <span>View Case</span>
                    <x-icon name="chevron-down" size="13" class="accountability-toggle-chevron" />
                </button>
            </td>
        </tr>
        <tr class="accountability-case-detail-row" hidden>
            <td colspan="6">
                <p class="eyebrow">Borrowing Restriction</p>
                <dl class="head-case-summary">
                    <div><dt>Restriction Type</dt><dd>{{ $restrictionTypeLabel }}</dd></div>
                    <div><dt>Reason</dt><dd>{{ $restriction->reason ?: '—' }}</dd></div>
                    <div><dt>Effective From</dt><dd>{{ optional($restriction->effective_from)->format('d M Y') ?: '—' }}</dd></div>
                    <div><dt>Until</dt><dd>{{ $restriction->effective_to ? $restriction->effective_to->format('d M Y') : 'Until resolved' }}</dd></div>
                </dl>
                @if($restriction->incident_id)
                    <div class="actions top-gap">
                        <a class="button secondary small" href="{{ route('restrictions.notice', $restriction) }}">Preview</a>
                    </div>
                @endif
                @if($isOfficer)
                    <p class="meta top-gap">Reference only. Action Officers can see active restrictions but cannot create, extend, lift, or override them.</p>
                @endif
            </td>
        </tr>
    @endforeach
                </tbody>
            </table>
        </div>

        <p id="accountability-cases-none" class="accountability-cases-none" hidden>
            No cases match the current search or filters.
        </p>
        @endunless
    </article>

    @include('accountability.partials.cases-interactions')

@else
    {{--
        Borrower-centered overview: one row per borrower with at least one
        open accountability matter, never a flat per-case row and never a
        per-row dropdown/expander. Opening a borrower's row is the only way
        into their cases, via the dedicated borrower workspace.
    --}}
    <article class="card accountability-cases-card">
        <div class="accountability-cases-head">
            <h2>
                Borrowers with Active Accountability
                <span class="accountability-count-chip" id="accountability-borrower-count" data-total="{{ $borrowerRollups->count() }}">{{ $borrowerRollups->count() }}</span>
            </h2>
        </div>

        @if($borrowerRollups->isEmpty())
            <div class="empty-state">
                <div>
                    <strong>No active accountability cases.</strong>
                    <p>{{ $isOfficer
                        ? 'There are no overdue returns, property cases, standalone billings, or borrowing restrictions needing action right now.'
                        : 'There are no property cases, late-return assessments, or borrowing restrictions waiting on a decision right now.' }}</p>
                </div>
            </div>
        @else
            <div class="table-wrap accountability-cases-table">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Borrower</th>
                            <th scope="col" class="is-numeric">Active Cases</th>
                            <th scope="col">Borrowing Status</th>
                            <th scope="col">Current Attention</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($borrowerRollups as $row)
                            <tr class="accountability-borrower-row">
                                <td><span class="accountability-case-borrower">{{ $row['borrower']?->full_name ?: '—' }}</span></td>
                                <td class="is-numeric">{{ $row['case_count'] }}</td>
                                <td>
                                    @if($row['restricted'])
                                        <x-status-badge status="BORROWING_RESTRICTED" label="Restricted" />
                                    @else
                                        <x-status-badge status="RESOLVED" label="Clear" />
                                    @endif
                                </td>
                                <td>{{ $row['custody_count'] }} Custody Record{{ $row['custody_count'] === 1 ? '' : 's' }}</td>
                                <td>
                                    @if($row['borrower'])
                                        <a class="table-action accountability-next-link" href="{{ route('accountability.borrower', $row['borrower']) }}">
                                            <span>View Accountability</span>
                                            <span aria-hidden="true">→</span>
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </article>
@endif

</section>
@endunless

@unless($isBorrower)
    @include('accountability.partials.resolved-history')
@endunless

@if(($isHead || $workspace === 'BORROWER') && $sanctions->isNotEmpty())
<section class="content-area accountability-sanctions-section">
    <article class="card accountability-cases-card accountability-sanctions-card">
        <div class="accountability-cases-head">
            <h2>
                {{ $workspace === 'BORROWER' ? 'My Sanctions' : 'Sanction History' }}
                <span class="accountability-count-chip">{{ $sanctions->count() }}</span>
            </h2>
        </div>

        <div class="table-wrap accountability-cases-table accountability-sanctions-table">
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
                        <tr class="accountability-sanction-row">
                            @if($isHead)
                                <td>
                                    <span class="accountability-case-ref">{{ $sanction->borrower->full_name }}</span>
                                </td>
                            @endif
                            <td><span class="accountability-sanction-offense">{{ $sanction->offense_no }}</span></td>
                            <td>
                                <span class="accountability-sanction-action">{{ $sanction->sanction_label }}</span>
                                @if($sanction->remarks)
                                    <small class="accountability-sanction-note" title="{{ $sanction->remarks }}">{{ $sanction->remarks }}</small>
                                @endif
                            </td>
                            <td>{{ $sanction->academicPeriod?->academic_year }} {{ $sanction->academicPeriod?->term_name }}</td>
                            <td>
                                {{ optional($sanction->effective_from)->format('d M Y') ?: '—' }}
                                @if($sanction->effective_to)
                                    <small>to {{ $sanction->effective_to->format('d M Y') }}</small>
                                @endif
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
                                    <a class="table-action accountability-sanction-notice" href="{{ route('documents.preview', $sanctionNotice) }}">
                                        <span>View Notice</span>
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @else
                                    <span class="accountability-row-none">—</span>
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


<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-head-decision-form]').forEach((form) => {
        const outcome = form.querySelector('[data-resolution-outcome]');
        const complianceField = form.querySelector('[data-compliance-action-field]');
        const complianceSelect = form.querySelector('[data-compliance-action]');
        const rslddpField = form.querySelector('[data-rslddp-field]');
        const rslddpInputs = rslddpField?.querySelectorAll('input[name="requires_rslddp"]') || [];

        const sync = () => {
            const complianceRequired = outcome?.value === 'COMPLIANCE_REQUIRED';
            if (complianceField) complianceField.hidden = !complianceRequired;
            if (rslddpField) rslddpField.hidden = !complianceRequired;
            if (complianceSelect) complianceSelect.required = complianceRequired;
            rslddpInputs.forEach((input) => {
                input.required = complianceRequired;
                if (!complianceRequired) input.checked = false;
            });
        };

        outcome?.addEventListener('change', sync);
        sync();
    });
});
</script>

@endsection
