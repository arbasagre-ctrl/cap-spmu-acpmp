<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
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
 * Coverage for the borrower-initiated Cancel Request workflow
 * (RequestWorkflowService::cancel()), previously without any regression
 * coverage despite being a real, Terms-and-Conditions-documented borrower
 * action. Cancellation finalizes in a single step - there is no separate
 * SPMU confirmation stage (the older two-step reviewCancellation() /
 * requests.cancellation.review path was removed as unreachable dead code;
 * see the cancellation-workflow dead-code audit).
 */
class RequestCancellationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Deterministic Pickup / Release hours regardless of which weekday
        // the suite happens to run on, matching CompleteWorkflowTest.
        OperationalWeeklySchedule::query()
            ->where('is_open', true)
            ->update([
                'open_time' => '13:00',
                'close_time' => '16:00',
            ]);
    }

    public function test_borrower_can_cancel_an_approved_unreleased_request_and_reservation_is_restored(): void
    {
        [$borrower, , , $request, $version] = $this->approvedUnreleasedRequest('BR-CANCEL-001');

        $custody = CustodyTransaction::query()->where('request_id', $request->id)->firstOrFail();
        $requestItem = RequestItem::query()->where('request_version_id', $version->id)->firstOrFail();

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('requests.cancel', $request), [
                'reason' => 'No longer needed for the activity.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);

        $this->assertDatabaseHas('allocations', [
            'request_item_id' => $requestItem->id,
            'status' => 'CANCELLED',
        ]);
        $allocation = Allocation::query()->where('request_item_id', $requestItem->id)->firstOrFail();
        $this->assertEquals((float) $allocation->allocated_quantity, (float) $allocation->restored_quantity);

        $this->assertSame('CANCELLED', $custody->fresh()->status);
        $this->assertNotNull($custody->fresh()->closed_at);

        $this->assertDatabaseHas('request_cancellations', [
            'request_id' => $request->id,
            'status' => 'CONFIRMED',
            'phase' => 'AFTER_APPROVAL_BEFORE_RELEASE',
            'cancelled_by_user_id' => $borrower->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'record_type' => BorrowingRequest::class,
            'record_id' => $request->id,
            'action_code' => 'REQUEST_CANCELLED',
        ]);

        $borrowerEvent = NotificationEvent::query()
            ->where('event_code', 'REQUEST_CANCELLED')
            ->where('source_type', $request->getMorphClass())
            ->where('source_id', $request->id)
            ->first();
        $this->assertNotNull($borrowerEvent, 'The borrower must receive a REQUEST_CANCELLED notification event.');
        $this->assertTrue(
            NotificationDelivery::query()
                ->where('notification_event_id', $borrowerEvent->id)
                ->where('recipient_user_id', $borrower->id)
                ->exists()
        );

        // A borrower-initiated cancellation notifies Action Officers that no
        // further release action is required (RequestWorkflowService::
        // finalizeCancellation()).
        $officer = User::where('access_classification', AccessClassification::SpmuOfficer->value)->firstOrFail();
        $officerNotified = NotificationEvent::query()
            ->where('event_code', 'REQUEST_CANCELLED')
            ->where('source_type', $request->getMorphClass())
            ->where('source_id', $request->id)
            ->whereHas('deliveries', fn ($q) => $q->where('recipient_user_id', $officer->id))
            ->exists();
        $this->assertTrue($officerNotified, 'Action Officers must be notified that no release action is required.');
    }

    public function test_cancellation_is_blocked_once_items_have_been_physically_released(): void
    {
        [$borrower, , $spmuOfficer, $request, $version] = $this->approvedUnreleasedRequest('BR-CANCEL-002');

        $custody = CustodyTransaction::query()->where('request_id', $request->id)->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.schedule-pickup', $custody))
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.prepare', $custody), [
                'quantities' => $custody->lines->mapWithKeys(
                    fn ($line) => [$line->id => (int) $line->approved_quantity]
                )->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->travelTo($custody->fresh()->scheduled_release_at->copy()->addMinutes(15));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Physical issuance completed.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($custody->fresh()->released_at);

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('requests.cancel', $request), [
                'reason' => 'Trying to cancel after release.',
            ])
            ->assertSessionHasErrors('cancel');

        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertSame('ACTIVE', $custody->fresh()->status);
    }

    public function test_borrower_cannot_cancel_an_already_cancelled_request(): void
    {
        [$borrower, , , $request] = $this->approvedUnreleasedRequest('BR-CANCEL-003');

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('requests.cancel', $request), ['reason' => 'First cancellation.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);

        $this->actingAs($borrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('requests.cancel', $request), ['reason' => 'Second attempt.'])
            ->assertSessionHasErrors('cancel');

        $this->assertDatabaseCount('request_cancellations', 1);
    }

    public function test_borrower_cannot_cancel_another_borrowers_request(): void
    {
        [, , , $request] = $this->approvedUnreleasedRequest('BR-CANCEL-004');

        $otherBorrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
        ]);

        $this->actingAs($otherBorrower)
            ->withSession(['active_workspace' => 'BORROWER'])
            ->post(route('requests.cancel', $request), ['reason' => 'Not mine.'])
            ->assertForbidden();

        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
    }

    public function test_spmu_head_can_cancel_an_approved_unreleased_request(): void
    {
        [, $spmu, , $request] = $this->approvedUnreleasedRequest('BR-CANCEL-005');

        $this->actingAs($spmu)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('requests.cancel', $request), ['reason' => 'SPMU-initiated cancellation.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);

        $this->assertDatabaseHas('request_cancellations', [
            'request_id' => $request->id,
            'status' => 'CONFIRMED',
            'reviewed_by_user_id' => $spmu->id,
        ]);
    }

    public function test_spmu_officer_without_delegation_cannot_cancel_from_the_spmu_workspace(): void
    {
        [, , $spmuOfficer, $request] = $this->approvedUnreleasedRequest('BR-CANCEL-006');

        $this->actingAs($spmuOfficer)
            ->withSession(['active_workspace' => 'SPMU'])
            ->post(route('requests.cancel', $request), ['reason' => 'Officer attempt.'])
            ->assertForbidden();

        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
    }

    /**
     * @return array{0:User,1:User,2:User,3:BorrowingRequest,4:mixed}
     */
    private function approvedUnreleasedRequest(string $requestNo): array
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

        $scheduleDate = now()->addDays(2)->startOfDay();
        $returnDate = now()->addDays(5)->startOfDay();

        $request = BorrowingRequest::create([
            'request_no' => $requestNo,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $version = $request->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Cancellation workflow fixture',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate->copy(),
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'represents_student_activity' => false,
            'event_details' => 'Cancellation workflow test.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        RequestItem::create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => 2,
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
        $bytes = '%PDF-1.4 cancellation-workflow-test';

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
