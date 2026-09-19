<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\Allocation;
use App\Models\BillingLine;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Role;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AccountabilityCaseTally;
use App\Services\BorrowerObligationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batch 3: dashboard actions, counts, and drilldowns.
 *
 * Covers: the borrower action queue reusing BorrowerObligationService's own
 * action_state instead of a coarse custody status, the three borrower KPI
 * cards opening the exact filtered My Borrowings subset they counted, the
 * canonical AccountabilityCaseTally reconciling the AO dashboard, the Head
 * dashboard, and the Accountability workspace's own summary/chip/rows, and
 * the Head "Active Restrictions" card's destination filter.
 */
class DashboardAccountabilityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 9, 15, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function user(AccessClassification $classification): User
    {
        $user = User::factory()->create(['access_classification' => $classification]);

        $isSpmu = in_array($classification, [
            AccessClassification::SpmuHead,
            AccessClassification::SpmuOfficer,
        ], true);

        if ($isSpmu) {
            $role = Role::query()->firstOrCreate(
                ['role_code' => UserRole::Spmu->value],
                ['role_name' => 'SPMU', 'active' => true]
            );

            $user->roles()->syncWithoutDetaching([
                $role->id => ['assigned_at' => now()],
            ]);
        }

        return $user->fresh();
    }

    private function borrower(): User
    {
        return $this->user(AccessClassification::BorrowerOnly);
    }

    private function officer(): User
    {
        return $this->user(AccessClassification::SpmuOfficer);
    }

    private function spmuHead(): User
    {
        return $this->user(AccessClassification::SpmuHead);
    }

    private function organizationalUnit(): OrganizationalUnit
    {
        return OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'BATCH3FIX'],
            ['unit_name' => 'Batch 3 Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]
        );
    }

    private function inventoryItem(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'BATCH3'],
            ['category_name' => 'Batch 3 Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Batch 3 Equipment '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 50,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $custodyOverrides
     * @param  array<string, mixed>  $lineOverrides
     */
    private function custody(User $borrower, array $custodyOverrides = [], array $lineOverrides = []): CustodyTransaction
    {
        $unit = $this->organizationalUnit();
        $item = $this->inventoryItem();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Batch 3 fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => now()->subDays(5)->toDateString(),
            'return_date' => now()->addDays(3)->toDateString(),
            'needed_from' => now()->subDays(5)->startOfDay(),
            'return_due_at' => now()->addDays(3)->endOfDay(),
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 5,
            'approved_quantity' => 5,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => now()->subDays(5)->startOfDay(),
            'period_end' => now()->addDays(3)->endOfDay(),
            'allocated_quantity' => 5,
            'released_quantity' => 5,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => now()->subDays(5),
        ]);

        $custody = CustodyTransaction::query()->create(array_merge([
            'custody_no' => 'CUS-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'BORROWED',
            'due_at' => now()->addDays(3),
            'released_at' => now()->subDays(5),
        ], $custodyOverrides));

        CustodyLine::query()->create(array_merge([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 5,
            'quantity_to_receive' => 5,
            'actual_released_quantity' => 5,
            'returned_quantity' => 0,
        ], $lineOverrides));

        return $custody->fresh(['lines.requestItem.inventoryItem']);
    }

    private function incident(CustodyTransaction $custody, string $status = 'COMPLIANCE_REQUIRED'): Incident
    {
        $officer = $this->officer();

        return Incident::query()->create([
            'incident_no' => 'INC-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'reported_by_user_id' => $officer->id,
            'incident_type' => 'DAMAGED',
            'reported_at' => now(),
            'status' => $status,
        ]);
    }

    private function overdueCase(CustodyTransaction $custody, string $status = 'FOR_HEAD_APPROVAL'): OverdueCase
    {
        return OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $custody->borrower_user_id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => now()->subDays(2),
            'offense_level' => 1,
            'rate_snapshot' => 75,
            'accrued_amount' => 150,
            'status' => $status,
        ]);
    }

    private function billingForIncident(Incident $incident, string $status = 'ISSUED'): BillingStatement
    {
        $head = $this->spmuHead();

        $billing = BillingStatement::query()->create([
            'billing_no' => 'BILL-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $incident->borrower_user_id,
            'responsible_spmu_user_id' => $head->id,
            'issued_at' => now(),
            'total_amount' => 500,
            'status' => $status,
        ]);

        BillingLine::query()->create([
            'billing_statement_id' => $billing->id,
            'incident_id' => $incident->id,
            'source_key' => 'INCIDENT:'.$incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Fixture charge',
            'basis' => 'Fixture',
            'amount' => 500,
        ]);

        return $billing->fresh('lines.penalty');
    }

    private function billingForOverdue(OverdueCase $overdue, string $status = 'ISSUED'): BillingStatement
    {
        $head = $this->spmuHead();

        $penalty = \App\Models\Penalty::query()->create([
            'borrower_user_id' => $overdue->borrower_user_id,
            'custody_transaction_id' => $overdue->custody_transaction_id,
            'overdue_case_id' => $overdue->id,
            'assessed_by_user_id' => $overdue->borrower_user_id,
            'penalty_type' => 'LATE_RETURN_FEE',
            'basis' => 'Fixture',
            'rate_snapshot' => 75,
            'amount' => 150,
            'status' => 'ASSESSED',
            'assessed_at' => now(),
        ]);

        $billing = BillingStatement::query()->create([
            'billing_no' => 'BILL-LATE-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $overdue->borrower_user_id,
            'responsible_spmu_user_id' => $head->id,
            'issued_at' => now(),
            'total_amount' => 150,
            'status' => $status,
        ]);

        BillingLine::query()->create([
            'billing_statement_id' => $billing->id,
            'penalty_id' => $penalty->id,
            'source_key' => 'OVERDUE_CASE:'.$overdue->id,
            'line_type' => 'LATE_RETURN_FEE',
            'description' => 'Fixture late fee',
            'basis' => 'Fixture',
            'amount' => 150,
        ]);

        return $billing->fresh('lines.penalty');
    }

    private function restriction(User $borrower, array $overrides = []): BorrowerRestriction
    {
        return BorrowerRestriction::query()->create(array_merge([
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'UNRESOLVED_PROPERTY_OBLIGATION',
            'reason' => 'Fixture restriction',
            'effective_from' => now()->subDay(),
            'status' => 'ACTIVE',
        ], $overrides));
    }

    /* ================================================================== */
    /* 1. Borrower action queue reuses BorrowerObligationService           */
    /* ================================================================== */

    public function test_borrower_actionable_custody_ids_excludes_processing_only_rslddp_stages(): void
    {
        $borrower = $this->borrower();
        $service = app(BorrowerObligationService::class);

        foreach (['RSLDDP_AWAITING_UPLOAD', 'RSLDDP_FOR_ACCOUNTING_PROCESSING', 'RSLDDP_FOR_RESOLUTION'] as $status) {
            $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
            $this->incident($custody, $status);

            $this->assertNotContains(
                $custody->id,
                $service->borrowerActionableCustodyIds($borrower->id),
                "{$status} must not be borrower-actionable - it is SPMU/Accounting/Head processing."
            );
        }
    }

    public function test_borrower_actionable_custody_ids_includes_rslddp_payment_required_with_genuine_billing(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incident = $this->incident($custody, 'RSLDDP_PAYMENT_REQUIRED');
        $this->billingForIncident($incident, 'ISSUED');

        $service = app(BorrowerObligationService::class);

        $this->assertContains(
            $custody->id,
            $service->borrowerActionableCustodyIds($borrower->id),
            'RSLDDP_PAYMENT_REQUIRED with a genuinely unpaid linked billing must be borrower-actionable.'
        );
    }

    public function test_dashboard_action_queue_excludes_processing_only_rslddp_incident(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $this->incident($custody, 'RSLDDP_AWAITING_UPLOAD');

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk();

        $this->assertTrue(
            $response->viewData('queue')->isEmpty(),
            'An RSLDDP case that is only awaiting upload must not appear in the borrower action queue.'
        );
        $response->assertSee('class="borrower-dash-empty"', false);
    }

    public function test_dashboard_action_queue_includes_rslddp_payment_required_with_genuine_billing(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incident = $this->incident($custody, 'RSLDDP_PAYMENT_REQUIRED');
        $this->billingForIncident($incident, 'ISSUED');

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk();

        $queue = $response->viewData('queue');
        $this->assertCount(1, $queue, 'The payable RSLDDP case must be in the borrower action queue.');
        $this->assertSame($custody->request_id, $queue->first()->id);
        $response->assertSee('borrower-next-row');
    }

    /* ================================================================== */
    /* 2. Borrower dashboard KPI drilldowns                                */
    /* ================================================================== */

    public function test_active_borrowings_kpi_links_to_matching_filtered_set(): void
    {
        $borrower = $this->borrower();
        $matching = $this->custody($borrower, [
            'status' => 'BORROWED',
            'released_at' => now()->subDay(),
        ], ['returned_quantity' => 0]);

        /* Not released yet - must not count/appear as an Active Borrowing. */
        $distractor = $this->custody($borrower, [
            'status' => 'PREPARING_RELEASE',
            'released_at' => null,
            'scheduled_release_at' => now()->addDay(),
        ]);

        $dashboard = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(1, $dashboard->viewData('statistics')['Active Borrowings']);

        $filtered = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.index', ['kpi' => 'active_borrowings']));
        $filtered->assertOk();

        $custodies = $filtered->viewData('custodies');
        $this->assertCount(1, $custodies);
        $this->assertSame($matching->id, $custodies->first()->id);
        $this->assertFalse($custodies->contains('id', $distractor->id));
    }

    public function test_upcoming_pickup_kpi_links_to_matching_filtered_set(): void
    {
        $borrower = $this->borrower();
        $matching = $this->custody($borrower, [
            'status' => 'PREPARING_RELEASE',
            'released_at' => null,
            'scheduled_release_at' => now()->addDay(),
            'pickup_expired_at' => null,
            'pickup_expires_at' => now()->addDays(2),
        ]);

        /* Already released - must not count/appear as Upcoming Pickup. */
        $distractor = $this->custody($borrower, [
            'status' => 'BORROWED',
            'released_at' => now()->subDay(),
        ], ['returned_quantity' => 0]);

        $dashboard = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(1, $dashboard->viewData('statistics')['Upcoming Pickup']);

        $filtered = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.index', ['kpi' => 'upcoming_pickup']));
        $filtered->assertOk();

        $custodies = $filtered->viewData('custodies');
        $this->assertCount(1, $custodies);
        $this->assertSame($matching->id, $custodies->first()->id);
        $this->assertFalse($custodies->contains('id', $distractor->id));
    }

    public function test_returns_due_kpi_links_to_matching_filtered_set(): void
    {
        $borrower = $this->borrower();
        $matching = $this->custody($borrower, [
            'status' => 'BORROWED',
            'released_at' => now()->subDays(2),
            'due_at' => now()->addHours(6),
        ], ['returned_quantity' => 0]);

        /* Due date far in the future - must not count/appear as Returns Due. */
        $distractor = $this->custody($borrower, [
            'status' => 'BORROWED',
            'released_at' => now()->subDay(),
            'due_at' => now()->addDays(10),
        ], ['returned_quantity' => 0]);

        $dashboard = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(1, $dashboard->viewData('statistics')['Returns Due']);

        $filtered = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.index', ['kpi' => 'returns_due']));
        $filtered->assertOk();

        $custodies = $filtered->viewData('custodies');
        $this->assertCount(1, $custodies);
        $this->assertSame($matching->id, $custodies->first()->id);
        $this->assertFalse($custodies->contains('id', $distractor->id));
    }

    public function test_custody_index_without_kpi_param_shows_the_normal_all_view(): void
    {
        $borrower = $this->borrower();
        $active = $this->custody($borrower, ['status' => 'BORROWED', 'released_at' => now()->subDay()], ['returned_quantity' => 0]);
        $upcoming = $this->custody($borrower, [
            'status' => 'PREPARING_RELEASE',
            'released_at' => null,
            'scheduled_release_at' => now()->addDay(),
        ]);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('custody.index'));

        $response->assertOk();
        $custodies = $response->viewData('custodies');
        $this->assertCount(2, $custodies);
        $this->assertTrue($custodies->contains('id', $active->id));
        $this->assertTrue($custodies->contains('id', $upcoming->id));
    }

    /* ================================================================== */
    /* 3. Canonical accountability case tally                              */
    /* ================================================================== */

    public function test_incident_with_linked_billing_and_restriction_counts_as_one_case(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incident = $this->incident($custody, 'FOR_BILLING');
        $billing = $this->billingForIncident($incident);
        $this->restriction($borrower, ['incident_id' => $incident->id]);

        $tally = AccountabilityCaseTally::forOpenRecords(
            Incident::query()->get(),
            OverdueCase::query()->get(),
            BillingStatement::with('lines.penalty')->get(),
            BorrowerRestriction::query()->get(),
        );

        $this->assertSame(1, $tally->count());
        $this->assertTrue($tally->standaloneBillings()->isEmpty(), 'The linked billing is a detail of the incident, not a second case.');
        $this->assertTrue($tally->standaloneRestrictions()->isEmpty(), 'The linked restriction is a detail of the incident, not a second case.');
    }

    public function test_overdue_case_with_linked_billing_counts_as_one_case(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OVERDUE'], ['returned_quantity' => 0]);
        $overdue = $this->overdueCase($custody, 'BILLED');
        $this->billingForOverdue($overdue);

        $tally = AccountabilityCaseTally::forOpenRecords(
            Incident::query()->get(),
            OverdueCase::query()->where('status', '!=', 'RESOLVED')->get(),
            BillingStatement::with('lines.penalty')->get(),
            BorrowerRestriction::query()->get(),
        );

        $this->assertSame(1, $tally->count());
        $this->assertTrue($tally->standaloneBillings()->isEmpty(), 'The linked billing is a detail of the overdue case, not a second case.');
    }

    public function test_incident_and_overdue_case_on_same_custody_count_as_two_cases(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $this->incident($custody, 'COMPLIANCE_REQUIRED');
        $this->overdueCase($custody, 'FOR_HEAD_APPROVAL');

        $tally = AccountabilityCaseTally::forOpenRecords(
            Incident::query()->get(),
            OverdueCase::query()->where('status', '!=', 'RESOLVED')->get(),
            BillingStatement::with('lines.penalty')->get(),
            BorrowerRestriction::query()->get(),
        );

        $this->assertSame(
            2,
            $tally->count(),
            'An Incident and an OverdueCase on the same custody remain two separate cases - never deduplicated by custody_id.'
        );
    }

    public function test_standalone_legacy_record_remains_accessible_without_inflating_a_linked_case(): void
    {
        $borrower = $this->borrower();
        $head = $this->spmuHead();

        /* A genuinely standalone legacy billing and a genuinely standalone administrative restriction - neither linked to any open case. */
        $legacyBilling = BillingStatement::query()->create([
            'billing_no' => 'BILL-LEGACY-BATCH3-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'responsible_spmu_user_id' => $head->id,
            'issued_at' => now(),
            'total_amount' => 300,
            'status' => 'ISSUED',
        ]);
        BillingLine::query()->create([
            'billing_statement_id' => $legacyBilling->id,
            'source_key' => 'LEGACY:1',
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Legacy fixture charge',
            'basis' => 'Legacy',
            'amount' => 300,
        ]);
        $standaloneRestriction = $this->restriction($borrower);

        $tally = AccountabilityCaseTally::forOpenRecords(
            Incident::query()->get(),
            OverdueCase::query()->where('status', '!=', 'RESOLVED')->get(),
            BillingStatement::with('lines.penalty')->get(),
            BorrowerRestriction::query()->get(),
        );

        $this->assertSame(2, $tally->count(), 'Both standalone records remain represented, each once.');
        $this->assertTrue($tally->standaloneBillings()->contains('id', $legacyBilling->id));
        $this->assertTrue($tally->standaloneRestrictions()->contains('id', $standaloneRestriction->id));
    }

    public function test_ao_dashboard_head_dashboard_and_accountability_summary_reconcile(): void
    {
        $borrower = $this->borrower();

        /* One property case (with a linked billing + restriction - still one case). */
        $custodyA = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incident = $this->incident($custodyA, 'FOR_BILLING');
        $this->billingForIncident($incident);
        $this->restriction($borrower, ['incident_id' => $incident->id]);

        /* One late-return case, unrelated custody. */
        $custodyB = $this->custody($borrower, ['status' => 'OVERDUE'], ['returned_quantity' => 0]);
        $this->overdueCase($custodyB, 'FOR_HEAD_APPROVAL');

        /* One standalone legacy restriction - no linked incident/overdue. */
        $this->restriction($borrower);

        $expectedCaseCount = 3; // incident + overdue + standalone restriction

        $officer = $this->officer();
        $head = $this->spmuHead();

        $aoDashboard = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($officer)
            ->get(route('dashboard'));
        $aoDashboard->assertOk();
        $this->assertSame($expectedCaseCount, $aoDashboard->viewData('statistics')['Accountability Cases']);

        $headDashboard = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('dashboard'));
        $headDashboard->assertOk();
        $this->assertSame($expectedCaseCount, $headDashboard->viewData('statistics')['Open Accountability Cases']);

        $accountabilityPage = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index'));
        $accountabilityPage->assertOk()
            ->assertSee(
                'aria-label="Open Accountability Cases: '.$expectedCaseCount.'. Unresolved cases"',
                false
            )
            ->assertSee(
                '<span class="accountability-count-chip">'.$expectedCaseCount.'</span>',
                false
            );
    }

    /* ================================================================== */
    /* 4. Head/Admin Active Restrictions destination                       */
    /* ================================================================== */

    public function test_active_restrictions_card_destination_consumes_its_filter_and_shows_matching_records(): void
    {
        $borrower = $this->borrower();

        /* A restriction attached to an open case - must stay visible under the restrictions filter. */
        $custodyWithRestriction = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incidentWithRestriction = $this->incident($custodyWithRestriction, 'COMPLIANCE_REQUIRED');
        $this->restriction($borrower, ['incident_id' => $incidentWithRestriction->id]);

        /* An open case with no restriction - must be hidden under the restrictions filter. */
        $custodyWithoutRestriction = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $incidentWithoutRestriction = $this->incident($custodyWithoutRestriction, 'COMPLIANCE_REQUIRED');

        /* A late-return case can be restricted too, without becoming a different case type. */
        $custodyLateWithRestriction = $this->custody($borrower, ['status' => 'OVERDUE'], ['returned_quantity' => 0]);
        $lateWithRestriction = $this->overdueCase($custodyLateWithRestriction, 'FOR_HEAD_APPROVAL');
        $this->restriction($borrower, [
            'custody_transaction_id' => $custodyLateWithRestriction->id,
            'restriction_type' => 'OVERDUE_RETURN',
        ]);

        $custodyLateWithoutRestriction = $this->custody($borrower, ['status' => 'OVERDUE'], ['returned_quantity' => 0]);
        $lateWithoutRestriction = $this->overdueCase($custodyLateWithoutRestriction, 'FOR_HEAD_APPROVAL');

        /* A standalone administrative restriction - its own row, always visible. */
        $standaloneRestriction = $this->restriction($borrower);

        $head = $this->spmuHead();

        $dashboard = $this->withSession(['active_workspace' => 'SPMU'])->actingAs($head)->get(route('dashboard'));
        $dashboard->assertOk();
        $this->assertSame(3, $dashboard->viewData('statistics')['Active Restrictions']);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index', ['view' => 'restrictions']));
        $response->assertOk();

        $html = $response->getContent();

        $tagWindow = function (string $marker) use ($html): string {
            $start = strpos($html, $marker);
            $this->assertNotFalse($start, "Marker not found: {$marker}");
            $end = strpos($html, '>', $start);

            return substr($html, $start, $end - $start);
        };

        $restrictedPropertyTag = $tagWindow('id="incident-'.$incidentWithRestriction->id.'"');
        $unrestrictedPropertyTag = $tagWindow('id="incident-'.$incidentWithoutRestriction->id.'"');
        $restrictedLateTag = $tagWindow('id="late-return-'.$lateWithRestriction->id.'"');
        $unrestrictedLateTag = $tagWindow('id="late-return-'.$lateWithoutRestriction->id.'"');

        $this->assertStringNotContainsString('hidden', $restrictedPropertyTag);
        $this->assertStringContainsString('data-case-type="PROPERTY"', $restrictedPropertyTag);
        $this->assertStringContainsString('data-has-restriction="1"', $restrictedPropertyTag);
        $this->assertStringContainsString('hidden', $unrestrictedPropertyTag);
        $this->assertStringContainsString('data-has-restriction="0"', $unrestrictedPropertyTag);

        $this->assertStringNotContainsString('hidden', $restrictedLateTag);
        $this->assertStringContainsString('data-case-type="LATE_RETURN"', $restrictedLateTag);
        $this->assertStringContainsString('data-has-restriction="1"', $restrictedLateTag);
        $this->assertStringContainsString('hidden', $unrestrictedLateTag);
        $this->assertStringContainsString('data-has-restriction="0"', $unrestrictedLateTag);
        $standaloneRestrictionTag = $tagWindow('id="restriction-'.$standaloneRestriction->id.'"');
        $this->assertStringNotContainsString('hidden', $standaloneRestrictionTag);
        $this->assertStringContainsString('data-has-restriction="1"', $standaloneRestrictionTag);
        $response->assertSee('value="ACTIVE" checked', false);

        /* The "Restrictions" type chip starts pre-selected for this destination. */
        $response->assertSee('accountability-type-chip is-active" data-type-filter="RESTRICTION"', false);

        $interactions = file_get_contents(resource_path('views/accountability/partials/cases-interactions.blade.php'));
        $this->assertStringContainsString("type === 'RESTRICTION'", $interactions);
        $this->assertStringContainsString("row.dataset.hasRestriction === '1'", $interactions);
    }

    public function test_case_status_filter_contract_matches_current_rslddp_property_statuses(): void
    {
        $borrower = $this->borrower();
        $matchingCustody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $matchingIncident = $this->incident($matchingCustody, 'RSLDDP_FOR_ACCOUNTING_PROCESSING');
        $nonmatchingCustody = $this->custody($borrower, ['status' => 'OBLIGATION_OPEN'], ['returned_quantity' => 5]);
        $nonmatchingIncident = $this->incident($nonmatchingCustody, 'COMPLIANCE_REQUIRED');
        $head = $this->spmuHead();

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('accountability.index'));

        $response->assertOk()
            ->assertSee('value="RSLDDP_FOR_ACCOUNTING_PROCESSING" checked', false);

        $html = $response->getContent();
        $tagWindow = function (string $marker) use ($html): string {
            $start = strpos($html, $marker);
            $this->assertNotFalse($start, "Marker not found: {$marker}");
            $end = strpos($html, '>', $start);

            return substr($html, $start, $end - $start);
        };

        $this->assertStringContainsString('data-case-type="PROPERTY"', $tagWindow('id="incident-'.$matchingIncident->id.'"'));
        $this->assertStringContainsString('data-status="RSLDDP_FOR_ACCOUNTING_PROCESSING"', $tagWindow('id="incident-'.$matchingIncident->id.'"'));
        $this->assertStringContainsString('data-status="COMPLIANCE_REQUIRED"', $tagWindow('id="incident-'.$nonmatchingIncident->id.'"'));

        $interactions = file_get_contents(resource_path('views/accountability/partials/cases-interactions.blade.php'));
        $this->assertStringContainsString('const statusMatches = statuses.has(row.dataset.status);', $interactions);
        $this->assertStringNotContainsString("row.dataset.caseType !== 'LATE_RETURN'", $interactions);
    }

    public function test_accountability_filter_and_decision_copy_is_clear_and_current(): void
    {
        $view = file_get_contents(resource_path('views/accountability/index.blade.php'));

        $this->assertStringContainsString(
            'An RSLDDP will be generated as part of the compliance process and retained as this case\'s paper trail.',
            $view
        );
        $this->assertStringNotContainsString('An RSLDDP is not prepared', $view);
        $this->assertStringContainsString(
            'Administrative offense confirmation can proceed only when the required academic-period and sanction configuration is complete and this transaction has not already been dismissed for offense purposes.',
            $view
        );
        $this->assertStringContainsString('No cases match the current search or filters.', $view);
        $this->assertStringNotContainsString('No case does not match the current search or status filter.', $view);
    }
}
