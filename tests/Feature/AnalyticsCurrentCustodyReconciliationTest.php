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
use App\Models\LaundryJob;
use App\Models\OrganizationalUnit;
use App\Models\RequestItem;
use App\Models\RequestVersion;
use App\Models\ReturnLine;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\AnalyticsDetailService;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The Currently Out and Currently Overdue details open from their KPIs, so
 * they must list exactly the custody the KPI counted - one population, read
 * from one place. Physical custody decides membership: a custody that has
 * fully come back is out of both, whatever administrative state
 * accountability leaves it in; property still outstanding keeps it in.
 *
 * Today is 20 April 2026; the selected period is April.
 */
class AnalyticsCurrentCustodyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $analytics;

    private AnalyticsDetailService $details;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(AnalyticsService::class);
        $this->details = app(AnalyticsDetailService::class);

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'CCR',
            'unit_name' => 'Custody Reconciliation Fixture Unit',
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

    /* ------------------------------------------------------------------ */
    /* Physically returned custody is out of both, whatever its status     */
    /* ------------------------------------------------------------------ */

    public function test_a_returned_late_custody_held_obligation_open_is_in_neither_kpi_nor_detail(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12), status: 'OBLIGATION_OPEN', closedAt: null);

        $this->assertReconciled(out: 0, overdue: 0);
        $this->assertSame(1, $this->analytics->returns($this->from, $this->to, null, null)['late']);
    }

    public function test_a_returned_on_time_custody_held_incident_open_is_in_neither_kpi_nor_detail(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 15), receivedAt: Carbon::create(2026, 4, 12), status: 'INCIDENT_OPEN', closedAt: null);

        $this->assertReconciled(out: 0, overdue: 0);
        $this->assertSame(1, $this->analytics->returns($this->from, $this->to, null, null)['on_time']);
    }

    /* ------------------------------------------------------------------ */
    /* Property still outstanding stays in both                            */
    /* ------------------------------------------------------------------ */

    public function test_a_partial_return_held_obligation_open_stays_out_and_overdue_when_past_due(): void
    {
        $partial = $this->custody(dueAt: Carbon::create(2026, 4, 10), status: 'OBLIGATION_OPEN', quantity: 3, custodyNo: 'CUS-PARTIAL');
        $partial->lines()->update(['returned_quantity' => 2]);

        $this->assertReconciled(out: 1, overdue: 1);
        $this->assertSame(['CUS-PARTIAL'], $this->detailCustodyNumbers('follow-up'));
        $this->assertSame(['CUS-PARTIAL'], $this->detailCustodyNumbers('currently-out'));
    }

    public function test_linen_stays_out_and_overdue_until_laundry_physically_receives_it(): void
    {
        $linen = $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12),
            status: 'RETURN_PROCESSING', closedAt: null, laundry: true, custodyNo: 'CUS-LINEN'
        );

        $this->assertReconciled(out: 1, overdue: 1);
        $this->assertSame(['CUS-LINEN'], $this->detailCustodyNumbers('follow-up'));

        LaundryJob::query()->create([
            'custody_transaction_id' => $linen->id,
            'status' => 'TURNED_OVER_TO_LAUNDRY',
            'worker_received_at' => Carbon::create(2026, 4, 14, 10),
        ]);

        /* Physical receipt happens in a later request, with a fresh per-instance return cache. */
        $this->analytics = app(AnalyticsService::class);

        $this->assertReconciled(out: 0, overdue: 0);
        $this->assertSame(1, $this->analytics->returns($this->from, $this->to, null, null)['late']);
    }

    public function test_a_future_due_outstanding_custody_is_out_but_not_overdue(): void
    {
        $this->custody(dueAt: Carbon::create(2026, 4, 25), custodyNo: 'CUS-FUTURE');

        $this->assertReconciled(out: 1, overdue: 0);
        $this->assertSame(['CUS-FUTURE'], $this->detailCustodyNumbers('currently-out'));
        $this->assertSame([], $this->detailCustodyNumbers('follow-up'));
    }

    /* ------------------------------------------------------------------ */
    /* KPI == detail under every filter, and Aging follows                  */
    /* ------------------------------------------------------------------ */

    public function test_kpi_and_detail_agree_under_every_filter_and_overdue_aging_follows(): void
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        /* Mixed backlog: outstanding past due, outstanding future, partial, linen awaiting, plus returned-but-open noise. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10), borrower: $alice, custodyNo: 'CUS-A1');
        $this->custody(dueAt: Carbon::create(2026, 4, 25), borrower: $alice, custodyNo: 'CUS-A2');
        $partial = $this->custody(dueAt: Carbon::create(2026, 3, 1), status: 'OBLIGATION_OPEN', quantity: 2, borrower: $bob, division: 'ADMINISTRATION', unit: $this->hrmo(), custodyNo: 'CUS-B1');
        $partial->lines()->update(['returned_quantity' => 1]);
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12), status: 'RETURN_PROCESSING', closedAt: null, laundry: true, borrower: $bob, custodyNo: 'CUS-B2');
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12), status: 'OBLIGATION_OPEN', closedAt: null, borrower: $alice, custodyNo: 'CUS-A3');
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 15), receivedAt: Carbon::create(2026, 4, 12), status: 'INCIDENT_OPEN', closedAt: null, borrower: $bob, division: 'ADMINISTRATION', unit: $this->hrmo(), custodyNo: 'CUS-B3');

        foreach ([
            [null, null, null, 4, 3],
            ['ACADEMIC', null, null, 3, 2],
            ['ADMINISTRATION', $this->hrmo(), null, 1, 1],
            [null, null, $alice->id, 2, 1],
            [null, null, $bob->id, 2, 2],
        ] as [$division, $unit, $borrower, $out, $overdue]) {
            $this->assertReconciled($out, $overdue, $division, $unit, $borrower);

            $aging = $this->analytics->overdueAging($division, $unit, $borrower);
            $this->assertSame($overdue, $aging['total'], "aging $division/$unit/$borrower");
            $this->assertSame($overdue, array_sum(array_column($aging['groups'], 'count')) + $aging['unbanded']);
        }

        /* The returned-but-open custodies never surface in any listing. */
        $listed = $this->detailCustodyNumbers('currently-out');
        $this->assertNotContains('CUS-A3', $listed);
        $this->assertNotContains('CUS-B3', $listed);
        $this->assertSame(['CUS-A1', 'CUS-A2', 'CUS-B1', 'CUS-B2'], $this->sorted($listed));
        $this->assertSame(['CUS-A1', 'CUS-B1', 'CUS-B2'], $this->sorted($this->detailCustodyNumbers('follow-up')));
    }

    public function test_the_returns_detail_overdue_state_lists_the_same_population(): void
    {
        $this->custody(dueAt: Carbon::create(2026, 4, 10), custodyNo: 'CUS-OUT');
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12), status: 'OBLIGATION_OPEN', closedAt: null, custodyNo: 'CUS-BACK');

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'returns', 'state' => 'overdue',
        ]));

        $page->assertOk();
        $page->assertSee('CUS-OUT');
        $page->assertDontSee('CUS-BACK');

        $overview = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview', 'academic_period' => 'month', 'detail' => 'currently-out',
        ]));

        $overview->assertOk();
        $overview->assertSee('CUS-OUT');
        $overview->assertDontSee('CUS-BACK');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function assertReconciled(int $out, int $overdue, ?string $division = null, ?string $unit = null, ?int $borrower = null): void
    {
        $scope = "$division/$unit/$borrower";

        $overview = $this->analytics->overview($this->from, $this->to, $division, $unit, $borrower);
        $this->assertSame($out, $overview['on_custody'], "KPI Currently Out $scope");
        $this->assertSame($overdue, $overview['needs_follow_up'], "KPI Currently Overdue $scope");
        $this->assertSame($overdue, $this->analytics->returns($this->from, $this->to, $division, $unit, $borrower)['overdue']);

        $this->assertSame($out, $this->detail('currently-out', $division, $unit, $borrower)['value'], "detail Currently Out $scope");
        $this->assertSame($overdue, $this->detail('follow-up', $division, $unit, $borrower)['value'], "detail Currently Overdue $scope");
        $this->assertSame($overdue, $this->detail('returns', $division, $unit, $borrower, ['state' => 'overdue'])['value'], "returns/overdue detail $scope");

        $this->assertSame($out, count($this->detailCustodyNumbers('currently-out', $division, $unit, $borrower)));
        $this->assertSame($overdue, count($this->detailCustodyNumbers('follow-up', $division, $unit, $borrower)));
    }

    /** @return array<string, mixed> */
    private function detail(string $type, ?string $division = null, ?string $unit = null, ?int $borrower = null, array $params = []): array
    {
        $detail = $this->details->resolve(
            $type,
            Request::create('/analytics', 'GET', $params),
            $this->from,
            $this->to,
            $division,
            $unit,
            'month',
            $borrower
        );

        $this->assertNotNull($detail, "$type detail resolves");

        return $detail;
    }

    /** @return list<string> */
    private function detailCustodyNumbers(string $type, ?string $division = null, ?string $unit = null, ?int $borrower = null): array
    {
        $table = $this->detail($type, $division, $unit, $borrower)['table'];

        if ($table === null) {
            return [];
        }

        $column = array_search('Custody No.', $table['columns'], true);

        return array_map(static fn (array $row): string => $row[$column], $table['rows']);
    }

    /** @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function spmuHead(): User
    {
        return User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
    }

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function ccs(): string
    {
        return 'College of Computer Studies';
    }

    private function hrmo(): string
    {
        return 'Human Resource Management Office';
    }

    private function item(bool $laundry = false): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'CCR'],
            ['category_name' => 'Custody Reconciliation Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => $laundry ? 'Reconciliation Linen' : 'Reconciliation Chair'],
            [
                'category_id' => $category->id,
                'unit_id' => $measure->id,
                'total_quantity' => 500,
                'condition_code' => 'SERVICEABLE',
                'borrowable' => true,
                'off_campus_allowed' => false,
                'laundry_required' => $laundry,
                'provisional' => false,
                'active' => true,
            ]
        );
    }

    private function custody(
        Carbon $dueAt,
        string $status = 'ACTIVE',
        ?Carbon $closedAt = null,
        ?Carbon $releasedAt = new Carbon('2026-03-01 10:00:00'),
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?User $borrower = null,
        ?string $custodyNo = null,
        int $quantity = 1,
        bool $laundry = false
    ): CustodyTransaction {
        $borrower ??= $this->borrower();
        $item = $this->item($laundry);

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 9999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Custody reconciliation fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit ?? $this->ccs(),
            'schedule_date' => $dueAt->copy()->subDays(3)->toDateString(),
            'return_date' => $dueAt->toDateString(),
            'needed_from' => $dueAt->copy()->subDays(3)->startOfDay(),
            'return_due_at' => $dueAt->copy()->endOfDay(),
            'submitted_at' => Carbon::create(2026, 4, 2, 9),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => $custodyNo ?? 'CUS-'.fake()->unique()->numberBetween(100000, 9999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $status,
            'due_at' => $dueAt->copy()->endOfDay(),
            'released_at' => $releasedAt,
            'closed_at' => $closedAt,
        ]);

        $requestItem = RequestItem::query()->create([
            'request_version_id' => $version->id,
            'inventory_item_id' => $item->id,
            'description_snapshot' => $item->unique_description,
            'unit_snapshot' => 'Piece',
            'requested_quantity' => $quantity,
            'approved_quantity' => $quantity,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $dueAt->copy()->subDays(3)->startOfDay(),
            'period_end' => $dueAt->copy()->endOfDay(),
            'allocated_quantity' => $quantity,
            'released_quantity' => $releasedAt ? $quantity : 0,
            'restored_quantity' => 0,
            'status' => $releasedAt ? 'RELEASED' : 'ACTIVE',
            'allocated_at' => $dueAt->copy()->subDays(10),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $quantity,
            'quantity_to_receive' => $quantity,
            'actual_released_quantity' => $releasedAt ? $quantity : 0,
            'returned_quantity' => $closedAt ? $quantity : 0,
        ]);

        return $custody;
    }

    private function completedReturn(
        Carbon $dueAt,
        Carbon $receivedAt,
        string $status = 'CLOSED',
        ?Carbon $closedAt = new Carbon('2026-04-13 10:00:00'),
        bool $laundry = false,
        ?User $borrower = null,
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?string $custodyNo = null
    ): CustodyTransaction {
        $custody = $this->custody(
            dueAt: $dueAt, status: $status, closedAt: $closedAt, division: $division, unit: $unit,
            borrower: $borrower, custodyNo: $custodyNo, laundry: $laundry
        );
        $custody->lines()->update(['returned_quantity' => 1]);

        $return = ReturnTransaction::query()->create([
            'return_no' => 'RT-'.fake()->unique()->numberBetween(100000, 9999999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $this->spmuHead()->id,
            'return_type' => 'NORMAL',
            'received_at' => $receivedAt,
            'status' => 'INSPECTED',
        ]);

        ReturnLine::query()->create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $custody->lines()->first()->id,
            'quantity_received' => 1,
            'condition_code' => 'FINE',
            'disposition_state' => $laundry ? 'LAUNDRY' : 'RETURNED',
        ]);

        return $custody;
    }
}
