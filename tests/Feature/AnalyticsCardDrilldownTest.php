<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Every Analytics card opens a real detail.
 *
 * The promise this guards is "no dead clicks": a card that offers a drill-down
 * must resolve to a panel with a title and an explanation, including when the
 * underlying reading does not exist. A card whose data is empty still has to
 * say why it is empty rather than opening a blank drawer.
 */
class AnalyticsCardDrilldownTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every card key wired into the five Analytics tabs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function cardKeys(): array
    {
        $keys = [
            'overview' => [
                'overview.trend', 'overview.insights', 'overview.released',
                'overview.units', 'overview.snapshot',
            ],
            'demand' => [
                'demand.requested-quantity', 'demand.released-quantity', 'demand.active-units',
                'demand.trend', 'demand.division', 'demand.requested-items', 'demand.units',
                'demand.released-items', 'demand.low-usage', 'demand.peak',
            ],
            'inventory' => [
                'inventory.available', 'inventory.reserved', 'inventory.custody',
                'inventory.attention', 'inventory.availability', 'inventory.distribution',
                'inventory.low-availability', 'inventory.utilization', 'inventory.operational',
                'inventory.coverage',
            ],
            'returns' => [
                'returns.trend', 'returns.outcome', 'returns.lifecycle', 'returns.followup',
                'returns.condition', 'returns.summary', 'returns.issues',
            ],
            'predictive' => [
                'forecast.readiness', 'forecast.scheduled', 'forecast.outlook',
                'forecast.division', 'forecast.unit', 'forecast.equipment',
                'forecast.busy', 'forecast.notes', 'forecast.methodology',
            ],
        ];

        $cases = [];

        foreach ($keys as $section => $group) {
            foreach ($group as $key) {
                $cases[$section.' / '.$key] = [$section, $key];
            }
        }

        return $cases;
    }

    /* The Analytics route authorises on the access classification alone. */
    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cardKeys')]
    public function test_every_card_key_opens_a_detail_with_content(string $section, string $key): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => $section,
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => $key,
        ]));

        $response->assertOk();

        /* A resolved panel, not a silently dropped detail. */
        $response->assertSee('data-analytics-detail-panel', false);

        /* The fallback is a real failure: it means the key was never wired. */
        $response->assertDontSee('Detail unavailable');
    }


    /**
     * Record-backed card details should expose the matching Reports dataset,
     * already generated and carrying the card's scope.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function recordBackedCardSources(): array
    {
        return [
            'overview trend' => ['overview', 'overview.trend', 'report=borrowing'],
            'return compliance' => ['overview', 'overview.return-compliance', 'return_status=COMPLETED'],
            'released quantity' => ['demand', 'demand.released-quantity', 'report=utilization'],
            'available inventory' => ['inventory', 'inventory.available', 'availability_status=AVAILABLE'],
            'low availability' => ['inventory', 'inventory.low-availability', 'availability_status=LOW_AVAILABILITY'],
            'return outcome' => ['returns', 'returns.outcome', 'return_status=COMPLETED'],
            'current overdue' => ['returns', 'returns.followup', 'return_status=CURRENTLY_OVERDUE'],
            'return summary' => ['returns', 'returns.summary', 'return_status=COMPLETED'],
            'return issues' => ['returns', 'returns.issues', 'report=returns'],
            /*
             * Condition is only ever recorded on a completed physical return,
             * so COMPLETED is the exact population the breakdown is summed
             * from.
             */
            'return condition' => ['returns', 'returns.condition', 'return_status=COMPLETED'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recordBackedCardSources')]
    public function test_record_backed_card_details_offer_the_matching_source_records(
        string $section,
        string $key,
        string $expectedQuery
    ): void {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => $section,
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => $key,
        ]));

        $response->assertOk();
        $response->assertSee('View source records', false);
        $response->assertSee($expectedQuery, false);
        $response->assertSee('generated=1', false);
    }

    /**
     * Derived/composite readings deliberately do not pretend there is one
     * exact raw-record dataset behind them.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function derivedCardsWithoutSingleSource(): array
    {
        return [
            'priority insights' => ['overview', 'overview.insights'],
            'inventory coverage estimate' => ['inventory', 'inventory.coverage'],
            'forecast outlook' => ['predictive', 'forecast.outlook'],
            'forecast methodology' => ['predictive', 'forecast.methodology'],
            /*
             * Awaiting/preparing release live in Borrowing Activity Report, on
             * custody belongs to Release & Custody, and returned belongs to
             * Return & Accountability - no single report reproduces the whole
             * lifecycle strip, so none is offered.
             */
            'borrowing lifecycle' => ['returns', 'returns.lifecycle'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('derivedCardsWithoutSingleSource')]
    public function test_derived_cards_without_one_exact_dataset_do_not_show_a_source_records_button(
        string $section,
        string $key
    ): void {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => $section,
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => $key,
        ]));

        $response->assertOk();
        $response->assertDontSee('View source records', false);
    }

    public function test_an_unknown_card_key_still_answers_rather_than_vanishing(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'overview',
                'detail' => 'card',
                'for' => 'not-a-real-card',
            ]))
            ->assertOk()
            ->assertSee('data-analytics-detail-panel', false)
            ->assertSee('Detail unavailable');
    }

    public function test_a_card_detail_keeps_the_selected_filters(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns',
            'academic_period' => 'week',
            'group' => 'ACADEMIC',
            'detail' => 'card',
            'for' => 'returns.summary',
        ]));

        $response->assertOk();

        /* The close link returns to the same tab and filters it was opened from. */
        $response->assertSee('section=returns', false);
        $response->assertSee('academic_period=week', false);
        $response->assertSee('group=ACADEMIC', false);
    }

    public function test_empty_readings_explain_themselves_instead_of_opening_blank(): void
    {
        /*
         * With no completed returns the summary cannot produce a rate. The
         * drawer must still say so - "not measurable" is a reading, 0% is not.
         */
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'returns',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'returns.summary',
            ]))
            ->assertOk()
            ->assertSee('Not measurable');
    }

    public function test_overdue_and_returned_late_stay_separate_in_the_drawer(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'returns',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'returns.followup',
            ]))
            ->assertOk()
            ->assertSee('Out past the due date right now', false);
    }

    public function test_scheduled_demand_is_not_described_as_a_forecast(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', [
                'section' => 'predictive',
                'academic_period' => 'month',
                'detail' => 'card',
                'for' => 'forecast.scheduled',
            ]))
            ->assertOk()
            ->assertSee('requests already filed', false);
    }

    /**
     * Inventory Availability reconciliation.
     *
     * The KPI row, the "Inventory Availability" card detail and the Inventory
     * Status Report all describe the same physical stock, so all three must
     * agree on the same number. AnalyticsCardDetailService::inventoryState()
     * used to read $inventory['available'] instead of the nested
     * $inventory['totals']['available'] the KPI cards actually use, which
     * silently produced zero for every facet regardless of true stock.
     */
    public function test_inventory_availability_reconciles_across_kpi_detail_and_report(): void
    {
        $category = InventoryCategory::query()->create([
            'category_code' => 'DRILL',
            'category_name' => 'Drilldown Fixture',
            'active' => true,
        ]);

        $measure = UnitOfMeasure::query()->create([
            'unit_code' => 'PC-DRILL',
            'unit_name' => 'Piece',
            'active' => true,
        ]);

        InventoryItem::query()->create([
            'category_id' => $category->id,
            'unit_id' => $measure->id,
            'unique_description' => 'Reconciliation Fixture Chair',
            'total_quantity' => 47,
            'condition_code' => 'SERVICEABLE',
            'borrowable' => true,
            'off_campus_allowed' => false,
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);

        $user = $this->spmuHead();

        $kpi = $this->actingAs($user)->get(route('analytics.index', [
            'section' => 'inventory',
            'academic_period' => 'month',
        ]));

        $kpi->assertOk();
        $kpi->assertSee('47', false);

        $detail = $this->actingAs($user)->get(route('analytics.index', [
            'section' => 'inventory',
            'academic_period' => 'month',
            'detail' => 'card',
            'for' => 'inventory.availability',
        ]));

        $detail->assertOk();
        $detail->assertSee('Available', false);
        $detail->assertSee('47', false);

        /*
         * Inventory is a present-tense snapshot (AnalyticsService::inventory()
         * and InventoryStatusReport both ignore the reporting period), so the
         * exact window here does not matter - only that both sides agree.
         */
        $report = app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET'),
                'inventory',
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
                'month'
            )
        );

        $this->assertSame(47, $report->summary['Available to allocate']);
    }
}
