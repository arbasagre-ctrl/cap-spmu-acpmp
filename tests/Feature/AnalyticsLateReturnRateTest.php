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
use App\Services\AnalyticsService;
use App\Services\ReturnMetricsService;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Late Return Patterns: late-return share of completed returns by
 * organisation.
 *
 * The denominator is the completed-return population behind Returned On
 * Time and Returned Late - physical completion date, ReturnMetricsService
 * classification, request-version attribution - and nothing else: not
 * requests filed, not quantities, not the current backlog. Today is 20 April
 * 2026 throughout; the selected period is April.
 */
class AnalyticsLateReturnRateTest extends TestCase
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
            'unit_code' => 'LRR',
            'unit_name' => 'Late Rate Fixture Unit',
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
    /* Who is in the denominator, who is in the numerator                  */
    /* ------------------------------------------------------------------ */

    public function test_on_time_completions_count_in_the_denominator_only_and_late_ones_in_both(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8));
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 14));

        $rates = $this->rates('division');

        $this->assertTrue($rates['available']);
        $this->assertSame(2, $rates['completed']);
        $this->assertSame(1, $rates['on_time']);
        $this->assertSame(1, $rates['late']);
        $this->assertSame(50.0, $rates['late_rate']);

        $academic = $this->group($rates, 'ACADEMIC');
        $this->assertSame(['completed' => 2, 'on_time' => 1, 'late' => 1, 'late_rate' => 50.0], $this->figures($academic));
    }

    public function test_unreturned_custody_is_never_in_the_denominator(): void
    {
        /* Past due and still out: currently overdue, not a completed return. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10));

        /* Not yet due and still out. */
        $this->custody(dueAt: Carbon::create(2026, 4, 25));

        /* One completed return so the cohort exists. */
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8));

        $rates = $this->rates('division');
        $returns = $this->analytics->returns($this->from, $this->to, null, null);

        $this->assertSame(1, $rates['completed']);
        $this->assertSame(0, $rates['late']);
        $this->assertSame(0.0, $this->group($rates, 'ACADEMIC')['late_rate']);
        $this->assertSame(1, $returns['overdue']);
        $this->assertSame($returns['completed'], $rates['completed']);
    }

    public function test_a_late_return_with_open_accountability_is_a_late_completed_return_and_not_overdue(): void
    {
        $custody = $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 5),
            receivedAt: Carbon::create(2026, 4, 12),
            status: 'OBLIGATION_OPEN',
            closedAt: null
        );

        $fresh = $custody->fresh(['lines', 'returns', 'laundryJob']);
        $this->assertSame('OBLIGATION_OPEN', $fresh->status);
        $this->assertNull($fresh->closed_at);
        $this->assertSame(ReturnMetricsService::RETURNED_LATE, app(ReturnMetricsService::class)->state($fresh));

        $rates = $this->rates('division');
        $academic = $this->group($rates, 'ACADEMIC');

        $this->assertSame(['completed' => 1, 'on_time' => 0, 'late' => 1, 'late_rate' => 100.0], $this->figures($academic));

        /* Phase 1C.1 semantics hold alongside: not out, not overdue, not aging. */
        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $overview = $this->analytics->overview($this->from, $this->to, null, null);
        $this->assertSame(1, $returns['late']);
        $this->assertSame(0, $returns['overdue']);
        $this->assertSame(0, $overview['on_custody']);
        $this->assertSame(0, $this->analytics->overdueAging(null, null)['total']);
    }

    public function test_linen_enters_the_denominator_only_once_laundry_physically_receives_it(): void
    {
        $linen = $this->completedReturn(
            dueAt: Carbon::create(2026, 4, 5),
            receivedAt: Carbon::create(2026, 4, 12),
            status: 'RETURN_PROCESSING',
            closedAt: null,
            laundry: true
        );

        /* Inspected from the Laundry Form, not yet received by laundry: not completed. */
        $before = $this->rates('division');
        $this->assertFalse($before['available']);
        $this->assertSame(0, $before['completed']);
        $this->assertSame(1, $this->analytics->returns($this->from, $this->to, null, null)['overdue']);

        LaundryJob::query()->create([
            'custody_transaction_id' => $linen->id,
            'status' => 'TURNED_OVER_TO_LAUNDRY',
            'worker_received_at' => Carbon::create(2026, 4, 14, 10),
        ]);

        /* The service memoises a scope's completed returns for its own lifetime; a new request is a new instance. */
        $this->analytics = app(AnalyticsService::class);

        /* Received on 14 April, due 5 April: a late completed return. */
        $after = $this->rates('division');
        $this->assertSame(1, $after['completed']);
        $this->assertSame(1, $after['late']);
        $this->assertSame(100.0, $this->group($after, 'ACADEMIC')['late_rate']);
        $this->assertSame(0, $this->analytics->returns($this->from, $this->to, null, null)['overdue']);
    }

    /* ------------------------------------------------------------------ */
    /* Formulas, identity and reconciliation                               */
    /* ------------------------------------------------------------------ */

    public function test_division_and_unit_rates_are_late_over_completed_in_the_same_segment(): void
    {
        /* Academic / CCS: 20 completed, 4 late. Administration / HRMO: 10 completed, 1 late. */
        $this->seedSegment('ACADEMIC', $this->ccs(), completed: 20, late: 4);
        $this->seedSegment('ADMINISTRATION', $this->hrmo(), completed: 10, late: 1);

        $byDivision = $this->rates('division');
        $this->assertSame(['completed' => 20, 'on_time' => 16, 'late' => 4, 'late_rate' => 20.0], $this->figures($this->group($byDivision, 'ACADEMIC')));
        $this->assertSame(['completed' => 10, 'on_time' => 9, 'late' => 1, 'late_rate' => 10.0], $this->figures($this->group($byDivision, 'ADMINISTRATION')));
        $this->assertSame(30, $byDivision['completed']);
        $this->assertSame(5, $byDivision['late']);
        $this->assertSame(16.7, $byDivision['late_rate']);

        /* Higher rate first, then completed, then label - and the leader is named factually. */
        $this->assertSame(['ACADEMIC', 'ADMINISTRATION'], array_column($byDivision['groups'], 'code'));
        $this->assertSame('Academic recorded 4 late returns among 20 completed returns.', $byDivision['summary']);

        $byUnit = $this->rates('unit');
        $this->assertSame(['completed' => 20, 'on_time' => 16, 'late' => 4, 'late_rate' => 20.0], $this->figures($this->group($byUnit, 'ACADEMIC|'.$this->ccs())));
        $this->assertSame(['completed' => 10, 'on_time' => 9, 'late' => 1, 'late_rate' => 10.0], $this->figures($this->group($byUnit, 'ADMINISTRATION|'.$this->hrmo())));

        /* Both breakdowns are the KPI population and nothing else. */
        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame($returns['completed'], array_sum(array_column($byDivision['groups'], 'completed')));
        $this->assertSame($returns['late'], array_sum(array_column($byDivision['groups'], 'late')));
        $this->assertSame($returns['completed'], array_sum(array_column($byUnit['groups'], 'completed')));

        /* Each unit row reconciles with the KPI filtered to that unit. */
        foreach ($byUnit['groups'] as $row) {
            $scoped = $this->analytics->returns($this->from, $this->to, $row['code'], $row['unit']);
            $this->assertSame($scoped['completed'], $row['completed'], $row['key']);
            $this->assertSame($scoped['late'], $row['late'], $row['key']);
        }
    }

    public function test_the_reading_names_the_segment_with_the_most_late_returns_not_the_highest_rate(): void
    {
        /* Academic: 5 late of 30 (16.7%). Administration: 2 late of 4 (50%). */
        $this->seedSegment('ACADEMIC', $this->ccs(), completed: 30, late: 5);
        $this->seedSegment('ADMINISTRATION', $this->hrmo(), completed: 4, late: 2);

        $rates = $this->rates('division');

        /* The bars still rank by rate; the sentence follows the count. */
        $this->assertSame(['ADMINISTRATION', 'ACADEMIC'], array_column($rates['groups'], 'code'));
        $this->assertSame('Academic recorded 5 late returns among 30 completed returns.', $rates['summary']);
        $this->assertStringNotContainsString('worst', strtolower($rates['summary']));
    }

    public function test_the_reading_is_withheld_when_late_counts_tie(): void
    {
        /* Both segments have 2 late returns; their rates differ, but the count is the subject. */
        $this->seedSegment('ACADEMIC', $this->ccs(), completed: 10, late: 2);
        $this->seedSegment('ADMINISTRATION', $this->hrmo(), completed: 4, late: 2);

        $this->assertNull($this->rates('division')['summary']);
    }

    public function test_a_single_completed_return_reads_as_one_hundred_percent_of_one(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 5), receivedAt: Carbon::create(2026, 4, 12));

        $rates = $this->rates('unit');
        $row = $this->group($rates, 'ACADEMIC|'.$this->ccs());

        $this->assertSame(100.0, $row['late_rate']);
        $this->assertSame(1, $row['completed']);
        $this->assertSame(1, $row['late']);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']));
        $page->assertOk();

        $card = $this->card($page->getContent());
        $this->assertStringContainsString('100%', $card->textContent);
        $this->assertStringContainsString('1 late · 1 completed', $card->textContent);
    }

    public function test_no_completed_returns_is_a_sentence_not_a_zero_rate(): void
    {
        /* Out and overdue, but nothing has come back. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10));

        $rates = $this->rates('division');

        $this->assertFalse($rates['available']);
        $this->assertNull($rates['late_rate']);
        $this->assertSame([], $rates['groups']);
        $this->assertSame('No completed returns are available for late-return rate analysis in this period.', $rates['summary']);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']));
        $page->assertOk();

        $html = $page->getContent();
        $card = $this->card($html);
        $this->assertStringContainsString('No completed returns are available for late-return rate analysis in this period.', $card->textContent);
        $this->assertSame(0, $this->xpath($html)->query('.//ul[contains(@class,"analytics-laterate-rows")]', $card)->length);
        $this->assertStringNotContainsString('0%', $card->textContent);
    }

    public function test_a_segment_with_no_completed_returns_is_absent_rather_than_zero(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8));

        /* Administration has activity, but nothing completed. */
        $this->custody(dueAt: Carbon::create(2026, 4, 10), division: 'ADMINISTRATION', unit: $this->hrmo());

        $rates = $this->rates('division');

        $this->assertSame(['ACADEMIC'], array_column($rates['groups'], 'code'));
    }

    public function test_like_named_units_in_different_divisions_stay_apart(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 14), division: 'ACADEMIC', unit: 'Registrar');
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8), division: 'ADMINISTRATION', unit: 'Registrar');

        $rates = $this->rates('unit');

        $this->assertCount(2, $rates['groups']);
        $this->assertSame(['ACADEMIC|Registrar', 'ADMINISTRATION|Registrar'], array_column($rates['groups'], 'key'));
        $this->assertSame(100.0, $this->group($rates, 'ACADEMIC|Registrar')['late_rate']);
        $this->assertSame(0.0, $this->group($rates, 'ADMINISTRATION|Registrar')['late_rate']);
    }

    public function test_a_return_without_a_recorded_division_is_listed_as_unspecified_but_not_as_a_unit(): void
    {
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 14), division: '', unit: '');
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8));

        $byDivision = $this->rates('division');
        $this->assertSame(['unspecified', 'ACADEMIC'], array_column($byDivision['groups'], 'key'));
        $this->assertSame('Unspecified', $this->group($byDivision, 'unspecified')['label']);
        $this->assertSame(2, array_sum(array_column($byDivision['groups'], 'completed')));

        /* A nameless snapshot has no unit to report; the division view still carries it. */
        $byUnit = $this->rates('unit');
        $this->assertSame(['ACADEMIC|'.$this->ccs()], array_column($byUnit['groups'], 'key'));
        $this->assertSame(2, $byUnit['completed']);
    }

    /* ------------------------------------------------------------------ */
    /* Period and filters                                                  */
    /* ------------------------------------------------------------------ */

    public function test_the_period_follows_the_physical_completion_date(): void
    {
        /* Released in March, physically received in April: April's cohort. */
        $this->completedReturn(dueAt: Carbon::create(2026, 3, 25), receivedAt: Carbon::create(2026, 4, 2), releasedAt: Carbon::create(2026, 3, 20, 10));

        /* Received in March: March's cohort, whatever happened to the record later. */
        $this->completedReturn(dueAt: Carbon::create(2026, 3, 10), receivedAt: Carbon::create(2026, 3, 15), releasedAt: Carbon::create(2026, 3, 5, 10));

        $rates = $this->rates('division');

        $this->assertSame(1, $rates['completed']);
        $this->assertSame(1, $rates['late']);
    }

    public function test_division_unit_and_borrower_filters_scope_both_breakdowns(): void
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 14), borrower: $alice);
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8), borrower: $bob);
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 8), borrower: $bob, division: 'ADMINISTRATION', unit: $this->hrmo());
        $this->completedReturn(dueAt: Carbon::create(2026, 4, 10), receivedAt: Carbon::create(2026, 4, 15), borrower: $alice, division: 'ADMINISTRATION', unit: 'Registrar');

        /* Division: only that division's rows, and only its units. */
        $academic = $this->analytics->lateReturnRates($this->from, $this->to, 'ACADEMIC', null, null, 'division');
        $this->assertSame(['ACADEMIC'], array_column($academic['groups'], 'code'));
        $this->assertSame(2, $academic['completed']);

        $academicUnits = $this->analytics->lateReturnRates($this->from, $this->to, 'ACADEMIC', null, null, 'unit');
        $this->assertSame(['ACADEMIC|'.$this->ccs()], array_column($academicUnits['groups'], 'key'));

        $adminUnits = $this->analytics->lateReturnRates($this->from, $this->to, 'ADMINISTRATION', null, null, 'unit');
        $this->assertSame(['ADMINISTRATION|Registrar', 'ADMINISTRATION|'.$this->hrmo()], array_column($adminUnits['groups'], 'key'));

        /* Unit: reconciles to that unit alone. */
        $registrar = $this->analytics->lateReturnRates($this->from, $this->to, 'ADMINISTRATION', 'Registrar', null, 'unit');
        $this->assertSame(['ADMINISTRATION|Registrar'], array_column($registrar['groups'], 'key'));
        $this->assertSame(1, $registrar['completed']);
        $this->assertSame(1, $registrar['late']);

        /* Borrower: the selected borrower's completed returns, by their organisation. */
        $aliceOnly = $this->analytics->lateReturnRates($this->from, $this->to, null, null, $alice->id, 'division');
        $this->assertSame(2, $aliceOnly['completed']);
        $this->assertSame(2, $aliceOnly['late']);
        $this->assertSame(['ACADEMIC', 'ADMINISTRATION'], array_column($aliceOnly['groups'], 'code'));

        $bobOnly = $this->analytics->lateReturnRates($this->from, $this->to, null, null, $bob->id, 'division');
        $this->assertSame(2, $bobOnly['completed']);
        $this->assertSame(0, $bobOnly['late']);

        /* The scoped page states the borrower scope on the card. */
        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'borrower' => $alice->id,
        ]));
        $page->assertOk();
        $this->assertStringContainsString("Scoped to the selected borrower's completed returns.", $this->card($page->getContent())->textContent);
    }

    /* ------------------------------------------------------------------ */
    /* Rendering and details                                               */
    /* ------------------------------------------------------------------ */

    public function test_the_card_draws_rate_bars_on_a_hundred_point_track_with_counts_and_no_comparison(): void
    {
        $this->seedSegment('ACADEMIC', $this->ccs(), completed: 20, late: 4);
        $this->seedSegment('ADMINISTRATION', $this->hrmo(), completed: 10, late: 1);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', ['section' => 'returns', 'academic_period' => 'month']));
        $page->assertOk();

        $html = $page->getContent();
        $x = $this->xpath($html);
        $card = $this->card($html);

        $this->assertStringContainsString('Late Return Patterns', $card->textContent);
        $this->assertStringContainsString('Late-return share among completed returns in the selected period.', $card->textContent);

        $panels = $x->query('.//div[contains(@class,"analytics-laterate-panel")]', $card);
        $this->assertSame(2, $panels->length);

        $divisionRows = $x->query('.//ul[contains(@class,"analytics-laterate-rows")]/li/a', $panels->item(0));
        $this->assertSame(2, $divisionRows->length);

        $academic = $divisionRows->item(0);
        $this->assertSame('Academic', $academic->getAttribute('data-tip-title'));
        $this->assertSame(
            [['Late return rate', '20%'], ['Returned late', '4'], ['Completed returns', '20']],
            json_decode($academic->getAttribute('data-tip-rows'), true)
        );
        /* The bar is the rate on a 0-100 track: 20%, not 100% for the top row. */
        $this->assertSame('width: 20%', $x->query('.//*[contains(@class,"analytics-laterate-fill")]', $academic)->item(0)->getAttribute('style'));
        $this->assertStringContainsString('4 late · 20 completed', $academic->textContent);
        $this->assertStringContainsString('detail=late-rate', $academic->getAttribute('href'));
        $this->assertStringContainsString('level=division', $academic->getAttribute('href'));
        $this->assertStringContainsString('for=ACADEMIC', $academic->getAttribute('href'));

        $unitRows = $x->query('.//ul[contains(@class,"analytics-laterate-rows")]/li/a', $panels->item(1));
        $this->assertSame(2, $unitRows->length);
        $this->assertStringContainsString('segment=', $unitRows->item(0)->getAttribute('href'));
        $this->assertSame('width: 10%', $x->query('.//*[contains(@class,"analytics-laterate-fill")]', $unitRows->item(1))->item(0)->getAttribute('style'));

        $text = preg_replace('/\s+/', ' ', $card->textContent);
        /* The overall total is the Returns KPIs' reading; the card keeps only its sample note. */
        $this->assertStringNotContainsString('5 late of 30 completed returns in this period', $text);
        $this->assertStringContainsString('Rates are shown with their counts; a small count is a small sample.', $text);
        $this->assertStringContainsString('Academic recorded 4 late returns among 20 completed returns.', $text);

        /* No period-over-period line, and no grading language. */
        $this->assertSame(0, $x->query('.//*[@data-period-delta]', $card)->length);
        $this->assertStringNotContainsString('vs previous period', $card->textContent);
        foreach (['Worst', 'worst', 'Poor', 'Risk', 'Best'] as $word) {
            $this->assertStringNotContainsString($word, $card->textContent);
        }

        /* The Return Outcome figures it complements are untouched. */
        $returns = $this->analytics->returns($this->from, $this->to, null, null);
        $this->assertSame(30, $returns['completed']);
        $this->assertSame(5, $returns['late']);
        $this->assertSame(83.3, $returns['on_time_rate']);
    }

    public function test_details_reconcile_and_reports_opens_the_late_subset_of_the_scope(): void
    {
        $this->seedSegment('ACADEMIC', $this->ccs(), completed: 5, late: 2);
        $this->seedSegment('ADMINISTRATION', $this->hrmo(), completed: 4, late: 1);

        $head = $this->spmuHead();

        $whole = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'late-rate', 'level' => 'division',
        ]));
        $whole->assertOk();
        $whole->assertSee('data-analytics-detail-panel', false);
        $whole->assertSee('Late Return Rate by Division');
        $whole->assertSee('3 late of 9 completed');

        $x = $this->xpath($whole->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);
        $this->assertSame(9, $x->query('.//table//tbody/tr', $panel)->length);
        /* Late returns first. */
        $this->assertSame('Returned late', trim($x->query('.//table//tbody/tr[1]/td[7]', $panel)->item(0)->textContent));
        $this->assertSame('Returned on time', trim($x->query('.//table//tbody/tr[9]/td[7]', $panel)->item(0)->textContent));
        $source = $x->query('.//footer//a', $panel)->item(0)->getAttribute('href');
        $this->assertStringContainsString('return_status=RETURNED_LATE', $source);
        $this->assertStringContainsString('report=returns', $source);

        $row = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'late-rate', 'level' => 'unit',
            'for' => 'ADMINISTRATION', 'segment' => $this->hrmo(),
        ]));
        $row->assertOk();
        $row->assertSee($this->hrmo().' · Administrative');
        $row->assertSee('1 late of 4 completed');

        $x = $this->xpath($row->getContent());
        $panel = $x->query('//section[@data-analytics-detail-panel]')->item(0);
        $this->assertSame(4, $x->query('.//table//tbody/tr', $panel)->length);
        $source = $x->query('.//footer//a', $panel)->item(0)->getAttribute('href');
        $this->assertStringContainsString('return_status=RETURNED_LATE', $source);
        $this->assertStringContainsString('division=ADMINISTRATION', $source);
        $this->assertStringContainsString('unit=Human', $source);

        /* An unknown segment is not a detail. */
        $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'returns', 'academic_period' => 'month', 'detail' => 'late-rate', 'level' => 'division', 'for' => 'NOPE',
        ]))->assertOk()->assertDontSee('data-analytics-detail-panel', false);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function rates(string $level): array
    {
        return $this->analytics->lateReturnRates($this->from, $this->to, null, null, null, $level);
    }

    /** @return array<string, mixed> */
    private function group(array $rates, string $key): array
    {
        $row = collect($rates['groups'])->firstWhere('key', $key);
        $this->assertNotNull($row, "segment $key is listed");

        return $row;
    }

    /** @return array<string, int|float> */
    private function figures(array $row): array
    {
        return ['completed' => $row['completed'], 'on_time' => $row['on_time'], 'late' => $row['late'], 'late_rate' => $row['late_rate']];
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

    /** @var array<string, DOMXPath> */
    private array $parsed = [];

    private function card(string $html): \DOMNode
    {
        $card = $this->xpath($html)->query('//section[contains(@class,"analytics-laterate")]')->item(0);
        $this->assertNotNull($card, 'The Late Return Patterns card is on the page.');

        return $card;
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
            ['category_code' => 'LRR'],
            ['category_name' => 'Late Rate Fixture', 'active' => true]
        );

        $measure = UnitOfMeasure::query()->firstOrCreate(
            ['unit_code' => 'PC'],
            ['unit_name' => 'Piece', 'active' => true]
        );

        return InventoryItem::query()->firstOrCreate(
            ['unique_description' => $laundry ? 'Late Rate Fixture Linen' : 'Late Rate Fixture Chair'],
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

    /** $completed returns in one segment, $late of them received after the due date. */
    private function seedSegment(string $division, string $unit, int $completed, int $late): void
    {
        for ($i = 0; $i < $completed; $i++) {
            $this->completedReturn(
                dueAt: Carbon::create(2026, 4, 10),
                receivedAt: $i < $late ? Carbon::create(2026, 4, 12 + ($i % 5)) : Carbon::create(2026, 4, 8),
                division: $division,
                unit: $unit
            );
        }
    }

    /** One released custody with one line, due on $dueAt. */
    private function custody(
        Carbon $dueAt,
        string $status = 'ACTIVE',
        ?Carbon $closedAt = null,
        ?Carbon $releasedAt = new Carbon('2026-04-01 10:00:00'),
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?User $borrower = null,
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
            'purpose_event' => 'Late rate fixture',
            'location' => 'Campus',
            'division_code' => $division !== '' ? $division : null,
            'office_unit' => $unit ?? ($division !== '' ? $this->ccs() : ''),
            'schedule_date' => $dueAt->copy()->subDays(3)->toDateString(),
            'return_date' => $dueAt->toDateString(),
            'needed_from' => $dueAt->copy()->subDays(3)->startOfDay(),
            'return_due_at' => $dueAt->copy()->endOfDay(),
            'submitted_at' => $dueAt->copy()->subDays(5),
        ]);

        $custody = CustodyTransaction::query()->create([
            'custody_no' => 'CUS-'.fake()->unique()->numberBetween(100000, 9999999),
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
            'requested_quantity' => 1,
            'approved_quantity' => 1,
        ]);

        $allocation = Allocation::query()->create([
            'request_item_id' => $requestItem->id,
            'period_start' => $dueAt->copy()->subDays(3)->startOfDay(),
            'period_end' => $dueAt->copy()->endOfDay(),
            'allocated_quantity' => 1,
            'released_quantity' => $releasedAt ? 1 : 0,
            'restored_quantity' => 0,
            'status' => $releasedAt ? 'RELEASED' : 'ACTIVE',
            'allocated_at' => $dueAt->copy()->subDays(10),
        ]);

        CustodyLine::query()->create([
            'custody_transaction_id' => $custody->id,
            'request_item_id' => $requestItem->id,
            'allocation_id' => $allocation->id,
            'approved_quantity' => 1,
            'quantity_to_receive' => 1,
            'actual_released_quantity' => $releasedAt ? 1 : 0,
            'returned_quantity' => 0,
        ]);

        return $custody;
    }

    /**
     * A custody physically received back on $receivedAt via a Return
     * Inspection receipt. Closed by default; pass a status and null closedAt
     * to leave it administratively open.
     */
    private function completedReturn(
        Carbon $dueAt,
        Carbon $receivedAt,
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?User $borrower = null,
        string $status = 'CLOSED',
        ?Carbon $closedAt = new Carbon('2026-04-13 10:00:00'),
        ?Carbon $releasedAt = new Carbon('2026-04-01 10:00:00'),
        bool $laundry = false
    ): CustodyTransaction {
        $custody = $this->custody(
            dueAt: $dueAt, status: $status, closedAt: $closedAt, releasedAt: $releasedAt,
            division: $division, unit: $unit, borrower: $borrower, laundry: $laundry
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
