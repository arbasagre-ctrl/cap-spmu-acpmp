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
use App\Models\NotificationEvent;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\CustodyService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    /**
     * SCENARIO A: normal on-campus, non-linen request, all the way from
     * approval through a normal return to a final CLOSED state, including
     * the borrower-facing TRANSACTION_CLOSED notification.
     */
    public function test_normal_on_campus_non_linen_return_reaches_closed_with_completion_notice(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(6, 'BR-SCENARIO-A-001', 'Round Table');

        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare($custody, $spmuOfficer, $version);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical handover completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertSame('ACTIVE', $custody->fresh()->status);

        $line = $custody->lines->firstOrFail();

        /*
         * Return inspection is only permitted on or after the Expected
         * Return Date.
         */
        $this->travelTo($custody->fresh()->due_at);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 6],
                    'conditions' => [$line->id => 'FINE'],
                    'remarks' => 'All items returned serviceable.',
                ]
            )
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $this->assertSame('CLOSED', $custody->status);
        $this->assertNotNull($custody->closed_at);

        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'return_type' => 'NORMAL',
        ]);

        $this->assertSame(1, NotificationEvent::query()
            ->where('event_code', 'TRANSACTION_CLOSED')
            ->where('source_type', CustodyTransaction::class)
            ->where('source_id', $custody->id)
            ->count());
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

    /**
     * SCENARIO B: borrower requests an Early Return before the approved due
     * date, SPMU is notified, and the original approved due date is never
     * mutated by the request itself. When the property is then physically
     * returned early, the return is recorded as an Early
     * Return, and the original due date remains historical truth.
     */
    public function test_early_return_request_and_physical_early_return_record_correctly(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(4, 'BR-SCENARIO-B-001', 'Round Table');

        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare($custody, $spmuOfficer, $version);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical handover completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $originalDueAt = $custody->due_at;
        $this->assertNotNull($originalDueAt);

        $line = $custody->lines->firstOrFail();
        $proposedReturnAt = now()->addHours(2);

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.show', $custody))
            ->assertOk()
            ->assertSeeText('Currently On Custody')
            ->assertDontSeeText('Quantity to Hand Over');

        $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(
                route('custody.early-return', $custody),
                [
                    'proposed_return_at' => $proposedReturnAt->format('Y-m-d H:i:s'),
                    'reason' => 'Event ended earlier than planned.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('notification_events', [
            'event_code' => 'EARLY_RETURN_REQUESTED',
        ]);
        $this->assertDatabaseHas('early_return_requests', [
            'custody_transaction_id' => $custody->id,
            'status' => 'REQUESTED',
            'reason' => 'Event ended earlier than planned.',
        ]);
        $this->assertDatabaseCount('early_return_request_lines', 0);

        /*
         * A mere Early Return request must NOT rewrite the original
         * approved return date.
         */
        $custody->refresh();
        $this->assertTrue($custody->due_at->equalTo($originalDueAt));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 4],
                    'conditions' => [$line->id => 'FINE'],
                    'remarks' => 'Early physical return inspected.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'return_type' => 'EARLY',
        ]);
        $this->assertDatabaseHas('early_return_requests', [
            'custody_transaction_id' => $custody->id,
            'status' => 'COMPLETED',
        ]);

        /*
         * The original approved due date remains intact even after the
         * actual early return is recorded.
         */
        $this->assertTrue($custody->fresh()->due_at->equalTo($originalDueAt));
    }

    /**
     * An Early Return Request is optional borrower/SPMU coordination only --
     * it must never be a prerequisite for accepting or classifying an actual
     * early physical return. With NO Early Return Request ever filed, a
     * borrower who physically returns items before the Expected Return Date
     * must be accepted and auto-classified EARLY based on the calendar date
     * alone, and the due date must remain untouched.
     */
    public function test_early_physical_return_succeeds_and_classifies_early_with_no_early_return_request_on_file(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(2, 'BR-NO-ERR-001', 'Round Table');

        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare($custody, $spmuOfficer, $version);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.release', $custody),
                [
                    'physical_signatures_confirmed' => '1',
                    'remarks' => 'Physical handover completed.',
                ]
            )
            ->assertSessionHasNoErrors();

        $custody->refresh();
        $originalDueAt = $custody->due_at;
        $this->assertNotNull($originalDueAt);
        $this->assertTrue(now()->lt($originalDueAt), 'Test assumes the due date is still in the future.');

        $this->assertDatabaseMissing('early_return_requests', [
            'custody_transaction_id' => $custody->id,
        ]);

        $line = $custody->lines->firstOrFail();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 2],
                    'conditions' => [$line->id => 'FINE'],
                    'remarks' => 'Early physical return with no prior notice.',
                ]
            )
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'return_type' => 'EARLY',
        ]);

        $this->assertTrue($custody->fresh()->due_at->equalTo($originalDueAt));
    }

    /**
     * SCENARIO J: a double-click / duplicate POST on release or return must
     * never issue or return the same property twice. This exercises the
     * CustodyService guards directly (bypassing the HTTP-level
     * PreventDuplicateSubmission middleware) to prove the deeper,
     * service-level idempotency itself -- not just that the middleware
     * blocks an identical second HTTP request.
     */
    public function test_duplicate_release_and_return_calls_do_not_duplicate_side_effects(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(3, 'BR-SCENARIO-J-001', 'Round Table');

        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $this->schedulePickupAndPrepare($custody, $spmuOfficer, $version);

        /*
         * schedulePickupAndPrepare() acts over HTTP, which does not update
         * this PHP object -- refresh once so the first release() call sees
         * the real (scheduled, prepared) state. From here on, both release
         * calls deliberately reuse this SAME instance without refreshing
         * again, exactly like a double-click at the service layer: both
         * calls pass the outer status guard, but only the first should
         * have any effect.
         */
        $custody = $custody->fresh(['lines']);

        $service = app(CustodyService::class);

        $service->release($custody, $spmuOfficer, 'First release attempt.');
        $service->release($custody, $spmuOfficer, 'Second release attempt.');

        $this->assertSame('ACTIVE', $custody->fresh()->status);

        $this->assertSame(1, DB::table('inventory_transaction_lines')
            ->where('to_state', 'BORROWED')
            ->where('quantity', 3)
            ->count());

        $line = $custody->fresh()->lines->firstOrFail();

        /*
         * Return inspection is only permitted on or after the Expected
         * Return Date.
         */
        $this->travelTo($custody->fresh()->due_at);

        $service->receiveReturn(
            $custody->fresh(),
            $spmuOfficer,
            [$line->id => 3],
            [$line->id => 'FINE'],
            'First return attempt.'
        );

        $this->assertSame(1, DB::table('return_transactions')
            ->where('custody_transaction_id', $custody->id)
            ->count());

        /*
         * A second identical return attempt has nothing left outstanding
         * to account for, so it must fail cleanly rather than duplicate a
         * ReturnTransaction or double-count a return.
         */
        $this->expectException(ValidationException::class);

        try {
            $service->receiveReturn(
                $custody->fresh(),
                $spmuOfficer,
                [$line->id => 3],
                [$line->id => 'FINE'],
                'Second return attempt.'
            );
        } finally {
            $this->assertSame(1, DB::table('return_transactions')
                ->where('custody_transaction_id', $custody->id)
                ->count());
        }
    }

    /**
     * SCENARIO K: two requests for the same item with overlapping borrowing
     * periods, where the combined requested quantity exceeds stock. The
     * first approval succeeds and reserves its full quantity; the second
     * approval must fail rather than overbook the item, and must leave the
     * first request's allocation untouched.
     */
    public function test_overlapping_requests_exceeding_stock_do_not_overbook(): void
    {
        $item = InventoryItem::where('unique_description', 'Round Table')->firstOrFail();
        $stock = (float) $item->total_quantity;
        $this->assertGreaterThan(40, $stock, 'Test assumes at least 41 units of Round Table are seeded.');

        [$borrower, $spmu, $spmuOfficer, $firstRequest, $firstVersion] =
            $this->approvedRequest(40, 'BR-SCENARIO-K-001', 'Round Table');

        $this->assertDatabaseHas('allocations', [
            'request_item_id' => RequestItem::where('request_version_id', $firstVersion->id)->firstOrFail()->id,
            'allocated_quantity' => 40,
            'status' => 'ACTIVE',
        ]);

        /*
         * Second request for the same item, same borrower, same
         * (default) schedule/return window -- i.e. a fully overlapping
         * period -- for a quantity that, combined with the first
         * request's 40, exceeds the seeded stock.
         */
        $secondRequest = BorrowingRequest::create([
            'request_no' => 'BR-SCENARIO-K-002',
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::Draft,
        ]);

        $scheduleDate = now()->addDays(2)->startOfDay();
        $returnDate = now()->addDays(3)->startOfDay();

        $secondVersion = $secondRequest->versions()->create([
            'version_no' => 1,
            'purpose_event' => 'Institutional activity',
            'location' => 'CSPC Campus',
            'schedule_date' => $scheduleDate->toDateString(),
            'return_date' => $returnDate->toDateString(),
            'needed_from' => $scheduleDate->copy()->startOfDay(),
            'return_due_at' => $returnDate->copy()->endOfDay(),
            'represents_student_activity' => false,
            'event_details' => 'Overlapping second request for overbooking test.',
            'off_campus' => false,
            'created_by_user_id' => $borrower->id,
        ]);

        RequestItem::create([
            'request_version_id' => $secondVersion->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => $item->unit->unit_name,
            'requested_quantity' => $stock - 40 + 5,
            'use_location' => 'ON_CAMPUS',
        ]);

        $this->attachApprovedRequestLetter($secondRequest, $secondVersion, $borrower);

        $this->actingAs($borrower)
            ->post(route('requests.submit', $secondRequest), [
                'borrower_acknowledgement' => '1',
                'confirm_e_signature' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmu)
            ->post(route('approvals.decide', $secondRequest), [
                'decision' => 'APPROVED',
                'details_complete' => '1',
                'documents_complete' => '1',
                'availability_verified' => '1',
                'confirm_e_signature' => '1',
            ]);

        /*
         * The second request must NOT reach ApprovedReadyForRelease -- it
         * must not overbook the item.
         */
        $this->assertNotSame(
            RequestStatus::ApprovedReadyForRelease,
            $secondRequest->fresh()->status
        );

        /*
         * The first request's allocation is untouched: still 40, still
         * ACTIVE, never silently reduced to make room for the second.
         */
        $this->assertDatabaseHas('allocations', [
            'request_item_id' => RequestItem::where('request_version_id', $firstVersion->id)->firstOrFail()->id,
            'allocated_quantity' => 40,
            'released_quantity' => 0,
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseMissing('allocations', [
            'request_item_id' => RequestItem::where('request_version_id', $secondVersion->id)->firstOrFail()->id,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * SCENARIO L: physical release is blocked once the pickup window has
     * expired; SPMU must reschedule before release can succeed again.
     */
    public function test_release_is_blocked_after_pickup_window_expiry_until_rescheduled(): void
    {
        [$borrower, $spmu, $spmuOfficer, $request, $version] =
            $this->approvedRequest(2, 'BR-SCENARIO-L-001', 'Round Table');

        $custody = CustodyTransaction::query()
            ->where('request_id', $request->id)
            ->with('lines')
            ->firstOrFail();

        $pickupAt = $version->schedule_date->copy()->setTime(9, 0, 0);
        $pickupExpiresAt = $pickupAt->copy()->addHours(1);

        $this->travelTo($pickupAt->copy()->subHour());

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.schedule-pickup', $custody), [
                'pickup_at' => $pickupAt->format('Y-m-d H:i:s'),
                'pickup_expires_at' => $pickupExpiresAt->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasNoErrors();

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.prepare', $custody), [
                'quantities' => $custody->lines
                    ->mapWithKeys(fn ($line) => [$line->id => (int) $line->approved_quantity])
                    ->all(),
            ])
            ->assertSessionHasNoErrors();

        /*
         * Travel past the original pickup window without releasing.
         */
        $this->travelTo($pickupExpiresAt->copy()->addMinutes(5));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Attempted release after expiry.',
            ])
            ->assertSessionHasErrors();

        $this->assertNull($custody->fresh()->released_at);

        /*
         * Reschedule to a new, later window on the same calendar day
         * (pickup must stay on the approved Schedule Date) -- the
         * transaction is still PREPARING_RELEASE, so rescheduling is
         * allowed.
         */
        $newPickupAt = $pickupAt->copy()->setTime(14, 0, 0);
        $newPickupExpiresAt = $newPickupAt->copy()->addHours(2);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.schedule-pickup', $custody), [
                'pickup_at' => $newPickupAt->format('Y-m-d H:i:s'),
                'pickup_expires_at' => $newPickupExpiresAt->format('Y-m-d H:i:s'),
            ])
            ->assertSessionHasNoErrors();

        /*
         * Inside the new window, release now succeeds.
         */
        $this->travelTo($newPickupAt->copy()->addMinutes(30));

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(route('custody.release', $custody), [
                'physical_signatures_confirmed' => '1',
                'remarks' => 'Release inside the rescheduled window.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('ACTIVE', $custody->fresh()->status);
        $this->assertNotNull($custody->fresh()->released_at);
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
            /*
             * synchronizeCustodyDueDate() re-derives the effective due date
             * from original_due_at (set at release time) every time the
             * deadline processor runs, so it must move together with the
             * override above or it will recompute due_at back to the
             * originally approved return date and this custody will never
             * appear overdue.
             */
            'original_due_at' => now()
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

        /*
         * The physical return happened one calendar day after the
         * effective due date, so the recorded return type -- and the
         * label the final Borrower's Slip will render -- must be
         * OVERDUE, distinct from a Normal or Early return.
         */
        $this->assertDatabaseHas('return_transactions', [
            'custody_transaction_id' => $custody->id,
            'return_type' => 'OVERDUE',
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

        /*
         * Return inspection is only permitted on or after the Expected
         * Return Date.
         */
        $this->travelTo($custody->fresh()->due_at);

        $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($spmuOfficer)
            ->post(
                route('custody.return', $custody),
                [
                    'quantities' => [$line->id => 2],
                    'conditions' => [$line->id => 'STOLEN'],
                    // Evidence alone is not enough for a stolen quantity;
                    // it is supplied here so the missing police-blotter
                    // reference is the only remaining, isolated failure.
                    'evidence_files' => [
                        $line->id => UploadedFile::fake()->create(
                            'incident.pdf',
                            10,
                            'application/pdf'
                        ),
                    ],
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

        /*
         * The SPMU Head must first record the formal accountability
         * decision (Billing / Payment Required) before a Billing Statement
         * can be generated for this incident.
         */
        $this->actingAs($spmu)
            ->post(
                route(
                    'incidents.resolve',
                    $incident
                ),
                [
                    'resolution_outcome' => 'BILLING_REQUIRED',
                    'resolution_remarks' => 'Stolen property requires borrower billing.',
                ]
            )
            ->assertSessionHasNoErrors();

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
        /*
         * Request submission is only permitted on an open SPMU operational
         * day; anchor the clock so this helper does not depend on which
         * weekday the test suite happens to run.
         */
        $this->travelTo(
            app(\App\Services\OperationalCalendarService::class)
                ->nextOpenDate(\App\Services\OperationalCalendarService::REQUEST, now(), true)
                ->setTime(9, 0)
        );

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

        /*
         * Approval, release, and return inspection each apply the acting
         * SPMU user's own registered E-signature, so both roles need one
         * on file (there is no seeder that registers one by default).
         */
        $this->registerSignature($spmu);
        $this->registerSignature($spmuOfficer);

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

        $this->registerSignature($borrower);

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
                    'confirm_e_signature' => '1',
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

                    'documents_complete' =>
                        '1',

                    'availability_verified' =>
                        '1',

                    'confirm_e_signature' =>
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
     * Submission E-signs the request certification, which requires the
     * submitting user to have a currently-effective registered E-signature
     * on file (there is no seeder that registers one by default).
     */
    private function registerSignature(User $user): void
    {
        $bytes = "\x89PNG\r\n\x1a\n".'signature-ink-'.$user->id;
        $path = 'tests/signatures/'.$user->id.'/signature.png';

        // SignatureService::snapshot() reads these bytes back from disk, so
        // the StoredFile row must point at an actual, readable file.
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

        $preparedQuantities = $custody->lines
            ->mapWithKeys(fn ($line) => [$line->id => (int) $line->approved_quantity])
            ->all();

        $this->withSession([
            'active_workspace' => 'SPMU',
        ])
            ->actingAs($spmuOfficer)
            ->post(
                route(
                    'custody.prepare',
                    $custody
                ),
                [
                    'quantities' => $preparedQuantities,
                ]
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
