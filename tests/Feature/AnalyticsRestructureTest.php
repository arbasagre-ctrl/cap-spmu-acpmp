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
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The restructured Analytics module.
 *
 * The metric-correctness tests here are the point of the exercise: a figure
 * that silently counts abandoned drafts, or that hides an overdue borrowing
 * because the request was filed last month, is worse than no figure at all.
 */
class AnalyticsRestructureTest extends TestCase
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
            'unit_code' => 'RESTR',
            'unit_name' => 'Restructure Fixture Unit',
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
    /* P0 - request activity definition                                    */
    /* ------------------------------------------------------------------ */

    public function test_drafts_cancellations_and_expired_requests_are_not_counted_as_activity(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::UnderSpmu);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Draft);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Cancelled);
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Expired);

        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        $this->assertSame(1, $overview['total']);
    }

    public function test_a_rejected_request_still_counts_as_activity(): void
    {
        /*
         * It was filed, reviewed and decided on. Hiding it would understate
         * both the demand that was expressed and the review workload.
         */
        $this->request('ACADEMIC', 'College of Computer Studies', RequestStatus::Rejected);

        $this->assertSame(
            1,
            $this->analytics->overview($this->from, $this->to, null, null)['total']
        );
    }

    public function test_the_excluded_status_list_is_declared_in_one_place(): void
    {
        $this->assertSame(
            [RequestStatus::Draft, RequestStatus::Cancelled, RequestStatus::Expired],
            AnalyticsService::excludedFromActivity()
        );
    }

    /* ------------------------------------------------------------------ */
    /* P0 - current-state metrics                                          */
    /* ------------------------------------------------------------------ */

    public function test_currently_out_survives_a_request_filed_before_the_period(): void
    {
        /*
         * Requested in February, released and still held in April. April must
         * still report it as out: the equipment has not come back.
         */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            createdAt: Carbon::create(2026, 2, 10, 9),
            custody: [
                'status' => 'ACTIVE',
                'released_at' => Carbon::create(2026, 2, 12, 9),
                'due_at' => Carbon::create(2026, 5, 30)->endOfDay(),
            ]
        );

        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        $this->assertSame(0, $overview['total'], 'The request itself belongs to February.');
        $this->assertSame(1, $overview['on_custody'], 'The equipment is still out in April.');
    }

    public function test_need_follow_up_survives_a_request_filed_before_the_period(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            createdAt: Carbon::create(2026, 2, 10, 9),
            custody: [
                'status' => 'OVERDUE',
                'released_at' => Carbon::create(2026, 2, 12, 9),
                'due_at' => Carbon::create(2026, 3, 1)->endOfDay(),
            ]
        );

        $this->assertSame(
            1,
            $this->analytics->overview($this->from, $this->to, null, null)['needs_follow_up']
        );
    }

    public function test_current_state_metrics_still_follow_the_division_filter(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(2)]
        );

        $this->assertSame(
            1,
            $this->analytics->overview($this->from, $this->to, 'ACADEMIC', null)['on_custody']
        );

        $this->assertSame(
            0,
            $this->analytics->overview($this->from, $this->to, 'ADMINISTRATION', null)['on_custody']
        );
    }

    public function test_overdue_and_late_return_remain_separate_measures(): void
    {
        /* Returned, but after the due date: a LATE RETURN, not overdue. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
                'closed_at' => $this->from->copy()->addDays(9),
            ]
        );

        /* Still out past its due date: OVERDUE, not a late return. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
            ]
        );

        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame(1, $returns['late']);
        $this->assertSame(1, $returns['overdue']);
        $this->assertSame(0, $returns['on_time']);
    }

    /* ------------------------------------------------------------------ */
    /* Return rates and duration                                           */
    /* ------------------------------------------------------------------ */

    public function test_return_rates_are_shares_of_completed_returns(): void
    {
        foreach ([true, true, false] as $onTime) {
            $this->request(
                'ACADEMIC',
                'College of Computer Studies',
                RequestStatus::ApprovedReadyForRelease,
                custody: [
                    'status' => 'CLOSED',
                    'released_at' => $this->from->copy()->addDay(),
                    'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
                    'closed_at' => $onTime
                        ? $this->from->copy()->addDays(4)
                        : $this->from->copy()->addDays(9),
                ]
            );
        }

        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame(3, $returns['completed']);
        $this->assertSame(66.7, $returns['on_time_rate']);
        $this->assertSame(33.3, $returns['late_rate']);
    }

    public function test_return_rates_are_null_rather_than_zero_without_completed_returns(): void
    {
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertNull($returns['on_time_rate']);
        $this->assertNull($returns['late_rate']);
    }

    public function test_average_borrowing_duration_uses_release_to_closure(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(9)->endOfDay(),
                'closed_at' => $this->from->copy()->addDays(3),
            ]
        );

        $duration = $this->analytics->averageCustodyDuration($this->from, $this->to, null, null);

        $this->assertTrue($duration['available']);
        $this->assertSame(2.0, $duration['days']);
    }

    /* ------------------------------------------------------------------ */
    /* Borrower behaviour                                                  */
    /* ------------------------------------------------------------------ */

    public function test_most_frequent_borrowers_are_ranked_by_request_count(): void
    {
        $busy = $this->borrower('Busy Borrower');
        $quiet = $this->borrower('Quiet Borrower');

        foreach (range(1, 3) as $index) {
            $this->request('ACADEMIC', 'College of Computer Studies', borrower: $busy);
        }

        $this->request('ACADEMIC', 'College of Computer Studies', borrower: $quiet);

        $result = $this->analytics->frequentBorrowers($this->from, $this->to, null, null);

        $this->assertSame('Busy Borrower', $result['borrowers'][0]['name']);
        $this->assertSame(3, $result['borrowers'][0]['requests']);
        $this->assertSame(1, $result['borrowers'][1]['requests']);
    }

    public function test_late_return_borrowers_count_only_returned_equipment(): void
    {
        $borrower = $this->borrower('Late Returner');

        /* Returned late: counts. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            borrower: $borrower,
            custody: [
                'status' => 'CLOSED',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
                'closed_at' => $this->from->copy()->addDays(9),
            ]
        );

        /* Still out past due: overdue, so it must not appear here. */
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            borrower: $borrower,
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
            ]
        );

        $result = $this->analytics->lateReturnBorrowers($this->from, $this->to, null, null);

        $this->assertCount(1, $result['borrowers']);
        $this->assertSame(1, $result['borrowers'][0]['late_returns']);
    }

    /* ------------------------------------------------------------------ */
    /* Movement                                                            */
    /* ------------------------------------------------------------------ */

    public function test_slow_moving_includes_equipment_that_never_moved(): void
    {
        $moved = $this->item('Monoblock Chair', 200);
        $this->item('Unused Projector', 5);

        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $moved, 'released' => 30]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(2)]
        );

        $result = $this->analytics->slowMovingItems($this->from, $this->to);

        $names = collect($result['items'])->pluck('name')->all();

        $this->assertContains('Unused Projector', $names);
        $this->assertSame('Unused Projector', $result['items'][0]['name']);
        $this->assertSame(0.0, $result['items'][0]['released']);
        $this->assertSame(1, $result['never_moved']);
    }

    public function test_peak_borrowing_withholds_a_peak_below_the_minimum(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null);

        $this->assertFalse($peak['available']);
        $this->assertNull($peak['peak_day']);
        $this->assertSame('Not enough activity to determine a reliable peak.', $peak['summary']);
    }

    public function test_peak_borrowing_reports_a_day_once_enough_requests_exist(): void
    {
        /* 6 April 2026 is a Monday. */
        for ($index = 0; $index < AnalyticsService::PEAK_MINIMUM_OBSERVATIONS; $index++) {
            $this->request(
                'ACADEMIC',
                'College of Computer Studies',
                createdAt: Carbon::create(2026, 4, 6, 9)->addMinutes($index)
            );
        }

        $peak = $this->analytics->peakBorrowing($this->from, $this->to, null, null);

        $this->assertTrue($peak['available']);
        $this->assertSame('Monday', $peak['peak_day']);
    }

    /* ------------------------------------------------------------------ */
    /* Stock coverage                                                      */
    /* ------------------------------------------------------------------ */

    public function test_stock_coverage_reports_nothing_without_any_release(): void
    {
        $this->item('Idle Item', 40);

        $coverage = $this->coverage();

        $this->assertFalse($coverage['available']);
        $this->assertSame([], $coverage['items']);
        $this->assertStringContainsString('No equipment was released', $coverage['summary']);
    }

    public function test_a_single_isolated_release_does_not_produce_a_risk_classification(): void
    {
        /*
         * One release is an event, not a usage rate. Projecting days of
         * coverage from it would dress a single data point up as a trend.
         */
        $item = $this->item('Monoblock Chair', 300);

        $this->releaseOnce($item, 30, $this->from->copy()->addDays(2));

        $coverage = $this->coverage();

        $this->assertFalse($coverage['available'], 'Nothing could be measured.');
        $this->assertCount(1, $coverage['items']);

        $row = $coverage['items'][0];

        $this->assertFalse($row['sufficient']);
        $this->assertSame(1, $row['releases']);
        $this->assertNull($row['per_day']);
        $this->assertNull($row['days_cover']);
        $this->assertNull($row['risk']);
        $this->assertSame(1, $coverage['insufficient']);
        $this->assertSame(0, $coverage['high_risk']);
    }

    public function test_two_releases_are_still_below_the_minimum(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->releaseOnce($item, 10, $this->from->copy()->addDays(2));
        $this->releaseOnce($item, 10, $this->from->copy()->addDays(6));

        $row = $this->coverage()['items'][0];

        $this->assertFalse($row['sufficient']);
        $this->assertNull($row['risk']);
    }

    public function test_a_window_shorter_than_the_minimum_blocks_every_item(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        foreach ([1, 2, 3] as $offset) {
            $this->releaseOnce($item, 10, $this->from->copy()->addDays($offset));
        }

        /* A five-day window cannot support a daily rate however many releases. */
        $coverage = $this->coverage(
            $this->from->copy(),
            $this->from->copy()->addDays(4)->endOfDay()
        );

        $this->assertFalse($coverage['window_sufficient']);
        $this->assertFalse($coverage['available']);
        $this->assertNull($coverage['items'][0]['risk']);
        $this->assertStringContainsString('too short', $coverage['summary']);
    }

    public function test_sufficient_history_produces_a_rate_coverage_and_risk_band(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        /* Three separate releases of 10 across the 30-day period. */
        foreach ([2, 10, 18] as $offset) {
            $this->releaseOnce($item, 10, $this->from->copy()->addDays($offset));
        }

        $coverage = $this->coverage();

        $this->assertTrue($coverage['window_sufficient']);
        $this->assertTrue($coverage['available']);
        $this->assertSame(1, $coverage['measured']);
        $this->assertSame(0, $coverage['insufficient']);

        $row = $coverage['items'][0];

        $this->assertTrue($row['sufficient']);
        $this->assertSame(3, $row['releases']);
        $this->assertSame(30.0, $row['released']);
        /* 30 released over 30 days is 1 per day. */
        $this->assertSame(1.0, $row['per_day']);
        /* 300 serviceable less 30 still out = 270 usable, at 1 per day. */
        $this->assertSame(270, $row['days_cover']);
        $this->assertSame('Low', $row['risk']);
    }

    public function test_the_risk_band_follows_the_estimated_coverage(): void
    {
        /* 90 released from a catalogue of 90 leaves 0 usable: High risk. */
        $item = $this->item('Monoblock Chair', 90);

        foreach ([2, 10, 18] as $offset) {
            $this->releaseOnce($item, 30, $this->from->copy()->addDays($offset));
        }

        $row = $this->coverage()['items'][0];

        $this->assertTrue($row['sufficient']);
        $this->assertSame(0, $row['days_cover']);
        $this->assertSame('High', $row['risk']);
        $this->assertSame(1, $this->coverage()['high_risk']);
    }

    public function test_zero_usable_stock_with_no_usage_rate_is_never_divided_by_zero(): void
    {
        /*
         * stockoutRisk() is the only place a coverage figure becomes a band,
         * and it must refuse a null reading rather than inventing one.
         */
        $method = new \ReflectionMethod(AnalyticsService::class, 'stockoutRisk');

        $this->assertNull($method->invoke($this->analytics, null));
        $this->assertSame('High', $method->invoke($this->analytics, 0.0));
        $this->assertSame('Medium', $method->invoke($this->analytics, 45.0));
        $this->assertSame('Low', $method->invoke($this->analytics, 120.0));
    }

    public function test_the_minimum_history_rule_is_declared_in_the_service(): void
    {
        $this->assertSame(14, AnalyticsService::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS);
        $this->assertSame(3, AnalyticsService::STOCK_COVERAGE_MINIMUM_RELEASES);

        $coverage = $this->coverage();

        $this->assertSame(
            ['window_days' => 14, 'releases' => 3],
            $coverage['requirement']
        );
    }

    public function test_stockout_risk_thresholds_live_in_the_service(): void
    {
        $this->assertSame(30, AnalyticsService::STOCKOUT_RISK_HIGH_DAYS);
        $this->assertSame(60, AnalyticsService::STOCKOUT_RISK_MEDIUM_DAYS);
    }

    /* ------------------------------------------------------------------ */
    /* Incidents                                                           */
    /* ------------------------------------------------------------------ */

    public function test_incident_summary_reports_a_clean_zero_without_records(): void
    {
        $summary = $this->analytics->incidentSummary($this->from, $this->to, null, null);

        $this->assertSame(0, $summary['total']);
        $this->assertNull($summary['rate']);
        $this->assertSame('No property incidents were recorded for this period.', $summary['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Page: tabs, drill-down and the academic-period limitation           */
    /* ------------------------------------------------------------------ */

    public function test_the_five_restructured_tabs_are_offered(): void
    {
        $response = $this->actingAs($this->spmuHead())
            ->get(route('analytics.index'));

        $response->assertOk();

        foreach ([
            'Overview',
            'Demand &amp; Utilization',
            'Inventory Health',
            'Borrowing &amp; Return Performance',
            'Forecast &amp; Planning',
        ] as $tab) {
            $response->assertSee($tab, false);
        }
    }

    public function test_renamed_sections_still_open_from_an_old_link(): void
    {
        foreach ([
            'borrowers' => 'Borrowing &amp; Return Performance',
            'equipment' => 'Demand &amp; Utilization',
            'forecast' => 'Forecast &amp; Planning',
        ] as $legacy => $heading) {
            $this->actingAs($this->spmuHead())
                ->get(route('analytics.index', ['section' => $legacy]))
                ->assertOk()
                ->assertSee($heading, false);
        }
    }

    public function test_overview_cards_open_an_analytics_detail_not_reports(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'group' => 'ACADEMIC',
        ]));

        $response->assertOk();

        /*
         * The primary click is a question about the figure, so it stays
         * inside Analytics carrying the same period and division.
         */
        foreach (['requests', 'currently-out', 'follow-up', 'low-availability'] as $detail) {
            $response->assertSee(
                e(route('analytics.index', [
                    'section' => 'overview',
                    'academic_period' => 'month',
                    'group' => 'ACADEMIC',
                    'detail' => $detail,
                ])),
                false
            );
        }

        /* And no card jumps straight to the record module. */
        $response->assertDontSee(e(route('reports.index', [
            'report' => 'borrowing',
            'academic_period' => 'month',
            'division' => 'ACADEMIC',
        ])), false);
    }

    public function test_the_primary_call_to_action_reads_view_details(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
        ]));

        $response->assertOk();
        $response->assertDontSee('Show release records for', false);
        $response->assertDontSee('Show borrowing records for', false);
    }

    public function test_the_requests_detail_explains_the_metric_and_offers_reports_second(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'group' => 'ACADEMIC',
            'detail' => 'requests',
        ]));

        $response->assertOk();
        $response->assertSee('aria-modal="true"', false);
        $response->assertSee('It is not a measure of actual asset usage.', false);

        /* Reports is the secondary step, with the analytics filters intact. */
        $response->assertSee('View source records in Reports', false);
        $response->assertSee(e(route('reports.index', [
            'report' => 'borrowing',
            'academic_period' => 'month',
            'division' => 'ACADEMIC',
        ])), false);
    }

    public function test_current_custody_and_follow_up_details_render(): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            custody: [
                'status' => 'OVERDUE',
                'released_at' => $this->from->copy()->addDay(),
                'due_at' => $this->from->copy()->addDays(5)->endOfDay(),
            ]
        );

        $out = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'detail' => 'currently-out',
        ]));

        $out->assertOk();
        $out->assertSee('Current physical custody', false);
        $out->assertSee(e(route('reports.index', [
            'report' => 'custody',
            'academic_period' => 'month',
            'custody_status' => 'ACTIVE',
        ])), false);

        $followUp = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'detail' => 'follow-up',
        ]));

        $followUp->assertOk();
        /* The overdue / late-return distinction survives into the detail. */
        $followUp->assertSee('has not come back after its effective due date', false);
        $followUp->assertSee('Days overdue', false);
    }

    public function test_low_availability_detail_states_it_is_current_inventory(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'detail' => 'low-availability',
        ]));

        $response->assertOk();
        $response->assertSee('Current inventory status, as of today', false);
        $response->assertSee(e(route('reports.index', [
            'report' => 'inventory',
            'academic_period' => 'month',
            'availability_status' => 'FULLY_COMMITTED',
        ])), false);
    }

    public function test_equipment_detail_separates_expressed_demand_from_actual_usage(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            lines: [['item' => $item, 'released' => 30]],
            custody: ['status' => 'ACTIVE', 'released_at' => $this->from->copy()->addDays(2)]
        );

        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'detail' => 'equipment',
            'item' => $item->id,
        ]));

        $response->assertOk();
        $response->assertSee('Monoblock Chair', false);
        $response->assertSee('Requested quantity (expressed demand)', false);
        $response->assertSee('Released quantity (actual usage)', false);
        $response->assertSee('They are different measures and are never combined.', false);
    }

    public function test_unit_and_division_details_render_with_their_scope(): void
    {
        $this->request('ACADEMIC', 'College of Computer Studies');

        $unit = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'detail' => 'unit',
            'for' => 'College of Computer Studies',
            'unit' => 'College of Computer Studies',
        ]));

        $unit->assertOk();
        $unit->assertSee('Unit detail', false);
        $unit->assertSee('College of Computer Studies', false);

        $division = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'detail' => 'division',
            'for' => 'ACADEMIC',
        ]));

        $division->assertOk();
        $division->assertSee('Division detail', false);
    }

    public function test_each_return_kpi_opens_its_own_detail(): void
    {
        foreach ([
            'on-time' => 'Returned On Time',
            'late' => 'Returned Late',
            'overdue' => 'Currently Overdue',
            'accountability' => 'Open Accountability',
        ] as $state => $heading) {
            $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
                'section' => 'returns',
                'academic_period' => 'month',
                'detail' => 'returns',
                'state' => $state,
            ]));

            $response->assertOk();
            $response->assertSee($heading, false);
            $response->assertSee('View source records in Reports', false);
        }
    }

    public function test_a_zero_return_metric_still_explains_itself(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns',
            'academic_period' => 'month',
            'detail' => 'returns',
            'state' => 'late',
        ]));

        $response->assertOk();
        $response->assertSee('No late returns were recorded for this reporting period.', false);
    }

    public function test_predictive_detail_shows_the_calculation_not_a_reports_jump(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'predictive',
            'academic_period' => 'month',
            'detail' => 'forecast',
            'metric' => 'demand',
        ]));

        $response->assertOk();
        $response->assertSee('Forecasted Demand', false);

        /* With this dataset the guard holds, and the detail says why. */
        $response->assertSee('Not enough historical data', false);
        $response->assertDontSee('machine learning', false);
    }

    public function test_coverage_detail_withholds_a_rate_without_enough_history(): void
    {
        $item = $this->item('Monoblock Chair', 300);

        $this->releaseOnce($item, 30, $this->from->copy()->addDays(2));

        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'inventory',
            'academic_period' => 'month',
            'detail' => 'coverage',
            'item' => $item->id,
        ]));

        $response->assertOk();

        /*
         * The panel explains the requirement and withholds the derived
         * figures. "Risk classification" and "Estimated days of coverage" are
         * stat labels the detail only emits once the history is sufficient,
         * so their absence is what proves the guard held.
         */
        $response->assertSee('Insufficient usage history', false);
        $response->assertSee('before stock coverage is estimated', false);
        $response->assertDontSee('Risk classification', false);
        $response->assertDontSee('Estimated days of coverage', false);
    }

    public function test_an_unknown_detail_key_renders_the_section_without_a_panel(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'overview',
            'academic_period' => 'month',
            'detail' => 'not-a-detail',
        ]));

        $response->assertOk();
        $response->assertDontSee('aria-modal="true"', false);
    }

    public function test_closing_a_detail_returns_to_the_same_analytics_state(): void
    {
        $response = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
            'group' => 'ACADEMIC',
            'detail' => 'requests',
        ]));

        $response->assertOk();

        /* The close link keeps the tab and every filter, dropping only detail. */
        $response->assertSee(
            e(route('analytics.index', [
                'section' => 'demand',
                'academic_period' => 'month',
                'group' => 'ACADEMIC',
            ])),
            false
        );
    }

    public function test_a_missing_academic_period_is_surfaced_rather_than_relabelled(): void
    {
        /*
         * The month fallback still renders, but calling it a semester would
         * be a false reading rather than a small one.
         */
        $this->assertSame(0, \App\Models\AcademicPeriod::query()->count());

        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['academic_period' => 'semester']))
            ->assertOk()
            ->assertSee('No academic period is configured', false);
    }

    public function test_no_warning_is_shown_for_a_month_scope(): void
    {
        $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['academic_period' => 'month']))
            ->assertOk()
            ->assertDontSee('No academic period is configured', false);
    }

    public function test_analytics_makes_no_machine_learning_claim(): void
    {
        $response = $this->actingAs($this->spmuHead())
            ->get(route('analytics.index', ['section' => 'predictive']));

        $response->assertOk();
        $response->assertSee('Weighted Moving Average', false);

        foreach (['machine learning', 'artificial intelligence', 'trained model', 'AI model'] as $claim) {
            $response->assertDontSee($claim, false);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /** Stock coverage over the fixture period unless another is given. */
    private function coverage(?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->analytics->stockCoverage(
            app(InventoryService::class),
            $from ?? $this->from,
            $to ?? $this->to
        );
    }

    /**
     * One physical release of an item, as its own custody transaction.
     *
     * Separate transactions are what the minimum-release rule counts, so the
     * fixture has to create them separately rather than adding lines to one.
     */
    private function releaseOnce(InventoryItem $item, int $quantity, Carbon $releasedAt): void
    {
        $this->request(
            'ACADEMIC',
            'College of Computer Studies',
            RequestStatus::ApprovedReadyForRelease,
            createdAt: $releasedAt->copy()->subDay(),
            lines: [['item' => $item, 'released' => $quantity]],
            custody: ['status' => 'ACTIVE', 'released_at' => $releasedAt]
        );
    }

    private function spmuHead(): User
    {
        return User::factory()->create([
            'access_classification' => AccessClassification::SpmuHead,
        ]);
    }


    private function borrower(?string $name = null): User
    {
        return User::factory()->create(array_filter([
            'access_classification' => AccessClassification::BorrowerOnly,
            'organizational_unit_id' => $this->unit->id,
            'full_name' => $name,
        ]));
    }

    private function item(string $description, int $total): InventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(
            ['category_code' => 'RESTR'],
            ['category_name' => 'Restructure Fixture', 'active' => true]
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
     * @param  array<int, array{item: InventoryItem, released: int}>  $lines
     */
    private function request(
        string $division,
        string $unit,
        RequestStatus $status = RequestStatus::UnderSpmu,
        ?Carbon $createdAt = null,
        ?User $borrower = null,
        array $lines = [],
        ?array $custody = null,
    ): BorrowingRequest {
        $createdAt ??= $this->from->copy()->addDays(2);
        $borrower ??= $this->borrower();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $version = RequestVersion::query()->create([
            'request_id' => $request->id,
            'version_no' => 1,
            'purpose_event' => 'Restructure fixture activity',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit,
            'schedule_date' => $createdAt->copy()->addDay()->toDateString(),
            'return_date' => $createdAt->copy()->addDays(3)->toDateString(),
            'needed_from' => $createdAt->copy()->addDay()->startOfDay(),
            'return_due_at' => $createdAt->copy()->addDays(3)->endOfDay(),
        ]);

        if ($custody === null) {
            return $request->refresh();
        }

        $transaction = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 999999),
            'request_id' => $request->id,
            'request_version_id' => $version->id,
            'borrower_user_id' => $borrower->id,
            'status' => $custody['status'],
            'due_at' => $custody['due_at'] ?? $createdAt->copy()->addDays(3)->endOfDay(),
            'released_at' => $custody['released_at'] ?? null,
            'closed_at' => $custody['closed_at'] ?? null,
        ]);

        $transaction->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

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
                'returned_quantity' => 0,
            ]);
        }

        return $request->refresh();
    }
}
