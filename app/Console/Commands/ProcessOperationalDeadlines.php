<?php

namespace App\Console\Commands;

use App\Models\BorrowerRestriction;
use App\Models\CustodyTransaction;
use App\Models\NotificationEvent;
use App\Models\OverdueCase;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\CustodyService;
use App\Services\NotificationService;
use App\Services\OperationalCalendarService;
use Illuminate\Console\Command;

class ProcessOperationalDeadlines extends Command
{
    protected $signature = 'spmu:process-deadlines';

    protected $description = 'Expire pickup reservations, lock prior-day issuance records, and process date-based due/overdue custody records';

    public function handle(
        CustodyService $custodyService,
        NotificationService $notifications,
        AuditService $audit,
        OperationalCalendarService $operationalCalendar
    ): int {
        $pickupExpired = $custodyService->expirePickupWindows();
        $legacyLaundryReconciled = $custodyService->reconcileLegacyLaundryAvailability();
        $issuanceLocked = 0;
        $dueSoon = 0;
        $markedOverdue = 0;
        $overdueProcessed = 0;

        $rate = SystemSetting::value('daily_overdue_tariff');
        $today = now()->startOfDay();
        $tomorrow = now()->addDay()->startOfDay();

        /*
         * Issuance may be corrected on the actual pickup/release day. After
         * that calendar day it becomes read-only automatically.
         */
        CustodyTransaction::query()
            ->whereNotNull('released_at')
            ->whereNull('issuance_locked_at')
            ->whereDate('released_at', '<', $today->toDateString())
            ->each(function (CustodyTransaction $custody) use (&$issuanceLocked, $audit): void {
                $custody->update(['issuance_locked_at' => now()]);
                $audit->record(
                    'ISSUANCE_AUTO_LOCKED',
                    $custody,
                    after: ['issuance_locked_at' => now()->toIso8601String()]
                );
                $issuanceLocked++;
            });

        $openCustodies = CustodyTransaction::query()
            ->with([
                'borrower',
                'lines.requestItem.inventoryItem',
                'laundryJob',
            ])
            ->whereIn('status', [
                'ACTIVE',
                'RETURN_PROCESSING',

                'OVERDUE',
                'INCIDENT_OPEN',
                'OBLIGATION_OPEN',
            ])
            ->whereNotNull('due_at')
            ->get();

        foreach ($openCustodies as $custody) {
            $custody = $operationalCalendar->synchronizeCustodyDueDate($custody, $audit);
            $custody->loadMissing('lines', 'borrower');

            $hasOutstanding = $custody->lines->contains(
                fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
            );

            if (! $hasOutstanding) {
                continue;
            }

            $dueDate = $custody->due_at->copy()->startOfDay();

            /* Due today / due tomorrow reminder. */
            if ($dueDate->isSameDay($today) || $dueDate->isSameDay($tomorrow)) {
                $eventCode = $dueDate->isSameDay($today)
                    ? 'RETURN_DUE_TODAY'
                    : 'RETURN_DUE_TOMORROW';

                $alreadySent = NotificationEvent::query()
                    ->where('event_code', $eventCode)
                    ->where('source_type', $custody->getMorphClass())
                    ->where('source_id', $custody->id)
                    ->exists();

                if (! $alreadySent) {
                    $label = $dueDate->isSameDay($today) ? 'today' : 'tomorrow';
                    $notifications->send(
                        $eventCode,
                        collect([$custody->borrower]),
                        "Custody {$custody->custody_no} is due {$label}, {$custody->due_at->format('F j, Y')}. This is the effective SPMU operational return date; approved closures automatically move the deadline to the next open return day.",
                        $custody
                    );
                    $dueSoon++;
                }
            }

            /*
             * DATE-ONLY late rule:
             * Lateness begins after the effective operational return date. A closed original due date is first moved to the next open SPMU return day.
             */
            if (! $today->gt($dueDate)) {
                continue;
            }

            /*
             * LINEN DOCUMENTARY HOLD
             * ----------------------
             * When every still-outstanding quantity belongs to laundry-required
             * linen, do not declare the borrower overdue merely because the
             * accomplished offline Laundry Form has not reached SPMU yet.
             *
             * - No RECEIVED BY date yet: status stays pending verification.
             * - RECEIVED BY on/before due date: documentary delay is not late.
             * - RECEIVED BY after due date: normal overdue processing continues.
             *
             * If any non-linen property is still outstanding, the ordinary
             * overdue rule still applies immediately.
             */
            $outstandingLines = $custody->lines->filter(
                fn ($line) => (float) $line->returned_quantity < (float) $line->actual_released_quantity
            );

            $onlyLinenOutstanding = $outstandingLines->isNotEmpty()
                && $outstandingLines->every(
                    fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                );

            if ($onlyLinenOutstanding) {
                $physicalLaundryReceipt = $custody->laundryJob?->worker_received_at?->copy()->startOfDay();

                if (! $physicalLaundryReceipt || ! $physicalLaundryReceipt->gt($dueDate)) {
                    /*
                     * Clean up an older scheduler-created provisional overdue
                     * state when this transaction is now known to be a
                     * linen-documentary hold. This does not waive a settled or
                     * manually processed accountability case; only an untouched
                     * automatic OVERDUE case can be reversed here.
                     */
                    $provisionalOverdue = OverdueCase::query()
                        ->where('custody_transaction_id', $custody->id)
                        ->first();

                    $canReturnToPendingVerification = ! $provisionalOverdue
                        || ($provisionalOverdue->status === 'OVERDUE'
                            && ! $provisionalOverdue->penalties()->where('status', '!=', 'VOID')->exists());

                    if ($canReturnToPendingVerification) {
                        if ($provisionalOverdue) {
                            $provisionalOverdue->update([
                                'status' => 'RESOLVED',
                                'accrued_amount' => 0,
                                'sanction_type' => null,
                            ]);
                        }

                        BorrowerRestriction::query()
                            ->where('borrower_user_id', $custody->borrower_user_id)
                            ->whereIn('restriction_type', ['PENDING_RETURN', 'OVERDUE_RETURN'])
                            ->where('status', 'ACTIVE')
                            ->update([
                                'status' => 'LIFTED',
                                'effective_to' => now(),
                            ]);

                        if ($custody->status === 'OVERDUE') {
                            $custody->update([
                                'status' => 'RETURN_PROCESSING',
                                'closed_at' => null,
                            ]);
                        }
                    }

                    continue;
                }
            }

            if ($custody->status !== 'OVERDUE') {
                $custody->update(['status' => 'OVERDUE']);
                $markedOverdue++;
            }

            $daysLate = (int) $dueDate->diffInDays($today);
            $case = OverdueCase::query()->firstOrNew([
                'custody_transaction_id' => $custody->id,
            ]);
            $isNew = ! $case->exists;
            /*
             * A policy revision must affect new cases only. Existing cases
             * retain the rate captured when they first became overdue; their
             * accrued total continues to grow only because another late day
             * elapsed, not because an administrator changed today's tariff.
             */
            $rateSnapshot = $case->exists && is_numeric($case->rate_snapshot)
                ? (float) $case->rate_snapshot
                : (is_numeric($rate) ? (float) $rate : null);

            $case->fill([
                'borrower_user_id' => $custody->borrower_user_id,
                /* Legacy non-null field retained; no grace changes late status. */
                'grace_expires_at' => $custody->due_at,
                'overdue_started_at' => $custody->due_at->copy()->addDay()->startOfDay(),
                'offense_level' => $case->offense_level ?: 1,
                'rate_snapshot' => $rateSnapshot,
                'accrued_amount' => $rateSnapshot !== null
                    ? round($daysLate * $rateSnapshot, 2)
                    : 0,
                'sanction_type' => null,
                'status' => 'OVERDUE',
            ])->save();

            BorrowerRestriction::query()->updateOrCreate(
                [
                    'borrower_user_id' => $custody->borrower_user_id,
                    'restriction_type' => 'PENDING_RETURN',
                    'status' => 'ACTIVE',
                ],
                [
                    'reason' => "Issued property under {$custody->custody_no} remains outstanding after the Expected Return Date.",
                    'effective_from' => $custody->due_at->copy()->addDay()->startOfDay(),
                    'imposed_by_user_id' => null,
                ]
            );

            if ($isNew) {
                $notifications->send(
                    'BORROWING_OVERDUE',
                    collect([$custody->borrower]),
                    "Custody {$custody->custody_no} is late by {$daysLate} calendar day(s). Please return the outstanding property to SPMU.",
                    $custody
                );

                $audit->record(
                    'CUSTODY_MARKED_OVERDUE',
                    $case,
                    after: [
                        'effective_return_date' => $dueDate->toDateString(),
                        'original_expected_return_date' => $custody->original_due_at?->toDateString(),
                        'current_date' => $today->toDateString(),
                        'days_late' => $daysLate,
                        'rate_snapshot' => $rateSnapshot,
                        'sanction_auto_imposed' => false,
                    ]
                );
            }

            $overdueProcessed++;
        }

        $this->info(
            "Processed {$pickupExpired} pickup expiration(s), "
            ."{$legacyLaundryReconciled} legacy Laundry availability reconciliation(s), "
            ."{$issuanceLocked} issuance auto-lock(s), "
            ."{$dueSoon} due reminder(s), "
            ."{$markedOverdue} newly overdue custody record(s), "
            ."and {$overdueProcessed} open overdue record(s)."
        );

        return self::SUCCESS;
    }
}
