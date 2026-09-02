<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
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
use App\Models\OverdueCase;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\SystemSetting;
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
    ) {}

    /**
     * Create the current pickup/custody record immediately after SPMU
     * approval and inventory reservation.
     *
     * This method intentionally does not assign a pickup time. The SPMU
     * Action Officer schedules the pickup window later from the custody
     * workspace. It is idempotent so replaying the approval-side call does
     * not duplicate the custody transaction or its lines.
     */
    public function ensurePickupRecord(
        BorrowingRequest $request,
        User $actor
    ): CustodyTransaction {
        return DB::transaction(function () use ($request): CustodyTransaction {
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

                    // Pickup is scheduled later by the SPMU Action Officer.
                    'scheduled_release_at' => null,
                    'pickup_expires_at' => null,
                    'pickup_expired_at' => null,
                    'pickup_scheduled_by_user_id' => null,
                    'pickup_scheduled_at' => null,

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

            return $custody->fresh([
                'lines.requestItem.inventoryItem',
                'borrower',
                'request.currentVersion',
            ]);
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
        $expired = 0;

        CustodyTransaction::query()
            ->where('status', 'PREPARING_RELEASE')
            ->whereNull('released_at')
            ->whereNotNull('pickup_expires_at')
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
                        $this->notifications->send(
                            'PICKUP_WINDOW_EXPIRED',
                            collect([$locked->borrower]),
                            "The pickup window for {$locked->custody_no} has expired. The approved reservation remains in place; wait for SPMU to schedule a new pickup window.",
                            $locked
                        );
                    }

                    $expired++;
                }, 3);
            });

        return $expired;
    }

    public function schedulePickup(
        CustodyTransaction $custody,
        User $spmu,
        string $pickupAt,
        string $pickupExpiresAt
    ): void {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        $timezone = config('app.timezone') ?: 'Asia/Manila';
        $pickup = CarbonImmutable::parse($pickupAt, $timezone);
        $expires = CarbonImmutable::parse($pickupExpiresAt, $timezone);

        $this->operationalCalendar->assertOpenFor(
            OperationalCalendarService::PICKUP,
            $pickup,
            'pickup_at'
        );

        $custody->loadMissing('request.currentVersion', 'borrower', 'lines.requestItem');
        $version = $custody->request?->currentVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'pickup_at' => 'The approved request schedule could not be found.',
            ]);
        }

        $approvedSchedule = $version->getAttribute('schedule_date')
            ?: $version->getAttribute('needed_from');

        if (! $approvedSchedule) {
            throw ValidationException::withMessages([
                'pickup_at' => 'The approved Schedule Date could not be found.',
            ]);
        }

        $approvedDate = CarbonImmutable::parse($approvedSchedule, $timezone)->startOfDay();
        $effectivePickupDate = $this->operationalCalendar->nextOpenDate(
            OperationalCalendarService::PICKUP,
            $approvedDate,
            true
        );

        if ($pickup->toDateString() !== $effectivePickupDate->toDateString()) {
            $message = $effectivePickupDate->isSameDay($approvedDate)
                ? 'Pickup must be scheduled on the approved Schedule Date: '.$approvedDate->format('F j, Y').'.'
                : 'The approved Schedule Date '.$approvedDate->format('F j, Y').' is closed for pickup/release. Schedule the pickup on the next open operational date: '.$effectivePickupDate->format('F j, Y').'.';

            throw ValidationException::withMessages([
                'pickup_at' => $message,
            ]);
        }

        if ($expires->toDateString() !== $pickup->toDateString()) {
            throw ValidationException::withMessages([
                'pickup_expires_at' => 'Pickup time and pickup expiration must be on the same calendar date.',
            ]);
        }

        if ($expires->lte($pickup)) {
            throw ValidationException::withMessages([
                'pickup_expires_at' => 'Pickup expiration must be later than the pickup time.',
            ]);
        }

        DB::transaction(function () use ($custody, $spmu, $pickup, $expires): void {
            $locked = CustodyTransaction::query()
                ->lockForUpdate()
                ->findOrFail($custody->id);

            if ($locked->status !== 'PREPARING_RELEASE' || $locked->released_at) {
                throw ValidationException::withMessages([
                    'pickup_at' => 'This pickup transaction has already moved to another state.',
                ]);
            }

            $before = [
                'scheduled_release_at' => $locked->scheduled_release_at,
                'pickup_expires_at' => $locked->pickup_expires_at,
                'pickup_scheduled_by_user_id' => $locked->pickup_scheduled_by_user_id,
                'prepared_at' => $locked->prepared_at,
            ];

            $locked->update([
                'scheduled_release_at' => $pickup,
                'pickup_expires_at' => $expires,
                'pickup_scheduled_by_user_id' => $spmu->id,
                'pickup_scheduled_at' => now(),
                'pickup_expired_at' => null,
                // Scheduling/rescheduling does not erase a preparation that was already confirmed.
            ]);

            $this->audit->record(
                'PICKUP_SCHEDULED',
                $locked,
                before: $before,
                after: [
                    'pickup_at' => $pickup->toIso8601String(),
                    'pickup_expires_at' => $expires->toIso8601String(),
                    'scheduled_by_user_id' => $spmu->id,
                ]
            );

            $locked->loadMissing('borrower');

            if ($locked->borrower) {
                $locked->loadMissing('lines.requestItem');
                $requiredDocuments = $locked->lines->contains(
                    fn ($line) => $line->requestItem?->use_location === 'OFF_CAMPUS'
                )
                    ? 'the generated Borrower Slip and Gate Pass'
                    : 'the generated Borrower Slip';

                $this->notifications->send(
                    'PICKUP_SCHEDULED',
                    collect([$locked->borrower]),
                    "Pickup for {$locked->custody_no} is scheduled on {$pickup->format('F j, Y g:i A')} and may be claimed until {$expires->format('g:i A')}. Proceed to SPMU within this window and bring {$requiredDocuments}.",
                    $locked
                );
            }
        }, 3);
    }

    public function updateReceiptQuantities(CustodyTransaction $custody, User $spmu, array $quantities, array $reasons): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        DB::transaction(function () use ($custody, $quantities): void {
            foreach ($custody->lines()->get() as $line) {
                $approved = (float) $line->approved_quantity;
                $quantity = (float) ($quantities[$line->id] ?? $approved);

                if (abs($quantity - $approved) > 0.000001) {
                    throw ValidationException::withMessages([
                        'quantities' => 'Prepared quantity must exactly match the verified approved quantity. Revise the request if the quantity must change.',
                    ]);
                }

                $line->update([
                    'quantity_to_receive' => $approved,
                    'adjustment_reason' => null,
                ]);
            }

            $custody->update([
                'prepared_at' => null,
                'prepared_by_user_id' => null,
            ]);

            $this->audit->record(
                'FINAL_ISSUED_QUANTITY_RECORDED',
                $custody,
                after: ['quantities' => $quantities]
            );
        });
    }

    public function prepare(CustodyTransaction $custody, User $spmu, array $quantities): void
    {
        abort_unless(
            $spmu->access_classification === AccessClassification::SpmuOfficer
                && $custody->borrower_user_id !== $spmu->id
                && $custody->status === 'PREPARING_RELEASE'
                && ! $custody->released_at,
            403
        );

        $documentIds = [];

        DB::transaction(function () use ($custody, $spmu, $quantities, &$documentIds): void {
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

            /*
             * The approved packet must already exist before preparation is
             * persisted. This validates Head-generated documents and never
             * creates a substitute Gate Pass or Borrower Slip at this stage.
             */
            $documentIds = $this->validateApprovedReleaseDocuments($custody);

            foreach ($custody->lines as $line) {
                if (! array_key_exists($line->id, $quantities)) {
                    throw ValidationException::withMessages([
                        'quantities' => 'Enter the actual prepared quantity for every approved item.',
                    ]);
                }

                $approved = (float) $line->approved_quantity;
                $prepared = (float) $quantities[$line->id];

                if (abs($prepared - $approved) > 0.000001) {
                    throw ValidationException::withMessages([
                        'quantities' => "Prepared quantity does not match the approved request for {$line->requestItem->description_snapshot}. Approved: {$approved}; prepared: {$prepared}. Recheck the count. Release processing remains blocked until the actual prepared quantity matches the approved quantity.",
                    ]);
                }

                $line->update([
                    'quantity_to_receive' => $approved,
                    'item_status' => 'PREPARED',
                    'adjustment_reason' => null,
                ]);
            }

            $custody->update([
                'prepared_by_user_id' => $spmu->id,
                'prepared_at' => now(),
            ]);
        }, 3);

        $fresh = $custody->fresh([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        $this->audit->record(
            'RELEASE_PREPARED',
            $fresh,
            reason: 'SPMU Action Officer confirmed every Actual Prepared quantity and validated the approved/generated release documents against the allocation.',
            after: [
                'prepared_quantities' => $quantities,
                'borrower_slip_document_id' => $documentIds['borrower_slip'],
                'gate_pass_document_id' => $documentIds['gate_pass'],
                'laundry_form_document_id' => $documentIds['laundry_form'],
            ]
        );
    }

    public function acknowledge(CustodyTransaction $custody, User $borrower): void
    {
        abort_unless($custody->borrower_user_id === $borrower->id && $custody->status === 'PREPARING_RELEASE', 403);
        if (! $custody->prepared_at) {
            throw ValidationException::withMessages(['acknowledge' => 'SPMU must verify the prepared quantities before borrower acknowledgement.']);
        }
        DB::transaction(function () use ($custody): void {
            $hasLinen = $custody->lines()
                ->whereHas('requestItem.inventoryItem', fn ($query) => $query->where('laundry_required', true))
                ->exists();

            /*
             * This is a system acknowledgement only. It is NOT an electronic
             * signature. All documents that require signatures are printed,
             * signed by hand, scanned, and uploaded/verified as evidence.
             * Legacy signature columns are explicitly cleared and are not used
             * by the active workflow.
             */
            $custody->update([
                'borrower_ack_signature_snapshot_id' => null,
                'laundry_borrower_signature_snapshot_id' => null,
                'laundry_approver_signature_snapshot_id' => null,
                'acknowledged_at' => now(),
            ]);

            $custody->lines()->update(['item_status' => 'PREPARED']);

            /*
             * Compatibility acknowledgement must never replace the Borrower
             * Slip generated from the Action Officer's confirmed preparation.
             * The physical Borrower Slip is printed and wet-signed during
             * handover; there is no borrower-generated/e-signed slip version.
             */
            if ($hasLinen) {
                $this->documents->replaceConditionalForm($custody->fresh(), 'LAUNDRY_FORM');
            } else {
                $this->documents->refreshPacketIfReady($custody->fresh());
            }

            $this->audit->record(
                'BORROWER_RECEIPT_ACKNOWLEDGED',
                $custody,
                after: [
                    'acknowledged_at' => $custody->fresh()->acknowledged_at?->toIso8601String(),
                    'signature_method' => 'NONE_SYSTEM_ACKNOWLEDGEMENT_ONLY',
                ]
            );
        });
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
                'status' => 'ACTIVE',
            ]);

            /*
             * Regenerate the Borrower Slip after physical release so the
             * controlled digital copy reflects the issued transaction state.
             * The Action Officer's operational identity and timestamp remain
             * in the custody/audit records; no release E-signature is rendered.
             */
            $this->documents->borrowerSlip(
                $custody->fresh([
                    'borrower',
                    'releasedBy',
                    'releaseSignature.file',
                    'request.borrower',
                    'request.currentVersion.borrowerSignature.file',
                    'request.currentVersion.approvalSteps.approver',
                    'request.currentVersion.approvalSteps.signatureSnapshot.file',
                    'lines.requestItem.inventoryItem',
                    'returns.receivedBy',
                    'returns.inspectionSignature.file',
                ])
            );

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
            $this->notifications->send('ITEMS_RELEASED', collect([$custody->borrower]), "Items under {$custody->custody_no} were physically released. Effective Return Date: {$custody->due_at->format('F j, Y')}.", $custody);

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
                        "A linen transaction under {$custody->custody_no} is for Laundry. The borrower will bring the used linen and physical Laundry Form after use.",
                        $custody,
                        ['SYSTEM']
                    );
                }
            }
        }, 3);
    }

    public function receiveReturn(CustodyTransaction $custody, User $spmu, array $quantities, array $conditions, ?string $remarks, array $policeBlotterReferences = [], array $evidenceFileIds = [], array $conditionBreakdowns = []): ReturnTransaction
    {
        abort_unless($spmu->access_classification === AccessClassification::SpmuOfficer && $custody->borrower_user_id !== $spmu->id, 403);

        return DB::transaction(function () use ($custody, $spmu, $quantities, $conditions, $remarks, $policeBlotterReferences, $evidenceFileIds, $conditionBreakdowns): ReturnTransaction {
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
             * Revised linen return rule:
             * SPMU inspects linen WHEN THE BORROWER RETURNS IT, not after the
             * washing cycle. The Action Officer records the same Fine/Damaged/
             * Missing/etc. findings used for all other property. Fine linen
             * moves to the LAUNDRY inventory
             * state and remains unavailable until SPMU later records internal
             * laundry completion. The borrower's transaction only waits for
             * Laundry Personnel to physically receive the linen and wet-sign
             * the same printed Laundry Form.
             */
            $eligibleLines = $custody->lines->filter(function ($line): bool {
                return max(
                    0,
                    (float) $line->actual_released_quantity - (float) $line->returned_quantity
                ) > 0;
            });

            /*
             * A zero-total line simply means that item is not part of this
             * inspection yet. Once any quantity is entered for an item, the
             * COMPLETE outstanding quantity for that item must be accounted in
             * the same inspection. This prevents 8-now / 2-later returns while
             * still allowing different item lines (and the Laundry branch) to
             * reach SPMU at different times under the single overall
             * RETURN_PROCESSING state.
             */
            $returnableLines = $eligibleLines->filter(
                fn ($line) => array_sum($normalizedBreakdowns[$line->id]) > 0
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

                if ($nonFine > 0
                    && empty($evidenceFileIds[$line->id])) {
                    throw ValidationException::withMessages([
                        'evidence_files' => 'Supporting evidence is required for every item with damaged, destroyed, missing, lost, or stolen quantity.',
                    ]);
                }

                if (($normalizedBreakdowns[$line->id]['STOLEN'] ?? 0) > 0
                    && trim((string) ($policeBlotterReferences[$line->id] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'police_blotter_references' => 'A police-blotter reference is required for every stolen quantity.',
                    ]);
                }
            }

            $return = ReturnTransaction::query()->create([
                'return_no' => 'RET-'.now()->format('YmdHis').'-'.$custody->id,
                'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $spmu->id,
                'return_type' => $isEarlyReturn ? 'EARLY' : ($isOverdueReturn ? 'OVERDUE' : 'NORMAL'),
                'received_at' => now(),
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
            $transactionId = $this->transactionHeader('PHYSICAL_RETURN', $return, $spmu, $remarks ?: 'Physical return and full-quantity accountability inspection.');

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
                            'incident_id' => $incident->id,
                            'status' => 'ACTIVE',
                        ], [
                            'restriction_type' => 'UNRESOLVED_INCIDENT',
                            'reason' => 'Unresolved '.$condition.' incident '.$incident->incident_no.'.',
                            'effective_from' => now(),
                            'imposed_by_user_id' => $spmu->id,
                        ]);

                        if (SystemSetting::value('rslddp_template_status') === 'APPROVED') {
                            $this->documents->rslddp($incident->fresh());
                        }
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
                     * The Action Officer has finished the borrower-side return
                     * inspection. Keep the LaundryJob at FOR_LAUNDRY until the
                     * physical Laundry Form comes back with the Laundry
                     * Personnel's wet "Received by" signature. Confirming that
                     * handover is the point at which Laundry stops blocking the
                     * borrower's transaction.
                     */
                    $laundryJob->update([
                        'status' => 'FOR_LAUNDRY',
                        'completed_at' => null,
                    ]);

                    $custody->lines()
                        ->whereHas(
                            'requestItem.inventoryItem',
                            fn ($query) => $query->where('laundry_required', true)
                        )
                        ->update([
                            'compliance_status' => 'LAUNDRY_RECEIPT_PENDING',
                        ]);
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
                 * PENDING_RETURN means that physical property is still
                 * outstanding. Once every released quantity for this custody
                 * has been physically returned, lift that restriction unless
                 * the same borrower still has another custody with an
                 * outstanding issued quantity.
                 */
                $hasOtherOutstandingCustody = CustodyTransaction::query()
                    ->where('borrower_user_id', $custody->borrower_user_id)
                    ->whereKeyNot($custody->id)
                    ->whereHas(
                        'lines',
                        fn ($query) =>
                            $query->whereColumn(
                                'returned_quantity',
                                '<',
                                'actual_released_quantity'
                            )
                    )
                    ->exists();

                if (! $hasOtherOutstandingCustody) {
                    BorrowerRestriction::query()
                        ->where('borrower_user_id', $custody->borrower_user_id)
                        ->where('restriction_type', 'PENDING_RETURN')
                        ->where('status', 'ACTIVE')
                        ->update([
                            'status' => 'LIFTED',
                            'effective_to' => now(),
                            'lifted_by_user_id' => $spmu->id,
                        ]);
                }
            }

            if ($overdue && $allReturned && $overdue->status !== 'RESOLVED') {
                $rate = SystemSetting::value('daily_overdue_tariff');
                $days = max(
                    1,
                    (int) ceil(
                        $overdue->grace_expires_at->diffInMinutes(now())
                        / 1440
                    )
                );

                $overdue->update([
                    'rate_snapshot' => is_numeric($rate) ? $rate : null,
                    'accrued_amount' => is_numeric($rate)
                        ? round($days * (float) $rate, 2)
                        : 0,
                    'status' => 'RETURNED_PENDING_SETTLEMENT',
                ]);

                /*
                 * The physical-return restriction is finished at this point,
                 * but the late-return accountability remains open until the
                 * assessed fee is settled or formally waived.
                 */
                BorrowerRestriction::query()->firstOrCreate(
                    [
                        'borrower_user_id' => $custody->borrower_user_id,
                        'restriction_type' => 'OVERDUE_RETURN',
                        'status' => 'ACTIVE',
                    ],
                    [
                        'reason' =>
                            'Late return under '
                            .$custody->custody_no
                            .' is awaiting accountability settlement.',
                        'effective_from' => now(),
                        'imposed_by_user_id' => $spmu->id,
                    ]
                );
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
             * The Borrower Slip was originally generated before the return.
             * Replace it now with the controlled post-inspection copy so its
             * return section reflects adverse findings (if any) and the
             * persisted Date Returned, without an Action Officer E-signature.
             */
            $this->documents->borrowerSlip(
                $custody->fresh([
                    'borrower',
                    'releasedBy',
                    'releaseSignature.file',
                    'request.borrower',
                    'request.currentVersion.borrowerSignature.file',
                    'request.currentVersion.approvalSteps.approver',
                    'request.currentVersion.approvalSteps.signatureSnapshot.file',
                    'lines.requestItem.inventoryItem',
                    'returns.receivedBy',
                    'returns.inspectionSignature.file',
                ])
            );

            $this->notifications->send('RETURN_INSPECTED', collect([$custody->borrower]), "Return {$return->return_no} was physically counted and inspected. Status: {$status}.", $custody, ['SYSTEM', 'EMAIL']);

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
                "Early Return {$earlyReturn->early_return_no} was requested for {$custody->custody_no}. This is coordination only; actual returned quantities and conditions are recorded by SPMU during physical Return & Inspection.",
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

        $hasOpenIncident = Incident::query()
            ->where('custody_transaction_id', $custody->id)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->exists();

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
