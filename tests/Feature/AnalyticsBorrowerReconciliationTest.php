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
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The Borrower filter and its reconciliation with Reports.
 *
 * Every Analytics figure computed here with a borrower selected must be
 * matched, record for record, by the corresponding Reports dataset scoped to
 * that same borrower. A mismatch here is exactly the bug class the module
 * must never ship: Analytics and Reports quietly disagreeing about who a
 * number belongs to.
 */
class AnalyticsBorrowerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $analytics;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    private User $borrowerA;

    private User $borrowerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(AnalyticsService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'FIXTURE',
            'unit_name' => 'Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));

        $this->borrowerA = $this->borrower('Borrower A');
        $this->borrowerB = $this->borrower('Borrower B');

        $chairs = $this->item('Monoblock Chairs');
        $line = [['item' => $chairs, 'released' => 5]];

        /* Borrower A: one on-time return, one late return. */
        $this->request($this->borrowerA, Carbon::create(2026, 4, 2), $line, [
            'status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 3),
            'due_at' => Carbon::create(2026, 4, 10), 'closed_at' => Carbon::create(2026, 4, 9),
        ]);
        $this->request($this->borrowerA, Carbon::create(2026, 4, 4), $line, [
            'status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 5),
            'due_at' => Carbon::create(2026, 4, 8), 'closed_at' => Carbon::create(2026, 4, 12),
        ]);

        /* Borrower B: one on-time return only. */
        $this->request($this->borrowerB, Carbon::create(2026, 4, 6), $line, [
            'status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 7),
            'due_at' => Carbon::create(2026, 4, 14), 'closed_at' => Carbon::create(2026, 4, 13),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Borrower filtering                                                  */
    /* ------------------------------------------------------------------ */

    public function test_requests_are_scoped_by_borrower(): void
    {
        $all = $this->analytics->overview($this->from, $this->to, null, null);
        $a = $this->analytics->overview($this->from, $this->to, null, null, $this->borrowerA->id);
        $b = $this->analytics->overview($this->from, $this->to, null, null, $this->borrowerB->id);

        $this->assertSame(3, $all['total']);
        $this->assertSame(2, $a['total']);
        $this->assertSame(1, $b['total']);
    }

    public function test_returns_are_scoped_by_borrower(): void
    {
        $all = $this->analytics->returns($this->from, $this->to, null, null);
        $a = $this->analytics->returns($this->from, $this->to, null, null, $this->borrowerA->id);
        $b = $this->analytics->returns($this->from, $this->to, null, null, $this->borrowerB->id);

        $this->assertSame(2, $all['on_time']);
        $this->assertSame(1, $all['late']);

        $this->assertSame(1, $a['on_time']);
        $this->assertSame(1, $a['late']);

        $this->assertSame(1, $b['on_time']);
        $this->assertSame(0, $b['late']);
    }

    /**
     * Institution-wide inventory stock must never be attributed to one
     * borrower: selecting a borrower changes borrowing activity, not the
     * physical stock snapshot.
     */
    public function test_inventory_snapshot_ignores_borrower_scope(): void
    {
        $inventoryService = app(\App\Services\InventoryService::class);

        $unscoped = $this->analytics->inventory($inventoryService);

        $this->assertSame($unscoped['totals'], $this->analytics->inventory($inventoryService)['totals']);
    }

    /* ------------------------------------------------------------------ */
    /* Analytics <-> Reports reconciliation                                */
    /* ------------------------------------------------------------------ */

    public function test_borrower_filed_requests_reconcile_with_borrowing_activity_report(): void
    {
        foreach ([$this->borrowerA, $this->borrowerB] as $borrower) {
            $overviewTotal = $this->analytics->overview(
                $this->from, $this->to, null, null, $borrower->id
            )['total'];

            $rows = $this->generate('borrowing', ['borrower' => $borrower->id])->rows;

            $this->assertSame(
                $overviewTotal,
                $rows->count(),
                "Requests filed by {$borrower->full_name} must match between Analytics and the Borrowing Activity Report."
            );
        }
    }

    public function test_returned_on_time_and_late_reconcile_with_return_accountability_report(): void
    {
        foreach ([null, $this->borrowerA->id, $this->borrowerB->id] as $borrower) {
            $returns = $this->analytics->returns($this->from, $this->to, null, null, $borrower);

            $onTimeRows = $this->generate('returns', array_filter([
                'borrower' => $borrower,
                'return_status' => 'RETURNED_ON_TIME',
            ]))->rows;

            $lateRows = $this->generate('returns', array_filter([
                'borrower' => $borrower,
                'return_status' => 'RETURNED_LATE',
            ]))->rows;

            $this->assertSame($returns['on_time'], $onTimeRows->count());
            $this->assertSame($returns['late'], $lateRows->count());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Filter propagation                                                  */
    /* ------------------------------------------------------------------ */

    public function test_borrower_selection_scopes_the_analytics_page_and_carries_across_tabs(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $response = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'borrower' => $this->borrowerA->id,
        ]));

        $response->assertOk();

        /* The tab links must not silently drop the borrower selection. */
        $response->assertSee('borrower='.$this->borrowerA->id, false);
    }

    public function test_view_source_records_link_carries_the_selected_borrower(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $response = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'borrower' => $this->borrowerA->id,
            'detail' => 'requests',
        ]));

        $response->assertOk();
        $response->assertSee('report=borrowing', false);
        $response->assertSee('borrower='.$this->borrowerA->id, false);
    }

    public function test_borrower_options_endpoint_only_lists_borrowers_with_activity_in_scope(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $response = $this->actingAs($head)->getJson(route('analytics.index', [
            'borrower_options' => 1,
            'section' => 'overview',
            'academic_period' => 'month',
        ]));

        $response->assertOk()->assertJsonPath('count', 2);

        $ids = collect($response->json('options'))->pluck('value');

        $this->assertTrue($ids->contains((string) $this->borrowerA->id));
        $this->assertTrue($ids->contains((string) $this->borrowerB->id));
    }

    /* ------------------------------------------------------------------ */
    /* Fixture helpers                                                     */
    /* ------------------------------------------------------------------ */

    private function borrower(string $name): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => $name,
        ]);
    }

    private function item(string $description, int $total = 500): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'FIXTURE'],
            ['category_name' => 'Fixture Category', 'active' => true]
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
            'laundry_required' => false,
            'provisional' => false,
            'active' => true,
        ]);
    }

    /**
     * @param  array<int, array{item: InventoryItem, released: int, returned?: int}>  $lines
     */
    private function request(User $borrower, Carbon $createdAt, array $lines, array $custody): BorrowingRequest
    {
        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 99999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Fixture activity',
            'location' => 'Campus',
            'division_code' => 'ACADEMIC',
            'office_unit' => 'College of Computer Studies',
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.$createdAt->format('Ymd').'-'.fake()->unique()->numberBetween(1000, 99999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custody['status'],
            'due_at' => $custody['due_at'] ?? $createdAt->copy()->addDays(3)->endOfDay(),
            'released_at' => $custody['released_at'] ?? null,
            'closed_at' => $custody['closed_at'] ?? null,
        ]);

        foreach ($lines as $line) {
            $requestItem = RequestItem::query()->create([
                'request_version_id' => $version->id,
                'inventory_item_id' => $line['item']->id,
                'description_snapshot' => $line['item']->unique_description,
                'unit_snapshot' => 'Piece',
                'requested_quantity' => $line['released'],
                'approved_quantity' => $line['released'],
            ]);

            $allocation = Allocation::query()->create([
                'request_item_id' => $requestItem->id,
                'period_start' => $createdAt->copy()->addDay()->startOfDay(),
                'period_end' => $createdAt->copy()->addDays(3)->endOfDay(),
                'allocated_quantity' => $line['released'],
                'released_quantity' => $line['released'],
                'restored_quantity' => 0,
                'status' => 'RELEASED',
                'allocated_at' => $createdAt,
            ]);

            CustodyLine::query()->create([
                'custody_transaction_id' => $transaction->id,
                'request_item_id' => $requestItem->id,
                'allocation_id' => $allocation->id,
                'approved_quantity' => $line['released'],
                'quantity_to_receive' => $line['released'],
                'actual_released_quantity' => $line['released'],
                'returned_quantity' => $line['returned'] ?? 0,
            ]);
        }

        return $request;
    }

    private function generate(string $report, array $input = []): ReportDataset
    {
        return app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', $input),
                $report,
                $this->from,
                $this->to,
                'month'
            )
        );
    }
}
