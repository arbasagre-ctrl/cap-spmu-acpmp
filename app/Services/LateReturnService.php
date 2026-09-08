<?php

namespace App\Services;

use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The late-return lifecycle.
 *
 * OVERDUE and LATE RETURN are different states and are never merged:
 *
 *   OVERDUE       the item is past its effective return date and has NOT been
 *                 returned. The amount shown is an estimate that grows daily.
 *
 *   LATE RETURN   the item HAS been returned, after its effective return date.
 *                 The late days and the fee are frozen at the physical return
 *                 date and never move again.
 *
 * This service does not perform returns. It reads the authoritative result of
 * the existing return workflows and classifies it:
 *
 *   linen      the Laundry Personnel receipt (laundry_jobs.worker_received_at)
 *   otherwise  the Return Inspection (return_transactions.received_at)
 *
 * For linen the Action Officer's later attestation of the accomplished Laundry
 * Form is a document step, not a physical return, so it must never add late
 * days. A borrower is not charged for internal document forwarding.
 */
class LateReturnService
{
    /** Awaiting the physical return. The fee shown is an estimate. */
    public const STATUS_OVERDUE = 'OVERDUE';

    /**
     * Returned late, waiting for the Action Officer to confirm the assessment.
     *
     * The existing status name is kept so historical rows stay readable; the
     * presentation label is "Late Return - For AO Confirmation".
     */
    public const STATUS_FOR_AO_CONFIRMATION = 'RETURNED_PENDING_SETTLEMENT';

    /** Confirmed by the Action Officer, waiting for the SPMU Head. */
    public const STATUS_FOR_HEAD_APPROVAL = 'FOR_HEAD_APPROVAL';

    /** Head-approved: the Late Return Fee Form exists and payment is due. */
    public const STATUS_AWAITING_PAYMENT = 'BILLED';

    /** Settled or formally waived. */
    public const STATUS_RESOLVED = 'RESOLVED';

    /** Presentation labels for each stage of the lifecycle. */
    public const LABELS = [
        self::STATUS_OVERDUE => 'Overdue - Item Not Returned',
        self::STATUS_FOR_AO_CONFIRMATION => 'Late Return - For AO Confirmation',
        self::STATUS_FOR_HEAD_APPROVAL => 'For Head Approval',
        self::STATUS_AWAITING_PAYMENT => 'Approved - Awaiting Payment',
        self::STATUS_RESOLVED => 'Resolved',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    public static function label(?string $status): string
    {
        return self::LABELS[(string) $status] ?? (string) $status;
    }

    /* ------------------------------------------------------------------ */
    /* The authoritative physical return date                              */
    /* ------------------------------------------------------------------ */

    /**
     * When the property physically came back, and which workflow said so.
     *
     * Linen is answered by Laundry Operations because the borrower hands linen
     * to the Laundry Area, not to the Action Officer. Everything else is
     * answered by the Return Inspection.
     *
     * @return array{0: ?Carbon, 1: ?string}
     */
    public function actualReturn(CustodyTransaction $custody): array
    {
        $custody->loadMissing(['lines.requestItem.inventoryItem', 'laundryJob', 'returns']);

        if ($this->isLinen($custody)) {
            $receivedAt = $custody->laundryJob?->worker_received_at;

            if ($receivedAt) {
                return [Carbon::parse($receivedAt)->startOfDay(), 'LAUNDRY_RECEIPT'];
            }

            /*
             * Linen with no recorded Laundry receipt has not completed its
             * physical return path yet, even if a return row exists.
             */
            return [null, null];
        }

        $receivedAt = $custody->returns
            ->filter(fn ($return): bool => $return->received_at !== null)
            ->max('received_at');

        return $receivedAt
            ? [Carbon::parse($receivedAt)->startOfDay(), 'RETURN_INSPECTION']
            : [null, null];
    }

    /**
     * Record the Laundry RECEIVED BY date the Action Officer read off the
     * accomplished Laundry Form.
     *
     * This is only for linen whose receipt was never captured digitally by
     * Laundry Personnel. It is the date written and wet-signed on the form,
     * never the date the form reached SPMU and never today.
     *
     * An existing digital receipt always wins and is never overwritten.
     */
    public function recordLaundryReceipt(
        CustodyTransaction $custody,
        Carbon $receivedOn,
        User $officer
    ): void {
        $custody->loadMissing('laundryJob');
        $job = $custody->laundryJob;

        if (! $job) {
            throw ValidationException::withMessages([
                'laundry_received_date' => 'This custody has no Laundry job, so a Laundry receipt date cannot be recorded.',
            ]);
        }

        /* Laundry Personnel are authoritative; their record is never replaced. */
        if ($job->worker_received_at !== null) {
            return;
        }

        if ($receivedOn->isFuture()) {
            throw ValidationException::withMessages([
                'laundry_received_date' => 'The Laundry Received Date cannot be in the future.',
            ]);
        }

        if ($custody->released_at && $receivedOn->lt(Carbon::parse($custody->released_at)->startOfDay())) {
            throw ValidationException::withMessages([
                'laundry_received_date' => 'The Laundry Received Date cannot be earlier than the release date.',
            ]);
        }

        $job->update(['worker_received_at' => $receivedOn]);

        $this->audit->record('LAUNDRY_RECEIVED_DATE_ATTESTED', $job, after: [
            'laundry_received_at' => $receivedOn->toDateString(),
            'source' => 'ACCOMPLISHED_LAUNDRY_FORM',
            'attested_by_user_id' => $officer->id,
            'attested_at' => now()->toDateTimeString(),
            'custody_no' => $custody->custody_no,
        ]);
    }

    /** Does this custody carry any linen? */
    public function isLinen(CustodyTransaction $custody): bool
    {
        $custody->loadMissing('lines.requestItem.inventoryItem');

        return $custody->lines->contains(
            fn ($line): bool => (bool) $line->requestItem?->inventoryItem?->laundry_required
        );
    }

    /**
     * Linen whose Laundry receipt date is still unknown.
     *
     * Until it is known there is no authoritative physical return date, so the
     * case cannot leave the overdue stage.
     */
    public function needsLaundryReceiptDate(CustodyTransaction $custody): bool
    {
        if (! $this->isLinen($custody)) {
            return false;
        }

        $custody->loadMissing('laundryJob');

        return $custody->laundryJob?->worker_received_at === null;
    }

    /** The effective expected return date this custody is measured against. */
    public function expectedReturn(CustodyTransaction $custody): ?Carbon
    {
        return $custody->due_at ? Carbon::parse($custody->due_at)->startOfDay() : null;
    }

    /* ------------------------------------------------------------------ */
    /* Classification                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Classify a custody once its physical return has been recorded.
     *
     * Called by the return workflows. Returns the case when the return was
     * late, and null when it was on time or is not yet fully returned - an
     * on-time return never opens a late-return case.
     */
    public function assess(CustodyTransaction $custody, ?User $actor = null): ?OverdueCase
    {
        $custody->loadMissing('lines');

        $fullyReturned = $custody->lines->isNotEmpty() && $custody->lines->every(
            fn ($line): bool => (float) $line->returned_quantity >= (float) $line->actual_released_quantity
        );

        if (! $fullyReturned) {
            return null;
        }

        [$actualReturn, $source] = $this->actualReturn($custody);
        $expected = $this->expectedReturn($custody);

        if (! $actualReturn || ! $expected) {
            return null;
        }

        $case = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->first();

        /* On time: no late-return accountability, and any case opened while it
           was merely overdue is closed rather than escalated. */
        if ($actualReturn->lessThanOrEqualTo($expected)) {
            if ($case && in_array($case->status, [self::STATUS_OVERDUE, self::STATUS_FOR_AO_CONFIRMATION], true)) {
                $case->update([
                    'actual_return_date' => $actualReturn->toDateString(),
                    'return_date_source' => $source,
                    'late_days' => 0,
                    'accrued_amount' => 0,
                    'status' => self::STATUS_RESOLVED,
                ]);

                $this->audit->record('LATE_RETURN_NOT_APPLICABLE', $case, after: [
                    'expected_return_date' => $expected->toDateString(),
                    'actual_return_date' => $actualReturn->toDateString(),
                    'return_date_source' => $source,
                ]);
            }

            return null;
        }

        $lateDays = (int) $expected->diffInDays($actualReturn);
        $rate = SystemSetting::value('daily_overdue_tariff');
        $amount = is_numeric($rate) ? round($lateDays * (float) $rate, 2) : 0;

        $case = $case ?: OverdueCase::query()->make([
            'custody_transaction_id' => $custody->id,
            'grace_expires_at' => $custody->due_at,
            'offense_level' => 1,
        ]);

        /* Already past confirmation: the assessment is settled, not re-opened. */
        if ($case->exists && ! in_array($case->status, [
            self::STATUS_OVERDUE,
            self::STATUS_FOR_AO_CONFIRMATION,
        ], true)) {
            return $case;
        }

        $case->fill([
            'borrower_user_id' => $custody->borrower_user_id,
            'overdue_started_at' => $case->overdue_started_at
                ?: $expected->copy()->addDay()->startOfDay(),
            'actual_return_date' => $actualReturn->toDateString(),
            'return_date_source' => $source,
            'late_days' => $lateDays,
            'rate_snapshot' => is_numeric($rate) ? (float) $rate : null,
            'accrued_amount' => $amount,
            'status' => self::STATUS_FOR_AO_CONFIRMATION,
        ])->save();

        $this->audit->record('LATE_RETURN_DETECTED', $case, after: [
            'expected_return_date' => $expected->toDateString(),
            'actual_return_date' => $actualReturn->toDateString(),
            'return_date_source' => $source,
            'late_days' => $lateDays,
            'rate_snapshot' => is_numeric($rate) ? (float) $rate : null,
            'amount' => $amount,
        ]);

        $this->notifications->send(
            'LATE_RETURN_FOR_CONFIRMATION',
            $this->actionOfficers(),
            "Custody {$custody->custody_no} was returned {$lateDays} day(s) after its expected return date. "
                .'Confirm the late-return assessment so it can go to the SPMU Head.',
            $case
        );

        /*
         * The physical property is back, so the outstanding-property
         * restriction is finished; the fee remains a separate obligation.
         */
        BorrowerRestriction::query()->firstOrCreate(
            [
                'borrower_user_id' => $custody->borrower_user_id,
                'restriction_type' => 'OVERDUE_RETURN',
                'status' => 'ACTIVE',
            ],
            [
                'reason' => 'Late return under '.$custody->custody_no.' is awaiting accountability settlement.',
                'effective_from' => now(),
                'imposed_by_user_id' => $actor?->id,
            ]
        );

        return $case;
    }

    /* ------------------------------------------------------------------ */
    /* Action Officer confirmation                                         */
    /* ------------------------------------------------------------------ */

    /**
     * The Action Officer confirms the recorded return date and the system's
     * late classification. This is the only route to Head approval.
     */
    public function confirm(OverdueCase $case, User $officer): OverdueCase
    {
        /* An unreturned item can never be confirmed as returned late. */
        if ($case->actual_return_date === null) {
            throw ValidationException::withMessages([
                'overdue' => 'The item has not been returned yet. Record the physical return before confirming a late return.',
            ]);
        }

        if ($case->status === self::STATUS_OVERDUE) {
            throw ValidationException::withMessages([
                'overdue' => 'This case is still awaiting the physical return.',
            ]);
        }

        /* Confirming twice is a no-op rather than a second forward. */
        if ($case->status !== self::STATUS_FOR_AO_CONFIRMATION) {
            return $case;
        }

        DB::transaction(function () use ($case, $officer): void {
            $case->update([
                'ao_confirmed_by_user_id' => $officer->id,
                'ao_confirmed_at' => now(),
                'correction_remarks' => null,
                'status' => self::STATUS_FOR_HEAD_APPROVAL,
            ]);

            $this->audit->record('LATE_RETURN_CONFIRMED_BY_AO', $case, after: [
                'expected_return_date' => $case->grace_expires_at?->toDateString(),
                'actual_return_date' => $case->actual_return_date,
                'late_days' => $case->late_days,
                'amount' => (float) $case->accrued_amount,
            ]);
        }, 3);

        $this->notifications->send(
            'LATE_RETURN_FOR_HEAD_APPROVAL',
            $this->spmuHeads(),
            "A late-return assessment for {$case->custody?->custody_no} is ready for approval: "
                ."{$case->late_days} late day(s).",
            $case
        );

        return $case->fresh();
    }

    /**
     * The SPMU Head sends an assessment back to the Action Officer.
     *
     * The case is preserved with its history; only the stage moves back.
     */
    public function returnForCorrection(OverdueCase $case, User $head, string $remarks): OverdueCase
    {
        if ($case->status !== self::STATUS_FOR_HEAD_APPROVAL) {
            throw ValidationException::withMessages([
                'overdue' => 'Only an assessment waiting for Head approval can be returned for correction.',
            ]);
        }

        $case->update([
            'status' => self::STATUS_FOR_AO_CONFIRMATION,
            'ao_confirmed_by_user_id' => null,
            'ao_confirmed_at' => null,
            'correction_remarks' => $remarks,
        ]);

        $this->audit->record('LATE_RETURN_RETURNED_FOR_CORRECTION', $case, reason: $remarks);

        $this->notifications->send(
            'LATE_RETURN_FOR_CONFIRMATION',
            $this->actionOfficers(),
            "The late-return assessment for {$case->custody?->custody_no} was returned for correction: {$remarks}",
            $case
        );

        return $case->fresh();
    }

    /* ------------------------------------------------------------------ */
    /* Presentation helpers                                                */
    /* ------------------------------------------------------------------ */

    /**
     * What the case is worth right now.
     *
     * While overdue the amount is an estimate that grows each day. Once the
     * item is back the frozen figures are returned unchanged - today's date is
     * deliberately not consulted.
     *
     * @return array<string, mixed>
     */
    public function assessment(OverdueCase $case): array
    {
        $expected = $case->grace_expires_at
            ? Carbon::parse($case->grace_expires_at)->startOfDay()
            : null;

        $rate = $case->rate_snapshot !== null
            ? (float) $case->rate_snapshot
            : (is_numeric($raw = SystemSetting::value('daily_overdue_tariff')) ? (float) $raw : null);

        if ($case->actual_return_date !== null) {
            return [
                'is_estimate' => false,
                'expected_return_date' => $expected,
                'actual_return_date' => Carbon::parse($case->actual_return_date),
                'return_date_source' => $case->return_date_source,
                'late_days' => (int) $case->late_days,
                'rate' => $rate,
                'amount' => (float) $case->accrued_amount,
            ];
        }

        $lateDays = $expected ? max(0, (int) $expected->diffInDays(now()->startOfDay())) : 0;

        return [
            'is_estimate' => true,
            'expected_return_date' => $expected,
            'actual_return_date' => null,
            'return_date_source' => null,
            'late_days' => $lateDays,
            'rate' => $rate,
            'amount' => $rate !== null ? round($lateDays * $rate, 2) : 0.0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Recipients                                                          */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Support\Collection<int, User> */
    private function actionOfficers()
    {
        return User::query()
            ->where('access_classification', \App\Enums\AccessClassification::SpmuOfficer)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function spmuHeads()
    {
        return User::query()
            ->where('access_classification', \App\Enums\AccessClassification::SpmuHead)
            ->get();
    }
}
