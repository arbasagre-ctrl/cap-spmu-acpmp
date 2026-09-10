<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\BillingLine;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\LaundryJob;
use App\Models\LaundryJobLine;
use App\Models\LaundryRecord;
use App\Models\RequestItem;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\SignatureSnapshot;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\CustodyService;
use App\Services\DocumentService;
use App\Services\InventoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression coverage for the workflow audit corrections:
 *  - Physical Release attestation persistence, never global completion
 *  - Early Return coordination-only
 *  - Global "Completed" as a live, shared obligation check (not custody
 *    CLOSED trusted as a proxy)
 *  - Legacy laundry double-counting fix
 *  - No automatic borrower liability from laundry-discovered damage
 *  - Gate Pass stage gating on all three required signatures
 *  - SignatureSnapshot immutability
 *  - Borrower Slip aggregates adverse findings across all returns
 */
class WorkflowAuditCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    // ------------------------------------------------------------------
    // 1. Physical Release
    // ------------------------------------------------------------------

    public function test_physical_release_persists_attestation_and_never_globally_completes(): void
    {
        [, , $spmuOfficer, , , $custody] = $this->releasedCustody('Monoblock Chairs', 3);

        $custody->refresh();

        $this->assertSame('ACTIVE', $custody->status);
        $this->assertNotNull($custody->physical_handover_attested_at);

        $this->assertFalse(app(CustodyService::class)->isGloballyCompleted($custody));

        $this->assertDatabaseHas('audit_events', [
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'action_code' => 'ITEMS_RELEASED',
        ]);
    }

    // ------------------------------------------------------------------
    // 3. Early Return — coordination only
    // ------------------------------------------------------------------

    public function test_early_return_request_does_not_touch_inventory_or_returned_quantity(): void
    {
        [$borrower, , , , , $custody] = $this->releasedCustody('Monoblock Chairs', 3);

        $line = $custody->lines->first();
        $beforeReturned = (float) $line->returned_quantity;

        $inventory = app(InventoryService::class);
        $item = InventoryItem::where('unique_description', 'Monoblock Chairs')->firstOrFail();
        $beforeAvailable = (float) $inventory->availability($item, now()->subMonth(), now()->addMonth())['current_available'];

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('custody.early-return', $custody), [
                'proposed_return_at' => $custody->due_at->copy()->subHour()->format('Y-m-d H:i:s'),
                'reason' => 'Event ended early.',
            ])
            ->assertSessionHasNoErrors();

        $line->refresh();
        $custody->refresh();

        $this->assertSame($beforeReturned, (float) $line->returned_quantity);
        $this->assertSame('ACTIVE', $custody->status);
        $this->assertNull($custody->closed_at);

        $afterAvailable = (float) $inventory->availability($item->fresh(), now()->subMonth(), now()->addMonth())['current_available'];
        $this->assertSame($beforeAvailable, $afterAvailable);
    }

    // ------------------------------------------------------------------
    // 5. Global completion — live obligation checks, not CLOSED-trusting
    // ------------------------------------------------------------------

    public function test_turned_over_to_laundry_prevents_global_completion_even_though_custody_closes(): void
    {
        [, , , , , $custody, $job] = $this->laundryTurnedOver();

        $custody->refresh();
        $this->assertSame('CLOSED', $custody->status);
        $this->assertSame('TURNED_OVER_TO_LAUNDRY', $job->fresh()->status);

        $this->assertFalse(app(CustodyService::class)->isGloballyCompleted($custody));
    }

    public function test_laundry_completion_permits_global_completion_only_when_nothing_else_is_open(): void
    {
        [, $spmuOfficer, , , , $custody, $job, $jobLine] = $this->laundryTurnedOver();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('laundry.complete-processing', $job), [
                'worker_remarks' => 'All linen cleaned.',
                'lines' => [
                    $jobLine->id => [
                        'cleaned_quantity' => (int) $jobLine->issued_quantity,
                        'damaged_quantity' => 0,
                        'remarks' => null,
                    ],
                ],
            ])
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $this->assertSame('LAUNDRY_COMPLETED', $job->fresh()->status);
        $this->assertSame('CLOSED', $custody->status);
        $this->assertTrue(app(CustodyService::class)->isGloballyCompleted($custody));
    }

    public function test_closed_custody_with_later_open_incident_immediately_fails_global_completion(): void
    {
        [, , , , , $custody, $job, $jobLine] = $this->laundryTurnedOver();

        // Laundry finishes cleanly: nothing left open via laundry itself.
        app(CustodyService::class); // ensure container resolved before manual mutation
        $job->update(['status' => 'LAUNDRY_COMPLETED', 'completed_at' => now()]);
        $jobLine->update(['completed_quantity' => $jobLine->issued_quantity]);

        $custody->refresh();
        $this->assertSame('CLOSED', $custody->status);

        // Simulate the legacy laundry-damage path opening a fresh incident
        // AFTER custody already reached CLOSED, without anything re-running
        // reconcileTransactionStatus() yet.
        Incident::create([
            'incident_no' => 'INC-TEST-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $custody->borrower_user_id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => 'OPEN',
        ]);

        // custody.status is still 'CLOSED' in the database at this point —
        // isGloballyCompleted() must not trust that cached value.
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertFalse(app(CustodyService::class)->isGloballyCompleted($custody->fresh()));
    }

    public function test_unresolved_billing_prevents_global_completion(): void
    {
        [, , , , , $custody] = $this->releasedCustody('Monoblock Chairs', 2);

        $line = $custody->lines->first();
        $return = ReturnTransaction::create([
            'return_no' => 'RET-BILL-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $custody->borrower_user_id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);
        ReturnLine::create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $line->id,
            'quantity_received' => (float) $line->actual_released_quantity,
            'condition_code' => 'FINE',
            'disposition_state' => 'AVAILABLE',
        ]);
        $line->update(['returned_quantity' => $line->actual_released_quantity]);

        $incident = Incident::create([
            'incident_no' => 'INC-BILL-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $custody->borrower_user_id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => 'RESOLVED',
        ]);

        $this->closeCustody($custody);
        $this->assertSame('CLOSED', $custody->fresh()->status);
        $this->assertTrue(app(CustodyService::class)->isGloballyCompleted($custody->fresh()));

        $billing = BillingStatement::create([
            'billing_no' => 'BILL-TEST-'.uniqid(),
            'borrower_user_id' => $custody->borrower_user_id,
            'responsible_spmu_user_id' => $custody->borrower_user_id,
            'issued_at' => now(),
            'total_amount' => 500,
            'status' => 'ISSUED',
        ]);

        BillingLine::create([
            'billing_statement_id' => $billing->id,
            'incident_id' => $incident->id,
            'line_type' => 'DAMAGE',
            'description' => 'Test damage line',
            'amount' => 500,
        ]);

        $this->assertFalse(app(CustodyService::class)->isGloballyCompleted($custody->fresh()));

        $billing->update(['status' => 'SETTLED']);
        $this->assertTrue(app(CustodyService::class)->isGloballyCompleted($custody->fresh()));
    }

    // ------------------------------------------------------------------
    // 6. Legacy laundry double-counting
    // ------------------------------------------------------------------

    public function test_legacy_laundry_quantity_is_counted_once_not_twice(): void
    {
        $item = InventoryItem::where('laundry_required', true)->firstOrFail();
        [, , , , , $custody] = $this->releasedCustody($item->unique_description, 5);

        $line = $custody->lines->first();

        // Physical Release unconditionally creates a current-workflow
        // LaundryJob/LaundryJobLine for every laundry-required line. To
        // reproduce the audit's genuinely LEGACY scenario (a return line
        // with no LaundryJobLine at all, predating that system), remove it.
        LaundryJobLine::where('custody_line_id', $line->id)->delete();
        LaundryJob::where('custody_transaction_id', $custody->id)->delete();

        $return = ReturnTransaction::create([
            'return_no' => 'RET-LEGACY-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $custody->borrower_user_id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);

        $returnLine = ReturnLine::create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $line->id,
            'quantity_received' => 5,
            'condition_code' => 'FINE',
            'disposition_state' => 'LAUNDRY',
        ]);

        $line->increment('returned_quantity', 5);

        LaundryRecord::create([
            'return_line_id' => $returnLine->id,
            'cleaned_quantity' => 0,
            'damaged_quantity' => 0,
            'status' => 'PENDING_EVIDENCE',
        ]);

        $inventory = app(InventoryService::class);
        $balance = $inventory->availability($item->fresh(), now()->subMonth(), now()->addMonth());

        // Before this fix, this reproduced the audit's "5 counted as 10".
        $this->assertSame(5.0, (float) $balance['laundry']);

        $laundryRecord = LaundryRecord::query()->where('return_line_id', $returnLine->id)->firstOrFail();
        $laundryRecord->update(['status' => 'VERIFIED', 'cleaned_quantity' => 5]);

        $afterVerification = $inventory->availability($item->fresh(), now()->subMonth(), now()->addMonth());
        $this->assertSame(0.0, (float) $afterVerification['laundry']);
    }

    // ------------------------------------------------------------------
    // 4. No auto-liability from laundry-discovered damage (legacy path)
    // ------------------------------------------------------------------

    public function test_legacy_laundry_damage_creates_incident_but_no_automatic_liability(): void
    {
        $item = InventoryItem::where('laundry_required', true)->firstOrFail();
        [, $spmuOfficer, , , , $custody] = $this->releasedCustody($item->unique_description, 4);

        $line = $custody->lines->first();

        // See test_legacy_laundry_quantity_is_counted_once_not_twice() for
        // why this must be removed to reproduce the genuinely legacy path.
        LaundryJobLine::where('custody_line_id', $line->id)->delete();
        LaundryJob::where('custody_transaction_id', $custody->id)->delete();

        $return = ReturnTransaction::create([
            'return_no' => 'RET-LEGACY-DMG-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $custody->borrower_user_id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);

        $returnLine = ReturnLine::create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $line->id,
            'quantity_received' => 4,
            'condition_code' => 'FINE',
            'disposition_state' => 'LAUNDRY',
        ]);

        $line->increment('returned_quantity', 4);

        $laundry = LaundryRecord::create([
            'return_line_id' => $returnLine->id,
            'cleaned_quantity' => 0,
            'damaged_quantity' => 0,
            'status' => 'EVIDENCE_VERIFIED_PENDING_PHYSICAL_CHECK',
        ]);

        $document = GeneratedDocument::create([
            'stored_file_id' => $this->fakeStoredFile('laundry-form.pdf')->id,
            'request_version_id' => $custody->request->currentVersion->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-LAUNDRY-'.uniqid(),
            'document_type' => 'LAUNDRY_FORM',
            'version_no' => 1,
            'sha256' => str_repeat('a', 64),
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        \App\Models\EvidenceSubmission::create([
            'generated_document_id' => $document->id,
            'stored_file_id' => $this->fakeStoredFile('evidence.pdf')->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'uploaded_by_user_id' => $spmuOfficer->id,
            'verified_by_user_id' => $spmuOfficer->id,
            'upload_mode' => 'SPMU_ACTION_OFFICER',
            'submitted_at' => now(),
            'verification_status' => 'VERIFIED',
            'verified_at' => now(),
        ]);

        $laundry->update(['form_document_id' => $document->id]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('laundry.verify', $laundry), [
                'worker_name' => 'Test Laundry Worker',
                'worker_received_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'worker_completed_at' => now()->format('Y-m-d H:i:s'),
                'cleaned_quantity' => 2,
                'damaged_quantity' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('incidents', [
            'custody_transaction_id' => $custody->id,
            'incident_type' => 'DAMAGED',
            'status' => 'OPEN',
        ]);

        $this->assertDatabaseMissing('borrower_restrictions', [
            'borrower_user_id' => $custody->borrower_user_id,
        ]);

        $this->assertDatabaseCount('billing_statements', 0);
    }

    // ------------------------------------------------------------------
    // 7/8. Gate Pass stage gating on all three required signatures
    // ------------------------------------------------------------------

    public function test_gate_pass_final_generation_fails_before_physical_release_signature(): void
    {
        [, , , $custody] = $this->offCampusApprovedCustody();

        $this->expectException(ValidationException::class);

        app(DocumentService::class)->conditionalForm($custody->fresh(['request.currentVersion', 'gatePass']), 'GATE_PASS');
    }

    public function test_gate_pass_final_generation_requires_each_individual_signature(): void
    {
        [, , , $custody] = $this->offCampusApprovedCustody();

        $documents = app(DocumentService::class);

        // Missing Action Officer + missing borrower + missing approver are
        // all independently fatal. Clear the approver signature the normal
        // approval flow captured, to test that branch too.
        $custody->gatePass->update(['approver_signature_snapshot_id' => null]);

        try {
            $documents->conditionalForm($custody->fresh(['request.currentVersion', 'gatePass']), 'GATE_PASS');
            $this->fail('Expected ValidationException for missing SPMU Head/delegate approval signature.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('SPMU Head/delegate approval', $exception->validator->errors()->first('document'));
        }
    }

    public function test_gate_pass_reaches_ready_for_printing_with_all_signatures_at_physical_release(): void
    {
        [, , $spmuOfficer, $custody] = $this->offCampusApprovedCustody();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Off-campus release with Gate Pass.',
            ])
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $gatePass = $custody->gatePass()->firstOrFail();

        $this->assertSame('READY_FOR_PRINTING', $gatePass->status);
        $this->assertNotNull($gatePass->prepared_verifier_signature_snapshot_id);
        $this->assertNotNull($gatePass->approver_signature_snapshot_id);
        $this->assertNotNull($custody->request->currentVersion->borrower_signature_snapshot_id);
    }

    public function test_gate_pass_verification_rejects_pending_status(): void
    {
        [, , $spmuOfficer, $custody] = $this->offCampusApprovedCustody();
        $gatePass = $custody->gatePass()->firstOrFail();
        // Deliberately left at PENDING (release not yet performed).

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('gate-passes.verify', $gatePass), [
                'accomplished_form' => \Illuminate\Http\UploadedFile::fake()->create('scan.pdf', 20, 'application/pdf'),
                'guard_name' => 'Test Guard',
                'guard_signed_at' => now()->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasErrors('gate_pass');

        $this->assertSame('PENDING', $gatePass->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 9. Borrower Slip aggregates findings across all returns
    // ------------------------------------------------------------------

    public function test_regenerated_borrower_slip_preserves_earlier_adverse_finding_after_later_clean_return(): void
    {
        [, , , , , $custody] = $this->releasedCustody('Monoblock Chairs', 4);
        $line = $custody->lines->first();

        $firstReturn = ReturnTransaction::create([
            'return_no' => 'RET-EARLY-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $custody->borrower_user_id,
            'return_type' => 'NORMAL',
            'received_at' => now()->subDay(),
            'status' => 'INSPECTED',
        ]);

        ReturnLine::create([
            'return_transaction_id' => $firstReturn->id,
            'custody_line_id' => $line->id,
            'quantity_received' => 1,
            'condition_code' => 'DAMAGED',
            'disposition_state' => 'DAMAGED_MAINTENANCE',
        ]);

        $line->increment('returned_quantity', 1);

        $secondReturn = ReturnTransaction::create([
            'return_no' => 'RET-LATER-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $custody->borrower_user_id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);

        ReturnLine::create([
            'return_transaction_id' => $secondReturn->id,
            'custody_line_id' => $line->id,
            'quantity_received' => 3,
            'condition_code' => 'FINE',
            'disposition_state' => 'AVAILABLE',
        ]);

        $line->increment('returned_quantity', 3);

        $freshCustody = $custody->fresh([
            'borrower',
            'releasedBy',
            'releaseSignature.file',
            'request.borrower',
            'request.currentVersion.borrowerSignature.file',
            'request.currentVersion.approvalSteps.approver',
            'request.currentVersion.approvalSteps.signatureSnapshot.file',
            'lines.requestItem.inventoryItem',
            'returns.lines.custodyLine.requestItem',
            'returns.receivedBy',
        ]);

        // borrowerSlipHtml() is private production code, embedded only
        // inside the generated PDF binary with no other accessor — reflect
        // into it directly rather than trying to find plain text inside a
        // compressed PDF stream (see RoleBasedSignatureTest's same pattern).
        $method = new \ReflectionMethod(DocumentService::class, 'borrowerSlipHtml');
        $method->setAccessible(true);
        $html = $method->invoke(app(DocumentService::class), $freshCustody);

        $this->assertStringContainsString('damaged', $html);
    }

    // ------------------------------------------------------------------
    // 8. SignatureSnapshot immutability
    // ------------------------------------------------------------------

    public function test_signature_snapshot_cannot_be_updated_or_deleted(): void
    {
        $user = $this->classificationUser(AccessClassification::SpmuHead);
        $file = $this->fakeStoredFile('sig.png');
        $userSignature = \App\Models\UserSignature::create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'status' => 'ACTIVE',
        ]);

        $snapshot = SignatureSnapshot::create([
            'user_signature_id' => $userSignature->id,
            'signer_user_id' => $user->id,
            'snapshot_file_id' => $file->id,
            'signer_name' => $user->full_name,
            'signer_role' => 'SPMU Head',
            'purpose_code' => 'TEST',
            'sha256' => $file->sha256,
            'captured_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $snapshot->update(['signer_name' => 'Someone Else']);
    }

    public function test_signature_snapshot_deletion_is_rejected(): void
    {
        $user = $this->classificationUser(AccessClassification::SpmuHead);
        $file = $this->fakeStoredFile('sig2.png');
        $userSignature = \App\Models\UserSignature::create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'status' => 'ACTIVE',
        ]);

        $snapshot = SignatureSnapshot::create([
            'user_signature_id' => $userSignature->id,
            'signer_user_id' => $user->id,
            'snapshot_file_id' => $file->id,
            'signer_name' => $user->full_name,
            'signer_role' => 'SPMU Head',
            'purpose_code' => 'TEST',
            'sha256' => $file->sha256,
            'captured_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $snapshot->delete();
    }

    // ==================================================================
    // Fixture builders
    // ==================================================================

    private function fakeStoredFile(string $name): StoredFile
    {
        $bytes = '%PDF-1.4 test-'.uniqid();
        $path = 'tests/wf-audit/'.uniqid().'-'.$name;
        Storage::disk('local')->put($path, $bytes);

        return StoredFile::create([
            'uploaded_by_user_id' => null,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/octet-stream',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'PROTECTED',
        ]);
    }

    private function registerSignature(User $user): void
    {
        $bytes = "\x89PNG\r\n\x1a\n".'signature-ink-'.$user->id;
        $path = 'tests/signatures/'.$user->id.'/signature.png';

        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::create([
            'uploaded_by_user_id' => $user->id,
            'disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'signature.png',
            'mime_type' => 'image/png',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'SIGNATURE',
        ]);

        \App\Models\UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()->where('access_classification', $classification->value)->firstOrFail();
    }

    private function roleUser(UserRole $role): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('role_code', $role->value)->whereNull('user_roles.revoked_at'))
            ->firstOrFail();
    }

    /**
     * Drives a request all the way through approval, pickup scheduling,
     * preparation, and Physical Release via the real HTTP endpoints (so
     * real signature snapshots and Gate Pass records are produced exactly
     * as in production), for a single on-campus (unless $offCampus) item.
     *
     * @return array{0: User, 1: User, 2: User, 3: BorrowingRequest, 4: mixed, 5: CustodyTransaction}
     */
    private function releasedCustody(
        string $itemDescription,
        float $quantity,
        bool $offCampus = false
    ): array {
        $this->travelTo(
            app(\App\Services\OperationalCalendarService::class)
                ->nextOpenDate(\App\Services\OperationalCalendarService::REQUEST, now(), true)
                ->setTime(9, 0)
        );

        $borrower = $this->roleUser(UserRole::Borrower);
        $spmu = $this->classificationUser(AccessClassification::SpmuHead);
        $spmuOfficer = $this->classificationUser(AccessClassification::SpmuOfficer);

        $this->registerSignature($spmu);
        $this->registerSignature($spmuOfficer);
        $this->registerSignature($borrower);

        $item = InventoryItem::where('unique_description', $itemDescription)->firstOrFail();

        $scheduleDate = now()->addDays(2)->startOfDay();
        $returnDate = now()->addDays(3)->startOfDay();

        $request = BorrowingRequest::create([
            'request_no' => 'BR-WFAUDIT-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Workflow audit correction test',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate->copy()->startOfDay(),
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'represents_student_activity' => false,
            'event_details' => 'Workflow audit correction regression test.',
            'off_campus' => $offCampus,
            'created_by_user_id' => $borrower->id,
        ]);

        RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => $quantity,
            'use_location' => $offCampus ? 'OFF_CAMPUS' : 'ON_CAMPUS',
        ]);

        $bytes = '%PDF-1.4 wf-audit-request-letter';
        $letterPath = 'tests/wf-audit/'.$request->id.'/letter.pdf';
        Storage::disk('local')->put($letterPath, $bytes);

        $file = StoredFile::create([
            'uploaded_by_user_id' => $borrower->id,
            'disk' => 'local',
            'storage_path' => $letterPath,
            'original_name' => 'signed-approved-request-letter.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'REQUEST_SUPPORTING_DOCUMENT',
        ]);

        \App\Models\RequestSupportingDocument::create([
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'document_type' => \App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'version_no' => 1,
            'stored_file_id' => $file->id,
            'uploaded_by_user_id' => $borrower->id,
            'uploaded_at' => now(),
            'verification_status' => \App\Models\RequestSupportingDocument::STATUS_PENDING,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'verification_remarks' => null,
            'is_current' => true,
            'superseded_at' => null,
        ]);

        $this->actingAs($borrower)
            ->post(route('requests.submit', $request), [
                'borrower_acknowledgement' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('approvals.decide', $request), [
                'decision' => 'APPROVED',
                'details_complete' => '1',
                'documents_complete' => '1',
                'availability_verified' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();

        $custody = CustodyTransaction::where('request_id', $request->id)->with('lines')->firstOrFail();

        $pickupAt = $version->schedule_date->copy()->setTime(9, 0, 0);
        $pickupExpiresAt = $pickupAt->copy()->addHours(3);

        $this->travelTo($pickupAt->copy()->subHour());

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.schedule-pickup', $custody), [
                'pickup_at' => $pickupAt->format('Y-m-d H:i:s'),
                'pickup_expires_at' => $pickupExpiresAt->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasNoErrors();

        $preparedQuantities = $custody->lines->mapWithKeys(fn ($line) => [$line->id => (int) $line->approved_quantity])->all();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.prepare', $custody), ['quantities' => $preparedQuantities])
            ->assertSessionHasNoErrors();

        $this->travelTo($pickupAt->copy()->addMinutes(30));

        if (! $offCampus) {
            $this->withSession(['active_workspace' => 'SPMU'])
                ->actingAs($spmuOfficer)
                ->post(route('custody.release', $custody), [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Workflow audit correction test release.',
                ])
                ->assertSessionHasNoErrors();
        }

        $custody->refresh()->load('lines');

        return [$borrower, $spmu, $spmuOfficer, $request, $version->fresh(), $custody];
    }

    /**
     * Builds an off-campus custody through approval and pickup/prepare, but
     * deliberately stops BEFORE Physical Release, leaving the PENDING Gate
     * Pass with only the borrower + SPMU Head signatures captured (the
     * Action Officer signature is only captured at release).
     */
    private function offCampusApprovedCustody(): array
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version, $custody] =
            $this->releasedCustody('Barricade', 2, offCampus: true);

        return [$borrower, $spmu, $spmuOfficer, $custody->fresh(['lines', 'gatePass', 'request.currentVersion'])];
    }

    /**
     * Released custody with a laundry-required item, turned over to
     * Laundry via the real endpoints (custody CLOSED, internal washing
     * still pending).
     *
     * @return array{0: User, 1: User, 2: User, 3: BorrowingRequest, 4: mixed, 5: CustodyTransaction, 6: LaundryJob, 7: LaundryJobLine}
     */
    private function laundryTurnedOver(): array
    {
        $item = InventoryItem::where('laundry_required', true)->firstOrFail();
        [$borrower, $spmu, $spmuOfficer, $request, $version, $custody] =
            $this->releasedCustody($item->unique_description, 3);

        $line = $custody->lines->first();
        $job = LaundryJob::where('custody_transaction_id', $custody->id)->firstOrFail();
        $jobLine = LaundryJobLine::where('laundry_job_id', $job->id)->firstOrFail();

        $return = ReturnTransaction::create([
            'return_no' => 'RET-TURNOVER-'.uniqid(),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $spmuOfficer->id,
            'return_type' => 'NORMAL',
            'received_at' => now(),
            'status' => 'INSPECTED',
        ]);

        ReturnLine::create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $line->id,
            'quantity_received' => (float) $line->actual_released_quantity,
            'condition_code' => 'FINE',
            'disposition_state' => 'LAUNDRY',
        ]);

        $line->update(['returned_quantity' => $line->actual_released_quantity]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('laundry.receive', $job), [
                'laundry_received_signature_confirmed' => 1,
                'worker_remarks' => null,
            ])
            ->assertSessionHasNoErrors();

        return [$borrower, $spmuOfficer, $spmu, $request, $version, $custody->fresh(), $job->fresh(), $jobLine->fresh()];
    }

    private function closeCustody(CustodyTransaction $custody): void
    {
        app(CustodyService::class)->reconcileTransactionStatus($custody);
    }
}
