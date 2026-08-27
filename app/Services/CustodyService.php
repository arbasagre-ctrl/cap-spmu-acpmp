<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\GatePass;
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

        $custody->loadMissing('request.currentVersion', 'borrower');
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
                $this->notifications->send(
                    'PICKUP_SCHEDULED',
                    collect([$locked->borrower]),
                    "Pickup for {$locked->custody_no} is scheduled on {$pickup->format('F j, Y g:i A')} and may be claimed until {$expires->format('g:i A')}.",
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

        $custody->loadMissing([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        if (! $custody->hasPickupSchedule()) {
            throw ValidationException::withMessages([
                'preparation' => 'Set an active pickup schedule before confirming item preparation.',
            ]);
        }

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
        }

        DB::transaction(function () use ($custody, $spmu, $quantities): void {
            foreach ($custody->lines()->get() as $line) {
                $approved = (float) $line->approved_quantity;
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
        });

        $fresh = $custody->fresh([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem',
            'gatePass',
        ]);

        /*
         * The printable Borrower Slip / Laundry Form / Gate Pass are created
         * at approval time so the borrower can print them before coming to
         * SPMU. Preparation verifies the same approved quantities and must not
         * silently supersede a document the borrower may already have printed.
         * A missing document is regenerated only as a recovery fallback.
         */
        $borrowerSlip = GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $fresh->id)
            ->where('document_type', 'BORROWER_SLIP')
            ->where('status', 'FINAL')
            ->latest('id')
            ->first();

        if (! $borrowerSlip) {
            $this->documents->borrowerSlip($fresh);
        }

        $offCampusLine = $fresh->lines->first(
            fn ($line) =>
                $line->requestItem?->use_location === 'OFF_CAMPUS'
                && (float) $line->quantity_to_receive > 0
        );

        if ($offCampusLine) {
            $gatePassDocument = GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $fresh->id)
                ->where('document_type', 'GATE_PASS')
                ->where('status', 'FINAL')
                ->latest('id')
                ->first();

            if (! $gatePassDocument) {
                $gatePassDocument = $this->documents->conditionalForm(
                    $fresh,
                    'GATE_PASS'
                );
            }

            GatePass::query()->updateOrCreate(
                ['custody_transaction_id' => $fresh->id],
                [
                    'custody_line_id' => $offCampusLine->id,
                    'pass_document_id' => $gatePassDocument->id,
                    'bearer_name' => $fresh->borrower?->full_name,
                    'destination' => $fresh->request?->currentVersion?->location,
                    'purpose' => $fresh->request?->currentVersion?->purpose_event,
                    'status' => $fresh->gatePass?->status === 'VERIFIED'
                        ? 'VERIFIED'
                        : 'PENDING',
                ]
            );
        }

        $hasLaundry = $fresh->lines->contains(
            fn ($line) =>
                (bool) $line->requestItem?->inventoryItem?->laundry_required
                && (float) $line->quantity_to_receive > 0
        );

        if ($hasLaundry) {
            $laundryDocumentExists = GeneratedDocument::query()
                ->where('subject_type', CustodyTransaction::class)
                ->where('subject_id', $fresh->id)
                ->where('document_type', 'LAUNDRY_FORM')
                ->where('status', 'FINAL')
                ->exists();

            if (! $laundryDocumentExists) {
                $this->documents->conditionalForm(
                    $fresh->fresh(),
                    'LAUNDRY_FORM'
                );
            }
        }

        $this->audit->record(
            'RELEASE_PREPARED',
            $fresh,
            reason: 'SPMU Action Officer confirmed every Actual Prepared quantity against the approved allocation. Existing borrower-printable documents remain valid for physical handover.',
            after: ['prepared_quantities' => $quantities]
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

    public function release(CustodyTransaction $custody, User $spmu, ?string $remarks = null): void
    {
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
                'release' => 'Set an active pickup schedule before recording the physical release.',
            ]);
        }

        $custody->loadMissing('request.currentVersion', 'gatePass', 'lines.requestItem.inventoryItem');
        $hasOffCampusItem = $custody->lines->contains(
            fn ($line) => $line->requestItem->use_location === 'OFF_CAMPUS'
        );

        if ($hasOffCampusItem && ! $custody->gatePass) {
            throw ValidationException::withMessages([
                'release' => 'Generate and print the Gate Pass before release. Required signatures are handwritten/wet signatures on the printed form; the signed scan is uploaded and verified as evidence.',
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
             * digitally approved by the Laundry Worker or borrower.
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

            $custody->loadMissing('lines.requestItem.inventoryItem', 'lines.allocation', 'borrower', 'request');

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
                'released_at' => now(),
                'status' => 'ACTIVE',
            ]);

            /*
             * Off-campus Gate Passes are physical wet-signed forms.
             * After physical issuance, the current form is ready to be
             * accomplished by the guard and returned as a scan. No digital
             * SPMU signature/approval step is required.
             */
            $custody->gatePass()
                ->where('status', '!=', 'VERIFIED')
                ->update([
                    'status' => 'READY_FOR_PRINTING',
                    'prepared_verified_by_user_id' => null,
                    'prepared_verifier_signature_snapshot_id' => null,
                    'prepared_verified_at' => null,
                    'approved_by_user_id' => null,
                    'approver_signature_snapshot_id' => null,
                    'temporary_delegation_id' => null,
                    'approved_at' => null,
                ]);

            $this->audit->record('ITEMS_RELEASED', $custody, after: ['released_by' => $spmu->id, 'released_at' => now()->toIso8601String()]);
            $this->notifications->send('ITEMS_RELEASED', collect([$custody->borrower]), "Items under {$custody->custody_no} were physically released. Effective Return Date: {$custody->due_at->format('F j, Y')}.", $custody);

            if ($hasLinen) {
                $laundryWorkers = User::query()
                    ->where(
                        'access_classification',
                        AccessClassification::LaundryWorker->value
                    )
                    ->where('account_status', 'ACTIVE')
                    ->get();

                if ($laundryWorkers->isNotEmpty()) {
                    $this->notifications->send(
                        'LINEN_FOR_LAUNDRY',
                        $laundryWorkers,
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

            if (! in_array($custody->status, ['ACTIVE', 'RETURN_PROCESSING', 'OVERDUE', 'INCIDENT_OPEN'], true)) {
                throw ValidationException::withMessages(['return' => 'This custody record is no longer open for a physical return.']);
            }

            $custody->setRelation('lines', $custody->lines()->with('requestItem.inventoryItem')->lockForUpdate()->get());
            $custody->loadMissing('borrower');

            /*
             * DATE-BASED RETURN POLICY
             * ------------------------
             * Borrowed property is returnable on the Expected Return Date.
             * A physical return cannot be recorded before that calendar date.
             * If property remains outstanding on the following calendar day,
             * the deadline processor marks the custody OVERDUE.
             */
            if ($custody->due_at && now()->startOfDay()->lt($custody->due_at->copy()->startOfDay())) {
                throw ValidationException::withMessages([
                    'return' => 'Return inspection may be recorded only on the Expected Return Date or after it.',
                ]);
            }

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
             * Non-linen can be finalized immediately at SPMU. Linen becomes
             * eligible only after Laundry has finished processing it and the
             * Laundry Worker is ready to hand the cleaned linen plus the same
             * physical Laundry Form directly to SPMU. The form upload happens
             * only AFTER SPMU final physical acceptance/signature.
             */
            $eligibleLines = $custody->lines->filter(function ($line) use ($laundryJob): bool {
                $outstanding = max(
                    0,
                    (float) $line->actual_released_quantity - (float) $line->returned_quantity
                );

                if ($outstanding <= 0) {
                    return false;
                }

                if (! (bool) $line->requestItem->inventoryItem->laundry_required) {
                    return true;
                }

                if (! $laundryJob) {
                    return true; // legacy pre-current-laundry record
                }

                return $laundryJob->status === 'READY_FOR_SPMU_RETURN';
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
                        .': this item is not yet eligible for SPMU final return inspection. Complete the required Laundry workflow first.',
                ]);
            }

            if ($returnableLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'return' => 'Select at least one item that is ready for SPMU return inspection and account for its complete outstanding quantity.',
                ]);
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

                $usesLaundrySupportingForm =
                    (bool) $line->requestItem?->inventoryItem?->laundry_required
                    && $laundryJob;

                if ($nonFine > 0
                    && ! $usesLaundrySupportingForm
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
                'return_type' => 'NORMAL',
                'received_at' => now(),
                'status' => 'INSPECTED',
                'remarks' => $remarks,
            ]);
            $transactionId = $this->transactionHeader('PHYSICAL_RETURN', $return, $spmu, $remarks ?: 'Physical return and full-quantity accountability inspection.');

            foreach ($returnableLines as $line) {
                $item = $line->requestItem->inventoryItem;
                $breakdown = $normalizedBreakdowns[$line->id];
                $accounted = array_sum($breakdown);
                $blotterReference = trim((string) ($policeBlotterReferences[$line->id] ?? ''));
                $hasIncident = false;
                $hasLaundryDisposition = false;

                if ($item->laundry_required && $laundryJob) {
                    /*
                     * Current linen flow:
                     * Borrower -> Laundry Worker -> SPMU Action Officer archive.
                     * The Laundry Worker returns the cleaned linen and the same
                     * physical form directly to SPMU. SPMU performs the final
                     * physical quantity/condition inspection before the worker
                     * uploads the fully signed form for settlement.
                     */
                    if ($laundryJob->status !== 'READY_FOR_SPMU_RETURN') {
                        throw ValidationException::withMessages([
                            'return' =>
                                'This linen cannot be finalized yet. Laundry must finish processing and bring the cleaned linen plus the physical Laundry Form directly to SPMU first.',
                        ]);
                    }
                }

                foreach ($breakdown as $condition => $quantity) {
                    $quantity = (float) $quantity;

                    if ($quantity <= 0) {
                        continue;
                    }

                    if ($item->laundry_required && $laundryJob) {
                        $disposition = $condition === 'FINE'
                            ? 'AVAILABLE'
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

                        LaundryRecord::query()->create([
                            'return_line_id' => $returnLine->id,
                            'cleaned_quantity' => 0,
                            'damaged_quantity' => 0,
                            'status' => 'PENDING_EVIDENCE',
                        ]);
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

                if ($allLaundryReturned) {
                    /*
                     * SPMU has now physically accepted/accounted for all linen.
                     * The SPMU Action Officer archives the accomplished physical Laundry
                     * Form as the supporting document. Laundry is not settled
                     * until that final form is successfully uploaded.
                     */
                    $laundryJob->update([
                        'status' => 'AWAITING_FINAL_FORM_UPLOAD',
                        'completed_at' => null,
                    ]);

                    $custody->lines()
                        ->whereHas(
                            'requestItem.inventoryItem',
                            fn ($query) =>
                                $query->where('laundry_required', true)
                        )
                        ->update([
                            'compliance_status' => 'LAUNDRY_FINAL_FORM_PENDING',
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
            $hasOpenIncident = Incident::query()
                ->where('custody_transaction_id', $custody->id)
                ->whereNotIn('status', ['RESOLVED', 'CLOSED'])
                ->exists();
            $hasOpenLegacyLaundry = LaundryRecord::query()
                ->whereHas('returnLine.custodyLine', fn ($query) => $query->where('custody_transaction_id', $custody->id))
                ->where('status', '!=', 'VERIFIED')
                ->exists();
            $hasOpenCurrentLaundry = LaundryJob::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', '!=', 'LAUNDRY_COMPLETED')
                ->exists();
            $hasOpenLaundry = $hasOpenLegacyLaundry || $hasOpenCurrentLaundry;
            $hasOpenOverdue = OverdueCase::query()
                ->where('custody_transaction_id', $custody->id)
                ->where('status', '!=', 'RESOLVED')
                ->exists();
            $hasOpenGatePass = $custody->gatePass()
                ->where('status', '!=', 'VERIFIED')
                ->exists();
            $hasOpenObligation = $hasOpenIncident || $hasOpenLaundry || $hasOpenOverdue || $hasOpenGatePass;
            $status = match (true) {
                $allReturned && $hasOpenObligation => 'OBLIGATION_OPEN',
                $allReturned => 'CLOSED',
                $custody->status === 'OVERDUE' => 'OVERDUE',
                default => 'RETURN_PROCESSING',
            };
            $custody->update(['status' => $status, 'closed_at' => $status === 'CLOSED' ? now() : null]);
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
            $this->audit->record('RETURN_INSPECTED', $return, after: ['custody_status' => $status]);
            $this->notifications->send('RETURN_INSPECTED', collect([$custody->borrower]), "Return {$return->return_no} was physically counted and inspected. Status: {$status}.", $custody, ['SYSTEM', 'EMAIL']);

            return $return;
        }, 3);
    }



    private function spmuRecipients(): Collection
    {
        return User::query()->whereHas('roles', fn ($query) => $query->where('role_code', UserRole::Spmu->value)->whereNull('user_roles.revoked_at'))->get();
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
