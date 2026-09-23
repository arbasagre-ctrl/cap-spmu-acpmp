@extends('layouts.app', ['title' => 'Accountability — '.$borrower->full_name])
@section('content')

@include('accountability.partials.obligations-styles')

<style>
    /* Borrower accountability uses the same compact scale as the rest of the
       SPMU workspace. Case cards stay readable, but status/action copy never
       becomes a second oversized dashboard. */
    .borrower-accountability-workspace .page-heading { margin-bottom: 14px; }
    .borrower-accountability-workspace .page-heading h1 {
        font-size: clamp(24px, 1.9vw, 29px);
        margin: 4px 0 5px;
    }
    .borrower-accountability-workspace .page-heading p:not(.eyebrow) {
        font-size: 12.5px;
        line-height: 1.45;
    }
    .accountability-back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 7px;
        color: var(--interactive);
        font-size: 11.5px;
        font-weight: 750;
        text-decoration: none;
    }
    .accountability-back-link:hover { text-decoration: underline; }

    .accountability-workspace-summary {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 14px;
    }
    .accountability-workspace-summary > .dashboard-kpi-card {
        min-height: 108px;
        padding: 13px 15px 14px;
        gap: 3px;
    }
    .accountability-workspace-summary .kpi-icon {
        width: 30px;
        height: 30px;
        border-radius: 8px;
    }
    .accountability-workspace-summary .kpi-label { font-size: 11px; }
    .accountability-workspace-summary .kpi-value {
        margin-top: 3px;
        font-size: 26px;
        line-height: 1.05;
    }
    .accountability-workspace-summary .kpi-note {
        font-size: 10.5px;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }

    .accountability-workspace-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        margin: 0 0 16px;
    }
    .accountability-workspace-tab {
        appearance: none;
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 0;
        min-height: 40px;
        padding: 8px 12px;
        border: 1px solid var(--border);
        border-radius: 9px;
        background: var(--surface-elevated);
        color: var(--text-secondary);
        font-size: 12px;
        font-weight: 750;
        line-height: 1.2;
        text-align: center;
        text-decoration: none;
    }
    .accountability-workspace-tab:hover { border-color: var(--border-strong); background: var(--surface-hover); }
    .accountability-workspace-tab.is-active {
        border-color: var(--interactive);
        background: var(--info-bg);
        color: var(--interactive);
        box-shadow: inset 0 2px 0 var(--interactive);
    }

    /* Documents are a simple left-aligned record list. Do not reuse the
       dashboard queue row here: that component carries dashboard-specific
       alignment rules that can visually push document copy toward the middle. */
    .accountability-document-panel { padding: 0; overflow: hidden; }
    .accountability-document-list { display: grid; }
    .accountability-document-row {
        display: grid;
        grid-template-columns: 34px minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        min-width: 0;
        padding: 12px 14px;
        border-bottom: 1px solid var(--row-border);
        text-align: left;
    }
    .accountability-document-row:last-child { border-bottom: 0; }
    .accountability-document-row .dashboard-activity-icon {
        width: 34px;
        height: 34px;
        justify-self: start;
    }
    .accountability-document-copy {
        display: grid;
        min-width: 0;
        justify-self: stretch;
        text-align: left;
    }
    .accountability-document-copy strong,
    .accountability-document-copy span,
    .accountability-document-copy small {
        display: block;
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .accountability-document-copy strong {
        color: var(--heading);
        font-size: 12px;
        font-weight: 750;
        line-height: 1.35;
    }
    .accountability-document-copy span {
        margin-top: 2px;
        color: var(--text-secondary);
        font-size: 11.5px;
        line-height: 1.4;
    }
    .accountability-document-copy small {
        margin-top: 3px;
        color: var(--text-muted);
        font-size: 10.75px;
        line-height: 1.4;
    }
    .accountability-document-row > .button {
        justify-self: end;
        width: auto;
        min-width: 74px;
        white-space: nowrap;
    }

    .accountability-custody-groups { display: grid; gap: 12px; }
    .accountability-custody-group { padding: 14px; }
    .accountability-custody-group__header {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-bottom: 10px;
        padding-bottom: 9px;
        border-bottom: 1px solid var(--border);
        color: var(--text-secondary);
    }
    .accountability-custody-group__header strong {
        color: var(--text-primary);
        font-size: 13px;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }
    .accountability-custody-group .ob-case-list { gap: 10px; }
    .accountability-custody-case {
        padding: 13px 14px;
        background: var(--surface-elevated);
        box-shadow: none;
    }
    .accountability-custody-case .ob-case-identity { gap: 10px; }
    .accountability-custody-case .ob-case-icon {
        width: 34px;
        height: 34px;
        border-radius: 9px;
    }
    .accountability-custody-case .ob-case-type { font-size: 9px; margin-bottom: 3px; }
    .accountability-case-title-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 7px 9px;
        min-width: 0;
    }
    .accountability-case-title-row h3 {
        margin: 0;
        font-size: 13.5px;
        line-height: 1.35;
    }
    .accountability-case-status {
        display: inline-flex;
        align-items: center;
        max-width: 100%;
        min-height: 22px;
        padding: 3px 8px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--surface-subtle);
        color: var(--text-secondary);
        font-size: 9.5px;
        font-weight: 750;
        line-height: 1.3;
        overflow-wrap: anywhere;
        white-space: normal;
    }
    .accountability-case-status.is-danger { color: var(--danger); background: var(--danger-bg); border-color: var(--danger-border); }
    .accountability-case-status.is-warning { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
    .accountability-case-status.is-info { color: var(--info); background: var(--info-bg); border-color: var(--info-border); }
    .accountability-case-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 5px 14px;
        margin: 8px 0 0 44px;
        color: var(--text-secondary);
        font-size: 11.5px;
        line-height: 1.45;
    }
    .accountability-case-summary span { overflow-wrap: anywhere; }
    .accountability-case-summary strong { color: var(--text-primary); font-weight: 700; }

    .accountability-case-responsibility {
        display: grid;
        grid-template-columns: minmax(135px, .75fr) minmax(0, 1.5fr) auto;
        align-items: center;
        gap: 8px 14px;
        margin: 10px 0 0 44px;
        padding: 9px 10px;
        border: 1px solid var(--border);
        border-left: 3px solid var(--info);
        border-radius: 8px;
        background: var(--surface-subtle);
    }
    .accountability-role-copy { min-width: 0; }
    .accountability-role-copy small {
        display: block;
        margin-bottom: 2px;
        color: var(--text-muted);
        font-size: 8.5px;
        font-weight: 800;
        letter-spacing: .05em;
        text-transform: uppercase;
    }
    .accountability-role-copy strong,
    .accountability-role-copy span {
        display: block;
        color: var(--text-primary);
        font-size: 10.75px;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }
    .accountability-role-copy span { color: var(--text-secondary); }
    .accountability-action-button {
        min-height: 32px;
        padding: 6px 10px;
        white-space: normal;
        text-align: center;
        line-height: 1.25;
    }

    .accountability-action-dialog {
        width: min(92vw, 560px);
        max-height: min(86vh, 720px);
        padding: 0;
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-elevated);
        color: var(--text-primary);
        box-shadow: 0 22px 60px rgba(8, 27, 50, .24);
    }
    .accountability-action-dialog::backdrop { background: rgba(8, 27, 50, .45); }
    .accountability-action-dialog__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 16px 12px;
        border-bottom: 1px solid var(--border);
    }
    .accountability-action-dialog__header > div { min-width: 0; }
    .accountability-action-dialog__header small {
        display: block;
        margin-bottom: 3px;
        color: var(--text-muted);
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .05em;
        text-transform: uppercase;
    }
    .accountability-action-dialog__header h3 {
        margin: 0;
        font-size: 14px;
        line-height: 1.35;
        overflow-wrap: anywhere;
    }
    .accountability-dialog-close {
        display: grid;
        place-items: center;
        flex: 0 0 auto;
        width: 30px;
        height: 30px;
        padding: 0;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--surface);
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
    }
    .accountability-action-dialog__body {
        max-height: calc(86vh - 58px);
        overflow: auto;
        padding: 14px 16px 16px;
    }
    .accountability-action-dialog__body form { display: grid; gap: 12px; }
    .accountability-action-dialog__body .form-grid { gap: 10px; }
    .accountability-action-dialog__body .form-grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .accountability-action-dialog__body label {
        min-width: 0;
        font-size: 10.5px;
    }
    .accountability-action-dialog__body textarea { min-height: 88px; resize: vertical; }
    .accountability-dialog-note {
        margin: 0;
        padding: 9px 10px;
        border-left: 3px solid var(--info);
        border-radius: 7px;
        background: var(--surface-subtle);
        color: var(--text-secondary);
        font-size: 10.5px;
        line-height: 1.5;
    }
    .accountability-dialog-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
        padding-top: 2px;
    }

    @media (max-width: 820px) {
        .accountability-workspace-summary { grid-template-columns: 1fr; }
        .accountability-workspace-summary > .dashboard-kpi-card { min-height: 96px; }
        .accountability-case-responsibility { grid-template-columns: 1fr 1fr; }
        .accountability-case-responsibility .accountability-action-button { grid-column: 1 / -1; justify-self: start; }
    }
    @media (max-width: 620px) {
        .accountability-workspace-tabs { grid-template-columns: 1fr; gap: 7px; }
        .accountability-document-row {
            grid-template-columns: 30px minmax(0, 1fr);
            gap: 9px;
            padding: 11px 12px;
        }
        .accountability-document-row > .button {
            grid-column: 2;
            justify-self: start;
            min-width: 0;
            margin-top: 2px;
        }
        .accountability-workspace-tab { min-height: 38px; }
        .accountability-custody-group { padding: 12px; }
        .accountability-custody-case { padding: 12px; }
        .accountability-case-summary,
        .accountability-case-responsibility { margin-left: 0; }
        .accountability-case-responsibility { grid-template-columns: 1fr; }
        .accountability-case-responsibility .accountability-action-button { grid-column: auto; width: 100%; }
        .accountability-action-dialog__body .form-grid.two { grid-template-columns: 1fr; }
    }
</style>

@php
    $requestedTab = (string) request('tab', 'cases');
    $activeTab = in_array($requestedTab, ['cases', 'documents', 'history'], true) ? $requestedTab : 'cases';
    $activeRestrictionCount = (int) ($overview['restrictions'] ?? 0);
    $borrowingStatus = $activeRestrictionCount > 0 ? 'Restricted' : 'Clear';
    $classification = auth()->user()?->access_classification?->value;
    $isOfficer = $classification === 'SPMU_OFFICER';
    $isHead = $classification === 'SPMU_HEAD';

    $billingForIncident = static function ($incident) use ($openBillings) {
        return $openBillings->first(fn ($billing) => $billing->lines->contains(
            fn ($line) => (int) ($line->incident_id ?? 0) === (int) $incident->id
        ));
    };

    $billingForOverdue = static function ($overdue) use ($openBillings) {
        return $openBillings->first(fn ($billing) => $billing->lines->contains(
            fn ($line) => (int) ($line->penalty?->overdue_case_id ?? 0) === (int) $overdue->id
        ));
    };
@endphp

<div class="borrower-accountability-workspace">
<section class="page-heading">
    <div>
        <a class="accountability-back-link" href="{{ route('accountability.index') }}">
            <span aria-hidden="true">←</span><span>Accountability Processing</span>
        </a>
        <p class="eyebrow">Borrower Accountability</p>
        <h1>{{ $borrower->full_name }}</h1>
        <p>{{ $borrower->organizationalUnit?->unit_name ?: $borrower->organizationalUnit?->name }}</p>
    </div>
</section>

<section class="stat-grid accountability-workspace-summary" aria-label="Borrower accountability summary">
    <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-{{ $activeRestrictionCount > 0 ? 'danger' : 'success' }}">
        <span class="kpi-icon" aria-hidden="true"><x-icon :name="$activeRestrictionCount > 0 ? 'lock' : 'check-circle'" size="16" /></span>
        <span class="kpi-label">Borrowing Status</span>
        <strong class="kpi-value">{{ $borrowingStatus }}</strong>
        <span class="kpi-note">{{ $activeRestrictionCount > 0 ? $activeRestrictionCount.' active restriction(s)' : 'No active restriction' }}</span>
    </article>
    <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-info">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="accountability" size="16" /></span>
        <span class="kpi-label">Active Cases</span>
        <strong class="kpi-value">{{ $overview['count'] ?? 0 }}</strong>
        <span class="kpi-note">Unresolved accountability matters</span>
    </article>
    <article class="card stat-card kpi-card dashboard-kpi-card kpi-accent-warning">
        <span class="kpi-icon" aria-hidden="true"><x-icon name="warning" size="16" /></span>
        <span class="kpi-label">Borrower Action Pending</span>
        <strong class="kpi-value">{{ $overview['needs_action'] ?? 0 }}</strong>
        <span class="kpi-note">Cases currently waiting on the borrower</span>
    </article>
</section>

<nav class="accountability-workspace-tabs" aria-label="Borrower accountability sections" role="tablist">
    <a role="tab" aria-selected="{{ $activeTab === 'cases' ? 'true' : 'false' }}" class="accountability-workspace-tab {{ $activeTab === 'cases' ? 'is-active' : '' }}" href="{{ route('accountability.borrower', [$borrower, 'tab' => 'cases']) }}">Active Cases</a>
    <a role="tab" aria-selected="{{ $activeTab === 'documents' ? 'true' : 'false' }}" class="accountability-workspace-tab {{ $activeTab === 'documents' ? 'is-active' : '' }}" href="{{ route('accountability.borrower', [$borrower, 'tab' => 'documents']) }}">Documents</a>
    <a role="tab" aria-selected="{{ $activeTab === 'history' ? 'true' : 'false' }}" class="accountability-workspace-tab {{ $activeTab === 'history' ? 'is-active' : '' }}" href="{{ route('accountability.borrower', [$borrower, 'tab' => 'history']) }}">History</a>
</nav>

@if($activeTab === 'cases')
    <section role="tabpanel" aria-label="Active Cases">
        @php
            $custodyGroups = collect()
                ->concat($openIncidents)
                ->concat($openOverdueCases)
                ->groupBy(fn ($record) => $record->custody_transaction_id ?: 'standalone-'.$record->getMorphClass().'-'.$record->id)
                ->sortByDesc(fn ($group) => $group->max(
                    fn ($record) => optional($record->reported_at ?? $record->overdue_started_at ?? $record->created_at)->timestamp
                ));
        @endphp

        @if($custodyGroups->isEmpty())
            <div class="empty-state">
                <strong>No active accountability cases.</strong>
                <span>This borrower currently has no open property or late-return accountability matter.</span>
            </div>
        @else
            <div class="accountability-custody-groups">
                @foreach($custodyGroups as $groupRecords)
                    @php $groupCustody = $groupRecords->first()->custody; @endphp
                    <article class="card accountability-custody-group">
                        <header class="accountability-custody-group__header">
                            <x-icon name="inventory" size="15" />
                            <strong>{{ $groupCustody?->custody_no ?: 'No custody reference' }}</strong>
                        </header>

                        <div class="ob-case-list">
                            @foreach($groupRecords as $record)
                                @php $isOverdueCase = $record instanceof \App\Models\OverdueCase; @endphp

                                @if($isOverdueCase)
                                    @php
                                        $lateReturnService = app(\App\Services\LateReturnService::class);
                                        $assessment = $lateReturnService->assessment($record);
                                        $isStillOverdue = $record->status === \App\Services\LateReturnService::STATUS_OVERDUE;
                                        $isHeadReview = in_array($record->status, [
                                            \App\Services\LateReturnService::STATUS_FOR_AO_CONFIRMATION,
                                            \App\Services\LateReturnService::STATUS_FOR_HEAD_APPROVAL,
                                        ], true);
                                        $isAwaitingPayment = $record->status === \App\Services\LateReturnService::STATUS_AWAITING_PAYMENT;
                                        $linkedBilling = $billingForOverdue($record);
                                        $statusLabel = match (true) {
                                            $isStillOverdue => 'Overdue — Awaiting Return',
                                            $isHeadReview => 'For Head Review',
                                            $isAwaitingPayment => 'Awaiting Cashier Payment',
                                            default => \App\Services\LateReturnService::label($record->status),
                                        };
                                        $statusTone = $isStillOverdue ? 'danger' : ($isAwaitingPayment ? 'warning' : 'info');
                                        $responsibleNow = match (true) {
                                            $isStillOverdue => 'SPMU Action Officer',
                                            $isHeadReview => 'SPMU Head/Admin',
                                            $isAwaitingPayment => 'Borrower / SPMU Action Officer',
                                            default => 'SPMU',
                                        };
                                        $currentRoleOwnsAction = ($isOfficer && ($isStillOverdue || $isAwaitingPayment)) || ($isHead && $isHeadReview);
                                        $roleMessage = match (true) {
                                            $currentRoleOwnsAction && $isStillOverdue => 'Record the physical return.',
                                            $currentRoleOwnsAction && $isHeadReview => 'Review the finalized late-return assessment.',
                                            $currentRoleOwnsAction && $isAwaitingPayment => 'Record the official Cashier receipt after payment.',
                                            $isOfficer && $isHeadReview => 'No action required at this stage. Waiting for SPMU Head/Admin review.',
                                            $isHead && $isStillOverdue => 'No action required at this stage. Waiting for the Action Officer to record the return.',
                                            $isHead && $isAwaitingPayment => 'No action required at this stage. Waiting for the borrower payment and Action Officer receipt recording.',
                                            default => 'No action required at this stage.',
                                        };
                                        $dialogId = 'late-return-action-'.$record->id;
                                        $remainingBilling = $linkedBilling
                                            ? max(0, (float) $linkedBilling->total_amount - (float) $linkedBilling->payments->where('status', 'VERIFIED')->sum('amount'))
                                            : null;
                                    @endphp

                                    <article class="ob-case-card accountability-custody-case">
                                        <div class="ob-case-top">
                                            <div class="ob-case-identity">
                                                <span class="ob-case-icon is-{{ $isStillOverdue ? 'danger' : 'warning' }}" aria-hidden="true"><x-icon name="clock" size="17" /></span>
                                                <div>
                                                    <span class="ob-case-type">Late Return</span>
                                                    <div class="accountability-case-title-row">
                                                        <h3>Late Return</h3>
                                                        <span class="accountability-case-status is-{{ $statusTone }}">{{ $statusLabel }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="accountability-case-summary">
                                            @if($isStillOverdue)
                                                <span><strong>Return:</strong> Awaiting physical return</span>
                                                <span><strong>Estimated Fee:</strong> {{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}</span>
                                            @else
                                                <span><strong>Late Days:</strong> {{ $assessment['late_days'] }}</span>
                                                <span><strong>{{ $assessment['is_estimate'] ? 'Estimated Fee' : 'Final Fee' }}:</strong> {{ $assessment['rate'] === null ? 'Not determined' : 'PHP '.number_format($assessment['amount'], 2) }}</span>
                                            @endif
                                        </div>

                                        <div class="accountability-case-responsibility">
                                            <div class="accountability-role-copy">
                                                <small>Responsible Now</small>
                                                <strong>{{ $responsibleNow }}</strong>
                                            </div>
                                            <div class="accountability-role-copy">
                                                <small>{{ $currentRoleOwnsAction ? 'Your Action' : 'Current Stage' }}</small>
                                                <span>{{ $roleMessage }}</span>
                                            </div>
                                            @if($currentRoleOwnsAction && $isStillOverdue)
                                                <a class="button primary small accountability-action-button" href="{{ route('custody.return.show', $record->custody) }}">Record Return</a>
                                            @elseif($currentRoleOwnsAction && $isHeadReview)
                                                <button type="button" class="button primary small accountability-action-button" data-accountability-dialog="{{ $dialogId }}">Review Assessment</button>
                                            @elseif($currentRoleOwnsAction && $isAwaitingPayment && $linkedBilling)
                                                <button type="button" class="button primary small accountability-action-button" data-accountability-dialog="{{ $dialogId }}">Record Receipt</button>
                                            @endif
                                        </div>
                                    </article>

                                    @if($currentRoleOwnsAction && $isHeadReview)
                                        <dialog class="accountability-action-dialog" id="{{ $dialogId }}">
                                            <header class="accountability-action-dialog__header">
                                                <div>
                                                    <small>{{ $record->custody?->custody_no }} · Late Return</small>
                                                    <h3>{{ ($assessment['rate'] === null || (float) $assessment['amount'] <= 0) ? 'Resolve Late Return' : 'Review Late Return Assessment' }}</h3>
                                                </div>
                                                <button type="button" class="accountability-dialog-close" data-dialog-close aria-label="Close">×</button>
                                            </header>
                                            <div class="accountability-action-dialog__body">
                                                @if($assessment['rate'] === null || (float) $assessment['amount'] <= 0)
                                                    <form method="post" action="{{ route('overdue.resolve-without-charge', $record) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">No billable late-return rate is available for this finalized case. Record the Head/Admin resolution remarks to close it without a charge.</p>
                                                        <label>Resolution Remarks
                                                            <textarea name="resolution_remarks" required maxlength="2000" placeholder="State the basis for resolving this late-return case without a charge."></textarea>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Resolve Without Charge</button>
                                                        </div>
                                                    </form>
                                                @else
                                                    <form method="post" action="{{ route('overdue.bill', $record) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Final assessment: {{ (int) $assessment['late_days'] }} late day(s), PHP {{ number_format((float) $assessment['amount'], 2) }}. Issue the Late Return Billing Statement only after review.</p>
                                                        <label>Assessment Basis
                                                            <textarea name="basis" required maxlength="2000" placeholder="State the basis for approving the finalized late-return assessment."></textarea>
                                                        </label>
                                                        <label>Payment Due Date <span class="muted">(optional)</span>
                                                            <input type="date" name="due_at" min="{{ now()->toDateString() }}">
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Issue Billing Statement</button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </div>
                                        </dialog>
                                    @elseif($currentRoleOwnsAction && $isAwaitingPayment && $linkedBilling)
                                        <dialog class="accountability-action-dialog" id="{{ $dialogId }}">
                                            <header class="accountability-action-dialog__header">
                                                <div>
                                                    <small>{{ $linkedBilling->billing_no }}</small>
                                                    <h3>Record Cashier Receipt</h3>
                                                </div>
                                                <button type="button" class="accountability-dialog-close" data-dialog-close aria-label="Close">×</button>
                                            </header>
                                            <div class="accountability-action-dialog__body">
                                                <form method="post" action="{{ route('payments.store', $linkedBilling) }}" enctype="multipart/form-data">
                                                    @csrf
                                                    <p class="accountability-dialog-note">Remaining balance: PHP {{ number_format((float) $remainingBilling, 2) }}. Record only the official CSPC Cashier receipt presented by the borrower.</p>
                                                    <div class="form-grid two">
                                                        <label>Cashier Receipt No.
                                                            <input name="official_receipt_no" required maxlength="255">
                                                        </label>
                                                        <label>Receipt Date
                                                            <input type="date" name="receipt_date" required max="{{ now()->toDateString() }}">
                                                        </label>
                                                    </div>
                                                    <label>Amount Paid
                                                        <input type="number" name="amount" min="0.01" step="0.01" max="{{ number_format((float) $remainingBilling, 2, '.', '') }}" required>
                                                    </label>
                                                    <label>Scanned Paid Receipt
                                                        <input type="file" name="evidence" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                                                    </label>
                                                    <label>Remarks <span class="muted">(optional)</span>
                                                        <textarea name="remarks" maxlength="1000"></textarea>
                                                    </label>
                                                    <div class="accountability-dialog-actions">
                                                        <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                        <button class="button primary small">Record Receipt</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </dialog>
                                    @endif
                                @else
                                    @php
                                        $incident = $record;
                                        $statusKey = strtoupper((string) $incident->status);
                                        $statusLabel = match ($statusKey) {
                                            'OPEN' => 'Awaiting Accountability Confirmation',
                                            'FOR_BILLING' => 'For Billing',
                                            'BILLING_PENDING' => 'Awaiting Payment',
                                            'COMPLIANCE_REQUIRED', 'COMPLIANCE_RSLDDP_PENDING' => 'Compliance Required',
                                            'RSLDDP_AWAITING_UPLOAD' => 'Accomplished RSLDDP Pending',
                                            'RSLDDP_DISPOSITION_PENDING' => 'Official Disposition Pending',
                                            'RSLDDP_COMPLIANCE_VERIFICATION' => \App\Support\AccountabilityDispositionLabels::subStatusLabel($incident),
                                            'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'Official Billing Pending',
                                            'RSLDDP_PAYMENT_REQUIRED' => 'Awaiting Payment',
                                            'RSLDDP_FOR_RESOLUTION' => 'For Final Resolution',
                                            default => \App\Support\StatusLabels::label($statusKey) ?? str($statusKey)->replace('_', ' ')->title()->toString(),
                                        };
                                        $linkedBilling = $billingForIncident($incident);
                                        $responsibleNow = match ($statusKey) {
                                            'OPEN',
                                            'FOR_BILLING',
                                            'RSLDDP_AWAITING_UPLOAD',
                                            'RSLDDP_DISPOSITION_PENDING',
                                            'RSLDDP_FOR_ACCOUNTING_PROCESSING',
                                            'RSLDDP_FOR_RESOLUTION' => 'SPMU Head/Admin',
                                            'BILLING_PENDING',
                                            'RSLDDP_PAYMENT_REQUIRED' => 'Borrower / SPMU Action Officer',
                                            'RSLDDP_COMPLIANCE_VERIFICATION' => $incident->official_disposition === 'OTHER' ? 'SPMU Head/Admin' : 'SPMU Action Officer',
                                            'COMPLIANCE_RSLDDP_PENDING' => 'SPMU Head/Admin',
                                            'COMPLIANCE_REQUIRED' => 'SPMU Action Officer',
                                            default => 'SPMU',
                                        };
                                        $headOwnedStatuses = ['OPEN', 'FOR_BILLING', 'RSLDDP_AWAITING_UPLOAD', 'RSLDDP_DISPOSITION_PENDING', 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_FOR_RESOLUTION', 'COMPLIANCE_RSLDDP_PENDING'];
                                        $officerOwnedStatuses = ['BILLING_PENDING', 'RSLDDP_PAYMENT_REQUIRED', 'COMPLIANCE_REQUIRED'];
                                        $roleOwnsAction = ($isHead && in_array($statusKey, $headOwnedStatuses, true))
                                            || ($isOfficer && in_array($statusKey, $officerOwnedStatuses, true))
                                            || ($isOfficer && $statusKey === 'RSLDDP_COMPLIANCE_VERIFICATION' && in_array($incident->official_disposition, ['REPAIR', 'REPLACEMENT', 'RETURN_RECOVERY', 'MONETARY_SETTLEMENT'], true));
                                        $actionLabel = match ($statusKey) {
                                            'OPEN' => 'Confirm / Clear',
                                            'FOR_BILLING' => 'Generate Billing',
                                            'BILLING_PENDING', 'RSLDDP_PAYMENT_REQUIRED' => 'Record Receipt',
                                            'RSLDDP_AWAITING_UPLOAD', 'COMPLIANCE_RSLDDP_PENDING' => 'Upload RSLDDP',
                                            'RSLDDP_DISPOSITION_PENDING' => 'Record Disposition',
                                            'RSLDDP_COMPLIANCE_VERIFICATION' => $incident->official_disposition === 'MONETARY_SETTLEMENT' ? 'Record Receipt' : 'Record Verification',
                                            'COMPLIANCE_REQUIRED' => 'Verify Compliance',
                                            'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'Record Billing Statement',
                                            'RSLDDP_FOR_RESOLUTION' => 'Resolve Case',
                                            default => 'Open Action',
                                        };
                                        $roleMessage = match (true) {
                                            $roleOwnsAction => match ($statusKey) {
                                                'OPEN' => 'Review the finding and record Confirm or Clear.',
                                                'FOR_BILLING' => 'Generate the required property Billing Statement.',
                                                'BILLING_PENDING', 'RSLDDP_PAYMENT_REQUIRED' => 'Record the official Cashier receipt after payment.',
                                                'RSLDDP_AWAITING_UPLOAD', 'COMPLIANCE_RSLDDP_PENDING' => 'Upload the accomplished RSLDDP received by SPMU.',
                                                'RSLDDP_DISPOSITION_PENDING' => 'Record the disposition stated in the accomplished RSLDDP.',
                                                'RSLDDP_COMPLIANCE_VERIFICATION' => $incident->official_disposition === 'MONETARY_SETTLEMENT' ? 'Record the official Cashier receipt after payment.' : 'Verify the required property compliance.',
                                                'COMPLIANCE_REQUIRED' => 'Verify the completed property compliance recorded for this legacy case.',
                                                'RSLDDP_FOR_ACCOUNTING_PROCESSING' => 'Record the official Accounting-issued Billing Statement.',
                                                'RSLDDP_FOR_RESOLUTION' => 'Perform the final accountability review and resolution.',
                                                default => 'Complete the current accountability action.',
                                            },
                                            $isOfficer && $responsibleNow === 'SPMU Head/Admin' => 'No action required at this stage. Waiting for SPMU Head/Admin.',
                                            $isHead && str_contains($responsibleNow, 'Action Officer') => 'No action required at this stage. Waiting for the SPMU Action Officer.',
                                            $isHead && str_contains($responsibleNow, 'Borrower') => 'No action required at this stage. Waiting for payment and Action Officer receipt recording.',
                                            default => 'No action required at this stage.',
                                        };
                                        $propertySummary = $incident->lines
                                            ->map(function ($line) use ($incident) {
                                                $custodyLine = $incident->custody?->lines?->firstWhere('id', $line->custody_line_id);
                                                $description = $custodyLine?->requestItem?->description_snapshot ?: 'Inventory item';
                                                $quantity = (float) $line->quantity;
                                                $quantityLabel = fmod($quantity, 1.0) === 0.0 ? (string) (int) $quantity : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
                                                return $description.' ×'.$quantityLabel;
                                            })
                                            ->filter()
                                            ->implode(', ');
                                        $dialogId = 'property-action-'.$incident->id;
                                        $remainingBilling = $linkedBilling
                                            ? max(0, (float) $linkedBilling->total_amount - (float) $linkedBilling->payments->where('status', 'VERIFIED')->sum('amount'))
                                            : null;
                                    @endphp

                                    <article class="ob-case-card accountability-custody-case">
                                        <div class="ob-case-top">
                                            <div class="ob-case-identity">
                                                <span class="ob-case-icon is-warning" aria-hidden="true"><x-icon name="warning" size="17" /></span>
                                                <div>
                                                    <span class="ob-case-type">Property Accountability</span>
                                                    <div class="accountability-case-title-row">
                                                        <h3>{{ str($incident->incident_type)->replace('_', ' ')->title() }}</h3>
                                                        <span class="accountability-case-status is-warning">{{ $statusLabel }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="accountability-case-summary">
                                            <span><strong>Item:</strong> {{ $propertySummary ?: 'See recorded finding' }}</span>
                                            @if($incident->official_disposition)
                                                <span><strong>Disposition:</strong> {{ \App\Support\AccountabilityDispositionLabels::officialDispositionLabel($incident->official_disposition) }}</span>
                                            @endif
                                        </div>

                                        <div class="accountability-case-responsibility">
                                            <div class="accountability-role-copy">
                                                <small>Responsible Now</small>
                                                <strong>{{ $responsibleNow }}</strong>
                                            </div>
                                            <div class="accountability-role-copy">
                                                <small>{{ $roleOwnsAction ? 'Your Action' : 'Current Stage' }}</small>
                                                <span>{{ $roleMessage }}</span>
                                            </div>
                                            @if($roleOwnsAction)
                                                @if(in_array($statusKey, ['BILLING_PENDING', 'RSLDDP_PAYMENT_REQUIRED'], true) && ! $linkedBilling)
                                                    <span class="muted">Billing record pending</span>
                                                @elseif($statusKey === 'RSLDDP_COMPLIANCE_VERIFICATION' && $incident->official_disposition === 'MONETARY_SETTLEMENT' && ! $linkedBilling)
                                                    <span class="muted">Billing record pending</span>
                                                @else
                                                    <button type="button" class="button primary small accountability-action-button" data-accountability-dialog="{{ $dialogId }}">{{ $actionLabel }}</button>
                                                @endif
                                            @endif
                                        </div>
                                    </article>

                                    @if($roleOwnsAction)
                                        <dialog class="accountability-action-dialog" id="{{ $dialogId }}">
                                            <header class="accountability-action-dialog__header">
                                                <div>
                                                    <small>{{ $incident->custody?->custody_no }} · {{ $incident->incident_no }}</small>
                                                    <h3>{{ $actionLabel }}</h3>
                                                </div>
                                                <button type="button" class="accountability-dialog-close" data-dialog-close aria-label="Close">×</button>
                                            </header>
                                            <div class="accountability-action-dialog__body">
                                                @if($statusKey === 'OPEN')
                                                    <form method="post" action="{{ route('incidents.resolve', $incident) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Confirm only when the recorded finding establishes borrower accountability. Clear closes this property finding without creating an offense from this case.</p>
                                                        <label>Decision
                                                            <select name="decision" required>
                                                                <option value="">Select decision</option>
                                                                <option value="CONFIRM">Confirm Accountability</option>
                                                                <option value="CLEAR">Clear Finding</option>
                                                            </select>
                                                        </label>
                                                        <label>Decision Remarks
                                                            <textarea name="resolution_remarks" required maxlength="2000" placeholder="State the basis for the decision."></textarea>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Save Decision</button>
                                                        </div>
                                                    </form>
                                                @elseif(in_array($statusKey, ['RSLDDP_AWAITING_UPLOAD', 'COMPLIANCE_RSLDDP_PENDING'], true))
                                                    <form method="post" action="{{ route('incidents.rslddp.upload', $incident) }}" enctype="multipart/form-data">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Upload the accomplished RSLDDP received by SPMU. The borrower is not the uploader of record.</p>
                                                        <label>Accomplished RSLDDP
                                                            <input type="file" name="evidence" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Upload RSLDDP</button>
                                                        </div>
                                                    </form>
                                                @elseif($statusKey === 'RSLDDP_DISPOSITION_PENDING')
                                                    <form method="post" action="{{ route('incidents.disposition.record', $incident) }}" data-disposition-form>
                                                        @csrf
                                                        <p class="accountability-dialog-note">Record only the disposition stated in the accomplished RSLDDP. Do not choose a settlement outcome on the borrower's behalf.</p>
                                                        <label>Official Disposition
                                                            <select name="official_disposition" required data-disposition-select>
                                                                <option value="">Select disposition</option>
                                                                <option value="MONETARY_SETTLEMENT">Monetary Settlement</option>
                                                                <option value="REPAIR">Repair</option>
                                                                <option value="REPLACEMENT">Replacement</option>
                                                                <option value="RETURN_RECOVERY">Return / Recovery</option>
                                                                <option value="OTHER">Other</option>
                                                            </select>
                                                        </label>
                                                        <label data-disposition-amount hidden>Settlement Amount
                                                            <input type="number" name="official_disposition_amount" min="0.01" step="0.01">
                                                        </label>
                                                        <label data-disposition-details hidden>Other Disposition Details
                                                            <textarea name="official_disposition_details" maxlength="2000"></textarea>
                                                        </label>
                                                        <label>Recording Remarks
                                                            <textarea name="resolution_remarks" required maxlength="2000" placeholder="State where the recorded disposition appears in the accomplished RSLDDP or related official record."></textarea>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Save Disposition</button>
                                                        </div>
                                                    </form>
                                                @elseif($statusKey === 'COMPLIANCE_REQUIRED')
                                                    <form method="post" action="{{ route('incidents.resolve', $incident) }}">
                                                        @csrf
                                                        <input type="hidden" name="resolution_outcome" value="COMPLIANCE_COMPLETED">
                                                        <p class="accountability-dialog-note">Legacy case: verify the physical compliance already required by the Head/Admin. This verification does not make a new accountability decision.</p>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Verify Compliance</button>
                                                        </div>
                                                    </form>
                                                @elseif($statusKey === 'RSLDDP_COMPLIANCE_VERIFICATION' && in_array($incident->official_disposition, ['REPAIR', 'REPLACEMENT', 'RETURN_RECOVERY'], true))
                                                    <form method="post" action="{{ route('incidents.disposition.verify', $incident) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Verify the actual {{ strtolower(\App\Support\AccountabilityDispositionLabels::officialDispositionLabel($incident->official_disposition)) }} against the recorded official disposition.</p>
                                                        <label>Verification Decision
                                                            <select name="decision" required>
                                                                <option value="">Select result</option>
                                                                <option value="ACCEPTED">Accepted</option>
                                                                <option value="NOT_ACCEPTED">Not Accepted</option>
                                                            </select>
                                                        </label>
                                                        <label>Remarks <span class="muted">(required if not accepted)</span>
                                                            <textarea name="remarks" maxlength="2000"></textarea>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Record Verification</button>
                                                        </div>
                                                    </form>
                                                @elseif(in_array($statusKey, ['BILLING_PENDING', 'RSLDDP_PAYMENT_REQUIRED'], true) || ($statusKey === 'RSLDDP_COMPLIANCE_VERIFICATION' && $incident->official_disposition === 'MONETARY_SETTLEMENT'))
                                                    @if($linkedBilling)
                                                        <form method="post" action="{{ route('payments.store', $linkedBilling) }}" enctype="multipart/form-data">
                                                            @csrf
                                                            <p class="accountability-dialog-note">Remaining balance: PHP {{ number_format((float) $remainingBilling, 2) }}. Record only the official CSPC Cashier receipt presented by the borrower.</p>
                                                            <div class="form-grid two">
                                                                <label>Cashier Receipt No.
                                                                    <input name="official_receipt_no" required maxlength="255">
                                                                </label>
                                                                <label>Receipt Date
                                                                    <input type="date" name="receipt_date" required max="{{ now()->toDateString() }}">
                                                                </label>
                                                            </div>
                                                            <label>Amount Paid
                                                                <input type="number" name="amount" min="0.01" step="0.01" max="{{ number_format((float) $remainingBilling, 2, '.', '') }}" required>
                                                            </label>
                                                            <label>Scanned Paid Receipt
                                                                <input type="file" name="evidence" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                                                            </label>
                                                            <label>Remarks <span class="muted">(optional)</span>
                                                                <textarea name="remarks" maxlength="1000"></textarea>
                                                            </label>
                                                            <div class="accountability-dialog-actions">
                                                                <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                                <button class="button primary small">Record Receipt</button>
                                                            </div>
                                                        </form>
                                                    @endif
                                                @elseif($statusKey === 'RSLDDP_FOR_ACCOUNTING_PROCESSING')
                                                    <form method="post" action="{{ route('incidents.rslddp.billing', $incident) }}" enctype="multipart/form-data">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Record the official Billing Statement issued by Accounting. This system does not generate that external document.</p>
                                                        <label>Official Billing Statement
                                                            <input type="file" name="evidence" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                                                        </label>
                                                        <div class="form-grid two">
                                                            <label>Amount
                                                                <input type="number" name="amount" min="0.01" step="0.01" required>
                                                            </label>
                                                            <label>Accounting Reference No.
                                                                <input name="billing_reference" required maxlength="255">
                                                            </label>
                                                        </div>
                                                        <label>Payment Due Date <span class="muted">(optional)</span>
                                                            <input type="date" name="due_at" min="{{ now()->toDateString() }}">
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Record Billing Statement</button>
                                                        </div>
                                                    </form>
                                                @elseif($statusKey === 'RSLDDP_FOR_RESOLUTION')
                                                    <form method="post" action="{{ route('incidents.rslddp.resolve', $incident) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Final resolution is available only after the required RSLDDP/disposition compliance has reached this stage.</p>
                                                        <label>Final Resolution Remarks
                                                            <textarea name="resolution_remarks" required maxlength="2000" placeholder="Record the basis for final case resolution."></textarea>
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Resolve Case</button>
                                                        </div>
                                                    </form>
                                                @elseif($statusKey === 'FOR_BILLING')
                                                    <form method="post" action="{{ route('incidents.bill', $incident) }}">
                                                        @csrf
                                                        <p class="accountability-dialog-note">Legacy property billing stage. Enter only the approved billing details for this existing case.</p>
                                                        <label>Amount
                                                            <input type="number" name="amount" min="0.01" step="0.01" required>
                                                        </label>
                                                        <label>Billing Basis
                                                            <textarea name="basis" required maxlength="2000"></textarea>
                                                        </label>
                                                        <label>Payment Due Date <span class="muted">(optional)</span>
                                                            <input type="date" name="due_at" min="{{ now()->toDateString() }}">
                                                        </label>
                                                        <div class="accountability-dialog-actions">
                                                            <button type="button" class="button secondary small" data-dialog-close>Cancel</button>
                                                            <button class="button primary small">Generate Billing</button>
                                                        </div>
                                                    </form>
                                                @endif
                                            </div>
                                        </dialog>
                                    @endif
                                @endif
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@elseif($activeTab === 'documents')
    <section role="tabpanel" aria-label="Documents" class="card accountability-document-panel">
        @if($documents->isEmpty())
            <div class="empty-state">
                <strong>No documents yet.</strong>
                <span>No accountability document has been generated for this borrower.</span>
            </div>
        @else
            <div class="accountability-document-list">
                @foreach($documents as $document)
                    <article class="accountability-document-row">
                        <span class="dashboard-activity-icon" aria-hidden="true"><x-icon name="report-document" size="16" /></span>
                        <div class="accountability-document-copy">
                            <strong>{{ str($document->document_type)->replace('_',' ')->title() }}</strong>
                            <span>{{ $document->document_no }}</span>
                            <small>{{ optional($document->generated_at)->format('d M Y, g:i A') }} · {{ str($document->status)->title() }}</small>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('documents.preview', $document) }}"><span>Preview</span></a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@else
    <section role="tabpanel" aria-label="History">
        @include('accountability.partials.resolved-history', [
            'resolvedHistory' => $resolvedHistory,
            'isBorrower' => false,
            'embeddedInBorrowerWorkspace' => true,
        ])
    </section>
@endif
</div>

<script>
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-accountability-dialog]');
    if (opener) {
        const dialog = document.getElementById(opener.dataset.accountabilityDialog);
        if (dialog?.showModal) dialog.showModal();
        return;
    }

    const closer = event.target.closest('[data-dialog-close]');
    if (closer) {
        closer.closest('dialog')?.close();
    }
});

document.querySelectorAll('.accountability-action-dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
});

document.querySelectorAll('[data-disposition-form]').forEach((form) => {
    const select = form.querySelector('[data-disposition-select]');
    const amount = form.querySelector('[data-disposition-amount]');
    const details = form.querySelector('[data-disposition-details]');
    if (!select) return;

    const sync = () => {
        const monetary = select.value === 'MONETARY_SETTLEMENT';
        const other = select.value === 'OTHER';
        if (amount) {
            amount.hidden = !monetary;
            const input = amount.querySelector('input');
            if (input) input.required = monetary;
        }
        if (details) {
            details.hidden = !other;
            const textarea = details.querySelector('textarea');
            if (textarea) textarea.required = other;
        }
    };

    select.addEventListener('change', sync);
    sync();
});
</script>

@endsection
