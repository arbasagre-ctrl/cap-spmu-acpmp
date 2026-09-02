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
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\ForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The forecast is a weighted moving average, so every expectation below is a
 * figure worked out by hand from the fixture. A formula that drifts fails here
 * rather than quietly producing a plausible-looking number.
 *
 * Selected period: April 2026 (30 days).
 * History windows are the three 30-day periods before it:
 *
 *   P-1  02 Mar - 31 Mar   weight 3
 *   P-2  31 Jan - 01 Mar   weight 2
 *   P-3  01 Jan - 30 Jan   weight 1
 */
class ForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private ForecastService $forecast;

    private AnalyticsService $analytics;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forecast = app(ForecastService::class);
        $this->analytics = app(AnalyticsService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'FORECAST',
            'unit_name' => 'Forecast Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        /* Inside the selected period, after every history window has closed. */
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function item(string $description, int $total = 500, bool $laundry = false): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'FORECAST'],
            ['category_name' => 'Forecast Category', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => $description,
            'total_quantity' => $total,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => $laundry,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * @param  array<int, array{item: InventoryItem, quantity: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        Carbon $createdAt,
        array $lines = []
    ): BorrowingRequest {
        $borrower = $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::UnderSpmu,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Forecast fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        foreach ($lines as $line) {
            RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $line['item']->id,
                'description_snapshot' => $line['item']->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => $line['quantity'],
                'approved_quantity' => $line['quantity'],
            ]);
        }

        return $request;
    }

    private function ccs(): string
    {
        return 'College of Computer Studies';
    }

    private function saso(): string
    {
        return 'Student Affairs and Services';
    }

    /**
     * 6 requests in P-1, 4 in P-2, 2 in P-3.
     *
     * Weighted average = (3*6 + 2*4 + 1*2) / 6 = 28 / 6 = 4.667 -> 5
     */
    private function seedThreePeriodHistory(): void
    {
        $march = Carbon::create(2026, 3, 10, 10);
        $february = Carbon::create(2026, 2, 10, 10);
        $january = Carbon::create(2026, 1, 10, 10);

        for ($i = 0; $i < 6; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $march->copy()->addHours($i));
        }

        for ($i = 0; $i < 4; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $february->copy()->addHours($i));
        }

        for ($i = 0; $i < 2; $i++) {
            $this->request('ACADEMIC', $this->ccs(), $january->copy()->addHours($i));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Period arithmetic                                                   */
    /* ------------------------------------------------------------------ */

    public function test_forecast_window_is_the_next_period_of_the_same_length(): void
    {
        [$start, $end] = $this->forecast->forecastWindow($this->from, $this->to);

        $this->assertSame('2026-05-01', $start->toDateString());
        $this->assertSame('2026-05-30', $end->toDateString());
    }

    public function test_history_windows_are_the_three_preceding_periods_most_recent_first(): void
    {
        $windows = $this->forecast->historyWindows($this->from, $this->to);

        $this->assertCount(3, $windows);
        $this->assertSame('2026-03-02', $windows[0][0]->toDateString());
        $this->assertSame('2026-03-31', $windows[0][1]->toDateString());
        $this->assertSame('2026-01-31', $windows[1][0]->toDateString());
        $this->assertSame('2026-03-01', $windows[1][1]->toDateString());
        $this->assertSame('2026-01-01', $windows[2][0]->toDateString());
        $this->assertSame('2026-01-30', $windows[2][1]->toDateString());
    }

    public function test_a_week_long_selection_forecasts_the_following_week(): void
    {
        $from = Carbon::create(2026, 4, 6)->startOfDay();
        $to = Carbon::create(2026, 4, 12)->endOfDay();

        [$start, $end] = $this->forecast->forecastWindow($from, $to);

        $this->assertSame('2026-04-13', $start->toDateString());
        $this->assertSame('2026-04-19', $end->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /* The weighted moving average                                         */
    /* ------------------------------------------------------------------ */

    public function test_weighted_average_favours_the_most_recent_period(): void
    {
        /* (3*6 + 2*4 + 1*2) / 6 */
        $this->assertEqualsWithDelta(
            28 / 6,
            $this->forecast->weightedAverage([6, 4, 2]),
            0.0001
        );

        /* Reversing the series must not give the same answer. */
        $this->assertNotEqualsWithDelta(
            28 / 6,
            $this->forecast->weightedAverage([2, 4, 6]),
            0.0001
        );
    }

    public function test_weighted_average_of_an_empty_series_is_zero_not_a_division_by_zero(): void
    {
        $value = $this->forecast->weightedAverage([]);

        $this->assertSame(0.0, $value);
        $this->assertFalse(is_nan($value));
        $this->assertTrue(is_finite($value));
    }

    public function test_rounding_is_half_up_and_never_negative(): void
    {
        $this->assertSame(5, $this->forecast->round(4.5));
        $this->assertSame(4, $this->forecast->round(4.4));
        $this->assertSame(0, $this->forecast->round(-3.0));
        $this->assertSame(0, $this->forecast->round(0.0));
    }

    /* ------------------------------------------------------------------ */
    /* Minimum history                                                     */
    /* ------------------------------------------------------------------ */

    public function test_no_history_reports_insufficient_data_instead_of_a_number(): void
    {
        $demand = $this->forecast->demand($this->analytics, $this->from, $this->to);

        $this->assertFalse($demand['available']);
        $this->assertArrayNotHasKey('forecast', $demand);
        $this->assertStringContainsString('Not enough historical data', $demand['reason']);
        $this->assertStringContainsString('3 completed periods', $demand['requirement']);
    }

    public function test_two_isolated_records_do_not_produce_a_forecast(): void
    {
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 10, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 2, 10, 10));

        $demand = $this->forecast->demand($this->analytics, $this->from, $this->to);

        $this->assertFalse($demand['available'], 'Two observations are below the minimum.');
    }

    public function test_a_history_period_that_has_not_finished_blocks_the_forecast(): void
    {
        $this->seedThreePeriodHistory();

        /* Rewind the clock so P-1 is still running. */
        Carbon::setTestNow(Carbon::create(2026, 3, 15, 9));

        $demand = $this->forecast->demand($this->analytics, $this->from, $this->to);

        $this->assertFalse($demand['available']);
    }

    /* ------------------------------------------------------------------ */
    /* Demand                                                              */
    /* ------------------------------------------------------------------ */

    public function test_demand_forecast_is_the_weighted_average_of_the_three_periods(): void
    {
        $this->seedThreePeriodHistory();

        $demand = $this->forecast->demand($this->analytics, $this->from, $this->to);

        $this->assertTrue($demand['available']);
        $this->assertSame(5, $demand['forecast'], '(3*6 + 2*4 + 1*2) / 6 = 4.667 rounds to 5.');
        $this->assertSame(0, $demand['current'], 'April itself has no fixture requests.');
        $this->assertSame(6, $demand['previous']);
    }

    public function test_history_rows_are_oldest_first_and_carry_their_weights(): void
    {
        $this->seedThreePeriodHistory();

        $rows = $this->forecast->demand($this->analytics, $this->from, $this->to)['history'];

        $this->assertSame([2, 4, 6], array_column($rows, 'count'));
        $this->assertSame([1, 2, 3], array_column($rows, 'weight'));
    }

    public function test_direction_is_similar_within_a_tenth_and_never_divides_by_zero(): void
    {
        $this->assertSame('higher', $this->forecast->direction(31, 24));
        $this->assertSame('lower', $this->forecast->direction(18, 24));
        $this->assertSame('similar', $this->forecast->direction(25, 24));

        /* A previous period of zero must not become an infinite increase. */
        $this->assertSame('higher', $this->forecast->direction(5, 0));
        $this->assertSame('similar', $this->forecast->direction(0, 0));
    }

    /* ------------------------------------------------------------------ */
    /* Divisions and units                                                 */
    /* ------------------------------------------------------------------ */

    public function test_each_canonical_division_is_forecast_separately(): void
    {
        $this->seedThreePeriodHistory();

        /* Research keeps its own series rather than joining another group. */
        $rdso = 'Research and Development Services Office (RDSO)';
        foreach ([3, 2, 1] as $index => $count) {
            $when = [
                Carbon::create(2026, 3, 12, 10),
                Carbon::create(2026, 2, 12, 10),
                Carbon::create(2026, 1, 12, 10),
            ][$index];

            for ($i = 0; $i < $count; $i++) {
                $this->request('RESEARCH_INNOVATION_COLLABORATION', $rdso, $when->copy()->addHours($i));
            }
        }

        $divisions = $this->forecast->divisionDemand($this->analytics, $this->from, $this->to);

        $this->assertTrue($divisions['available']);
        $this->assertSame(
            ['ACADEMIC', 'ADMINISTRATION', 'RESEARCH_INNOVATION_COLLABORATION'],
            array_column($divisions['groups'], 'code')
        );

        $byCode = collect($divisions['groups'])->keyBy('code');

        /* Academic: (3*6 + 2*4 + 1*2)/6 = 4.667 -> 5 */
        $this->assertSame(5, $byCode['ACADEMIC']['forecast']);

        /* Research: (3*3 + 2*2 + 1*1)/6 = 14/6 = 2.333 -> 2 */
        $this->assertSame(2, $byCode['RESEARCH_INNOVATION_COLLABORATION']['forecast']);

        /* Administration recorded nothing and is reported as zero, not dropped. */
        $this->assertSame(0, $byCode['ADMINISTRATION']['forecast']);

        $this->assertSame('ACADEMIC', $divisions['leader']['code']);
    }

    public function test_a_unit_seen_only_once_is_not_forecast(): void
    {
        $this->seedThreePeriodHistory();

        /* One lone Administration request: below UNIT_MINIMUM_OBSERVATIONS. */
        $this->request('ADMINISTRATION', $this->saso(), Carbon::create(2026, 3, 14, 10));

        $units = $this->forecast->unitDemand($this->analytics, $this->from, $this->to);

        $this->assertTrue($units['available']);
        $this->assertSame($this->ccs(), $units['leader']['unit']);
        $this->assertNotContains(
            $this->saso(),
            array_column($units['units'], 'unit'),
            'A unit with a single observation must not be predicted.'
        );
    }

    public function test_unit_forecast_reports_insufficient_history_when_no_unit_qualifies(): void
    {
        /* Three requests spread one per period: enough overall, but no unit
           reaches the per-unit minimum on its own. */
        $this->request('ACADEMIC', 'College of Arts and Sciences', Carbon::create(2026, 3, 10, 10));
        $this->request('ACADEMIC', 'College of Health and Sciences', Carbon::create(2026, 2, 10, 10));
        $this->request('ACADEMIC', 'Graduate School', Carbon::create(2026, 1, 10, 10));

        $units = $this->forecast->unitDemand($this->analytics, $this->from, $this->to);

        $this->assertFalse($units['available']);
        $this->assertSame('Insufficient history', $units['reason']);
    }

    /* ------------------------------------------------------------------ */
    /* Equipment demand against expected availability                      */
    /* ------------------------------------------------------------------ */

    public function test_availability_status_thresholds(): void
    {
        /* Demand met exactly is sufficient. */
        $this->assertSame('Sufficient', $this->forecast->availabilityStatus(10, 10));
        $this->assertSame('Sufficient', $this->forecast->availabilityStatus(10, 12));

        /* A shortfall inside a quarter of demand is limited, not a shortage. */
        $this->assertSame('Limited', $this->forecast->availabilityStatus(4, 3));

        /* A wider gap is escalated. */
        $this->assertSame('Possible Shortage', $this->forecast->availabilityStatus(11, 8));

        /* No demand cannot be a shortage. */
        $this->assertSame('Sufficient', $this->forecast->availabilityStatus(0, 0));
    }

    public function test_equipment_forecast_compares_demand_with_authoritative_availability(): void
    {
        $scarce = $this->item('Multimedia Projector', 2);
        $plentiful = $this->item('Monoblock Chair', 400);

        /* Projector: 4, 2, 0 -> (12 + 4 + 0)/6 = 2.667 -> 3, only 2 in stock.
           Chair:    30, 20, 10 -> (90 + 40 + 10)/6 = 23.33 -> 23, 400 in stock. */
        $plan = [
            [Carbon::create(2026, 3, 10, 10), 4, 30],
            [Carbon::create(2026, 2, 10, 10), 2, 20],
            [Carbon::create(2026, 1, 10, 10), 0, 10],
        ];

        foreach ($plan as [$when, $projectors, $chairs]) {
            $lines = [['item' => $plentiful, 'quantity' => $chairs]];

            if ($projectors > 0) {
                $lines[] = ['item' => $scarce, 'quantity' => $projectors];
            }

            $this->request('ACADEMIC', $this->ccs(), $when, $lines);
        }

        $equipment = $this->forecast->equipment($this->from, $this->to);

        $this->assertTrue($equipment['available']);

        $byName = collect($equipment['items'])->keyBy('name');

        $this->assertSame(3, $byName['Multimedia Projector']['demand']);
        $this->assertSame(2.0, $byName['Multimedia Projector']['expected_available']);
        $this->assertSame('Possible Shortage', $byName['Multimedia Projector']['status']);

        $this->assertSame(23, $byName['Monoblock Chair']['demand']);
        $this->assertSame('Sufficient', $byName['Monoblock Chair']['status']);

        $this->assertSame(1, $equipment['at_risk_count']);
    }

    public function test_equipment_forecast_reports_insufficient_history_without_records(): void
    {
        $equipment = $this->forecast->equipment($this->from, $this->to);

        $this->assertFalse($equipment['available']);
        $this->assertSame([], $equipment['items']);
    }

    public function test_a_future_approved_allocation_is_not_counted_as_available(): void
    {
        $item = $this->item('Sound System', 10);

        foreach ([
            [Carbon::create(2026, 3, 10, 10), 6],
            [Carbon::create(2026, 2, 10, 10), 6],
            [Carbon::create(2026, 1, 10, 10), 6],
        ] as [$when, $quantity]) {
            $this->request('ACADEMIC', $this->ccs(), $when, [['item' => $item, 'quantity' => $quantity]]);
        }

        $before = collect($this->forecast->equipment($this->from, $this->to)['items'])
            ->firstWhere('name', 'Sound System');

        $this->assertSame(10.0, $before['expected_available']);

        /* Reserve 7 units across the forecast window (01-30 May). */
        $requestItem = RequestItem::query()->where('inventory_item_id', $item->id)->firstOrFail();

        Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => Carbon::create(2026, 5, 5)->startOfDay(),
            'period_end' => Carbon::create(2026, 5, 8)->endOfDay(),
            'allocated_quantity' => 7,
            'released_quantity' => 0,
            'restored_quantity' => 0,
            'status' => 'ACTIVE',
            'allocated_at' => Carbon::create(2026, 4, 15),
        ]);

        $after = collect($this->forecast->equipment($this->from, $this->to)['items'])
            ->firstWhere('name', 'Sound System');

        $this->assertSame(
            3.0,
            $after['expected_available'],
            'A reservation inside the forecast window must reduce expected availability exactly once.'
        );
    }

    public function test_linen_still_under_laundry_is_not_expected_to_be_available(): void
    {
        $linen = $this->item('Table Linen', 20, laundry: true);

        foreach ([
            [Carbon::create(2026, 3, 10, 10), 6],
            [Carbon::create(2026, 2, 10, 10), 6],
            [Carbon::create(2026, 1, 10, 10), 6],
        ] as [$when, $quantity]) {
            $this->request('ACADEMIC', $this->ccs(), $when, [['item' => $linen, 'quantity' => $quantity]]);
        }

        $before = collect($this->forecast->equipment($this->from, $this->to)['items'])
            ->firstWhere('name', 'Table Linen');

        $this->assertSame(20.0, $before['expected_available']);

        /* Return 12 units that still require Laundry Operations. */
        $requestItem = RequestItem::query()->where('inventory_item_id', $linen->id)->firstOrFail();
        $version = RequestVersion::query()->findOrFail($requestItem->request_version_id);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => Carbon::create(2026, 3, 11)->startOfDay(),
            'period_end' => Carbon::create(2026, 3, 14)->endOfDay(),
            'allocated_quantity' => 12,
            'released_quantity' => 12,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => Carbon::create(2026, 3, 11),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-LINEN-1',
            'request_id' => $version->request_id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $this->borrower()->id,
            'status' => 'CLOSED',
            'due_at' => Carbon::create(2026, 3, 14)->endOfDay(),
            'released_at' => Carbon::create(2026, 3, 11),
            'closed_at' => Carbon::create(2026, 3, 14),
        ]);

        $custodyLine = CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 12,
            'quantity_to_receive' => 12,
            'actual_released_quantity' => 12,
            'returned_quantity' => 12,
        ]);

        $this->markLinenAsAwaitingLaundry($custodyLine->id, 12);

        $after = collect($this->forecast->equipment($this->from, $this->to)['items'])
            ->firstWhere('name', 'Table Linen');

        $this->assertSame(
            8.0,
            $after['expected_available'],
            'Returned linen stays unavailable until Laundry Operations completes it.'
        );

        $this->assertSame(12.0, $after['laundry_held']);
        $this->assertTrue($after['laundry_required']);
    }

    /**
     * Record returned linen as inspected into Laundry Operations and not yet
     * completed. InventoryService keys this off the return line's LAUNDRY
     * disposition, which is what the Return Inspection workflow writes.
     */
    private function markLinenAsAwaitingLaundry(int $custodyLineId, int $quantity): void
    {
        $line = CustodyLine::query()->findOrFail($custodyLineId);
        $receivedAt = Carbon::create(2026, 3, 14);

        $returnTransactionId = DB::table('return_transactions')->insertGetId([
            'return_no' => 'RT-LINEN-'.$custodyLineId,
            'custody_transaction_id' => $line->custody_transaction_id,
            'received_by_user_id' => $this->borrower()->id,
            'return_type' => 'NORMAL',
            'received_at' => $receivedAt,
            'status' => 'INSPECTED',
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);

        DB::table('return_lines')->insert([
            'return_transaction_id' => $returnTransactionId,
            'custody_line_id' => $custodyLineId,
            'quantity_received' => $quantity,
            'condition_code' => 'SERVICEABLE',
            'disposition_state' => 'LAUNDRY',
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Busy period                                                         */
    /* ------------------------------------------------------------------ */

    public function test_busy_period_follows_the_shape_of_previous_periods(): void
    {
        /* Concentrate history in the third week of each 30-day window so the
           same slice is expected to peak next period. */
        foreach ([
            Carbon::create(2026, 3, 2),
            Carbon::create(2026, 1, 31),
            Carbon::create(2026, 1, 1),
        ] as $windowStart) {
            for ($i = 0; $i < 4; $i++) {
                $this->request(
                    'ACADEMIC',
                    $this->ccs(),
                    $windowStart->copy()->addDays(15)->addHours($i)
                );
            }
        }

        $busy = $this->forecast->busyPeriod($this->analytics, $this->from, $this->to);

        $this->assertTrue($busy['available']);
        $this->assertNotEmpty($busy['buckets']);

        $levels = array_column($busy['buckets'], 'level');
        $this->assertContains('High', $levels);
        $this->assertSame('Week 3', $busy['busiest']['label']);
        $this->assertStringContainsString('Week 3', $busy['summary']);
    }

    public function test_busy_period_is_unavailable_without_a_forecast(): void
    {
        $busy = $this->forecast->busyPeriod($this->analytics, $this->from, $this->to);

        $this->assertFalse($busy['available']);
    }

    /* ------------------------------------------------------------------ */
    /* Transparency                                                        */
    /* ------------------------------------------------------------------ */

    public function test_forecast_basis_states_the_method_without_claiming_a_model(): void
    {
        $basis = $this->forecast->basis();

        $text = $basis['summary'].' '.implode(' ', $basis['details']);

        $this->assertStringContainsString('weighted average', $text);
        $this->assertStringContainsString('3 periods before', $text);

        /* No model is trained here, so the wording must not imply one. */
        foreach (['machine learning', 'artificial intelligence', 'neural', 'trained model'] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $text);
        }
    }
}
