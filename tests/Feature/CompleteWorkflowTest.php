<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompleteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_complete_spmu_approval_pickup_and_physical_release_workflow(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(20, 'BR-FLOW-001', 'Monoblock Chairs');

        $this->assertSame(
            RequestStatus::ApprovedReadyForRelease,
            $request->fresh()->status
        );

        $this->assertDatabaseCount('approval_steps', 1);

        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $version->id,
            'stage_code' => 'SPMU',
            'decision' => 'APPROVED',
            'approver_user_id' => $spmu->id,
        ]);

        $this->assertDatabaseHas('request_supporting_documents', [
            'request_version_id' => $version->id,
            'document_type' => RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'verification_status' => RequestSupportingDocument::STATUS_VERIFIED,
            'verified_by_user_id' => $spmu->id,
            'is_current' => true,
        ]);

        $this->assertDatabaseHas('allocations', [
            'allocated_quantity' => 20,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
        ]);

        /*
         * Current workflow creates the pickup/custody record immediately
         * after SPMU approval. There is no generated approved-letter
         * download gate before reservation/pickup.
         */
        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame('PREPARING_RELEASE', $custody->status);

        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'APPROVED_REQUEST_LETTER',
            'status' => 'FINAL',
        ]);

        $this->schedulePickupAndPrepare(
            $custody,
            $spmuOfficer,
            $version
        );

        /*
         * Borrower Slip is generated during SPMU physical preparation,
         * before issuance. No online borrower acknowledgement is required.
         */
        $this->assertDatabaseHas('generated_documents', [
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'FINAL',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical handover and wet signatures completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame('ACTIVE', $custody->fresh()->status);

        $this->assertDatabaseHas('custody_lines', [
            'custody_transaction_id' => $custody->id,
            'actual_released_quantity' => 20,
        ]);

        /*
         * Internal ledger terminology is retained for historical
         * compatibility: ALLOCATED = Reserved, BORROWED = Issued.
         */
        $this->assertDatabaseHas('inventory_transaction_lines', [
            'from_state' => 'ALLOCATED',
            'to_state' => 'BORROWED',
            'quantity' => 20,
        ]);
    }

    public function test_spmu_cannot_reduce_pickup_quantity_below_verified_approved_quantity(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(12);

        $custody = $request
            ->custody()
            ->with('lines')
            ->firstOrFail();

        $line = $custody->lines->firstOrFail();

        /*
         * Finalized workflow: SPMU may not silently reduce an approved
         * quantity at pickup. If stock/requirements changed, the request
         * must go through revision/correction instead.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.quantities', $custody),
                [
                    'quantities' => [$line->id => 8],
                    'reasons' => [$line->id => 'Attempted reduced pickup.'],
                ]
            )
            ->assertSessionHasErrors('quantities');

        $this->assertSame(
            12.0,
            (float) $line->fresh()->quantity_to_receive
        );

        $this->assertDatabaseHas('allocations', [
            'request_item_id' => $line->request_item_id,
            'allocated_quantity' => 12,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
        ]);

        $this->schedulePickupAndPrepare(
            $custody,
            $spmuOfficer,
            $version
        );

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Exact approved quantity physically issued.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('allocations', [
            'request_item_id' => $line->request_item_id,
            'allocated_quantity' => 12,
            'released_quantity' => 12,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
        ]);
    }

    public function test_ictu_cannot_act_on_official_approval_queue(): void
    {
        $ictu = $this->roleUser(UserRole::Ictu);

        $this->withSession(['active_workspace' => 'ICTU'])
            ->actingAs($ictu)
            ->get('/approvals')
            ->assertForbidden();

        $this->withSession(['active_workspace' => 'ICTU'])
            ->actingAs($ictu)
            ->get('/administration/users')
            ->assertOk();
    }

    public function test_date_based_overdue_billing_payment_and_closeout(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(5);

        $custody = $request
            ->custody()
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare(
            $custody,
            $spmuOfficer,
            $version
        );

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical issuance completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        /*
         * DATE-ONLY rule:
         * Expected return yesterday + outstanding property today
         * = exactly one late calendar day.
         */
        $custody->update([
            'due_at' => now()
                ->subDay()
                ->endOfDay(),
        ]);

        SystemSetting::where(
            'setting_key',
            'daily_overdue_tariff'
        )
            ->firstOrFail()
            ->update([
                'value_json' => 75,
            ]);

        $this->artisan(
            'spmu:process-deadlines'
        )
            ->assertSuccessful();

        $this->assertSame(
            'OVERDUE',
            $custody->fresh()->status
        );

        /*
         * While property remains physically outstanding, the blocking
         * restriction is PENDING_RETURN. The separate OVERDUE_RETURN
         * restriction is created after the late return is confirmed and
         * the fee/accountability remains unresolved.
         */
        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'PENDING_RETURN',
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('overdue_cases', [
            'custody_transaction_id' => $custody->id,
            'accrued_amount' => 75,
            'status' => 'OVERDUE',
        ]);

        $line = $custody->fresh()
            ->lines()
            ->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 5],
                    'conditions' => [$line->id => 'FINE'],
                    'remarks' => 'Complete one-day-late physical return.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('overdue_cases', [
            'custody_transaction_id' => $custody->id,
            'accrued_amount' => 75,
            'status' => 'RETURNED_PENDING_SETTLEMENT',
        ]);

        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'PENDING_RETURN',
            'status' => 'LIFTED',
        ]);

        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'OVERDUE_RETURN',
            'status' => 'ACTIVE',
        ]);

        $overdue = $custody
            ->overdueCase()
            ->firstOrFail();

        $this->actingAs($spmu)
            ->post(
                route('overdue.bill', $overdue),
                [
                    'basis' => 'Configured daily tariff for one late calendar day.',
                ]
            )
            ->assertSessionHasNoErrors();

        $billing = BillingStatement::where(
            'borrower_user_id',
            $borrower->id
        )
            ->firstOrFail();

        $billingDocument = GeneratedDocument::where(
            'subject_type',
            BillingStatement::class
        )
            ->where(
                'subject_id',
                $billing->id
            )
            ->firstOrFail();

        $this->actingAs($borrower)
            ->get(
                route(
                    'documents.download',
                    $billingDocument
                )
            )
            ->assertOk();

        /*
         * The borrower physically pays at the CSPC Cashier and returns
         * the actual paid receipt to SPMU. SPMU—not the borrower—
         * scans/uploads the receipt and records its structured details.
         */
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route(
                    'payments.store',
                    $billing
                ),
                [
                    'evidence' =>
                        UploadedFile::fake()
                            ->create(
                                'official-receipt.pdf',
                                10,
                                'application/pdf'
                            ),

                    'official_receipt_no' =>
                        'OR-OVERDUE-001',

                    'receipt_date' =>
                        now()->toDateString(),

                    'amount' =>
                        75,

                    'remarks' =>
                        'Actual CSPC Cashier paid receipt returned to SPMU and scanned.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'RECEIPT_SUBMITTED',
            $billing->fresh()->status
        );

        $payment = $billing
            ->payments()
            ->firstOrFail();

        $this->actingAs($borrower)
            ->get(
                route(
                    'files.show',
                    $payment->evidence_file_id
                )
            )
            ->assertOk();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route(
                    'payments.verify',
                    $payment
                ),
                [
                    'decision' =>
                        'VERIFIED',

                    'remarks' =>
                        'Original CSPC Cashier paid receipt inspected and verified by SPMU.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'SETTLED',
            $billing->fresh()->status
        );

        $this->assertSame(
            'CLOSED',
            $custody->fresh()->status
        );

        $this->assertDatabaseHas('borrower_restrictions', [
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'OVERDUE_RETURN',
            'status' => 'LIFTED',
        ]);
    }

    public function test_stolen_property_requires_blotter_and_evidence_and_can_generate_approved_rslddp(): void
    {
        SystemSetting::where(
            'setting_key',
            'rslddp_template_status'
        )
            ->firstOrFail()
            ->update([
                'value_json' => 'APPROVED',
            ]);

        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(2);

        $custody = $request
            ->custody()
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare(
            $custody,
            $spmuOfficer,
            $version
        );

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical issuance completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        $line = $custody
            ->fresh()
            ->lines()
            ->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 2],
                    'conditions' => [$line->id => 'STOLEN'],
                ]
            )
            ->assertSessionHasErrors(
                'police_blotter_references'
            );

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 2],
                    'conditions' => [$line->id => 'STOLEN'],
                    'police_blotter_references' => [
                        $line->id =>
                            'PNP-BLOTTER-2026-001',
                    ],
                    'evidence_files' => [
                        $line->id =>
                            UploadedFile::fake()
                                ->create(
                                    'incident.pdf',
                                    10,
                                    'application/pdf'
                                ),
                    ],
                    'remarks' =>
                        'Reported to the proper authority.',
                ]
            )
            ->assertSessionHasNoErrors();

        $incident = Incident::where(
            'custody_transaction_id',
            $custody->id
        )
            ->firstOrFail();

        $this->assertNotNull(
            $incident->supporting_evidence_file_id
        );

        $this->assertSame(
            'PNP-BLOTTER-2026-001',
            $incident->police_blotter_reference
        );

        $this->assertDatabaseHas('generated_documents', [
            'subject_type' => Incident::class,
            'subject_id' => $incident->id,
            'document_type' => 'RSLDDP',
        ]);

        $this->assertSame(
            'OBLIGATION_OPEN',
            $custody->fresh()->status
        );

        $this->actingAs($spmu)
            ->post(
                route(
                    'incidents.bill',
                    $incident
                ),
                [
                    'amount' => 500,
                    'basis' => 'Authorized appraisal.',
                ]
            )
            ->assertSessionHasNoErrors();

        $billing = BillingStatement::where(
            'borrower_user_id',
            $borrower->id
        )
            ->firstOrFail();

        $this->actingAs($spmu)
            ->post(
                route(
                    'billings.waive',
                    $billing
                ),
                [
                    'reason' =>
                        'Authorized institutional waiver for test closeout.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'WAIVED',
            $billing->fresh()->status
        );

        $this->assertSame(
            'CLOSED',
            $custody->fresh()->status
        );

        $this->assertDatabaseHas('borrower_restrictions', [
            'incident_id' => $incident->id,
            'status' => 'LIFTED',
        ]);
    }

    /**
     * Create a current-workflow request with its scanned approved
     * Borrowing Request Letter, submit it to SPMU, and approve it.
     *
     * Submission does not reserve inventory. SPMU approval does.
     *
     * @return array{
     *     0: User,
     *     1: User,
     *     2: User,
     *     3: BorrowingRequest,
     *     4: \App\Models\RequestVersion
     * }
     */
    private function approvedRequest(
        float $quantity,
        string $requestNo = 'BR-CURRENT-001',
        string $itemDescription = 'Round Table'
    ): array {
        $borrower =
            $this->roleUser(
                UserRole::Borrower
            );

        $spmu =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $spmuOfficer =
            $this->classificationUser(
                AccessClassification::SpmuOfficer
            );

        $item =
            InventoryItem::where(
                'unique_description',
                $itemDescription
            )
                ->firstOrFail();

        $scheduleDate =
            now()
                ->addDays(2)
                ->startOfDay();

        $returnDate =
            now()
                ->addDays(3)
                ->startOfDay();

        $request =
            BorrowingRequest::create([
                'request_no' =>
                    $requestNo,

                'borrower_user_id' =>
                    $borrower->id,

                'accountable_unit_id' =>
                    $borrower
                        ->organizational_unit_id,

                'current_version_no' =>
                    1,

                'status' =>
                    RequestStatus::Draft,
            ]);

        $version =
            $request
                ->versions()
                ->create([
                    'version_no' =>
                        1,

                    'purpose_event' =>
                        'Institutional activity',

                    'location' =>
                        'CSPC Campus',

                    /*
                     * Canonical client schedule is date-only.
                     */
                    'schedule_date' =>
                        $scheduleDate
                            ->toDateString(),

                    'return_date' =>
                        $returnDate
                            ->toDateString(),

                    /*
                     * Legacy timestamp fields remain synchronized for
                     * inventory/calendar compatibility.
                     */
                    'needed_from' =>
                        $scheduleDate
                            ->copy()
                            ->startOfDay(),

                    'return_due_at' =>
                        $returnDate
                            ->copy()
                            ->endOfDay(),

                    'represents_student_activity' =>
                        false,

                    'event_details' =>
                        'Current SPMU-only workflow integration test.',

                    'off_campus' =>
                        false,

                    'created_by_user_id' =>
                        $borrower->id,
                ]);

        RequestItem::create([
            'request_version_id' =>
                $version->id,

            'inventory_item_id' =>
                $item->id,

            'description_snapshot' =>
                $item->unique_description,

            'unit_snapshot' =>
                $item->unit->unit_name,

            'requested_quantity' =>
                $quantity,

            'use_location' =>
                'ON_CAMPUS',
        ]);

        $this->attachApprovedRequestLetter(
            $request,
            $version,
            $borrower
        );

        /*
         * Before submission there is still no reservation.
         */
        $this->assertDatabaseCount(
            'allocations',
            0
        );

        $this->actingAs($borrower)
            ->post(
                route(
                    'requests.submit',
                    $request
                ),
                [
                    'borrower_acknowledgement' => '1',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame(
            RequestStatus::UnderSpmu,
            $request->fresh()->status
        );

        /*
         * Exactly one active in-system approval/verification stage.
         */
        $this->assertDatabaseCount(
            'approval_steps',
            1
        );

        $this->assertDatabaseCount(
            'allocations',
            0
        );

        $this->withSession([
            'active_workspace' => 'SPMU',
        ])
            ->actingAs($spmu)
            ->post(
                route(
                    'approvals.decide',
                    $request
                ),
                [
                    'decision' =>
                        'APPROVED',

                    'details_complete' =>
                        '1',

                    'signatures_present' =>
                        '1',

                    'document_readable' =>
                        '1',
                ]
            )
            ->assertSessionHasNoErrors();

        $request->refresh();

        $this->assertSame(
            RequestStatus::ApprovedReadyForRelease,
            $request->status
        );

        $this->assertDatabaseHas(
            'allocations',
            [
                'request_item_id' =>
                    $version
                        ->items()
                        ->firstOrFail()
                        ->id,

                'allocated_quantity' =>
                    $quantity,

                'status' =>
                    'ACTIVE',
            ]
        );

        $this->assertDatabaseHas(
            'custody_transactions',
            [
                'request_id' =>
                    $request->id,

                'status' =>
                    'PREPARING_RELEASE',
            ]
        );

        return [
            $borrower,
            $spmu,
            $spmuOfficer,
            $request,
            $version->fresh(),
        ];
    }

    /**
     * Save the scanned wet-signed/approved Borrowing Request Letter
     * as the current supporting document for this request version.
     */
    private function attachApprovedRequestLetter(
        BorrowingRequest $request,
        $version,
        User $borrower
    ): void {
        $bytes =
            '%PDF-1.4 current-workflow-test';

        $file =
            StoredFile::create([
                'uploaded_by_user_id' =>
                    $borrower->id,

                'disk' =>
                    'local',

                'storage_path' =>
                    'tests/request-supporting-documents/'
                    .$request->id
                    .'/signed-approved-request-letter.pdf',

                'original_name' =>
                    'signed-approved-request-letter.pdf',

                'mime_type' =>
                    'application/pdf',

                'byte_size' =>
                    strlen($bytes),

                'sha256' =>
                    hash(
                        'sha256',
                        $bytes
                    ),

                'classification' =>
                    'REQUEST_SUPPORTING_DOCUMENT',
            ]);

        RequestSupportingDocument::create([
            'request_id' =>
                $request->id,

            'request_version_id' =>
                $version->id,

            'document_type' =>
                RequestSupportingDocument::TYPE_REQUEST_LETTER,

            'version_no' =>
                1,

            'stored_file_id' =>
                $file->id,

            'uploaded_by_user_id' =>
                $borrower->id,

            'uploaded_at' =>
                now(),

            'verification_status' =>
                RequestSupportingDocument::STATUS_PENDING,

            'verified_by_user_id' =>
                null,

            'verified_at' =>
                null,

            'verification_remarks' =>
                null,

            'is_current' =>
                true,

            'superseded_at' =>
                null,
        ]);
    }

    /**
     * SPMU configures the pickup window on the approved Schedule Date,
     * prepares the exact approved quantity, then time is advanced into
     * the active pickup window so physical issuance may be confirmed.
     */
    private function schedulePickupAndPrepare(
        CustodyTransaction $custody,
        User $spmuOfficer,
        $version
    ): void {
        $pickupAt =
            $version
                ->schedule_date
                ->copy()
                ->setTime(
                    9,
                    0,
                    0
                );

        $pickupExpiresAt =
            $pickupAt
                ->copy()
                ->addHours(3);

        /*
         * Schedule while the pickup time is still in the future.
         */
        $this->travelTo(
            $pickupAt
                ->copy()
                ->subHour()
        );

        $this->withSession([
            'active_workspace' => 'SPMU',
        ])
            ->actingAs($spmuOfficer)
            ->post(
                route(
                    'custody.schedule-pickup',
                    $custody
                ),
                [
                    'pickup_at' =>
                        $pickupAt
                            ->format(
                                'Y-m-d H:i:s'
                            ),

                    'pickup_expires_at' =>
                        $pickupExpiresAt
                            ->format(
                                'Y-m-d H:i:s'
                            ),
                ]
            )
            ->assertSessionHasNoErrors();

        $this->withSession([
            'active_workspace' => 'SPMU',
        ])
            ->actingAs($spmuOfficer)
            ->post(
                route(
                    'custody.prepare',
                    $custody
                )
            )
            ->assertSessionHasNoErrors();

        /*
         * Enter the configured claim window before physical release.
         */
        $this->travelTo(
            $pickupAt
                ->copy()
                ->addMinutes(30)
        );
    }

    private function roleUser(
        UserRole $role
    ): User {
        return User::query()
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
            ->firstOrFail();
    }

    private function classificationUser(
        AccessClassification $classification
    ): User {
        return User::query()
            ->where(
                'access_classification',
                $classification->value
            )
            ->firstOrFail();
    }
}
