<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\OperationalWeeklySchedule;
use App\Models\RequestItem;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Functional coverage for RequestWorkflowService::
 * cancelApprovedForPreparationDiscrepancy() (the "Unable to Fulfill Approved
 * Request" Step 2 resolution), previously covered only by structural
 * (source-string) assertions in ItemPreparationStep2ContractTest, and for the
 * missing-E-signature-confirmation negative path on Create Request
 * submission, previously covered only by the happy path.
 */
class PreparationDiscrepancyCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        OperationalWeeklySchedule::query()
            ->where('is_open', true)
            ->update([
                'open_time' => '13:00',
                'close_time' => '16:00',
            ]);
    }

    public function test_unable_to_fulfill_approved_request_cancels_without_partial_issuance_and_restores_inventory(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] = $this->approvedUnreleasedRequest('BR-PREPISSUE-001', 3);

        $custody = CustodyTransaction::query()->where('request_id', $request->id)->with('lines')->firstOrFail();
        $line = $custody->lines->firstOrFail();
        $requestItem = RequestItem::query()->where('request_version_id', $version->id)->firstOrFail();

        GeneratedDocument::query()->create([
            'request_version_id' => $version->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-'.$custody->custody_no,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.report-preparation-issue', $custody), [
                'custody_line_id' => $line->id,
                'issue_type' => 'QUANTITY_AVAILABILITY',
                'observed_usable_quantity' => 1,
            ])
            ->assertSessionHasNoErrors();

        $preparationIssue = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PREPARATION_ISSUE_REPORTED')
            ->firstOrFail();

        // Physical release must remain unavailable while the discrepancy is
        // under review - no partial issuance is possible from here.
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Attempted release during open discrepancy.',
            ])
            ->assertSessionHasErrors();
        $this->assertNull($custody->fresh()->released_at);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('custody.resolve-preparation-issue', [$custody, $preparationIssue]), [
                'resolution_type' => 'UNABLE_TO_FULFILL_APPROVED_REQUEST',
                'resolution_notes' => 'Only 1 of 3 approved units is physically available; cannot fulfill the complete approved quantity.',
                'confirm_unable_to_fulfill' => '1',
            ])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $custody->refresh();

        $this->assertSame(RequestStatus::Cancelled, $request->status);
        $this->assertSame('CANCELLED', $custody->status);
        $this->assertNotNull($custody->closed_at);
        $this->assertNull($custody->released_at, 'No partial issuance may ever occur through this path.');

        $allocation = Allocation::query()->where('request_item_id', $requestItem->id)->firstOrFail();
        $this->assertSame('CANCELLED', $allocation->status);
        $this->assertEquals((float) $allocation->allocated_quantity, (float) $allocation->restored_quantity);

        $this->assertDatabaseHas('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'INVALIDATED',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'action_code' => 'PREPARATION_ISSUE_CLOSED_UNFULFILLED',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'record_type' => BorrowingRequest::class,
            'record_id' => $request->id,
            'action_code' => 'PREPARATION_UNABLE_TO_FULFILL',
        ]);

        $this->assertDatabaseHas('request_cancellations', [
            'request_id' => $request->id,
            'status' => 'CONFIRMED',
        ]);
    }

    public function test_resolving_the_same_preparation_issue_twice_as_unable_to_fulfill_is_rejected(): void
    {
        [, $spmu, $spmuOfficer, $request, $version] = $this->approvedUnreleasedRequest('BR-PREPISSUE-002', 2);

        $custody = CustodyTransaction::query()->where('request_id', $request->id)->with('lines')->firstOrFail();
        $line = $custody->lines->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.report-preparation-issue', $custody), [
                'custody_line_id' => $line->id,
                'issue_type' => 'QUANTITY_AVAILABILITY',
                'observed_usable_quantity' => 0,
            ])
            ->assertSessionHasNoErrors();

        $preparationIssue = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PREPARATION_ISSUE_REPORTED')
            ->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('custody.resolve-preparation-issue', [$custody, $preparationIssue]), [
                'resolution_type' => 'UNABLE_TO_FULFILL_APPROVED_REQUEST',
                'resolution_notes' => 'Nothing usable is available.',
                'confirm_unable_to_fulfill' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);

        // The custody is already CANCELLED, so any second resolution attempt
        // against the same custody must fail rather than double-cancel.
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('custody.resolve-preparation-issue', [$custody, $preparationIssue]), [
                'resolution_type' => 'UNABLE_TO_FULFILL_APPROVED_REQUEST',
                'resolution_notes' => 'Second attempt.',
                'confirm_unable_to_fulfill' => '1',
            ])
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('request_cancellations', 1);
    }

    public function test_submitting_a_request_without_confirming_the_e_signature_is_rejected(): void
    {
        $borrower = User::query()
            ->whereHas('roles', fn ($q) => $q->where('role_code', UserRole::Borrower->value)->whereNull('user_roles.revoked_at'))
            ->firstOrFail();
        $this->registerSignature($borrower);

        $item = InventoryItem::where('unique_description', 'Round Table')->firstOrFail();

        $request = BorrowingRequest::create([
            'request_no' => 'BR-NOSIGN-001',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'E-signature negative-path fixture',
            'location' => 'CSPC Campus',
            'schedule_date' => now()->addDays(2)->toDateString(),
            'return_date' => now()->addDays(5)->toDateString(),
            'needed_from' => now()->addDays(2)->startOfDay(),
            'return_due_at' => now()->addDays(5)->endOfDay(),
            'represents_student_activity' => false,
            'event_details' => 'E-signature negative-path test.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'use_location' => 'ON_CAMPUS',
        ]);

        $this->attachApprovedRequestLetter($request, $version, $borrower);

        // borrower_acknowledgement accepted, confirm_e_signature withheld.
        $this->actingAs($borrower)
            ->post(route('requests.submit', $request), [
                'borrower_acknowledgement' => '1',
            ])
            ->assertSessionHasErrors('confirm_e_signature');

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('approval_steps', 0);
    }

    /**
     * @return array{0:User,1:User,2:User,3:BorrowingRequest,4:mixed}
     */
    private function approvedUnreleasedRequest(string $requestNo, int $quantity): array
    {
        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::REQUEST, now(), true)
                ->setTime(9, 0)
        );

        $borrower = User::query()
            ->whereHas('roles', fn ($q) => $q->where('role_code', UserRole::Borrower->value)->whereNull('user_roles.revoked_at'))
            ->firstOrFail();
        $spmu = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
        $spmuOfficer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();

        $this->registerSignature($borrower);
        $this->registerSignature($spmu);
        $this->registerSignature($spmuOfficer);

        $item = InventoryItem::where('unique_description', 'Round Table')->firstOrFail();

        $request = BorrowingRequest::create([
            'request_no' => $requestNo,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Preparation discrepancy fixture',
            'location' => 'CSPC Campus',
            'schedule_date' => now()->addDays(2)->startOfDay()->toDateString(),
            'return_date' => now()->addDays(5)->startOfDay()->toDateString(),
            'needed_from' => now()->addDays(2)->startOfDay(),
            'return_due_at' => now()->addDays(5)->startOfDay()->endOfDay(),
            'represents_student_activity' => false,
            'event_details' => 'Preparation discrepancy test.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => $quantity,
            'use_location' => 'ON_CAMPUS',
        ]);

        $this->attachApprovedRequestLetter($request, $version, $borrower);

        $this->actingAs($borrower)
            ->post(route('requests.submit', $request), [
                'borrower_acknowledgement' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);

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

        $request->refresh();
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->status);

        return [$borrower, $spmu, $spmuOfficer, $request, $version->fresh()];
    }

    private function attachApprovedRequestLetter(BorrowingRequest $request, $version, User $borrower): void
    {
        $bytes = '%PDF-1.4 preparation-discrepancy-test';

        $file = StoredFile::create([
            'uploaded_by_user_id' => $borrower->id,
            'disk' => 'local',
            'storage_path' => 'tests/request-supporting-documents/'.$request->id.'/signed-approved-request-letter.pdf',
            'original_name' => 'signed-approved-request-letter.pdf',
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'classification' => 'REQUEST_SUPPORTING_DOCUMENT',
        ]);

        RequestSupportingDocument::create([
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'document_type' => RequestSupportingDocument::TYPE_REQUEST_LETTER,
            'version_no' => 1,
            'stored_file_id' => $file->id,
            'uploaded_by_user_id' => $borrower->id,
            'uploaded_at' => now(),
            'verification_status' => RequestSupportingDocument::STATUS_PENDING,
            'verified_by_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function registerSignature(User $user): void
    {
        if (UserSignature::where('user_id', $user->id)->where('status', 'ACTIVE')->exists()) {
            return;
        }

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

        UserSignature::query()->create([
            'user_id' => $user->id,
            'stored_file_id' => $file->id,
            'effective_from' => now()->subMinute(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);
    }
}
