<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\GeneratedDocument;
use App\Models\InventoryItem;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OffCampusGatePassWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;

    private User $officer;

    private User $head;

    private InventoryItem $offCampusItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        $this->travelTo(
            app(OperationalCalendarService::class)
                ->nextOpenDate(OperationalCalendarService::REQUEST, now(), true)
                ->setTime(9, 0)
        );

        $this->borrower = $this->classificationUser(AccessClassification::BorrowerOnly);
        $this->officer = $this->classificationUser(AccessClassification::SpmuOfficer);
        $this->head = $this->classificationUser(AccessClassification::SpmuHead);
        $this->offCampusItem = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('off_campus_allowed', true)
            ->firstOrFail();

        $this->registerSignature($this->borrower);
        $this->registerSignature($this->officer);
        $this->registerSignature($this->head);
    }

    public function test_off_campus_non_student_activity_requires_only_request_letter_and_routes_to_officer_first(): void
    {
        $request = $this->submitOffCampusRequest(studentActivity: false, includePtc: false);
        $version = $request->currentVersion;

        $this->assertTrue($version->off_campus);
        $this->assertFalse($version->represents_student_activity);
        $this->assertSame('OFF_CAMPUS', $version->items->first()->use_location);
        $this->assertCount(1, $version->supportingDocuments);
        $this->assertSame(
            RequestSupportingDocument::TYPE_REQUEST_LETTER,
            $version->supportingDocuments->first()->document_type
        );
        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $version->id,
            'sequence_no' => 1,
            'decision' => 'RECEIVED',
        ]);
        $this->assertDatabaseMissing('approval_steps', [
            'request_version_id' => $version->id,
            'sequence_no' => 2,
        ]);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
        $this->assertDatabaseCount('gate_passes', 0);
        $this->assertDatabaseMissing('generated_documents', [
            'document_type' => 'BORROWER_SLIP',
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->get(route('verifications.index'))
            ->assertOk()
            ->assertSeeText($request->request_no)
            ->assertSeeText('Verification is not approval');

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertDontSeeText($request->request_no);
    }

    public function test_off_campus_student_activity_requires_ptc_but_non_student_activity_does_not(): void
    {
        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($this->borrower)
            ->post(route('requests.store'), $this->requestPayload(
                studentActivity: true,
                includePtc: false
            ))
            ->assertSessionHasErrors('permission_to_conduct_letter');

        $this->assertDatabaseCount('borrowing_requests', 0);

        $request = $this->submitOffCampusRequest(studentActivity: true, includePtc: true);

        $this->assertTrue($request->currentVersion->off_campus);
        $this->assertTrue($request->currentVersion->represents_student_activity);
        $this->assertEqualsCanonicalizing(
            [
                RequestSupportingDocument::TYPE_REQUEST_LETTER,
                RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT,
            ],
            $request->currentVersion->supportingDocuments->pluck('document_type')->all()
        );
    }

    public function test_action_officer_can_return_for_correction_and_corrected_version_restarts_verification(): void
    {
        $request = $this->submitOffCampusRequest();
        $oldVersion = $request->currentVersion;

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->post(route('verifications.verify', $request), [
                'decision' => 'RETURNED_FOR_REVISION',
                'remarks' => 'Upload a clearer Borrowing Request Letter.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::ReturnedForRevision, $request->fresh()->status);
        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $oldVersion->id,
            'sequence_no' => 1,
            'decision' => 'RETURNED_FOR_REVISION',
            'approver_user_id' => $this->officer->id,
        ]);
        $this->assertDatabaseMissing('approval_steps', [
            'request_version_id' => $oldVersion->id,
            'sequence_no' => 2,
        ]);
        $this->assertDatabaseCount('allocations', 0);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($this->borrower)
            ->put(route('requests.update', $request), $this->requestPayload())
            ->assertSessionHasNoErrors();

        $corrected = $request->fresh('currentVersion.approvalSteps');

        $this->assertSame(2, $corrected->current_version_no);
        $this->assertSame(RequestStatus::UnderSpmu, $corrected->status);
        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $corrected->currentVersion->id,
            'sequence_no' => 1,
            'decision' => 'RECEIVED',
        ]);
        $this->assertDatabaseHas('request_supporting_documents', [
            'request_version_id' => $oldVersion->id,
            'verification_status' => RequestSupportingDocument::STATUS_RETURNED_FOR_REVISION,
        ]);
        $this->assertDatabaseHas('request_supporting_documents', [
            'request_version_id' => $corrected->currentVersion->id,
            'verification_status' => RequestSupportingDocument::STATUS_PENDING,
            'is_current' => true,
        ]);
    }

    public function test_action_officer_verification_routes_to_head_without_approving_reserving_or_generating(): void
    {
        $request = $this->submitOffCampusRequest();

        $this->verifyByOfficer($request);

        $version = $request->fresh('currentVersion.approvalSteps')->currentVersion;

        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $version->id,
            'sequence_no' => 1,
            'decision' => 'VERIFIED',
            'approver_user_id' => $this->officer->id,
        ]);
        $this->assertDatabaseHas('approval_steps', [
            'request_version_id' => $version->id,
            'sequence_no' => 2,
            'decision' => 'RECEIVED',
        ]);
        $this->assertSame(RequestStatus::UnderSpmu, $request->fresh()->status);
        $this->assertNull($request->fresh()->final_approved_at);
        $this->assertDatabaseCount('allocations', 0);
        $this->assertDatabaseCount('custody_transactions', 0);
        $this->assertDatabaseCount('gate_passes', 0);
        $this->assertDatabaseHas('request_supporting_documents', [
            'request_version_id' => $version->id,
            'verification_status' => RequestSupportingDocument::STATUS_VERIFIED,
            'verified_by_user_id' => $this->officer->id,
        ]);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSeeText($request->request_no);
    }

    public function test_head_rejection_creates_no_inventory_or_operational_documents(): void
    {
        $request = $this->submitOffCampusRequest();
        $this->verifyByOfficer($request);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('approvals.decide', $request), [
                'decision' => 'REJECTED',
                'remarks' => 'The proposed use cannot be approved.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $this->assertNoApprovalSideEffects($request);
    }

    public function test_head_return_for_revision_creates_no_inventory_or_operational_documents(): void
    {
        $request = $this->submitOffCampusRequest();
        $this->verifyByOfficer($request);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('approvals.decide', $request), [
                'decision' => 'RETURNED_FOR_REVISION',
                'remarks' => 'Correct the schedule before approval.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::ReturnedForRevision, $request->fresh()->status);
        $this->assertNoApprovalSideEffects($request);
    }

    public function test_head_approval_generates_one_gate_pass_and_borrower_slip_then_physical_release_reuses_them(): void
    {
        $request = $this->submitOffCampusRequest();
        $this->verifyByOfficer($request);

        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $request->currentVersion->id,
            'document_type' => 'GATE_PASS',
        ]);

        $this->approveByHead($request);

        $request = $request->fresh([
            'currentVersion.documents',
            'custody.lines',
            'custody.gatePass.passDocument',
        ]);
        $custody = $request->custody;
        $gatePass = $custody->gatePass;

        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->status);
        $this->assertNotNull($request->final_approved_at);
        $this->assertSame('PREPARING_RELEASE', $custody->status);
        $this->assertSame('READY_FOR_PRINTING', $gatePass->status);
        $this->assertSame($this->officer->id, $gatePass->prepared_verified_by_user_id);
        $this->assertSame($this->head->id, $gatePass->approved_by_user_id);
        $this->assertNotNull($gatePass->pass_document_id);

        $this->assertSame(1, $this->operationalDocumentCount($custody->id, 'BORROWER_SLIP'));
        $this->assertSame(1, $this->operationalDocumentCount($custody->id, 'GATE_PASS'));

        $borrowerSlip = $this->operationalDocument($custody->id, 'BORROWER_SLIP');
        $gatePassDocument = $this->operationalDocument($custody->id, 'GATE_PASS');

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($this->borrower)
            ->get(route('requests.show', $request))
            ->assertOk()
            ->assertSeeText('Bring the generated documents to SPMU')
            ->assertSee(route('documents.view', $borrowerSlip), false)
            ->assertSee(route('documents.download', $borrowerSlip), false)
            ->assertSee(route('documents.view', $gatePassDocument), false)
            ->assertSee(route('documents.download', $gatePassDocument), false);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($this->borrower)
            ->get(route('documents.view', $gatePassDocument))
            ->assertOk()
            ->assertHeader('content-disposition', 'inline; filename="'.$gatePassDocument->file->original_name.'"');

        $pickupAt = $request->currentVersion->schedule_date->copy()->setTime(13, 0);
        $pickupEndsAt = $pickupAt->copy()->addHours(3);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->post(route('custody.schedule-pickup', $custody), [
                'pickup_at' => $pickupAt->format('Y-m-d H:i:s'),
                'pickup_expires_at' => $pickupEndsAt->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasNoErrors();

        $quantities = $custody->lines
            ->mapWithKeys(fn ($line) => [$line->id => (int) $line->approved_quantity])
            ->all();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->post(route('custody.prepare', $custody), ['quantities' => $quantities])
            ->assertSessionHasNoErrors();

        $gatePassDocumentId = $gatePass->pass_document_id;
        $this->travelTo($pickupAt->copy()->addMinutes(30));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Approved documents validated and property handed over.',
            ])
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $gatePass->refresh();

        $this->assertSame('ACTIVE', $custody->status);
        $this->assertNotNull($custody->released_at);
        $this->assertSame($this->officer->id, $custody->released_by_user_id);
        $this->assertSame($gatePassDocumentId, $gatePass->pass_document_id);
        $this->assertSame(1, $this->operationalDocumentCount($custody->id, 'GATE_PASS'));
        $this->assertDatabaseHas('audit_events', [
            'action_code' => 'ITEMS_RELEASED',
            'record_id' => $custody->id,
        ]);
    }

    private function submitOffCampusRequest(
        bool $studentActivity = false,
        bool $includePtc = false
    ): BorrowingRequest {
        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($this->borrower)
            ->post(route('requests.store'), $this->requestPayload($studentActivity, $includePtc))
            ->assertSessionHasNoErrors();

        return BorrowingRequest::query()
            ->with([
                'currentVersion.items',
                'currentVersion.supportingDocuments',
            ])
            ->latest('id')
            ->firstOrFail();
    }

    private function requestPayload(
        bool $studentActivity = false,
        bool $includePtc = false
    ): array {
        $scheduleDate = app(OperationalCalendarService::class)
            ->nextOpenDate(OperationalCalendarService::PICKUP, now()->addDays(2), true);

        $payload = [
            'purpose_event' => 'Off-campus institutional activity',
            'event_details' => 'Off-campus institutional activity',
            'location' => 'Municipal activity venue',
            'division_code' => 'ADMINISTRATION',
            'office_unit' => 'Office of the President',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $scheduleDate->copy()->addDay()->toDateString(),
            'represents_student_activity' => $studentActivity ? '1' : '0',
            'off_campus' => '1',
            'intent' => 'submit',
            'borrower_acknowledgement' => '1',
            'confirm_e_signature' => '1',
            'item_ids' => [$this->offCampusItem->id],
            'quantities' => [$this->offCampusItem->id => 1],
            'locations' => [$this->offCampusItem->id => 'OFF_CAMPUS'],
            'approved_request_letter' => UploadedFile::fake()->create(
                'borrowing-request-letter.pdf',
                24,
                'application/pdf'
            ),
        ];

        if ($includePtc) {
            $payload['permission_to_conduct_letter'] = UploadedFile::fake()->create(
                'permission-to-conduct.pdf',
                24,
                'application/pdf'
            );
        }

        return $payload;
    }

    private function verifyByOfficer(BorrowingRequest $request): void
    {
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->officer)
            ->post(route('verifications.verify', $request), [
                'decision' => 'VERIFIED',
                'details_complete' => '1',
                'documents_complete' => '1',
                'availability_verified' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();
    }

    private function approveByHead(BorrowingRequest $request): void
    {
        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->head)
            ->post(route('approvals.decide', $request), [
                'decision' => 'APPROVED',
                'details_complete' => '1',
                'documents_complete' => '1',
                'availability_verified' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();
    }

    private function registerSignature(User $user): void
    {
        $bytes = "\x89PNG\r\n\x1a\n".'workflow-signature-'.$user->id;
        $path = 'tests/signatures/'.$user->id.'/signature.png';
        Storage::disk('local')->put($path, $bytes);

        $file = StoredFile::query()->create([
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
            'status' => 'ACTIVE',
        ]);
    }

    private function operationalDocument(int $custodyId, string $type): GeneratedDocument
    {
        return GeneratedDocument::query()
            ->with('file')
            ->where('subject_type', \App\Models\CustodyTransaction::class)
            ->where('subject_id', $custodyId)
            ->where('document_type', $type)
            ->where('status', 'FINAL')
            ->latest('id')
            ->firstOrFail();
    }

    private function operationalDocumentCount(int $custodyId, string $type): int
    {
        return GeneratedDocument::query()
            ->where('subject_type', \App\Models\CustodyTransaction::class)
            ->where('subject_id', $custodyId)
            ->where('document_type', $type)
            ->where('status', 'FINAL')
            ->count();
    }

    private function assertNoApprovalSideEffects(BorrowingRequest $request): void
    {
        $request->loadMissing('currentVersion.items');

        $this->assertDatabaseMissing('allocations', [
            'request_item_id' => $request->currentVersion->items->first()->id,
        ]);
        $this->assertDatabaseMissing('custody_transactions', ['request_id' => $request->id]);
        $this->assertDatabaseCount('gate_passes', 0);
        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $request->currentVersion->id,
            'document_type' => 'BORROWER_SLIP',
        ]);
        $this->assertDatabaseMissing('generated_documents', [
            'request_version_id' => $request->currentVersion->id,
            'document_type' => 'GATE_PASS',
        ]);
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
