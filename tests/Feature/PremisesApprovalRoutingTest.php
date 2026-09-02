<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\ApprovalStep;
use App\Models\BorrowingRequest;
use App\Models\RequestSupportingDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Models\UserSignature;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Premises decides where a submitted request enters the SPMU queue.
 *
 *   ON-CAMPUS   submit -> SPMU Head For Approval (sequence 2) immediately
 *   OFF-CAMPUS  submit -> Action Officer verification (sequence 1) first,
 *                         then SPMU Head For Approval
 *
 * Both stay visible in Request Records throughout.
 */
class PremisesApprovalRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');

        // Anchor to a configured open weekday so the operational calendar is
        // never what decides these routing assertions.
        $this->travelTo(Carbon::now()->next(Carbon::MONDAY)->setTime(9, 0));
    }

    public function test_on_campus_request_is_immediately_eligible_for_head_approval(): void
    {
        $request = $this->submittedRequest(offCampus: false);

        // Entry step is the Head decision step, not Action Officer verification.
        $this->assertSame(
            [2],
            $this->sequenceNumbers($request)
        );

        $this->assertTrue($this->headQueueContains($request));
        $this->assertFalse($this->officerQueueContains($request));
    }

    public function test_off_campus_request_waits_for_action_officer_verification(): void
    {
        $request = $this->submittedRequest(offCampus: true);

        $this->assertSame(
            [1],
            $this->sequenceNumbers($request)
        );

        // Not yet eligible for the Head.
        $this->assertFalse($this->headQueueContains($request));
        $this->assertTrue($this->officerQueueContains($request));
    }

    public function test_off_campus_request_reaches_the_head_after_verification(): void
    {
        $request = $this->submittedRequest(offCampus: true);

        $this->verifyAsActionOfficer($request);

        $this->assertSame(
            [1, 2],
            $this->sequenceNumbers($request)
        );

        $this->assertTrue($this->headQueueContains($request));
        $this->assertFalse($this->officerQueueContains($request));
    }

    public function test_both_premises_stay_visible_in_request_records(): void
    {
        $onCampus = $this->submittedRequest(offCampus: false);
        $offCampus = $this->submittedRequest(offCampus: true);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->classificationUser(AccessClassification::SpmuHead))
            ->get(route('requests.index'))
            ->assertOk()
            ->assertSeeText($onCampus->request_no)
            ->assertSeeText($offCampus->request_no);
    }

    public function test_head_may_decide_an_on_campus_request_without_officer_verification(): void
    {
        $request = $this->submittedRequest(offCampus: false);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($this->classificationUser(AccessClassification::SpmuHead))
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSeeText($request->request_no);

        // The Head decision guard must not demand a verification step that
        // an on-campus request never has.
        $step = ApprovalStep::query()
            ->where('request_version_id', $request->currentVersion->id)
            ->where('sequence_no', 2)
            ->first();

        $this->assertNotNull($step);
        $this->assertSame('RECEIVED', $step->decision);
    }

    /**
     * @return list<int>
     */
    private function sequenceNumbers(BorrowingRequest $request): array
    {
        return ApprovalStep::query()
            ->where('request_version_id', $request->fresh()->currentVersion->id)
            ->where('stage_code', 'SPMU')
            ->orderBy('sequence_no')
            ->pluck('sequence_no')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function headQueueContains(BorrowingRequest $request): bool
    {
        return $this->queueContains($request, 2);
    }

    private function officerQueueContains(BorrowingRequest $request): bool
    {
        return $this->queueContains($request, 1);
    }

    /**
     * Mirrors ApprovalController::pendingRequestsForSequence().
     */
    private function queueContains(BorrowingRequest $request, int $sequence): bool
    {
        return BorrowingRequest::query()
            ->where('id', $request->id)
            ->where('status', RequestStatus::UnderSpmu)
            ->whereHas('currentVersion.approvalSteps', function ($step) use ($sequence): void {
                $step->where('stage_code', 'SPMU')
                    ->where('sequence_no', $sequence)
                    ->whereIn('decision', ['PENDING', 'RECEIVED']);
            })
            ->exists();
    }

    private function verifyAsActionOfficer(BorrowingRequest $request): void
    {
        $officer = $this->classificationUser(AccessClassification::SpmuOfficer);

        // Verification is also E-signed.
        $this->registerSignature($officer);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->post(route('verifications.verify', $request), [
                'decision' => 'VERIFIED',
                'details_complete' => 1,
                'documents_complete' => 1,
                'availability_verified' => 1,
                'confirm_e_signature' => 1,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Drive the real borrower submit path so the routing under test is the
     * one production uses.
     */
    private function submittedRequest(bool $offCampus): BorrowingRequest
    {
        $borrower = $this->classificationUser(AccessClassification::BorrowerOnly);

        $draft = $this->makeDraft($borrower, $offCampus);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.submit', $draft), [
                'borrower_acknowledgement' => 1,
                'confirm_e_signature' => 1,
            ])
            ->assertSessionHasNoErrors();

        $draft->refresh();

        $this->assertSame(RequestStatus::UnderSpmu, $draft->status);

        return $draft;
    }

    private function makeDraft(User $borrower, bool $offCampus): BorrowingRequest
    {
        // Reuse whatever draft-building helper the suite already relies on by
        // going through the same controller the borrower uses.
        $item = \App\Models\InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->when($offCampus, fn ($query) => $query->where('off_campus_allowed', true))
            ->firstOrFail();

        $request = BorrowingRequest::create([
            'request_no' => 'BR-PREMISES-'.uniqid(),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Premises routing test',
            'location' => 'CSPC Campus',
            'needed_from' => now()->addWeek()->setTime(9, 0),
            'return_due_at' => now()->addWeek()->addDays(2)->setTime(16, 0),
            'event_details' => 'Routing check',
            'off_campus' => $offCampus,
            'created_by_user_id' => $borrower->id,
        ]);

        $version->items()->create([
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 1,
            'approved_quantity' => null,
            'use_location' => $offCampus ? 'OFF_CAMPUS' : 'ON_CAMPUS',
        ]);

        $this->registerSignature($borrower);
        $this->attachApprovedRequestLetter($request, $version, $borrower);

        return $request->fresh();
    }

    /**
     * Submission E-signs the request certification, so the borrower needs a
     * currently-effective registered E-signature (no seeder provides one).
     */
    private function registerSignature(User $user): void
    {
        // One active signature per borrower; a second request must reuse it.
        if (UserSignature::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->exists()) {
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

        UserSignature::query()->firstOrCreate(
            ['user_id' => $user->id, 'status' => 'ACTIVE'],
            [
                'stored_file_id' => $file->id,
                'effective_from' => now()->subMinute(),
                'effective_to' => null,
            ]
        );
    }

    /**
     * The scanned approved Borrowing Request Letter is required at submission.
     */
    private function attachApprovedRequestLetter(
        BorrowingRequest $request,
        $version,
        User $borrower
    ): void {
        $bytes = '%PDF-1.4 premises-routing-test';

        $file = StoredFile::create([
            'uploaded_by_user_id' => $borrower->id,
            'disk' => 'local',
            'storage_path' => 'tests/request-supporting-documents/'
                .$request->id.'/signed-approved-request-letter.pdf',
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
            'is_current' => true,
        ]);
    }

    private function classificationUser(AccessClassification $classification): User
    {
        return User::query()
            ->where('access_classification', $classification->value)
            ->firstOrFail();
    }
}
