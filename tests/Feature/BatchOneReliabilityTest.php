<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\RequestVersion;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CustodyService;
use App\Services\DocumentService;
use App\Services\ProtectedFileService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BatchOneReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_draft_request_survives_supporting_document_storage_failure(): void
    {
        $borrower =
            $this->borrower();

        $item =
            InventoryItem::query()
                ->where(
                    'active',
                    true
                )
                ->where(
                    'borrowable',
                    true
                )
                ->firstOrFail();

        /*
         * The current create flow saves the Draft first, then stores
         * optional supporting documents. A storage failure must not erase
         * the already-created Draft/request items.
         */
        $this->mock(
            ProtectedFileService::class,
            function (
                MockInterface $mock
            ): void {
                $mock
                    ->shouldReceive(
                        'storeUpload'
                    )
                    ->once()
                    ->andThrow(
                        new RuntimeException(
                            'Simulated supporting-document storage failure.'
                        )
                    );
            }
        );

        $this
            ->withSession([
                'active_workspace' =>
                    'BORROWER',
            ])
            ->actingAs(
                $borrower
            )
            ->post(
                route(
                    'requests.store'
                ),
                [
                    'purpose_event' =>
                        'Document recovery test',

                    'location' =>
                        'Campus',

                    'schedule_date' =>
                        now()
                            ->addDay()
                            ->toDateString(),

                    'return_date' =>
                        now()
                            ->addDays(2)
                            ->toDateString(),

                    'event_details' =>
                        'The database Draft must survive a failed supporting-document write.',

                    'approved_request_letter' =>
                        UploadedFile::fake()
                            ->create(
                                'approved-request-letter.pdf',
                                10,
                                'application/pdf'
                            ),

                    'item_ids' =>
                        [
                            $item->id,
                        ],

                    'quantities' =>
                        [
                            $item->id =>
                                1,
                        ],

                    'locations' =>
                        [
                            $item->id =>
                                'ON_CAMPUS',
                        ],
                ]
            )
            ->assertStatus(
                500
            );

        $request =
            BorrowingRequest::query()
                ->where(
                    'borrower_user_id',
                    $borrower->id
                )
                ->firstOrFail();

        $this->assertSame(
            RequestStatus::Draft,
            $request->status
        );

        $this->assertDatabaseHas(
            'request_versions',
            [
                'request_id' =>
                    $request->id,

                'version_no' =>
                    1,
            ]
        );

        $this->assertDatabaseHas(
            'request_items',
            [
                'request_version_id' =>
                    $request
                        ->currentVersion
                        ->id,

                'inventory_item_id' =>
                    $item->id,
            ]
        );

        $this->assertDatabaseMissing(
            'request_supporting_documents',
            [
                'request_version_id' =>
                    $request
                        ->currentVersion
                        ->id,

                'document_type' =>
                    'BORROWING_REQUEST_LETTER',
            ]
        );
    }

    public function test_draft_can_attach_the_current_scanned_approved_request_letter_later(): void
    {
        [
            $request,
            $version,
        ] =
            $this->draftRequest(
                'BR-SUPPORTING-DOC-001'
            );

        $borrower =
            $request->borrower;

        $requestItem =
            $version
                ->items()
                ->firstOrFail();

        $this
            ->withSession([
                'active_workspace' =>
                    'BORROWER',
            ])
            ->actingAs(
                $borrower
            )
            ->put(
                route(
                    'requests.update',
                    $request
                ),
                [
                    'purpose_event' =>
                        $version
                            ->purpose_event,

                    'location' =>
                        $version
                            ->location,

                    'schedule_date' =>
                        now()
                            ->addDay()
                            ->toDateString(),

                    'return_date' =>
                        now()
                            ->addDays(2)
                            ->toDateString(),

                    'event_details' =>
                        $version
                            ->event_details,

                    'represents_student_activity' =>
                        0,

                    'approved_request_letter' =>
                        UploadedFile::fake()
                            ->create(
                                'signed-approved-request-letter.pdf',
                                10,
                                'application/pdf'
                            ),

                    'item_ids' =>
                        [
                            $requestItem
                                ->inventory_item_id,
                        ],

                    'quantities' =>
                        [
                            $requestItem
                                ->inventory_item_id =>
                                1,
                        ],

                    'locations' =>
                        [
                            $requestItem
                                ->inventory_item_id =>
                                'ON_CAMPUS',
                        ],
                ]
            )
            ->assertSessionHasNoErrors();

        $request->refresh();

        $this->assertDatabaseHas(
            'request_supporting_documents',
            [
                'request_id' =>
                    $request->id,

                'request_version_id' =>
                    $request
                        ->currentVersion
                        ->id,

                'document_type' =>
                    RequestSupportingDocument::TYPE_REQUEST_LETTER,

                'version_no' =>
                    1,

                'verification_status' =>
                    RequestSupportingDocument::STATUS_PENDING,

                'is_current' =>
                    true,
            ]
        );

        /*
         * The current physical-signature workflow keeps a printable Draft
         * Borrowing Request Letter. The borrower prints it, obtains the
         * required wet signatures, then uploads the accomplished scan.
         */
        $this->assertDatabaseHas(
            'generated_documents',
            [
                'request_version_id' =>
                    $request
                        ->currentVersion
                        ->id,

                'document_type' =>
                    'REQUEST_LETTER',

                'status' =>
                    'DRAFT',
            ]
        );
    }

    public function test_completed_line_accounting_keeps_custody_in_return_processing_until_other_lines_are_accounted(): void
    {
        [$custody, $lines] = $this->activeCustodyWithLines(2);
        $officer = $this->spmuOfficer();
        $evidence = StoredFile::query()->create([
            'uploaded_by_user_id' => $officer->id,
            'disk' => 'local',
            'storage_path' => 'test-evidence/'.uniqid().'.pdf',
            'original_name' => 'damage.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1,
            'sha256' => hash('sha256', 'x'),
            'classification' => 'INCIDENT_EVIDENCE',
        ]);

        app(CustodyService::class)->receiveReturn(
            $custody,
            $officer,
            [$lines[0]->id => 1],
            [$lines[0]->id => 'DAMAGED'],
            'Damage found while fully accounting for the first issued item.',
            evidenceFileIds: [$lines[0]->id => $evidence->id],
        );
        $this->assertSame('RETURN_PROCESSING', $custody->fresh()->status);
        $this->travel(1)->seconds();

        app(CustodyService::class)->receiveReturn(
            $custody->fresh(),
            $officer,
            [$lines[1]->id => 1],
            [$lines[1]->id => 'FINE'],
            'Final serviceable item returned.',
        );

        $this->assertDatabaseHas('incidents', ['custody_transaction_id' => $custody->id, 'status' => 'OPEN']);
        $this->assertSame('OBLIGATION_OPEN', $custody->fresh()->status);
        $this->assertNull($custody->fresh()->closed_at);
    }

    public function test_replayed_gate_pass_verification_does_not_replace_verified_physical_evidence(): void
    {
        [
            $custody,
            $gatePass,
        ] =
            $this->gatePassCustody(
                true
            );

        $officer =
            $this->spmuOfficer();

        $first =
            $this
                ->withSession([
                    'active_workspace' =>
                        'SPMU',
                ])
                ->actingAs(
                    $officer
                )
                ->post(
                    route(
                        'gate-passes.verify',
                        $gatePass
                    ),
                    [
                        'accomplished_form' =>
                            UploadedFile::fake()
                                ->create(
                                    'gate-pass-accomplished.pdf',
                                    10,
                                    'application/pdf'
                                ),

                        'guard_name' =>
                            'Guard One',

                        'guard_signed_at' =>
                            now()
                                ->format(
                                    'Y-m-d H:i:s'
                                ),

                        'remarks' =>
                            'Original accomplished Gate Pass returned to SPMU.',
                    ]
                );

        $first->assertSessionHasNoErrors();

        $gatePass->refresh();

        $firstFileId =
            $gatePass
                ->accomplished_file_id;

        $firstVerifiedAt =
            $gatePass
                ->verified_at;

        $auditCount =
            DB::table(
                'audit_events'
            )
                ->where(
                    'action_code',
                    'GATE_PASS_ACCOMPLISHED_VERIFIED'
                )
                ->count();

        $this->travel(
            1
        )->minute();

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $officer
            )
            ->post(
                route(
                    'gate-passes.verify',
                    $gatePass
                ),
                [
                    'accomplished_form' =>
                        UploadedFile::fake()
                            ->create(
                                'duplicate-gate-pass.pdf',
                                10,
                                'application/pdf'
                            ),

                    'guard_name' =>
                        'Different Guard',

                    'guard_signed_at' =>
                        now()
                            ->format(
                                'Y-m-d H:i:s'
                            ),

                    'remarks' =>
                        'Replay must not overwrite the verified record.',
                ]
            )
            ->assertSessionHasNoErrors()
            ->assertSessionHas(
                'status',
                'This Gate Pass is already verified. No duplicate scan or verification was recorded.'
            );

        $gatePass->refresh();

        $this->assertSame(
            $firstFileId,
            $gatePass
                ->accomplished_file_id
        );

        $this->assertSame(
            'Guard One',
            $gatePass
                ->guard_name
        );

        $this->assertTrue(
            $firstVerifiedAt
                ->equalTo(
                    $gatePass
                        ->verified_at
                )
        );

        $this->assertSame(
            $auditCount,
            DB::table(
                'audit_events'
            )
                ->where(
                    'action_code',
                    'GATE_PASS_ACCOMPLISHED_VERIFIED'
                )
                ->count()
        );
    }

    public function test_spmu_records_guard_details_and_scan_for_accomplished_gate_pass(): void
    {
        [
            $custody,
            $gatePass,
        ] =
            $this->gatePassCustody(
                true
            );

        $officer =
            $this->spmuOfficer();

        $signedAt =
            now()
                ->subMinutes(
                    5
                );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $officer
            )
            ->post(
                route(
                    'gate-passes.verify',
                    $gatePass
                ),
                [
                    'accomplished_form' =>
                        UploadedFile::fake()
                            ->create(
                                'signed-gate-pass.pdf',
                                10,
                                'application/pdf'
                            ),

                    'guard_name' =>
                        'Campus Guard',

                    'guard_signed_at' =>
                        $signedAt
                            ->format(
                                'Y-m-d H:i:s'
                            ),

                    'remarks' =>
                        'Accomplished physical Gate Pass returned and scanned.',
                ]
            )
            ->assertSessionHasNoErrors();

        $gatePass->refresh();

        $this->assertSame(
            'VERIFIED',
            $gatePass->status
        );

        $this->assertSame(
            'Campus Guard',
            $gatePass
                ->guard_name
        );

        $this->assertNotNull(
            $gatePass
                ->accomplished_file_id
        );

        $this->assertSame(
            $officer->id,
            $gatePass
                ->uploaded_by_user_id
        );

        $this->assertSame(
            $officer->id,
            $gatePass
                ->verified_by_user_id
        );

        $this->assertDatabaseHas(
            'custody_lines',
            [
                'custody_transaction_id' =>
                    $custody->id,

                'compliance_status' =>
                    'GATE_PASS_COMPLETED',
            ]
        );

        $this->assertDatabaseHas(
            'audit_events',
            [
                'action_code' =>
                    'GATE_PASS_ACCOMPLISHED_VERIFIED',
            ]
        );
    }

    public function test_replacing_scanned_request_letter_preserves_history_and_marks_only_the_latest_as_current(): void
    {
        [
            $request,
            $version,
        ] =
            $this->draftRequest(
                'BR-SUPPORTING-DOC-HISTORY-001'
            );

        $borrower =
            $request->borrower;

        $requestItem =
            $version
                ->items()
                ->firstOrFail();

        $basePayload = [
            'purpose_event' =>
                $version
                    ->purpose_event,

            'location' =>
                $version
                    ->location,

            'schedule_date' =>
                now()
                    ->addDay()
                    ->toDateString(),

            'return_date' =>
                now()
                    ->addDays(2)
                    ->toDateString(),

            'event_details' =>
                $version
                    ->event_details,

            'represents_student_activity' =>
                0,

            'item_ids' =>
                [
                    $requestItem
                        ->inventory_item_id,
                ],

            'quantities' =>
                [
                    $requestItem
                        ->inventory_item_id =>
                        1,
                ],

            'locations' =>
                [
                    $requestItem
                        ->inventory_item_id =>
                        'ON_CAMPUS',
                ],
        ];

        $this
            ->withSession([
                'active_workspace' =>
                    'BORROWER',
            ])
            ->actingAs(
                $borrower
            )
            ->put(
                route(
                    'requests.update',
                    $request
                ),
                [
                    ...$basePayload,

                    'approved_request_letter' =>
                        UploadedFile::fake()
                            ->create(
                                'signed-approved-v1.pdf',
                                10,
                                'application/pdf'
                            ),
                ]
            )
            ->assertSessionHasNoErrors();

        $request->refresh();

        $currentVersionId =
            $request
                ->currentVersion
                ->id;

        $first =
            RequestSupportingDocument::query()
                ->where(
                    'request_version_id',
                    $currentVersionId
                )
                ->where(
                    'document_type',
                    RequestSupportingDocument::TYPE_REQUEST_LETTER
                )
                ->firstOrFail();

        $this->assertTrue(
            (bool)
                $first
                    ->is_current
        );

        $this
            ->withSession([
                'active_workspace' =>
                    'BORROWER',
            ])
            ->actingAs(
                $borrower
            )
            ->put(
                route(
                    'requests.update',
                    $request
                ),
                [
                    ...$basePayload,

                    'approved_request_letter' =>
                        UploadedFile::fake()
                            ->create(
                                'signed-approved-v2.pdf',
                                10,
                                'application/pdf'
                            ),
                ]
            )
            ->assertSessionHasNoErrors();

        $documents =
            RequestSupportingDocument::query()
                ->where(
                    'request_version_id',
                    $currentVersionId
                )
                ->where(
                    'document_type',
                    RequestSupportingDocument::TYPE_REQUEST_LETTER
                )
                ->orderBy(
                    'version_no'
                )
                ->get();

        $this->assertCount(
            2,
            $documents
        );

        $this->assertSame(
            1,
            $documents[0]
                ->version_no
        );

        $this->assertFalse(
            (bool)
                $documents[0]
                    ->is_current
        );

        $this->assertNotNull(
            $documents[0]
                ->superseded_at
        );

        $this->assertSame(
            2,
            $documents[1]
                ->version_no
        );

        $this->assertTrue(
            (bool)
                $documents[1]
                    ->is_current
        );

        $this->assertNull(
            $documents[1]
                ->superseded_at
        );

        $this->assertNotSame(
            $documents[0]
                ->stored_file_id,

            $documents[1]
                ->stored_file_id
        );
    }

    public function test_gate_pass_cannot_be_verified_before_physical_issuance(): void
    {
        [
            $custody,
            $gatePass,
        ] =
            $this->gatePassCustody(
                false
            );

        $officer =
            $this->spmuOfficer();

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $officer
            )
            ->post(
                route(
                    'gate-passes.verify',
                    $gatePass
                ),
                [
                    'accomplished_form' =>
                        UploadedFile::fake()
                            ->create(
                                'premature-gate-pass.pdf',
                                10,
                                'application/pdf'
                            ),

                    'guard_name' =>
                        'Campus Guard',

                    'guard_signed_at' =>
                        now()
                            ->format(
                                'Y-m-d H:i:s'
                            ),
                ]
            )
            ->assertSessionHasErrors(
                'gate_pass'
            );

        $gatePass->refresh();

        $this->assertSame(
            'PENDING',
            $gatePass->status
        );

        $this->assertNull(
            $gatePass
                ->accomplished_file_id
        );

        $this->assertNull(
            $gatePass
                ->verified_at
        );

        $this->assertNull(
            $custody
                ->fresh()
                ->released_at
        );
    }

    public function test_authenticated_user_is_logged_out_after_account_deactivation(): void
    {
        $borrower = $this->borrower();
        $this->withSession(['active_workspace' => 'BORROWER'])->actingAs($borrower)
            ->get(route('dashboard'))->assertOk();

        $borrower->update(['account_status' => AccountStatus::Inactive]);

        $this->withSession(['active_workspace' => 'BORROWER'])->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** @return array{BorrowingRequest, RequestVersion} */
    private function draftRequest(string $requestNo): array
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()->where('active', true)->where('borrowable', true)->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => $requestNo,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Batch one reliability test',
            'location' => 'Campus',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'event_details' => 'Focused reliability fixture.',
            'created_by_user_id' => $borrower->id,
        ]);
        RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'use_location' => 'ON_CAMPUS',
        ]);

        return [$request->fresh(), $version];
    }

    /** @return array{CustodyTransaction, array<int, CustodyLine>} */
    private function activeCustodyWithLines(int $count): array
    {
        $borrower = $this->borrower();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-CUSTODY-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Return closeout test',
            'location' => 'Campus',
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->endOfDay(),
            'created_by_user_id' => $borrower->id,
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'released_at' => now(),
            'due_at' => now()->endOfDay(),
        ]);
        $items = InventoryItem::query()->where('active', true)->where('borrowable', true)->where('laundry_required', false)->limit($count)->get();
        $this->assertCount($count, $items);
        $lines = [];
        foreach ($items as $item) {
            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $item->id,
                'description_snapshot' => $item->unique_description,
                'unit_snapshot' => $item->unit->unit_name,
                'requested_quantity' => 1,
                'approved_quantity' => 1,
                'use_location' => 'ON_CAMPUS',
            ]);
            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $version->needed_from,
                'period_end' => $version->return_due_at,
                'allocated_quantity' => 1,
                'released_quantity' => 1,
                'status' => 'RELEASED',
                'allocated_at' => now()->subDay(),
            ]);
            $lines[] = CustodyLine::query()->create([
                'custody_transaction_id' => $custody->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => 1,
                'quantity_to_receive' => 1,
                'actual_released_quantity' => 1,
                'returned_quantity' => 0,
                'item_status' => 'RELEASED_PENDING_RETURN',
            ]);
        }

        return [$custody->fresh(), $lines];
    }

    /** @return array{CustodyTransaction, GatePass, GeneratedDocument} */
    private function gatePassCustody(bool $released): array
    {
        $borrower = $this->borrower();
        $item = InventoryItem::query()->where('off_campus_allowed', true)->firstOrFail();
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-GATE-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);
        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Off-campus test',
            'location' => 'Off-campus venue',
            'needed_from' => now()->addDay(),
            'return_due_at' => now()->addDays(2),
            'off_campus' => true,
            'created_by_user_id' => $borrower->id,
        ]);
        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'approved_quantity' => 1,
            'use_location' => 'OFF_CAMPUS',
        ]);
        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $version->needed_from,
            'period_end' => $version->return_due_at,
            'allocated_quantity' => 1,
            'released_quantity' => $released ? 1 : 0,
            'status' => $released ? 'RELEASED' : 'ACTIVE',
            'allocated_at' => now(),
        ]);
        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-GATE-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $released ? 'ACTIVE' : 'PREPARING_RELEASE',
            'released_at' => $released ? now() : null,
            'due_at' => $version->return_due_at,
        ]);
        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => $released ? 1 : 0,
            'returned_quantity' => 0,
            'item_status' => $released ? 'RELEASED_PENDING_RETURN' : 'CONFIRMED',
            'compliance_status' => 'AWAITING_GUARD_SIGNATURE',
        ]);
        $document = app(DocumentService::class)->conditionalForm($custody, 'GATE_PASS');
        $gatePass = GatePass::query()->create([
            'custody_transaction_id' => $custody->id,
            'custody_line_id' => $line->id,
            'pass_document_id' => $document->id,
            'bearer_name' => $borrower->full_name,
            'destination' => $version->location,
            'purpose' => $version->purpose_event,
            'status' => $released ? 'READY_FOR_PRINTING' : 'PENDING',
            'approved_at' => $released ? now() : null,
        ]);

        return [$custody->fresh(), $gatePass->fresh(), $document->fresh()];
    }

    private function gatePassDocumentCount(CustodyTransaction $custody): int
    {
        return GeneratedDocument::query()
            ->where('subject_type', CustodyTransaction::class)
            ->where('subject_id', $custody->id)
            ->where('document_type', 'GATE_PASS')
            ->count();
    }

    private function borrower(): User
    {
        return User::query()->where('access_classification', AccessClassification::BorrowerOnly->value)->firstOrFail();
    }

    private function spmuOfficer(): User
    {
        return User::query()->where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();
    }
}
