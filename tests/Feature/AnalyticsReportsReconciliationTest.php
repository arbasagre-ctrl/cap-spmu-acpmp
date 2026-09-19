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
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * "View source records" opens the records the figure was counted from.
 *
 * Each test renders the Analytics detail, takes the Reports link exactly as
 * the page emits it, runs the Reports module with those parameters, and
 * compares the record list with the Analytics population - under the whole
 * scope, a division, a unit and a borrower. A link that carries the wrong
 * filter, or a report that scopes differently, fails here.
 */
class AnalyticsReportsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationalUnit $unit;

    private AnalyticsService $analytics;

    private Carbon $from;

    private Carbon $to;

    private User $head;

    /** @var array<string, DOMXPath> */
    private array $parsed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'RECON',
            'unit_name' => 'Reports Reconciliation Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->analytics = app(AnalyticsService::class);
        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();
        $this->head = User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);

        /* academic_period=month is the calendar month of "today" on both modules. */
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Request Outcomes                                                    */
    /* ------------------------------------------------------------------ */

    public function test_rejected_and_revision_outcome_links_open_exactly_the_analytics_group(): void
    {
        $alice = $this->borrower('Alice Reconciler');
        $bob = $this->borrower('Bob Reconciler');

        /* Rejected: two Academic/CCS by Alice, one Administration/HRMO by Bob. */
        $this->request(RequestStatus::Rejected, borrower: $alice);
        $this->request(RequestStatus::Rejected, borrower: $alice);
        $this->request(RequestStatus::Rejected, division: 'ADMINISTRATION', unit: $this->hrmo(), borrower: $bob);
        /* Returned for revision: one Academic/CCS by Bob, one Academic/CED by Alice. */
        $this->request(RequestStatus::ReturnedForRevision, borrower: $bob);
        $this->request(RequestStatus::ReturnedForRevision, unit: $this->ced(), borrower: $alice);
        /* Noise the links must not pick up: other outcomes, and a rejection filed outside the period. */
        $this->request(RequestStatus::ApprovedReadyForRelease, borrower: $alice);
        $this->request(RequestStatus::Cancelled, borrower: $bob);
        $this->request(RequestStatus::Rejected, submittedAt: Carbon::create(2026, 3, 12, 10), borrower: $alice);

        /* The Analytics page names its division filter "group"; the Reports link must translate it to "division". */
        $scopes = [
            'whole' => ['page' => [], 'division' => null, 'unit' => null, 'borrower' => null],
            'division' => ['page' => ['group' => 'ACADEMIC'], 'division' => 'ACADEMIC', 'unit' => null, 'borrower' => null],
            'unit' => ['page' => ['group' => 'ACADEMIC', 'unit' => $this->ccs()], 'division' => 'ACADEMIC', 'unit' => $this->ccs(), 'borrower' => null],
            'borrower' => ['page' => ['borrower' => (string) $alice->id], 'division' => null, 'unit' => null, 'borrower' => (string) $alice->id],
        ];

        $expected = [
            'rejected' => ['whole' => 3, 'division' => 2, 'unit' => 2, 'borrower' => 2],
            'revision' => ['whole' => 2, 'division' => 2, 'unit' => 1, 'borrower' => 1],
        ];

        $status = ['rejected' => RequestStatus::Rejected->value, 'revision' => RequestStatus::ReturnedForRevision->value];

        foreach ($scopes as $scopeName => $scope) {
            foreach (['rejected', 'revision'] as $group) {
                $outcomes = $this->analytics->requestOutcomes(
                    $this->from,
                    $this->to,
                    $scope['division'],
                    $scope['unit'],
                    $scope['borrower'] === null ? null : (int) $scope['borrower']
                );
                $count = collect($outcomes['groups'])->firstWhere('key', $group)['count'];
                $this->assertSame($expected[$group][$scopeName], $count, "$group under $scopeName scope");

                $analyticsRequests = $this->analytics
                    ->requestOutcomeScope(
                        $this->from,
                        $this->to,
                        $scope['division'],
                        $scope['unit'],
                        $scope['borrower'] === null ? null : (int) $scope['borrower']
                    )
                    ->where('borrowing_requests.status', $status[$group])
                    ->pluck('borrowing_requests.request_no')
                    ->sort()
                    ->values()
                    ->all();

                $href = $this->sourceLink(array_merge(
                    ['section' => 'demand', 'academic_period' => 'month', 'detail' => 'outcomes', 'outcome' => $group],
                    $scope['page']
                ));
                $parameters = $this->linkParameters($href);

                $this->assertSame('borrowing', $parameters['report'], "$group under $scopeName opens the Borrowing Activity Report");
                $this->assertSame($status[$group], $parameters['status'] ?? null, "$group under $scopeName carries its status");
                $this->assertSame($scope['division'], $parameters['division'] ?? null, "$group under $scopeName carries the division");
                $this->assertSame($scope['unit'], $parameters['unit'] ?? null, "$group under $scopeName carries the unit");
                $this->assertSame($scope['borrower'], $parameters['borrower'] ?? null, "$group under $scopeName carries the borrower");

                $rows = $this->report($parameters)->rows;

                $this->assertCount($count, $rows, "Reports lists as many $group requests as Analytics counts under $scopeName scope");
                $this->assertSame(
                    $analyticsRequests,
                    $rows->pluck('request_no')->sort()->values()->all(),
                    "Reports lists the same $group requests as Analytics under $scopeName scope"
                );
                $this->assertSame(
                    [$status[$group]],
                    $rows->pluck('_status_code')->unique()->values()->all(),
                    "every listed row is $group"
                );
            }
        }
    }

    public function test_an_outcome_group_detail_keeps_the_division_filter_and_closes_back_to_it(): void
    {
        /*
         * The page's division filter is the "group" parameter; the outcome
         * group is "outcome". They must never share a name, or opening one
         * outcome on a filtered tab silently widens the figure to every
         * division and closing the detail drops the filter.
         */
        $this->request(RequestStatus::Rejected);
        $this->request(RequestStatus::Rejected, division: 'ADMINISTRATION', unit: $this->hrmo());

        $page = $this->actingAs($this->head)->get(route('analytics.index', [
            'section' => 'demand', 'academic_period' => 'month', 'group' => 'ADMINISTRATION',
            'detail' => 'outcomes', 'outcome' => 'rejected',
        ]));
        $page->assertOk();

        $x = $this->xpath($page->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);
        $this->assertNotNull($panel);

        $this->assertSame(1, $x->query('.//table//tbody/tr', $panel)->length, 'only the filtered division\'s rejection is listed');

        $close = $x->query('.//a[@aria-label="Close detail"]', $panel)->item(0);
        $this->assertNotNull($close);
        $closeParameters = $this->linkParameters($close->getAttribute('href'));
        $this->assertSame('ADMINISTRATION', $closeParameters['group'] ?? null, 'closing keeps the division filter');
        $this->assertArrayNotHasKey('outcome', $closeParameters);
        $this->assertArrayNotHasKey('detail', $closeParameters);

        $source = $this->linkParameters($x->query('.//footer//a', $panel)->item(0)->getAttribute('href'));
        $this->assertSame('ADMINISTRATION', $source['division'] ?? null);
        $this->assertCount(1, $this->report($source)->rows);
    }

    /* ------------------------------------------------------------------ */
    /* Late Return Rate                                                    */
    /* ------------------------------------------------------------------ */

    public function test_late_rate_segment_links_open_the_late_subset_and_not_the_denominator(): void
    {
        $alice = $this->borrower('Alice Returner');
        $bob = $this->borrower('Bob Returner');

        /* Academic/CCS: 4 completed, 2 late (Alice late x1 and on time x1, Bob late x1 and on time x1). */
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 14), $alice);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 8), $alice);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 15), $bob);
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 9), $bob);
        /* Academic/CED: 2 completed, 1 late (Alice). */
        $this->completedReturn(Carbon::create(2026, 4, 12), Carbon::create(2026, 4, 16), $alice, unit: $this->ced());
        $this->completedReturn(Carbon::create(2026, 4, 12), Carbon::create(2026, 4, 11), $bob, unit: $this->ced());
        /* Administration/HRMO: 3 completed, 1 late (Bob). */
        $this->completedReturn(Carbon::create(2026, 4, 6), Carbon::create(2026, 4, 9), $bob, 'ADMINISTRATION', $this->hrmo());
        $this->completedReturn(Carbon::create(2026, 4, 6), Carbon::create(2026, 4, 5), $bob, 'ADMINISTRATION', $this->hrmo());
        $this->completedReturn(Carbon::create(2026, 4, 6), Carbon::create(2026, 4, 6), $alice, 'ADMINISTRATION', $this->hrmo());
        /* A late return completed in March is not in April's rate and must not be in April's list. */
        $this->completedReturn(Carbon::create(2026, 3, 10), Carbon::create(2026, 3, 14), $alice, closedAt: Carbon::create(2026, 3, 15, 10));

        $cases = [
            'whole, division level' => [
                'query' => ['level' => 'division'],
                'completed' => 9, 'late' => 4, 'division' => null, 'unit' => null, 'borrower' => null,
            ],
            'Academic segment' => [
                'query' => ['level' => 'division', 'for' => 'ACADEMIC'],
                'completed' => 6, 'late' => 3, 'division' => 'ACADEMIC', 'unit' => null, 'borrower' => null,
            ],
            'Administration segment' => [
                'query' => ['level' => 'division', 'for' => 'ADMINISTRATION'],
                'completed' => 3, 'late' => 1, 'division' => 'ADMINISTRATION', 'unit' => null, 'borrower' => null,
            ],
            'CCS unit segment' => [
                'query' => ['level' => 'unit', 'for' => 'ACADEMIC', 'segment' => $this->ccs()],
                'completed' => 4, 'late' => 2, 'division' => 'ACADEMIC', 'unit' => $this->ccs(), 'borrower' => null,
            ],
            'HRMO unit segment' => [
                'query' => ['level' => 'unit', 'for' => 'ADMINISTRATION', 'segment' => $this->hrmo()],
                'completed' => 3, 'late' => 1, 'division' => 'ADMINISTRATION', 'unit' => $this->hrmo(), 'borrower' => null,
            ],
            'Academic segment for one borrower' => [
                'query' => ['level' => 'division', 'for' => 'ACADEMIC', 'borrower' => (string) $alice->id],
                'completed' => 3, 'late' => 2, 'division' => 'ACADEMIC', 'unit' => null, 'borrower' => (string) $alice->id,
            ],
            'whole scope for one borrower' => [
                'query' => ['level' => 'division', 'borrower' => (string) $bob->id],
                'completed' => 5, 'late' => 2, 'division' => null, 'unit' => null, 'borrower' => (string) $bob->id,
            ],
            'page filtered to Administration, whole breakdown' => [
                'query' => ['level' => 'unit', 'group' => 'ADMINISTRATION'],
                'completed' => 3, 'late' => 1, 'division' => 'ADMINISTRATION', 'unit' => null, 'borrower' => null,
            ],
            'page filtered to one unit, whole breakdown' => [
                'query' => ['level' => 'division', 'group' => 'ACADEMIC', 'unit' => $this->ced()],
                'completed' => 2, 'late' => 1, 'division' => 'ACADEMIC', 'unit' => $this->ced(), 'borrower' => null,
            ],
        ];

        foreach ($cases as $name => $case) {
            $href = $this->sourceLink(array_merge(
                ['section' => 'returns', 'academic_period' => 'month', 'detail' => 'late-rate'],
                $case['query']
            ));
            $parameters = $this->linkParameters($href);

            $this->assertSame('returns', $parameters['report'], "$name opens the Return & Accountability Report");
            $this->assertSame('RETURNED_LATE', $parameters['return_status'] ?? null, "$name asks Reports for the late subset");
            $this->assertSame($case['division'], $parameters['division'] ?? null, "$name carries the division");
            $this->assertSame($case['unit'], $parameters['unit'] ?? null, "$name carries the unit");
            $this->assertSame($case['borrower'], $parameters['borrower'] ?? null, "$name carries the borrower");

            $rows = $this->report($parameters)->rows;

            /* The numerator, listed: the late returns of the segment - never the completed-return denominator. */
            $this->assertCount($case['late'], $rows, "$name: Reports lists the segment's late returns");
            $this->assertNotSame($case['completed'], $rows->count(), "$name: the list is the late subset, not every completed return");
            $this->assertSame(['RETURNED_LATE'], $rows->pluck('_return_state')->unique()->values()->all(), "$name: every listed row is a late return");

            $analyticsLate = $this->analytics
                ->lateReturnRateRecords(
                    $this->from,
                    $this->to,
                    $case['division'],
                    $case['unit'],
                    $case['borrower'] === null ? null : (int) $case['borrower'],
                    $case['query']['for'] ?? null,
                    $case['query']['segment'] ?? null,
                    100
                )['rows']
                ->filter(fn (array $row): bool => $row['state'] === 'RETURNED_LATE')
                ->map(fn (array $row): string => (string) $row['custody']->custody_no)
                ->sort()
                ->values()
                ->all();

            $this->assertSame(
                $analyticsLate,
                $rows->pluck('custody_no')->sort()->values()->all(),
                "$name: Reports lists the same late custodies Analytics measured"
            );
        }
    }

    public function test_the_late_rate_detail_says_reports_lists_only_the_late_returns(): void
    {
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 14), $this->borrower());
        $this->completedReturn(Carbon::create(2026, 4, 10), Carbon::create(2026, 4, 8), $this->borrower());

        $page = $this->actingAs($this->head)->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'late-rate', 'level' => 'division',
        ]));

        $page->assertOk();
        $page->assertSee('The source-record view opens only the late returns of this scope');
        $page->assertSee('the table below lists every completed return the rate was measured against');
    }

    /* ------------------------------------------------------------------ */
    /* Link plumbing                                                       */
    /* ------------------------------------------------------------------ */

    /** The href of the detail panel's "View source records" link, as rendered. */
    private function sourceLink(array $query): string
    {
        $response = $this->actingAs($this->head)->get(route('analytics.index', $query));
        $response->assertOk();

        $x = $this->xpath($response->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);
        $this->assertNotNull($panel, 'the detail panel renders for '.json_encode($query));

        $link = $x->query('.//footer//a', $panel)->item(0);
        $this->assertNotNull($link, 'the detail offers a source-record link for '.json_encode($query));

        return $link->getAttribute('href');
    }

    /** @return array<string, string> */
    private function linkParameters(string $href): array
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parameters);

        return array_map(static fn ($value): string => (string) $value, $parameters);
    }

    /** Run the Reports module with the link's own parameters over the same calendar month. */
    private function report(array $parameters): ReportDataset
    {
        return app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', $parameters),
                $parameters['report'],
                $this->from,
                $this->to,
                'month'
            )
        );
    }

    private function xpath(string $html): DOMXPath
    {
        $key = md5($html);

        if (! isset($this->parsed[$key])) {
            $document = new DOMDocument();
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            libxml_clear_errors();
            $this->parsed[$key] = new DOMXPath($document);
        }

        return $this->parsed[$key];
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    private function borrower(?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => $name,
        ]));
    }

    private function ccs(): string
    {
        return 'College of Computer Studies';
    }

    private function ced(): string
    {
        return 'College of Education';
    }

    private function hrmo(): string
    {
        return 'Human Resource Management Office';
    }

    private function request(
        RequestStatus $status,
        ?Carbon $submittedAt = new Carbon('2026-04-10 10:00:00'),
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?User $borrower = null
    ): BorrowingRequest {
        $borrower ??= $this->borrower();
        $createdAt = $submittedAt->copy()->subHour();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 9999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Reconciliation fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit ?? $this->ccs(),
            'schedule_date' => $submittedAt->copy()->addDay()->toDateString(),
            'return_date' => $submittedAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $submittedAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $submittedAt->copy()->addDays(3)->endOfDay(),
            'submitted_at' => $submittedAt,
        ]);

        return $request->refresh();
    }

    private function item(): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(['category_code' => 'RECON'], ['category_name' => 'Reconciliation Fixture', 'active' => true]);
        $measure = UnitOfMeasure::query()->firstOrCreate(['unit_code' => 'PC'], ['unit_name' => 'Piece', 'active' => true]);

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => 'Reconciliation Chair'],
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
        ?string $unit = null,
        ?Carbon $closedAt = new Carbon('2026-04-18 10:00:00')
    ): CustodyTransaction {
        $item = $this->item();
        $unit ??= $this->ccs();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 9999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => RequestStatus::ApprovedReadyForRelease,
        ]);

        $version = RequestVersion::query()->create([
            'request_id' => $request->id, 'version_no' => 1, 'purpose_event' => 'Reconciliation fixture', 'location' => 'Campus',
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
            'received_by_user_id' => $this->head->id,
            'return_type' => 'NORMAL', 'received_at' => $receivedAt, 'status' => 'INSPECTED',
        ]);

        ReturnLine::query()->create([
            'return_transaction_id' => $return->id, 'custody_line_id' => $line->id, 'quantity_received' => 1,
            'condition_code' => 'FINE', 'disposition_state' => 'RETURNED',
        ]);

        return $custody;
    }
}
