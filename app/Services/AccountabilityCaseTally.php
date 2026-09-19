<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The one canonical definition of an "accountability case", shared by every
 * dashboard card and the Accountability workspace so they can never quietly
 * disagree again:
 *
 * - one Incident is one property accountability case
 * - one OverdueCase is one late-return case
 * - an Incident and an OverdueCase on the same custody remain two cases
 *   (never deduplicated by custody_transaction_id)
 * - a linked BillingStatement, BorrowerRestriction, Payment, or document is a
 *   detail of its case, never counted again on its own
 * - a truly standalone billing/restriction (no linked open Incident or
 *   OverdueCase - typically a legacy record) is represented, and only once
 *
 * Callers are responsible for deciding which records are "open"/"current" -
 * this class only decides how to count them without double-counting.
 */
class AccountabilityCaseTally
{
    private Collection $incidents;

    private Collection $overdueCases;

    private Collection $standaloneBillings;

    private Collection $standaloneRestrictions;

    /**
     * @param  Collection  $incidents  Open incidents, each to be counted as one case.
     * @param  Collection  $overdueCases  Open overdue cases, each to be counted as one case.
     * @param  Collection  $billings  Open/unsettled billings, each with its `lines.penalty` relation loaded (or loadable), used only to detect which ones are standalone.
     * @param  Collection  $restrictions  Active restrictions, used only to detect which ones are standalone.
     */
    public function __construct(
        Collection $incidents,
        Collection $overdueCases,
        Collection $billings,
        Collection $restrictions
    ) {
        $this->incidents = $incidents->values();
        $this->overdueCases = $overdueCases->values();

        $incidentIds = $this->incidents->pluck('id')->filter()->map(fn ($id): int => (int) $id);
        $overdueIds = $this->overdueCases->pluck('id')->filter()->map(fn ($id): int => (int) $id);

        $this->standaloneBillings = $billings->reject(function ($billing) use ($incidentIds, $overdueIds): bool {
            return $billing->lines->contains(function ($line) use ($incidentIds, $overdueIds): bool {
                $incidentId = (int) ($line->incident_id ?? 0);
                $overdueId = (int) ($line->penalty?->overdue_case_id ?? 0);

                return ($incidentId > 0 && $incidentIds->contains($incidentId))
                    || ($overdueId > 0 && $overdueIds->contains($overdueId));
            });
        })->values();

        $overdueCustodyIds = $this->overdueCases
            ->pluck('custody_transaction_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        $this->standaloneRestrictions = $restrictions->reject(function ($restriction) use ($incidentIds, $overdueCustodyIds): bool {
            $incidentId = (int) ($restriction->incident_id ?? 0);

            if ($incidentId > 0 && $incidentIds->contains($incidentId)) {
                return true;
            }

            /*
             * A restriction only ever falls back to a custody match when no
             * incident claims it first - the same rule the Accountability
             * workspace's own case<->restriction linkage already uses.
             */
            $custodyId = (int) ($restriction->custody_transaction_id ?? 0);

            return $incidentId === 0 && $custodyId > 0 && $overdueCustodyIds->contains($custodyId);
        })->values();
    }

    public static function forOpenRecords(
        Collection $incidents,
        Collection $overdueCases,
        Collection $billings,
        Collection $restrictions
    ): self {
        return new self($incidents, $overdueCases, $billings, $restrictions);
    }

    /** Billings with no linked open Incident/OverdueCase - each still one case of its own. */
    public function standaloneBillings(): Collection
    {
        return $this->standaloneBillings;
    }

    /** Restrictions with no linked open Incident/OverdueCase - each still one case of its own. */
    public function standaloneRestrictions(): Collection
    {
        return $this->standaloneRestrictions;
    }

    /** The one canonical case count: incidents + overdue cases + standalone billings + standalone restrictions. */
    public function count(): int
    {
        return $this->incidents->count()
            + $this->overdueCases->count()
            + $this->standaloneBillings->count()
            + $this->standaloneRestrictions->count();
    }
}
