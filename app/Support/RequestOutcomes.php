<?php

namespace App\Support;

use App\Enums\RequestStatus;

/**
 * The one mapping from a request's workflow status to its analytical outcome.
 *
 * Request Outcomes is a cohort reading: requests FILED in the selected period,
 * classified by the workflow state they are in NOW. The groups below are
 * decisions and workflow positions only. Release, return and completion are
 * custody facts, not request statuses - a request stays in its approved status
 * after the equipment goes out and comes back - so no such group exists here.
 *
 * WHAT COUNTS AS FILED
 * --------------------
 * The workflow files a request in one step: submit() moves DRAFT (or a
 * RETURNED_FOR_REVISION request being resubmitted) straight to UNDER_SPMU and
 * stamps request_versions.submitted_at. That stamp is the proof of filing.
 * A DRAFT is never in the cohort. SIGNED is not produced by the current
 * workflow and, like scheduledRequestScope(), is not treated as filed unless
 * its version carries a submitted_at.
 *
 * The match in groupFor() has no default arm on purpose: a status added to the
 * enum without a home here fails loudly instead of vanishing from the count.
 */
final class RequestOutcomes
{
    /** Group keys in display order, with their labels. */
    public const GROUPS = [
        'approved' => 'Approved',
        'in_review' => 'In Review',
        'revision' => 'Returned for Revision',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    /** How each group reads inside a sentence. */
    public const PHRASES = [
        'approved' => 'approved',
        'in_review' => 'in review',
        'revision' => 'returned for revision',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        'expired' => 'expired',
    ];

    /**
     * The outcome group a status belongs to, or null for a status that is not
     * a filed request at all.
     */
    public static function groupFor(RequestStatus $status): ?string
    {
        return match ($status) {
            RequestStatus::Draft => null,

            RequestStatus::Signed,
            RequestStatus::Submitted,
            RequestStatus::UnderSpmu,
            RequestStatus::UnderGsu,
            RequestStatus::UnderVpaf => 'in_review',

            RequestStatus::ReturnedForRevision => 'revision',

            RequestStatus::Rejected => 'rejected',

            RequestStatus::FinalApprovedAwaitingDownload,
            RequestStatus::ApprovedReadyForRelease => 'approved',

            RequestStatus::Cancelled => 'cancelled',

            RequestStatus::Expired => 'expired',
        };
    }

    /** @return list<RequestStatus> */
    public static function statusesFor(string $group): array
    {
        return array_values(array_filter(
            RequestStatus::cases(),
            static fn (RequestStatus $status): bool => self::groupFor($status) === $group
        ));
    }

    /**
     * Every status a filed request can currently hold.
     *
     * @return list<RequestStatus>
     */
    public static function filedStatuses(): array
    {
        return array_values(array_filter(
            RequestStatus::cases(),
            static fn (RequestStatus $status): bool => self::groupFor($status) !== null
        ));
    }

    /**
     * Statuses that prove a request was filed even when its version carries
     * no submitted_at (older rows). A request cannot be under review,
     * returned, decided or approved without having been filed. SIGNED,
     * CANCELLED and EXPIRED prove nothing: a draft can be cancelled, and
     * SIGNED precedes submission.
     *
     * @return list<RequestStatus>
     */
    public static function legacyFilingProofStatuses(): array
    {
        return array_values(array_filter(
            self::filedStatuses(),
            static fn (RequestStatus $status): bool => ! in_array($status, [
                RequestStatus::Signed,
                RequestStatus::Cancelled,
                RequestStatus::Expired,
            ], true)
        ));
    }

    public static function label(string $group): string
    {
        return self::GROUPS[$group] ?? $group;
    }
}
