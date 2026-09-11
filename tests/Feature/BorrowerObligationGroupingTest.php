<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\BillingStatement;
use App\Models\BorrowerRestriction;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\OverdueCase;
use App\Models\Payment;
use App\Models\User;
use App\Services\BorrowerObligationService;
use App\Services\LateReturnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrower-facing accountability grouping.
 *
 * One underlying accountability matter must read as ONE borrower obligation
 * even when it produces several technical records (an Incident/OverdueCase,
 * its Billing Statement, and its Borrowing Restriction). These tests drive
 * BorrowerObligationService directly - the single implementation shared by
 * the borrower dashboard and My Obligations - plus a couple of HTTP checks
 * confirming both surfaces are actually wired to it.
 */
class BorrowerObligationGroupingTest extends TestCase
{
    use RefreshDatabase;

    private BorrowerObligationService $obligations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->obligations = app(BorrowerObligationService::class);

        Carbon::setTestNow(Carbon::create(2026, 9, 11, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function borrower(): User
    {
        return User::factory()->create(['access_classification' => AccessClassification::BorrowerOnly]);
    }

    /** A released custody with one line, enough to satisfy Incident/OverdueCase foreign keys. */
    private function custody(User $borrower, Carbon $dueAt): CustodyTransaction
    {
        $category = \App\Models\InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'OBLFIX'],
            ['category_name' => 'Obligation Fixture', 'active' => true]
        );

        $measure = \App\Models\UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        $item = \App\Models\InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Fixture Item '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 50,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);

        $unit = \App\Models\OrganizationalUnit::query()->firstOrCreate(
            ['unit_code' => 'OBLFIX'],
            ['unit_name' => 'Obligation Fixture Unit', 'unit_type' => 'OFFICE', 'active' => true]
        );

        $request = \App\Models\BorrowingRequest::query()->create([
            'request_no' => 'BR-OBL-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $unit->id,
            'current_version_no' => 1,
            'status' => \App\Enums\RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = \App\Models\RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Obligation fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => '2026-08-28',
            'return_date' => $dueAt->toDateString(),
            'needed_from' => Carbon::create(2026, 8, 28)->startOfDay(),
            'return_due_at' => $dueAt,
        ]);

        $requestItem = \App\Models\RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => 1,
            'approved_quantity' => 1,
        ]);

        $allocation = \App\Models\Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => Carbon::create(2026, 8, 28)->startOfDay(),
            'period_end' => $dueAt,
            'allocated_quantity' => 1,
            'released_quantity' => 1,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 8, 28),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-OBL-'.fake()->unique()->numberBetween(1000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'ACTIVE',
            'due_at' => $dueAt,
            'released_at' => Carbon::create(2026, 8, 28, 9),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 1,
            'returned_quantity' => 0,
        ]);

        return $custody->fresh();
    }

    private function incident(User $borrower, CustodyTransaction $custody, string $status): Incident
    {
        return Incident::query()->create([
            'incident_no' => 'INC-'.fake()->unique()->numberBetween(1000, 999999),
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'reported_by_user_id' => $borrower->id,
            'incident_type' => 'DAMAGE',
            'reported_at' => now(),
            'status' => $status,
        ]);
    }

    private function billing(User $borrower, string $status, float $amount = 500.0): BillingStatement
    {
        return BillingStatement::query()->create([
            'billing_no' => 'BILL-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'responsible_spmu_user_id' => $borrower->id,
            'issued_at' => now(),
            'total_amount' => $amount,
            'status' => $status,
        ]);
    }

    /* ================================================================== */
    /* Grouping: incident + billing + restriction = ONE obligation        */
    /* ================================================================== */

    public function test_property_incident_billing_and_restriction_count_as_one_obligation(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $incident = $this->incident($borrower, $custody, 'BILLING_PENDING');
        $billing = $this->billing($borrower, 'ISSUED');
        $billing->lines()->create([
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Damage charge',
            'amount' => 500,
        ]);

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'incident_id' => $incident->id,
            'billing_statement_id' => $billing->id,
            'restriction_type' => 'UNRESOLVED_PROPERTY_OBLIGATION',
            'reason' => 'Open billing statement '.$billing->billing_no,
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count'], 'Incident + billing + restriction must be ONE obligation, not three.');
        $this->assertSame(1, $overview['needs_action'], 'An unpaid Billing Statement needs the borrower to pay.');
        $this->assertSame(0, $overview['processing']);
        $this->assertSame(1, $overview['restrictions']);
    }

    public function test_receipt_awaiting_verification_needs_no_borrower_action(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $incident = $this->incident($borrower, $custody, 'BILLING_PENDING');
        $billing = $this->billing($borrower, 'RECEIPT_SUBMITTED');
        $billing->lines()->create([
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Damage charge',
            'amount' => 500,
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count']);
        $this->assertSame(0, $overview['needs_action'], 'Verifying a submitted receipt is SPMU work, not a borrower task.');
        $this->assertSame(1, $overview['processing']);
    }

    /* ================================================================== */
    /* Standalone restriction without an active custody                   */
    /* ================================================================== */

    public function test_a_standalone_restriction_counts_even_without_a_custody_transaction(): void
    {
        $borrower = $this->borrower();

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'BORROWING_SUSPENSION',
            'reason' => 'Administrative sanction.',
            'effective_from' => now(),
            'effective_to' => null,
            'status' => 'ACTIVE',
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count'], 'A standalone administrative restriction must still be a borrower obligation.');
        $this->assertSame(0, $overview['property_cases']);
        $this->assertSame(0, $overview['late_returns']);
        /* No custody state can hide this from the borrower dashboard. */
        $this->assertSame(1, $overview['needs_action'], 'An indefinite restriction requires the borrower to resolve it with SPMU.');
    }

    public function test_a_timed_restriction_only_needs_waiting(): void
    {
        $borrower = $this->borrower();

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'restriction_type' => 'BORROWING_SUSPENSION',
            'reason' => 'Administrative sanction.',
            'effective_from' => now(),
            'effective_to' => now()->addMonth(),
            'status' => 'ACTIVE',
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count']);
        $this->assertSame(0, $overview['needs_action']);
        $this->assertSame(1, $overview['processing']);
    }

    /* ================================================================== */
    /* Late return: physically outstanding vs. under processing           */
    /* ================================================================== */

    public function test_a_physically_unreturned_item_needs_borrower_action(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->subDays(2));

        OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => now()->subDay(),
            'offense_level' => 1,
            'rate_snapshot' => 75,
            'accrued_amount' => 150,
            'status' => LateReturnService::STATUS_OVERDUE,
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count']);
        $this->assertSame(1, $overview['needs_action'], 'An unreturned item is the borrower\'s obligation to resolve.');
        $this->assertSame(0, $overview['processing']);
    }

    public function test_a_late_return_under_head_review_needs_no_borrower_action(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->subDays(5));

        OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => now()->subDays(4),
            'actual_return_date' => now()->subDays(2),
            'offense_level' => 1,
            'rate_snapshot' => 75,
            'accrued_amount' => 225,
            'status' => LateReturnService::STATUS_FOR_HEAD_APPROVAL,
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(1, $overview['count']);
        $this->assertSame(0, $overview['needs_action'], 'The physical return is already recorded; only SPMU processing remains.');
        $this->assertSame(1, $overview['processing']);
    }

    /* ================================================================== */
    /* Resolved history is excluded from every active count               */
    /* ================================================================== */

    public function test_a_settled_case_leaves_active_counts_and_appears_in_resolved_history(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $incident = $this->incident($borrower, $custody, 'RESOLVED');
        $billing = $this->billing($borrower, 'SETTLED');
        $billing->lines()->create([
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Damage charge',
            'amount' => 500,
        ]);

        Payment::query()->create([
            'billing_statement_id' => $billing->id,
            'recorded_by_user_id' => $borrower->id,
            'verified_by_user_id' => $borrower->id,
            'official_receipt_no' => 'OR-TEST-0001',
            'receipt_date' => now()->toDateString(),
            'amount' => 500,
            'status' => 'VERIFIED',
            'verified_at' => now(),
        ]);

        $overview = $this->obligations->overview($borrower->id);

        $this->assertSame(0, $overview['count'], 'A settled case must no longer count as an active obligation.');
        $this->assertSame(0, $overview['needs_action']);
        $this->assertSame(0, $overview['processing']);
        $this->assertSame(1, $overview['resolved_count']);
    }

    public function test_a_waived_billing_is_never_relabelled_paid_in_the_overview_history(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $incident = $this->incident($borrower, $custody, 'RESOLVED');
        $billing = $this->billing($borrower, 'WAIVED');
        $billing->lines()->create([
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Damage charge',
            'amount' => 500,
        ]);

        $resolved = $this->obligations->resolvedHistory(
            BillingStatement::query()->where('borrower_user_id', $borrower->id)->get(),
            OverdueCase::query()->where('borrower_user_id', $borrower->id)->get()
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('Waived', $resolved->first()['outcome']);
    }

    /* ================================================================== */
    /* Dashboard wiring                                                    */
    /* ================================================================== */

    public function test_dashboard_shows_the_grouped_count_not_the_raw_technical_record_total(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $incident = $this->incident($borrower, $custody, 'BILLING_PENDING');
        $billing = $this->billing($borrower, 'ISSUED');
        $billing->lines()->create([
            'incident_id' => $incident->id,
            'line_type' => 'PROPERTY_ACCOUNTABILITY_CHARGE',
            'description' => 'Damage charge',
            'amount' => 500,
        ]);

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'incident_id' => $incident->id,
            'billing_statement_id' => $billing->id,
            'restriction_type' => 'UNRESOLVED_PROPERTY_OBLIGATION',
            'reason' => 'Open billing statement '.$billing->billing_no,
            'effective_from' => now(),
            'status' => 'ACTIVE',
        ]);

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk()
            /* One grouped obligation, not the three underlying records. */
            ->assertSee('1 outstanding obligation')
            ->assertSee('Action required')
            ->assertSee('1 obligation needs your attention.');
    }

    public function test_dashboard_alert_reads_as_processing_when_nothing_needs_the_borrower(): void
    {
        $borrower = $this->borrower();
        $custody = $this->custody($borrower, now()->addDays(3));

        $this->incident($borrower, $custody, 'OPEN');

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Under SPMU processing')
            ->assertSee('No action is required from you at this time.')
            ->assertDontSee('Action required');
    }

    public function test_dashboard_shows_no_obligation_alert_when_there_are_none(): void
    {
        $borrower = $this->borrower();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('dashboard'));

        /*
         * The dashboard subtitle always says "...and outstanding
         * obligations", and the borrower stylesheet always defines the
         * .borrower-obligation-card rule, so neither text is a safe probe.
         * The rendered alert element's id is unique to the actual markup.
         */
        $response->assertOk()->assertDontSee('id="borrower-obligation-title"', false);
    }

    public function test_my_obligations_shows_the_four_grouped_summary_cards(): void
    {
        $borrower = $this->borrower();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('accountability.index'));

        $response->assertOk()
            ->assertSee('Outstanding Obligations')
            ->assertSee('Needs My Action')
            ->assertSee('Under SPMU Processing')
            ->assertSee('Resolved History')
            ->assertSee('No unresolved obligations.')
            ->assertDontSee('Overdue Returns')
            ->assertDontSee('Open Billings')
            ->assertDontSee('Active Restrictions');
    }
}
