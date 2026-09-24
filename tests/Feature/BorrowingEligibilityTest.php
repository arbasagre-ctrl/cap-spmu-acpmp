<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\AccountStatus;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
use App\Models\Allocation;
use App\Models\BorrowerRestriction;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\OverdueCase;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Sanction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\BorrowerObligationService;
use App\Services\LateReturnService;
use App\Services\PolicyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrowing eligibility must reflect the borrower's OVERALL standing - every
 * open accountability case, outstanding billing, and active restriction -
 * not just the most recently confirmed sanction. It must also correctly
 * separate the SANCTION PERIOD (a suspension's effective_to date) from CASE/
 * OBLIGATION RESOLUTION (the linked Incident/OverdueCase's own status):
 * eligibility returns only once both have cleared.
 */
class BorrowingEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private AcademicPeriod $periodA;

    private AcademicPeriod $periodB;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->now = Carbon::create(2026, 9, 20, 9);
        Carbon::setTestNow($this->now);

        $this->periodA = AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'ELIG-SEM-A',
            'term_name' => 'Eligibility Test Semester A',
            'start_date' => $this->now->copy()->subMonth()->toDateString(),
            'end_date' => $this->now->copy()->addMonth()->toDateString(),
            'status' => 'ACTIVE',
        ]);

        $this->periodB = AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'ELIG-SEM-B',
            'term_name' => 'Eligibility Test Semester B',
            'start_date' => $this->now->copy()->addMonth()->addDay()->toDateString(),
            'end_date' => $this->now->copy()->addMonths(4)->toDateString(),
            'status' => 'ACTIVE',
        ]);

        $this->head = User::where('access_classification', AccessClassification::SpmuHead->value)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    private function borrower(): User
    {
        $unit = OrganizationalUnit::query()->where('unit_code', 'CHS')->firstOrFail();

        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'account_status' => AccountStatus::Active,
            'organizational_unit_id' => $unit->id,
        ]);
    }

    private function inventoryItem(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'ELIG-FIX'],
            ['category_name' => 'Eligibility Fixture', 'active' => true]
        );
        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Eligibility Fixture Item '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 10,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * A closed, past custody transaction with a linked OverdueCase (given
     * status) and a matching PENDING_REVIEW late-return BorrowerViolation on
     * the same custody transaction, ready for PolicyService::reviewViolation().
     *
     * @return array{0: OverdueCase, 1: BorrowerViolation, 2: CustodyTransaction}
     */
    private function lateReturnCase(User $borrower, string $status = LateReturnService::STATUS_FOR_HEAD_APPROVAL): array
    {
        $item = $this->inventoryItem();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-ELIG-'.fake()->unique()->numberBetween(1000, 9999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Eligibility fixture',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'Test Office',
            'schedule_date' => $this->now->copy()->subDays(10)->toDateString(),
            'return_date' => $this->now->copy()->subDays(5)->toDateString(),
            'needed_from' => $this->now->copy()->subDays(10),
            'return_due_at' => $this->now->copy()->subDays(5)->endOfDay(),
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
            'period_start' => $this->now->copy()->subDays(10),
            'period_end' => $this->now->copy()->subDays(5),
            'allocated_quantity' => 1,
            'released_quantity' => 1,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $this->now->copy()->subDays(10),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-ELIG-'.fake()->unique()->numberBetween(1000, 9999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => 'RETURN_PROCESSING',
            'released_at' => $this->now->copy()->subDays(10),
            'due_at' => $this->now->copy()->subDays(5)->endOfDay(),
            'closed_at' => $this->now->copy()->subDays(3),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => 1,
            'returned_quantity' => 1,
        ]);

        $overdueCase = OverdueCase::query()->create([
            'custody_transaction_id' => $custody->id,
            'borrower_user_id' => $borrower->id,
            'grace_expires_at' => $custody->due_at,
            'overdue_started_at' => $custody->due_at->copy()->addDay(),
            'offense_level' => 1,
            'accrued_amount' => 0,
            'status' => $status,
            'actual_return_date' => $this->now->copy()->subDays(3)->toDateString(),
            'late_days' => 2,
        ]);

        BorrowerRestriction::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => $custody->id,
            'restriction_type' => 'OVERDUE_RETURN',
            'reason' => "Late return under {$custody->custody_no} is awaiting accountability settlement.",
            'effective_from' => $this->now->copy()->subDays(3),
            'status' => 'ACTIVE',
        ]);

        $violation = BorrowerViolation::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => $custody->id,
            'academic_period_id' => $this->periodA->id,
            'violation_code' => 'LATE_RETURN',
            'violation_source' => 'LATE_RETURN',
            'details_json' => ['reasons' => ['LATE_RETURN']],
            'status' => 'PENDING_REVIEW',
            'detected_at' => $this->now->copy()->subDays(3),
        ]);

        return [$overdueCase, $violation, $custody];
    }

    private function confirmOffense(BorrowerViolation $violation, ?string $effectiveTo = null): ?Sanction
    {
        return app(PolicyService::class)->reviewViolation(
            $violation,
            $this->head,
            'CONFIRMED',
            'Confirmed for eligibility test.',
            null,
            null,
            $effectiveTo
        );
    }

    /**
     * Simulates the case/obligation itself being fully resolved (billing
     * settled, final resolution recorded) - the accountability mechanics
     * that reach this state are already covered by
     * LateReturnAccountabilityTest, so this isolates eligibility from them.
     * Deliberately leaves any SANCTION_SUSPENSION restriction untouched:
     * resolving the case must never, by itself, lift a separate active
     * suspension period.
     */
    private function resolveCase(OverdueCase $case): void
    {
        $case->update(['status' => LateReturnService::STATUS_RESOLVED]);

        BorrowerRestriction::query()
            ->where('custody_transaction_id', $case->custody_transaction_id)
            ->whereIn('restriction_type', ['PENDING_RETURN', 'OVERDUE_RETURN', 'UNRESOLVED_INCIDENT'])
            ->where('status', 'ACTIVE')
            ->update(['status' => 'LIFTED', 'effective_to' => now()]);
    }

    private function attemptCreateRequest(User $borrower): \Illuminate\Testing\TestResponse
    {
        $item = $this->inventoryItem();

        return $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->post(route('requests.store'), [
                'purpose_event' => 'Eligibility gate request',
                'location' => 'Campus',
                'requesting_organizational_unit_id' => $borrower->organizational_unit_id,
                'schedule_date' => now()->addDay()->toDateString(),
                'return_date' => now()->addDays(2)->toDateString(),
                'intent' => 'draft',
                'item_ids' => [$item->id],
                'quantities' => [$item->id => 1],
                'locations' => [$item->id => 'ON_CAMPUS'],
            ]);
    }

    private function assertBlocked(User $borrower): void
    {
        $this->attemptCreateRequest($borrower)->assertSessionHasErrors('restriction');
        $this->assertDatabaseMissing('request_versions', ['purpose_event' => 'Eligibility gate request']);
        $this->assertFalse(app(BorrowerObligationService::class)->isEligibleToBorrow($borrower->id));
    }

    private function assertAllowed(User $borrower): void
    {
        $this->attemptCreateRequest($borrower)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('request_versions', ['purpose_event' => 'Eligibility gate request']);
        $this->assertTrue(app(BorrowerObligationService::class)->isEligibleToBorrow($borrower->id));
    }

    /* ------------------------------------------------------------------ */
    /* First offense                                                       */
    /* ------------------------------------------------------------------ */

    public function test_unresolved_first_offense_case_blocks_borrowing(): void
    {
        $borrower = $this->borrower();
        [$case, $violation] = $this->lateReturnCase($borrower);

        $sanction = $this->confirmOffense($violation);

        $this->assertSame(1, $sanction->offense_no);
        $this->assertSame('WRITTEN_REPRIMAND', $sanction->sanction_code);
        $this->assertBlocked($borrower);
    }

    public function test_resolved_first_offense_allows_borrowing(): void
    {
        $borrower = $this->borrower();
        [$case, $violation] = $this->lateReturnCase($borrower);

        $this->confirmOffense($violation);
        $this->resolveCase($case);

        $this->assertAllowed($borrower);
    }

    public function test_historical_resolved_sanction_alone_does_not_block(): void
    {
        $borrower = $this->borrower();
        [$case, $violation] = $this->lateReturnCase($borrower);

        $this->confirmOffense($violation);
        $this->resolveCase($case);

        // The confirmed Sanction record itself is never deleted or hidden.
        $this->assertDatabaseHas('sanctions', ['borrower_user_id' => $borrower->id, 'offense_no' => 1]);
        $this->assertAllowed($borrower);
    }

    /* ------------------------------------------------------------------ */
    /* Second offense                                                      */
    /* ------------------------------------------------------------------ */

    public function test_active_second_offense_suspension_blocks_even_after_the_case_resolves(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $sanction = $this->confirmOffense($violation2);

        $this->assertSame(2, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
        $this->assertNotNull($sanction->effective_to);

        // Resolve the second case's own obligation, but the suspension
        // period itself (a separate restriction) is still active.
        $this->resolveCase($case2);

        $this->assertBlocked($borrower);
    }

    public function test_second_offense_fully_resolved_and_suspension_ended_allows_borrowing(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation2, effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->resolveCase($case2);

        // Still within the suspension window: blocked.
        $this->assertBlocked($borrower);

        // Suspension period ends.
        Carbon::setTestNow($this->now->copy()->addDays(4));

        $this->assertAllowed($borrower);
    }

    public function test_second_offense_suspension_ended_but_billing_still_unpaid_stays_blocked(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation2, effectiveTo: $this->now->copy()->addDays(3)->toDateString());

        // Case itself is left un-resolved (billing still outstanding).
        Carbon::setTestNow($this->now->copy()->addDays(4));

        $this->assertBlocked($borrower);
    }

    /* ------------------------------------------------------------------ */
    /* Third offense / current semester                                    */
    /* ------------------------------------------------------------------ */

    public function test_third_offense_resolved_but_still_same_semester_stays_blocked(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation2, effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->resolveCase($case2);
        Carbon::setTestNow($this->now->copy()->addDays(4));

        [$case3, $violation3] = $this->lateReturnCase($borrower);
        $sanction = $this->confirmOffense($violation3);

        $this->assertSame(3, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
        $this->assertEquals($this->periodA->end_date->toDateString(), $sanction->effective_to->toDateString());

        // The penalty/obligation is resolved today...
        $this->resolveCase($case3);

        // ...but the current semester (periodA) has not ended yet.
        $this->assertBlocked($borrower);
    }

    public function test_third_offense_next_semester_with_no_other_obligation_allows_borrowing(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation2, effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->resolveCase($case2);
        Carbon::setTestNow($this->now->copy()->addDays(4));

        [$case3, $violation3] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation3);
        $this->resolveCase($case3);

        // Blocked while periodA (current semester) is still active.
        $this->assertBlocked($borrower);

        // Next semester begins; periodA's suspension naturally ends.
        Carbon::setTestNow($this->periodB->start_date->copy()->addDay());

        $this->assertAllowed($borrower);
    }

    public function test_third_offense_next_semester_with_unresolved_obligation_stays_blocked(): void
    {
        $borrower = $this->borrower();

        [$case1, $violation1] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation1);
        $this->resolveCase($case1);

        [$case2, $violation2] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation2, effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->resolveCase($case2);
        Carbon::setTestNow($this->now->copy()->addDays(4));

        [$case3, $violation3] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation3);

        // The third offense's own case/obligation is left unresolved.
        Carbon::setTestNow($this->periodB->start_date->copy()->addDay());

        $this->assertBlocked($borrower);
    }

    /* ------------------------------------------------------------------ */
    /* Server-side enforcement (forged POST, not just a disabled button)   */
    /* ------------------------------------------------------------------ */

    public function test_create_request_cannot_be_forged_past_an_active_restriction(): void
    {
        $borrower = $this->borrower();
        [$case, $violation] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation, effectiveTo: $this->now->copy()->addDays(3)->toDateString());

        $response = $this->attemptCreateRequest($borrower);

        $response->assertSessionHasErrors('restriction');
        $this->assertDatabaseMissing('request_versions', ['purpose_event' => 'Eligibility gate request']);
    }

    public function test_create_request_page_shows_the_blocking_reasons(): void
    {
        $borrower = $this->borrower();
        [$case, $violation] = $this->lateReturnCase($borrower);
        $this->confirmOffense($violation, effectiveTo: $this->now->copy()->addDays(3)->toDateString());

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $reasons = $response->viewData('borrowingBlockedReasons');

        $this->assertNotEmpty($reasons);
    }

    public function test_eligible_borrower_sees_no_blocking_reasons(): void
    {
        $borrower = $this->borrower();

        $response = $this->withSession(['active_workspace' => 'BORROWER'])
            ->actingAs($borrower)
            ->get(route('requests.create'));

        $response->assertOk();
        $this->assertSame([], $response->viewData('borrowingBlockedReasons'));
    }
}
