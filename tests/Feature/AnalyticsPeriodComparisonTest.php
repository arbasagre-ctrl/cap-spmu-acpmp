<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\AcademicPeriod;
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
use App\Services\AnalyticsCardDetailService;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Period-over-period comparison in Analytics.
 *
 * Two promises are guarded here. First, every comparison measures the same
 * event over the same filters in the previous window as in the selected one -
 * filing date for requests and requested quantity, released_at for released
 * quantity, physical completion for returns. Second, a present-tense reading
 * (Currently Out, Currently Overdue, Low Availability, stock) is never
 * compared with a past window at all.
 *
 * Selected period: April 2026 (30 days), so the previous period is the 30
 * days before it: 02 Mar – 31 Mar. 01 Mar sits outside both.
 */
class AnalyticsPeriodComparisonTest extends TestCase
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

        $this->unit = OrganizationalUnit::query()->create([
            'unit_code' => 'POP',
            'unit_name' => 'Period Comparison Fixture Unit',
            'unit_type' => 'OFFICE',
            'active' => true,
        ]);

        $this->from = Carbon::create(2026, 4, 1)->startOfDay();
        $this->to = Carbon::create(2026, 4, 30)->endOfDay();

        /*
         * Inside the selected period, as a reader of "This month" always is:
         * the previous window (02 Mar - 31 Mar) is complete, April is not.
         */
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Previous-period resolution                                          */
    /* ------------------------------------------------------------------ */

    public function test_the_previous_window_is_the_equal_length_period_immediately_before(): void
    {
        [$from, $to] = $this->analytics->previousWindow($this->from, $this->to);

        /* 30 days before 01 Apr: 02 Mar – 31 Mar, not the calendar month of March. */
        $this->assertSame('2026-03-02 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-03-31 23:59:59', $to->toDateTimeString());

        /* A calendar week resolves to the seven days before it. */
        [$weekFrom, $weekTo] = $this->analytics->previousWindow(
            Carbon::create(2026, 4, 6)->startOfDay(),
            Carbon::create(2026, 4, 12)->endOfDay()
        );

        $this->assertSame('2026-03-30', $weekFrom->toDateString());
        $this->assertSame('2026-04-05', $weekTo->toDateString());

        /* A semester compares with the same number of days before it. */
        [$semFrom, $semTo] = $this->analytics->previousWindow(
            Carbon::create(2026, 8, 1)->startOfDay(),
            Carbon::create(2026, 12, 20)->endOfDay()
        );

        $this->assertSame('2026-07-31', $semTo->toDateString());
        $this->assertSame(142, (int) $semFrom->diffInDays($semTo->copy()->startOfDay()) + 1);

        /* The same rule ForecastService uses for its first history window. */
        $forecastWindow = app(\App\Services\ForecastService::class)->historyWindows($this->from, $this->to, 1)[0];
        $this->assertSame($from->toDateTimeString(), $forecastWindow[0]->toDateTimeString());
        $this->assertSame($to->toDateTimeString(), $forecastWindow[1]->toDateTimeString());
    }

    /* ------------------------------------------------------------------ */
    /* Event-date bases                                                    */
    /* ------------------------------------------------------------------ */

    public function test_requests_comparison_counts_filing_dates_in_the_equivalent_previous_range(): void
    {
        /* Current: three filed in April. */
        foreach ([3, 10, 25] as $day) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, $day, 10));
        }

        /* Previous: two filed inside 02 Mar – 31 Mar. */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 2, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 31, 10));

        /* 01 Mar is the calendar month but not the equal-length window. */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 1, 10));

        /* A draft is not activity in either window. */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 15, 10), status: RequestStatus::Draft);

        $comparison = $this->analytics->demandComparison($this->from, $this->to, null, null);

        $this->assertTrue($comparison['available']);
        $this->assertSame(3, $comparison['requests']['current']);
        $this->assertSame(2, $comparison['requests']['previous']);
        $this->assertSame(50.0, $comparison['requests']['percent']);
        $this->assertSame('Up 50% vs previous period', $comparison['requests']['label']);
    }

    public function test_requested_quantity_comparison_follows_the_request_filing_period(): void
    {
        $chair = $this->item('Comparison Chair');

        /* Filed in April for 40; filed in March for 100 - quantity follows filing. */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 8, 10), [['item' => $chair, 'quantity' => 40]]);
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 8, 10), [['item' => $chair, 'quantity' => 100]]);

        $comparison = $this->analytics->demandComparison($this->from, $this->to, null, null);

        $this->assertSame(40, $comparison['requested_quantity']['current']);
        $this->assertSame(100, $comparison['requested_quantity']['previous']);
        $this->assertSame(-60.0, $comparison['requested_quantity']['percent']);
        $this->assertSame('Down 60% vs previous period', $comparison['requested_quantity']['label']);
    }

    public function test_released_quantity_comparison_uses_released_at_in_both_periods(): void
    {
        $chair = $this->item('Release Chair');

        /* Filed in March, physically released in April: current-period release. */
        $this->release($chair, 12, filedAt: Carbon::create(2026, 3, 20, 10), releasedAt: Carbon::create(2026, 4, 2, 10));

        /* Filed in February, released in the previous window. */
        $this->release($chair, 20, filedAt: Carbon::create(2026, 2, 20, 10), releasedAt: Carbon::create(2026, 3, 15, 10));

        /* Released on 01 Mar: outside the equal-length previous window. */
        $this->release($chair, 99, filedAt: Carbon::create(2026, 2, 20, 10), releasedAt: Carbon::create(2026, 3, 1, 10));

        $comparison = $this->analytics->demandComparison($this->from, $this->to, null, null);

        $this->assertSame(12, $comparison['released_quantity']['current']);
        $this->assertSame(20, $comparison['released_quantity']['previous']);
        $this->assertSame('Down 40% vs previous period', $comparison['released_quantity']['label']);

        /* The filing dates would have said 0 current, 3 previous requests - not what is compared. */
        $this->assertSame(0, $comparison['requests']['current']);
    }

    public function test_return_comparison_uses_the_physical_completion_date_in_both_periods(): void
    {
        $chair = $this->item('Return Chair');

        /* Released in March, physically received in April, before due: current on time. */
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 25, 10), dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 3, 10));

        /* Received in April after due: current late. */
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 4, 1, 10), dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 9, 10));

        /* Previous window: two on time, one late. */
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 3, 10), dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 8, 10));
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 12, 10), dueAt: Carbon::create(2026, 3, 20), receivedAt: Carbon::create(2026, 3, 18, 10));
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 14, 10), dueAt: Carbon::create(2026, 3, 20), receivedAt: Carbon::create(2026, 3, 26, 10));

        /* Received 01 Mar: outside the equal-length previous window. */
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 2, 20, 10), dueAt: Carbon::create(2026, 2, 27), receivedAt: Carbon::create(2026, 3, 1, 10));

        /* Still out and overdue today: never a completed return in any period. */
        $this->release($chair, 1, filedAt: Carbon::create(2026, 4, 1, 10), releasedAt: Carbon::create(2026, 4, 2, 10), dueAt: Carbon::create(2026, 4, 6), status: 'OVERDUE');

        $comparison = $this->analytics->returnComparison($this->from, $this->to, null, null);

        $this->assertSame(2, $comparison['completed']['current']);
        $this->assertSame(3, $comparison['completed']['previous']);
        $this->assertSame(1, $comparison['on_time']['current']);
        $this->assertSame(2, $comparison['on_time']['previous']);
        $this->assertSame(1, $comparison['late']['current']);
        $this->assertSame(1, $comparison['late']['previous']);
        $this->assertSame('No change vs previous period', $comparison['late']['label']);

        /* 1/2 = 50.0 against 2/3 = 66.7: minus 16.7 points, not minus 25%. */
        $this->assertSame(50.0, $comparison['on_time_rate']['current']);
        $this->assertSame(66.7, $comparison['on_time_rate']['previous']);
        $this->assertSame(-16.7, $comparison['on_time_rate']['points']);
        $this->assertSame('Down 16.7 percentage points vs previous period', $comparison['on_time_rate']['label']);

        /* The comparison carries no present-tense reading at all. */
        $this->assertArrayNotHasKey('overdue', $comparison);
        $this->assertArrayNotHasKey('open_cases', $comparison);

        /* And it reconciles with the figures the KPI cards print. */
        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame($returns['completed'], $comparison['completed']['current']);
        $this->assertSame($returns['on_time'], $comparison['on_time']['current']);
        $this->assertSame($returns['late'], $comparison['late']['current']);
        $this->assertSame($returns['on_time_rate'], $comparison['on_time_rate']['current']);
        $this->assertSame(1, $returns['overdue']);
    }

    public function test_a_rate_with_no_completed_returns_in_the_previous_period_is_not_measurable(): void
    {
        $chair = $this->item('Lonely Chair');
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 4, 1, 10), dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 3, 10));

        $comparison = $this->analytics->returnComparison($this->from, $this->to, null, null);

        $this->assertSame(100.0, $comparison['on_time_rate']['current']);
        $this->assertNull($comparison['on_time_rate']['previous']);
        $this->assertFalse($comparison['on_time_rate']['measurable']);
        $this->assertNull($comparison['on_time_rate']['points']);
        $this->assertSame('Previous-period rate not measurable', $comparison['on_time_rate']['label']);

        /* The count beside it is still a truthful "from zero". */
        $this->assertSame('Up from 0 in previous period', $comparison['completed']['label']);
        $this->assertNull($comparison['completed']['percent']);
    }

    public function test_nothing_in_either_period_is_stated_as_such(): void
    {
        $comparison = $this->analytics->demandComparison($this->from, $this->to, null, null);

        $this->assertSame('No activity in either period', $comparison['requests']['label']);
        $this->assertSame('No activity in either period', $comparison['released_quantity']['label']);
        $this->assertNull($comparison['requests']['percent']);

        $returns = $this->analytics->returnComparison($this->from, $this->to, null, null);
        $this->assertSame('Rate not measurable in either period', $returns['on_time_rate']['label']);
    }

    /* ------------------------------------------------------------------ */
    /* Filters                                                             */
    /* ------------------------------------------------------------------ */

    public function test_division_unit_and_borrower_filters_carry_into_the_previous_period(): void
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        /* April: Academic/CCS 2 (Alice, Bob), Administration/HRMO 1 (Bob). */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 3, 10), borrower: $alice);
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 4, 10), borrower: $bob);
        $this->request('ADMINISTRATION', $this->hrmo(), Carbon::create(2026, 4, 5, 10), borrower: $bob);

        /* Previous: Academic/CCS 1 (Alice), Academic/CCS 3 (Bob), Administration 4 (Bob). */
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 5, 10), borrower: $alice);
        foreach ([6, 7, 8] as $day) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, $day, 10), borrower: $bob);
        }
        foreach ([9, 10, 11, 12] as $day) {
            $this->request('ADMINISTRATION', $this->hrmo(), Carbon::create(2026, 3, $day, 10), borrower: $bob);
        }

        $all = $this->analytics->demandComparison($this->from, $this->to, null, null);
        $this->assertSame([3, 8], [$all['requests']['current'], $all['requests']['previous']]);

        /* Division: the previous figure is Academic's 4, not the institution's 8. */
        $academic = $this->analytics->demandComparison($this->from, $this->to, 'ACADEMIC', null);
        $this->assertSame([2, 4], [$academic['requests']['current'], $academic['requests']['previous']]);
        $this->assertSame('Down 50% vs previous period', $academic['requests']['label']);

        /* Unit. */
        $hrmo = $this->analytics->demandComparison($this->from, $this->to, 'ADMINISTRATION', $this->hrmo());
        $this->assertSame([1, 4], [$hrmo['requests']['current'], $hrmo['requests']['previous']]);

        /* Borrower: Alice filed one in each window. */
        $aliceOnly = $this->analytics->demandComparison($this->from, $this->to, null, null, $alice->id);
        $this->assertSame([1, 1], [$aliceOnly['requests']['current'], $aliceOnly['requests']['previous']]);
        $this->assertSame('No change vs previous period', $aliceOnly['requests']['label']);

        /* Division and borrower together. */
        $bobAcademic = $this->analytics->demandComparison($this->from, $this->to, 'ACADEMIC', null, $bob->id);
        $this->assertSame([1, 3], [$bobAcademic['requests']['current'], $bobAcademic['requests']['previous']]);
    }

    public function test_return_comparison_filters_carry_into_the_previous_period(): void
    {
        $chair = $this->item('Filtered Chair');
        $alice = $this->borrower();
        $bob = $this->borrower();

        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 4, 1, 10), dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 3, 10), borrower: $alice);
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 5, 10), dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 8, 10), borrower: $alice);
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 5, 10), dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 8, 10), borrower: $bob);
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 5, 10), dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 8, 10), borrower: $bob, division: 'ADMINISTRATION', unit: $this->hrmo());

        $all = $this->analytics->returnComparison($this->from, $this->to, null, null);
        $this->assertSame([1, 3], [$all['completed']['current'], $all['completed']['previous']]);

        $academic = $this->analytics->returnComparison($this->from, $this->to, 'ACADEMIC', null);
        $this->assertSame([1, 2], [$academic['completed']['current'], $academic['completed']['previous']]);

        $aliceOnly = $this->analytics->returnComparison($this->from, $this->to, null, null, $alice->id);
        $this->assertSame([1, 1], [$aliceOnly['completed']['current'], $aliceOnly['completed']['previous']]);
    }

    /* ------------------------------------------------------------------ */
    /* Where the line appears, and where it never does                     */
    /* ------------------------------------------------------------------ */

    public function test_current_state_kpis_carry_no_previous_period_line(): void
    {
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 3, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 3, 10));
        $this->item('Stock Chair');

        $head = $this->spmuHead();

        $overview = $this->page($head, 'overview');
        $this->assertKpiHasDelta($overview, 'Requests', true);
        $this->assertKpiHasDelta($overview, 'Currently Out', false);
        $this->assertKpiHasDelta($overview, 'Currently Overdue', false);
        $this->assertKpiHasDelta($overview, 'Low Availability', false);

        $inventory = $this->page($head, 'inventory');
        foreach (['Available Units', 'Reserved / Allocated', 'On Custody', 'Attention Needed'] as $label) {
            $this->assertKpiHasDelta($inventory, $label, false);
        }
        $this->assertSame(0, $this->countDeltas($inventory), 'Inventory Health is current-state throughout.');

        $returns = $this->page($head, 'returns');
        $this->assertKpiHasDelta($returns, 'Returned On Time', true);
        $this->assertKpiHasDelta($returns, 'Returned Late', true);
        $this->assertKpiHasDelta($returns, 'Currently Overdue', false);
        $this->assertKpiHasDelta($returns, 'Open Accountability', false);

        $demand = $this->page($head, 'demand');
        $this->assertKpiHasDelta($demand, 'Requests Filed', true);
        $this->assertKpiHasDelta($demand, 'Requested Quantity', true);
        $this->assertKpiHasDelta($demand, 'Released Quantity', true);
        $this->assertKpiHasDelta($demand, 'Active Borrowing Units', false);
    }

    public function test_forecast_and_planning_carries_no_previous_period_line(): void
    {
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 3, 10));

        $predictive = $this->page($this->spmuHead(), 'predictive');

        $this->assertSame(0, $this->countDeltas($predictive));
        foreach (['Forecast Readiness', 'Scheduled Demand', 'Forecasted Demand', 'Equipment Coverage Risk'] as $label) {
            $this->assertKpiHasDelta($predictive, $label, false);
        }
    }

    public function test_the_kpi_line_states_the_comparison_in_words(): void
    {
        foreach ([3, 10, 25] as $day) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, $day, 10));
        }
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 2, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 31, 10));

        $demand = $this->page($this->spmuHead(), 'demand');

        $this->assertStringContainsString('↑ 50% vs previous period', $this->kpiDeltaText($demand, 'Requests Filed'));
        $this->assertStringContainsString('Up 50% vs previous period', $this->kpiDeltaText($demand, 'Requests Filed'));
        $this->assertStringContainsString('No activity in either period', $this->kpiDeltaText($demand, 'Released Quantity'));
    }

    public function test_the_kpi_line_flags_a_period_still_in_progress_and_only_then(): void
    {
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, 3, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 3, 10));

        /* Twenty days into April: the previous period is complete, this one is not. */
        $demand = $this->page($this->spmuHead(), 'demand');

        $this->assertStringContainsString('(to date)', $this->kpiDeltaText($demand, 'Requests Filed'));
        $this->assertStringContainsString('still in progress', $this->kpiDeltaText($demand, 'Requests Filed'));

        $running = $this->analytics->demandComparison($this->from, $this->to, null, null);
        $this->assertFalse($running['current_complete']);

        /* Once April has ended the same comparison drops the caveat. */
        Carbon::setTestNow(Carbon::create(2026, 5, 5, 9));

        $finished = $this->analytics->demandComparison($this->from, $this->to, null, null);
        $this->assertTrue($finished['current_complete']);

        $line = view('analytics.partials.period-delta', ['comparison' => $finished['requests']])->render();
        $this->assertStringContainsString('No change vs previous period', $line);
        $this->assertStringNotContainsString('(to date)', $line);
        $this->assertStringNotContainsString('still in progress', $line);
    }

    public function test_an_unfinished_previous_period_is_not_compared_on_the_page(): void
    {
        /*
         * An academic period marked ACTIVE ahead of its start: the previous
         * window ends the day before June, which is still in the future.
         */
        AcademicPeriod::query()->create([
            'academic_year' => '2026-2027',
            'term_code' => 'SUMMER',
            'term_name' => 'Summer Term',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'ACTIVE',
        ]);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'semester',
        ]));

        $page->assertOk();
        $this->assertStringContainsString('Previous period not yet complete', $this->kpiDeltaText($page->getContent(), 'Requests Filed'));
        $this->assertStringNotContainsString('%', $this->kpiDeltaText($page->getContent(), 'Requests Filed'));
    }

    /* ------------------------------------------------------------------ */
    /* Details                                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_requests_detail_carries_the_comparison_block_and_its_source_link(): void
    {
        foreach ([3, 10, 25] as $day) {
            $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 4, $day, 10));
        }
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 2, 10));
        $this->request('ACADEMIC', $this->ccs(), Carbon::create(2026, 3, 31, 10));

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'detail' => 'requests',
        ]));

        $page->assertOk();
        $page->assertSee('Compared with the previous period');
        $page->assertSee('Previous period: 02 Mar 2026 – 31 Mar 2026');
        $page->assertSee('3 requests', false);
        $page->assertSee('2 requests', false);
        $page->assertSee('+1 request (+50%)', false);
        $page->assertSee('View source records');
        $page->assertSee('report=borrowing', false);
    }

    public function test_the_card_details_carry_their_own_comparisons(): void
    {
        $chair = $this->item('Detail Chair');
        $this->release($chair, 12, filedAt: Carbon::create(2026, 4, 1, 10), releasedAt: Carbon::create(2026, 4, 2, 10));
        $this->release($chair, 20, filedAt: Carbon::create(2026, 3, 10, 10), releasedAt: Carbon::create(2026, 3, 15, 10));
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 4, 1, 10), dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 3, 10));
        $this->completedReturn($chair, releasedAt: Carbon::create(2026, 3, 5, 10), dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 18, 10));

        $details = app(AnalyticsCardDetailService::class);
        $scope = ['from' => $this->from, 'to' => $this->to, 'division' => null, 'unit' => null, 'period' => 'month', 'borrower' => null];

        /* 12 + the returned chair's 1 in April; 20 + 1 in the previous window. */
        $released = $details->resolve($scope, 'demand.released-quantity');
        $this->assertSame([
            ['Current period', '13 units'],
            ['Previous period', '21 units'],
            ['Change', '−8 units (−38.1%)'],
        ], $released['comparison']['rows']);
        $this->assertSame(100, $released['comparison']['bars'][0]['share']);
        $this->assertSame(62, $released['comparison']['bars'][1]['share']);
        $this->assertStringContainsString('still in progress', $released['comparison']['note']);

        $summary = $details->resolve($scope, 'returns.summary');
        $rows = collect($summary['comparison']['rows'])->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);
        $this->assertSame('1 return', $rows['Completed returns · Current period']);
        $this->assertSame('1 return', $rows['Completed returns · Previous period']);
        $this->assertSame('No change', $rows['Completed returns · Change']);
        $this->assertSame('100%', $rows['On-time rate · Current period']);
        $this->assertSame('0%', $rows['On-time rate · Previous period']);
        $this->assertSame('+100 percentage points', $rows['On-time rate · Difference']);

        $compliance = $details->resolve($scope, 'overview.return-compliance');
        $this->assertSame(['Difference', '+100 percentage points'], $compliance['comparison']['rows'][2]);

        /* A current-state card detail has no comparison at all. */
        $this->assertArrayNotHasKey('comparison', $details->resolve($scope, 'inventory.available'));
        $this->assertArrayNotHasKey('comparison', $details->resolve($scope, 'returns.followup'));
        $this->assertArrayNotHasKey('comparison', $details->resolve($scope, 'forecast.scheduled'));
    }

    /* ------------------------------------------------------------------ */
    /* Page helpers                                                        */
    /* ------------------------------------------------------------------ */

    private function page(User $head, string $section): string
    {
        $response = $this->actingAs($head)->get(route('analytics.index', [
            'section' => $section,
            'academic_period' => 'month',
        ]));

        $response->assertOk();

        return $response->getContent();
    }

    /** @var array<string, DOMXPath> one parsed document per page, so nodes can be reused */
    private array $parsed = [];

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

    private function kpiCard(string $html, string $label): \DOMNode
    {
        $cards = $this->xpath($html)->query(
            '//a[contains(@class,"analytics-kpi-card")][.//*[contains(@class,"analytics-kpi-card-label") and normalize-space(text())="'.$label.'"]]'
        );

        $this->assertGreaterThan(0, $cards->length, "KPI card '$label' is on the page.");

        return $cards->item(0);
    }

    private function assertKpiHasDelta(string $html, string $label, bool $expected): void
    {
        $card = $this->kpiCard($html, $label);
        $deltas = $this->xpath($html)->query('.//*[@data-period-delta]', $card);

        $this->assertSame(
            $expected ? 1 : 0,
            $deltas->length,
            "KPI card '$label' ".($expected ? 'carries' : 'must not carry').' a period-over-period line.'
        );
    }

    private function kpiDeltaText(string $html, string $label): string
    {
        $card = $this->kpiCard($html, $label);
        $delta = $this->xpath($html)->query('.//*[@data-period-delta]', $card)->item(0);

        return $delta ? trim(preg_replace('/\s+/', ' ', $delta->textContent)) : '';
    }

    private function countDeltas(string $html): int
    {
        return $this->xpath($html)->query('//*[@data-period-delta]')->length;
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

    private function item(string $description, int $total = 200): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'POP'],
            ['category_name' => 'Period Comparison Fixture', 'active' => true]
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
     * A request filed on $filedAt (submitted_at, the filing event).
     *
     * @param  array<int, array{item: InventoryItem, quantity: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        Carbon $filedAt,
        array $lines = [],
        ?User $borrower = null,
        RequestStatus $status = RequestStatus::UnderSpmu
    ): RequestVersion {
        $borrower ??= $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.$filedAt->format('YmdHis').'-'.fake()->unique()->numberBetween(1000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $filedAt, 'updated_at' => $filedAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Period comparison fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $filedAt->copy()->addDay()->toDateString(),
            'return_date' => $filedAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $filedAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $filedAt->copy()->addDays(3)->endOfDay(),
            'submitted_at' => $filedAt,
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

        return $version;
    }

    /** One physical release of $quantity on $releasedAt, as its own custody. */
    private function release(
        InventoryItem $item,
        int $quantity,
        Carbon $filedAt,
        Carbon $releasedAt,
        ?Carbon $dueAt = null,
        string $status = 'ACTIVE',
        ?User $borrower = null,
        string $division = 'ACADEMIC',
        ?string $unit = null
    ): CustodyTransaction {
        $borrower ??= $this->borrower();
        $dueAt ??= $releasedAt->copy()->addDays(3);

        $version = $this->request($division, $unit ?? $this->ccs(), $filedAt, [['item' => $item, 'quantity' => $quantity]], $borrower, RequestStatus::ApprovedReadyForRelease);
        $requestItem = $version->items()->first();

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $version->request_id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $status,
            'due_at' => $dueAt->copy()->endOfDay(),
            'released_at' => $releasedAt,
            'closed_at' => null,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $releasedAt->copy()->startOfDay(),
            'period_end' => $dueAt->copy()->endOfDay(),
            'allocated_quantity' => $quantity,
            'released_quantity' => $quantity,
            'restored_quantity' => 0,
            'status' => 'RELEASED',
            'allocated_at' => $filedAt,
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => $quantity,
            'quantity_to_receive' => $quantity,
            'actual_released_quantity' => $quantity,
            'returned_quantity' => 0,
        ]);

        return $custody;
    }

    /**
     * A borrowing physically received back on $receivedAt via a Return
     * Inspection receipt - the authoritative completion event.
     */
    private function completedReturn(
        InventoryItem $item,
        Carbon $releasedAt,
        Carbon $dueAt,
        Carbon $receivedAt,
        ?User $borrower = null,
        string $division = 'ACADEMIC',
        ?string $unit = null
    ): CustodyTransaction {
        $borrower ??= $this->borrower();

        $custody = $this->release(
            $item, 1,
            filedAt: $releasedAt->copy()->subDay(),
            releasedAt: $releasedAt,
            dueAt: $dueAt,
            status: 'CLOSED',
            borrower: $borrower,
            division: $division,
            unit: $unit
        );

        $line = $custody->lines()->first();
        $line->update(['returned_quantity' => 1]);

        $return = ReturnTransaction::query()->create([
            'return_no' => 'RT-'.fake()->unique()->numberBetween(100000, 999999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $this->spmuHead()->id,
            'return_type' => 'NORMAL',
            'received_at' => $receivedAt,
            'status' => 'INSPECTED',
        ]);

        ReturnLine::query()->create([
            'return_transaction_id' => $return->id,
            'custody_line_id' => $line->id,
            'quantity_received' => 1,
            'condition_code' => 'FINE',
            'disposition_state' => 'RETURNED',
        ]);

        /* closed_at is set later than the receipt on purpose: it must not win. */
        $custody->forceFill(['closed_at' => $receivedAt->copy()->addDays(10)])->save();

        return $custody;
    }
}
