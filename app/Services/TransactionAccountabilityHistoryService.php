<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the accountability portion of a borrowing transaction timeline.
 *
 * The request detail already shows request, approval, release, and return
 * events. This service adds the records that used to disappear from that
 * readable history once the transaction entered Accountability Processing.
 * It never creates business records; it only reads the persisted lifecycle.
 */
class TransactionAccountabilityHistoryService
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forCustody(?CustodyTransaction $custody): Collection
    {
        if (! $custody) {
            return collect();
        }

        $events = collect();

        $push = function (
            $when,
            string $stage,
            string $borrowerEvent,
            string $spmuEvent,
            string $actor,
            string $borrowerDetails,
            string $spmuDetails,
            string $key
        ) use ($events): void {
            if (! $when) {
                return;
            }

            $events->push([
                'when' => $when,
                'stage' => $stage,
                'event_borrower' => $borrowerEvent,
                'event_spmu' => $spmuEvent,
                'actor' => $actor,
                'details_borrower' => $borrowerDetails,
                'details_spmu' => $spmuDetails,
                'key' => $key,
            ]);
        };

        $incidents = Incident::query()
            ->with([
                'reportedBy',
                'headDecidedBy',
                'lines.custodyLine.requestItem',
            ])
            ->where('custody_transaction_id', $custody->id)
            ->orderBy('reported_at')
            ->get();

        $incidentIds = $incidents->pluck('id')->map(fn ($id) => (int) $id)->values();

        foreach ($incidents as $incident) {
            $finding = Str::of((string) $incident->incident_type)
                ->replace('_', ' ')
                ->lower()
                ->title()
                ->toString();
            $item = $incident->lines
                ->pluck('custodyLine.requestItem.description_snapshot')
                ->filter()
                ->first();
            $subject = $item ? $item.' — '.$finding : $finding;

            $push(
                $incident->reported_at ?: $incident->created_at,
                'Accountability',
                'Property issue recorded',
                'Property accountability case created',
                $incident->reportedBy?->full_name ?: 'SPMU Action Officer',
                "{$subject} was recorded under {$incident->incident_no}.",
                "{$subject} was recorded as {$incident->incident_no} after the return inspection.",
                'accountability-incident-'.$incident->id
            );
        }

        /*
         * Head decisions are audit-backed because an incident can move through
         * more than one Head action and its current status alone cannot recreate
         * those earlier decisions after the case is resolved.
         */
        if ($incidentIds->isNotEmpty()) {
            $incidentAuditEvents = AuditEvent::query()
                ->with('actor')
                ->where('record_type', Incident::class)
                ->whereIn('record_id', $incidentIds)
                ->whereIn('action_code', [
                    'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED',
                    'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED',
                ])
                ->orderBy('occurred_at')
                ->get();

            foreach ($incidentAuditEvents as $audit) {
                $outcome = $this->outcomeLabel($audit->after_json['resolution_outcome'] ?? null);
                $resolved = $audit->action_code === 'PROPERTY_ACCOUNTABILITY_CASE_RESOLVED';
                $reference = $incidents->firstWhere('id', $audit->record_id)?->incident_no ?: 'property case';

                $push(
                    $audit->occurred_at,
                    'Accountability',
                    $resolved ? 'Property obligation resolved' : 'SPMU decision recorded',
                    $resolved ? 'Property accountability case resolved' : 'SPMU Head accountability decision recorded',
                    $audit->actor?->full_name ?: 'SPMU Head/Admin',
                    $resolved
                        ? "SPMU resolved {$reference}."
                        : "SPMU recorded the decision for {$reference}: {$outcome}.",
                    $resolved
                        ? "{$reference} was resolved. Outcome: {$outcome}."
                        : "Head/Admin decision for {$reference}: {$outcome}.",
                    'accountability-audit-'.$audit->id
                );
            }
        }

        $overdueCases = OverdueCase::query()
            ->with('confirmedBy')
            ->where('custody_transaction_id', $custody->id)
            ->get();

        foreach ($overdueCases as $case) {
            $push(
                $case->overdue_started_at,
                'Accountability',
                'Return became overdue',
                'Overdue return case opened',
                'System',
                'The borrowing passed its expected return date and entered accountability monitoring.',
                'The custody passed its effective return date and an overdue case was opened.',
                'overdue-opened-'.$case->id
            );

            $push(
                $case->ao_confirmed_at,
                'Accountability',
                'Late-return assessment confirmed',
                'Action Officer confirmed late-return assessment',
                $case->confirmedBy?->full_name ?: 'SPMU Action Officer',
                'SPMU confirmed the recorded late-return assessment.',
                'The Action Officer confirmed the physical return date and frozen late-day assessment.',
                'overdue-confirmed-'.$case->id
            );
        }

        $penalties = Penalty::query()
            ->where('custody_transaction_id', $custody->id)
            ->get();
        $penaltyIds = $penalties->pluck('id')->map(fn ($id) => (int) $id)->values();

        $billingIds = $this->billingIds($incidentIds, $penaltyIds);
        $billings = BillingStatement::query()
            ->with([
                'responsibleSpmuUser',
                'payments.recordedBy',
                'payments.verifiedBy',
            ])
            ->whereIn('id', $billingIds)
            ->orderBy('issued_at')
            ->get();

        foreach ($billings as $billing) {
            $push(
                $billing->issued_at ?: $billing->created_at,
                'Billing',
                'Billing Statement issued',
                'Billing Statement issued',
                $billing->responsibleSpmuUser?->full_name ?: 'SPMU Head/Admin',
                "{$billing->billing_no} was issued for PHP ".number_format((float) $billing->total_amount, 2).'.',
                "{$billing->billing_no} was issued for PHP ".number_format((float) $billing->total_amount, 2)." as part of this custody accountability lifecycle.",
                'billing-issued-'.$billing->id
            );

            foreach ($billing->payments->sortBy(fn ($payment) => $payment->verified_at ?: $payment->submitted_at) as $payment) {
                $when = $payment->verified_at ?: $payment->submitted_at ?: $payment->created_at;
                $verified = $payment->status === 'VERIFIED';
                $rejected = $payment->status === 'REJECTED';

                $borrowerEvent = match (true) {
                    $verified => 'Payment confirmed',
                    $rejected => 'Cashier receipt returned for correction',
                    default => 'Cashier receipt recorded',
                };

                $spmuEvent = match (true) {
                    $verified => 'Cashier payment recorded and confirmed',
                    $rejected => 'Cashier receipt rejected',
                    default => 'Cashier receipt recorded',
                };

                $actor = $payment->verifiedBy?->full_name
                    ?: $payment->recordedBy?->full_name
                    ?: 'SPMU Action Officer';

                $borrowerDetails = $verified
                    ? "Payment for {$billing->billing_no} was confirmed by SPMU."
                    : ($rejected
                        ? "The receipt for {$billing->billing_no} needs correction."
                        : "A Cashier receipt was recorded for {$billing->billing_no}.");

                $spmuDetails = "{$billing->billing_no}; receipt {$payment->official_receipt_no}; PHP "
                    .number_format((float) $payment->amount, 2)."; status {$payment->status}.";

                $push(
                    $when,
                    'Billing',
                    $borrowerEvent,
                    $spmuEvent,
                    $actor,
                    $borrowerDetails,
                    $spmuDetails,
                    'payment-'.$payment->id.'-'.$payment->status
                );
            }
        }

        $restrictions = $this->linkedRestrictions(
            $custody,
            $incidentIds,
            $penaltyIds,
            $billingIds
        );

        $actorIds = $restrictions
            ->flatMap(fn ($restriction) => [$restriction->imposed_by_user_id, $restriction->lifted_by_user_id])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $actorNames = User::query()
            ->whereIn('id', $actorIds)
            ->pluck('full_name', 'id');

        foreach ($restrictions as $restriction) {
            $push(
                $restriction->effective_from ?: $restriction->created_at,
                'Restriction',
                'Borrowing restriction applied',
                'Linked borrowing restriction applied',
                $actorNames->get((int) $restriction->imposed_by_user_id) ?: 'SPMU',
                'Borrowing is restricted while the linked obligation remains unresolved.',
                "Restriction {$restriction->restriction_type} was applied for this transaction.",
                'restriction-applied-'.$restriction->id
            );

            if ($restriction->status === 'LIFTED' || $restriction->effective_to) {
                $push(
                    $restriction->effective_to ?: $restriction->updated_at,
                    'Restriction',
                    'Borrowing restriction lifted',
                    'Linked borrowing restriction lifted',
                    $actorNames->get((int) $restriction->lifted_by_user_id) ?: 'SPMU',
                    'The restriction linked to this obligation was lifted.',
                    "Restriction {$restriction->restriction_type} was lifted after the linked obligation was cleared.",
                    'restriction-lifted-'.$restriction->id
                );
            }
        }

        $violations = BorrowerViolation::query()
            ->with(['sanction.confirmedBy'])
            ->where('custody_transaction_id', $custody->id)
            ->get();

        foreach ($violations as $violation) {
            $sanction = $violation->sanction;
            if (! $sanction) {
                continue;
            }

            $push(
                $sanction->confirmed_at ?: $sanction->created_at,
                'Administrative',
                'Administrative action recorded',
                'Administrative sanction recorded',
                $sanction->confirmedBy?->full_name ?: 'SPMU Head/Admin',
                "SPMU recorded the applicable administrative action: {$sanction->sanction_label}.",
                "Offense {$sanction->offense_no}: {$sanction->sanction_label}. Status: {$sanction->status}.",
                'sanction-'.$sanction->id
            );
        }

        /* Preserve authorized waivers as their own outcome; waived is not paid. */
        if ($billingIds->isNotEmpty()) {
            $waiverAudits = AuditEvent::query()
                ->with('actor')
                ->where('record_type', BillingStatement::class)
                ->whereIn('record_id', $billingIds)
                ->where('action_code', 'BILLING_STATEMENT_WAIVED')
                ->orderBy('occurred_at')
                ->get();

            foreach ($waiverAudits as $audit) {
                $billing = $billings->firstWhere('id', $audit->record_id);
                $reference = $billing?->billing_no ?: 'Billing Statement';

                $push(
                    $audit->occurred_at,
                    'Billing',
                    'Billing waived',
                    'Billing Statement waived',
                    $audit->actor?->full_name ?: 'SPMU Head/Admin',
                    "{$reference} was formally waived by SPMU.",
                    "{$reference} was formally waived. This outcome remains distinct from paid settlement.",
                    'billing-waived-'.$audit->id
                );
            }
        }

        return $events
            ->filter(fn (array $event) => $event['when'])
            ->unique('key')
            ->sortBy(fn (array $event) => $event['when']->getTimestamp())
            ->values();
    }

    /**
     * @param Collection<int, int> $incidentIds
     * @param Collection<int, int> $penaltyIds
     * @return Collection<int, int>
     */
    private function billingIds(Collection $incidentIds, Collection $penaltyIds): Collection
    {
        if ($incidentIds->isEmpty() && $penaltyIds->isEmpty()) {
            return collect();
        }

        return DB::table('billing_lines')
            ->where(function ($query) use ($incidentIds, $penaltyIds): void {
                if ($incidentIds->isNotEmpty()) {
                    $query->whereIn('incident_id', $incidentIds);
                }

                if ($penaltyIds->isNotEmpty()) {
                    $method = $incidentIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('penalty_id', $penaltyIds);
                }
            })
            ->distinct()
            ->pluck('billing_statement_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * @param Collection<int, int> $incidentIds
     * @param Collection<int, int> $penaltyIds
     * @param Collection<int, int> $billingIds
     * @return Collection<int, BorrowerRestriction>
     */
    private function linkedRestrictions(
        CustodyTransaction $custody,
        Collection $incidentIds,
        Collection $penaltyIds,
        Collection $billingIds
    ): Collection {
        if ($incidentIds->isEmpty() && $penaltyIds->isEmpty() && $billingIds->isEmpty()) {
            return collect();
        }

        return BorrowerRestriction::query()
            ->where('borrower_user_id', $custody->borrower_user_id)
            ->where(function ($query) use ($incidentIds, $penaltyIds, $billingIds): void {
                $hasClause = false;

                if ($incidentIds->isNotEmpty()) {
                    $query->whereIn('incident_id', $incidentIds);
                    $hasClause = true;
                }

                if ($penaltyIds->isNotEmpty()) {
                    $method = $hasClause ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('penalty_id', $penaltyIds);
                    $hasClause = true;
                }

                if ($billingIds->isNotEmpty()) {
                    $method = $hasClause ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('billing_statement_id', $billingIds);
                }
            })
            ->orderBy('effective_from')
            ->get();
    }

    private function outcomeLabel(?string $outcome): string
    {
        return match ((string) $outcome) {
            'NO_BORROWER_CHARGE' => 'No borrower liability / no charge',
            'COMPLIANCE_REQUIRED' => 'Repair / replacement / compliance required',
            'BILLING_REQUIRED' => 'Billing / payment required',
            'COMPLIANCE_COMPLETED' => 'Required compliance completed',
            'ADMINISTRATIVELY_CLEARED' => 'Administratively cleared',
            default => 'Decision recorded',
        };
    }
}
