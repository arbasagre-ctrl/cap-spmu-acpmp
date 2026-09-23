<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryAdjustmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $inventory;
    private InventoryCategory $category;
    private UnitOfMeasure $measure;
    private OrganizationalUnit $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(InventoryService::class);
        $this->category = InventoryCategory::query()->create([
            'category_code' => 'ADJ',
            'category_name' => 'Adjustment Test',
            'active' => true,
        ]);
        $this->measure = UnitOfMeasure::query()->create([
            'unit_code' => 'PC',
            'unit_name' => 'Piece',
            'active' => true,
        ]);
        $this->office = OrganizationalUnit::query()->create([
            'unit_code' => 'ADJ-OFFICE',
            'unit_name' => 'Adjustment Test Office',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);
    }

    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
            'organizational_unit_id' => $this->office->id,
        ]);
    }

    private function officer(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuOfficer,
            'organizational_unit_id' => $this->office->id,
        ]);
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->office->id,
        ]);
    }

    private function item(string $name = 'Round Table', int $total = 10, bool $active = true, bool $laundryRequired = false): InventoryItem
    {
        return InventoryItem::query()->create([
            'category_id' => $this->category->id,
            'unit_id' => $this->measure->id,
            'unique_description' => $name,
            'total_quantity' => $total,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => $laundryRequired,
            'provisional' => false,
            'active' => $active,
        ]);
    }

    /** @return array{0: InventoryItem, 1: Incident} */
    private function incidentItem(string $state = 'LOST', string $incidentStatus = 'RESOLVED', bool $laundryRequired = false): array
    {
        $item = $this->item('Incident Item '.fake()->unique()->numerify('###'), 10, laundryRequired: $laundryRequired);
        $borrower = $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-ADJ-'.fake()->unique()->numerify('#####'),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->office->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Adjustment test',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'Adjustment Office',
            'schedule_date' => now()->toDateString(),
            'return_date' => now()->toDateString(),
            'needed_from' => now()->subDay(),
            'return_due_at' => now()->endOfDay(),
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
            'period_start' => now()->subDay(),
            'period_end' => now()->endOfDay(),
            'allocated_quantity' => 1,
            'released_quantity' => 1,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDay(),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-ADJ-'.fake()->unique()->numerify('#####'),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'OBLIGATION_OPEN',
            'released_at' => now()->subDay(),
            'due_at' => now()->endOfDay(),
        ]);

        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        $incident = Incident::query()->create([
            'incident_no' => 'INC-ADJ-'.fake()->unique()->numerify('#####'),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $this->officer()->id,
            'incident_type' => $state === 'DAMAGED_MAINTENANCE' ? 'DAMAGE' : 'LOSS',
            'reported_at' => now()->subHours(2),
            'status' => $incidentStatus,
        ]);

        DB::table('incident_lines')->insert([
            'incident_id' => $incident->id,
            'custody_line_id' => $line->id,
            'quantity' => 1,
            'observed_condition' => $state,
            'disposition_state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$item, $incident];
    }

    public function test_resolved_accountability_does_not_restore_inventory_until_physical_reconciliation(): void
    {
        [$item] = $this->incidentItem('LOST', 'RESOLVED');

        $balance = $this->inventory->availability($item, now(), now()->addMinute());

        $this->assertSame(1.0, $balance['incident']);
        $this->assertSame(9.0, $balance['borrower_available']);
    }

    public function test_accountability_restoration_can_use_linked_incident_without_duplicate_reason_text(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'RESOLVED');
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'REPLACEMENT_RECEIVED',
            'incident_id' => $incident->id,
            'incident_state' => 'LOST',
            'quantity' => 1,
        ]);

        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
            'reason' => 'Replacement Received',
        ]);
    }

    public function test_stock_increasing_adjustments_still_require_documented_source_or_basis(): void
    {
        $item = $this->item('Reason Required Item', 5);
        $head = $this->spmuHead();

        $this->expectException(ValidationException::class);

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'STOCK_ADDITION',
            'quantity' => 1,
        ]);
    }

    public function test_replacement_received_restores_incident_quantity_without_increasing_total_stock(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'RESOLVED');
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'REPLACEMENT_RECEIVED',
            'incident_id' => $incident->id,
            'incident_state' => 'LOST',
            'quantity' => 1,
            'reason' => 'Replacement unit physically received and inspected as serviceable.',
        ]);

        $item->refresh();
        $balance = $this->inventory->availability($item, now(), now()->addMinute());

        $this->assertSame(10.0, (float) $item->total_quantity);
        $this->assertSame(0.0, $balance['incident']);
        $this->assertSame(10.0, $balance['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
        ]);
    }

    public function test_write_off_retires_incident_quantity_without_creating_false_available_stock(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'RESOLVED');
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'WRITE_OFF_RETIRED',
            'incident_id' => $incident->id,
            'incident_state' => 'LOST',
            'quantity' => 1,
            'reason' => 'Approved physical write-off after accountability disposition.',
        ]);

        $item->refresh();
        $balance = $this->inventory->availability($item, now(), now()->addMinute());

        $this->assertSame(9.0, (float) $item->total_quantity);
        $this->assertSame(0.0, $balance['incident']);
        $this->assertSame(9.0, $balance['borrower_available']);
    }

    public function test_additional_stock_and_physical_count_corrections_are_stock_card_movements(): void
    {
        $item = $this->item('Stock Count Item', 5);
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'STOCK_ADDITION',
            'quantity' => 2,
            'reason' => 'Two newly acquired units received by SPMU.',
        ]);

        $this->assertSame(7.0, (float) $item->fresh()->total_quantity);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_STOCK_ADDITION',
            'source_type' => InventoryItem::class,
            'source_id' => $item->id,
        ]);

        $this->inventory->recordAdjustment($item->fresh(), $head, [
            'action' => 'PHYSICAL_COUNT_CORRECTION',
            'new_total_quantity' => 6,
            'reason' => 'Verified physical count during inventory reconciliation.',
        ]);

        $this->assertSame(6.0, (float) $item->fresh()->total_quantity);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_PHYSICAL_COUNT_CORRECTION',
            'source_id' => $item->id,
        ]);
    }


    public function test_manual_maintenance_hold_removes_only_free_stock_and_can_be_restored(): void
    {
        $item = $this->item('Preparation Condition Item', 10);
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 2,
            'reason' => 'AO preparation discrepancy verified: two units require maintenance.',
        ]);

        $held = $this->inventory->availability($item->fresh(), now(), now()->addMinute());

        $this->assertSame(10.0, (float) $item->fresh()->total_quantity);
        $this->assertSame(2.0, $held['condition_hold']);
        $this->assertSame(2.0, $held['damaged_maintenance']);
        $this->assertSame(8.0, $held['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_CONDITION_HOLD',
            'source_type' => InventoryItem::class,
            'source_id' => $item->id,
        ]);

        $this->inventory->recordAdjustment($item->fresh(), $head, [
            'action' => 'RETURN_MAINTENANCE_TO_SERVICE',
            'quantity' => 1,
        ]);

        $restored = $this->inventory->availability($item->fresh(), now(), now()->addMinute());

        $this->assertSame(1.0, $restored['condition_hold']);
        $this->assertSame(9.0, $restored['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_CONDITION_RESTORATION',
            'source_id' => $item->id,
            'reason' => 'Maintenance Stock Returned to Service — Physically verified serviceable and returned to service.',
        ]);
    }

    public function test_manual_maintenance_stock_can_be_retired_without_creating_available_stock(): void
    {
        $item = $this->item('Condemn Maintenance Item', 10);
        $head = $this->spmuHead();

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 2,
            'reason' => 'Two units removed from service pending assessment.',
        ]);

        $this->inventory->recordAdjustment($item->fresh(), $head, [
            'action' => 'RETIRE_MAINTENANCE_STOCK',
            'quantity' => 2,
            'reason' => 'Assessment confirmed the two units are beyond repair.',
        ]);

        $balance = $this->inventory->availability($item->fresh(), now(), now()->addMinute());

        $this->assertSame(8.0, (float) $item->fresh()->total_quantity);
        $this->assertSame(0.0, $balance['condition_hold']);
        $this->assertSame(8.0, $balance['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_CONDITION_RETIREMENT',
            'source_id' => $item->id,
        ]);
    }

    public function test_action_officer_cannot_post_inventory_adjustment(): void
    {
        $item = $this->item('AO Read Only Item', 5);
        $officer = $this->officer();

        $this->actingAs($officer)
            ->post(route('inventory.adjust', $item), [
                'action' => 'STOCK_ADDITION',
                'quantity' => 1,
                'reason' => 'Attempted stock edit.',
            ])
            ->assertForbidden();

        $this->assertSame(5.0, (float) $item->fresh()->total_quantity);
    }

    public function test_manual_inventory_adjustment_cannot_duplicate_ao_compliance_restoration(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'COMPLIANCE_REQUIRED');
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->post(route('inventory.adjust', $item), [
                'action' => 'REPLACEMENT_RECEIVED',
                'incident_source' => $incident->id.'|LOST',
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('action');

        $this->assertDatabaseMissing('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
        ]);
    }

    public function test_inactive_inventory_remains_visible_to_spmu_for_reactivation_and_review(): void
    {
        $item = $this->item('Archived Item', 5, active: false);
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertSee('Archived Item')
            ->assertSee('Inactive / archived records');

        $this->actingAs($head)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertSee('Inactive / archived record');
    }

    public function test_edit_screen_does_not_allow_direct_total_stock_or_condition_changes(): void
    {
        $item = $this->item('Controlled Stock Item', 8);
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->get(route('inventory.edit', $item))
            ->assertOk()
            ->assertDontSee('name="total_quantity"', false)
            ->assertDontSee('name="condition_code"', false)
            ->assertSee('Managed from Inventory Overview')
            ->assertSee('View Inventory Overview');
    }

    public function test_laundry_managed_item_can_enter_a_manual_maintenance_hold_when_physically_damaged(): void
    {
        $item = $this->item('Round Table Cloth', 12, laundryRequired: true);
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertSee('Additional Stock Received')
            ->assertSee('Physical Count Correction')
            ->assertSee('Place Stock Under Maintenance')
            ->assertDontSee('Return Maintenance Stock to Service')
            ->assertDontSee('Retire / Condemn Maintenance Stock');

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 1,
            'reason' => 'One unit is physically damaged and requires maintenance.',
        ]);

        $balance = $this->inventory->availability($item->fresh(), now(), now()->addMinute());

        $this->assertSame(1.0, $balance['condition_hold']);
        $this->assertSame(11.0, $balance['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_CONDITION_HOLD',
            'source_type' => InventoryItem::class,
            'source_id' => $item->id,
        ]);
    }

    public function test_laundry_managed_accountability_replacement_restores_inventory_automatically_after_ao_verification(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'COMPLIANCE_REQUIRED', laundryRequired: true);
        $officer = $this->officer();

        $this->inventory->recordIncidentCompliance($incident, $officer, 'REPLACEMENT');

        $item->refresh();
        $balance = $this->inventory->availability($item, now(), now()->addMinute());

        $this->assertSame(10.0, (float) $item->total_quantity);
        $this->assertSame(0.0, $balance['incident']);
        $this->assertSame(10.0, $balance['borrower_available']);
        $this->assertDatabaseHas('inventory_transactions', [
            'transaction_type' => 'INVENTORY_INCIDENT_RESTORATION',
            'source_type' => Incident::class,
            'source_id' => $incident->id,
        ]);
    }

    public function test_non_linen_return_and_retire_actions_appear_only_when_manual_maintenance_hold_exists(): void
    {
        $item = $this->item('Maintenance Eligibility Item', 8);
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertSee('Place Stock Under Maintenance')
            ->assertDontSee('Return Maintenance Stock to Service')
            ->assertDontSee('Retire / Condemn Maintenance Stock');

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'PLACE_UNDER_MAINTENANCE',
            'quantity' => 2,
            'reason' => 'Two units physically removed from service.',
        ]);

        $this->actingAs($head)
            ->get(route('inventory.show', $item->fresh()))
            ->assertOk()
            ->assertSee('Return Maintenance Stock to Service')
            ->assertSee('Retire / Condemn Maintenance Stock');
    }

    public function test_non_linen_borrowing_history_does_not_offer_laundry_filter_but_laundry_item_does(): void
    {
        $head = $this->spmuHead();
        $nonLinen = $this->item('Non Linen Filter Item', 4);
        $linen = $this->item('Linen Filter Item', 4, laundryRequired: true);

        $this->actingAs($head)
            ->get(route('inventory.show', $nonLinen))
            ->assertOk()
            ->assertDontSee('<option value="IN_LAUNDRY"', false);

        $this->actingAs($head)
            ->get(route('inventory.show', $linen))
            ->assertOk()
            ->assertSee('<option value="IN_LAUNDRY"', false);
    }

    public function test_write_off_is_hidden_until_accountability_case_has_final_resolution(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'OPEN');
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->get(route('inventory.show', $item))
            ->assertOk()
            ->assertDontSee('Stock Retired / Written Off');

        $incident->update(['status' => 'RESOLVED']);

        $this->actingAs($head)
            ->get(route('inventory.show', $item->fresh()))
            ->assertOk()
            ->assertSee('Stock Retired / Written Off');
    }

    public function test_write_off_rejects_non_final_accountability_case_even_with_crafted_request(): void
    {
        [$item, $incident] = $this->incidentItem('LOST', 'OPEN');
        $head = $this->spmuHead();

        $this->expectException(ValidationException::class);

        $this->inventory->recordAdjustment($item, $head, [
            'action' => 'WRITE_OFF_RETIRED',
            'incident_id' => $incident->id,
            'incident_state' => 'LOST',
            'quantity' => 1,
            'reason' => 'Attempted write-off before final accountability resolution.',
        ]);
    }


    public function test_item_with_active_inventory_commitment_cannot_be_archived(): void
    {
        [$item] = $this->incidentItem('LOST', 'OPEN');
        $head = $this->spmuHead();

        $this->actingAs($head)
            ->from(route('inventory.edit', $item))
            ->put(route('inventory.update', $item), [
                'category_id' => $item->category_id,
                'unit_id' => $item->unit_id,
                'unique_description' => $item->unique_description,
                'specification' => $item->specification,
                'borrowable' => 1,
                'change_reason' => 'Attempt to archive while incident stock is unresolved physically.',
            ])
            ->assertSessionHasErrors('active');

        $this->assertTrue($item->fresh()->active);
    }
}
