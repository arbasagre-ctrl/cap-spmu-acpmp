<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\CustodyLine;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * completedReturnRecords() is loaded once per scope per request.
 *
 * Every return figure on a page - the KPIs, the previous-period comparison,
 * the trend, both late-rate breakdowns - reads the same window. The service
 * memoises the classified records per exact scope for its own lifetime, so
 * the second reader costs no queries; a different period, division, unit or
 * borrower is a different scope and is loaded on its own.
 */
class AnalyticsCompletedReturnMemoisationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'MEMO',
            'unit_name' => 'Memoisation Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_same_scope_is_loaded_once_and_reused_by_every_reader(): void
    {
        $this->seedReturns();
        $analytics = app(AnalyticsService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = $analytics->lateReturnRates($this->from, $this->to, null, null, null, 'division');
        $windowLoad = $this->windowQueries();
        $this->assertNotEmpty($windowLoad, 'the first reader loads the records');

        /* Same scope again, and every other reader of the same window: the window is not queried again. */
        DB::flushQueryLog();
        $second = $analytics->lateReturnRates($this->from, $this->to, null, null, null, 'division');
        $analytics->lateReturnRates($this->from, $this->to, null, null, null, 'unit');
        $analytics->returnTrend($this->from, $this->to, null, null, 'month');
        $this->assertSame([], $this->windowQueries());

        $this->assertSame($first, $second);
        $this->assertSame(4, $first['completed']);

        /* returns() still runs its own present-tense and accountability queries, but never reloads the window. */
        DB::flushQueryLog();
        $returns = $analytics->returns($this->from, $this->to, null, null);
        $this->assertSame(
            [],
            array_values(array_intersect($this->windowQueries(), $windowLoad)),
            'the completed-return window is not rebuilt for returns()'
        );
        $this->assertSame(4, $returns['completed']);

        DB::disableQueryLog();
    }

    public function test_each_distinct_scope_is_its_own_entry(): void
    {
        [$alice, $bob] = $this->seedReturns();
        $analytics = app(AnalyticsService::class);

        $april = $analytics->lateReturnRates($this->from, $this->to, null, null, null, 'division');
        $march = $analytics->lateReturnRates(Carbon::create(2026, 3, 1)->startOfDay(), Carbon::create(2026, 3, 31)->endOfDay(), null, null, null, 'division');
        $academic = $analytics->lateReturnRates($this->from, $this->to, 'ACADEMIC', null, null, 'division');
        $hrmo = $analytics->lateReturnRates($this->from, $this->to, 'ADMINISTRATION', $this->hrmo(), null, 'division');
        $aliceOnly = $analytics->lateReturnRates($this->from, $this->to, null, null, $alice->id, 'division');
        $bobOnly = $analytics->lateReturnRates($this->from, $this->to, null, null, $bob->id, 'division');

        $this->assertSame(4, $april['completed']);
        $this->assertSame(1, $march['completed']);
        $this->assertSame(3, $academic['completed']);
        $this->assertSame(1, $hrmo['completed']);
        $this->assertSame(2, $aliceOnly['completed']);
        $this->assertSame(2, $bobOnly['completed']);

        /* "all" and null mean the same scope and share one entry. */
        DB::flushQueryLog();
        DB::enableQueryLog();
        $analytics->lateReturnRates($this->from, $this->to, 'all', 'all', null, 'division');
        $this->assertSame([], $this->windowQueries());
        DB::disableQueryLog();

        /* A fresh instance sees exactly the same figures: memoisation changes cost, not results. */
        $fresh = app(AnalyticsService::class);
        $this->assertSame($april['groups'], $fresh->lateReturnRates($this->from, $this->to, null, null, null, 'division')['groups']);
        $this->assertSame($aliceOnly['groups'], $fresh->lateReturnRates($this->from, $this->to, null, null, $alice->id, 'division')['groups']);
    }

    /**
     * The queries that build the completed-return window: everything read
     * from the custody, return and laundry tables. The division label
     * catalogue (organizational_units) is a separate, deliberately
     * unmemoised master-data read and is not part of what this test measures.
     *
     * @return list<string>
     */
    private function windowQueries(): array
    {
        return collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'custody_transactions')
                || str_contains($sql, 'return_transactions')
                || str_contains($sql, 'custody_lines')
                || str_contains($sql, 'laundry_jobs')
                || str_contains($sql, 'request_versions'))
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array{0: User, 1: User} */
    private function seedReturns(): array
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        /* April: Alice x2 Academic (1 late), Bob Academic on time, Bob Administration late. March: one. */
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 14), $alice);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 8), $alice);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 8), $bob);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 15), $bob, 'ADMINISTRATION', $this->hrmo());
        $this->completedReturn(Carbon::create(2026, 3, 10), Carbon::create(2026, 3, 8), $alice, closedAt: Carbon::create(2026, 3, 9, 10));

        return [$alice, $bob];
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function hrmo(): string
    {
        return 'Human Resource Management Office';
    }

    private function item(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(['category_code' => 'MEMO'], ['category_name' => 'Memoisation Fixture', 'active' => true]);
        $measure = UnitOfMeasure::query()->firstOrCreate(['unit_code' => 'PC'], ['unit_name' => 'Piece', 'active' => true]);

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => 'Memoisation Chair'],
            [
                'category_id' => $category->id, 'unit_id' => $measure->id, 'total_quantity' => 500,
                'condition_code' => 'SERVICEABLE', 'borrowable' => true, 'off_campus_allowed' => false,
                'laundry_required' => false, 'provisional' => false, 'active' => true,
            ]
        );
    }

    private function completedReturn(
        Carbon $dueAt,
        Carbon $receivedAt,
        User $borrower,
        string $division = 'ACADEMIC',
        string $unit = 'College of Computer Studies',
        ?Carbon $closedAt = new Carbon('2026-04-16 10:00:00')
    ): CustodyTransaction {
        $item = $this->item();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 9999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id, 'version_no' => 1, 'purpose_event' => 'Memoisation fixture', 'location' => 'Campus',
            'division_code' => $division, 'office_unit' => $unit,
            'schedule_date' => $dueAt->copy()->subDays(3)->toDateString(), 'return_date' => $dueAt->toDateString(),
            'needed_from' => $dueAt->copy()->subDays(3)->startOfDay(), 'return_due_at' => $dueAt->copy()->endOfDay(),
            'submitted_at' => $dueAt->copy()->subDays(5),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 9999999),
            'request_id' => $request->id, 'request_version_id' => $version->id, 'borrower_user_id' => $borrower->id,
            'status' => 'CLOSED', 'due_at' => $dueAt->copy()->endOfDay(), 'released_at' => $dueAt->copy()->subDays(3), 'closed_at' => $closedAt,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id, 'inventory_item_id' => $item->id, 'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece', 'requested_quantity' => 1, 'approved_quantity' => 1,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id, 'period_start' => $dueAt->copy()->subDays(3)->startOfDay(), 'period_end' => $dueAt->copy()->endOfDay(),
            'allocated_quantity' => 1, 'released_quantity' => 1, 'restored_quantity' => 0, 'status' => 'RELEASED', 'allocated_at' => $dueAt->copy()->subDays(5),
        ]);

        $line = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id, 'request_item_id' => $requestItem->id, 'allocation_id' => $allocation->id,
            'approved_quantity' => 1, 'quantity_to_receive' => 1, 'actual_released_quantity' => 1, 'returned_quantity' => 1,
        ]);

        $return = ReturnTransaction::query()->create([
            'return_no' => 'RT-'.fake()->unique()->numberBetween(100000, 9999999), 'custody_transaction_id' => $custody->id,
            'received_by_user_id' => User::factory()->create(['access_classification' => AccessClassification::SpmuHead])->id,
            'return_type' => 'NORMAL', 'received_at' => $receivedAt, 'status' => 'INSPECTED',
        ]);

        ReturnLine::query()->create([
            'return_transaction_id' => $return->id, 'custody_line_id' => $line->id, 'quantity_received' => 1,
            'condition_code' => 'FINE', 'disposition_state' => 'RETURNED',
        ]);

        return $custody;
    }
}
