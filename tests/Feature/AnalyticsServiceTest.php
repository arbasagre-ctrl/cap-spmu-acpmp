<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
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
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analytics against a dataset dense enough to rank, tie and trend.
 *
 * Every expectation is written as a literal figure derived by hand from the
 * fixture below, so a formula that drifts fails here rather than quietly
 * producing a plausible-looking number.
 */
class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $analytics;

    private OrganizationalUnit $unit;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(AnalyticsService::class);

        /* borrowing_requests.accountable_unit_id is NOT NULL. */
        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'FIXTURE',
            'unit_name' => 'Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        /* A fixed window keeps the fixture independent of the wall clock. */
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
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function borrower(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
        ]);
    }

    private function item(string $description, int $total = 500): InventoryItem
    {
        /* category_id and unit_id are both NOT NULL on inventory_items. */
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
     * One filed request, optionally released into custody.
     *
     * @param  array<int, array{item: InventoryItem, released: int, returned?: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        Carbon $createdAt,
        RequestStatus $status = RequestStatus::UnderSpmu,
        array $lines = [],
        ?array $custody = null
    ): BorrowingRequest {
        $borrower = $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$createdAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 99999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        /* created_at is what the reporting period filters on. */
        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            /* Legacy timestamp columns are NOT NULL and mirror the dates. */
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        if ($custody === null) {
            return $request;
        }

        /* due_at is NOT NULL, so the lifecycle dates go in on insert. */
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

            /* custody_lines.allocation_id is NOT NULL: a released line always
               traces back to the allocation that reserved the quantity. */
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

    /**
     * Twelve requests across three divisions and eight units.
     *
     * Academic       10 requests  CCS 4, CEA 3, CAS 2, CHS 1
     * Administration  5 requests  SASO 3, Registrar 1, HR 1
     * Research        3 requests  RDSO 2, ECSO 1
     * Total          18 requests
     */
    private function seedRepresentativeDataset(): void
    {
        $ccs = 'College of Computer Studies';
        $cea = 'College of Engineering and Architecture';
        $cas = 'College of Arts and Sciences';
        $chs = 'College of Health and Sciences';
        $saso = 'Student Affairs and Services';
        $registrar = "Registrar's Office";
        $hr = 'Human Resource Management Office';
        $rdso = 'Research and Development Services Office (RDSO)';
        $ecso = 'Extension and Community Services Office (ECSO)';

        $april = fn (int $day, int $hour = 10): Carbon => Carbon::create(2026, 4, $day, $hour);

        /* Week 1 (1-7): 5 requests */
        $this->request('ACADEMIC', $ccs, $april(2), RequestStatus::ApprovedReadyForRelease);
        $this->request('ACADEMIC', $ccs, $april(3));
        $this->request('ACADEMIC', $cea, $april(5), RequestStatus::ApprovedReadyForRelease);
        $this->request('ADMINISTRATION', $saso, $april(6));
        $this->request('RESEARCH_INNOVATION_COLLABORATION', $rdso, $april(7));

        /* Week 2 (8-14): 8 requests - the peak */
        $this->request('ACADEMIC', $ccs, $april(8), RequestStatus::ApprovedReadyForRelease);
        $this->request('ACADEMIC', $ccs, $april(9));
        $this->request('ACADEMIC', $cea, $april(10), RequestStatus::FinalApprovedAwaitingDownload);
        $this->request('ACADEMIC', $cea, $april(11));
        $this->request('ACADEMIC', $cas, $april(12));
        $this->request('ACADEMIC', $cas, $april(13));
        $this->request('ADMINISTRATION', $saso, $april(13));
        $this->request('ADMINISTRATION', $registrar, $april(14));

        /* Week 3 (15-21): deliberately empty - an interior gap. */

        /* Week 4 (22-28): 5 requests */
        $this->request('ACADEMIC', $chs, $april(22));
        $this->request('ADMINISTRATION', $saso, $april(23));
        $this->request('ADMINISTRATION', $hr, $april(24));
        $this->request('RESEARCH_INNOVATION_COLLABORATION', $rdso, $april(25));
        $this->request('RESEARCH_INNOVATION_COLLABORATION', $ecso, $april(26));

        /* Week 5 (29-30): deliberately empty - trailing. */
    }

    /* ------------------------------------------------------------------ */
    /* Overview                                                            */
    /* ------------------------------------------------------------------ */

    public function test_overview_counts_requests_approvals_custody_and_follow_up(): void
    {
        $this->seedRepresentativeDataset();

        $chairs = $this->item('Monoblock Chairs');

        /* On custody, still within its due date. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 15), RequestStatus::ApprovedReadyForRelease,
            [['item' => $chairs, 'released' => 10]],
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, 16), 'due_at' => Carbon::create(2026, 4, 25)]
        );

        /* On custody and past its due date. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 16), RequestStatus::ApprovedReadyForRelease,
            [['item' => $chairs, 'released' => 5]],
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, 17), 'due_at' => Carbon::create(2026, 4, 18)]
        );

        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        /* 18 fixture requests + 2 custody requests. */
        $this->assertSame(20, $overview['total']);
        /* 4 approved in the fixture + the 2 custody requests. */
        $this->assertSame(6, $overview['approved']);
        $this->assertSame(2, $overview['on_custody']);
        $this->assertSame(1, $overview['needs_follow_up']);
    }

    public function test_overview_is_zero_safe_without_any_request(): void
    {
        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        $this->assertSame(0, $overview['total']);
        $this->assertSame(0, $overview['approved']);
        $this->assertSame('No borrowing requests were filed during this period.', $overview['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Divisions                                                           */
    /* ------------------------------------------------------------------ */

    public function test_divisions_are_counted_and_percentaged_separately(): void
    {
        $this->seedRepresentativeDataset();

        $groups = $this->analytics->borrowerGroups($this->from, $this->to, null, null);

        $this->assertSame(18, $groups['total']);

        $byLabel = $groups['groups']->keyBy('label');

        $this->assertSame(10, $byLabel['Academic']['count']);
        $this->assertSame(5, $byLabel['Administrative']['count']);
        $this->assertSame(3, $byLabel['Research & Innovation']['count']);

        /* 10/18 = 55.6 -> 56, 5/18 = 27.8 -> 28, 3/18 = 16.7 -> 17. */
        $this->assertSame(56.0, (float) $byLabel['Academic']['percentage']);
        $this->assertSame(28.0, (float) $byLabel['Administrative']['percentage']);
        $this->assertSame(17.0, (float) $byLabel['Research & Innovation']['percentage']);

        $this->assertStringContainsString('Academic', $groups['summary']);
    }

    public function test_percentages_stay_zero_when_no_request_exists(): void
    {
        $groups = $this->analytics->borrowerGroups($this->from, $this->to, null, null);

        $this->assertSame(0, $groups['total']);
        $this->assertTrue($groups['groups']->isEmpty());
        $this->assertStringNotContainsString('%', $groups['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Unit rankings                                                       */
    /* ------------------------------------------------------------------ */

    public function test_units_are_ranked_within_their_own_division(): void
    {
        $this->seedRepresentativeDataset();

        $columns = $this->analytics->unitRankings($this->from, $this->to, null, null)['columns']->keyBy('code');

        $academic = collect($columns['ACADEMIC']['units']);
        $this->assertSame('College of Computer Studies', $academic[0]['name']);
        $this->assertSame(4, $academic[0]['count']);
        $this->assertSame(3, $academic[1]['count']);
        $this->assertSame(2, $academic[2]['count']);
        $this->assertSame(1, $academic[3]['count']);

        $administrative = collect($columns['ADMINISTRATION']['units']);
        $this->assertSame('Student Affairs and Services', $administrative[0]['name']);
        $this->assertSame(3, $administrative[0]['count']);

        /* A tie: Registrar and HR both have one request. */
        $this->assertSame(1, $administrative[1]['count']);
        $this->assertSame(1, $administrative[2]['count']);

        $research = collect($columns['RESEARCH_INNOVATION_COLLABORATION']['units']);
        $this->assertSame('Research and Development Services Office (RDSO)', $research[0]['name']);
        $this->assertSame(2, $research[0]['count']);

        /* No academic unit may appear in the administrative column. */
        $this->assertEmpty(
            $administrative->pluck('name')->intersect($academic->pluck('name'))
        );
    }

    public function test_bar_share_is_relative_to_the_leader_of_the_same_column(): void
    {
        $this->seedRepresentativeDataset();

        $columns = $this->analytics->unitRankings($this->from, $this->to, null, null)['columns']->keyBy('code');

        /* The leader always fills its own column, however small its count. */
        $this->assertSame(100.0, (float) collect($columns['ACADEMIC']['units'])[0]['share']);
        $this->assertSame(100.0, (float) collect($columns['ADMINISTRATION']['units'])[0]['share']);
        /* CEA 3 of CCS 4 = 75. */
        $this->assertSame(75.0, (float) collect($columns['ACADEMIC']['units'])[1]['share']);
    }

    /* ------------------------------------------------------------------ */
    /* Filters                                                             */
    /* ------------------------------------------------------------------ */

    public function test_division_filter_isolates_its_own_requests(): void
    {
        $this->seedRepresentativeDataset();

        $this->assertSame(10, $this->analytics->overview($this->from, $this->to, 'ACADEMIC', null)['total']);
        $this->assertSame(5, $this->analytics->overview($this->from, $this->to, 'ADMINISTRATION', null)['total']);
        $this->assertSame(3, $this->analytics->overview($this->from, $this->to, 'RESEARCH_INNOVATION_COLLABORATION', null)['total']);
    }

    public function test_unit_filter_isolates_a_single_unit(): void
    {
        $this->seedRepresentativeDataset();

        $this->assertSame(
            4,
            $this->analytics->overview($this->from, $this->to, 'ACADEMIC', 'College of Computer Studies')['total']
        );

        /* A unit that belongs to another division yields nothing, never a leak. */
        $this->assertSame(
            0,
            $this->analytics->overview($this->from, $this->to, 'ACADEMIC', 'Student Affairs and Services')['total']
        );
    }

    public function test_unit_options_are_grouped_by_division(): void
    {
        $this->seedRepresentativeDataset();

        $options = $this->analytics->unitOptions($this->from, $this->to);

        $this->assertCount(4, $options['ACADEMIC']);
        $this->assertCount(3, $options['ADMINISTRATION']);
        $this->assertCount(2, $options['RESEARCH_INNOVATION_COLLABORATION']);
        $this->assertNotContains('Student Affairs and Services', $options['ACADEMIC']);
    }

    /* ------------------------------------------------------------------ */
    /* Asset utilisation                                                   */
    /* ------------------------------------------------------------------ */

    public function test_units_released_sums_quantity_not_request_count(): void
    {
        $chairs = $this->item('Monoblock Chairs', 1000);
        $tables = $this->item('Folding Tables');
        $projector = $this->item('Projector', 5);

        $release = fn (array $lines, int $day) => $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            Carbon::create(2026, 4, $day),
            RequestStatus::ApprovedReadyForRelease,
            $lines,
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, $day + 1), 'due_at' => Carbon::create(2026, 4, 28)]
        );

        /* Two separate releases of the same asset must add up. */
        $release([['item' => $chairs, 'released' => 100]], 2);
        $release([['item' => $chairs, 'released' => 250]], 4);
        $release([['item' => $tables, 'released' => 45]], 6);
        /* Three single-unit releases of one projector. */
        $release([['item' => $projector, 'released' => 1]], 8);
        $release([['item' => $projector, 'released' => 1]], 9);
        $release([['item' => $projector, 'released' => 1]], 10);

        $equipment = $this->analytics->equipment($this->from, $this->to, null, null);

        $this->assertSame('Units released', $equipment['metric']);

        $ranked = collect($equipment['items'])->keyBy('name');

        /* 100 + 250 = 350, well ahead of a request count of 2. */
        $this->assertSame(350.0, (float) $ranked['Monoblock Chairs']['released']);
        $this->assertSame(45.0, (float) $ranked['Folding Tables']['released']);
        $this->assertSame(3.0, (float) $ranked['Projector']['released']);

        $this->assertSame('Monoblock Chairs', $equipment['items'][0]['name']);
        $this->assertSame('Folding Tables', $equipment['items'][1]['name']);
        $this->assertSame('Projector', $equipment['items'][2]['name']);
    }

    public function test_equipment_ranking_follows_the_unit_filter(): void
    {
        $chairs = $this->item('Monoblock Chairs', 1000);
        $tables = $this->item('Folding Tables');

        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 2), RequestStatus::ApprovedReadyForRelease,
            [['item' => $chairs, 'released' => 300]],
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, 3), 'due_at' => Carbon::create(2026, 4, 28)]
        );

        $this->request('ADMINISTRATION', 'Student Affairs and Services', Carbon::create(2026, 4, 4), RequestStatus::ApprovedReadyForRelease,
            [['item' => $tables, 'released' => 20]],
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, 5), 'due_at' => Carbon::create(2026, 4, 28)]
        );

        $academic = $this->analytics->equipment($this->from, $this->to, 'ACADEMIC', null);
        $this->assertCount(1, $academic['items']);
        $this->assertSame('Monoblock Chairs', $academic['items'][0]['name']);

        $administrative = $this->analytics->equipment($this->from, $this->to, 'ADMINISTRATION', null);
        $this->assertCount(1, $administrative['items']);
        $this->assertSame('Folding Tables', $administrative['items'][0]['name']);
    }

    /* ------------------------------------------------------------------ */
    /* Trend                                                               */
    /* ------------------------------------------------------------------ */

    public function test_trend_keeps_interior_gaps_and_trims_trailing_empty_buckets(): void
    {
        $this->seedRepresentativeDataset();

        $trend = $this->analytics->trend($this->from, $this->to, null, null, 'month');

        /* Weeks 1-4 are plotted; week 5 is empty and trailing, so it is not. */
        $this->assertCount(4, $trend['points']);

        $counts = collect($trend['points'])->pluck('count')->all();
        $this->assertSame([5, 8, 0, 5], $counts);

        /* The quiet third week stays visible between two busy ones. */
        $this->assertSame(0, $trend['points'][2]['count']);

        $this->assertSame('week', $trend['granularity']);
        $this->assertStringContainsString('Week 2', $trend['summary']);
    }

    public function test_trend_groups_by_day_for_a_week_and_by_month_for_longer_scopes(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 2));

        $week = $this->analytics->trend(
            Carbon::create(2026, 3, 30)->startOfDay(),
            Carbon::create(2026, 4, 5)->endOfDay(),
            null,
            null,
            'week'
        );
        $this->assertSame('day', $week['granularity']);

        $year = $this->analytics->trend(
            Carbon::create(2026, 1, 1)->startOfDay(),
            Carbon::create(2026, 12, 31)->endOfDay(),
            null,
            null,
            'academic_year'
        );
        $this->assertSame('month', $year['granularity']);
        /* Activity is in April, so January through April are plotted. */
        $this->assertCount(4, $year['points']);
        $this->assertSame(1, $year['points'][3]['count']);
    }

    public function test_trend_reports_no_activity_without_inventing_a_peak(): void
    {
        $trend = $this->analytics->trend($this->from, $this->to, null, null, 'month');

        $this->assertSame('No borrowing requests were filed during this period.', $trend['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Returns and accountability                                          */
    /* ------------------------------------------------------------------ */

    public function test_returns_separate_on_time_late_overdue_and_open_cases(): void
    {
        $chairs = $this->item('Monoblock Chairs');
        $line = [['item' => $chairs, 'released' => 5]];

        /* Closed before the due date. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 2), RequestStatus::ApprovedReadyForRelease, $line,
            ['status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 3), 'due_at' => Carbon::create(2026, 4, 10), 'closed_at' => Carbon::create(2026, 4, 9)]
        );

        /* Closed exactly on the due date still counts as on time. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 3), RequestStatus::ApprovedReadyForRelease, $line,
            ['status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 4), 'due_at' => Carbon::create(2026, 4, 11), 'closed_at' => Carbon::create(2026, 4, 11)]
        );

        /* Closed after the due date. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 4), RequestStatus::ApprovedReadyForRelease, $line,
            ['status' => 'CLOSED', 'released_at' => Carbon::create(2026, 4, 5), 'due_at' => Carbon::create(2026, 4, 8), 'closed_at' => Carbon::create(2026, 4, 12)]
        );

        /* Out now and past due. */
        $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 5), RequestStatus::ApprovedReadyForRelease, $line,
            ['status' => 'OVERDUE', 'released_at' => Carbon::create(2026, 4, 6), 'due_at' => Carbon::create(2026, 4, 12)]
        );

        /* Out now and still within its due date: neither late nor overdue. */
        $withCustody = $this->request('ACADEMIC', 'College of Computer Studies', Carbon::create(2026, 4, 6), RequestStatus::ApprovedReadyForRelease, $line,
            ['status' => 'ACTIVE', 'released_at' => Carbon::create(2026, 4, 7), 'due_at' => Carbon::create(2026, 4, 28)]
        );

        $custodyId = CustodyTransaction::query()->where('request_id', $withCustody->id)->value('id');

        Incident::query()->create([
            'incident_no' => 'PC-FIXTURE-OPEN',
            'custody_transaction_id' => $custodyId,
            'borrower_user_id' => $withCustody->borrower_user_id,
            'reported_by_user_id' => $withCustody->borrower_user_id,
            'incident_type' => 'DAMAGE',
            'status' => 'OPEN',
            'reported_at' => Carbon::create(2026, 4, 8),
        ]);

        Incident::query()->create([
            'incident_no' => 'PC-FIXTURE-DONE',
            'custody_transaction_id' => $custodyId,
            'borrower_user_id' => $withCustody->borrower_user_id,
            'reported_by_user_id' => $withCustody->borrower_user_id,
            'incident_type' => 'DAMAGE',
            'status' => 'RESOLVED',
            'reported_at' => Carbon::create(2026, 4, 8),
        ]);

        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame(2, $returns['on_time']);
        $this->assertSame(1, $returns['late']);
        $this->assertSame(1, $returns['overdue']);
        /* Only the unresolved incident counts. */
        $this->assertSame(1, $returns['open_cases']);
        $this->assertTrue($returns['has_data']);
    }

    public function test_returns_report_no_data_without_any_custody(): void
    {
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertFalse($returns['has_data']);
        $this->assertSame('No completed returns are available for this period yet.', $returns['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Key insights                                                        */
    /* ------------------------------------------------------------------ */

    public function test_insights_are_capped_deduplicated_and_never_repeat_a_section_reading(): void
    {
        $this->seedRepresentativeDataset();

        $overview = $this->analytics->overview($this->from, $this->to, null, null);
        $groups = $this->analytics->borrowerGroups($this->from, $this->to, null, null);
        $units = $this->analytics->unitRankings($this->from, $this->to, null, null);
        $equipment = $this->analytics->equipment($this->from, $this->to, null, null);
        $trend = $this->analytics->trend($this->from, $this->to, null, null, 'month');
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $insights = $this->analytics->insights($overview, $groups, $units, $equipment, $trend, $returns);

        $this->assertLessThanOrEqual(5, count($insights));
        $this->assertSame(count($insights), count(array_unique($insights)));

        foreach ([$overview['summary'], $groups['summary'], $equipment['summary'], $trend['summary'], $returns['summary']] as $reading) {
            $this->assertNotContains($reading, $insights);
        }

        foreach ($units['summary'] as $reading) {
            $this->assertNotContains($reading, $insights);
        }
    }

    public function test_insights_are_empty_when_nothing_happened(): void
    {
        $overview = $this->analytics->overview($this->from, $this->to, null, null);
        $groups = $this->analytics->borrowerGroups($this->from, $this->to, null, null);
        $units = $this->analytics->unitRankings($this->from, $this->to, null, null);
        $equipment = $this->analytics->equipment($this->from, $this->to, null, null);
        $trend = $this->analytics->trend($this->from, $this->to, null, null, 'month');
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame([], $this->analytics->insights($overview, $groups, $units, $equipment, $trend, $returns));
    }

    /* ------------------------------------------------------------------ */
    /* Reporting period                                                    */
    /* ------------------------------------------------------------------ */

    public function test_semester_and_academic_year_use_the_active_academic_period(): void
    {
        AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'FIRST',
            'term_name' => 'First Semester',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-20',
            'status' => 'ACTIVE',
        ]);

        AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'SECOND',
            'term_name' => 'Second Semester',
            'start_date' => '2027-01-10',
            'end_date' => '2027-05-30',
            'status' => 'PLANNED',
        ]);

        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $semester = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('analytics.index', ['academic_period' => 'semester']));

        $semester->assertOk()->assertSee('01 Aug 2026')->assertSee('20 Dec 2026');

        $year = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('analytics.index', ['academic_period' => 'academic_year']));

        /* The academic year spans both terms of 2026-2027. */
        $year->assertOk()->assertSee('01 Aug 2026')->assertSee('30 May 2027');
    }

    public function test_semester_falls_back_to_the_month_without_an_active_period(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('analytics.index', ['academic_period' => 'semester']));

        $response->assertOk()
            ->assertSee(Carbon::now()->startOfMonth()->format('d M Y'))
            ->assertSee(Carbon::now()->endOfMonth()->format('d M Y'));
    }

    /* ------------------------------------------------------------------ */
    /* Controller filter handling                                          */
    /* ------------------------------------------------------------------ */

    public function test_a_unit_from_another_division_resets_to_all_units(): void
    {
        Carbon::setTestNow();
        $this->seedRepresentativeDataset();

        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        /* SASO is administrative; asking for it under Academic must reset. */
        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('analytics.index', [
                'group' => 'ACADEMIC',
                'unit' => 'Student Affairs and Services',
            ]));

        $response->assertOk();
        $this->assertSame('all', $response->viewData('selectedUnit'));
        $this->assertSame('ACADEMIC', $response->viewData('selectedDivision'));
    }

    public function test_an_unknown_group_resets_to_all_groups(): void
    {
        $head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        $response = $this->withSession(['active_workspace' => 'SPMU'])
            ->actingAs($head)
            ->get(route('analytics.index', ['group' => 'NOT_A_DIVISION']));

        $response->assertOk();
        $this->assertSame('all', $response->viewData('selectedDivision'));
    }
}
