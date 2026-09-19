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
use App\Reports\ReportFilters;
use App\Services\AnalyticsService;
use App\Services\ReportService;
use App\Services\ReturnMetricsService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Overdue Aging: the current overdue backlog by days past due.
 *
 * The population is the Currently Overdue KPI's own query, so the one thing
 * that matters most is guarded first: the bands always sum to that KPI under
 * the same filters. The bands themselves are exact at every boundary, the
 * reporting period plays no part, and a custody that has come back - on time
 * or late - is never in the backlog.
 *
 * Today is 20 April 2026 throughout.
 */
class AnalyticsOverdueAgingTest extends TestCase
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
            'unit_code' => 'AGING',
            'unit_name' => 'Aging Fixture Unit',
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
    /* Bands and boundaries                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_bands_are_exact_at_every_boundary(): void
    {
        $band = fn (int $days): string => $this->analytics->overdueAgingBand($days);

        $this->assertSame('1-7', $band(1));
        $this->assertSame('1-7', $band(7));
        $this->assertSame('8-30', $band(8));
        $this->assertSame('8-30', $band(30));
        $this->assertSame('31-plus', $band(31));
        $this->assertSame('31-plus', $band(400));

        /* Flagged overdue but not a full day past due, or no due date: banded apart, never forced into 1–7. */
        $this->assertSame('unbanded', $band(0));
        $this->assertSame('unbanded', $this->analytics->overdueAgingBand(null));

        $this->assertSame(
            [['1-7', 1, 7], ['8-30', 8, 30], ['31-plus', 31, null]],
            array_map(static fn (array $b): array => [$b['key'], $b['min'], $b['max']], AnalyticsService::OVERDUE_AGING_BANDS)
        );
    }

    public function test_each_overdue_custody_lands_in_the_band_its_days_past_due_dictate(): void
    {
        /* 20 April: due 19 Apr = 1 day, 13 Apr = 7, 12 Apr = 8, 21 Mar = 30, 20 Mar = 31. */
        $one = $this->custody(dueAt: Carbon::create(2026, 4, 19));
        $seven = $this->custody(dueAt: Carbon::create(2026, 4, 13));
        $eight = $this->custody(dueAt: Carbon::create(2026, 4, 12));
        $thirty = $this->custody(dueAt: Carbon::create(2026, 3, 21));
        $thirtyOne = $this->custody(dueAt: Carbon::create(2026, 3, 20));

        $this->assertSame(1, $this->analytics->daysOverdue($one));
        $this->assertSame(7, $this->analytics->daysOverdue($seven));
        $this->assertSame(8, $this->analytics->daysOverdue($eight));
        $this->assertSame(30, $this->analytics->daysOverdue($thirty));
        $this->assertSame(31, $this->analytics->daysOverdue($thirtyOne));

        $counts = $this->counts();

        $this->assertSame(2, $counts['1-7']);
        $this->assertSame(2, $counts['8-30']);
        $this->assertSame(1, $counts['31-plus']);
    }

    /* ------------------------------------------------------------------ */
    /* Reconciliation with the KPI                                         */
    /* ------------------------------------------------------------------ */

    public function test_the_bands_sum_to_the_currently_overdue_kpi_and_count_each_custody_once(): void
    {
        /* Every route into the KPI: flagged OVERDUE, past due but not yet flagged, past due mid-return. */
        $this->custody(dueAt: Carbon::create(2026, 4, 18), status: 'OVERDUE');
        $this->custody(dueAt: Carbon::create(2026, 4, 10), status: 'ACTIVE');
        $this->custody(dueAt: Carbon::create(2026, 4, 1), status: 'RETURN_PROCESSING');
        $this->custody(dueAt: Carbon::create(2026, 2, 1), status: 'OVERDUE');

        /* Noise the KPI leaves out. */
        $this->custody(dueAt: Carbon::create(2026, 4, 25), status: 'ACTIVE');
        $this->custody(dueAt: Carbon::create(2026, 4, 1), status: 'CLOSED', closedAt: Carbon::create(2026, 4, 3));

        $aging = $this->analytics->overdueAging(null, null);
        $kpi = $this->analytics->returns($this->from, $this->to, null, null)['overdue'];

        $this->assertSame(4, $kpi);
        $this->assertSame($kpi, $aging['total']);
        $this->assertSame($kpi, array_sum(array_column($aging['groups'], 'count')) + $aging['unbanded']);
        $this->assertSame($kpi, $this->analytics->overview($this->from, $this->to, null, null)['needs_follow_up']);
        $this->assertSame($kpi, $this->analytics->currentOverdue(null, null, 100)['total']);

        /* The listing behind the detail is the same population, each custody once. */
        $records = $this->analytics->overdueAgingRecords(null, null, null, null, 100);
        $this->assertSame($kpi, $records['total']);
        $this->assertSame($kpi, $records['rows']->pluck('id')->unique()->count());

        /* 18 Apr = 2 days; 10 Apr = 10 and 1 Apr = 19; 1 Feb = 78. */
        $this->assertSame([1, 2, 1], array_column($aging['groups'], 'count'));
        $this->assertSame([25.0, 50.0, 25.0], array_column($aging['groups'], 'share'));
        $this->assertSame([50, 100, 50], array_column($aging['groups'], 'width'));
    }

    public function test_a_custody_flagged_overdue_before_a_full_day_has_passed_still_counts_in_the_total(): void
    {
        /* Due today but already flagged: in the KPI, so in the total; not in a day band. */
        $this->custody(dueAt: Carbon::create(2026, 4, 20), status: 'OVERDUE');
        $this->custody(dueAt: Carbon::create(2026, 4, 10));

        $aging = $this->analytics->overdueAging(null, null);

        $this->assertSame(2, $aging['total']);
        $this->assertSame(1, $aging['unbanded']);
        $this->assertSame(1, array_sum(array_column($aging['groups'], 'count')));
        $this->assertSame($this->analytics->returns($this->from, $this->to, null, null)['overdue'], $aging['total']);
    }

    public function test_hidden_unbanded_records_do_not_shrink_the_drawn_aging_bands(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->custody(dueAt: Carbon::create(2026, 4, 20), status: 'OVERDUE');
        }
        $this->custody(dueAt: Carbon::create(2026, 4, 10));

        $aging = $this->analytics->overdueAging(null, null);

        $this->assertSame(4, $aging['total']);
        $this->assertSame(3, $aging['unbanded']);
        $this->assertSame([0, 1, 0], array_column($aging['groups'], 'count'));
        $this->assertSame([0.0, 25.0, 0.0], array_column($aging['groups'], 'share'));
        $this->assertSame([0, 100, 0], array_column($aging['groups'], 'width'));
    }

    /* ------------------------------------------------------------------ */
    /* What is never in the backlog                                        */
    /* ------------------------------------------------------------------ */

    public function test_returned_on_time_returned_late_future_due_and_closed_custody_are_excluded(): void
    {
        /* Returned on time: closed before the due date. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10), status: 'CLOSED', closedAt: Carbon::create(2026, 4, 8));

        /* Returned late: physically received after the due date, closed. */
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12));

        /* Not yet due: tomorrow, and today (a due date is not past until the next day). */
        $this->custody(dueAt: Carbon::create(2026, 4, 21));
        $this->custody(dueAt: Carbon::create(2026, 4, 20));

        /* Cancelled before release, and closed (on time) outside the period. */
        $this->custody(dueAt: Carbon::create(2026, 4, 1), status: 'CANCELLED', closedAt: Carbon::create(2026, 3, 30), releasedAt: null);
        $this->custody(dueAt: Carbon::create(2026, 4, 1), status: 'CLOSED', closedAt: Carbon::create(2026, 3, 30));

        /* One genuinely overdue custody so the reading is not simply empty. */
        $this->custody(dueAt: Carbon::create(2026, 4, 15));

        $aging = $this->analytics->overdueAging(null, null);
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame(1, $aging['total']);
        $this->assertSame(1, $returns['overdue']);

        /* The late one is a completed return, counted there and only there. */
        $this->assertSame(1, $returns['late']);
        $this->assertSame(1, $returns['on_time']);
        $this->assertSame(1, $this->analytics->overdueAgingRecords(null, null, null, null, 100)['rows']->count());
    }

    /**
     * Physical custody and administrative closure are different lifecycles.
     *
     * A custody fully received back after its due date is a completed late
     * return. Accountability then keeps it OBLIGATION_OPEN with closed_at
     * null - a legitimate post-return state - and that must not make it
     * "out". The population behind Currently Out / Currently Overdue reads
     * the physical signal (outstanding line quantity, and for linen the
     * laundry receipt), never the administrative status or closure stamp.
     */
    public function test_a_returned_late_custody_held_open_by_accountability_is_not_currently_out_or_overdue(): void
    {
        $custody = $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 5),
            receivedAt: Carbon::create(2026, 4, 12),
            status: 'OBLIGATION_OPEN',
            closedAt: null
        );

        $fresh = $custody->fresh(['lines', 'returns', 'laundryJob']);

        /* The state the accountability workflow legitimately leaves behind. */
        $this->assertSame('OBLIGATION_OPEN', $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertSame(0.0, app(ReturnMetricsService::class)->quantities($fresh)['outstanding']);
        $this->assertSame(ReturnMetricsService::RETURNED_LATE, app(ReturnMetricsService::class)->state($fresh));

        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        /* A completed late return, counted once, there and only there. */
        $this->assertSame(1, $returns['late']);
        $this->assertSame(0, $returns['overdue']);
        $this->assertSame(0, $overview['on_custody']);
        $this->assertSame(0, $overview['needs_follow_up']);
        $this->assertSame(0, $this->analytics->currentOverdue(null, null, 100)['total']);
        $this->assertSame(0, $this->analytics->overdueAging(null, null)['total']);
        $this->assertFalse($this->analytics->overdueAging(null, null)['available']);
    }

    public function test_a_returned_on_time_custody_held_open_by_accountability_is_not_currently_out(): void
    {
        /* Received before the due date; an incident keeps accountability open. */
        $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 15),
            receivedAt: Carbon::create(2026, 4, 12),
            status: 'INCIDENT_OPEN',
            closedAt: null
        );

        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        $this->assertSame(1, $returns['on_time']);
        $this->assertSame(0, $returns['overdue']);
        $this->assertSame(0, $overview['on_custody']);
        $this->assertSame(0, $this->analytics->overdueAging(null, null)['total']);
    }

    public function test_administrative_status_alone_never_decides_physical_custody(): void
    {
        /*
         * OBLIGATION_OPEN with property genuinely still outstanding - a
         * partial return inspected, one unit never brought back. Status is
         * the same as the returned case above; the physical facts differ.
         */
        $partial = $this->custody(dueAt: Carbon::create(2026, 4, 10), status: 'OBLIGATION_OPEN', quantity: 3);
        $partial->lines()->update(['returned_quantity' => 2]);

        /* And a partial return that is not yet due. */
        $future = $this->custody(dueAt: Carbon::create(2026, 4, 25), status: 'RETURN_PROCESSING', quantity: 2);
        $future->lines()->update(['returned_quantity' => 1]);

        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $overview = $this->analytics->overview($this->from, $this->to, null, null);

        $this->assertSame(2, $overview['on_custody']);
        $this->assertSame(1, $returns['overdue']);
        $this->assertSame(0, $returns['late']);
        $this->assertSame(1, $this->analytics->overdueAging(null, null)['total']);
        $this->assertSame(1, $this->analytics->overdueAging(null, null)['groups'][1]['count']);
    }

    public function test_linen_stays_out_until_laundry_physically_receives_it(): void
    {
        /*
         * Linen inspected from the Laundry Form: the line is accounted for,
         * but the authoritative physical receipt is the laundry worker's.
         * Until worker_received_at exists the linen is still out - and, past
         * due, still overdue - exactly as ReturnMetricsService reads it.
         */
        $linen = $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 5),
            receivedAt: Carbon::create(2026, 4, 12),
            status: 'RETURN_PROCESSING',
            closedAt: null,
            laundry: true
        );

        $fresh = $linen->fresh(['lines', 'returns', 'laundryJob']);
        $this->assertSame(0.0, app(ReturnMetricsService::class)->quantities($fresh)['outstanding']);
        $this->assertSame(ReturnMetricsService::CURRENTLY_OVERDUE, app(ReturnMetricsService::class)->state($fresh));

        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame(1, $this->analytics->overview($this->from, $this->to, null, null)['on_custody']);
        $this->assertSame(1, $returns['overdue']);
        $this->assertSame(0, $returns['late']);
        $this->assertSame(1, $this->analytics->overdueAging(null, null)['total']);

        /* Laundry personnel physically receive it: now it is a completed (late) return. */
        LaundryJob::query()->create([
            'custody_transaction_id' => $linen->id,
            'status' => 'TURNED_OVER_TO_LAUNDRY',
            'worker_received_at' => Carbon::create(2026, 4, 14, 10),
        ]);

        $fresh = $linen->fresh(['lines', 'returns', 'laundryJob']);
        $this->assertSame(ReturnMetricsService::RETURNED_LATE, app(ReturnMetricsService::class)->state($fresh));

        /* The service memoises a scope's completed returns for its own lifetime; a new request is a new instance. */
        $this->analytics = app(AnalyticsService::class);

        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame(0, $this->analytics->overview($this->from, $this->to, null, null)['on_custody']);
        $this->assertSame(0, $returns['overdue']);
        $this->assertSame(1, $returns['late']);
        $this->assertSame(0, $this->analytics->overdueAging(null, null)['total']);
    }

    public function test_analytics_currently_overdue_and_reports_currently_overdue_mean_the_same_thing(): void
    {
        /* Genuinely outstanding and past due. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10));

        /* Physically returned late, accountability open. */
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12), status: 'OBLIGATION_OPEN', closedAt: null);

        $report = app(ReportService::class)->generate(
            ReportFilters::fromRequest(
                Request::create('/reports', 'GET', ['return_status' => 'CURRENTLY_OVERDUE']),
                'returns',
                $this->from,
                $this->to,
                'month'
            )
        );

        $this->assertSame(1, $this->analytics->returns($this->from, $this->to, null, null)['overdue']);
        $this->assertSame(1, $report->summary['Currently overdue']);
        $this->assertSame(1, $report->rows->count());
    }

    /* ------------------------------------------------------------------ */
    /* Filters and period                                                  */
    /* ------------------------------------------------------------------ */

    public function test_division_unit_and_borrower_filters_match_the_kpi(): void
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        $this->custody(dueAt: Carbon::create(2026, 4, 18), borrower: $alice);
        $this->custody(dueAt: Carbon::create(2026, 4, 10), borrower: $bob);
        $this->custody(dueAt: Carbon::create(2026, 3, 1), borrower: $bob, division: 'ADMINISTRATION', unit: $this->hrmo());

        foreach ([
            [null, null, null],
            ['ACADEMIC', null, null],
            ['ADMINISTRATION', $this->hrmo(), null],
            [null, null, $alice->id],
            [null, null, $bob->id],
            ['ACADEMIC', null, $bob->id],
        ] as [$division, $unit, $borrower]) {
            $aging = $this->analytics->overdueAging($division, $unit, $borrower);
            $kpi = $this->analytics->returns($this->from, $this->to, $division, $unit, $borrower)['overdue'];

            $this->assertSame($kpi, $aging['total'], "scope $division/$unit/$borrower");
            $this->assertSame($kpi, array_sum(array_column($aging['groups'], 'count')) + $aging['unbanded']);
        }

        $this->assertSame(2, $this->analytics->overdueAging('ACADEMIC', null)['total']);
        $this->assertSame(1, $this->analytics->overdueAging('ADMINISTRATION', $this->hrmo())['total']);
        $this->assertSame(1, $this->analytics->overdueAging(null, null, $alice->id)['total']);
        $this->assertSame(1, $this->analytics->overdueAging('ACADEMIC', null, $bob->id)['total']);
        $this->assertSame(1, $this->analytics->overdueAging(null, null, $bob->id)['groups'][2]['count']);
    }

    public function test_the_reporting_period_never_removes_an_older_still_overdue_custody(): void
    {
        /* Released in January, still out: overdue in April however the period is set. */
        $this->custody(dueAt: Carbon::create(2026, 1, 10), releasedAt: Carbon::create(2026, 1, 5, 10));

        $this->assertSame(1, $this->analytics->overdueAging(null, null)['total']);
        $this->assertSame('31-plus', $this->analytics->overdueAgingBand(100));
        $this->assertSame(1, $this->analytics->overdueAging(null, null)['groups'][2]['count']);

        $head = $this->spmuHead();

        foreach (['week', 'month'] as $period) {
            $page = $this->actingAs($head)->get(route('analytics.index', ['section' => 'returns', 'academic_period' => $period]));

            $page->assertOk();
            $card = $this->agingCard($page->getContent());
            $this->assertSame('1 overdue', trim($this->xpath($page->getContent())->query('.//*[contains(@class,"analytics-count-pill")]', $card)->item(0)->textContent));
            $this->assertStringNotContainsString('Total currently overdue', $card->textContent);
            $this->assertStringContainsString('not limited to the reporting period', $card->textContent);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    public function test_the_card_draws_three_bands_with_counts_and_no_period_comparison(): void
    {
        $this->custody(dueAt: Carbon::create(2026, 4, 18));
        $this->custody(dueAt: Carbon::create(2026, 4, 15));
        $this->custody(dueAt: Carbon::create(2026, 4, 5));
        $this->custody(dueAt: Carbon::create(2026, 3, 1));

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']));
        $page->assertOk();

        $html = $page->getContent();
        $x = $this->xpath($html);
        $card = $this->agingCard($html);

        $this->assertStringContainsString('Overdue Aging', $card->textContent);
        $this->assertStringContainsString('Current overdue borrowings grouped by days past due.', $card->textContent);

        $rows = $x->query('.//ul[contains(@class,"analytics-aging-bands")]/li/a', $card);
        $this->assertSame(3, $rows->length);

        $first = $rows->item(0);
        $this->assertSame('1–7 days past due', $first->getAttribute('data-tip-title'));
        $this->assertSame([['Borrowings', '2'], ['Share of currently overdue', '50%']], json_decode($first->getAttribute('data-tip-rows'), true));
        $this->assertSame('width: 100%', $x->query('.//*[contains(@class,"analytics-aging-fill")]', $first)->item(0)->getAttribute('style'));
        $this->assertStringContainsString('detail=overdue-aging', $first->getAttribute('href'));
        $this->assertStringContainsString('bucket=1-7', $first->getAttribute('href'));

        $third = $rows->item(2);
        $this->assertSame('31+ days past due', $third->getAttribute('data-tip-title'));
        $this->assertSame('width: 50%', $x->query('.//*[contains(@class,"analytics-aging-fill")]', $third)->item(0)->getAttribute('style'));
        $this->assertStringContainsString('bucket=31-plus', $third->getAttribute('href'));

        $this->assertSame('4 overdue', trim($x->query('.//*[contains(@class,"analytics-count-pill")]', $card)->item(0)->textContent));
        $this->assertStringNotContainsString('Total currently overdue', $card->textContent);
        $this->assertStringContainsString('1 current overdue borrowing has been overdue for more than 30 days.', $card->textContent);

        /* Current state: no period-over-period line anywhere on the card. */
        $this->assertSame(0, $x->query('.//*[@data-period-delta]', $card)->length);
        $this->assertStringNotContainsString('vs previous period', $card->textContent);

        $this->assertStringContainsString('detail=overdue-aging', $card->getAttribute('data-card-detail'));

        /* The KPI above agrees. */
        $kpi = $x->query('//a[contains(@class,"analytics-kpi-card")][.//*[normalize-space(text())="Currently Overdue"]]//*[contains(@class,"analytics-kpi-card-value")]')->item(0);
        $this->assertSame('4', trim($kpi->textContent));
    }

    public function test_an_empty_backlog_is_a_sentence_not_a_row_of_zeroes(): void
    {
        $this->assertFalse($this->analytics->overdueAging(null, null)['available']);
        $this->assertSame([null, null, null], array_column($this->analytics->overdueAging(null, null)['groups'], 'share'));
        $this->assertNull($this->analytics->overdueAging(null, null)['insight']);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']));
        $page->assertOk();

        $html = $page->getContent();
        $x = $this->xpath($html);
        $card = $this->agingCard($html);

        $this->assertStringContainsString('No borrowings are currently overdue.', $card->textContent);
        $this->assertSame(0, $x->query('.//ul[contains(@class,"analytics-aging-bands")]', $card)->length);
        $this->assertStringNotContainsString('1–7 days', $card->textContent);

        $kpi = $x->query('//a[contains(@class,"analytics-kpi-card")][.//*[normalize-space(text())="Currently Overdue"]]//*[contains(@class,"analytics-kpi-card-value")]')->item(0);
        $this->assertSame('0', trim($kpi->textContent));
    }

    /* ------------------------------------------------------------------ */
    /* Details                                                             */
    /* ------------------------------------------------------------------ */

    public function test_the_whole_card_detail_reconciles_and_lists_longest_overdue_first(): void
    {
        $this->custody(dueAt: Carbon::create(2026, 4, 18), custodyNo: 'CUS-B');
        $this->custody(dueAt: Carbon::create(2026, 4, 18), custodyNo: 'CUS-A');
        $this->custody(dueAt: Carbon::create(2026, 4, 5), custodyNo: 'CUS-C');
        $this->custody(dueAt: Carbon::create(2026, 3, 1), custodyNo: 'CUS-D', division: 'ADMINISTRATION', unit: $this->hrmo());

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'overdue-aging',
        ]));

        $page->assertOk();
        $page->assertSee('data-analytics-detail-panel', false);
        $page->assertSee('Overdue Aging');
        $page->assertSee('Current state as of today, not limited to the reporting period');

        $x = $this->xpath($page->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);

        $stats = [];
        foreach ($x->query('.//div[contains(@class,"analytics-stat ")]', $panel) as $stat) {
            $stats[trim($x->query('./span', $stat)->item(0)->textContent)] = trim($x->query('./strong', $stat)->item(0)->textContent);
        }
        $this->assertSame('4', $stats['Currently overdue']);

        /* Longest overdue first, then custody number for equal ages. */
        $rows = $x->query('.//table//tbody/tr', $panel);
        $this->assertSame(4, $rows->length);
        $this->assertSame(['CUS-D', 'CUS-C', 'CUS-A', 'CUS-B'], $this->column($x, $rows, 0));
        $this->assertSame(['50', '15', '2', '2'], $this->column($x, $rows, 5));
        $this->assertSame('Human Resource Management Office', $this->column($x, $rows, 3)[0]);

        $page->assertSee('return_status=CURRENTLY_OVERDUE', false);
        $page->assertSee('View source records');
    }

    public function test_a_band_detail_shows_only_that_band_and_is_truthful_about_reports(): void
    {
        $this->custody(dueAt: Carbon::create(2026, 4, 18), custodyNo: 'CUS-YOUNG');
        $this->custody(dueAt: Carbon::create(2026, 4, 5), custodyNo: 'CUS-MID');
        $this->custody(dueAt: Carbon::create(2026, 4, 1), custodyNo: 'CUS-MID-2');
        $this->custody(dueAt: Carbon::create(2026, 3, 1), custodyNo: 'CUS-OLD');

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'overdue-aging', 'bucket' => '8-30',
        ]));

        $page->assertOk();
        $page->assertSee('Overdue 8–30 days');
        $page->assertSee('50% of currently overdue');

        $x = $this->xpath($page->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);
        $rows = $x->query('.//table//tbody/tr', $panel);

        $this->assertSame(2, $rows->length);
        $this->assertSame(['CUS-MID-2', 'CUS-MID'], $this->column($x, $rows, 0));
        $this->assertSame(['19', '15'], $this->column($x, $rows, 5));

        /* Reports cannot narrow to a band: the link opens every currently overdue record and the note says so. */
        $source = $x->query('.//footer//a', $panel)->item(0)->getAttribute('href');
        $this->assertStringContainsString('return_status=CURRENTLY_OVERDUE', $source);
        $this->assertStringNotContainsString('bucket', $source);
        $page->assertSee('Reports has no days-overdue filter');

        /* An empty band explains itself. */
        $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'overdue-aging', 'bucket' => '31-plus',
        ]))->assertOk()->assertSee('Overdue 31+ days');

        /* An unknown band is not a detail. */
        $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'overdue-aging', 'bucket' => '99-plus',
        ]))->assertOk()->assertDontSee('data-analytics-detail-panel', false);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect($this->analytics->overdueAging(null, null)['groups'])->pluck('count', 'key')->all();
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

    private function agingCard(string $html): \DOMNode
    {
        $card = $this->xpath($html)->query('//section[contains(@class,"analytics-aging")]')->item(0);
        $this->assertNotNull($card, 'The Overdue Aging card is on the page.');

        return $card;
    }

    /** @return list<string> */
    private function column(DOMXPath $x, \DOMNodeList $rows, int $index): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values[] = trim($x->query('./td', $row)->item($index)->textContent);
        }

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
            ['category_code' => 'AGING'],
            ['category_name' => 'Aging Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => $laundry ? 'Aging Fixture Linen' : 'Aging Fixture Chair'],
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

    /**
     * One released custody with one line, due on $dueAt (end of day, as the
     * workflow stores it).
     */
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
            'purpose_event' => 'Aging fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit ?? $this->ccs(),
            'schedule_date' => $dueAt->copy()->subDays(3)->toDateString(),
            'return_date' => $dueAt->toDateString(),
            'needed_from' => $dueAt->copy()->subDays(3)->startOfDay(),
            'return_due_at' => $dueAt->copy()->endOfDay(),
            'submitted_at' => $dueAt->copy()->subDays(10),
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

    /**
     * A custody physically received back on $receivedAt through a Return
     * Inspection receipt - the authoritative completion event.
     */
    private function completedReturn(
        Carbon $dueAt,
        Carbon $receivedAt,
        string $status = 'CLOSED',
        ?Carbon $closedAt = new Carbon('2026-04-13 10:00:00'),
        bool $laundry = false
    ): CustodyTransaction {
        $custody = $this->custody(dueAt: $dueAt, status: $status, closedAt: $closedAt, laundry: $laundry);
        $custody->lines()->update(['returned_quantity' => 1]);

        $return = ReturnTransaction::query()->create([
            'return_no' => 'RT-'.fake()->unique()->numberBetween(100000, 9999999),
            'custody_transaction_id' => $custody->id,
            'received_by_user_id' => $this->spmuHead()->id,
            'return_type' => 'OVERDUE',
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
