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
            ->with(['custody', 'sanction.documents'])
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
        $filtered = $this->filterOpenRecords($records);

        [$openIncidents, $openOverdueCases, $openBillings, $activeRestrictions] = $filtered;

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
     * The same grouped obligation rows overview() summarizes into counts,
     * exposed directly so a caller (the borrower dashboard's action list) can
     * render the individual rows instead of only their totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function obligationRows(int $borrowerUserId): array
    {
        [$openIncidents, $openOverdueCases, $openBillings, $activeRestrictions] = $this->filterOpenRecords(
            $this->recordsForBorrower($borrowerUserId)
        );

        return $this->buildRows($openIncidents, $openOverdueCases, $openBillings, $activeRestrictions);
    }

    /**
     * Custody transaction IDs whose open property/late-return obligation
     * genuinely needs the borrower to act right now - not merely "processing"
     * while SPMU, the Accounting Office, or the Head/Admin handles the next
     * step. Reused by the dashboard's action queue so a custody is never
     * flagged purely from a coarse OBLIGATION_OPEN/INCIDENT_OPEN status; the
     * same per-record action_state used by My Obligations decides it.
     *
     * @return array<int, int>
     */
    public function borrowerActionableCustodyIds(int $borrowerUserId): array
    {
        return collect($this->obligationRows($borrowerUserId))
            ->whereIn('category', ['property', 'overdue'])
            ->where('action_state', self::ACTION_BORROWER)
            ->pluck('custody_transaction_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The same open-record filter buildRows()/overview() already apply,
     * exposed so a caller that needs the raw Incident/OverdueCase models
     * themselves (not the grouped obligation rows) - such as the borrower
     * workspace grouping its Active Cases by custody transaction - reads
     * exactly the same "open" definition instead of a second one drifting
     * out of sync with it.
     *
     * @return array{incidents: Collection, overdueCases: Collection, billings: Collection, restrictions: Collection}
     */
    public function openRecordsForBorrower(int $borrowerUserId): array
    {
        [$incidents, $overdueCases, $billings, $restrictions] = $this->filterOpenRecords(
            $this->recordsForBorrower($borrowerUserId)
        );

        return compact('incidents', 'overdueCases', 'billings', 'restrictions');
    }

    /**
     * Whether this borrower currently has any open accountability case,
     * outstanding billing, or active restriction that must block a new
     * borrowing request. This is the single source of truth for borrowing
     * eligibility: it reuses the exact same "open" definition as the
     * borrower dashboard and My Obligations (filterOpenRecords()), so
     * eligibility is decided from the borrower's OVERALL standing - every
     * open case, obligation, and restriction - never from only the most
     * recently confirmed sanction.
     */
    public function isEligibleToBorrow(int $borrowerUserId): bool
    {
        return $this->blockingReasons($borrowerUserId) === [];
    }

    /**
     * Human-readable reasons this borrower cannot currently submit a new
     * borrowing request, one per open case/obligation/restriction found.
     * Empty when eligible.
     *
     * A resolved case and an expired sanction restriction are each removed
     * independently of one another (filterOpenRecords()'s own status/date
     * rules), so a case that is still open keeps blocking even after its
     * sanction's suspension period has ended, and an active suspension
     * keeps blocking even after its underlying case is fully resolved -
     * only when both clear does this return empty.
     *
     * @return list<string>
     */
    public function blockingReasons(int $borrowerUserId): array
    {
        [$openIncidents, $openOverdueCases, $openBillings, $activeRestrictions] = $this->filterOpenRecords(
            $this->recordsForBorrower($borrowerUserId)
        );

        $reasons = [];

        foreach ($openOverdueCases as $overdueCase) {
            $custodyNo = $overdueCase->custody?->custody_no;
            $reasons[] = $custodyNo
                ? "Active late-return accountability case ({$custodyNo})."
                : 'Active late-return accountability case.';
        }

        foreach ($openIncidents as $incident) {
            $custodyNo = $incident->custody?->custody_no;
            $reasons[] = $custodyNo
                ? "Active property accountability case ({$custodyNo})."
                : 'Active property accountability case.';
        }

        foreach ($openBillings as $billing) {
            $reasons[] = "Outstanding payment: {$billing->billing_no}.";
        }

        foreach ($activeRestrictions as $restriction) {
            /*
             * A restriction created directly from an open Incident/OverdueCase
             * (PENDING_RETURN, OVERDUE_RETURN, UNRESOLVED_INCIDENT) is already
             * represented by that case above - listing it again would repeat
             * the same underlying fact under two different wordings.
             */
            if (in_array($restriction->restriction_type, ['PENDING_RETURN', 'OVERDUE_RETURN', 'UNRESOLVED_INCIDENT'], true)) {
                continue;
            }

            if ($restriction->restriction_type === 'SANCTION_SUSPENSION') {
                $isCurrentSemesterSuspension = (int) ($restriction->sanction?->offense_no ?? 0) >= 3;
                $until = $restriction->effective_to?->format('d M Y');

                $reasons[] = match (true) {
                    $isCurrentSemesterSuspension && $until => "Suspended for the current semester (until {$until}).",
                    $isCurrentSemesterSuspension => 'Suspended for the current semester.',
                    (bool) $until => "Suspension active until {$until}.",
                    default => 'Suspension active.',
                };

                continue;
            }

            $reasons[] = $restriction->reason ?: 'An active borrowing restriction is in effect.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array{incidents: Collection, billings: Collection, restrictions: Collection, overdueCases: Collection}  $records
     * @return array{0: Collection, 1: Collection, 2: Collection, 3: Collection}
     */
    private function filterOpenRecords(array $records): array
    {
        $openIncidents = $records['incidents']->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION']);
        $openBillings = $records['billings']->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID']);
        $activeRestrictions = $records['restrictions']->filter(
            fn ($restriction) => $restriction->status === 'ACTIVE'
                && ($restriction->effective_from === null || $restriction->effective_from->lte(now()))
                && ($restriction->effective_to === null || $restriction->effective_to->gt(now()))
        );
        $openOverdueCases = $records['overdueCases']->whereNotIn('status', [LateReturnService::STATUS_RESOLVED]);

        return [$openIncidents, $openOverdueCases, $openBillings, $activeRestrictions];
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
            $billingLabel = 'Preview';
            $complianceDocument = $activeDocument($incident->documents, 'ACCOUNTABILITY_COMPLIANCE_NOTICE');
            $rslddpDocument = $activeDocument($incident->documents, 'RSLDDP');
            $complianceActionLabel = match (strtoupper((string) $incident->compliance_action)) {
                'REPAIR' => 'repair and return-to-service requirement',
                'REPLACEMENT' => 'one-for-one replacement',
                'RECOVERY' => 'item recovery / return',
                default => 'property compliance',
            };

            $statusLabel = 'Under Review';
            $statusTone = 'neutral';
            $statusMeta = null;
            $nextAction = 'No borrower action is required while the SPMU decision is pending.';
            $nextTone = 'info';
            $actionState = self::ACTION_PROCESSING;
            $rslddpUploadIncidentId = null;

            if ($incident->status === 'COMPLIANCE_REQUIRED') {
                $statusLabel = 'Compliance Required';
                $statusTone = 'warning';
                $nextAction = 'Complete the required '.$complianceActionLabel.', then present the property to the SPMU Action Officer for physical verification.';
                $nextTone = 'warning';
                $actionState = self::ACTION_BORROWER;
            } elseif ($incident->status === 'COMPLIANCE_RSLDDP_PENDING') {
                /*
                 * Legacy status only - no new decision ever sets this. Kept
                 * exactly as it operated before the Accountability rework,
                 * including the borrower-upload copy, since a row already
                 * sitting here predates the SPMU-Head-only upload
                 * authorization change and its own linked EvidenceSubmission
                 * flow is unaffected by that change.
                 */
                $statusLabel = 'RSLDDP Pending Upload';
                $statusTone = 'warning';
                $nextAction = 'Print the generated RSLDDP for external signing/notarization, then upload the accomplished scan below to resume Action Officer compliance verification.';
                $nextTone = 'warning';
                $actionState = self::ACTION_BORROWER;
                $rslddpUploadIncidentId = $incident->id;
            } elseif ($incident->status === 'RSLDDP_AWAITING_UPLOAD') {
                /*
                 * The borrower is never the uploader of the accomplished
                 * RSLDDP (spec Section F) - SPMU Head/Admin uploads it, for
                 * both legacy and new-flow incidents reaching this status,
                 * so this is SPMU_PROCESSING regardless of
                 * accountability_flow_version.
                 */
                $statusLabel = 'RSLDDP Processing';
                $statusTone = 'warning';
                $nextAction = 'No borrower action is required. SPMU Head/Admin will upload the accomplished RSLDDP after external processing.';
                $actionState = self::ACTION_PROCESSING;
            } elseif ($incident->status === 'RSLDDP_FOR_ACCOUNTING_PROCESSING') {
                // Legacy only - no new decision ever sets this.
                $statusLabel = 'For Accounting Processing';
                $statusTone = 'info';
                $nextAction = 'No borrower action is required while the accomplished RSLDDP is processed by the Accounting Office.';
                $actionState = self::ACTION_PROCESSING;
            } elseif ($incident->status === 'RSLDDP_DISPOSITION_PENDING') {
                $statusLabel = 'Official Disposition Pending';
                $statusTone = 'info';
                $nextAction = 'No borrower action is required while the SPMU Head/Admin records the official disposition stated in the accomplished RSLDDP.';
                $actionState = self::ACTION_PROCESSING;
            } elseif ($incident->status === 'RSLDDP_COMPLIANCE_VERIFICATION' && $incident->official_disposition !== 'MONETARY_SETTLEMENT') {
                // Monetary falls through to the $linkedBilling branch below -
                // the borrower's action there is already Cashier settlement.
                $statusLabel = \App\Support\AccountabilityDispositionLabels::subStatusLabel($incident);
                $statusTone = 'warning';
                $nextAction = 'Complete the required '.\App\Support\AccountabilityDispositionLabels::officialDispositionLabel($incident->official_disposition).', then present it to the SPMU Action Officer for verification.';
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
            } elseif ($incident->status === 'RSLDDP_FOR_RESOLUTION') {
                $statusLabel = 'For Resolution';
                $statusTone = 'info';
                $nextAction = 'No borrower action is required while the SPMU Head/Admin verifies and resolves this case.';
                $actionState = self::ACTION_PROCESSING;
            } elseif (in_array($incident->status, ['FOR_BILLING', 'BILLING_PENDING'], true)) {
                $statusLabel = 'Billing Statement Pending';
                $statusTone = 'warning';
                $nextAction = 'No borrower action is required until the SPMU Head/Admin generates and issues the Billing Statement.';
                $nextTone = 'warning';
                $actionState = self::ACTION_PROCESSING;
            }

            $actions = [];

            /*
             * Every document link uses "Preview" and every record link uses
             * "View" - the same convention as the rest of the system - and
             * carries a $kind tag ('document' or 'reference') so the view
             * can group these into the Documents / Borrowing Reference
             * sections of one expanded obligation instead of a wall of
             * buttons on the collapsed card. A document never also needs a
             * separate Download action here: opening it already lets the
             * borrower view or save it. Every document opens through the
             * in-system preview page, in the same tab, never a raw stream
             * in a new one.
             */
            if ($document) {
                $actions[] = ['Preview', route('documents.preview', $document), false, 'primary', 'document', $billingLabel];
            }

            if ($rslddpDocument) {
                $actions[] = ['Preview', route('documents.preview', $rslddpDocument), false, 'secondary', 'document', 'RSLDDP'];
            }

            if ($complianceDocument) {
                $actions[] = ['Preview', route('documents.preview', $complianceDocument), false, 'primary', 'document', 'Compliance Notice'];
            }

            if ($linkedRestriction && $linkedRestriction->incident_id) {
                $actions[] = ['Preview', route('restrictions.notice', $linkedRestriction), false, 'secondary', 'document', 'Restriction Notice'];
            }

            if ($custody) {
                $actions[] = ['View Borrowing', route('custody.show', $custody), false, 'secondary', 'reference'];
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

            /* The restriction's own reason/dates/status now have a dedicated
               panel in the expanded obligation - see 'restriction' below -
               so this fact line no longer repeats the same thing more vaguely. */

            $obligationRows[] = [
                'category' => 'property',
                'custody_transaction_id' => $custody?->id,
                'action_state' => $actionState,
                'status' => $linkedBilling?->status ?: $incident->status,
                'date' => $linkedBilling?->issued_at ?: $recordDate,
                'tone' => 'warning',
                'icon' => 'accountability',
                'type' => 'Property Accountability',
                'title' => $itemName.' — '.$incidentType,
                'reference' => $incident->incident_no,
                'summary' => $linkedBilling
                    ? 'This property issue and its payment requirement are grouped as one obligation.'
                    : ($incident->status === 'COMPLIANCE_REQUIRED'
                        ? 'A repair, replacement, or other compliance step is required before this obligation can be cleared.'
                        : 'SPMU is reviewing this property accountability matter.'),
                'badge' => $statusLabel,
                'badge_tone' => $statusTone,
                'status_meta' => $statusMeta,
                'restricted' => (bool) $linkedRestriction,
                'restriction' => $linkedRestriction,
                'next_action' => $nextAction,
                'next_tone' => $nextTone,
                'facts' => $facts,
                'actions' => $actions,
                'rslddp_upload_incident_id' => $rslddpUploadIncidentId,
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
                : ($itemNames->isEmpty() ? 'Borrowed item' : $itemNames->implode(', '));

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
                    $nextAction = 'Preview or print the Late Return Billing Statement, present it to the CSPC Cashier, then submit the official receipt to SPMU.';
                    $nextTone = 'warning';
                    $actionState = self::ACTION_BORROWER;
                }

                $statusMeta = '₱'.number_format((float) $linkedBilling->total_amount, 2);
            }

            /*
             * "Preview" for documents, "View" for records - the same
             * convention every other action in the system uses - and each
             * carries a $kind tag so the view groups them under Documents /
             * Borrowing Reference in the expanded obligation instead of
             * listing every document action on the collapsed card. A
             * document that can be opened does not also need a separate
             * Download action.
             */
            $actions = [];

            if ($lateReturnNotice) {
                $actions[] = ['Preview', route('documents.preview', $lateReturnNotice), false, $linkedBilling ? 'secondary' : 'primary', 'document', 'Late Return Notice'];
            }

            if ($document) {
                $actions[] = ['Preview', route('documents.preview', $document), false, 'primary', 'document', 'Late Return Billing Statement'];
            }

            if ($custody) {
                $actions[] = ['View Borrowing', route('custody.show', $custody), false, 'secondary', 'reference'];
            }

            $facts = [
                ['Item(s)', $itemName],
                ['Expected return', optional($dueAt)->format('d M Y') ?: '—'],
                ['Actual return', $actualReturnAt?->format('d M Y') ?: 'Not yet returned'],
                ['Late days', (string) $daysLate],
                ['Custody', $custody?->custody_no ?: '—'],
            ];

            /* Billing reference and amount already appear in the obligation header
               and Documents section, so History / Details keeps only return facts. */

            /* The restriction's own reason/dates/status now have a dedicated
               panel in the expanded obligation - see 'restriction' below. */

            $obligationRows[] = [
                'category' => 'overdue',
                'custody_transaction_id' => $custody?->id,
                'action_state' => $actionState,
                'status' => $linkedBilling?->status ?: $overdue->status,
                'date' => $linkedBilling?->issued_at ?: $recordDate,
                'tone' => $isPhysicallyOutstanding ? 'danger' : 'warning',
                'icon' => 'calendar',
                'type' => $isPhysicallyOutstanding ? 'Overdue Return' : 'Late Return',
                /* One stable obligation title, not the physical item name -
                   the item(s) are still shown, as a fact, once the
                   obligation is opened. */
                'title' => 'Late Return Obligation',
                'reference' => $custody?->custody_no ?: ($custody?->request?->request_no ?: 'Late return record'),
                'summary' => $linkedBilling
                    ? 'This late return and its payment requirement are grouped as one obligation.'
                    : ($isPhysicallyOutstanding
                        ? 'Some property is still overdue and must be returned.'
                        : 'The return is complete. SPMU is processing the late-return assessment.'),
                'badge' => $statusLabel,
                'badge_tone' => $statusTone,
                'status_meta' => $statusMeta,
                'restricted' => (bool) $linkedRestriction,
                'restriction' => $linkedRestriction,
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

            $billingLabel = $billing->displayLabel();

            $nextAction = $billing->status === 'RECEIPT_SUBMITTED'
                ? 'No borrower action is required while the SPMU Action Officer verifies the official CSPC Cashier receipt.'
                : "Settle the issued {$billingLabel} through the CSPC Cashier and present the official receipt to the SPMU Action Officer for recording and confirmation.";

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
                $actions[] = ['Preview', route('documents.preview', $document), false, 'primary', 'document', $billingLabel];
            }

            $obligationRows[] = [
                'category' => 'billing',
                'action_state' => $actionState,
                'status' => $billing->status,
                'date' => $recordDate,
                'tone' => 'info',
                'icon' => 'requests',
                'type' => 'Financial Obligation',
                'title' => $billing->lines->first()?->description ?: $billingLabel,
                'reference' => $billing->billing_no,
                'summary' => "A payment requirement is still open and needs settlement or verification.",
                'badge' => $statusLabel,
                'badge_tone' => $latestPayment?->status === 'REJECTED' ? 'danger' : 'info',
                'status_meta' => '₱'.number_format((float) $billing->total_amount, 2),
                'restricted' => (bool) $linkedRestriction,
                'restriction' => $linkedRestriction,
                'next_action' => $nextAction,
                'next_tone' => $latestPayment?->status === 'REJECTED' ? 'danger' : 'warning',
                'facts' => [
                    [$billingLabel, $billing->billing_no],
                    ['Amount', '₱'.number_format((float) $billing->total_amount, 2)],
                    ['Payment due', optional($billing->due_at)->format('d M Y') ?: 'Not specified'],
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

            /*
             * A sanction-caused restriction gets the Suspension Notice (or,
             * for a historical non-suspension sanction, its original
             * Administrative Sanction Notice label - accurate to what it
             * actually is, never relabelled). A property-caused restriction
             * with no sanction gets the Restriction Notice. A late-return-
             * caused restriction gets neither - it already has its own Late
             * Return Notice on that obligation's row.
             */
            $sanctionForRestriction = $restriction->sanction_id ? $restriction->sanction : null;
            $isSuspension = $sanctionForRestriction
                && strtoupper((string) $sanctionForRestriction->sanction_code) === 'BORROWING_SUSPENSION';

            $actions = [];
            if ($sanctionForRestriction) {
                $sanctionDocument = $activeDocument($sanctionForRestriction->documents, 'ADMINISTRATIVE_SANCTION_NOTICE');
                if ($sanctionDocument) {
                    $actions[] = [
                        'Preview',
                        route('documents.preview', $sanctionDocument),
                        false,
                        'secondary',
                        'document',
                        $isSuspension ? 'Suspension Notice' : 'Administrative Sanction Notice',
                    ];
                }
            } elseif ($restriction->incident_id) {
                $actions[] = ['Preview', route('restrictions.notice', $restriction), false, 'secondary', 'document', 'Restriction Notice'];
            }

            $obligationRows[] = [
                'category' => 'restriction',
                'action_state' => $actionState,
                'status' => $restriction->status,
                'date' => $recordDate,
                'tone' => 'orange',
                'icon' => 'lock',
                'type' => 'Borrowing Restriction',
                'title' => match (true) {
                    $isSuspension => 'Borrowing suspension',
                    (bool) $sanctionForRestriction => 'Administrative borrowing restriction',
                    default => 'Borrowing temporarily restricted',
                },
                'reference' => match (true) {
                    $isSuspension => 'Suspension',
                    (bool) $sanctionForRestriction => 'Administrative sanction',
                    default => 'Restriction record',
                },
                'summary' => $restriction->reason ?: $restrictionType,
                'badge' => $restriction->effective_to ? 'In Effect' : 'Restricted',
                'badge_tone' => 'warning',
                'status_meta' => $restriction->effective_to
                    ? 'Until '.$restriction->effective_to->format('d M Y')
                    : 'Until resolved',
                'restricted' => true,
                'restriction' => $restriction,
                'next_action' => $restriction->effective_to
                    ? 'Wait until the configured restriction period ends.'
                    : 'Resolve the linked requirement with SPMU.',
                'next_tone' => 'warning',
                /* The row's whole subject is this restriction, so its
                   reason/dates/status are shown once, by the dedicated
                   Restriction panel every row gets - not duplicated here too. */
                'facts' => [],
                'actions' => $actions,
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
