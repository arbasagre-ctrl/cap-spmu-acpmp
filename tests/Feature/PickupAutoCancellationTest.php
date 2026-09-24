<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\GatePass;
use App\Models\GeneratedDocument;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\OperationalWeeklySchedule;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\CustodyService;
use App\Services\RequestWorkflowService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Coverage for RequestWorkflowService::autoCancelUnclaimedMissedPickups() -
 * previously entirely untested despite being real, Terms-and-Conditions-
 * documented automatic behavior invoked from the spmu:process-deadlines
 * scheduler command - and for the PICKUP_EXPIRED notification created by
 * CustodyService::expirePickupWindows(), which previously only had structural
 * (source-string) coverage rather than a real notification/delivery record
 * assertion.
 *
 * All fixtures anchor to Monday 2026-09-21 09:00 with a forced Mon-Fri
 * 08:00-17:00 Pickup / Release window so the three trigger paths can be
 * computed deterministically against OperationalCalendarService's real
 * weekday/hours logic instead of guessed offsets.
 */
class PickupAutoCancellationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        OperationalWeeklySchedule::query()
            ->whereIn('weekday', [1, 2, 3, 4, 5])
            ->update([
                'is_open' => true,
                'allows_pickup' => true,
                'open_time' => '08:00',
                'close_time' => '17:00',
            ]);

        Carbon::setTestNow(Carbon::create(2026, 9, 21, 9));

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'PICKUPFX',
            'unit_name' => 'Pickup Auto-Cancel Test Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_valid_replacement_pickup_before_expected_return_date_cancels_immediately(): void
    {
        [$custody, , $request, $version] = $this->preparingReleaseCustody(
            'NOREPL',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 22, 23, 59, 59)
        );
        $this->attachGeneratedDocuments($custody, $version);

        $this->travelTo(Carbon::create(2026, 9, 21, 18));

        $cancelled = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();

        $this->assertSame(1, $cancelled);
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('CANCELLED', $custody->fresh()->status);
        $this->assertNotNull($custody->fresh()->closed_at);

        $this->assertAllocationFullyRestored($version);
        $this->assertGeneratedDocumentsInvalidated($version);
        $this->assertGatePassVoided($custody);

        $this->assertDatabaseHas('audit_events', [
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'action_code' => 'PICKUP_AUTO_CANCELLED_UNCLAIMED',
        ]);
        $event = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PICKUP_AUTO_CANCELLED_UNCLAIMED')
            ->firstOrFail();
        $this->assertStringContainsString('no valid replacement pickup remains', (string) $event->reason);

        $this->assertBorrowerCancellationNotified($request);
    }

    public function test_no_response_within_allowed_period_cancels_after_deadline(): void
    {
        [$custody, , $request, $version] = $this->preparingReleaseCustody(
            'NORESP',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 25, 23, 59, 59)
        );

        // Still within the response period (through Tuesday's close): must
        // NOT cancel yet.
        $this->travelTo(Carbon::create(2026, 9, 22, 16, 0));
        $cancelledEarly = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();
        $this->assertSame(0, $cancelledEarly);
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);

        // Past the response deadline (Tuesday 17:00), no reschedule request
        // was ever recorded.
        $this->travelTo(Carbon::create(2026, 9, 23, 9, 0));
        $cancelled = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();

        $this->assertSame(1, $cancelled);
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('CANCELLED', $custody->fresh()->status);
        $this->assertAllocationFullyRestored($version);

        $event = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PICKUP_AUTO_CANCELLED_UNCLAIMED')
            ->firstOrFail();
        $this->assertStringContainsString('no reschedule or cancellation response was received', (string) $event->reason);
    }

    public function test_a_pending_reschedule_request_protects_the_reservation_until_ao_acts(): void
    {
        [$custody, , $request] = $this->preparingReleaseCustody(
            'PENDINGREQ',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 25, 23, 59, 59)
        );

        NotificationEvent::query()->create([
            'event_code' => 'PICKUP_RESCHEDULE_REQUESTED',
            'source_type' => $custody->getMorphClass(),
            'source_id' => $custody->id,
            'payload_snapshot_json' => ['message' => 'Borrower requested a pickup reschedule.'],
            'occurred_at' => Carbon::create(2026, 9, 21, 18),
        ]);

        // Even well past the normal response deadline, the pending request
        // must keep the reservation intact until SPMU actually reschedules.
        $this->travelTo(Carbon::create(2026, 9, 24, 9, 0));
        $cancelled = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();

        $this->assertSame(0, $cancelled);
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertSame('PREPARING_RELEASE', $custody->fresh()->status);
    }

    public function test_a_missed_rescheduled_pickup_cancels_immediately_with_no_second_reschedule_cycle(): void
    {
        [$custody, , $request, $version] = $this->preparingReleaseCustody(
            'MISSEDRESCHED',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 25, 23, 59, 59)
        );

        NotificationEvent::query()->create([
            'event_code' => 'PICKUP_RESCHEDULE_REQUESTED',
            'source_type' => $custody->getMorphClass(),
            'source_id' => $custody->id,
            'payload_snapshot_json' => ['message' => 'Borrower requested a pickup reschedule.'],
            'occurred_at' => Carbon::create(2026, 9, 21, 18),
        ]);

        // SPMU actually reschedules to the next valid window (Tuesday).
        $custody->update([
            'scheduled_release_at' => Carbon::create(2026, 9, 22, 8),
            'pickup_expires_at' => Carbon::create(2026, 9, 22, 17),
            'pickup_expired_at' => null,
            'pickup_scheduled_at' => Carbon::create(2026, 9, 21, 19),
        ]);

        AuditEvent::query()->create([
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'actor_user_id' => null,
            'action_code' => 'PICKUP_RESCHEDULED',
            'reason' => null,
            'before_json' => [],
            'after_json' => ['source' => 'MISSED_PICKUP_NEXT_VALID_OPERATIONAL_WINDOW'],
            'occurred_at' => Carbon::create(2026, 9, 21, 19),
            'correlation_id' => (string) Str::uuid(),
        ]);

        // Borrower also misses the rescheduled window. Travel well past it.
        $this->travelTo(Carbon::create(2026, 9, 23, 9, 0));
        $cancelled = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();

        $this->assertSame(1, $cancelled);
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('CANCELLED', $custody->fresh()->status);
        $this->assertAllocationFullyRestored($version);

        $event = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('record_id', $custody->id)
            ->where('action_code', 'PICKUP_AUTO_CANCELLED_UNCLAIMED')
            ->firstOrFail();
        $this->assertStringContainsString('rescheduled pickup window', (string) $event->reason);

        // Exactly one PICKUP_RESCHEDULED audit event exists - no second
        // reschedule cycle was opened for the missed rescheduled pickup.
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('record_type', CustodyTransaction::class)
                ->where('record_id', $custody->id)
                ->where('action_code', 'PICKUP_RESCHEDULED')
                ->count()
        );
    }

    public function test_preparation_exception_pending_release_protects_the_reservation(): void
    {
        [$custody, $line, $request] = $this->preparingReleaseCustody(
            'PREPISSUE',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 22, 23, 59, 59)
        );

        AuditEvent::query()->create([
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'actor_user_id' => null,
            'action_code' => 'PREPARATION_ISSUE_REPORTED',
            'reason' => 'Reported inventory discrepancy.',
            'before_json' => [],
            'after_json' => ['custody_line_id' => $line->id],
            'occurred_at' => Carbon::create(2026, 9, 21, 10),
            'correlation_id' => (string) Str::uuid(),
        ]);

        $this->travelTo(Carbon::create(2026, 9, 24, 9, 0));
        $cancelled = app(RequestWorkflowService::class)->autoCancelUnclaimedMissedPickups();

        $this->assertSame(0, $cancelled);
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertSame('PREPARING_RELEASE', $custody->fresh()->status);
    }

    public function test_pickup_expired_notification_offers_reschedule_when_a_valid_window_remains(): void
    {
        [$custody, , , , $borrower] = $this->preparingReleaseCustody(
            'EXPNOTIFY1',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 25, 23, 59, 59)
        );

        $this->travelTo(Carbon::create(2026, 9, 21, 18));

        $expired = app(CustodyService::class)->expirePickupWindows();

        $this->assertSame(1, $expired);
        $this->assertNotNull($custody->fresh()->pickup_expired_at);

        $this->assertDatabaseHas('audit_events', [
            'record_type' => CustodyTransaction::class,
            'record_id' => $custody->id,
            'action_code' => 'PICKUP_WINDOW_EXPIRED',
        ]);

        $event = NotificationEvent::query()
            ->where('event_code', 'PICKUP_EXPIRED')
            ->where('source_type', $custody->getMorphClass())
            ->where('source_id', $custody->id)
            ->firstOrFail();

        $this->assertStringContainsString('Request Reschedule', (string) $event->payload_snapshot_json['message']);
        $this->assertStringContainsString('Cancel Request', (string) $event->payload_snapshot_json['message']);

        $deliveries = NotificationDelivery::query()
            ->where('notification_event_id', $event->id)
            ->where('recipient_user_id', $borrower->id)
            ->get();

        $channels = $deliveries->pluck('channel')->unique()->sort()->values()->all();
        $this->assertSame(['EMAIL', 'SYSTEM'], $channels);
        $this->assertFalse($deliveries->pluck('channel')->contains('SMS'));
    }

    public function test_pickup_expired_notification_states_no_replacement_remains_when_due_date_is_imminent(): void
    {
        [$custody] = $this->preparingReleaseCustody(
            'EXPNOTIFY2',
            Carbon::create(2026, 9, 21, 8),
            Carbon::create(2026, 9, 21, 17),
            Carbon::create(2026, 9, 22, 23, 59, 59)
        );

        $this->travelTo(Carbon::create(2026, 9, 21, 18));

        app(CustodyService::class)->expirePickupWindows();

        $event = NotificationEvent::query()
            ->where('event_code', 'PICKUP_EXPIRED')
            ->where('source_type', $custody->getMorphClass())
            ->where('source_id', $custody->id)
            ->firstOrFail();

        $this->assertStringContainsString('no valid replacement pickup remains', (string) $event->payload_snapshot_json['message']);
    }

    /**
     * @return array{0:CustodyTransaction,1:CustodyLine,2:BorrowingRequest,3:RequestVersion,4:User}
     */
    private function preparingReleaseCustody(
        string $suffix,
        Carbon $pickupStart,
        Carbon $pickupEnd,
        Carbon $dueAt
    ): array {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'PICKUPFX'],
            ['category_name' => 'Pickup Auto-Cancel Fixture', 'active' => true]
        );
        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );
        $item = InventoryItem::query()->firstOrCreate(
            ['unique_description' => 'Pickup Fixture Item '.$suffix],
            [
                'category_id' => $category->id,
                'unit_id' => $measure->id,
                'total_quantity' => 10,
                'condition_code' => 'SERVICEABLE',
                'borrowable' => true,
                'off_campus_allowed' => false,
                'laundry_required' => false,
                'provisional' => false,
                'active' => true,
            ]
        );

        $borrower = User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-PICKUP-'.$suffix,
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Pickup auto-cancel fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'Test Office',
            'schedule_date' => $pickupStart->toDateString(),
            'return_date' => $dueAt->toDateString(),
            'needed_from' => $pickupStart->copy(),
            'return_due_at' => $dueAt->copy(),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 1,
            'approved_quantity' => 1,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $pickupStart->copy(),
            'period_end' => $dueAt->copy(),
            'allocated_quantity' => 1,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => now(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-PICKUP-'.$suffix,
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'PREPARING_RELEASE',
            'scheduled_release_at' => $pickupStart,
            'pickup_expires_at' => $pickupEnd,
            'pickup_expired_at' => null,
            'pickup_scheduled_at' => $pickupStart,
            'due_at' => $dueAt,
            'original_due_at' => $dueAt,
        ]);

        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 0,
            'returned_quantity' => 0,
        ]);

        return [$custody->fresh(), $line, $request, $version, $borrower];
    }

    private function attachGeneratedDocuments(CustodyTransaction $custody, RequestVersion $version): void
    {
        GeneratedDocument::query()->create([
            'request_version_id' => $version->id,
            'subject_type' => CustodyTransaction::class,
            'subject_id' => $custody->id,
            'document_no' => 'DOC-'.$custody->custody_no,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'FINAL',
            'generated_at' => now(),
        ]);

        GatePass::query()->create([
            'custody_transaction_id' => $custody->id,
            'custody_line_id' => $custody->lines()->firstOrFail()->id,
            'bearer_name' => $custody->borrower->full_name ?? 'Test Borrower',
            'destination' => 'Off-campus venue',
            'purpose' => 'Fixture gate pass',
            'status' => 'PENDING',
        ]);
    }

    private function assertAllocationFullyRestored(RequestVersion $version): void
    {
        $requestItem = RequestItem::query()->where('request_version_id', $version->id)->firstOrFail();
        $allocation = Allocation::query()->where('request_item_id', $requestItem->id)->firstOrFail();

        $this->assertSame('CANCELLED', $allocation->status);
        $this->assertEquals((float) $allocation->allocated_quantity, (float) $allocation->restored_quantity);
    }

    private function assertGeneratedDocumentsInvalidated(RequestVersion $version): void
    {
        $this->assertDatabaseHas('generated_documents', [
            'request_version_id' => $version->id,
            'document_type' => 'BORROWER_SLIP',
            'status' => 'INVALIDATED',
        ]);
    }

    private function assertGatePassVoided(CustodyTransaction $custody): void
    {
        $this->assertDatabaseHas('gate_passes', [
            'custody_transaction_id' => $custody->id,
            'status' => 'VOID',
        ]);
    }

    private function assertBorrowerCancellationNotified(BorrowingRequest $request): void
    {
        $event = NotificationEvent::query()
            ->where('event_code', 'REQUEST_CANCELLED')
            ->where('source_type', $request->getMorphClass())
            ->where('source_id', $request->id)
            ->exists();

        $this->assertTrue($event, 'The borrower must receive a REQUEST_CANCELLED notification.');
    }
}
