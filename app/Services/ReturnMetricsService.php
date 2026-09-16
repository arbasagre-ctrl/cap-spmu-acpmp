<?php

namespace App\Services;

use App\Models\CustodyTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Read-only return metrics shared by Analytics and formal Reports.
 *
 * The operational workflow already owns the authoritative expected-return and
 * physical-return rules in LateReturnService. This class only turns those
 * dates into reporting states so the two modules cannot classify the same
 * custody transaction differently.
 */
class ReturnMetricsService
{
    public const RETURNED_ON_TIME = 'RETURNED_ON_TIME';
    public const RETURNED_LATE = 'RETURNED_LATE';
    public const CURRENTLY_OVERDUE = 'CURRENTLY_OVERDUE';
    public const ON_CUSTODY = 'ON_CUSTODY';

    public function __construct(private readonly LateReturnService $lateReturns) {}

    /** @return array{released:float,returned:float,outstanding:float} */
    public function quantities(CustodyTransaction $custody): array
    {
        $custody->loadMissing('lines');

        $released = (float) $custody->lines->sum(
            fn ($line): float => (float) $line->actual_released_quantity
        );

        $returned = (float) $custody->lines->sum(
            fn ($line): float => (float) $line->returned_quantity
        );

        return [
            'released' => $released,
            'returned' => $returned,
            'outstanding' => max(0.0, $released - $returned),
        ];
    }

    /**
     * Date on which the full custody physically came back.
     *
     * New records use the same authoritative source as LateReturnService:
     * Return Inspection for non-linen and the Laundry receipt for linen.
     * closed_at is retained only as a legacy fallback for older, fully
     * returned rows that predate physical-receipt recording.
     */
    public function completionMoment(CustodyTransaction $custody): ?Carbon
    {
        $custody->loadMissing(['returns', 'laundryJob', 'lines.requestItem.inventoryItem']);

        $quantities = $this->quantities($custody);

        /*
         * Outstanding quantity only disqualifies completion when this custody
         * actually has a quantity-level return event on record - a Return
         * Inspection receipt or a Laundry job. That is a return genuinely still
         * in progress, so it must never be treated as completed just because a
         * closure timestamp exists.
         *
         * Older/legacy custody rows (and fixtures) can be fully CLOSED without
         * ever gaining one of those rows, because completion was recorded at
         * the transaction level instead of per line. Those rows are still
         * allowed to use their physical receipt or closed_at fallback below.
         * This is the legacy compatibility promised by this service without
         * weakening the rules for a return actively being processed.
         */
        $hasQuantityLevelReturnEvent = $custody->returns->isNotEmpty() || $custody->laundryJob !== null;

        if ($hasQuantityLevelReturnEvent && $quantities['outstanding'] > 0) {
            return null;
        }

        if ($this->lateReturns->isLinen($custody)) {
            $moment = $custody->laundryJob?->worker_received_at;
        } else {
            $moment = $custody->returns
                ->filter(fn ($return): bool => $return->received_at !== null)
                ->max('received_at');
        }

        if ($moment) {
            return Carbon::parse($moment);
        }

        return $custody->closed_at ? Carbon::parse($custody->closed_at) : null;
    }

    public function completionDate(CustodyTransaction $custody): ?Carbon
    {
        return $this->completionMoment($custody)?->copy()->startOfDay();
    }

    /** The due date the operational late-return workflow itself enforces. */
    public function expectedReturnDate(CustodyTransaction $custody): ?Carbon
    {
        return $this->lateReturns->expectedReturn($custody)?->copy()->startOfDay();
    }

    /**
     * One mutually exclusive return state for one custody transaction.
     */
    public function state(CustodyTransaction $custody): string
    {
        $completion = $this->completionDate($custody);
        $due = $this->expectedReturnDate($custody);

        if ($completion) {
            return $due && $completion->greaterThan($due)
                ? self::RETURNED_LATE
                : self::RETURNED_ON_TIME;
        }

        if ($custody->released_at !== null) {
            if ((string) $custody->status === 'OVERDUE') {
                return self::CURRENTLY_OVERDUE;
            }

            if ($due && now()->startOfDay()->greaterThan($due)) {
                return self::CURRENTLY_OVERDUE;
            }
        }

        return self::ON_CUSTODY;
    }

    public function completedInPeriod(
        CustodyTransaction $custody,
        CarbonInterface $from,
        CarbonInterface $to
    ): bool {
        $completion = $this->completionDate($custody);

        return $completion !== null
            && $completion->betweenIncluded(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay()
            );
    }
}
