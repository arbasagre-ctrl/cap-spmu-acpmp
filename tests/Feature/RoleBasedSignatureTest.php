<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\ApprovalStage;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\RequestItem;
use App\Models\SignatureSnapshot;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\CustodyService;
use App\Services\DocumentService;
use App\Services\OperationalCalendarService;
use App\Services\ProtectedFileService;
use App\Services\SignatureService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role-based E-signature guarantees.
 *
 * Each required system form must carry the signature of the role that is its
 * designated signatory, tied to the specific action it authorizes. Signatures
 * are never reused across roles, and physical handover signatures stay
 * handwritten.
 */
class RoleBasedSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Physical Release records the Action Officer's identity via
     * released_by_user_id/released_at and the audit log only. No E-signature
     * is required or captured for this operational action -- an Action
     * Officer E-signature is reserved for the Gate Pass (see
     * test_gate_pass_still_captures_and_renders_the_action_officer_e_signature).
     * The officer does not even need a registered UserSignature on file.
     */
    public function test_physical_release_does_not_capture_or_render_an_action_officer_issuance_signature(): void
    {
        /*
         * Physical release is only permitted on an open SPMU operational day.
         * preparedCustody() schedules pickup for "tomorrow" relative to
         * "now", so anchor "now" to the day BEFORE the next open pickup
         * date -- that makes the fixture's scheduled date land exactly on
         * a confirmed-open date, regardless of which weekday this test
         * happens to run.
         */
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                ->copy()->subDay()->setTime(9, 0)
        );

        $custody = $this->preparedCustody();
        $officer = $this->spmuActionOfficer();

        // Deliberately NOT registering any signature for the officer -- this
        // proves release no longer depends on one existing.
        $this->actingAs($officer);

        // Move "now" into the fixture's own scheduled pickup window.
        $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

        app(CustodyService::class)->release($custody, $officer, 'Physical handover completed.');

        $custody = $custody->fresh();

        $this->assertSame('ACTIVE', $custody->status);
        $this->assertSame($officer->id, $custody->released_by_user_id);
        $this->assertNotNull($custody->released_at);

        $this->assertNull(
            $custody->released_by_signature_snapshot_id,
            'Physical Release must not capture an Action Officer E-signature.'
        );

        $this->assertSame(
            0,
            SignatureSnapshot::query()->where('purpose_code', 'SPMU_RELEASE_ISSUANCE')->count(),
            'No SPMU_RELEASE_ISSUANCE snapshot should ever be created.'
        );

        $html = $this->borrowerSlipHtml($custody->fresh([
            'borrower',
            'releasedBy',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
        ]));

        $this->assertStringNotContainsString(
            strtoupper($officer->full_name),
            $html,
            'The Borrower\'s Slip must not render the Action Officer\'s identity under "ISSUED BY".'
        );

        $this->assertStringNotContainsString(
            'ISSUED BY',
            $html,
            'Physical Release issuer information must stay in the system and must not render as an ISSUED BY block on the Borrower\'s Slip.'
        );
    }

    /**
     * The uploaded, fully signed scanned Request Letter is the official
     * Request Letter. The system must never generate its own "approved"
     * or duplicate Request Letter.
     */
    public function test_approval_does_not_generate_a_system_borrowing_request_letter(): void
    {
        $custody = $this->preparedCustody();
        $request = $custody->request;
        $version = $request->currentVersion;

        $head = $this->spmuHead();

        ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'approver_user_id' => $head->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 1,
            'received_at' => now(),
            'decision' => 'APPROVED',
            'decided_at' => now(),
        ]);

        app(DocumentService::class)->borrowerSlip($custody);

        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'REQUEST_LETTER',
        ]);

        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'APPROVED_REQUEST_LETTER',
        ]);

        $this->assertDatabaseHas('generated_documents', [
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'FINAL',
        ]);
    }

    public function test_borrower_slip_renders_borrower_and_approver_signatures_from_their_own_snapshots(): void
    {
        $custody = $this->preparedCustody();
        $request = $custody->request;
        $version = $request->currentVersion;

        $borrower = $custody->borrower;
        $head = $this->spmuHead();

        $borrowerSnapshot = $this->captureSnapshot(
            $borrower,
            'BORROWER_REQUEST_CERTIFICATION',
            'BORROWER',
            'borrower-ink'
        );

        $version->update([
            'borrower_signature_snapshot_id' => $borrowerSnapshot->id,
            'signed_at' => now(),
            'submitted_at' => now(),
        ]);

        $headSnapshot = $this->captureSnapshot(
            $head,
            'SPMU_REQUEST_APPROVAL',
            'SPMU_HEAD',
            'head-ink'
        );

        ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'approver_user_id' => $head->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 1,
            'received_at' => now(),
            'decision' => 'APPROVED',
            'decided_at' => now(),
            'signature_snapshot_id' => $headSnapshot->id,
        ]);

        $html = $this->borrowerSlipHtml($custody->fresh([
            'borrower',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
        ]));

        $borrowerImage = $this->dataUri($borrowerSnapshot);
        $headImage = $this->dataUri($headSnapshot);

        $this->assertStringContainsString(
            $borrowerImage,
            $html,
            "The borrower's signature must render in the 'Very truly yours / "
            .'Signature over Printed Name" area.'
        );

        $this->assertStringContainsString(
            $headImage,
            $html,
            "The approving SPMU Admin's signature must render in the APPROVED area."
        );

        $this->assertNotSame($borrowerImage, $headImage);

        // The borrower's signature must appear exactly once: in the "Very
        // truly yours" block, never inside the item table's "BORROWER'S
        // SIGNATURE UPON RECEIPT OF ITEMS" column, which stays blank for the
        // person who actually receives the items.
        $this->assertSame(1, substr_count($html, $borrowerImage));

        $this->assertStringContainsString($custody->custody_no, $html);
        $this->assertStringContainsString($request->request_no, $html);
    }

    public function test_replacing_a_registered_signature_does_not_alter_an_existing_borrower_slip(): void
    {
        $custody = $this->preparedCustody();
        $request = $custody->request;
        $version = $request->currentVersion;
        $borrower = $custody->borrower;

        $original = $this->captureSnapshot(
            $borrower,
            'BORROWER_REQUEST_CERTIFICATION',
            'BORROWER',
            'original-ink'
        );

        $version->update([
            'borrower_signature_snapshot_id' => $original->id,
            'signed_at' => now(),
        ]);

        $freshCustody = fn () => $custody->fresh([
            'borrower',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
        ]);

        $before = $this->borrowerSlipHtml($freshCustody());

        // The borrower now replaces their registered E-signature.
        UserSignature::query()
            ->where('user_id', $borrower->id)
            ->update(['status' => 'REPLACED', 'effective_to' => now()]);

        $this->registerSignature($borrower, 'completely-different-ink');

        $after = $this->borrowerSlipHtml($freshCustody());

        $this->assertStringContainsString($this->dataUri($original), $after);
        $this->assertSame(
            $before,
            $after,
            'A historical document must not change when the signer replaces their signature.'
        );
    }

    /**
     * Borrower Slip rendering is private production code (it is embedded
     * inside the generated PDF, with no other public accessor). Reflection
     * here is test-only and does not change DocumentService's public API.
     */
    private function borrowerSlipHtml(CustodyTransaction $custody): string
    {
        $method = new \ReflectionMethod(DocumentService::class, 'borrowerSlipHtml');
        $method->setAccessible(true);

        return $method->invoke(app(DocumentService::class), $custody);
    }

    private function gatePassHtml(CustodyTransaction $custody): string
    {
        $method = new \ReflectionMethod(DocumentService::class, 'gatePassHtml');
        $method->setAccessible(true);

        return $method->invoke(app(DocumentService::class), $custody);
    }

    /**
     * EARLY/NORMAL/OVERDUE is a system/audit classification only. The
     * Borrower's Slip must never print "Return Type", "Normal Return",
     * "Early Return", or "Overdue Return" text, regardless of the
     * underlying return_type -- it shows only "Date Returned" (plus any
     * actual adverse findings). No Action Officer E-signature is captured
     * or rendered for the return either.
     */
    public function test_borrower_slip_never_prints_return_type_text_regardless_of_classification(): void
    {
        $officer = $this->spmuActionOfficer();

        foreach (['NORMAL', 'EARLY', 'OVERDUE'] as $returnType) {
            /*
             * Physical release is only permitted on an open SPMU
             * operational day. preparedCustody() schedules pickup for
             * "tomorrow" relative to "now", so re-anchor "now" to the day
             * BEFORE the next open pickup date on every iteration -- that
             * makes the fixture's scheduled date land exactly on a
             * confirmed-open date regardless of which weekday this test
             * happens to run, and regardless of where the clock was left
             * by the previous iteration.
             */
            $this->travelTo(
                app(OperationalCalendarService::class)
                    ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                    ->copy()->subDay()->setTime(9, 0)
            );

            $custody = $this->preparedCustody();

            $this->actingAs($officer);

            // Move "now" into the fixture's own scheduled pickup window.
            $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

            app(CustodyService::class)->release($custody, $officer, 'Physical handover completed.');

            // No signature is captured for Return Inspection either -- the
            // ReturnTransaction fixture below deliberately leaves
            // inspection_signature_snapshot_id unset (null).
            \App\Models\ReturnTransaction::query()->create([
                'return_no' => 'RET-LABEL-'.uniqid(),
                'custody_transaction_id' => $custody->id,
                'received_by_user_id' => $officer->id,
                'return_type' => $returnType,
                'received_at' => now(),
                'status' => 'INSPECTED',
            ]);

            $html = $this->borrowerSlipHtml($custody->fresh([
                'borrower',
                'request.currentVersion.borrowerSignature.file',
                'lines.requestItem.inventoryItem',
                'returns.receivedBy',
            ]));

            foreach (['Return Type', 'Normal Return', 'Early Return', 'Overdue Return'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $html,
                    "The Borrower's Slip must never print \"{$forbidden}\" (return_type={$returnType})."
                );
            }

            $this->assertStringContainsString('Date Returned', $html);
            $this->assertDatabaseHas('return_transactions', [
                'custody_transaction_id' => $custody->id,
                'return_type' => $returnType,
            ]);
        }

        $this->assertSame(
            0,
            SignatureSnapshot::query()->where('purpose_code', 'SPMU_RETURN_INSPECTION')->count(),
            'No SPMU_RETURN_INSPECTION snapshot should ever be created.'
        );
    }

    /**
     * Return Inspection records the Action Officer's identity via
     * received_by_user_id/received_at and the audit trail only -- exercised
     * here through the real CustodyService::receiveReturn() production path
     * (not a manually-built fixture row), with the officer having no
     * registered signature at all.
     */
    public function test_return_inspection_succeeds_without_requiring_any_registered_action_officer_signature(): void
    {
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                ->copy()->subDay()->setTime(9, 0)
        );

        $custody = $this->preparedCustody();
        $officer = $this->spmuActionOfficer();
        $this->actingAs($officer);

        $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

        $service = app(CustodyService::class);
        $service->release($custody, $officer, 'Physical handover completed.');

        $custody = $custody->fresh(['lines']);
        $line = $custody->lines->firstOrFail();

        // Return Inspection is permitted any day once released -- travel to
        // the effective due date only for a deterministic NORMAL label.
        $this->travelTo($custody->due_at);

        $service->receiveReturn(
            $custody,
            $officer,
            [$line->id => (int) $line->quantity_to_receive],
            [$line->id => 'FINE'],
            'Physical return inspected.'
        );

        $return = \App\Models\ReturnTransaction::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertSame($officer->id, $return->received_by_user_id);
        $this->assertNotNull($return->received_at);
        $this->assertSame('NORMAL', $return->return_type);

        $this->assertNull(
            $return->inspection_signature_snapshot_id,
            'Return Inspection must not capture an Action Officer E-signature.'
        );

        $this->assertSame(
            0,
            SignatureSnapshot::query()->where('purpose_code', 'SPMU_RETURN_INSPECTION')->count()
        );
    }

    /**
     * When every returned quantity is Fine/Good, the printed "Remarks upon
     * return of items" area must be completely blank -- no "serviceable /
     * good" text, no "No adverse findings" filler, no generic "physically
     * inspected" sentence, and no generic ReturnTransaction.remarks free
     * text either (even though that field is genuinely filled in and
     * persisted). "Date Returned" still appears, separately. Return Type
     * and both Action Officer E-signatures remain absent throughout.
     */
    public function test_borrower_slip_remarks_are_blank_when_all_items_returned_are_fine(): void
    {
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                ->copy()->subDay()->setTime(9, 0)
        );

        $custody = $this->preparedCustody();
        $officer = $this->spmuActionOfficer();
        $this->actingAs($officer);

        $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

        $service = app(CustodyService::class);
        $service->release($custody, $officer, 'Physical handover completed.');

        $custody = $custody->fresh(['lines']);
        $line = $custody->lines->firstOrFail();

        $this->travelTo($custody->due_at);

        $genericRemarks = 'All items returned in serviceable condition.';

        $service->receiveReturn(
            $custody,
            $officer,
            [$line->id => (int) $line->quantity_to_receive],
            [$line->id => 'FINE'],
            $genericRemarks
        );

        $html = $this->borrowerSlipHtml($custody->fresh([
            'borrower',
            'releasedBy',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
            'returns.receivedBy',
        ]));

        foreach ([
            'serviceable / good',
            'physically inspected by SPMU',
            'No adverse findings',
            'all items good',
            'Inspection note',
            $genericRemarks,
            'Return Type',
            'Normal Return',
            'Early Return',
            'Overdue Return',
            strtoupper($officer->full_name),
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                "The Borrower's Slip must not print \"{$forbidden}\" when everything returned is Fine/Good."
            );
        }

        $this->assertStringContainsString('Date Returned', $html);

        // The Fine/Good quantity and the generic remarks are still fully
        // persisted in the database, just not printed on the slip.
        $this->assertDatabaseHas('return_lines', [
            'custody_line_id' => $line->id,
            'condition_code' => 'FINE',
        ]);
        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'remarks' => $genericRemarks,
        ]);
    }

    /**
     * When a return mixes Fine/Good and adverse quantities across different
     * items, only the structured adverse item/quantity finding may appear in
     * the printed remarks -- the Fine/Good item must be omitted entirely
     * (even though its quantity is still persisted), and the generic
     * ReturnTransaction.remarks free text must NEVER be rendered, even
     * though an adverse finding exists and that field is genuinely filled
     * in and persisted. Return Type and both Action Officer E-signatures
     * remain absent.
     */
    public function test_borrower_slip_remarks_show_only_the_adverse_item_and_omit_the_fine_item(): void
    {
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                ->copy()->subDay()->setTime(9, 0)
        );

        $borrower = User::query()
            ->where('access_classification', AccessClassification::BorrowerOnly->value)
            ->firstOrFail();
        $officer = $this->spmuActionOfficer();

        $fineItem = InventoryItem::where('unique_description', 'Round Table')->firstOrFail();
        $damagedItem = InventoryItem::where('unique_description', 'Microphones')->firstOrFail();

        $scheduleDate = now()->addDay()->startOfDay();
        $returnDate = now()->addDays(2)->startOfDay();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-MIXED-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Mixed condition return test',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'created_by_user_id' => $borrower->id,
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-MIXED-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'scheduled_release_at' => $scheduleDate->copy()->setTime(9, 0),
            'pickup_expires_at' => $scheduleDate->copy()->setTime(12, 0),
            'prepared_at' => now(),
            'due_at' => $returnDate->copy()->endOfDay(),
        ]);

        $lines = [];

        foreach ([$fineItem, $damagedItem] as $item) {
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
                'released_quantity' => 0,
                'restored_quantity' => 0,
                'status' => 'ACTIVE',
                'allocated_at' => now(),
            ]);

            $lines[$item->unique_description] = CustodyLine::query()->create([
                'custody_transaction_id' => $custody->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => 1,
                'quantity_to_receive' => 1,
                'actual_released_quantity' => 0,
                'returned_quantity' => 0,
                'item_status' => 'PREPARED',
            ]);
        }

        $this->actingAs($officer);
        $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

        $service = app(CustodyService::class);
        $service->release(
            $custody->fresh(['lines.requestItem.inventoryItem', 'request.currentVersion']),
            $officer,
            'Physical handover completed.'
        );

        $custody = $custody->fresh(['lines']);
        $this->travelTo($custody->due_at);

        $evidence = \App\Models\StoredFile::query()->create([
            'uploaded_by_user_id' => $officer->id,
            'disk' => 'local',
            'storage_path' => 'test-evidence/'.uniqid().'.pdf',
            'original_name' => 'damage.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => 1,
            'sha256' => hash('sha256', uniqid()),
            'classification' => 'INCIDENT_EVIDENCE',
        ]);

        $genericRemarks = 'Mixed condition return.';

        $service->receiveReturn(
            $custody,
            $officer,
            [
                $lines['Round Table']->id => 1,
                $lines['Microphones']->id => 1,
            ],
            [
                $lines['Round Table']->id => 'FINE',
                $lines['Microphones']->id => 'DAMAGED',
            ],
            $genericRemarks,
            [],
            [$lines['Microphones']->id => $evidence->id]
        );

        $html = $this->borrowerSlipHtml($custody->fresh([
            'borrower',
            'releasedBy',
            'request.currentVersion.borrowerSignature.file',
            'lines.requestItem.inventoryItem',
            'returns.receivedBy',
        ]));

        $this->assertStringContainsString('Microphones — 1 damaged', $html);

        foreach ([
            'Round Table —',
            'serviceable / good',
            'physically inspected by SPMU',
            'Inspection note',
            $genericRemarks,
            'Return Type',
            'Normal Return',
            'Early Return',
            'Overdue Return',
            strtoupper($officer->full_name),
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $html,
                "The Borrower's Slip must not print \"{$forbidden}\" alongside a real adverse finding."
            );
        }

        $this->assertStringContainsString('Date Returned', $html);

        // The Fine quantity for Round Table and the generic remarks are
        // still persisted despite being omitted from the printed slip.
        $this->assertDatabaseHas('return_lines', [
            'custody_line_id' => $lines['Round Table']->id,
            'condition_code' => 'FINE',
            'quantity_received' => 1,
        ]);
        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'remarks' => $genericRemarks,
        ]);
    }

    /** The Gate Pass renders the earlier Action Officer verification signature. */
    public function test_gate_pass_uses_action_officer_verification_signature_before_release(): void
    {
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::PICKUP, now(), true)
                ->copy()->subDay()->setTime(9, 0)
        );

        $custody = $this->preparedOffCampusCustodyWithGatePass();
        $borrowerSnapshot = $this->captureSnapshot(
            $custody->borrower,
            'REQUEST_SUBMISSION',
            'BORROWER',
            'gate-pass-borrower-ink'
        );
        $custody->request->currentVersion->update([
            'borrower_signature_snapshot_id' => $borrowerSnapshot->id,
        ]);

        $officer = $this->spmuActionOfficer();

        $verificationSnapshot = $this->captureSnapshot(
            $officer,
            'SPMU_REQUEST_VERIFICATION',
            'SPMU_ACTION_OFFICER',
            'gate-pass-ink'
        );

        $gatePass = $custody->gatePass;
        $gatePass->update([
            'prepared_verified_by_user_id' => $officer->id,
            'prepared_verifier_signature_snapshot_id' => $verificationSnapshot->id,
            'prepared_verified_at' => now(),
            'status' => 'READY_FOR_PRINTING',
        ]);

        $document = app(DocumentService::class)->conditionalForm(
            $custody->fresh([
                'borrower',
                'request.currentVersion.borrowerSignature.file',
                'gatePass.preparedVerifier',
                'gatePass.preparedVerifierSignature.file',
                'lines.requestItem.inventoryItem.unit',
            ]),
            'GATE_PASS'
        );
        $gatePass->update(['pass_document_id' => $document->id]);
        app(DocumentService::class)->borrowerSlip($custody);

        $this->travelTo($custody->scheduled_release_at->copy()->addMinutes(30));

        app(CustodyService::class)->release($custody, $officer, 'Physical handover completed.');

        $gatePass = \App\Models\GatePass::query()
            ->where('custody_transaction_id', $custody->id)
            ->firstOrFail();

        $this->assertNotNull(
            $gatePass->prepared_verifier_signature_snapshot_id,
            'The Gate Pass must preserve the Action Officer verification signature.'
        );

        $snapshot = SignatureSnapshot::query()->findOrFail(
            $gatePass->prepared_verifier_signature_snapshot_id
        );

        $this->assertSame('SPMU_REQUEST_VERIFICATION', $snapshot->purpose_code);
        $this->assertSame($officer->id, $snapshot->signer_user_id);
        $this->assertSame('READY_FOR_PRINTING', $gatePass->fresh()->status);
        $this->assertNotNull($gatePass->fresh()->pass_document_id);
        $this->assertSame($document->id, $gatePass->fresh()->pass_document_id);

        $html = $this->gatePassHtml($custody->fresh([
            'borrower',
            'request.currentVersion.borrowerSignature.file',
            'gatePass.preparedVerifier',
            'gatePass.preparedVerifierSignature.file',
            'gatePass.approver',
            'gatePass.approverSignature.file',
            'lines.requestItem.inventoryItem.unit',
        ]));

        $this->assertStringContainsString(
            $this->dataUri($borrowerSnapshot),
            $html,
            'The final Gate Pass must render the borrower/bearer E-signature.'
        );
        $this->assertStringContainsString(
            $this->dataUri($snapshot),
            $html,
            'The final Gate Pass must render the Action Officer verification E-signature.'
        );
    }

    /**
     * If the approving SPMU Head/delegate has no designation on file, the
     * generated Borrower's Slip must leave the designation line blank
     * rather than inventing a title (or a different person's name/title
     * entirely) for a real, identified approver.
     */
    public function test_approval_signatory_leaves_designation_blank_when_profile_has_none(): void
    {
        $custody = $this->preparedCustody();
        $version = $custody->request->currentVersion;
        $head = $this->spmuHead();
        $head->update(['designation' => null]);

        $signature = $this->captureSnapshot(
            $head,
            'SPMU_REQUEST_APPROVAL',
            'SPMU_HEAD',
            'head-ink-blank-designation'
        );

        ApprovalStep::query()->create([
            'request_version_id' => $version->id,
            'approver_user_id' => $head->id,
            'stage_code' => ApprovalStage::Spmu,
            'sequence_no' => 1,
            'received_at' => now(),
            'decision' => 'APPROVED',
            'decided_at' => now(),
            'signature_snapshot_id' => $signature->id,
        ]);

        $html = $this->borrowerSlipHtml($custody->fresh([
            'borrower',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem',
        ]));

        /*
         * The Borrower's Slip has a separate, legitimate, static
         * letterhead addressee block that always reads "ANGELICA P.
         * REGONDOLA, PhD / Administrative Officer V" regardless of any
         * request/approver data -- that single occurrence is expected and
         * must stay. What must NOT happen is the dynamic "Approved By"
         * signatory block ALSO printing that same name/title as an
         * invented stand-in for this specific approver, which would show
         * up as a SECOND occurrence.
         */
        $this->assertSame(
            1,
            substr_count($html, 'Administrative Officer V'),
            'The invented title must not be used as a fallback for a real, identified approver with no designation on file.'
        );
        $this->assertSame(
            1,
            substr_count($html, 'ANGELICA P. REGONDOLA'),
            'The invented name must not be used as a fallback for a real, identified approver.'
        );
        $this->assertStringContainsString($head->full_name, $html);
    }

    public function test_custody_cannot_close_while_the_laundry_job_is_still_open(): void
    {
        $custody = $this->preparedCustody();

        $custody->update([
            'status' => 'ACTIVE',
            'released_at' => now(),
        ]);

        $custody->lines()->update([
            'actual_released_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        LaundryJob::query()->create([
            'custody_transaction_id' => $custody->id,
            'status' => 'AWAITING_FINAL_FORM_UPLOAD',
        ]);

        $head = $this->spmuHead();
        $this->registerSignature($head, 'head-ink');

        $incident = \App\Models\Incident::query()->create([
            'incident_no' => 'INC-TEST-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $head->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => 'OPEN',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->post(route('incidents.resolve', $incident), [
                'resolution_outcome' => 'NO_BORROWER_CHARGE',
                'resolution_remarks' => 'No borrower liability for this case.',
            ])
            ->assertRedirect();

        $this->assertNotSame(
            'CLOSED',
            $custody->fresh()->status,
            'Custody must not close while the signed Laundry Form is still pending archival.'
        );

        $this->assertNull($custody->fresh()->closed_at);
    }

    private function dataUri(SignatureSnapshot $snapshot): string
    {
        $bytes = app(ProtectedFileService::class)->bytes($snapshot->file);

        return base64_encode($bytes);
    }

    private function captureSnapshot(
        User $user,
        string $purpose,
        string $role,
        string $ink
    ): SignatureSnapshot {
        $this->registerSignature($user, $ink);
        $this->actingAs($user);

        return app(SignatureService::class)->snapshot($user, $purpose, $role);
    }

    private function registerSignature(User $user, string $ink): UserSignature
    {
        $file = app(ProtectedFileService::class)->storeBytes(
            // Distinct bytes per signer so snapshots are provably different.
            "\x89PNG\r\n\x1a\n".$ink,
            'test-signatures',
            'signature.png',
            'image/png',
            'png',
            'SIGNATURE',
            $user->id
        );

        return UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function spmuActionOfficer(): User
    {
        return User::query()
            ->where('access_classification', AccessClassification::SpmuOfficer->value)
            ->firstOrFail();
    }

    private function spmuHead(): User
    {
        return User::query()
            ->where('access_classification', AccessClassification::SpmuHead->value)
            ->firstOrFail();
    }

    private function preparedCustody(): CustodyTransaction
    {
        $borrower = User::query()
            ->where('access_classification', AccessClassification::BorrowerOnly->value)
            ->firstOrFail();

        $item = InventoryItem::query()
            ->with('unit')
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', false)
            ->firstOrFail();

        $scheduleDate = now()->addDay()->startOfDay();
        $returnDate = now()->addDays(2)->startOfDay();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-SIG-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Role-based signature test',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'created_by_user_id' => $borrower->id,
        ]);

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
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => now(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-SIG-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'scheduled_release_at' => $scheduleDate->copy()->setTime(9, 0),
            'pickup_expires_at' => $scheduleDate->copy()->setTime(12, 0),
            'prepared_at' => now(),
            'due_at' => $returnDate->copy()->endOfDay(),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 0,
            'returned_quantity' => 0,
            'item_status' => 'PREPARED',
        ]);

        return $custody->fresh([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem.unit',
        ]);
    }

    private function preparedOffCampusCustodyWithGatePass(): CustodyTransaction
    {
        $borrower = User::query()
            ->where('access_classification', AccessClassification::BorrowerOnly->value)
            ->firstOrFail();

        $item = InventoryItem::query()
            ->with('unit')
            ->where('active', true)
            ->where('borrowable', true)
            ->where('laundry_required', false)
            ->firstOrFail();

        $scheduleDate = now()->addDay()->startOfDay();
        $returnDate = now()->addDays(2)->startOfDay();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-GATEPASS-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Gate Pass signature test',
            'location' => 'Off-campus venue',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate,
            'return_due_at' => $returnDate->copy()->endOfDay(),
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
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => now(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-GATEPASS-'.uniqid(),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'scheduled_release_at' => $scheduleDate->copy()->setTime(13, 0),
            'pickup_expires_at' => $scheduleDate->copy()->setTime(16, 0),
            'prepared_at' => now(),
            'due_at' => $returnDate->copy()->endOfDay(),
        ]);

        $custodyLine = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 0,
            'returned_quantity' => 0,
            'item_status' => 'PREPARED',
        ]);

        \App\Models\GatePass::query()->create([
            'custody_transaction_id' => $custody->id,
            'custody_line_id' => $custodyLine->id,
            'bearer_name' => $borrower->full_name,
            'destination' => 'Off-campus venue',
            'purpose' => 'Gate Pass signature test',
            'status' => 'DRAFT',
        ]);

        return $custody->fresh([
            'borrower',
            'request.currentVersion',
            'lines.requestItem.inventoryItem.unit',
            'gatePass',
        ]);
    }
}
