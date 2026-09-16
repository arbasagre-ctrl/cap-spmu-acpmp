<?php

namespace App\Services;

use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\Incident;
use App\Models\OverdueCase;
use Illuminate\Support\Collection;

/**
 * Builds the borrower-facing accountability picture: one grouped obligation
 * per underlying accountability matter (Property Incident, Late Return, or a
 * standalone Billing/Restriction), each carrying an explicit action_state so
 * "needs my action" vs "under SPMU processing" is never guessed from text.
 *
 * This is the single source of truth for borrower obligation grouping so the
 * borrower dashboard and My Obligations never disagree on the same count.
 */
class BorrowerObligationService
{
    public const ACTION_BORROWER = 'BORROWER_ACTION';

    public const ACTION_PROCESSING = 'SPMU_PROCESSING';

    /**
     * Raw accountability records for one borrower, with the same eager loads
     * AccountabilityController::index() uses for the borrower workspace.
     *
     * @return array{incidents: Collection, billings: Collection, restrictions: Collection, overdueCases: Collection}
     */
    public function recordsForBorrower(int $borrowerUserId): array
    {
        $incidents = Incident::with(['borrower', 'evidenceFile', 'custody.request', 'custody.lines.requestItem.inventoryItem', 'lines.custodyLine.requestItem.inventoryItem', 'documents'])
            ->where('borrower_user_id', $borrowerUserId)
            ->latest('reported_at')
            ->get();

        $billings = BillingStatement::with(['borrower', 'lines.penalty', 'payments.verifiedBy', 'documents'])
            ->where('borrower_user_id', $borrowerUserId)
            ->latest('issued_at')
            ->get();

        $restrictions = BorrowerRestriction::query()
            ->with('custody')
            ->where('borrower_user_id', $borrowerUserId)
            ->latest('effective_from')
            ->get();

        $overdueCases = OverdueCase::with([
            'borrower',
            'custody.lines.requestItem.inventoryItem',
            'custody.request',
            'custody.returns',
            'custody.laundryJob',
            'penalties',
            'confirmedBy',
            'documents',
        ])
            ->where('borrower_user_id', $borrowerUserId)
            ->latest('overdue_started_at')
            ->get();

        return compact('incidents', 'billings', 'restrictions', 'overdueCases');
    }

    /**
     * The borrower dashboard/obligation overview: grouped counts derived from
     * the same rows My Obligations renders, plus a few technical-record
     * totals kept only to compose alert copy. The visible KPI must use
     * 'count', never a raw technical-record total.
     *
     * @return array{
     *     count: int, needs_action: int, processing: int, resolved_count: int,
     *     billings: int, billing_total: float, restrictions: int,
     *     property_cases: int, late_returns: int
     * }
     */
    public function overview(int $borrowerUserId): array
    {
        $records = $this->recordsForBorrower($borrowerUserId);

        $openIncidents = $records['incidents']->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION']);
        $openBillings = $records['billings']->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID']);
        $activeRestrictions = $records['restrictions']->filter(
            fn ($restriction) => $restriction->status === 'ACTIVE'
                && ($restriction->effective_from === null || $restriction->effective_from->lte(now()))
                && ($restriction->effective_to === null || $restriction->effective_to->gt(now()))
        );
        $openOverdueCases = $records['overdueCases']->whereNotIn('status', [LateReturnService::STATUS_RESOLVED]);

        $rows = $this->buildRows($openIncidents, $openOverdueCases, $openBillings, $activeRestrictions);
        $resolvedHistory = $this->resolvedHistory($records['billings'], $records['overdueCases'], $records['incidents']);

        $rowCollection = collect($rows);

        return [
            'count' => $rowCollection->count(),
            'needs_action' => $rowCollection->where('action_state', self::ACTION_BORROWER)->count(),
            'processing' => $rowCollection->where('action_state', self::ACTION_PROCESSING)->count(),
            'resolved_count' => $resolvedHistory->count(),
            'billings' => $openBillings->count(),
            'billing_total' => (float) $openBillings->sum('total_amount'),
            'restrictions' => $activeRestrictions->count(),
            'property_cases' => $openIncidents->count(),
            'late_returns' => $openOverdueCases->count(),
        ];
    }

    /**
     * Group related technical records into one borrower obligation row each.
     *
     * Property Incident + linked Billing + linked Restriction = ONE row.
     * Late Return + linked Billing + linked Restriction = ONE row.
     * A billing/restriction already claimed by one of those is never given
     * its own row; only genuinely standalone records surface separately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildRows(
        Collection $openIncidents,
        Collection $openOverdueCases,
        Collection $openBillings,
        Collection $activeRestrictions
    ): array {
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
        |----------------------------------------------------------------
        | Property accountability
        |----------------------------------------------------------------
        */
        foreach ($openIncidents as $incident) {
            $custody = $incident->custody;
            $recordDate = $incident->reported_at ?: $incident->created_at;
            $incidentType = (string) str($incident->incident_type)->replace('_', ' ')->title();
            $incidentItemNames = $incident->lines
                ?->map(fn ($line) => $line->custodyLine?->requestItem?->description_snapshot)
                ->filter()
                ->unique()
                ->values()
                ?? collect();
            $itemName = match (true) {
                $incidentItemNames->count() === 1 => (string) $incidentItemNames->first(),
                $incidentItemNames->count() > 1 => $incidentItemNames->implode(', '),
                default => $incidentType.' property',
            };

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
            $nextAction = 'No borrower action is required while the SPMU decision is pending.';
            $nextTone = 'info';
            $actionState = self::ACTION_PROCESSING;

            if ($incident->status === 'COMPLIANCE_REQUIRED') {
                $statusLabel = 'Compliance Required';
                $statusTone = 'warning';
                $nextAction = 'Complete the required repair, replacement, or compliance, then present it to the SPMU Action Officer for physical verification.';
                $nextTone = 'warning';
                $actionState = self::ACTION_BORROWER;
            } elseif ($linkedBilling) {
                if ($linkedBilling->status === 'RECEIPT_SUBMITTED') {
                    $statusLabel = 'Payment Verification';
                    $statusTone = 'info';
                    $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
                    $nextAction = 'No borrower action is required while the SPMU Action Officer verifies the official CSPC Cashier receipt.';
                    $actionState = self::ACTION_PROCESSING;
                } else {
                    $statusLabel = 'Payment Required';
                    $statusTone = 'warning';
                    $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
                    $nextAction = 'Settle the issued Billing Statement through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording and confirmation.';
                    $nextTone = 'warning';
                    $actionState = self::ACTION_BORROWER;
                }
            } elseif (in_array($incident->status, ['FOR_BILLING', 'BILLING_PENDING'], true)) {
                $statusLabel = 'Billing Statement Pending';
                $statusTone = 'warning';
                $nextAction = 'No borrower action is required until the SPMU Head/Admin generates and issues the Billing Statement.';
                $nextTone = 'warning';
                $actionState = self::ACTION_PROCESSING;
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

            if ($incident->status === 'COMPLIANCE_REQUIRED') {
                $facts[] = ['Verification by', 'SPMU Action Officer'];
            }

            if ($linkedBilling) {
                $facts[] = ['Billing Statement', $linkedBilling->billing_no];
                $facts[] = ['Amount', '₱'.number_format((float) $linkedBilling->total_amount, 2)];
                $facts[] = ['Payment due', optional($linkedBilling->due_at)->format('d M Y') ?: 'Not specified'];
                $facts[] = ['Receipt recording', 'SPMU Action Officer'];
            }

            if ($linkedRestriction) {
                $facts[] = ['Borrowing status', 'Restricted until this obligation is resolved'];
            }

            $obligationRows[] = [
                'category' => 'property',
                'action_state' => $actionState,
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
                        : 'This property accountability case remains under SPMU processing.'),
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
        |----------------------------------------------------------------
        | Late return / overdue
        |----------------------------------------------------------------
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

            $linkedRestriction = $activeRestrictions->first(function ($restriction) use ($penaltyIds, $linkedBilling, $custody) {
                $sameCustodyReturnControl = $custody
                    && (int) ($restriction->custody_transaction_id ?? 0) === (int) $custody->id
                    && in_array((string) $restriction->restriction_type, ['PENDING_RETURN', 'OVERDUE_RETURN'], true);

                return $sameCustodyReturnControl
                    || ($restriction->penalty_id && $penaltyIds->contains((int) $restriction->penalty_id))
                    || ($linkedBilling
                        && (int) ($restriction->billing_statement_id ?? 0) === (int) $linkedBilling->id);
            });

            if ($linkedRestriction) {
                $claimedRestrictionIds->push((int) $linkedRestriction->id);
            }

            $lines = $custody?->lines ?? collect();
            $outstandingLines = $lines->filter(
                fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
            );
            $hasOutstandingLinen = $outstandingLines->contains(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $hasOutstandingNonLinen = $outstandingLines->contains(
                fn ($line) => ! (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $itemNames = $lines
                ->map(fn ($line) => $line->requestItem?->description_snapshot)
                ->filter()
                ->unique()
                ->values();
            $itemName = $itemNames->count() === 1
                ? (string) $itemNames->first()
                : 'Borrowed items';

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
            $lateReturnNotice = $activeDocument($overdue->documents, 'LATE_RETURN_NOTICE');

            // Physical outstanding quantity, not only the case-status word,
            // determines whether the borrower still has a return action.
            $isPhysicallyOutstanding = $outstandingLines->isNotEmpty();
            $statusLabel = $isPhysicallyOutstanding ? 'Return Required' : 'Late Return Processing';
            $statusTone = $isPhysicallyOutstanding ? 'danger' : 'warning';
            $statusMeta = $daysLate > 0
                ? $daysLate.' '.($daysLate === 1 ? 'day' : 'days').' late'
                : null;

            $nextAction = match (true) {
                ! $isPhysicallyOutstanding => 'No borrower action is required while the final late-return assessment is being completed.',
                $hasOutstandingLinen && $hasOutstandingNonLinen => 'Return every outstanding non-linen item to SPMU and every outstanding linen item, with the same printed Laundry Form, to the Laundry Area immediately. Each return branch must be complete.',
                $hasOutstandingLinen => 'Return every outstanding linen item and the same printed Laundry Form to the Laundry Area immediately. Laundry RECEIVED BY is the physical return date used for timeliness.',
                default => 'Return every outstanding non-linen item to SPMU immediately for official return inspection. The complete outstanding non-linen branch must be presented together.',
            };
            $nextTone = $isPhysicallyOutstanding ? 'danger' : 'info';
            $actionState = $isPhysicallyOutstanding ? self::ACTION_BORROWER : self::ACTION_PROCESSING;

            if ($linkedBilling) {
                if ($linkedBilling->status === 'RECEIPT_SUBMITTED') {
                    $statusLabel = 'Payment Verification';
                    $statusTone = 'info';
                    $nextAction = 'No borrower action is required while the SPMU Action Officer verifies the official CSPC Cashier receipt.';
                    $actionState = self::ACTION_PROCESSING;
                } else {
                    $statusLabel = 'Payment Required';
                    $statusTone = 'warning';
                    $nextAction = 'Settle the issued late-return Billing Statement through the CSPC Cashier, then present the official receipt to the SPMU Action Officer for recording and confirmation.';
                    $nextTone = 'warning';
                    $actionState = self::ACTION_BORROWER;
                }

                $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
            }

            $actions = [];

            if ($lateReturnNotice) {
                $actions[] = ['View Late Return Notice', route('documents.view', $lateReturnNotice), true, $linkedBilling ? 'secondary' : 'primary'];
                $actions[] = ['Download Notice', route('documents.download', $lateReturnNotice), false, 'secondary'];
            }

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
                'action_state' => $actionState,
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
        |----------------------------------------------------------------
        | Standalone billings
        |----------------------------------------------------------------
        | A billing already grouped under a Property/Late Return case is
        | suppressed here. Only a billing with no visible parent obligation
        | gets its own row.
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
                ? 'No borrower action is required while the SPMU Action Officer verifies the official CSPC Cashier receipt.'
                : 'Settle the issued Billing Statement through the CSPC Cashier and present the official receipt to the SPMU Action Officer for recording and confirmation.';

            $actionState = $billing->status === 'RECEIPT_SUBMITTED'
                ? self::ACTION_PROCESSING
                : self::ACTION_BORROWER;

            if ($latestPayment?->status === 'REJECTED') {
                $statusLabel = 'Receipt Correction Required';
                $nextAction = 'Present the correct CSPC Cashier Official Receipt to SPMU.';
                $actionState = self::ACTION_BORROWER;
            }

            $actions = [];
            if ($document) {
                $actions[] = ['View Billing Statement', route('documents.view', $document), true, 'primary'];
                $actions[] = ['Download', route('documents.download', $document), false, 'secondary'];
            }

            $obligationRows[] = [
                'category' => 'billing',
                'action_state' => $actionState,
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
        |----------------------------------------------------------------
        | Standalone restrictions
        |----------------------------------------------------------------
        | Restrictions linked to a case/billing above are intentionally NOT
        | counted as another obligation. A standalone restriction (for
        | example, a borrowing suspension sanction) remains visible on its
        | own, and this is the only obligation type that can exist without
        | an active CustodyTransaction behind it.
        */
        foreach ($activeRestrictions as $restriction) {
            if ($claimedRestrictionIds->contains((int) $restriction->id)) {
                continue;
            }

            $recordDate = $restriction->effective_from ?: $restriction->created_at;
            $restrictionType = (string) str($restriction->restriction_type)->replace('_', ' ')->title();

            /* A timed restriction only requires waiting; an indefinite one
               requires the borrower to resolve the underlying requirement
               with SPMU before it can be lifted. */
            $actionState = $restriction->effective_to ? self::ACTION_PROCESSING : self::ACTION_BORROWER;

            $obligationRows[] = [
                'category' => 'restriction',
                'action_state' => $actionState,
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

        return collect($obligationRows)
            ->sortByDesc(fn ($row) => optional($row['date'])->timestamp ?? 0)
            ->values()
            ->all();
    }

    /**
     * Accountability cases that have reached a final outcome.
     *
     * Read-only history assembled from records that already exist: the
     * billing and its verified payment, the penalty that links the billing
     * back to its overdue case, and the frozen late-return assessment on
     * that case. Nothing is recalculated and nothing is duplicated into new
     * storage. Outcomes are kept distinct: a settled billing is Paid; a
     * waived or voided one is not, and is never relabelled as such.
     *
     * Moved out of AccountabilityController so the borrower dashboard's
     * resolved_count and My Obligations' Resolved History can never
     * disagree - both read this one implementation.
     *
     * @param  Collection<int, BillingStatement>  $billings
     * @param  Collection<int, OverdueCase>  $overdueCases
     * @return Collection<int, array<string, mixed>>
     */
    public function resolvedHistory(Collection $billings, Collection $overdueCases, ?Collection $incidents = null): Collection
    {
        $casesById = $overdueCases->keyBy('id');
        $incidents = $incidents ?? collect();
        $incidentsById = $incidents->keyBy('id');
        $billedCaseIds = [];
        $billedIncidentIds = [];

        $rows = $billings
            ->whereIn('status', ['SETTLED', 'WAIVED', 'VOID'])
            ->map(function (BillingStatement $billing) use ($casesById, $incidentsById, &$billedCaseIds, &$billedIncidentIds): array {
                /* Only a verified payment proves a case was paid. A stored
                   receipt file on its own never counts as settlement. */
                $payment = $billing->payments
                    ->where('status', 'VERIFIED')
                    ->sortByDesc('verified_at')
                    ->first();

                $caseId = $billing->lines
                    ->pluck('penalty.overdue_case_id')
                    ->filter()
                    ->first();

                $incidentId = $billing->lines
                    ->pluck('incident_id')
                    ->filter()
                    ->first();

                $case = $caseId ? $casesById->get($caseId) : null;
                $incident = $incidentId ? $incidentsById->get($incidentId) : null;

                if ($case) {
                    $billedCaseIds[] = $case->id;
                }

                if ($incident) {
                    $billedIncidentIds[] = $incident->id;
                }

                return [
                    'key' => 'billing-'.$billing->id,
                    'outcome' => match ($billing->status) {
                        'SETTLED' => 'Resolved - Paid',
                        'WAIVED' => 'Waived',
                        default => 'Void',
                    },
                    'tone' => $billing->status === 'SETTLED' ? 'success' : 'neutral',
                    'borrower' => $billing->borrower,
                    'reference' => $case?->custody?->custody_no
                        ?? $case?->custody?->request?->request_no
                        ?? $incident?->incident_no
                        ?? $incident?->custody?->custody_no
                        ?? $billing->billing_no,
                    'billing' => $billing,
                    'case' => $case,
                    'incident' => $incident,
                    'payment' => $billing->status === 'SETTLED' ? $payment : null,
                    'resolved_at' => $billing->status === 'SETTLED'
                        ? $payment?->verified_at
                        : $billing->updated_at,
                ];
            })
            ->values();

        /* A late-return case can resolve without ever being billed. */
        $unbilledLateReturns = $overdueCases
            ->where('status', LateReturnService::STATUS_RESOLVED)
            ->reject(fn (OverdueCase $case): bool => in_array($case->id, $billedCaseIds, true))
            ->map(fn (OverdueCase $case): array => [
                'key' => 'case-'.$case->id,
                'outcome' => 'Resolved - No Charge',
                'tone' => 'success',
                'borrower' => $case->borrower,
                'reference' => $case->custody?->custody_no ?? $case->custody?->request?->request_no ?? '-',
                'billing' => null,
                'case' => $case,
                'incident' => null,
                'payment' => null,
                'resolved_at' => $case->updated_at,
            ])
            ->values();

        /*
         * Property incidents may be resolved by no-liability clearance,
         * compliance/repair/replacement, or another non-billing outcome. They
         * still belong in Resolved History. A billed incident is already
         * represented by its billing row and is rejected here to avoid a
         * duplicate resolved record.
         */
        $unbilledPropertyIncidents = $incidents
            ->whereIn('status', ['RESOLVED', 'CLOSED'])
            ->reject(fn (Incident $incident): bool => in_array($incident->id, $billedIncidentIds, true))
            ->map(fn (Incident $incident): array => [
                'key' => 'incident-'.$incident->id,
                'outcome' => 'Resolved',
                'tone' => 'success',
                'borrower' => $incident->borrower,
                'reference' => $incident->incident_no ?: ($incident->custody?->custody_no ?? '-'),
                'billing' => null,
                'case' => null,
                'incident' => $incident,
                'payment' => null,
                'resolved_at' => $incident->updated_at,
            ])
            ->values();

        return $rows
            ->concat($unbilledLateReturns)
            ->concat($unbilledPropertyIncidents)
            ->sortByDesc(fn (array $row) => $row['resolved_at'])
            ->values();
    }
}
