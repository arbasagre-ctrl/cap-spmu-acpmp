<?php

namespace App\Reports;

use App\Models\BorrowingRequest;

/**
 * The status a borrowing request should be reported under.
 *
 * A request record intentionally keeps its approval-era status (such as
 * APPROVED_READY_FOR_RELEASE) after approval, while the physical workflow
 * carries on inside the custody transaction. Reporting the request column
 * directly would therefore show "Ready for Release" for a transaction that
 * has already been released, returned, or closed.
 *
 * The rule, unchanged from the original ReportController implementation and
 * now shared so the legacy path and the new dataset builders cannot drift:
 * once a custody transaction exists, custody is authoritative.
 */
final class OperationalStatus
{
    /**
     * @return array{0:string, 1:?string}  [status code, display label or null]
     */
    public static function forRequest(BorrowingRequest $row): array
    {
        $custody = $row->custody;

        if ($custody) {
            if ($custody->status === 'CLOSED' || $custody->closed_at !== null) {
                return ['COMPLETED', 'Completed'];
            }

            return match ($custody->status) {
                'ACTIVE' => ['ACTIVE', 'Released / On Custody'],

                'RETURN_PROCESSING',
                'PARTIALLY_RETURNED' => ['RETURN_PROCESSING', 'Return Processing'],

                'OVERDUE' => ['OVERDUE', 'Overdue'],

                'INCIDENT_OPEN' => ['INCIDENT_OPEN', 'Incident Open'],

                'OBLIGATION_OPEN' => ['OBLIGATION_OPEN', 'Obligation Open'],

                'PREPARING_RELEASE' => $custody->scheduled_release_at
                    ? ['PICKUP_SCHEDULED', 'Pickup Scheduled']
                    : ['PREPARING_RELEASE', 'Ready for Release'],

                default => [(string) $custody->status, null],
            };
        }

        $requestStatus = $row->status->value;

        return match ($requestStatus) {
            'FINAL_APPROVED_AWAITING_DOWNLOAD' => ['APPROVED_READY_FOR_RELEASE', 'Ready for Release'],

            default => [$requestStatus, null],
        };
    }

    /** The human-readable label for a reported status. */
    public static function label(string $status, ?string $label = null): string
    {
        return $label ?: str($status)->replace('_', ' ')->title()->toString();
    }
}
