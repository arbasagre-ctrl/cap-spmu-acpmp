<?php

namespace App\Services;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\DownloadEvent;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\RequestCancellation;
use App\Models\RequestStatusHistory;
use App\Models\RequestSupportingDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestWorkflowService
{
    public function __construct(
        private InventoryService $inventory,
        private CustodyService $custody,
        private DocumentService $documents,
        private NotificationService $notifications,
        private AuditService $audit,
    ) {}

    /**
     * Submit the borrower's request for SPMU verification.
     *
     * Submission does not create an inventory reservation.
     * Inventory is reserved only after SPMU verifies and
     * approves the submitted request.
     */
    public function submit(
        BorrowingRequest $request,
        User $borrower
    ): void {
        if (! $borrower->mayBorrow()) {
            abort(
                403,
                'This account classification is not permitted to borrow.'
            );
        }

        if (
            $request->borrower_user_id !== $borrower->id
            || ! in_array(
                $request->status,
                [
                    RequestStatus::Draft,
                    RequestStatus::ReturnedForRevision,
                ],
                true
            )
        ) {
            abort(403);
        }

        $outstandingCustody = CustodyTransaction::query()
            ->where('borrower_user_id', $borrower->id)
            ->whereIn('status', [
                'ACTIVE',
                'RETURN_PROCESSING',
                'OVERDUE',
                'INCIDENT_OPEN',
                'OBLIGATION_OPEN',
            ])
            ->latest('id')
            ->first();

        if ($outstandingCustody) {
            throw ValidationException::withMessages([
                'restriction' =>
                    "You cannot submit a new borrowing request while {$outstandingCustody->custody_no} has an outstanding return or unresolved obligation.",
            ]);
        }

        if ($borrower->activeRestrictions()->exists()) {
            $reason = $borrower->activeRestrictions()->latest('effective_from')->value('reason');

            throw ValidationException::withMessages([
                'restriction' =>
                    $reason
                        ? 'Borrowing is currently restricted: '.$reason
                        : 'You currently have an unresolved borrowing obligation. Resolve it with SPMU before submitting another request.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $borrower
            ): void {
                $request->loadMissing([
                    'currentVersion.items.inventoryItem',
                    'currentVersion.supportingDocuments',
                ]);

                $version = $request->currentVersion;

                if (
                    ! $version
                    || $version->items->isEmpty()
                ) {
                    throw ValidationException::withMessages([
                        'items' =>
                            'Add at least one inventory item before submission.',
                    ]);
                }

                $scheduleDate = ($version->schedule_date ?: $version->needed_from)
                    ->copy()
                    ->startOfDay();

                $returnDate = ($version->return_date ?: $version->return_due_at)
                    ->copy()
                    ->startOfDay();

                if (
                    ! $scheduleDate->gt(now()->startOfDay())
                    || ! $returnDate->gt($scheduleDate)
                ) {
                    throw ValidationException::withMessages([
                        'schedule_date' =>
                            'Schedule Date must be a future calendar date and Return Date must be after Schedule Date.',
                    ]);
                }

                /*
                 * Make sure the requested inventory items
                 * are still valid borrowable items.
                 *
                 * Do NOT reserve inventory here.
                 */
                foreach (
                    $version->items
                    as $requestItem
                ) {
                    $item = $requestItem->inventoryItem;

                    if (
                        ! $item
                        || ! $item->active
                        || ! $item->borrowable
                        || $item->condition_code !== 'SERVICEABLE'
                    ) {
                        throw ValidationException::withMessages([
                            'items' =>
                                "{$requestItem->description_snapshot} is no longer available for borrowing. Revise the request before submitting.",
                        ]);
                    }

                    if (
                        $requestItem->use_location
                            === 'OFF_CAMPUS'
                        && ! $item->off_campus_allowed
                    ) {
                        throw ValidationException::withMessages([
                            'locations' =>
                                "{$item->unique_description} is restricted to On-Campus use.",
                        ]);
                    }
                }

                /*
                 * The scanned approved Borrowing Request
                 * Letter is required.
                 *
                 * Permission to Conduct is also required
                 * when the request represents a student
                 * activity or organization.
                 */
                $this->validateRequiredSupportingDocuments(
                    $version
                );

                /*
                 * No electronic signature is created.
                 *
                 * Authentication, request version,
                 * submission timestamp, status history,
                 * and audit history identify the borrower
                 * who submitted the transaction.
                 */
                $version->update([
                    'borrower_signature_snapshot_id' =>
                        null,

                    'accuracy_certified' =>
                        true,

                    'signed_at' =>
                        null,

                    'submitted_at' =>
                        now(),
                ]);

                /*
                 * A submitted request has one system
                 * verification stage: SPMU.
                 *
                 * Any institutional approvals/signatures
                 * that were required before submission are
                 * represented by the uploaded approved
                 * scanned documents.
                 */
                $version
                    ->approvalSteps()
                    ->delete();

                ApprovalStep::query()->create([
                    'request_version_id' =>
                        $version->id,

                    'stage_code' =>
                        ApprovalStage::Spmu,

                    'sequence_no' =>
                        1,

                    'received_at' =>
                        now(),

                    'decision' =>
                        'RECEIVED',
                ]);

                /*
                 * Current uploaded supporting documents
                 * are awaiting SPMU verification.
                 */
                $version
                    ->supportingDocuments()
                    ->where(
                        'is_current',
                        true
                    )
                    ->update([
                        'verification_status' =>
                            RequestSupportingDocument::STATUS_PENDING,

                        'verified_by_user_id' =>
                            null,

                        'verified_at' =>
                            null,

                        'verification_remarks' =>
                            null,
                    ]);

                /*
                 * Submission only routes the request to
                 * SPMU. No allocation/reservation yet.
                 */
                $this->transition(
                    $request,
                    RequestStatus::UnderSpmu,
                    $borrower,
                    'Borrowing request and approved scanned supporting document(s) submitted to SPMU for verification.'
                );

                $this->audit->record(
                    'REQUEST_SUBMITTED',
                    $request,
                    reason:
                        'Request routed to SPMU for document and inventory verification. No reservation was created at submission.'
                );

                $heads = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where('access_classification', AccessClassification::SpmuHead->value)
                    ->get();

                $this->notifications->send(
                    'REQUEST_SUBMITTED',
                    $heads,
                    "Request {$request->request_no} is ready for SPMU Head review and decision.",
                    $request,
                    ['SYSTEM', 'EMAIL'],
                );
            },
            3
        );
    }

    /**
     * Record the SPMU verification decision.
     *
     * APPROVED
     * - final inventory availability is checked
     * - approved quantity becomes RESERVED
     *
     * RETURNED_FOR_REVISION
     * - borrower must correct the request/documents
     * - no reservation is created
     *
     * REJECTED
     * - request is closed as rejected
     * - no reservation is created
     */
    public function decide(
        BorrowingRequest $request,
        User $approver,
        string $decision,
        ?string $remarks
    ): void {
        $decision = strtoupper($decision);

        if (
            $request->status
            !== RequestStatus::UnderSpmu
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'This request is no longer awaiting SPMU verification.',
            ]);
        }

        if (
            $approver->primaryWorkspace()
                !== UserRole::Spmu->value
            || ! $approver->hasRole(
                UserRole::Spmu
            )
        ) {
            abort(
                403,
                'Only authorized SPMU personnel may verify this request.'
            );
        }

        $isSpmuHead =
            $approver->access_classification
                === AccessClassification::SpmuHead;

        $activeSpmuDelegation =
            $approver->access_classification
                === AccessClassification::SpmuOfficer
                ? $approver->activeDelegationFor(UserRole::Spmu->value)
                : null;

        if (! $isSpmuHead && ! $activeSpmuDelegation) {
            abort(
                403,
                'Final borrowing decisions are reserved for the SPMU Head or a formally delegated SPMU Action Officer.'
            );
        }

        if (
            $request->borrower_user_id
                === $approver->id
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'A user cannot verify their own borrowing request.',
            ]);
        }

        if (
            ! in_array(
                $decision,
                [
                    'APPROVED',
                    'REJECTED',
                    'RETURNED_FOR_REVISION',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'decision' =>
                    'Choose a valid verification decision.',
            ]);
        }

        if (
            in_array(
                $decision,
                [
                    'REJECTED',
                    'RETURNED_FOR_REVISION',
                ],
                true
            )
            && blank($remarks)
        ) {
            throw ValidationException::withMessages([
                'remarks' =>
                    'Remarks are required when rejecting or returning a request for revision.',
            ]);
        }

        DB::transaction(
            function () use (
                $request,
                $approver,
                $decision,
                $remarks
            ): void {
                $request->loadMissing([
                    'borrower',
                    'currentVersion.items.inventoryItem',
                    'currentVersion.approvalSteps',
                    'currentVersion.supportingDocuments',
                ]);

                $version = $request->currentVersion;

                if (! $version) {
                    throw ValidationException::withMessages([
                        'decision' =>
                            'The current request version could not be found.',
                    ]);
                }

                $step = $version->approvalSteps
                    ->where('sequence_no', 1)
                    ->firstWhere('stage_code', ApprovalStage::Spmu);

                $temporaryDelegationId =
                    $approver->access_classification === AccessClassification::SpmuOfficer
                        ? $approver->activeDelegationFor(UserRole::Spmu->value)?->id
                        : null;

                if (! $step || ! in_array($step->decision, ['PENDING', 'RECEIVED'], true)) {
                    throw ValidationException::withMessages([
                        'decision' => 'This SPMU approval step has already been completed.',
                    ]);
                }

                /*
                 * ===============================
                 * REJECT
                 * ===============================
                 */
                if (
                    $decision === 'REJECTED'
                ) {
                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'REJECTED',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $remarks,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    $this->markSupportingDocuments(
                        $version,
                        RequestSupportingDocument::STATUS_REJECTED,
                        $approver,
                        $remarks
                    );

                    $this->transition(
                        $request,
                        RequestStatus::Rejected,
                        $approver,
                        $remarks
                    );

                    $this->notifications->send(
                        'REQUEST_REJECTED',
                        collect([
                            $request->borrower,
                        ]),
                        "Request {$request->request_no} was rejected by SPMU. Reason: {$remarks}",
                        $request
                    );

                    $this->audit->record(
                        'SPMU_REQUEST_DECISION',
                        $step,
                        reason:
                            $remarks,
                        after: [
                            'decision' =>
                                'REJECTED',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    return;
                }

                /*
                 * ===============================
                 * RETURN FOR REVISION
                 * ===============================
                 */
                if (
                    $decision
                    === 'RETURNED_FOR_REVISION'
                ) {
                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'RETURNED_FOR_REVISION',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $remarks,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    $this->markSupportingDocuments(
                        $version,
                        RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
                        $approver,
                        $remarks
                    );

                    $this->transition(
                        $request,
                        RequestStatus::ReturnedForRevision,
                        $approver,
                        $remarks
                    );

                    $this->notifications->send(
                        'REQUEST_RETURNED_FOR_REVISION',
                        collect([
                            $request->borrower,
                        ]),
                        "Request {$request->request_no} was returned for revision. Reason: {$remarks}",
                        $request
                    );

                    $this->audit->record(
                        'SPMU_REQUEST_DECISION',
                        $step,
                        reason:
                            $remarks,
                        after: [
                            'decision' =>
                                'RETURNED_FOR_REVISION',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    return;
                }

                /*
                 * ===============================
                 * APPROVE
                 * ===============================
                 *
                 * Approval requires the complete current
                 * supporting-document set.
                 */
                $this->validateRequiredSupportingDocuments(
                    $version
                );

                /*
                 * This is the only point in this workflow
                 * where inventory reservation is created.
                 *
                 * InventoryService::allocate() performs
                 * the final locked availability check.
                 */
                try {
                    $this->inventory->allocate(
                        $version
                    );
                } catch (
                    ValidationException $exception
                ) {
                    /*
                     * Do not silently reduce an approved
                     * quantity simply because less stock
                     * is now available.
                     *
                     * Return the request for correction.
                     */
                    $reason =
                        $this->firstValidationMessage(
                            $exception,
                            'The approved quantity can no longer be fulfilled using the current inventory and schedule. Corrected approved documentation is required.'
                        );

                    $step->update([
                        'approver_user_id' =>
                            $approver->id,

                        'signature_snapshot_id' =>
                            null,

                        'received_at' =>
                            $step->received_at
                                ?: now(),

                        'decision' =>
                            'RETURNED_FOR_REVISION',

                        'decided_at' =>
                            now(),

                        'remarks' =>
                            $reason,

                        'temporary_delegation_id' =>
                            $temporaryDelegationId,
                    ]);

                    $this->markSupportingDocuments(
                        $version,
                        RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
                        $approver,
                        $reason
                    );

                    $this->transition(
                        $request,
                        RequestStatus::ReturnedForRevision,
                        $approver,
                        $reason
                    );

                    $this->notifications->send(
                        'REQUEST_RETURNED_FOR_REVISION',
                        collect([
                            $request->borrower,
                        ]),
                        "Request {$request->request_no} was returned because the approved quantity cannot currently be allocated for the selected schedule. {$reason}",
                        $request
                    );

                    $this->audit->record(
                        'SPMU_RESERVATION_CONFLICT',
                        $step,
                        reason:
                            $reason,
                        after: [
                            'decision' =>
                                'RETURNED_FOR_REVISION',

                            'reservation_created' =>
                                false,
                        ]
                    );

                    return;
                }

                /*
                 * Reservation succeeded.
                 */
                $step->update([
                    'approver_user_id' =>
                        $approver->id,

                    /*
                     * No e-signature snapshot.
                     */
                    'signature_snapshot_id' =>
                        null,

                    'received_at' =>
                        $step->received_at
                            ?: now(),

                    'decision' =>
                        'APPROVED',

                    'decided_at' =>
                        now(),

                    'remarks' =>
                        $remarks,

                    'temporary_delegation_id' =>
                        $temporaryDelegationId,
                ]);

                $this->markSupportingDocuments(
                    $version,
                    RequestSupportingDocument::STATUS_VERIFIED,
                    $approver,
                    $remarks
                );

                $request->update([
                    'final_approved_at' =>
                        now(),

                    /*
                     * The current workflow does not
                     * require a generated approved-letter
                     * download before keeping reservation.
                     */
                    'download_deadline_at' =>
                        null,
                ]);

                /*
                 * Existing status enum retained so we do
                 * not unnecessarily break downstream
                 * modules.
                 *
                 * At this point:
                 * request = approved
                 * inventory = reserved
                 */
                $this->transition(
                    $request,
                    RequestStatus::ApprovedReadyForRelease,
                    $approver,
                    'SPMU Head verified and approved the request. The approved quantity is allocated/held for pickup.'
                );

                /*
                 * Create the pickup/custody record immediately
                 * after SPMU approval and reservation.
                 *
                 * No exact pickup time is assigned here.
                 * SPMU will configure the pickup date/time and
                 * pickup expiration from the Pickup workflow.
                 */
                $custody = $this->custody->ensurePickupRecord(
                    $request->fresh(),
                    $approver
                );

                /*
                 * Generate the borrower's printable physical document packet
                 * immediately after approval. The borrower prints these forms
                 * and brings the applicable originals to SPMU for handwritten
                 * signatures/operational completion.
                 */
                $custody->loadMissing([
                    'borrower',
                    'request.currentVersion',
                    'lines.requestItem.inventoryItem',
                    'gatePass',
                ]);

                $this->documents->borrowerSlip($custody);

                $offCampusLine = $custody->lines->first(
                    fn ($line) =>
                        $line->requestItem?->use_location === 'OFF_CAMPUS'
                        && (float) $line->approved_quantity > 0
                );

                if ($offCampusLine) {
                    $gatePassDocument = $this->documents->conditionalForm(
                        $custody,
                        'GATE_PASS'
                    );

                    GatePass::query()->updateOrCreate(
                        ['custody_transaction_id' => $custody->id],
                        [
                            'custody_line_id' => $offCampusLine->id,
                            'pass_document_id' => $gatePassDocument->id,
                            'bearer_name' => $request->borrower->full_name,
                            'destination' => $version->location,
                            'purpose' => $version->purpose_event,
                            'status' => 'PENDING',
                        ]
                    );
                }

                $hasLaundry = $custody->lines->contains(
                    fn ($line) =>
                        (bool) $line->requestItem?->inventoryItem?->laundry_required
                        && (float) $line->approved_quantity > 0
                );

                if ($hasLaundry) {
                    $this->documents->conditionalForm(
                        $custody->fresh([
                            'borrower',
                            'request.currentVersion',
                            'lines.requestItem.inventoryItem',
                        ]),
                        'LAUNDRY_FORM'
                    );
                }

                $this->audit->record(
                    'REQUEST_READY_FOR_PICKUP_SCHEDULING',
                    $request,
                    after: [
                        'custody_id' =>
                            $custody?->id,

                        'reservation_created' =>
                            true,

                        'pickup_schedule_created' =>
                            false,

                        'borrower_documents_generated' =>
                            true,
                    ]
                );

                $this->notifications->send(
                    'REQUEST_APPROVED',
                    collect([
                        $request->borrower,
                    ]),
                    "Request {$request->request_no} was verified and approved by the SPMU Head. Your Borrower Slip and any applicable Laundry Form or Gate Pass are now available in the request for printing.",
                    $request
                );

                $actionOfficers = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where('access_classification', AccessClassification::SpmuOfficer->value)
                    ->get();

                $this->notifications->send(
                    'REQUEST_APPROVED',
                    $actionOfficers,
                    "Request {$request->request_no} was verified and approved by the SPMU Head. The approved quantity is allocated/held and is ready for pickup scheduling and release processing.",
                    $request,
                    ['SYSTEM', 'EMAIL']
                );

                $this->audit->record(
                    'SPMU_REQUEST_DECISION',
                    $step,
                    reason:
                        $remarks,
                    after: [
                        'decision' =>
                            'APPROVED',

                        'reservation_created' =>
                            true,
                    ]
                );
            },
            3
        );
    }

    /**
     * Borrower cancellation rules.
     *
     * A borrower may cancel any open request until physical release. This
     * includes Draft, Submitted/Under Review, Approved/Allocated, Prepared,
     * and Pickup Scheduled requests. Once released/on custody, cancellation is
     * blocked and the Return workflow must be used instead.
     *
     * If approval already allocated inventory, cancellation immediately
     * restores that unreleased allocation to Available stock and invalidates
     * any pickup documents that were prepared for the cancelled transaction.
     */
    public function cancel(
        BorrowingRequest $request,
        User $actor,
        string $reason
    ): void {
        if (
            $request->borrower_user_id !== $actor->id
            && ! $actor->hasRole(UserRole::Spmu)
        ) {
            abort(403);
        }

        $request->loadMissing([
            'borrower',
            'currentVersion',
            'custody.gatePass',
        ]);

        if ($request->custody?->released_at) {
            throw ValidationException::withMessages([
                'cancel' =>
                    'Items have already been physically released. Use the Return process on the Expected Return Date instead of cancellation.',
            ]);
        }

        if (in_array($request->status, [
            RequestStatus::Cancelled,
            RequestStatus::Expired,
            RequestStatus::Rejected,
        ], true)) {
            throw ValidationException::withMessages([
                'cancel' => 'This request is already closed.',
            ]);
        }

        $afterReservation = $request->status === RequestStatus::ApprovedReadyForRelease;

        // No second SPMU confirmation is required while nothing has been
        // physically released. Cancellation is effective immediately.
        $this->finalizeCancellation($request, $actor, $reason, $afterReservation);
    }

    public function reviewCancellation(
        BorrowingRequest $request,
        User $spmu,
        string $decision,
        ?string $remarks = null
    ): void {
        abort_unless($spmu->hasRole(UserRole::Spmu), 403);
        $decision = strtoupper($decision);

        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'Choose APPROVED or REJECTED.',
            ]);
        }

        $cancellation = RequestCancellation::query()
            ->where('request_id', $request->id)
            ->where('status', 'PENDING_SPMU')
            ->latest('id')
            ->first();

        if (! $cancellation) {
            throw ValidationException::withMessages([
                'cancel' => 'There is no pending cancellation request for this borrowing request.',
            ]);
        }

        if ($decision === 'REJECTED') {
            $cancellation->update([
                'status' => 'REJECTED',
                'reviewed_by_user_id' => $spmu->id,
                'reviewed_at' => now(),
                'decision_remarks' => $remarks,
            ]);

            $this->audit->record(
                'CANCELLATION_REJECTED',
                $cancellation,
                reason: $remarks
            );

            $this->notifications->send(
                'CANCELLATION_REJECTED',
                collect([$request->borrower]),
                "Cancellation request for {$request->request_no} was not approved by SPMU.".($remarks ? " {$remarks}" : ''),
                $request
            );

            return;
        }

        DB::transaction(function () use ($request, $spmu, $remarks, $cancellation): void {
            $cancellation->update([
                'status' => 'CONFIRMED',
                'reviewed_by_user_id' => $spmu->id,
                'reviewed_at' => now(),
                'decision_remarks' => $remarks,
                'cancelled_at' => now(),
            ]);

            $this->finalizeCancellation(
                $request,
                $spmu,
                $cancellation->reason,
                true,
                false
            );
        }, 3);
    }

    private function finalizeCancellation(
        BorrowingRequest $request,
        User $actor,
        string $reason,
        bool $afterReservation,
        bool $createCancellationRecord = true
    ): void {
        DB::transaction(function () use (
            $request,
            $actor,
            $reason,
            $afterReservation,
            $createCancellationRecord
        ): void {
            if ($afterReservation) {
                $this->inventory->restore(
                    $request,
                    'CANCELLED',
                    $reason
                );
            }

            if ($createCancellationRecord) {
                $cancelledBySpmu = in_array(
                    $actor->access_classification,
                    [AccessClassification::SpmuHead, AccessClassification::SpmuOfficer],
                    true
                );

                RequestCancellation::query()->create([
                    'request_id' => $request->id,
                    'request_version_id' => $request->currentVersion?->id,
                    'cancelled_by_user_id' => $actor->id,
                    'phase' => $afterReservation
                        ? 'AFTER_APPROVAL_BEFORE_RELEASE'
                        : 'BEFORE_FINAL_APPROVAL',
                    'reason' => $reason,
                    'status' => 'CONFIRMED',
                    'requested_at' => now(),
                    'reviewed_by_user_id' => $cancelledBySpmu ? $actor->id : null,
                    'reviewed_at' => $cancelledBySpmu ? now() : null,
                    'cancelled_at' => now(),
                ]);
            }

            /*
             * Borrower Slip, Gate Pass, Laundry Form, request-letter copies,
             * and other generated documents tied to the current request
             * version can no longer be used after cancellation.
             */
            GeneratedDocument::query()
                ->where('request_version_id', $request->currentVersion?->id)
                ->whereIn('status', ['DRAFT', 'FINAL'])
                ->update([
                    'status' => 'INVALIDATED',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Request cancelled: '.$reason,
                ]);

            $custody = $request->custody;

            if ($custody) {
                // Preserve the historical pickup times but make any pending
                // pickup window inactive immediately.
                $custodyUpdates = [
                    'status' => 'CANCELLED',
                    'closed_at' => now(),
                ];

                if ($custody->scheduled_release_at && ! $custody->released_at) {
                    $custodyUpdates['pickup_expired_at'] = now();
                }

                $custody->update($custodyUpdates);

                // An off-campus Gate Pass prepared before release is void once
                // the underlying borrowing request is cancelled.
                GatePass::query()
                    ->where('custody_transaction_id', $custody->id)
                    ->where('status', '!=', 'VERIFIED')
                    ->update([
                        'status' => 'VOID',
                        'verification_remarks' => 'Voided because the borrowing request was cancelled. '.$reason,
                    ]);
            }

            $this->transition(
                $request,
                RequestStatus::Cancelled,
                $actor,
                $reason
            );

            $this->audit->record(
                'REQUEST_CANCELLED',
                $request,
                reason: $reason,
                after: ['reservation_released' => $afterReservation]
            );

            $this->notifications->send(
                'REQUEST_CANCELLED',
                collect([$request->borrower]),
                "Request {$request->request_no} was cancelled. {$reason}",
                $request
            );

            if ($request->borrower_user_id === $actor->id) {
                $actionOfficers = User::query()
                    ->where('account_status', 'ACTIVE')
                    ->where(
                        'access_classification',
                        AccessClassification::SpmuOfficer->value
                    )
                    ->get();

                if ($actionOfficers->isNotEmpty()) {
                    $this->notifications->send(
                        'REQUEST_CANCELLED',
                        $actionOfficers,
                        "Borrower cancelled {$request->request_no} before physical release. Any unreleased allocation, pickup window, and prepared pickup documents are no longer active.",
                        $request
                    );
                }
            }
        }, 3);
    }

    /**
     * Historical compatibility for old generated
     * approved-letter transactions.
     *
     * This method is retained so records created by
     * the previous implementation remain accessible.
     *
     * Current requests do not use this as the
     * reservation trigger.
     */
    public function recordApprovedLetterDownload(
        BorrowingRequest $request,
        GeneratedDocument $document,
        User $borrower,
        string $ip,
        ?string $userAgent
    ): CustodyTransaction {
        if (
            $request->borrower_user_id
                !== $borrower->id
            || $document->document_type
                !== 'APPROVED_REQUEST_LETTER'
            || $document->status
                !== 'FINAL'
        ) {
            abort(403);
        }

        $existingDownload =
            DownloadEvent::query()
                ->where(
                    'generated_document_id',
                    $document->id
                )
                ->where(
                    'downloaded_by_user_id',
                    $borrower->id
                )
                ->where(
                    'integrity_hash',
                    $document->sha256
                )
                ->exists();

        if (
            $existingDownload
            && $request->status
                === RequestStatus::ApprovedReadyForRelease
            && $request->custody
        ) {
            return $request->custody;
        }

        if (
            $request->status
                === RequestStatus::Expired
            || (
                $request->download_deadline_at
                && now()->gt(
                    $request->download_deadline_at
                )
            )
        ) {
            throw ValidationException::withMessages([
                'download' =>
                    'The approved-letter download deadline has passed. This historical document can no longer be used for release.',
            ]);
        }

        return DB::transaction(
            function () use (
                $request,
                $document,
                $borrower,
                $ip,
                $userAgent
            ): CustodyTransaction {
                DownloadEvent::query()
                    ->firstOrCreate(
                        [
                            'generated_document_id' =>
                                $document->id,

                            'downloaded_by_user_id' =>
                                $borrower->id,

                            'integrity_hash' =>
                                $document->sha256,
                        ],
                        [
                            'downloaded_at' =>
                                now(),

                            'origin_ip' =>
                                $ip,

                            'user_agent' =>
                                $userAgent,
                        ]
                    );

                $custody =
                    CustodyTransaction::query()
                        ->firstOrCreate(
                            [
                                'request_id' =>
                                    $request->id,
                            ],
                            [
                                'custody_no' =>
                                    'CUS-'
                                    .now()->format(
                                        'Ymd'
                                    )
                                    .'-'
                                    .str_pad(
                                        (string) $request->id,
                                        5,
                                        '0',
                                        STR_PAD_LEFT
                                    ),

                                'request_version_id' =>
                                    $request
                                        ->currentVersion
                                        ->id,

                                'borrower_user_id' =>
                                    $borrower->id,

                                'status' =>
                                    'PREPARING_RELEASE',

                                'scheduled_release_at' =>
                                    $request
                                        ->currentVersion
                                        ->needed_from,

                                'due_at' =>
                                    $request
                                        ->currentVersion
                                        ->return_due_at,
                            ]
                        );

                $request->loadMissing(
                    'currentVersion.items.allocation'
                );

                foreach (
                    $request
                        ->currentVersion
                        ->items
                    as $item
                ) {
                    /*
                     * Historical records may not contain
                     * an allocation row. Do not fail the
                     * whole request because of it.
                     */
                    if (! $item->allocation) {
                        continue;
                    }

                    CustodyLine::query()
                        ->firstOrCreate(
                            [
                                'custody_transaction_id' =>
                                    $custody->id,

                                'request_item_id' =>
                                    $item->id,
                            ],
                            [
                                'allocation_id' =>
                                    $item
                                        ->allocation
                                        ->id,

                                'approved_quantity' =>
                                    $item
                                        ->approved_quantity,

                                'quantity_to_receive' =>
                                    $item
                                        ->approved_quantity,
                            ]
                        );
                }

                if (
                    $request->status
                    !== RequestStatus::ApprovedReadyForRelease
                ) {
                    $this->transition(
                        $request,
                        RequestStatus::ApprovedReadyForRelease,
                        $borrower,
                        'Historical approved-letter download recorded.'
                    );
                }

                /*
                 * Do not generate the Borrower Slip here.
                 *
                 * Approval/download establishes the approved custody record,
                 * but the Borrower Slip is a physical release document.
                 * It is generated later by CustodyService::prepare() only
                 * after the SPMU Action Officer confirms that every prepared
                 * quantity matches the approved quantity.
                 */

                /*
                 * Historical off-campus Gate Pass
                 * generation.
                 */
                $offCampusLine =
                    $custody
                        ->lines()
                        ->whereHas(
                            'requestItem',
                            fn ($query) =>
                                $query->where(
                                    'use_location',
                                    'OFF_CAMPUS'
                                )
                        )
                        ->first();

                if (
                    $offCampusLine
                    && ! $custody->gatePass
                ) {
                    $passDocument =
                        $this->documents
                            ->conditionalForm(
                                $custody,
                                'GATE_PASS'
                            );

                    GatePass::query()->create([
                        'custody_transaction_id' =>
                            $custody->id,

                        'custody_line_id' =>
                            $offCampusLine->id,

                        'pass_document_id' =>
                            $passDocument->id,

                        'bearer_name' =>
                            $borrower->full_name,

                        'destination' =>
                            $request
                                ->currentVersion
                                ->location,

                        'purpose' =>
                            $request
                                ->currentVersion
                                ->purpose_event,

                        'status' =>
                            'PENDING',
                    ]);
                }

                /*
                 * Historical laundry-form generation.
                 */
                if (
                    $custody
                        ->lines()
                        ->whereHas(
                            'requestItem.inventoryItem',
                            fn ($query) =>
                                $query->where(
                                    'laundry_required',
                                    true
                                )
                        )
                        ->exists()

                    && ! GeneratedDocument::query()
                        ->where(
                            'subject_type',
                            CustodyTransaction::class
                        )
                        ->where(
                            'subject_id',
                            $custody->id
                        )
                        ->where(
                            'document_type',
                            'LAUNDRY_FORM'
                        )
                        ->exists()
                ) {
                    $this->documents
                        ->conditionalForm(
                            $custody,
                            'LAUNDRY_FORM'
                        );
                }

                $this->audit->record(
                    'APPROVED_LETTER_DOWNLOADED',
                    $document,
                    after: [
                        'custody_id' =>
                            $custody->id,

                        'sha256' =>
                            $document->sha256,
                    ]
                );

                return $custody;
            },
            3
        );
    }

    /**
     * Historical compatibility for old requests that
     * used an approved-letter download deadline.
     */
    public function expireUndownloaded(): int
    {
        $count = 0;

        BorrowingRequest::query()
            ->where(
                'status',
                RequestStatus::FinalApprovedAwaitingDownload->value
            )
            ->whereNotNull(
                'download_deadline_at'
            )
            ->where(
                'download_deadline_at',
                '<',
                now()
            )
            ->each(
                function (
                    BorrowingRequest $request
                ) use (
                    &$count
                ): void {
                    DB::transaction(
                        function () use (
                            $request,
                            &$count
                        ): void {
                            $this->inventory->restore(
                                $request,
                                'EXPIRED',
                                'Approved letter was not downloaded by the configured deadline.'
                            );

                            GeneratedDocument::query()
                                ->where(
                                    'request_version_id',
                                    $request
                                        ->currentVersion
                                        ?->id
                                )
                                ->where(
                                    'status',
                                    'FINAL'
                                )
                                ->update([
                                    'status' =>
                                        'EXPIRED',

                                    'invalidated_at' =>
                                        now(),

                                    'invalidation_reason' =>
                                        'Download deadline missed.',
                                ]);

                            $this->transition(
                                $request,
                                RequestStatus::Expired,
                                null,
                                'Approved letter was not downloaded by the configured deadline.'
                            );

                            $this->notifications->send(
                                'REQUEST_EXPIRED',
                                collect([
                                    $request->borrower,
                                ]),
                                "Request {$request->request_no} expired because the approved letter was not downloaded by the deadline.",
                                $request
                            );

                            $count++;
                        },
                        3
                    );
                }
            );

        return $count;
    }

    /**
     * Verify that the current request version contains
     * all supporting documents required for submission
     * or approval.
     */
    private function validateRequiredSupportingDocuments(
        $version
    ): void {
        $documents =
            $version
                ->supportingDocuments()
                ->where(
                    'is_current',
                    true
                )
                ->get();

        $hasRequestLetter =
            $documents->contains(
                fn (
                    RequestSupportingDocument $document
                ) =>
                    $document->document_type
                        === RequestSupportingDocument::TYPE_REQUEST_LETTER
            );

        if (! $hasRequestLetter) {
            throw ValidationException::withMessages([
                'approved_request_letter' =>
                    'Upload the scanned approved Borrowing Request Letter before submitting.',
            ]);
        }

        if (
            $version->represents_student_activity
        ) {
            $hasPermission =
                $documents->contains(
                    fn (
                        RequestSupportingDocument $document
                    ) =>
                        $document->document_type
                            === RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                );

            if (! $hasPermission) {
                throw ValidationException::withMessages([
                    'permission_to_conduct_letter' =>
                        'The Permission to Conduct Letter is required for a student activity or organization request.',
                ]);
            }
        }
    }

    /**
     * Update the SPMU verification result for the
     * currently active uploaded supporting documents.
     */
    private function markSupportingDocuments(
        $version,
        string $status,
        User $verifier,
        ?string $remarks
    ): void {
        $version
            ->supportingDocuments()
            ->where(
                'is_current',
                true
            )
            ->update([
                'verification_status' =>
                    $status,

                'verified_by_user_id' =>
                    $verifier->id,

                'verified_at' =>
                    now(),

                'verification_remarks' =>
                    $remarks,
            ]);
    }

    /**
     * Extract the first useful validation message
     * generated by InventoryService.
     */
    private function firstValidationMessage(
        ValidationException $exception,
        string $fallback
    ): string {
        foreach (
            $exception->errors()
            as $messages
        ) {
            if (
                is_array($messages)
                && isset($messages[0])
            ) {
                return (string) $messages[0];
            }
        }

        return $fallback;
    }

    /**
     * Change request status and retain complete
     * status-history information.
     */
    private function transition(
        BorrowingRequest $request,
        RequestStatus $to,
        ?User $actor,
        ?string $reason
    ): void {
        $from = $request->status;

        $request->update([
            'status' =>
                $to,
        ]);

        RequestStatusHistory::query()
            ->create([
                'request_id' =>
                    $request->id,

                'request_version_id' =>
                    $request
                        ->currentVersion
                        ?->id,

                'actor_user_id' =>
                    $actor?->id,

                'from_status' =>
                    $from?->value,

                'to_status' =>
                    $to->value,

                'reason' =>
                    $reason,

                'changed_at' =>
                    now(),
            ]);
    }

    /**
     * Return active users having the requested role.
     */
    private function usersWithRole(
        UserRole $role
    ) {
        return User::query()
            ->where(
                'account_status',
                'ACTIVE'
            )
            ->whereHas(
                'roles',
                fn ($query) =>
                    $query
                        ->where(
                            'role_code',
                            $role->value
                        )
                        ->whereNull(
                            'user_roles.revoked_at'
                        )
            )
            ->get();
    }
}