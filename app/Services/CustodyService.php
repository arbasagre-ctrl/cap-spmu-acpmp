<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\EarlyReturnRequest;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\LaundryRecord;
use App\Models\NotificationEvent;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustodyService
{
    public function __construct(
        private DocumentService $documents,
        private AuditService $audit,
        private NotificationService $notifications,
        private OperationalCalendarService $operationalCalendar,
        private LateReturnService $lateReturns,
        private PolicyService $policy,
    ) {}

    /**
     * Create the current pickup/custody record immediately after SPMU
     * approval and inventory reservation.
     *
     * When the Operational Calendar provides a valid Pickup / Issuance
     * window, the schedule becomes active automatically. No Action Officer
     * confirmation is required. The initial borrower-facing schedule is sent
     * by the REQUEST_APPROVED notification so this method records activation
     * only and must not emit a second PICKUP_SCHEDULED notification. If no
     * valid window can be generated, the record remains for SPMU exception handling.
     */
    public function ensurePickupRecord(
        BorrowingRequest $request,
        User $actor,
        ?CarbonImmutable $pickupStart = null,
        ?CarbonImmutable $pickupEnd = null
    ): CustodyTransaction {
        return DB::transaction(function () use ($request, $actor, $pickupStart, $pickupEnd): CustodyTransaction {
            $request = BorrowingRequest::query()
                ->with([
                    'currentVersion.items.allocation',
                ])
                ->lockForUpdate()
                ->findOrFail($request->id);

            $version = $request->currentVersion;

            if (! $version) {
                throw ValidationException::withMessages([
                    'custody' => 'The approved request version could not be found.',
                ]);
            }

            $dueAt = $version->return_due_at;

            if (! $dueAt && $version->return_date) {
                $timezone = config('app.timezone') ?: 'Asia/Manila';
                $dueAt = CarbonImmutable::parse(
                    $version->return_date,
                    $timezone
                )->endOfDay();
            }

            if (! $dueAt) {
                throw ValidationException::withMessages([
                    'custody' => 'The approved Expected Return Date could not be found.',
                ]);
            }

            $custody = CustodyTransaction::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                ],
                [
                    'custody_no' => 'CUS-'
                        .now()->format('Ymd')
                        .'-'
                        .str_pad(
                            (string) $request->id,
                            5,
                            '0',
                            STR_PAD_LEFT
                        ),
                    'request_version_id' => $version->id,
                    'borrower_user_id' => $request->borrower_user_id,
                    'status' => 'PREPARING_RELEASE',

                    // System-generated Pickup / Issuance window. A valid
                    // Operational Calendar window becomes active immediately.
                    'scheduled_release_at' => $pickupStart,
                    'pickup_expires_at' => $pickupEnd,
                    'pickup_expired_at' => null,
                    'pickup_scheduled_by_user_id' => null,
                    'pickup_scheduled_at' => ($pickupStart && $pickupEnd) ? now() : null,

                    'due_at' => $dueAt,
                    'original_due_at' => $dueAt,
                    'due_adjustment_reason' => null,
                    'due_adjusted_at' => null,
                    'prepared_by_user_id' => null,
                    'prepared_at' => null,
                    'released_by_user_id' => null,
                    'released_at' => null,
                    'acknowledged_at' => null,
                    'closed_at' => null,
                ]
            );

            // firstOrCreate may already persist pickup_scheduled_at on a brand-new
            // automatically scheduled custody record. Track first activation for audit only;
            // The REQUEST_APPROVED notification already contains the initial pickup schedule.
            $automaticScheduleActivated = (bool) (
                $custody->wasRecentlyCreated
                && $pickupStart
                && $pickupEnd
            );

            if ($pickupStart && $pickupEnd && ! $custody->released_at) {
                $automaticScheduleActivated = $automaticScheduleActivated
                    || ! $custody->pickup_scheduled_at;

                $custody->update([
                    'scheduled_release_at' => $pickupStart,
                    'pickup_expires_at' => $pickupEnd,
                    'pickup_expired_at' => null,
                    'pickup_scheduled_by_user_id' => null,
                    'pickup_scheduled_at' => $custody->pickup_scheduled_at ?: now(),
                ]);
            }

            $custody = $this->operationalCalendar->synchronizeCustodyDueDate(
                $custody,
                $this->audit
            );

            foreach ($version->items as $item) {
                $allocation = $item->allocation;

                if (! $allocation || $allocation->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'custody' => 'The approved inventory reservation is incomplete. Re-run SPMU verification before preparing release.',
                    ]);
                }

                $approvedQuantity = (float) (
                    $item->approved_quantity
                    ?? $allocation->allocated_quantity
                );

                if ($approvedQuantity <= 0) {
                    throw ValidationException::withMessages([
                        'custody' => 'An approved custody quantity must be greater than zero.',
                    ]);
                }

                $custody->lines()->firstOrCreate(
                    [
                        'request_item_id' => $item->id,
                    ],
                    [
                        'allocation_id' => $allocation->id,
                        'approved_quantity' => $approvedQuantity,
                        'quantity_to_receive' => $approvedQuantity,
                        'actual_released_quantity' => 0,
                        'returned_quantity' => 0,
                    ]
                );
            }

            $custody = $custody->fresh([
                'lines.requestItem.inventoryItem',
                'lines.requestItem',
                'borrower',
                'request.currentVersion',
            ]);

            if ($automaticScheduleActivated && $custody->borrower) {
                $this->audit->record(
                    'PICKUP_SCHEDULE_AUTOMATICALLY_ACTIVATED',
                    $custody,
                    after: [
                        'pickup_at' => $pickupStart->toIso8601String(),
                        'pickup_expires_at' => $pickupEnd->toIso8601String(),
                        'source' => 'SPMU_OPERATIONAL_CALENDAR',
                        'approval_actor_user_id' => $actor->id,
                    ]
                );

                // Do not notify here. REQUEST_APPROVED is the single initial
                // borrower notification and already includes this pickup schedule.
                // PICKUP_SCHEDULED is reserved for a later schedule update/reschedule.
            }

            return $custody;
        }, 3);
    }

    /**
     * Expire pickup claim windows that were not completed before the
     * configured cutoff.
     *
     * Expiring the pickup window does not cancel the approved request or
     * release its inventory reservation. It only closes the current claim
     * window so the SPMU Action Officer can schedule a new pickup window.
     * A previously confirmed preparation remains valid because the approved
     * reservation is still held; rescheduling does not require duplicate quantity entry.
     */
    public function expirePickupWindows(): int
    {
        /*
         * Repair a missing pickup-missed notification for an already-expired
         * unreleased transaction before processing new expirations. This is
         * intentionally limited to PICKUP_EXPIRED and retries each required
         * channel at most once after its first failed/missing attempt.
         */
        $this->repairPickupExpiredNotifications();

        $expired = 0;

        CustodyTransaction::query()
            ->where('status', 'PREPARING_RELEASE')
            ->whereNull('released_at')
            ->whereNotNull('pickup_expires_at')
            ->whereNotNull('pickup_scheduled_at')
            ->whereNull('pickup_expired_at')
            ->where('pickup_expires_at', '<', now())
            ->orderBy('id')
            ->each(function (CustodyTransaction $custody) use (&$expired): void {
                DB::transaction(function () use ($custody, &$expired): void {
                    $locked = CustodyTransaction::query()
                        ->lockForUpdate()
                        ->find($custody->id);

                    if (
                        ! $locked
                        || $locked->status !== 'PREPARING_RELEASE'
                        || $locked->released_at
                        || ! $locked->pickup_expires_at
                        || $locked->pickup_expired_at
                        || $locked->pickup_expires_at->gte(now())
                    ) {
                        return;
                    }

                    /*
                     * A pickup window that passes while SPMU is resolving a
                     * preparation/inventory discrepancy is NOT a borrower
                     * missed pickup. Keep the approved request and reservation
                     * on hold and let the Head/Admin finish the inventory
                     * review. No automatic reschedule is created because the
                     * pickup schedule is already part of the approved packet.
                     */
                    if ($this->hasPreparationExceptionPendingRelease($locked)) {
                        $alreadyRecorded = AuditEvent::query()
                            ->where('record_type', CustodyTransaction::class)
                            ->where('record_id', $locked->id)
                            ->where('action_code', 'PICKUP_HELD_FOR_PREPARATION_ISSUE')
                            ->exists();

                        if (! $alreadyRecorded) {
                            $this->audit->record(
                                'PICKUP_HELD_FOR_PREPARATION_ISSUE',
                                $locked,
                                after: [
                                    'pickup_expires_at' => $locked->pickup_expires_at->toIso8601String(),
                                    'borrower_missed_pickup' => false,
                                    'reservation_released' => false,
                                    'reason' => 'Open inventory discrepancy during item preparation',
                                ]
                            );

                            $locked->loadMissing('borrower');

                            if ($locked->borrower) {
                                $this->notifications->send(
                                    'PICKUP_HELD_PREPARATION_ISSUE',
                                    collect([$locked->borrower]),
                                    "Your scheduled pickup for {$locked->custody_no} could not proceed because SPMU is resolving an inventory discrepancy found during item preparation. This is not recorded as a missed pickup. Your approved request remains on hold while SPMU completes the review.",
                                    $locked,
                                    ['SYSTEM', 'EMAIL'],
                                    ['SYSTEM', 'EMAIL']
                                );
                            }
                        }

                        return;
                    }

                    $expiredAt = now();

                    $locked->update([
                        'pickup_expired_at' => $expiredAt,
                    ]);

                    $this->audit->record(
                        'PICKUP_WINDOW_EXPIRED',
                        $locked,
                        after: [
                            'pickup_expires_at' => $locked->pickup_expires_at->toIso8601String(),
                            'pickup_expired_at' => $expiredAt->toIso8601String(),
                            'reservation_released' => false,
                            'requires_rescheduling' => true,
                            'preparation_preserved' => (bool) $locked->prepared_at,
                        ]
                    );

                    $locked->loadMissing('borrower');

                    if ($locked->borrower) {
                        $dueAt = $locked->original_due_at ?: $locked->due_at;
                        $nextPickup = $this->operationalCalendar->nextPickupWindow($expiredAt);
                        $canStillReschedule = false;

                        if ($dueAt && $nextPickup) {
                            $dueDay = CarbonImmutable::parse($dueAt, $expiredAt->timezone)->startOfDay();
                            $canStillReschedule = $nextPickup->startOfDay()->lt($dueDay);
                        }

                        $pickupPassedMessage = $canStillReschedule
                            ? "Your pickup schedule for {$locked->custody_no} has passed. Open My Borrowings and choose Request Reschedule if you still need the items, or Cancel Request if you no longer need them. If no action is taken within the allowed response period, the unreleased request will be cancelled automatically and the reserved quantity will return to available inventory."
                            : "Your pickup schedule for {$locked->custody_no} has passed and no valid replacement pickup remains before the approved Expected Return Date. The unreleased request will be cancelled automatically and the reserved quantity will return to available inventory.";

                        $this->notifications->send(
                            'PICKUP_EXPIRED',
                            collect([$locked->borrower]),
                            $pickupPassedMessage,
                            $locked,
                            ['SYSTEM', 'EMAIL'],
                            ['SYSTEM', 'EMAIL']
                        );
                    }

                    $expired++;
                }, 3);
            });

        return $expired;
    }

    /**
     * Reconcile older LaundryJob records still sitting in
     * TURNED_OVER_TO_LAUNDRY whose accomplished Laundry Form has since been
     * verified.
     *
     * The normal path completes a LaundryJob and restores serviceable linen
     * (LAUNDRY -> AVAILABLE, via laundry_job_lines.completed_quantity, the
     * same field InventoryService::availability() reads) inside
     * CustodyService::receiveReturn() itself, at the moment SPMU encodes
     * the physical return. If the accomplished form is instead verified
     * AFTER that return was already encoded, nothing else ever re-runs
     * that completion, so the job is stuck. See LaundryJob's own doc
     * comment ("TURNED_OVER_TO_LAUNDRY is retained only as a legacy data
     * state and is reconciled automatically") and
     * ConditionalProcessingController::gatePass()'s matching comment on
     * this exact one-time reconciliation.
     */
    public function reconcileLegacyLaundryAvailability(): int
    {
        $reconciled = 0;

        LaundryJob::query()
            ->where('status', 'TURNED_OVER_TO_LAUNDRY')
            ->whereNotNull('latest_evidence_submission_id')
            ->whereNotNull('form_verified_at')
            ->orderBy('id')
            ->each(function (LaundryJob $job) use (&$reconciled): void {
                DB::transaction(function () use ($job, &$reconciled): void {
                    $locked = LaundryJob::query()->lockForUpdate()->find($job->id);

                    if (! $locked
                        || $locked->status !== 'TURNED_OVER_TO_LAUNDRY'
                        || ! $locked->hasVerifiedAccomplishedForm()) {
                        return;
                    }

                    $locked->loadMissing([
                        'lines.custodyLine.returnLines',
                        'custody',
                    ]);

                    $totalServiceable = 0;
                    $serviceableByLine = [];

                    foreach ($locked->lines as $jobLine) {
                        $received = (float) $jobLine->custodyLine->returnLines
                            ->where('disposition_state', 'LAUNDRY')
                            ->sum('quantity_received');
                        $received = (int) round($received);
                        $serviceableByLine[$jobLine->id] = $received;
                        $totalServiceable += $received;
                    }

                    foreach ($locked->lines as $jobLine) {
                        $serviceable = (int) ($serviceableByLine[$jobLine->id] ?? 0);
                        $hasAdverseFinding = $jobLine->custodyLine->returnLines->contains(
                            fn ($returnLine) => strtoupper((string) $returnLine->condition_code) !== 'FINE'
                        );

                        $jobLine->update(['completed_quantity' => $serviceable]);

                        $jobLine->custodyLine->update([
                            'item_status' => $hasAdverseFinding ? 'INCIDENT_PENDING' : 'RETURNED',
                            'compliance_status' => 'LAUNDRY_COMPLETED',
                        ]);
                    }

                    $before = ['status' => $locked->status];

                    $locked->update([
                        'status' => 'LAUNDRY_COMPLETED',
                        'ready_at' => $locked->worker_completed_at ?: now(),
                        'completed_at' => now(),
                    ]);

                    $this->audit->record(
                        'LAUNDRY_LEGACY_AVAILABILITY_RECONCILED',
                        $locked,
                        before: $before,
                        after: [
                            'status' => 'LAUNDRY_COMPLETED',
                            'serviceable_quantity' => $totalServiceable,
                        ]
                    );

                    if ($locked->custody) {
                        $this->reconcileTransactionStatus($locked->custody);
                    }

                    $reconciled++;
                }, 3);
            });

        return $reconciled;
    }

    /**
     * Ensure an expired, unreleased pickup has a borrower-facing in-system
     * notice and an email attempt. Notification preferences do not suppress
     * this operational missed-pickup notice. A failed channel gets at most one
     * automatic retry so a broken mail transport cannot generate endless mail.
     */
    private function repairPickupExpiredNotifications(): void
    {
        CustodyTransaction::query()
            ->with('borrower')
            ->where('status', 'PREPARING_RELEASE')
            ->whereNull('released_at')
            ->whereNotNull('pickup_expired_at')
            ->orderBy('id')
            ->each(function (CustodyTransaction $custody): void {
                if (! $custody->borrower) {
                    return;
                }

                // Do not create/retry a borrower missed-pickup notice while
                // an SPMU-side preparation discrepancy is still unresolved.
                if ($this->hasPreparationExceptionPendingRelease($custody)) {
                    return;
                }

                $events = NotificationEvent::query()
                    ->with(['deliveries' => fn ($query) => $query
                        ->where('recipient_user_id', $custody->borrower_user_id)])
                    ->where('source_type', $custody->getMorphClass())
                    ->where('source_id', $custody->id)
                    ->where('event_code', 'PICKUP_EXPIRED')
                    ->get();

                $deliveries = $events->flatMap->deliveries;
                $channels = collect(['SYSTEM', 'EMAIL'])
                    ->filter(function (string $channel) use ($deliveries): bool {
                        $attempts = $deliveries->where('channel', $channel);
                        $sent = $attempts->contains(
                            fn ($delivery) => strtoupper((string) $delivery->delivery_status) === 'SENT'
                        );

                        return ! $sent && $attempts->count() < 2;
                    })
                    ->values()
                    ->all();

                if ($channels === []) {
                    return;
                }

                $expiredAt = CarbonImmutable::parse(
                    $custody->pickup_expired_at,
                    config('app.timezone') ?: 'Asia/Manila'
                );
                $dueAt = $custody->original_due_at ?: $custody->due_at;
                $nextPickup = $this->operationalCalendar->nextPickupWindow($expiredAt);
                $canStillReschedule = false;

                if ($dueAt && $nextPickup) {
                    $dueDay = CarbonImmutable::parse($dueAt, $expiredAt->timezone)->startOfDay();
                    $canStillReschedule = $nextPickup->startOfDay()->lt($dueDay);
                }

                $message = $canStillReschedule
                    ? "Your pickup schedule for {$custody->custody_no} has passed. Open My Borrowings and choose Request Reschedule if you still need the items, or Cancel Request if you no longer need them. If no action is taken within the allowed response period, the unreleased request will be cancelled automatically and the reserved quantity will return to available inventory."
                    : "Your pickup schedule for {$custody->custody_no} has passed and no valid replacement pickup remains before the approved Expected Return Date. The unreleased request will be cancelled automatically and the reserved quantity will return to available inventory.";

                $this->notifications->send(
                    'PICKUP_EXPIRED',
                    collect([$custody->borrower]),
                    $message,
                    $custody,
                    $channels,
                    $channels
                );
            });
    }

    public function confirmPickupSchedule(
        CustodyTransaction $custody,
        User $spmu
    ): void {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $spmu): void {
            $locked = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion', 'lines.requestItem'])
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($locked->status !== 'PREPARING_RELEASE' || $locked->released_at) {
                throw ValidationException::withMessages([
                    'pickup' => 'This pickup transaction has already moved to another state.',
                ]);
            }

            if ($locked->pickup_scheduled_at && $locked->hasPickupSchedule()) {
                return;
            }

            if ($locked->pickup_expired_at) {
                throw ValidationException::withMessages([
                    'pickup' => 'The confirmed pickup window has already expired. Use the missed-pickup handling process instead of creating a new normal pickup date.',
                ]);
            }

            $pickup = $locked->scheduled_release_at;
            $expires = $locked->pickup_expires_at;

            if (! $pickup || ! $expires) {
                throw ValidationException::withMessages([
                    'pickup' => 'The system-generated Pickup / Issuance schedule is unavailable. Review the Operational Calendar and the approved Items Needed From date.',
                ]);
            }

            if (now()->gte($expires)) {
                throw ValidationException::withMessages([
                    'pickup' => 'The system-generated Pickup / Issuance window has already passed and can no longer be confirmed.',
                ]);
            }

            $this->operationalCalendar->assertOpenFor(
                OperationalCalendarService::PICKUP,
                $pickup,
                'pickup'
            );

            $before = [
                'scheduled_release_at' => $locked->scheduled_release_at,
                'pickup_expires_at' => $locked->pickup_expires_at,
                'pickup_scheduled_by_user_id' => $locked->pickup_scheduled_by_user_id,
                'pickup_scheduled_at' => $locked->pickup_scheduled_at,
            ];

            $locked->update([
                'pickup_scheduled_by_user_id' => $spmu->id,
                'pickup_scheduled_at' => now(),
                'pickup_expired_at' => null,
            ]);

            $this->audit->record(
                'PICKUP_SCHEDULE_CONFIRMED',
                $locked,
                before: $before,
                after: [
                    'pickup_at' => $pickup->toIso8601String(),
                    'pickup_expires_at' => $expires->toIso8601String(),
                    'confirmed_by_user_id' => $spmu->id,
                    'source' => 'SYSTEM_GENERATED_PRE_BORROWING_WINDOW',
                ]
            );

            if ($locked->borrower) {
                $requiredDocuments = $locked->lines->contains(
                    fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                )
                    ? 'the generated Borrower Slip and Gate Pass'
                    : 'the generated Borrower Slip';

                $this->notifications->send(
                    'PICKUP_SCHEDULED',
                    collect([$locked->borrower]),
                    "Pickup and issuance for {$locked->custody_no} is confirmed for {$pickup->format('F j, Y g:i A')} until {$expires->format('g:i A')}. Proceed to SPMU within this window and bring {$requiredDocuments}.",
                    $locked
                );
            }
        }, 3);
    }

    /**
     * Let the borrower ask SPMU to reschedule a confirmed pickup window that
     * has already passed. This keeps the same approved request and reservation.
     * The borrower does not choose the replacement date/time; SPMU confirms
     * the next valid Operational Calendar window.
     */
    public function requestPickupReschedule(
        CustodyTransaction $custody,
        User $borrower
    ): void {
        abort_unless(
            $custody->borrower_user_id === $borrower->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $borrower): void {
            $locked = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion', 'lines.requestItem'])
                ->lockForUpdate()
                ->findOrFail($custody->id);

            $timezone = config('app.timezone') ?: 'Asia/Manila';
            $now = CarbonImmutable::now($timezone);
            $pickupPassed = (bool) $locked->pickup_scheduled_at
                && (
                    (bool) $locked->pickup_expired_at
                    || ($locked->pickup_expires_at && $locked->pickup_expires_at->lte($now))
                );

            if (! $pickupPassed) {
                throw ValidationException::withMessages([
                    'pickup' => 'A reschedule may be requested only after a confirmed pickup window has passed without physical issuance.',
                ]);
            }

            $alreadyRescheduled = AuditEvent::query()
                ->where('record_type', CustodyTransaction::class)
                ->where('record_id', $locked->id)
                ->where('action_code', 'PICKUP_RESCHEDULED')
                ->exists();

            if ($alreadyRescheduled) {
                throw ValidationException::withMessages([
                    'pickup' => 'The rescheduled pickup window has already passed. This unreleased request is no longer eligible for another pickup reschedule and will be cancelled automatically.',
                ]);
            }

            $dueAt = $locked->original_due_at ?: $locked->due_at;
            if (! $dueAt) {
                throw ValidationException::withMessages([
                    'pickup' => 'The approved Expected Return Date could not be found for this transaction.',
                ]);
            }

            /*
             * The initial missed-pickup response period lasts through the next
             * valid SPMU Pickup / Release operating window after the missed
             * schedule. A late click must not reopen a reservation that the
             * scheduler is already entitled to cancel.
             */
            $expiredAt = CarbonImmutable::parse(
                $locked->pickup_expired_at ?: $locked->pickup_expires_at,
                $timezone
            );
            $responseWindowStart = $this->operationalCalendar->nextPickupWindow(
                $expiredAt->addSecond()
            );
            $dueDay = CarbonImmutable::parse($dueAt, $timezone)->startOfDay();

            if (! $responseWindowStart || $responseWindowStart->startOfDay()->gte($dueDay)) {
                throw ValidationException::withMessages([
                    'pickup' => 'No valid rescheduled pickup remains before the approved Expected Return Date. The unreleased request is no longer eligible for rescheduling.',
                ]);
            }

            [, $responseDeadline] = $this->operationalCalendar->operatingWindow(
                OperationalCalendarService::PICKUP,
                $responseWindowStart
            );

            if (! $responseDeadline || $now->gt($responseDeadline)) {
                throw ValidationException::withMessages([
                    'pickup' => 'The missed-pickup response period has ended. This unreleased request is no longer eligible for rescheduling and will be cancelled automatically.',
                ]);
            }

            $nextStart = $this->operationalCalendar->nextPickupWindow($now);

            if (! $nextStart || $nextStart->startOfDay()->gte($dueDay)) {
                throw ValidationException::withMessages([
                    'pickup' => 'No valid rescheduled pickup remains before the approved Expected Return Date. Cancel the unreleased request or coordinate a revision of the approved borrowing dates.',
                ]);
            }

            $alreadyRequested = NotificationEvent::query()
                ->where('source_type', $locked->getMorphClass())
                ->where('source_id', $locked->id)
                ->where('event_code', 'PICKUP_RESCHEDULE_REQUESTED')
                ->when(
                    $locked->pickup_scheduled_at,
                    fn ($query) => $query->where('occurred_at', '>', $locked->pickup_scheduled_at)
                )
                ->exists();

            if ($alreadyRequested) {
                throw ValidationException::withMessages([
                    'pickup' => 'A pickup reschedule request is already waiting for SPMU action.',
                ]);
            }

            $spmuOfficers = User::query()
                ->where('access_classification', AccessClassification::SpmuOfficer->value)
                ->where('account_status', 'ACTIVE')
                ->get();

            $event = $this->notifications->send(
                'PICKUP_RESCHEDULE_REQUESTED',
                $spmuOfficers,
                "The borrower requested a new pickup schedule for {$locked->custody_no} after the confirmed pickup window passed. Keep the same approved request and reservation, and confirm the next valid SPMU operating window before the approved Expected Return Date.",
                $locked,
                ['SYSTEM', 'EMAIL']
            );

            $this->audit->record(
                'PICKUP_RESCHEDULE_REQUESTED',
                $locked,
                after: [
                    'requested_by_user_id' => $borrower->id,
                    'notification_event_id' => $event->id,
                    'same_request_retained' => true,
                    'reservation_released' => false,
                    'next_valid_window' => $nextStart->toIso8601String(),
                ]
            );
        }, 3);
    }

    /**
     * Handle a missed or unusable pickup window without forcing the borrower
     * to submit a new borrowing request.
     *
     * The approved request and its reserved quantity remain active. When the
     * borrower still needs the items, the Action Officer reschedules the same
     * custody transaction to the next valid SPMU Pickup / Release operating
     * window before the Expected Return Date. The reschedule action itself is
     * the AO confirmation of the new window and the borrower is notified.
     */
    public function rescheduleMissedPickup(
        CustodyTransaction $custody,
        User $spmu
    ): void {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $spmu): void {
            $locked = CustodyTransaction::query()
                ->with(['borrower', 'request.currentVersion', 'lines.requestItem'])
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($locked->status !== 'PREPARING_RELEASE' || $locked->released_at) {
                throw ValidationException::withMessages([
                    'pickup' => 'This pickup transaction has already moved to another state.',
                ]);
            }

            $now = CarbonImmutable::now(config('app.timezone') ?: 'Asia/Manila');
            $windowPassed = (bool) $locked->pickup_expired_at
                || ($locked->pickup_expires_at && $locked->pickup_expires_at->lte($now));
            $scheduleMissing = ! $locked->scheduled_release_at || ! $locked->pickup_expires_at;

            if (! $windowPassed && ! $scheduleMissing) {
                throw ValidationException::withMessages([
                    'pickup' => 'The current pickup window is still valid. Use the existing confirmed schedule instead of rescheduling it.',
                ]);
            }

            /*
             * When the borrower actually missed a CONFIRMED pickup, SPMU may
             * reschedule only after the borrower explicitly asks to continue.
             * A system/SPMU scheduling exception (no confirmed pickup) remains
             * an internal SPMU follow-up and does not require borrower consent.
             */
            if ($windowPassed && $locked->pickup_scheduled_at) {
                $borrowerRequestedReschedule = NotificationEvent::query()
                    ->where('source_type', $locked->getMorphClass())
                    ->where('source_id', $locked->id)
                    ->where('event_code', 'PICKUP_RESCHEDULE_REQUESTED')
                    ->where('occurred_at', '>', $locked->pickup_scheduled_at)
                    ->exists();

                if (! $borrowerRequestedReschedule) {
                    throw ValidationException::withMessages([
                        'pickup' => 'Wait for the borrower to request a pickup reschedule before assigning a new pickup window. The borrower may also cancel the unreleased request instead.',
                    ]);
                }
            }

            $nextStart = $this->operationalCalendar->nextPickupWindow($now);

            if (! $nextStart) {
                throw ValidationException::withMessages([
                    'pickup' => 'No future SPMU Pickup / Release operating window is currently configured. Review the Operational Calendar or cancel the unreleased request if it will no longer proceed.',
                ]);
            }

            [, $nextEnd] = $this->operationalCalendar->operatingWindow(
                OperationalCalendarService::PICKUP,
                $nextStart
            );

            if (! $nextEnd || ! $nextStart->lt($nextEnd)) {
                throw ValidationException::withMessages([
                    'pickup' => 'The next SPMU Pickup / Release day does not have a complete operating window configured.',
                ]);
            }

            $dueAt = $locked->original_due_at ?: $locked->due_at;

            if (! $dueAt) {
                throw ValidationException::withMessages([
                    'pickup' => 'The approved Expected Return Date could not be found for this transaction.',
                ]);
            }

            $dueDay = CarbonImmutable::parse($dueAt, $now->timezone)->startOfDay();

            if ($nextStart->startOfDay()->gte($dueDay)) {
                throw ValidationException::withMessages([
                    'pickup' => 'No valid rescheduled pickup remains before the approved Expected Return Date. Coordinate with the borrower, then cancel the request or revise the approved borrowing period as appropriate.',
                ]);
            }

            $before = [
                'scheduled_release_at' => $locked->scheduled_release_at?->toIso8601String(),
                'pickup_expires_at' => $locked->pickup_expires_at?->toIso8601String(),
                'pickup_expired_at' => $locked->pickup_expired_at?->toIso8601String(),
                'pickup_scheduled_by_user_id' => $locked->pickup_scheduled_by_user_id,
                'pickup_scheduled_at' => $locked->pickup_scheduled_at?->toIso8601String(),
            ];

            $locked->update([
                'scheduled_release_at' => $nextStart,
                'pickup_expires_at' => $nextEnd,
                'pickup_expired_at' => null,
                'pickup_scheduled_by_user_id' => $spmu->id,
                'pickup_scheduled_at' => $now,
            ]);

            $this->audit->record(
                'PICKUP_RESCHEDULED',
                $locked,
                before: $before,
                after: [
                    'pickup_at' => $nextStart->toIso8601String(),
                    'pickup_expires_at' => $nextEnd->toIso8601String(),
                    'confirmed_by_user_id' => $spmu->id,
                    'same_request_retained' => true,
                    'reservation_released' => false,
                    'preparation_preserved' => (bool) $locked->prepared_at,
                    'source' => 'MISSED_PICKUP_NEXT_VALID_OPERATIONAL_WINDOW',
                ]
            );

            if ($locked->borrower) {
                $requiredDocuments = $locked->lines->contains(
                    fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                )
                    ? 'the generated Borrower Slip and Gate Pass'
                    : 'the generated Borrower Slip';

                $this->notifications->send(
                    'PICKUP_SCHEDULED',
                    collect([$locked->borrower]),
                    "Pickup and issuance for {$locked->custody_no} has been rescheduled to {$nextStart->format('F j, Y g:i A')} until {$nextEnd->format('g:i A')}. This uses your same approved request; you do not need to submit a new borrowing request. Proceed to SPMU within the new window and bring {$requiredDocuments}.",
                    $locked
                );
            }
        }, 3);
    }

    public function reportPreparationIssue(
        CustodyTransaction $custody,
        User $spmu,
        int $custodyLineId,
        string $issueType,
        ?string $details = null,
        ?float $observedUsableQuantity = null,
        ?string $conditionObserved = null
    ): AuditEvent {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        $issueLabels = [
            'ITEM_NOT_READY' => 'Item not found / not ready',
            'PHYSICAL_CONDITION' => 'Physical condition issue',
            'QUANTITY_AVAILABILITY' => 'Physical quantity is short',
            'OTHER' => 'Other inventory discrepancy',
        ];

        if (! array_key_exists($issueType, $issueLabels)) {
            throw ValidationException::withMessages([
                'issue_type' => 'Select a valid inventory discrepancy.',
            ]);
        }

        [$issueEvent, $itemName, $approvedQuantity] = DB::transaction(function () use (
            $custody,
            $custodyLineId,
            $issueType,
            $details,
            $observedUsableQuantity,
            $conditionObserved,
            $issueLabels
        ): array {
            $locked = CustodyTransaction::query()
                ->with('lines.requestItem.inventoryItem')
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($locked->status !== 'PREPARING_RELEASE' || $locked->released_at) {
                throw ValidationException::withMessages([
                    'preparation_issue' => 'This transaction is no longer awaiting item preparation.',
                ]);
            }

            $line = $locked->lines->firstWhere('id', $custodyLineId);

            if (! $line) {
                throw ValidationException::withMessages([
                    'custody_line_id' => 'Select an item from this borrowing transaction.',
                ]);
            }

            $approved = (float) $line->approved_quantity;

            if ($observedUsableQuantity !== null && $observedUsableQuantity > $approved) {
                throw ValidationException::withMessages([
                    'observed_usable_quantity' => 'Physically ready quantity cannot be greater than the approved quantity.',
                ]);
            }

            if ($issueType === 'QUANTITY_AVAILABILITY' && $observedUsableQuantity === null) {
                throw ValidationException::withMessages([
                    'observed_usable_quantity' => 'Enter the physically ready quantity for a quantity shortage.',
                ]);
            }

            $details = trim((string) $details);
            $conditionObserved = trim((string) $conditionObserved);

            if ($issueType === 'PHYSICAL_CONDITION' && $conditionObserved === '') {
                throw ValidationException::withMessages([
                    'condition_observed' => 'Describe the physical condition observed.',
                ]);
            }

            if ($issueType === 'OTHER' && $details === '') {
                throw ValidationException::withMessages([
                    'details' => 'Describe the inventory discrepancy.',
                ]);
            }

            $issueReason = match ($issueType) {
                'QUANTITY_AVAILABILITY' => $details !== ''
                    ? $details
                    : 'Physical quantity shortage observed during item preparation.',
                'PHYSICAL_CONDITION' => $conditionObserved,
                'ITEM_NOT_READY' => $details !== ''
                    ? $details
                    : 'Item not found or not ready during physical preparation.',
                default => $details,
            };

            $openForLine = $this->unresolvedPreparationIssueEvents($locked)
                ->contains(fn (AuditEvent $event) => (int) data_get($event->after_json, 'custody_line_id') === $line->id);

            if ($openForLine) {
                throw ValidationException::withMessages([
                    'preparation_issue' => 'This item already has an inventory discrepancy under review.',
                ]);
            }

            // A newly observed problem invalidates any earlier preparation
            // confirmation. No inventory or approved quantity is changed here.
            $locked->update([
                'prepared_at' => null,
                'prepared_by_user_id' => null,
            ]);

            $itemName = (string) ($line->requestItem?->description_snapshot ?: 'Item');
            $unit = (string) ($line->requestItem?->unit_snapshot ?: '');

            $event = $this->audit->record(
                'PREPARATION_ISSUE_REPORTED',
                $locked,
                reason: $issueReason,
                after: [
                    'custody_line_id' => $line->id,
                    'request_item_id' => $line->request_item_id,
                    'item_name' => $itemName,
                    'unit' => $unit,
                    'approved_quantity' => $approved,
                    'observed_usable_quantity' => $observedUsableQuantity,
                    'condition_observed' => $conditionObserved !== '' ? $conditionObserved : null,
                    'issue_type' => $issueType,
                    'issue_label' => $issueLabels[$issueType],
                    'details' => $details !== '' ? $details : null,
                ]
            );

            return [$event, $itemName, $approved];
        }, 3);

        $heads = User::query()
            ->where('account_status', 'ACTIVE')
            ->where('access_classification', AccessClassification::SpmuHead->value)
            ->get();

        if ($heads->isNotEmpty()) {
            $observed = $observedUsableQuantity === null
                ? ''
                : ' Physically ready/usable: '.($observedUsableQuantity + 0).'.';

            $this->notifications->send(
                'PREPARATION_ISSUE_REPORTED',
                $heads,
                "Inventory discrepancy reported for {$custody->custody_no}: {$itemName}. Approved quantity: ".($approvedQuantity + 0).".{$observed} Review the affected item and Inventory before release.",
                $custody
            );
        }

        return $issueEvent;
    }

    /**
     * Complete a reported inventory-discrepancy review after the Head/Admin has
     * reviewed the physical discrepancy and any necessary inventory
     * correction. This action does not edit inventory by itself.
     */
    public function resolvePreparationIssue(
        CustodyTransaction $custody,
        User $spmuHead,
        AuditEvent $issueEvent,
        ?string $resolutionNotes = null
    ): void {
        abort_unless(
            $spmuHead->access_classification === AccessClassification::SpmuHead
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        $reporter = null;
        $itemName = 'item';

        DB::transaction(function () use (
            $custody,
            $issueEvent,
            $resolutionNotes,
            &$reporter,
            &$itemName
        ): void {
            $locked = CustodyTransaction::query()
                ->lockForUpdate()
                ->findOrFail($custody->id);

            $issue = AuditEvent::query()
                ->with('actor')
                ->lockForUpdate()
                ->findOrFail($issueEvent->id);

            abort_unless(
                $issue->action_code === 'PREPARATION_ISSUE_REPORTED'
                    && $issue->record_type === CustodyTransaction::class
                    && (int) $issue->record_id === (int) $locked->id,
                404
            );

            if (! $this->unresolvedPreparationIssueEvents($locked)->contains('id', $issue->id)) {
                throw ValidationException::withMessages([
                    'preparation_issue' => 'This inventory discrepancy has already been reviewed.',
                ]);
            }

            if ($locked->pickup_expires_at && now()->gt($locked->pickup_expires_at)) {
                throw ValidationException::withMessages([
                    'resolution_type' => 'The approved pickup schedule has already passed while this discrepancy was unresolved. Do not create a new schedule for the same approved Borrower Slip. Use Unable to Fulfill Approved Request instead.',
                ]);
            }

            $itemName = (string) data_get($issue->after_json, 'item_name', 'item');
            $reporter = $issue->actor;
            $notes = trim((string) $resolutionNotes);
            $auditReason = $notes !== ''
                ? $notes
                : 'Inventory review completed; Action Officer recheck required.';

            $this->audit->record(
                'PREPARATION_ISSUE_RESOLVED',
                $locked,
                reason: $auditReason,
                after: [
                    'preparation_issue_event_id' => $issue->id,
                    'custody_line_id' => (int) data_get($issue->after_json, 'custody_line_id'),
                    'item_name' => $itemName,
                    'resolution_notes' => $notes !== '' ? $notes : null,
                ]
            );
        }, 3);

        if ($reporter && $reporter->account_status?->value === 'ACTIVE') {
            $this->notifications->send(
                'PREPARATION_ISSUE_RESOLVED',
                collect([$reporter]),
                "The inventory review for {$itemName} under {$custody->custody_no} has been completed. Physically check the item again, then confirm Items Prepared only when every approved item is ready for release.",
                $custody
            );
        }
    }

    /**
     * Whether an AO-reported preparation exception is still preventing a
     * completed physical preparation. This remains true not only while Head/
     * Admin is reviewing the discrepancy, but also after Inventory Review is
     * completed and the Action Officer still needs to physically recheck the
     * item. Until Items Prepared is confirmed, a passed pickup window is an
     * SPMU-side fulfillment problem rather than a borrower no-show.
     */
    public function hasPreparationExceptionPendingRelease(CustodyTransaction $custody): bool
    {
        if (
            $custody->status !== 'PREPARING_RELEASE'
            || $custody->released_at
            || $custody->prepared_at
        ) {
            return false;
        }

        return AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PREPARATION_ISSUE_REPORTED')
            ->exists();
    }

    /**
     * Whether Step 2 currently has an AO-reported physical discrepancy that
     * still requires Head/Admin action. Other services use this to ensure an
     * SPMU-side preparation problem is never treated as a borrower no-show.
     */
    public function hasOpenPreparationIssue(CustodyTransaction $custody): bool
    {
        return $this->unresolvedPreparationIssueEvents($custody)->isNotEmpty();
    }

    /** @return Collection<int, AuditEvent> */
    private function unresolvedPreparationIssueEvents(CustodyTransaction $custody): Collection
    {
        $events = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->whereIn('action_code', [
                'PREPARATION_ISSUE_REPORTED',
                'PREPARATION_ISSUE_RESOLVED',
                'PREPARATION_ISSUE_CLOSED_UNFULFILLED',
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $closedIds = $events
            ->whereIn('action_code', [
                'PREPARATION_ISSUE_RESOLVED',
                'PREPARATION_ISSUE_CLOSED_UNFULFILLED',
            ])
            ->map(fn (AuditEvent $event) => (int) data_get($event->after_json, 'preparation_issue_event_id'))
            ->filter()
            ->unique();

        return $events
            ->where('action_code', 'PREPARATION_ISSUE_REPORTED')
            ->reject(fn (AuditEvent $event) => $closedIds->contains((int) $event->id))
            ->values();
    }

    public function prepare(CustodyTransaction $custody, User $spmu): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $spmu): void {
            $custody = CustodyTransaction::query()
                ->with([
                    'borrower',
                    'request.currentVersion',
                    'lines.requestItem.inventoryItem',
                    'gatePass',
                ])
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($custody->status !== 'PREPARING_RELEASE' || $custody->released_at) {
                throw ValidationException::withMessages([
                    'preparation' => 'This custody transaction is no longer awaiting physical preparation.',
                ]);
            }

            if (! $custody->hasPickupSchedule()) {
                throw ValidationException::withMessages([
                    'preparation' => 'Set an active pickup schedule before confirming item preparation.',
                ]);
            }

            if ($this->unresolvedPreparationIssueEvents($custody)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'preparation' => 'Wait for the reported inventory discrepancy to be reviewed before confirming Items Prepared.',
                ]);
            }

            /*
             * The approved packet must already exist before preparation is
             * persisted. This validates Head-generated documents and never
             * creates a substitute Gate Pass or Borrower Slip at this stage.
             */
            $documentIds = $this->validateApprovedReleaseDocuments($custody);
            $preparedQuantities = [];

            foreach ($custody->lines as $line) {
                $approved = (float) $line->approved_quantity;

                /*
                 * Item Preparation confirms physical readiness only. The
                 * approved quantity was already validated and reserved during
                 * approval, so the Action Officer does not re-enter or edit it
                 * here. Any physical discrepancy must be resolved through the
                 * appropriate inventory/administrative process before the AO
                 * confirms preparation.
                 */
                $line->update([
                    'quantity_to_receive' => $approved,
                    'item_status' => 'PREPARED',
                    'adjustment_reason' => null,
                ]);

                $preparedQuantities[$line->id] = $approved;
            }

            $custody->update([
                'prepared_by_user_id' => $spmu->id,
                'prepared_at' => now(),
            ]);

            /*
             * Keep the preparation state change and its audit entry atomic.
             * If audit persistence fails, the preparation updates roll back as
             * well instead of leaving a prepared transaction with no audit.
             */
            $fresh = $custody->fresh([
                'borrower',
                'request.currentVersion',
                'lines.requestItem.inventoryItem',
                'gatePass',
            ]);

            $this->audit->record(
                'RELEASE_PREPARED',
                $fresh,
                reason: 'SPMU Action Officer confirmed the approved items were physically checked and ready for release, and validated the approved/generated release documents.',
                after: [
                    'prepared_quantities' => $preparedQuantities,
                    'borrower_slip_document_id' => $documentIds['borrower_slip'],
                    'gate_pass_document_id' => $documentIds['gate_pass'],
                    'laundry_form_document_id' => $documentIds['laundry_form'],
                ]
            );
        }, 3);
    }

    public function release(
        CustodyTransaction $custody,
        User $spmu,
        ?string $remarks = null
    ): void {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE',
            403
        );

        $this->operationalCalendar->assertOpenFor(
            OperationalCalendarService::PICKUP,
            now(),
            'release'
        );

        if ($this->unresolvedPreparationIssueEvents($custody)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'release' => 'Physical Release is unavailable while an inventory discrepancy is under review.',
            ]);
        }

        if (! $custody->prepared_at) {
            throw ValidationException::withMessages([
                'release' => 'SPMU physical preparation is required before release.',
            ]);
        }

        if (! $custody->hasPickupSchedule()) {
            throw ValidationException::withMessages([
                'release' => $custody->pickup_expired_at
                    ? 'The pickup window has expired. Set a new pickup schedule before recording the physical release.'
                    : 'Set an active pickup schedule before recording the physical release.',
            ]);
        }

        $this->assertReleaseWithinPickupWindow($custody);

        $custody->loadMissing('request.currentVersion', 'gatePass', 'lines.requestItem.inventoryItem');
        $hasOffCampusItem = $custody->lines->contains(
            fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS'
        );

        if ($hasOffCampusItem && ! $custody->gatePass) {
            throw ValidationException::withMessages([
                'release' => 'An approved Gate Pass record is required before physical release.',
            ]);
        }

        if (
            $hasOffCampusItem
            && (
                ! $custody->gatePass?->pass_document_id
                || ! in_array($custody->gatePass?->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
            )
        ) {
            throw ValidationException::withMessages([
                'release' => 'The approved generated Gate Pass must be validated before physical release.',
            ]);
        }
        $hasLinen = $custody->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem->inventoryItem->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        $laundryDocument = null;

        if ($hasLinen) {
            /*
             * The Laundry Form is a physical working document. It is generated
             * by SPMU and travels with the borrower to Laundry; it is not
             * digitally approved by an operational portal user or borrower.
             */
            $laundryDocument = GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $custody->id)
                ->where('document_type', 'LAUNDRY_FORM')
                ->where('status', 'FINAL')
                ->latest('id')
                ->first();

            if (! $laundryDocument) {
                $laundryDocument = $this->documents->conditionalForm(
                    $custody->fresh(),
                    'LAUNDRY_FORM'
                );
            }
        }

        DB::transaction(function () use ($custody, $spmu, $hasLinen, $laundryDocument, $remarks): void {
            /*
             * Serialize physical release for this custody record. A double
             * click / duplicate POST must never issue the same property twice
             * or try to create a second LaundryJob for the same custody.
             */
            $custody = CustodyTransaction::query()
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($custody->released_at || $custody->status !== 'PREPARING_RELEASE') {
                return;
            }

            // Re-check the pickup window while holding the row lock so a
            // concurrent reschedule cannot turn an early/late release into a
            // valid issuance between the initial validation and persistence.
            if (! $custody->hasPickupSchedule()) {
                throw ValidationException::withMessages([
                    'release' => $custody->pickup_expired_at
                        ? 'The pickup window has expired. Set a new pickup schedule before recording the physical release.'
                        : 'Set an active pickup schedule before recording the physical release.',
                ]);
            }

            $this->assertReleaseWithinPickupWindow($custody);

            /* The handover identity and time are authoritative custody data. */
            $custody->loadMissing(
                'lines.requestItem.inventoryItem',
                'lines.allocation',
                'borrower',
                'request',
                'gatePass'
            );

            /* Revalidate the same approved packet while holding custody lock. */
            $approvedDocumentIds = $this->validateApprovedReleaseDocuments($custody);

            $releaseReason = trim((string) $remarks);
            if ($releaseReason === '') {
                $releaseReason = 'Physical count, condition, and required handwritten signatures confirmed.';
            }

            $transactionId = $this->transactionHeader(
                'PHYSICAL_RELEASE',
                $custody,
                $spmu,
                $releaseReason
            );
            foreach ($custody->lines as $line) {
                $allocation = $line->allocation()->lockForUpdate()->firstOrFail();
                $actual = (float) $line->quantity_to_receive;
                $unused = max(0, (float) $line->approved_quantity - $actual);
                $line->update(['actual_released_quantity' => $actual, 'release_condition' => 'SERVICEABLE']);
                $allocation->update([
                    'released_quantity' => $actual,
                    'restored_quantity' => (float) $allocation->restored_quantity + $unused,
                    'status' => $actual > 0 ? 'RELEASED' : 'RESTORED',
                ]);
                if ($actual > 0) {
                    $this->transactionLine($transactionId, $line->requestItem->inventory_item_id, 'ALLOCATED', 'BORROWED', $actual, $allocation->period_start, $allocation->period_end);
                }
                if ($unused > 0) {
                    $this->transactionLine($transactionId, $line->requestItem->inventory_item_id, 'ALLOCATED', 'AVAILABLE', $unused, $allocation->period_start, $allocation->period_end);
                }
                $line->update(['item_status' => $actual > 0 ? 'RELEASED_PENDING_RETURN' : 'CLOSED', 'compliance_status' => $line->requestItem->use_location === 'OFF_CAMPUS' ? 'AWAITING_GUARD_SIGNATURE' : ($line->requestItem->inventoryItem->laundry_required ? 'LAUNDRY_FORM_READY' : 'NOT_REQUIRED')]);
            }

            if ($hasLinen) {
                /*
                 * custody_transaction_id is UNIQUE in laundry_jobs. Older
                 * workflow versions may already have created the row, and a
                 * duplicate browser submission can race with another release
                 * request. insertOrIgnore + a locking current-read makes this
                 * creation idempotent and avoids a duplicate-key 500.
                 *
                 * Existing Laundry progress is deliberately preserved: release
                 * may attach the current Laundry Form, but must not reset a job
                 * that already has worker/verification timestamps.
                 */
                $now = now();

                LaundryJob::query()->insertOrIgnore([
                    'custody_transaction_id' => $custody->id,
                    'generated_document_id' => $laundryDocument?->id,
                    'status' => 'FOR_LAUNDRY',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $job = LaundryJob::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $laundryDocument
                    && (int) $job->generated_document_id !== (int) $laundryDocument->id
                ) {
                    $job->update([
                        'generated_document_id' => $laundryDocument->id,
                    ]);
                }

                foreach ($custody->lines as $line) {
                    if (
                        ! $line->requestItem->inventoryItem->laundry_required
                        || (float) $line->actual_released_quantity <= 0
                    ) {
                        continue;
                    }

                    /*
                     * custody_line_id is also UNIQUE. Reuse any existing line
                     * instead of clearing Laundry inspection data on a retry.
                     */
                    LaundryJobLine::query()->insertOrIgnore([
                        'laundry_job_id' => $job->id,
                        'custody_line_id' => $line->id,
                        'issued_quantity' => $line->actual_released_quantity,
                        'affected_quantity' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $jobLine = LaundryJobLine::query()
                        ->where('custody_line_id', $line->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $jobLine->update([
                        'laundry_job_id' => $job->id,
                        'issued_quantity' => $line->actual_released_quantity,
                    ]);

                    $line->update([
                        'compliance_status' => 'FOR_LAUNDRY',
                    ]);
                }
            }

            $custody->update([
                'released_by_user_id' => $spmu->id,
                'released_by_signature_snapshot_id' => null,
                'released_at' => now(),
                /*
                 * The Action Officer's physical-handover attestation
                 * (CustodyController::release() validates
                 * physical_signatures_confirmed as required|accepted before
                 * ever reaching here) is an explicit, queryable timestamp,
                 * distinct from the intentionally absent release
                 * E-signature - see the physical_handover_attested_at
                 * migration's own doc comment.
                 */
                'physical_handover_attested_at' => now(),
                'status' => 'ACTIVE',
            ]);

            /*
             * Keep the Borrower Slip generated at final Head approval as the
             * single official travelling copy. Physical release is recorded in
             * custody/audit data only; it must not invalidate or regenerate the PDF.
             */

            /*
             * The Laundry Form is intentionally NOT regenerated at release.
             * It is one travelling physical form generated after approval and
             * carried from pickup through return. Laundry Personnel complete
             * both "Issued by" and "Received by" using handwritten/wet
             * signatures on that same printed copy.
             */

            $this->audit->record('ITEMS_RELEASED', $custody, after: [
                'released_by' => $spmu->id,
                'released_at' => now()->toIso8601String(),
                'borrower_slip_document_id' => $approvedDocumentIds['borrower_slip'],
                'approved_gate_pass_document_id' => $approvedDocumentIds['gate_pass'],
                'laundry_form_document_id' => $approvedDocumentIds['laundry_form'],
            ]);
            $this->notifications->send('ITEMS_RELEASED', collect([$custody->borrower]), "Items under {$custody->custody_no} were physically released. Please return them on or before {$custody->due_at->format('F j, Y')}.", $custody, ['SYSTEM']);

            if ($hasLinen) {
                $spmuActionOfficers = User::query()
                    ->where(
                        'access_classification',
                        AccessClassification::SpmuOfficer->value
                    )
                    ->where('account_status', 'ACTIVE')
                    ->get();

                if ($spmuActionOfficers->isNotEmpty()) {
                    $this->notifications->send(
                        'LINEN_FOR_LAUNDRY',
                        $spmuActionOfficers,
                        "A linen transaction under {$custody->custody_no} is for Laundry. The borrower will return the used linen and physical Laundry Form to the Laundry Area after use; the Laundry Worker will later deliver the accomplished form to SPMU.",
                        $custody,
                        ['SYSTEM']
                    );
                }
            }
        }, 3);
    }

    public function receiveReturn(CustodyTransaction $custody, User $spmu, array $quantities, array $conditions, ?string $remarks, array $policeBlotterReferences = [], array $evidenceFileIds = [], array $conditionBreakdowns = [], ?\Carbon\Carbon $laundryReceivedOn = null): ReturnTransaction
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id, 403);

        return DB::transaction(function () use ($custody, $spmu, $quantities, $conditions, $remarks, $policeBlotterReferences, $evidenceFileIds, $conditionBreakdowns, $laundryReceivedOn): ReturnTransaction {
            $custody = CustodyTransaction::query()->lockForUpdate()->findOrFail($custody->id);
            $custody = $this->operationalCalendar->synchronizeCustodyDueDate($custody, $this->audit);
            $this->operationalCalendar->assertOpenFor(
                OperationalCalendarService::RETURN,
                now(),
                'return'
            );

            if (! in_array($custody->status, ['ACTIVE', 'RETURN_PROCESSING', 'PARTIALLY_RETURNED', 'OVERDUE', 'INCIDENT_OPEN'], true)) {
                throw ValidationException::withMessages(['return' => 'This custody record is no longer open for a physical return.']);
            }

            $custody->setRelation('lines', $custody->lines()->with('requestItem.inventoryItem')->lockForUpdate()->get());
            $custody->loadMissing('borrower');

            /*
             * Do not block the whole Return Inspection just because this custody
             * contains linen. Non-linen may be physically inspected and fully
             * accounted now. Linen is gated separately below and is accepted
             * only after the Laundry Worker delivers the accomplished physical
             * Laundry Form to SPMU for Action Officer encoding.
             */

            $activeEarlyReturns = EarlyReturnRequest::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', 'REQUESTED')
                ->lockForUpdate()
                ->get();

            /*
             * AUTOMATIC RETURN CLASSIFICATION
             * --------------------------------
             * The Action Officer never chooses the return type. It is derived
             * purely from calendar date: actual return date vs. the Expected
             * Return Date. An Early Return Request is optional borrower/SPMU
             * coordination only (used below solely to mark the active notice
             * COMPLETED) — it is never required to accept or classify an
             * actual early physical return, and a physical return may be
             * recorded any day once the custody is released and outstanding.
             */
            $today = now()->startOfDay();
            $dueDate = $custody->due_at?->copy()->startOfDay();

            $isEarlyReturn = $dueDate && $today->lt($dueDate);
            $isOverdueReturn = $dueDate && $today->gt($dueDate);

            /*
             * NO PARTIAL RETURN RULE
             * ----------------------
             * A custody line may be processed only when its entire outstanding
             * issued quantity is accounted for in the same SPMU inspection.
             * "Accounted" can be a mix of Fine, Damaged, Destroyed, Missing,
             * Lost, or Stolen quantities. This lets SPMU record, for example,
             * 18 Fine + 2 Damaged out of 20 issued without creating a partial
             * return. Mixed requests remain supported because non-linen lines
             * can be completed while linen continues through Laundry; the
             * overall custody remains RETURN_PROCESSING until every line is
             * fully accounted for and all obligations are resolved.
             */
            $allowedConditions = [
                'FINE',
                'DAMAGED',
                'DESTROYED',
                'MISSING',
                'LOST',
                'STOLEN',
            ];

            $normalizedBreakdowns = [];

            foreach ($custody->lines as $line) {
                $normalizedBreakdowns[$line->id] = array_fill_keys($allowedConditions, 0.0);

                if (isset($conditionBreakdowns[$line->id]) && is_array($conditionBreakdowns[$line->id])) {
                    foreach ($allowedConditions as $code) {
                        $normalizedBreakdowns[$line->id][$code] = max(
                            0,
                            (float) ($conditionBreakdowns[$line->id][$code] ?? 0)
                        );
                    }

                    continue;
                }

                /*
                 * Backward compatibility for existing tests/API calls that
                 * still send one quantity + one condition per custody line.
                 * The same full-accounting validation below still applies.
                 */
                $legacyQuantity = max(0, (float) ($quantities[$line->id] ?? 0));
                $legacyCondition = strtoupper((string) ($conditions[$line->id] ?? 'FINE'));

                if ($legacyQuantity > 0 && in_array($legacyCondition, $allowedConditions, true)) {
                    $normalizedBreakdowns[$line->id][$legacyCondition] = $legacyQuantity;
                }
            }

            $laundryJob = LaundryJob::query()
                ->where('custody_transaction_id', $custody->id)
                ->first();

            /*
             * Linen return rule:
             * The borrower physically returns linen to the Laundry Area first.
             * Laundry Personnel record the actual RECEIVED BY date, process/wash
             * the linen, fill DATE COMPLETED, and then deliver the fully
             * accomplished physical Laundry Form to SPMU.
             *
             * SPMU does not perform a second linen inspection. The Action Officer
             * uploads/verifies the completed form and encodes the returned
             * quantity plus any issue reported on/with the form. Fine / Good
             * linen is restored to Available automatically after that encoding
             * because the form already certifies that Laundry processing ended.
             */
            $eligibleLines = $custody->lines->filter(function ($line): bool {
                return max(
                    0,
                    (float) $line->actual_released_quantity - (float) $line->returned_quantity
                ) > 0;
            });

            /*
             * NO PARTIAL PHYSICAL RETURN — COMPLETE BRANCH RULE
             * -------------------------------------------------
             * A custody can have two legitimate physical return channels:
             *
             *   1) NON-LINEN -> direct AO physical inspection at SPMU
             *   2) LINEN     -> Laundry Area first, then AO encodes the fully
             *                   accomplished Laundry Form
             *
             * Mixed custodies may complete those two branches at different
             * times, because linen must not block a valid non-linen return.
             * Within either branch, however, ALL still-outstanding item types
             * must be fully accounted together. This prevents both quantity
             * splitting (8 now / 2 later) and item-type splitting (Table now /
             * Chair later) inside the same return channel.
             */
            $returnableLines = $eligibleLines->filter(
                fn ($line) => array_sum($normalizedBreakdowns[$line->id]) > 0
            );

            $eligibleNonLinenLines = $eligibleLines->reject(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $eligibleLinenLines = $eligibleLines->filter(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $selectedNonLinenLines = $returnableLines->reject(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );
            $selectedLinenLines = $returnableLines->filter(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );

            /*
             * Keep the two physical channels as separate ReturnTransactions so
             * each one keeps the correct authoritative return timestamp:
             * AO inspection time for non-linen, Laundry RECEIVED BY for linen.
             */
            if ($selectedNonLinenLines->isNotEmpty() && $selectedLinenLines->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'return' => 'Record one complete return branch at a time. Submit the complete non-linen AO inspection separately from the complete linen findings encoded from the accomplished Laundry Form.',
                ]);
            }

            $assertCompleteBranch = static function ($eligibleBranch, $selectedBranch, string $branchLabel): void {
                if ($selectedBranch->isEmpty()) {
                    return;
                }

                $selectedIds = $selectedBranch->pluck('id')->map(fn ($id) => (int) $id);
                $missing = $eligibleBranch->reject(
                    fn ($line) => $selectedIds->contains((int) $line->id)
                );

                if ($missing->isEmpty()) {
                    return;
                }

                $missingNames = $missing
                    ->map(fn ($line) => $line->requestItem?->description_snapshot ?: 'Borrowed item')
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'return' => $branchLabel
                        .' must be recorded as one complete return branch. Account for every still-outstanding item type in this branch before submitting. Missing: '
                        .$missingNames.'.',
                ]);
            };

            $assertCompleteBranch(
                $eligibleNonLinenLines,
                $selectedNonLinenLines,
                'Non-linen return'
            );
            $assertCompleteBranch(
                $eligibleLinenLines,
                $selectedLinenLines,
                'Linen return'
            );

            foreach ($custody->lines as $line) {
                $accounted = array_sum($normalizedBreakdowns[$line->id]);

                if ($accounted <= 0 || $eligibleLines->contains('id', $line->id)) {
                    continue;
                }

                $description = $line->requestItem->description_snapshot ?: 'Borrowed item';

                throw ValidationException::withMessages([
                    'return' => $description
                        .': this item is no longer outstanding or is not eligible for this return inspection.',
                ]);
            }

            if ($returnableLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'return' => 'Select at least one item that is ready for SPMU return inspection and account for its complete outstanding quantity.',
                ]);
            }

            /*
             * LINEN DOCUMENTARY BASIS
             * -----------------------
             * Laundry Personnel are the authoritative physical inspector for
             * linen. The borrower goes to the Laundry Area first, where the
             * actual received quantity and condition are written on the same
             * travelling printed Laundry Form and "Received by" is wet-signed.
             * The Action Officer is the system verifier/encoder for linen: the
             * accomplished form must already be uploaded and verified before
             * any linen quantity can be finalised here. Non-linen is unchanged
             * and remains a direct Action Officer physical inspection.
             */
            $linenLines = $returnableLines->filter(
                fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
            );

            if ($linenLines->isNotEmpty()) {
                $laundryJob = LaundryJob::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->first();

                if (! $laundryJob?->latest_evidence_submission_id
                    || ! $laundryJob?->form_verified_at) {
                    throw ValidationException::withMessages([
                        'laundry_form' => 'Completed Laundry Form required. This transaction includes linen items. Upload the accomplished Laundry Form signed by Laundry Personnel before the linen return can be finalized.',
                    ]);
                }
            }

            /*
             * A return submission belongs to exactly one complete physical
             * channel. Linen uses the physical Laundry RECEIVED BY date written
             * on the accomplished form; non-linen uses the Action Officer's
             * Return Inspection timestamp. Mixed linen/non-linen custody is
             * never combined in one submission: each channel must be completed
             * in full, and LateReturnService later uses the later authoritative
             * date when the whole custody has been physically completed.
             */
            $returnReceivedAt = now();

            if ($returnableLines->isNotEmpty()
                && $returnableLines->every(
                    fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                )
                && $laundryJob?->worker_received_at) {
                $returnReceivedAt = $laundryJob->worker_received_at->copy();
                $returnDay = $returnReceivedAt->copy()->startOfDay();
                $isEarlyReturn = $dueDate && $returnDay->lt($dueDate);
                $isOverdueReturn = $dueDate && $returnDay->gt($dueDate);
            }

            foreach ($returnableLines as $line) {
                $outstanding = max(
                    0,
                    (float) $line->actual_released_quantity - (float) $line->returned_quantity
                );
                $accounted = array_sum($normalizedBreakdowns[$line->id]);
                $description = $line->requestItem->description_snapshot ?: 'Borrowed item';

                if (abs($accounted - $outstanding) > 0.0005) {
                    throw ValidationException::withMessages([
                        'return' => $description
                            .': the complete outstanding quantity must be accounted in one inspection. Expected '
                            .($outstanding + 0)
                            .', but '
                            .($accounted + 0)
                            .' is accounted. Classify unavailable quantities as Missing, Lost, Stolen, Damaged, or Destroyed as applicable.',
                    ]);
                }

                $nonFine = collect($normalizedBreakdowns[$line->id])
                    ->except('FINE')
                    ->sum();

                $isLinenLine = (bool) $line->requestItem?->inventoryItem?->laundry_required;

                /*
                 * Linen condition findings are transcribed from the accomplished
                 * Laundry Form, which is already the authoritative documentary
                 * evidence. Extra photos/files remain optional for linen. A
                 * direct AO non-linen adverse finding still requires supporting
                 * evidence because the AO is the physical inspector there.
                 */
                if (! $isLinenLine
                    && $nonFine > 0
                    && empty($evidenceFileIds[$line->id])) {
                    throw ValidationException::withMessages([
                        'evidence_files' => 'Supporting evidence is required for every non-linen item with damaged, destroyed, missing, lost, or stolen quantity.',
                    ]);
                }

                if (($normalizedBreakdowns[$line->id]['STOLEN'] ?? 0) > 0
                    && trim((string) ($policeBlotterReferences[$line->id] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'police_blotter_references' => 'A police-blotter reference is required for every stolen quantity.',
                    ]);
                }
            }

            /*
             * return_no must be unique per ReturnTransaction, not per
             * custody+second: the COMPLETE BRANCH RULE above means the same
             * custody legitimately produces two separate ReturnTransactions
             * (non-linen, then linen) that can be submitted back-to-back,
             * easily landing in the same wall-clock second - and tests that
             * travel/freeze time make that collision certain, not just
             * possible. A trailing random token keeps the human-readable
             * prefix while making the collision that would otherwise throw
             * an uncaught UniqueConstraintViolationException (and silently
             * roll back this entire return) practically impossible.
             */
            $return = ReturnTransaction::query()->create([
                'return_no' => 'RET-'.now()->format('YmdHis').'-'.$custody->id.'-'.Str::upper(Str::random(4)),
                'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $spmu->id,
                'return_type' => $isEarlyReturn ? 'EARLY' : ($isOverdueReturn ? 'OVERDUE' : 'NORMAL'),
                'received_at' => $returnReceivedAt,
                'status' => 'INSPECTED',
                'remarks' => $remarks,
            ]);

            /*
             * The Action Officer's identity for this physical return
             * inspection is recorded via received_by_user_id/received_at
             * (above) plus the transaction/audit log below. No E-signature is
             * captured for Return Inspection — identity, timestamp, and the
             * audit trail are sufficient for this operational action.
             */
            $returnActivityNote = $linenLines->isNotEmpty()
                ? 'Linen return findings encoded by the Action Officer from the accomplished Laundry Form; Laundry Personnel are the authoritative physical receiver/inspector.'
                : 'Non-linen physical return and full-quantity inspection recorded by the Action Officer.';

            $transactionId = $this->transactionHeader(
                'PHYSICAL_RETURN',
                $return,
                $spmu,
                $remarks ?: $returnActivityNote
            );

            // Keep the incidents created by this single inspection so the
            // borrower can be notified only after the return itself has been
            // fully persisted and the custody status has been reconciled.
            $openedIncidents = collect();

            foreach ($returnableLines as $line) {
                $item = $line->requestItem->inventoryItem;
                $breakdown = $normalizedBreakdowns[$line->id];
                $accounted = array_sum($breakdown);
                $blotterReference = trim((string) ($policeBlotterReferences[$line->id] ?? ''));
                $hasIncident = false;
                $hasLaundryDisposition = false;

                foreach ($breakdown as $condition => $quantity) {
                    $quantity = (float) $quantity;

                    if ($quantity <= 0) {
                        continue;
                    }

                    if ($item->laundry_required && $laundryJob) {
                        $disposition = $condition === 'FINE'
                            ? 'LAUNDRY'
                            : match ($condition) {
                                'MISSING', 'LOST' => 'LOST',
                                'STOLEN' => 'STOLEN',
                                'DESTROYED' => 'DESTROYED',
                                default => 'DAMAGED_MAINTENANCE',
                            };
                    } else {
                        /* Historical compatibility for pre-current laundry records. */
                        $disposition = $condition === 'FINE'
                            ? ($item->laundry_required ? 'LAUNDRY' : 'AVAILABLE')
                            : match ($condition) {
                                'MISSING', 'LOST' => 'LOST',
                                'STOLEN' => 'STOLEN',
                                'DESTROYED' => 'DESTROYED',
                                default => 'DAMAGED_MAINTENANCE',
                            };
                    }

                    $returnLine = ReturnLine::query()->create([
                        'return_transaction_id' => $return->id,
                        'custody_line_id' => $line->id,
                        'quantity_received' => $quantity,
                        'condition_code' => $condition,
                        'disposition_state' => $disposition,
                        'remarks' => $remarks,
                    ]);

                    $this->transactionLine(
                        $transactionId,
                        $item->id,
                        'BORROWED',
                        $disposition,
                        $quantity
                    );

                    if ($disposition === 'LAUNDRY') {
                        $hasLaundryDisposition = true;

                        /*
                         * Current LaundryJob cases are handled by the simplified
                         * internal Laundry queue. Keep LaundryRecord creation only
                         * for historical transactions that predate LaundryJob.
                         */
                        if (! $laundryJob) {
                            LaundryRecord::query()->create([
                                'return_line_id' => $returnLine->id,
                                'cleaned_quantity' => 0,
                                'damaged_quantity' => 0,
                                'status' => 'PENDING_EVIDENCE',
                            ]);
                        }
                    } elseif ($condition !== 'FINE') {
                        $hasIncident = true;

                        $incident = Incident::query()->create([
                            'incident_no' => 'INC-'.now()->format('YmdHis').'-'.$returnLine->id,
                            'custody_transaction_id' => $custody->id,
                            'borrower_user_id' => $custody->borrower_user_id,
                            'reported_by_user_id' => $spmu->id,
                            'supporting_evidence_file_id' => $evidenceFileIds[$line->id] ?? null,
                            'incident_type' => $condition,
                            'reported_at' => now(),
                            'police_blotter_reference' => $blotterReference ?: null,
                            'status' => 'OPEN',
                            'remarks' => $remarks,
                        ]);

                        IncidentLine::query()->create([
                            'incident_id' => $incident->id,
                            'custody_line_id' => $line->id,
                            'quantity' => $quantity,
                            'observed_condition' => $condition,
                            'disposition_state' => $disposition,
                        ]);

                        BorrowerRestriction::query()->firstOrCreate([
                            'borrower_user_id' => $custody->borrower_user_id,
                            'custody_transaction_id' => $custody->id,
                            'incident_id' => $incident->id,
                            'status' => 'ACTIVE',
                        ], [
                            'restriction_type' => 'UNRESOLVED_INCIDENT',
                            'reason' => 'Unresolved '.$condition.' incident '.$incident->incident_no.'.',
                            'effective_from' => now(),
                            'imposed_by_user_id' => $spmu->id,
                        ]);

                        /*
                         * RSLDDP is intentionally NOT generated here. It
                         * starts only after SPMU Admin/Head confirms actual
                         * accountability exists (AccountabilityController::
                         * resolveIncident()), not merely because an adverse
                         * inspection finding was reported.
                         */
                        $openedIncidents->push($incident);
                    }
                }

                /*
                 * returned_quantity is retained as the historical database
                 * field, but under the revised workflow it represents the
                 * quantity fully ACCOUNTED FOR by SPMU (including incidents).
                 */
                $line->increment('returned_quantity', $accounted);

                if ($hasLaundryDisposition) {
                    $line->update([
                        'item_status' => 'IN_LAUNDRY',
                        'compliance_status' => 'LAUNDRY_FORM_PENDING',
                    ]);
                } elseif ($hasIncident) {
                    $line->update(['item_status' => 'INCIDENT_PENDING']);
                } else {
                    $line->update(['item_status' => 'RETURNED']);
                }
            }

            $custody->refresh()->load('lines.requestItem.inventoryItem');

            foreach ($activeEarlyReturns as $activeEarlyReturn) {
                /*
                 * Early Return requests are coordination notices only.
                 * Once SPMU records a real physical return for this custody,
                 * the notice has served its purpose regardless of which item
                 * types were physically presented in that inspection.
                 */
                $activeEarlyReturn->update([
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                ]);
            }

            if ($laundryJob) {
                $allLaundryReturned = $custody->lines
                    ->filter(
                        fn ($line) =>
                            (bool) $line->requestItem->inventoryItem->laundry_required
                            && (float) $line->actual_released_quantity > 0
                    )
                    ->every(
                        fn ($line) =>
                            (float) $line->returned_quantity
                            >= (float) $line->actual_released_quantity
                    );

                if ($allLaundryReturned
                    && ! in_array($laundryJob->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true)) {
                    /*
                     * The current physical form is delivered to SPMU only after
                     * Laundry Personnel have filled both RECEIVED BY and DATE
                     * COMPLETED. Once the Action Officer encodes all linen
                     * findings, the form-recording flow restores serviceable
                     * linen to Available automatically.
                     *
                     * Fine / Good linen is first recorded as physically returned
                     * to LAUNDRY, then immediately restored from LAUNDRY to
                     * AVAILABLE in the same accountable database transaction.
                     * Adverse quantities keep their existing incident/
                     * accountability dispositions and are never restored here.
                     *
                     * A verified accomplished form is the authoritative signal
                     * that offline Laundry processing is complete. Its DATE
                     * COMPLETED field may be represented in a generated document,
                     * but is not required as a separate portal input.
                     */
                    $laundryJob->loadMissing([
                        'lines.custodyLine.returnLines',
                        'lines.custodyLine.requestItem.inventoryItem',
                    ]);

                    $totalServiceable = 0;
                    $serviceableByLine = [];

                    foreach ($laundryJob->lines as $jobLine) {
                        $received = (float) $jobLine->custodyLine->returnLines
                            ->where('disposition_state', 'LAUNDRY')
                            ->sum('quantity_received');

                        $received = (int) round($received);
                        $serviceableByLine[$jobLine->id] = $received;

                        $jobLine->update([
                            'received_quantity' => $received,
                        ]);

                        $totalServiceable += $received;
                    }

                    $automaticAvailability = $laundryJob->hasVerifiedAccomplishedForm();

                    if ($automaticAvailability && $totalServiceable > 0) {
                        $completionTransactionId = $this->transactionHeader(
                            'LAUNDRY_COMPLETION',
                            $laundryJob,
                            $spmu,
                            'Completed Laundry Form verified; serviceable linen restored automatically to Available after SPMU return encoding.'
                        );

                        foreach ($laundryJob->lines as $jobLine) {
                            $serviceable = (float) ($serviceableByLine[$jobLine->id] ?? 0);

                            if ($serviceable <= 0) {
                                continue;
                            }

                            $this->transactionLine(
                                $completionTransactionId,
                                $jobLine->custodyLine->requestItem->inventory_item_id,
                                'LAUNDRY',
                                'AVAILABLE',
                                $serviceable,
                                $laundryJob->worker_completed_at ?: now()
                            );
                        }
                    }

                    $nextLaundryStatus = ($totalServiceable <= 0 || $automaticAvailability)
                        ? 'LAUNDRY_COMPLETED'
                        : 'TURNED_OVER_TO_LAUNDRY';

                    foreach ($laundryJob->lines as $jobLine) {
                        $serviceable = (int) ($serviceableByLine[$jobLine->id] ?? 0);
                        $hasAdverseFinding = $jobLine->custodyLine->returnLines->contains(
                            fn ($returnLine) => strtoupper((string) $returnLine->condition_code) !== 'FINE'
                        );

                        $jobLine->update([
                            'completed_quantity' => $nextLaundryStatus === 'LAUNDRY_COMPLETED'
                                ? $serviceable
                                : null,
                        ]);

                        $jobLine->custodyLine->update([
                            'item_status' => $nextLaundryStatus === 'LAUNDRY_COMPLETED'
                                ? ($hasAdverseFinding ? 'INCIDENT_PENDING' : 'RETURNED')
                                : 'IN_LAUNDRY',
                            'compliance_status' => $nextLaundryStatus === 'LAUNDRY_COMPLETED'
                                ? 'LAUNDRY_COMPLETED'
                                : 'INTERNAL_LAUNDRY',
                        ]);
                    }

                    $laundryJob->update([
                        'status' => $nextLaundryStatus,
                        // RECEIVED BY controls borrower return timeliness.
                        // DATE COMPLETED controls when the offline laundry work
                        // finished. SPMU encoding time is kept separately in the
                        // audit trail and must not replace either physical date.
                        'ready_at' => $nextLaundryStatus === 'LAUNDRY_COMPLETED'
                            ? ($laundryJob->worker_completed_at ?: now())
                            : null,
                        'completed_at' => $nextLaundryStatus === 'LAUNDRY_COMPLETED'
                            ? now()
                            : null,
                    ]);

                    /*
                     * Compatibility fallback for older/alternate return payloads:
                     * if the actual Laundry RECEIVED BY date was supplied at Return
                     * Inspection and the job does not already have the authoritative
                     * physical receipt date, let LateReturnService record it. New
                     * flows normally set worker_received_at when the accomplished
                     * Laundry Form is uploaded.
                     */
                    if ($laundryReceivedOn !== null) {
                        $this->lateReturns->recordLaundryReceipt(
                            $custody,
                            $laundryReceivedOn,
                            $spmu
                        );
                        $laundryJob->refresh();
                    }

                    $this->audit->record(
                        'LAUNDRY_RETURN_RECORDED_FROM_FORM',
                        $laundryJob,
                        after: [
                            'status' => $nextLaundryStatus,
                            'recorded_by_user_id' => $spmu->id,
                            'physical_condition_source' => 'LAUNDRY_PERSONNEL',
                            'accomplished_form_verified' => true,
                            'physical_laundry_received_on' => $laundryJob->worker_received_at?->toDateString(),
                            'physical_laundry_completed_on' => $laundryJob->worker_completed_at?->toDateString(),
                            'serviceable_quantity' => (int) round($totalServiceable),
                            'availability_restored_automatically' => $automaticAvailability,
                        ]
                    );

                    $this->notifications->send(
                        'LAUNDRY_INTERNAL_QUEUE',
                        $this->spmuRecipients(),
                        "The completed Laundry Form for {$custody->custody_no} was encoded. Serviceable linen is Available; any adverse finding continues through Accountability Processing.",
                        $laundryJob,
                        ['SYSTEM']
                    );
                }
            }

            $allReturned = $custody->lines->every(
                fn ($line) =>
                    (float) $line->returned_quantity
                    >= (float) $line->actual_released_quantity
            );

            $overdue = OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->first();

            if ($allReturned) {
                /*
                 * PENDING_RETURN is now source-specific. Returning this
                 * custody lifts only this custody's outstanding-property
                 * control; another open borrowing keeps its own restriction.
                 */
                BorrowerRestriction::query()
                    ->forCustody($custody)
                    ->where('restriction_type', 'PENDING_RETURN')
                    ->where('status', 'ACTIVE')
                    ->update([
                        'status' => 'LIFTED',
                        'effective_to' => now(),
                        'lifted_by_user_id' => $spmu->id,
                    ]);
            }

            /*
             * Classify the return against its expected date. LateReturnService
             * reads the authoritative physical return date - the Laundry
             * Personnel receipt for linen, the Return Inspection for non-linen,
             * or the later of both dates for mixed custody - and freezes late
             * days there. It is deliberately not now():
             * the borrower must not be charged for the time SPMU takes to
             * process the return, and an on-time return opens no case at all.
             */
            if ($allReturned) {
                $this->lateReturns->assess($custody->fresh(['lines', 'returns', 'laundryJob']) ?? $custody, $spmu);
            }
            // Recalculate the custody status from the complete transaction,
            // not only from the Return action that happened in this request.
            // Gate Pass, Laundry, incident, and overdue workflows call the same
            // reconciler when their obligations change, preventing stale
            // OBLIGATION_OPEN statuses.
            $status = $this->reconcileTransactionStatus($custody);

            $releasedTotal = (float) $custody->lines->sum('actual_released_quantity');
            $returnedTotal = (float) $custody->lines->sum('returned_quantity');
            DB::table('kpi_observations')->insert([
                'request_id' => $custody->request_id,
                'custody_id' => $custody->id,
                'recorded_by_user_id' => $spmu->id,
                'process_code' => 'CUSTODY_RETURN_COMPLIANCE',
                'started_at' => $custody->released_at,
                'completed_at' => now(),
                'duration_seconds' => $custody->released_at?->diffInSeconds(now()),
                'correct_count' => $custody->lines->where('returned_quantity', '>=', 0)->count(),
                'total_count' => $custody->lines->count(),
                'output_count' => $returnedTotal,
                'input_value' => $releasedTotal,
                'input_unit' => 'property units',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record('RETURN_INSPECTED', $return, after: [
                'custody_status' => $status,
                'return_recorded_by_user_id' => $spmu->id,
                'return_recorded_at' => $return->received_at?->toIso8601String(),
            ]);

            /*
             * Return findings and the physical return timestamp stay in the
             * return/custody/audit records. The approval-time Borrower Slip is
             * intentionally preserved and is not regenerated after inspection.
             */

            $borrowerReturnMessage = $linenLines->isNotEmpty()
                ? "Linen return {$return->return_no} was recorded by SPMU from the accomplished Laundry Form. Status: {$status}."
                : "Return {$return->return_no} was physically counted and inspected by SPMU. Status: {$status}.";

            /*
             * A clean return is already communicated by TRANSACTION_CLOSED
             * inside reconcileTransactionStatus(). Do not send a second,
             * later RETURN_INSPECTED email that can make the sequence look
             * reversed. If the custody remains open, send the inspection
             * result first and then any newly opened accountability case(s).
             */
            if ($status !== 'CLOSED') {
                $this->notifications->send(
                    'RETURN_INSPECTED',
                    collect([$custody->borrower]),
                    $borrowerReturnMessage,
                    $custody->fresh([
                        'borrower',
                        'request.currentVersion',
                        'lines.requestItem.inventoryItem.unit',
                        'returns.lines.custodyLine.requestItem.inventoryItem.unit',
                        'incidents',
                        'laundryJob',
                    ]),
                    ['SYSTEM', 'EMAIL']
                );
            }

            foreach ($openedIncidents as $openedIncident) {
                $openedIncident->loadMissing('borrower');

                if (! $openedIncident->borrower) {
                    continue;
                }

                $this->notifications->send(
                    'ACCOUNTABILITY_OPENED',
                    collect([$openedIncident->borrower]),
                    "Property accountability case {$openedIncident->incident_no} was opened from the recorded return inspection. No final decision or charge has been issued yet; the case is awaiting SPMU Head/Admin review.",
                    $openedIncident->fresh([
                        'borrower',
                        'custody.request',
                        'lines.custodyLine.requestItem.inventoryItem.unit',
                    ]),
                    ['SYSTEM', 'EMAIL']
                );
            }

            /*
             * Late Return and Property Accountability offense detection.
             * Recorded as two independent BorrowerViolation rows (never
             * merged) so a Head later clearing a property finding can never
             * erase a confirmed Late Return. The Late Return half is
             * confirmed automatically from due-date/actual-return
             * timestamps - it is never gated behind a Head Yes/No decision.
             * The Property Accountability half, if any, is left
             * PENDING_REVIEW for the Head's own Confirm/Clear decision on the
             * linked Incident, unchanged from before.
             */
            $detectedViolations = $this->policy->detectFromConfirmedReturn($custody, $return, $spmu);

            if ($detectedViolations['late_return']) {
                $this->policy->autoConfirmLateReturnViolation($detectedViolations['late_return'], $spmu);
            }

            return $return->fresh(['receivedBy', 'inspectionSignature.file']);
        }, 3);
    }

    public function requestEarlyReturn(
        CustodyTransaction $custody,
        User $borrower,
        string $proposedReturnAt,
        ?string $reason
    ): EarlyReturnRequest {
        $earlyReturn = DB::transaction(function () use ($custody, $borrower, $proposedReturnAt, $reason): EarlyReturnRequest {
            $custody = CustodyTransaction::query()
                ->lockForUpdate()
                ->findOrFail($custody->id);

            abort_unless($custody->borrower_user_id === $borrower->id, 403);

            if (! $custody->released_at || $custody->status !== 'ACTIVE' || $custody->closed_at) {
                throw ValidationException::withMessages([
                    'early_return' => 'Early Return is available only for an active, open custody transaction.',
                ]);
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            $dueAt = $custody->due_at
                ? CarbonImmutable::instance($custody->due_at)
                : null;
            $proposedAt = CarbonImmutable::parse(
                $proposedReturnAt,
                config('app.timezone')
            );

            if (! $dueAt || ! $now->lt($dueAt)) {
                throw ValidationException::withMessages([
                    'proposed_return_at' => 'Early Return can be requested only before the original return deadline.',
                ]);
            }

            if (! $proposedAt->gt($now)) {
                throw ValidationException::withMessages([
                    'proposed_return_at' => 'The proposed handover date and time must be in the future.',
                ]);
            }

            if ($proposedAt->gt($dueAt)) {
                throw ValidationException::withMessages([
                    'proposed_return_at' => 'The proposed handover must be on or before the original return deadline.',
                ]);
            }

            if (EarlyReturnRequest::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', 'REQUESTED')
                ->exists()) {
                throw ValidationException::withMessages([
                    'early_return' => 'An active Early Return request already exists for this custody transaction.',
                ]);
            }

            $hasOutstandingItems = $custody->lines()
                ->whereColumn('returned_quantity', '<', 'actual_released_quantity')
                ->exists();

            if (! $hasOutstandingItems) {
                throw ValidationException::withMessages([
                    'early_return' => 'This custody transaction has no outstanding items to return.',
                ]);
            }

            return EarlyReturnRequest::query()->create([
                'early_return_no' => 'ER-'.now()->format('YmdHis').'-'.$custody->id,
                'custody_transaction_id' => $custody->id,
                'requested_by_user_id' => $borrower->id,
                'proposed_return_at' => $proposedAt,
                'reason' => $reason,
                'status' => 'REQUESTED',
                'requested_at' => now(),
            ]);
        }, 3);

        try {
            $this->audit->record(
                'EARLY_RETURN_REQUESTED',
                $earlyReturn,
                reason: $reason,
                after: [
                    'custody_transaction_id' => $custody->id,
                    'proposed_return_at' => $earlyReturn->proposed_return_at?->toIso8601String(),
                    'coordination_only' => true,
                ]
            );
        } catch (Throwable $exception) {
            Log::warning('Early Return audit recording failed after persistence.', [
                'early_return_id' => $earlyReturn->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $this->notifications->send(
                'EARLY_RETURN_REQUESTED',
                $this->spmuRecipients()
                    ->merge([$borrower])
                    ->unique('id'),
                "Early Return {$earlyReturn->early_return_no} was requested for {$custody->custody_no}. This is coordination only; actual return quantities and conditions are recorded through the applicable SPMU or Laundry Area return workflow.",
                $custody,
                ['SYSTEM', 'EMAIL']
            );
        } catch (Throwable $exception) {
            Log::warning('Early Return notification failed after persistence.', [
                'early_return_id' => $earlyReturn->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return $earlyReturn;
    }

    /**
     * Validate the controlled document packet generated by the final approval.
     *
     * @return array{borrower_slip: int, gate_pass: ?int, laundry_form: ?int}
     */
    private function validateApprovedReleaseDocuments(CustodyTransaction $custody): array
    {
        $custody->loadMissing([
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        $documentQuery = static fn (string $type) => GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', $type)
            ->where('status', 'FINAL')
            ->latest('id');

        $borrowerSlip = $documentQuery('BORROWER_SLIP')->first();

        if (! $borrowerSlip) {
            throw ValidationException::withMessages([
                'documents' => 'The approved Borrower Slip is missing or invalid. Do not prepare or release the property; have the final approval record reviewed.',
            ]);
        }

        $requiresGatePass = $custody->lines->contains(
            fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->approved_quantity > 0
        );

        $gatePassDocument = null;

        if ($requiresGatePass) {
            $gatePass = $custody->gatePass;

            if (
                ! $gatePass
                || ! $gatePass->pass_document_id
                || ! in_array($gatePass->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
            ) {
                throw ValidationException::withMessages([
                    'documents' => 'The approved Gate Pass is missing or invalid. Do not prepare or release the property; have the final approval record reviewed.',
                ]);
            }

            $gatePassDocument = $documentQuery('GATE_PASS')
                ->whereKey($gatePass->pass_document_id)
                ->first();

            if (! $gatePassDocument) {
                throw ValidationException::withMessages([
                    'documents' => 'The Gate Pass record does not point to a current approved generated document. Physical preparation and release remain blocked.',
                ]);
            }
        }

        $requiresLaundryForm = $custody->lines->contains(
            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->approved_quantity > 0
        );

        $laundryForm = $requiresLaundryForm
            ? $documentQuery('LAUNDRY_FORM')->first()
            : null;

        if ($requiresLaundryForm && ! $laundryForm) {
            throw ValidationException::withMessages([
                'documents' => 'The required approved Laundry Form is missing or invalid. Physical preparation and release remain blocked.',
            ]);
        }

        return [
            'borrower_slip' => $borrowerSlip->id,
            'gate_pass' => $gatePassDocument?->id,
            'laundry_form' => $laundryForm?->id,
        ];
    }

    private function assertReleaseWithinPickupWindow(CustodyTransaction $custody): void
    {
        $now = now();
        $startsAt = $custody->scheduled_release_at;
        $endsAt = $custody->pickup_expires_at;

        if (! $startsAt || ! $endsAt) {
            throw ValidationException::withMessages([
                'release' => 'Set an active pickup schedule before recording the physical release.',
            ]);
        }

        if ($now->lt($startsAt)) {
            throw ValidationException::withMessages([
                'release' => 'Physical release is not available yet. The pickup window starts on '
                    .$startsAt->format('F j, Y g:i A').'.',
            ]);
        }

        if ($now->gt($endsAt)) {
            throw ValidationException::withMessages([
                'release' => 'The pickup window ended on '
                    .$endsAt->format('F j, Y g:i A')
                    .'. Set a new pickup schedule before recording the physical release.',
            ]);
        }
    }

    private function spmuRecipients(): Collection
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('role_code', UserRole::Spmu->value)->whereNull('user_roles.revoked_at'))->get();
    }

    /**
     * Notify the borrower that their transaction just transitioned to
     * CLOSED, with wording that matches what CLOSED actually means for
     * this transaction. For a transaction with linen, CLOSED only means
     * the borrower has been cleared — internal Laundry processing and
     * final Laundry Form archival can still be pending — so the borrower
     * must not be told the whole transaction is "Completed" in that case.
     * No separate notification is sent later when internal Laundry
     * processing/archival finishes: LaundryController already notifies
     * SPMU internally at that point (LAUNDRY_PROCESSING_COMPLETED,
     * LAUNDRY_FINAL_FORM_ARCHIVED), and the borrower has no further action
     * either way, so a second borrower message would be redundant.
     */
    /**
     * Recalculate the status of the whole custody transaction from its current
     * persisted facts. Every workflow that can open or clear an obligation
     * should call this method after it changes Gate Pass, Laundry, incident,
     * overdue, or return state.
     */
    public function reconcileTransactionStatus(CustodyTransaction $custody): string
    {
        $custody = CustodyTransaction::query()
            ->with('lines')
            ->findOrFail($custody->id);

        if (! $custody->released_at) {
            return $custody->status;
        }

        /*
         * Property accountability must surface as soon as an adverse finding
         * is recorded for ANY completed item line. A mixed custody must not
         * hide an already-open damage/loss/etc. case behind RETURN_PROCESSING
         * while another item (for example linen awaiting its accomplished
         * Laundry Form) is still outstanding.
         *
         * This does not create accountability by itself. Incidents are created
         * only by an actual recorded adverse finding (DAMAGED, DESTROYED,
         * MISSING, LOST, or STOLEN). Fine / Good quantities never open a
         * property case.
         */
        $incidentIds = Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->pluck('id');

        $hasOpenIncident = Incident::query()
            ->whereIn('id', $incidentIds)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->exists();

        $allReturned = $custody->lines->every(
            fn ($line) =>
                (float) $line->returned_quantity
                >= (float) $line->actual_released_quantity
        );

        $hasAnyReturn = $custody->lines->contains(
            fn ($line) => (float) $line->returned_quantity > 0
        );

        if (! $allReturned) {
            $nextStatus = match (true) {
                // Keep the remaining return branches open, but expose the
                // accountability case immediately to AO/Admin/borrower views.
                $hasOpenIncident => 'INCIDENT_OPEN',
                $custody->status === 'OVERDUE' => 'OVERDUE',
                $hasAnyReturn => 'RETURN_PROCESSING',
                default => $custody->status,
            };

            if ($custody->status !== $nextStatus || $custody->closed_at) {
                $custody->update([
                    'status' => $nextStatus,
                    'closed_at' => null,
                ]);
            }

            return $nextStatus;
        }

        $penaltyIds = Penalty::query()
            ->where('custody_transaction_id', $custody->id)
            ->pluck('id');

        $billingIds = collect();
        if ($incidentIds->isNotEmpty() || $penaltyIds->isNotEmpty()) {
            $billingIds = DB::table('billing_lines')
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
                ->filter();
        }

        /*
         * A property/late-return case must not disappear from the transaction
         * just because its source incident/case was moved forward. Keep the
         * custody open while a linked Billing Statement or linked restriction
         * is still unresolved. Sanction-only restrictions are intentionally not
         * included here because they are account-level consequences, not a
         * remaining custody settlement step.
         */
        $hasOpenBilling = $billingIds->isNotEmpty()
            && BillingStatement::query()
                ->whereIn('id', $billingIds)
                ->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID'])
                ->exists();

        $hasOpenLinkedRestriction = false;
        if ($incidentIds->isNotEmpty() || $penaltyIds->isNotEmpty() || $billingIds->isNotEmpty()) {
            $hasOpenLinkedRestriction = BorrowerRestriction::query()
                ->where('borrower_user_id', $custody->borrower_user_id)
                ->where('status', 'ACTIVE')
                ->where(function ($query): void {
                    $query->whereNull('effective_from')->orWhere('effective_from', '<=', now());
                })
                ->where(function ($query): void {
                    $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
                })
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
                ->exists();
        }

        $hasOpenLegacyLaundry = LaundryRecord::query()
            ->whereHas(
                'returnLine.custodyLine',
                fn ($query) => $query->where(
                    'custody_transaction_id',
                    $custody->id
                )
            )
            ->whereNotIn('status', ['VERIFIED', 'VOID', 'VOID_CORRECTION'])
            ->exists();

        // In the current Laundry workflow the borrower's obligation ends when
        // Laundry Personnel physically receives the linen. Internal washing
        // may continue later without holding the borrower transaction open.
        $hasOpenCurrentLaundry = LaundryJob::query()
            ->where('custody_transaction_id', $custody->id)
            ->whereNotIn('status', ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'])
            ->exists();

        $hasOpenOverdue = OverdueCase::query()
            ->where('custody_transaction_id', $custody->id)
            ->where('status', '!=', 'RESOLVED')
            ->exists();

        $hasOpenGatePass = $custody->gatePass()
            ->whereNotIn('status', ['VERIFIED', 'VOID'])
            ->exists();

        $hasOpenObligation = $hasOpenIncident
            || $hasOpenBilling
            || $hasOpenLinkedRestriction
            || $hasOpenLegacyLaundry
            || $hasOpenCurrentLaundry
            || $hasOpenOverdue
            || $hasOpenGatePass;

        $wasClosed = $custody->status === 'CLOSED';
        $nextStatus = $hasOpenObligation ? 'OBLIGATION_OPEN' : 'CLOSED';

        $custody->update([
            'status' => $nextStatus,
            'closed_at' => $nextStatus === 'CLOSED'
                ? ($custody->closed_at ?: now())
                : null,
        ]);

        if ($nextStatus === 'CLOSED' && ! $wasClosed) {
            $this->notifyTransactionClosed($custody->fresh());
        }

        return $nextStatus;
    }

    public function notifyTransactionClosed(CustodyTransaction $custody): void
    {
        $custody->loadMissing('borrower', 'lines.requestItem.inventoryItem', 'laundryJob.latestEvidence.file');

        if (! $custody->borrower) {
            return;
        }

        $hasLaundryItem = $custody->lines->contains(
            fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
        );

        $fullyComplete = ! $hasLaundryItem
            || ($custody->laundryJob?->status === 'LAUNDRY_COMPLETED' && $custody->laundryJob?->latestEvidence?->file);

        $message = $fullyComplete
            ? "Borrowing transaction {$custody->custody_no} has been completed. All items have been returned and reconciled."
            : "Your returned items under {$custody->custody_no} have been accepted and your borrowing obligation is now cleared. No further action is required from you. Any remaining laundry processing and internal documentation will be handled by SPMU.";

        $this->notifications->send(
            'TRANSACTION_CLOSED',
            collect([$custody->borrower]),
            $message,
            $custody,
            ['SYSTEM', 'EMAIL']
        );
    }

    private function transactionHeader(string $type, object $source, User $actor, string $reason): int
    {
        return DB::table('inventory_transactions')->insertGetId([
            'actor_user_id' => $actor->id,
            'transaction_type' => $type,
            'source_type' => $source::class,
            'source_id' => $source->id,
            'reason' => $reason,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transactionLine(int $transactionId, int $inventoryItemId, string $from, string $to, float $quantity, mixed $effectiveFrom = null, mixed $effectiveTo = null): void
    {
        DB::table('inventory_transaction_lines')->insert([
            'inventory_transaction_id' => $transactionId,
            'inventory_item_id' => $inventoryItemId,
            'from_state' => $from,
            'to_state' => $to,
            'quantity' => $quantity,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
