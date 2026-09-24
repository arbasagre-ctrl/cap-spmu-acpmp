<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
use App\Models\Allocation;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\Sanction;
use App\Models\SystemSetting;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\PolicyService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offense ladder (1st = Written Reprimand, 2nd = Borrowing Suspension,
 * 3rd+ = the 3rd-offense rule) is cumulative across a borrower's entire
 * confirmed-violation history. A new academic period/semester must never
 * reset the count back to 1st offense - only the 3rd-offense suspension's
 * effective_to date is period-scoped (it dates to the END of the period in
 * which THAT offense was confirmed, never to reset counting).
 */
class OffenseCountingPolicyTest extends TestCase
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
            'term_code' => 'OFFCNT-SEM-A',
            'term_name' => 'Offense Counting Semester A',
            'start_date' => $this->now->copy()->subMonth()->toDateString(),
            'end_date' => $this->now->copy()->addMonth()->toDateString(),
            'status' => 'ACTIVE',
        ]);

        $this->periodB = AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'OFFCNT-SEM-B',
            'term_name' => 'Offense Counting Semester B',
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
            'organizational_unit_id' => $unit->id,
        ]);
    }

    private function inventoryItem(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'OFFCNT-FIX'],
            ['category_name' => 'Offense Counting Fixture', 'active' => true]
        );
        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Offense Counting Fixture Item '.fake()->unique()->numberBetween(1, 99999),
            'total_quantity' => 10,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    private function custodyFor(User $borrower): CustodyTransaction
    {
        $item = $this->inventoryItem();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-OFFCNT-'.fake()->unique()->numberBetween(1000, 9999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $borrower->organizational_unit_id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Offense counting fixture',
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
            'custody_no' => 'CUS-OFFCNT-'.fake()->unique()->numberBetween(1000, 9999),
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

        return $custody;
    }

    private function pendingViolation(User $borrower, ?AcademicPeriod $period, ?CustodyTransaction $custody = null): BorrowerViolation
    {
        $custody ??= $this->custodyFor($borrower);

        return BorrowerViolation::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => $custody->id,
            'academic_period_id' => $period?->id,
            'violation_code' => 'LATE_RETURN',
            'violation_source' => 'LATE_RETURN',
            'details_json' => ['reasons' => ['LATE_RETURN']],
            'status' => 'PENDING_REVIEW',
            'detected_at' => now(),
        ]);
    }

    private function confirm(BorrowerViolation $violation, ?string $effectiveTo = null): ?Sanction
    {
        return app(PolicyService::class)->reviewViolation(
            $violation,
            $this->head,
            'CONFIRMED',
            'Confirmed for offense-counting test.',
            null,
            null,
            $effectiveTo
        );
    }

    /* ------------------------------------------------------------------ */
    /* 1-3. Cumulative counting within the same semester (baseline)        */
    /* ------------------------------------------------------------------ */

    public function test_first_confirmed_offense_in_semester_one_is_offense_one(): void
    {
        $borrower = $this->borrower();

        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodA));

        $this->assertSame(1, $sanction->offense_no);
        $this->assertSame('WRITTEN_REPRIMAND', $sanction->sanction_code);
    }

    public function test_second_confirmed_offense_in_same_semester_is_offense_two(): void
    {
        $borrower = $this->borrower();

        $this->confirm($this->pendingViolation($borrower, $this->periodA));
        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodA));

        $this->assertSame(2, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
    }

    public function test_third_confirmed_offense_in_same_semester_is_offense_three(): void
    {
        $borrower = $this->borrower();

        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodA));

        $this->assertSame(3, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
    }

    /* ------------------------------------------------------------------ */
    /* 4-5. Cross-semester: no reset, 3rd-offense rule keeps applying      */
    /* ------------------------------------------------------------------ */

    public function test_next_semester_violation_does_not_reset_to_offense_one(): void
    {
        $borrower = $this->borrower();

        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());

        // Semester A ends; Semester B begins.
        Carbon::setTestNow($this->periodB->start_date->copy()->addDay());

        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodB));

        $this->assertSame(3, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
    }

    public function test_borrower_already_at_offense_three_who_violates_again_later_stays_under_third_offense_rule(): void
    {
        $borrower = $this->borrower();

        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->confirm($this->pendingViolation($borrower, $this->periodA));

        Carbon::setTestNow($this->periodB->start_date->copy()->addDay());

        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodB));

        // The 4th confirmed offense - the true cumulative count is kept for
        // history/display, but the RULE applied is still the 3rd-offense one.
        $this->assertSame(4, $sanction->offense_no);
        $this->assertSame('BORROWING_SUSPENSION', $sanction->sanction_code);
        $this->assertNotNull($sanction->effective_to);
        $this->assertEqualsWithDelta(
            $this->periodB->end_date->copy()->endOfDay()->timestamp,
            $sanction->effective_to->timestamp,
            1
        );
    }

    /* ------------------------------------------------------------------ */
    /* 6. Cleared/unconfirmed violations never increment the count         */
    /* ------------------------------------------------------------------ */

    public function test_dismissed_violation_does_not_increment_the_count(): void
    {
        $borrower = $this->borrower();

        $dismissed = $this->pendingViolation($borrower, $this->periodA);
        app(PolicyService::class)->reviewViolation($dismissed, $this->head, 'DISMISSED', 'Not a real violation.');

        $this->assertSame('DISMISSED', $dismissed->fresh()->status);

        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodA));

        $this->assertSame(1, $sanction->offense_no);
        $this->assertSame('WRITTEN_REPRIMAND', $sanction->sanction_code);
    }

    /* ------------------------------------------------------------------ */
    /* 7. Same-custody multiple-violation policy is unchanged              */
    /* ------------------------------------------------------------------ */

    public function test_same_custody_policy_pending_behavior_is_unchanged(): void
    {
        SystemSetting::query()->where('setting_key', 'same_custody_multiple_violations_rule')->delete();

        $borrower = $this->borrower();
        $custody = $this->custodyFor($borrower);

        $first = BorrowerViolation::query()->create([
            'borrower_user_id' => $borrower->id,
            'custody_transaction_id' => $custody->id,
            'academic_period_id' => $this->periodA->id,
            'violation_code' => 'BORROWING_VIOLATION',
            'violation_source' => 'PROPERTY_ACCOUNTABILITY',
            'details_json' => ['reasons' => ['DAMAGED']],
            'status' => 'CONFIRMED',
            'detected_at' => now(),
            'reviewed_by_user_id' => $this->head->id,
            'reviewed_at' => now(),
        ]);
        Sanction::query()->create([
            'borrower_violation_id' => $first->id,
            'borrower_user_id' => $borrower->id,
            'academic_period_id' => $this->periodA->id,
            'offense_no' => 1,
            'sanction_code' => 'WRITTEN_REPRIMAND',
            'sanction_label' => 'Written Reprimand',
            'effective_from' => now(),
            'status' => 'ACTIVE',
            'confirmed_by_user_id' => $this->head->id,
            'confirmed_at' => now(),
        ]);

        $second = $this->pendingViolation($borrower, $this->periodA, $custody);

        $result = $this->confirm($second);

        $this->assertNull($result);
        $this->assertSame('CONFIRMED', $second->fresh()->status);
        $this->assertDatabaseMissing('sanctions', ['borrower_violation_id' => $second->id]);
    }

    /* ------------------------------------------------------------------ */
    /* 8. 3rd-offense effective_to still uses the period of confirmation   */
    /* ------------------------------------------------------------------ */

    public function test_third_offense_effective_to_uses_the_period_it_was_confirmed_in(): void
    {
        $borrower = $this->borrower();

        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());
        $this->confirm($this->pendingViolation($borrower, $this->periodA), effectiveTo: $this->now->copy()->addDays(3)->toDateString());

        Carbon::setTestNow($this->periodB->start_date->copy()->addDay());

        $sanction = $this->confirm($this->pendingViolation($borrower, $this->periodB));

        $this->assertSame(3, $sanction->offense_no);
        $this->assertEqualsWithDelta(
            $this->periodB->end_date->copy()->endOfDay()->timestamp,
            $sanction->effective_to->timestamp,
            1
        );
    }
}
