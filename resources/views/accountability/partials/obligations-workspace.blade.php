@php
    /*
    |--------------------------------------------------------------------------
    | Borrower - My Obligations
    |--------------------------------------------------------------------------
    |
    | Borrower-facing presentation groups related technical records into one
    | obligation. Example:
    |
    | Property Incident + Billing Statement + Borrowing Restriction
    | = ONE borrower obligation.
    |
    | The underlying records remain separate in the database and continue to
    | be available to SPMU/Admin for audit, enforcement, billing, and reports.
    |
    */

    $obligationRows = [];
    $claimedBillingIds = collect();
    $claimedRestrictionIds = collect();

    $activeDocument = static function ($documents, string $type) {
        return $documents
            ?->where('document_type', $type)
            ->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
            ->sortByDesc('generated_at')
            ->first();
    };

    $billingDocument = static function ($billing) {
        return $billing?->documents
            ?->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED'])
            ->sortByDesc('generated_at')
            ->first();
    };

    /*
    |--------------------------------------------------------------------------
    | Property accountability
    |--------------------------------------------------------------------------
    */
    foreach ($openIncidents as $incident) {
        $custody = $incident->custody;
        $recordDate = $incident->reported_at ?: $incident->created_at;
        $incidentType = (string) str($incident->incident_type)->replace('_', ' ')->title();
        $itemName = $custody?->lines?->first()?->requestItem?->description_snapshot
            ?: $incidentType.' property';

        $linkedBilling = $openBillings->first(
            fn ($billing) => $billing->lines->contains(
                fn ($line) => (int) $line->incident_id === (int) $incident->id
            )
        );

        if ($linkedBilling) {
            $claimedBillingIds->push((int) $linkedBilling->id);
        }

        $linkedRestriction = $activeRestrictions->first(function ($restriction) use ($incident, $linkedBilling) {
            return (int) ($restriction->incident_id ?? 0) === (int) $incident->id
                || ($linkedBilling
                    && (int) ($restriction->billing_statement_id ?? 0) === (int) $linkedBilling->id);
        });

        if ($linkedRestriction) {
            $claimedRestrictionIds->push((int) $linkedRestriction->id);
        }

        $document = $billingDocument($linkedBilling);
        $complianceDocument = $activeDocument($incident->documents, 'ACCOUNTABILITY_COMPLIANCE_NOTICE');

        $statusLabel = 'Under Review';
        $statusTone = 'neutral';
        $statusMeta = null;
        $nextAction = 'Wait for the SPMU decision.';
        $nextTone = 'info';

        if ($incident->status === 'COMPLIANCE_REQUIRED') {
            $statusLabel = 'Compliance Required';
            $statusTone = 'warning';
            $nextAction = 'Complete the required repair, replacement, or compliance with SPMU.';
            $nextTone = 'warning';
        } elseif ($linkedBilling) {
            if ($linkedBilling->status === 'RECEIPT_SUBMITTED') {
                $statusLabel = 'Payment Verification';
                $statusTone = 'info';
                $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
                $nextAction = 'SPMU is verifying the CSPC Cashier receipt.';
            } else {
                $statusLabel = 'Payment Required';
                $statusTone = 'warning';
                $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
                $nextAction = 'Pay the Billing Statement through the CSPC Cashier.';
                $nextTone = 'warning';
            }
        } elseif (in_array($incident->status, ['FOR_BILLING', 'BILLING_PENDING'], true)) {
            $statusLabel = 'Billing Statement Pending';
            $statusTone = 'warning';
            $nextAction = 'Wait for the SPMU Head/Admin to issue the Billing Statement.';
            $nextTone = 'warning';
        }

        $actions = [];

        if ($document) {
            $actions[] = ['View Billing Statement', route('documents.view', $document), true, 'primary'];
            $actions[] = ['Download', route('documents.download', $document), false, 'secondary'];
        }

        if ($complianceDocument) {
            $actions[] = ['View Compliance Notice', route('documents.view', $complianceDocument), true, 'primary'];
        }

        if ($custody) {
            $actions[] = ['View Borrowing', route('custody.show', $custody), false, 'secondary'];
        }

        $facts = [
            ['Finding', $incidentType],
            ['Case reference', $incident->incident_no],
            ['Custody', $custody?->custody_no ?: '—'],
        ];

        if ($linkedBilling) {
            $facts[] = ['Billing Statement', $linkedBilling->billing_no];
            $facts[] = ['Amount', '₱'.number_format((float) $linkedBilling->total_amount, 2)];
            $facts[] = ['Payment due', optional($linkedBilling->due_at)->format('d M Y') ?: 'Not specified'];
        }

        if ($linkedRestriction) {
            $facts[] = ['Borrowing status', 'Restricted until this obligation is resolved'];
        }

        $obligationRows[] = [
            'category' => 'property',
            'status' => $linkedBilling?->status ?: $incident->status,
            'date' => $linkedBilling?->issued_at ?: $recordDate,
            'tone' => 'warning',
            'icon' => 'accountability',
            'type' => 'Property Accountability',
            'title' => $itemName.' — '.$incidentType,
            'reference' => $incident->incident_no,
            'summary' => $linkedBilling
                ? 'The property case and its Billing Statement are shown together here.'
                : ($incident->status === 'COMPLIANCE_REQUIRED'
                    ? 'SPMU requires property compliance before this obligation can be cleared.'
                    : 'This property finding is still being processed by SPMU.'),
            'badge' => $statusLabel,
            'badge_tone' => $statusTone,
            'status_meta' => $statusMeta,
            'restricted' => (bool) $linkedRestriction,
            'next_action' => $nextAction,
            'next_tone' => $nextTone,
            'facts' => $facts,
            'actions' => $actions,
            'search' => strtolower(implode(' ', [
                'property accountability',
                $itemName,
                $incidentType,
                $incident->incident_no,
                $incident->status,
                $custody?->custody_no,
                $linkedBilling?->billing_no,
                $linkedBilling?->status,
            ])),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Late return / overdue
    |--------------------------------------------------------------------------
    */
    foreach ($openOverdueCases as $overdue) {
        $custody = $overdue->custody;
        $recordDate = $overdue->overdue_started_at ?: $overdue->created_at;
        $dueAt = $custody?->due_at;

        $penaltyIds = $overdue->penalties
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $linkedBilling = $openBillings->first(
            fn ($billing) => $billing->lines->contains(
                fn ($line) => $line->penalty
                    && (int) ($line->penalty->overdue_case_id ?? 0) === (int) $overdue->id
            )
        );

        if ($linkedBilling) {
            $claimedBillingIds->push((int) $linkedBilling->id);
        }

        $linkedRestriction = $activeRestrictions->first(function ($restriction) use ($penaltyIds, $linkedBilling) {
            return ($restriction->penalty_id && $penaltyIds->contains((int) $restriction->penalty_id))
                || ($linkedBilling
                    && (int) ($restriction->billing_statement_id ?? 0) === (int) $linkedBilling->id);
        });

        if ($linkedRestriction) {
            $claimedRestrictionIds->push((int) $linkedRestriction->id);
        }

        $lines = $custody?->lines ?? collect();
        $firstLine = $lines->first();
        $itemName = $firstLine?->requestItem?->description_snapshot ?: 'Borrowed items';

        $actualReturnAt = $custody?->returns
            ?->pluck('received_at')
            ->filter()
            ->sort()
            ->last();

        $dueDay = $dueAt?->copy()->startOfDay();
        $lateThrough = $actualReturnAt
            ? $actualReturnAt->copy()->startOfDay()
            : now()->startOfDay();

        $daysLate = $dueDay && $lateThrough->gt($dueDay)
            ? (int) $dueDay->diffInDays($lateThrough)
            : 0;

        $document = $billingDocument($linkedBilling);

        $isPhysicallyOutstanding = $overdue->status === 'OVERDUE';
        $statusLabel = $isPhysicallyOutstanding ? 'Return Required' : 'Late Return Processing';
        $statusTone = $isPhysicallyOutstanding ? 'danger' : 'warning';
        $statusMeta = $daysLate > 0
            ? $daysLate.' '.($daysLate === 1 ? 'day' : 'days').' late'
            : null;
        $nextAction = $isPhysicallyOutstanding
            ? 'Return the outstanding items to SPMU.'
            : 'Wait for the final late-return assessment.';
        $nextTone = $isPhysicallyOutstanding ? 'danger' : 'info';

        if ($linkedBilling) {
            if ($linkedBilling->status === 'RECEIPT_SUBMITTED') {
                $statusLabel = 'Payment Verification';
                $statusTone = 'info';
                $nextAction = 'SPMU is verifying the CSPC Cashier receipt.';
            } else {
                $statusLabel = 'Payment Required';
                $statusTone = 'warning';
                $nextAction = 'Pay the late-return Billing Statement through the CSPC Cashier.';
                $nextTone = 'warning';
            }

            $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
        }

        $actions = [];

        if ($document) {
            $actions[] = ['View Billing Statement', route('documents.view', $document), true, 'primary'];
            $actions[] = ['Download', route('documents.download', $document), false, 'secondary'];
        }

        if ($custody) {
            $actions[] = ['View Borrowing', route('custody.show', $custody), false, 'secondary'];
        }

        $facts = [
            ['Expected return', optional($dueAt)->format('d M Y') ?: '—'],
            ['Actual return', $actualReturnAt?->format('d M Y') ?: 'Not yet returned'],
            ['Late days', (string) $daysLate],
            ['Custody', $custody?->custody_no ?: '—'],
        ];

        if ($linkedBilling) {
            $facts[] = ['Billing Statement', $linkedBilling->billing_no];
            $facts[] = ['Amount', '₱'.number_format((float) $linkedBilling->total_amount, 2)];
        }

        if ($linkedRestriction) {
            $facts[] = ['Borrowing status', 'Restricted until this obligation is resolved'];
        }

        $obligationRows[] = [
            'category' => 'overdue',
            'status' => $linkedBilling?->status ?: $overdue->status,
            'date' => $linkedBilling?->issued_at ?: $recordDate,
            'tone' => $isPhysicallyOutstanding ? 'danger' : 'warning',
            'icon' => 'calendar',
            'type' => $isPhysicallyOutstanding ? 'Overdue Return' : 'Late Return',
            'title' => $itemName,
            'reference' => $custody?->custody_no ?: ($custody?->request?->request_no ?: 'Late return record'),
            'summary' => $linkedBilling
                ? 'The late-return case and its Billing Statement are shown together here.'
                : ($isPhysicallyOutstanding
                    ? 'The item is still physically outstanding.'
                    : 'The physical return is complete and the late-return assessment is being processed.'),
            'badge' => $statusLabel,
            'badge_tone' => $statusTone,
            'status_meta' => $statusMeta,
            'restricted' => (bool) $linkedRestriction,
            'next_action' => $nextAction,
            'next_tone' => $nextTone,
            'facts' => $facts,
            'actions' => $actions,
            'search' => strtolower(implode(' ', [
                'overdue late return',
                $itemName,
                $custody?->custody_no,
                $custody?->request?->request_no,
                $overdue->status,
                $linkedBilling?->billing_no,
                $linkedBilling?->status,
            ])),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Standalone billings
    |--------------------------------------------------------------------------
    |
    | A billing already grouped under a Property/Late Return case is suppressed
    | here. Only a billing with no visible parent obligation gets its own row.
    |
    */
    foreach ($openBillings as $billing) {
        if ($claimedBillingIds->contains((int) $billing->id)) {
            continue;
        }

        $recordDate = $billing->issued_at ?: $billing->created_at;
        $latestPayment = $billing->payments
            ->sortByDesc(fn ($payment) => $payment->submitted_at ?: $payment->created_at)
            ->first();
        $document = $billingDocument($billing);

        $linkedRestriction = $activeRestrictions->first(
            fn ($restriction) => (int) ($restriction->billing_statement_id ?? 0) === (int) $billing->id
        );

        if ($linkedRestriction) {
            $claimedRestrictionIds->push((int) $linkedRestriction->id);
        }

        $statusLabel = $billing->status === 'RECEIPT_SUBMITTED'
            ? 'Payment Verification'
            : 'Payment Required';

        $nextAction = $billing->status === 'RECEIPT_SUBMITTED'
            ? 'SPMU is verifying the CSPC Cashier receipt.'
            : 'Pay the Billing Statement through the CSPC Cashier.';

        if ($latestPayment?->status === 'REJECTED') {
            $statusLabel = 'Receipt Correction Required';
            $nextAction = 'Present the correct CSPC Cashier Official Receipt to SPMU.';
        }

        $actions = [];
        if ($document) {
            $actions[] = ['View Billing Statement', route('documents.view', $document), true, 'primary'];
            $actions[] = ['Download', route('documents.download', $document), false, 'secondary'];
        }

        $obligationRows[] = [
            'category' => 'billing',
            'status' => $billing->status,
            'date' => $recordDate,
            'tone' => 'info',
            'icon' => 'requests',
            'type' => 'Financial Obligation',
            'title' => $billing->lines->first()?->description ?: 'Billing Statement',
            'reference' => $billing->billing_no,
            'summary' => 'An open SPMU Billing Statement requires settlement or verification.',
            'badge' => $statusLabel,
            'badge_tone' => $latestPayment?->status === 'REJECTED' ? 'danger' : 'info',
            'status_meta' => '₱'.number_format((float) $billing->total_amount, 2),
            'restricted' => (bool) $linkedRestriction,
            'next_action' => $nextAction,
            'next_tone' => $latestPayment?->status === 'REJECTED' ? 'danger' : 'warning',
            'facts' => [
                ['Billing Statement', $billing->billing_no],
                ['Amount', '₱'.number_format((float) $billing->total_amount, 2)],
                ['Payment due', optional($billing->due_at)->format('d M Y') ?: 'Not specified'],
                ['Borrowing status', $linkedRestriction ? 'Restricted until resolved' : 'No linked restriction'],
            ],
            'actions' => $actions,
            'search' => strtolower(implode(' ', [
                'financial obligation billing',
                $billing->billing_no,
                $billing->status,
                $billing->total_amount,
                $billing->lines->pluck('description')->join(' '),
            ])),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Standalone restrictions
    |--------------------------------------------------------------------------
    |
    | Restrictions linked to a case/billing above are intentionally NOT counted
    | as another obligation. A standalone restriction (for example, a borrowing
    | suspension sanction) remains visible on its own.
    |
    */
    foreach ($activeRestrictions as $restriction) {
        if ($claimedRestrictionIds->contains((int) $restriction->id)) {
            continue;
        }

        $recordDate = $restriction->effective_from ?: $restriction->created_at;
        $restrictionType = (string) str($restriction->restriction_type)->replace('_', ' ')->title();

        $obligationRows[] = [
            'category' => 'restriction',
            'status' => $restriction->status,
            'date' => $recordDate,
            'tone' => 'orange',
            'icon' => 'lock',
            'type' => 'Borrowing Restriction',
            'title' => $restriction->sanction_id
                ? 'Administrative borrowing restriction'
                : 'Borrowing temporarily restricted',
            'reference' => $restriction->sanction_id
                ? 'Administrative sanction'
                : 'Restriction record',
            'summary' => $restriction->reason ?: $restrictionType,
            'badge' => $restriction->effective_to ? 'In Effect' : 'Restricted',
            'badge_tone' => 'warning',
            'status_meta' => $restriction->effective_to
                ? 'Until '.$restriction->effective_to->format('d M Y')
                : 'Until resolved',
            'restricted' => true,
            'next_action' => $restriction->effective_to
                ? 'Wait until the configured restriction period ends.'
                : 'Resolve the linked requirement with SPMU.',
            'next_tone' => 'warning',
            'facts' => [
                ['Restriction type', $restrictionType],
                ['Effective from', optional($restriction->effective_from)->format('d M Y') ?: '—'],
                ['Effective until', optional($restriction->effective_to)->format('d M Y') ?: 'Until resolved'],
            ],
            'actions' => [],
            'search' => strtolower(implode(' ', [
                'borrowing restriction',
                $restrictionType,
                $restriction->status,
                $restriction->reason,
            ])),
        ];
    }

    $obligationRows = collect($obligationRows)
        ->sortByDesc(fn ($row) => optional($row['date'])->timestamp ?? 0)
        ->values()
        ->all();

    $obligationCount = count($obligationRows);

    /*
     * These four cards are record-type summaries, not four separate borrower
     * tasks. They remain useful because they explain what records currently
     * exist behind the grouped obligation(s).
     */
    $billingTotal = (float) $openBillings->sum('total_amount');

    $summaryCards = [
        ['danger', 'calendar', $openOverdueCases->count(), 'Overdue Returns', $openOverdueCases->count() ? 'Return-related records' : 'No open records'],
        ['warning', 'accountability', $openIncidents->count(), 'Property Cases', $openIncidents->count() ? 'Property accountability' : 'No open records'],
        ['info', 'requests', $openBillings->count(), 'Open Billings', $openBillings->count() ? '₱'.number_format($billingTotal, 2).' outstanding' : 'No open records'],
        ['orange', 'lock', $activeRestrictions->count(), 'Active Restrictions', $activeRestrictions->count() ? 'Borrowing access affected' : 'No restrictions'],
    ];
@endphp

@include('accountability.partials.obligations-styles')

<section class="content-area ob-workspace" data-borrower-accountability-clean>
    <div class="ob-section-heading">
        <div>
            <span>Record summary</span>
            <p>Related records can belong to the same obligation.</p>
        </div>
    </div>

    <div class="ob-summary" aria-label="Accountability record summary">
        @foreach($summaryCards as [$tone, $icon, $value, $label, $note])
            <article class="ob-summary-card is-{{ $tone }} {{ $value === 0 ? 'is-empty' : '' }}">
                <span class="ob-summary-icon" aria-hidden="true">
                    <x-icon :name="$icon" size="20" />
                </span>
                <span class="ob-summary-copy">
                    <strong class="ob-summary-value">{{ $value }}</strong>
                    <span class="ob-summary-label">{{ $label }}</span>
                    <span class="ob-summary-note">{{ $note }}</span>
                </span>
            </article>
        @endforeach
    </div>

    <div class="ob-current-header">
        <div>
            <span>Current obligations</span>
            <h2>{{ $obligationCount }} {{ $obligationCount === 1 ? 'unresolved obligation' : 'unresolved obligations' }}</h2>
        </div>

        @if($obligationCount > 1)
            <label class="ob-search">
                <x-icon name="search" size="17" />
                <input
                    type="search"
                    placeholder="Search obligations"
                    autocomplete="off"
                    data-obligation-clean-search
                >
            </label>
        @endif
    </div>

    @if($obligationCount > 0)
        <div class="ob-case-list" data-obligation-clean-list>
            @foreach($obligationRows as $index => $row)
                <article
                    class="ob-case-card"
                    data-obligation-clean-row
                    data-search="{{ $row['search'] }}"
                >
                    <div class="ob-case-top">
                        <div class="ob-case-identity">
                            <span class="ob-case-icon is-{{ $row['tone'] }}" aria-hidden="true">
                                <x-icon :name="$row['icon']" size="20" />
                            </span>

                            <div>
                                <span class="ob-case-type">{{ $row['type'] }}</span>
                                <h3>{{ $row['title'] }}</h3>
                                <small>{{ $row['reference'] }}</small>
                            </div>
                        </div>

                        <div class="ob-case-state">
                            <span class="ob-badge is-{{ $row['badge_tone'] }}">{{ $row['badge'] }}</span>
                            @if($row['status_meta'])
                                <strong>{{ $row['status_meta'] }}</strong>
                            @endif
                        </div>
                    </div>

                    <p class="ob-case-summary">{{ $row['summary'] }}</p>

                    @if($row['restricted'])
                        <div class="ob-linked-restriction">
                            <x-icon name="lock" size="15" />
                            <span>Borrowing is temporarily restricted until this obligation is resolved.</span>
                        </div>
                    @endif

                    <div class="ob-next-action is-{{ $row['next_tone'] }}">
                        <span>Next action</span>
                        <strong>{{ $row['next_action'] }}</strong>
                    </div>

                    <div class="ob-case-actions">
                        @foreach($row['actions'] as [$label, $url, $newTab, $buttonTone])
                            <a
                                class="button {{ $buttonTone === 'primary' ? 'primary' : 'secondary' }} small ui-pressable"
                                href="{{ $url }}"
                                @if($newTab) target="_blank" rel="noopener" @endif
                            >
                                {{ $label }}
                            </a>
                        @endforeach

                        <details class="ob-details">
                            <summary>Details</summary>
                            <div class="ob-detail-grid">
                                @foreach($row['facts'] as [$factLabel, $factValue])
                                    <div>
                                        <small>{{ $factLabel }}</small>
                                        <strong>{{ $factValue }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="ob-no-match" data-obligation-clean-empty hidden>
            No obligations match your search.
        </div>
    @else
        <article class="ob-clear-card">
            <span aria-hidden="true"><x-icon name="check-circle" size="34" /></span>
            <div>
                <strong>No unresolved obligations</strong>
                <p>You have no outstanding return, property, billing, or borrowing restriction requiring action.</p>
            </div>
        </article>
    @endif
</section>

<script>
(() => {
    const workspace = document.querySelector('[data-borrower-accountability-clean]');
    if (!workspace) return;

    const input = workspace.querySelector('[data-obligation-clean-search]');
    const rows = Array.from(workspace.querySelectorAll('[data-obligation-clean-row]'));
    const empty = workspace.querySelector('[data-obligation-clean-empty]');

    if (!input || rows.length === 0) return;

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const matches = !query || (row.dataset.search || '').includes(query);
            row.hidden = !matches;
            if (matches) visible += 1;
        });

        if (empty) empty.hidden = visible !== 0;
    });
})();
</script>
