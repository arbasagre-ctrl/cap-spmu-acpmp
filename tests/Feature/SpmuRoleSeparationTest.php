<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryItem;
use App\Models\RequestItem;
use App\Models\User;
use App\Services\OperationalCalendarService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpmuRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            DatabaseSeeder::class
        );
    }

    public function test_spmu_head_sees_custody_as_read_only_oversight(): void
    {
        $custody =
            $this->preparingCustody();

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->get(
                route(
                    'custody.show',
                    $custody
                )
            )
            ->assertOk()
            ->assertSee(
                'Release & Return Oversight'
            )
            ->assertDontSee(
                'Save Pickup Schedule'
            )
            ->assertDontSee(
                'Confirm Preparation & Generate Physical Forms'
            );
    }

    public function test_spmu_action_officer_sees_operational_pickup_controls(): void
    {
        $custody =
            $this->preparingCustody();

        $officer =
            $this->classificationUser(
                AccessClassification::SpmuOfficer
            );

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $officer
            )
            ->get(
                route(
                    'custody.show',
                    $custody
                )
            )
            ->assertOk()
            ->assertSee(
                'Pickup & Issuance Schedule'
            )
            ->assertSeeText(
                'Scheduled automatically from the SPMU Operational Calendar.'
            )
            ->assertSeeText('Confirm Preparation')
            ->assertDontSee(
                'Release & Return Oversight'
            )
            ->assertDontSee(
                'Save Pickup Schedule'
            );
    }

    public function test_spmu_head_cannot_post_operational_pickup_schedule(): void
    {
        $custody =
            $this->preparingCustody();

        $head =
            $this->classificationUser(
                AccessClassification::SpmuHead
            );

        $pickup = $custody->scheduled_release_at;
        $pickupExpiresAt = $custody->pickup_expires_at;

        $this->assertNotNull($pickup);
        $this->assertNotNull($pickupExpiresAt);

        $this
            ->withSession([
                'active_workspace' =>
                    'SPMU',
            ])
            ->actingAs(
                $head
            )
            ->post(
                route(
                    'custody.schedule-pickup',
                    $custody
                )
            )
            ->assertForbidden();

        $fresh = $custody->fresh();

        $this->assertTrue($pickup->equalTo($fresh->scheduled_release_at));
        $this->assertTrue($pickupExpiresAt->equalTo($fresh->pickup_expires_at));
        $this->assertNull($fresh->pickup_scheduled_by_user_id);
    }

    public function test_spmu_action_officer_can_confirm_without_replacing_the_system_generated_pickup_schedule(): void
    {
        $custody =
            $this->preparingCustody();

        $officer =
            $this->classificationUser(
                AccessClassification::SpmuOfficer
            );

        $pickup = $custody->scheduled_release_at;
        $pickupExpiresAt = $custody->pickup_expires_at;

        $this->assertNotNull($pickup);
        $this->assertNotNull($pickupExpiresAt);

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
                    'custody.schedule-pickup',
                    $custody
                )
            )
            ->assertSessionHasNoErrors();

        $fresh = $custody->fresh();

        $this->assertTrue($pickup->equalTo($fresh->scheduled_release_at));
        $this->assertTrue($pickupExpiresAt->equalTo($fresh->pickup_expires_at));
        $this->assertNotNull($fresh->pickup_scheduled_at);
        $this->assertNull($fresh->pickup_scheduled_by_user_id);
    }

    private function preparingCustody(): CustodyTransaction
    {
        $borrower =
            $this->classificationUser(
                AccessClassification::BorrowerOnly
            );

        $item =
            InventoryItem::query()
                ->with(
                    'unit'
                )
                ->where(
                    'active',
                    true
                )
                ->where(
                    'borrowable',
                    true
                )
                ->firstOrFail();

        $calendar = app(OperationalCalendarService::class);

        $scheduleDate = now()->addWeek()->startOfDay();
        $pickupWindow = $calendar->automaticPickupWindowBefore($scheduleDate, now());

        $this->assertNotNull(
            $pickupWindow,
            'The seeded Operational Calendar must provide the system-generated pickup window used by an approved request.'
        );

        $returnDate =
            now()
                ->addDays(
                    3
                )
                ->startOfDay();

        $request =
            BorrowingRequest::query()
                ->create([
                    'request_no' =>
                        'BR-SPMU-ROLE-'
                        .uniqid(),

                    'borrower_user_id' =>
                        $borrower->id,

                    'accountable_unit_id' =>
                        $borrower
                            ->organizational_unit_id,

                    'current_version_no' =>
                        1,

                    'status' =>
                        RequestStatus::ApprovedReadyForRelease,
                ]);

        $version =
            $request
                ->versions()
                ->create([
                    'version_no' =>
                        1,

                    'purpose_event' =>
                        'SPMU role separation test',

                    'event_details' =>
                        'Current workflow operational custody authorization.',

                    'location' =>
                        'CSPC Campus',

                    'schedule_date' =>
                        $scheduleDate
                            ->toDateString(),

                    'return_date' =>
                        $returnDate
                            ->toDateString(),

                    'needed_from' =>
                        $scheduleDate,

                    'return_due_at' =>
                        $returnDate
                            ->copy()
                            ->endOfDay(),

                    'off_campus' =>
                        false,

                    'created_by_user_id' =>
                        $borrower->id,
                ]);

        $requestItem =
            RequestItem::query()
                ->create([
                    'request_version_id' =>
                        $version->id,

                    'inventory_item_id' =>
                        $item->id,

                    'description_snapshot' =>
                        $item
                            ->unique_description,

                    'unit_snapshot' =>
                        $item
                            ->unit
                            ->unit_name,

                    'requested_quantity' =>
                        1,

                    'approved_quantity' =>
                        1,

                    'use_location' =>
                        'ON_CAMPUS',
                ]);

        $allocation =
            Allocation::query()
                ->create([
                    'request_item_id' =>
                        $requestItem->id,

                    'period_start' =>
                        $version
                            ->needed_from,

                    'period_end' =>
                        $version
                            ->return_due_at,

                    'allocated_quantity' =>
                        1,

                    'released_quantity' =>
                        0,

                    'restored_quantity' =>
                        0,

                    'status' =>
                        'ACTIVE',

                    'allocated_at' =>
                        now(),
                ]);

        $custody =
            CustodyTransaction::query()
                ->create([
                    'custody_no' =>
                        'CUS-SPMU-ROLE-'
                        .uniqid(),

                    'request_id' =>
                        $request->id,

                    'request_version_id' =>
                        $version->id,

                    'borrower_user_id' =>
                        $borrower->id,

                    'status' =>
                        'PREPARING_RELEASE',

                    /*
                     * RequestWorkflowService approval creates this window
                     * from OperationalCalendarService before a custody
                     * record reaches the Release workspace. Reproduce the
                     * current generated state instead of letting this raw
                     * fixture invent a staff-selected pickup date.
                     */
                    'scheduled_release_at' => $pickupWindow['start'],
                    'pickup_expires_at' => $pickupWindow['end'],
                    'pickup_scheduled_at' => now(),

                    'due_at' =>
                        $returnDate
                            ->copy()
                            ->endOfDay(),
                ]);

        CustodyLine::query()
            ->create([
                'custody_transaction_id' =>
                    $custody->id,

                'request_item_id' =>
                    $requestItem->id,

                'allocation_id' =>
                    $allocation->id,

                'approved_quantity' =>
                    1,

                'quantity_to_receive' =>
                    1,

                'actual_released_quantity' =>
                    0,

                'returned_quantity' =>
                    0,

                'item_status' =>
                    'RESERVED_FOR_PICKUP',

                'compliance_status' =>
                    'PENDING',
            ]);

        return $custody
            ->fresh();
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
