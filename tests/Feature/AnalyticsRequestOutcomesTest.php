<?php

namespace Tests\Feature;

use App\Enums\AccessClassification;
use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\OrganizationalUnit;
use App\Models\RequestVersion;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Support\RequestOutcomes;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Request Outcomes: requests filed in the period, by current workflow state.
 *
 * Three promises are guarded. The cohort is exactly the formally filed
 * requests - no draft, no cancelled draft, one observation per request. Every
 * request lands in exactly one group and the groups sum to the total. And the
 * existing demand metrics keep their own definitions: Requests Filed still
 * leaves cancelled and expired requests out, while this analysis keeps them.
 *
 * Selected period: April 2026.
 */
class AnalyticsRequestOutcomesTest extends TestCase
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
            'unit_code' => 'OUTC',
            'unit_name' => 'Outcome Fixture Unit',
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
    /* The mapping                                                         */
    /* ------------------------------------------------------------------ */

    public function test_every_request_status_has_exactly_one_outcome_group(): void
    {
        /*
         * The enum as it stands. A case added later must be listed here AND
         * mapped in RequestOutcomes::groupFor(), whose match has no default:
         * an unmapped case throws, so it cannot vanish from the count.
         */
        $this->assertSame(
            [
                'DRAFT', 'SIGNED', 'SUBMITTED', 'UNDER_SPMU', 'UNDER_GSU', 'UNDER_VPAF',
                'RETURNED_FOR_REVISION', 'REJECTED', 'FINAL_APPROVED_AWAITING_DOWNLOAD',
                'APPROVED_READY_FOR_RELEASE', 'CANCELLED', 'EXPIRED',
            ],
            array_map(static fn (RequestStatus $status): string => $status->value, RequestStatus::cases()),
            'A RequestStatus case was added or removed: map it in RequestOutcomes and update this list.'
        );

        $expected = [
            'DRAFT' => null,
            'SIGNED' => 'in_review',
            'SUBMITTED' => 'in_review',
            'UNDER_SPMU' => 'in_review',
            'UNDER_GSU' => 'in_review',
            'UNDER_VPAF' => 'in_review',
            'RETURNED_FOR_REVISION' => 'revision',
            'REJECTED' => 'rejected',
            'FINAL_APPROVED_AWAITING_DOWNLOAD' => 'approved',
            'APPROVED_READY_FOR_RELEASE' => 'approved',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
        ];

        foreach (RequestStatus::cases() as $status) {
            $group = RequestOutcomes::groupFor($status);

            $this->assertSame($expected[$status->value], $group, $status->value);
            $this->assertTrue($group === null || array_key_exists($group, RequestOutcomes::GROUPS));
        }

        /* Release, return and completion are custody facts, never outcome groups. */
        foreach (['released', 'returned', 'completed'] as $notAGroup) {
            $this->assertArrayNotHasKey($notAGroup, RequestOutcomes::GROUPS);
        }

        /* The groups partition the filed statuses: each filed status in exactly one. */
        $partition = [];
        foreach (array_keys(RequestOutcomes::GROUPS) as $group) {
            foreach (RequestOutcomes::statusesFor($group) as $status) {
                $partition[] = $status->value;
            }
        }
        sort($partition);
        $filed = array_map(static fn (RequestStatus $s): string => $s->value, RequestOutcomes::filedStatuses());
        sort($filed);
        $this->assertSame($filed, $partition);
        $this->assertSame(count($partition), count(array_unique($partition)));
        $this->assertNotContains('DRAFT', $partition);
    }

    /* ------------------------------------------------------------------ */
    /* Who is in the cohort                                                */
    /* ------------------------------------------------------------------ */

    public function test_a_draft_is_never_in_the_cohort(): void
    {
        /* Never filed: no submitted_at. */
        $this->request(RequestStatus::Draft, submittedAt: null);

        /* A draft is a draft even if its version somehow carries a stamp. */
        $this->request(RequestStatus::Draft, submittedAt: Carbon::create(2026, 4, 5, 10));

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);

        $this->assertSame(0, $outcomes['total']);
        $this->assertFalse($outcomes['available']);
    }

    public function test_filed_approved_rejected_and_returned_requests_land_in_their_groups(): void
    {
        $this->request(RequestStatus::ApprovedReadyForRelease);
        $this->request(RequestStatus::FinalApprovedAwaitingDownload);
        $this->request(RequestStatus::Rejected);
        $this->request(RequestStatus::ReturnedForRevision);

        $counts = $this->counts();

        $this->assertSame(2, $counts['approved']);
        $this->assertSame(1, $counts['rejected']);
        $this->assertSame(1, $counts['revision']);
        $this->assertSame(0, $counts['in_review']);
        $this->assertSame(4, $this->analytics->requestOutcomes($this->from, $this->to)['total']);
    }

    public function test_pending_and_legacy_review_statuses_are_in_review_only_when_filed(): void
    {
        $this->request(RequestStatus::UnderSpmu);
        $this->request(RequestStatus::Submitted);
        $this->request(RequestStatus::UnderGsu);
        $this->request(RequestStatus::UnderVpaf);

        /* SIGNED with a filing stamp is a filed request awaiting review... */
        $this->request(RequestStatus::Signed);

        /* ...but SIGNED with no stamp precedes submission and is not filed. */
        $this->request(RequestStatus::Signed, submittedAt: null, createdAt: Carbon::create(2026, 4, 6, 10));

        $counts = $this->counts();

        $this->assertSame(5, $counts['in_review']);
        $this->assertSame(5, $this->analytics->requestOutcomes($this->from, $this->to)['total']);
    }

    public function test_cancelled_and_expired_requests_count_only_when_they_were_filed(): void
    {
        /* Filed, then cancelled or expired: real outcomes of filed requests. */
        $this->request(RequestStatus::Cancelled, submittedAt: Carbon::create(2026, 4, 3, 10));
        $this->request(RequestStatus::Expired, submittedAt: Carbon::create(2026, 4, 4, 10));

        /*
         * A draft can be cancelled without ever being filed - cancel() only
         * refuses already-closed requests. No stamp, no proof, no cohort.
         */
        $this->request(RequestStatus::Cancelled, submittedAt: null, createdAt: Carbon::create(2026, 4, 5, 10));
        $this->request(RequestStatus::Expired, submittedAt: null, createdAt: Carbon::create(2026, 4, 5, 10));

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);
        $counts = $this->counts();

        $this->assertSame(2, $outcomes['total']);
        $this->assertSame(1, $counts['cancelled']);
        $this->assertSame(1, $counts['expired']);
        $this->assertSame(2, $outcomes['closed_after_filing']);
    }

    public function test_legacy_rows_without_a_stamp_count_only_where_the_status_itself_proves_filing(): void
    {
        /* Under review or decided: it must have been filed. */
        $this->request(RequestStatus::UnderSpmu, submittedAt: null, createdAt: Carbon::create(2026, 4, 2, 10));
        $this->request(RequestStatus::Rejected, submittedAt: null, createdAt: Carbon::create(2026, 4, 2, 10));
        $this->request(RequestStatus::ApprovedReadyForRelease, submittedAt: null, createdAt: Carbon::create(2026, 4, 2, 10));

        /* Created in the period but filed outside it: the stamp wins over created_at. */
        $this->request(RequestStatus::UnderSpmu, submittedAt: Carbon::create(2026, 5, 2, 10), createdAt: Carbon::create(2026, 4, 2, 10));

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);

        $this->assertSame(3, $outcomes['total']);
    }

    public function test_a_revised_request_is_one_observation_in_the_cohort_of_its_current_filing(): void
    {
        $request = $this->request(RequestStatus::ReturnedForRevision, submittedAt: Carbon::create(2026, 4, 3, 10));

        /* The corrected version, resubmitted later in the period. */
        RequestVersion::query()->create($this->versionData($request, 2, Carbon::create(2026, 4, 20, 10)));
        $request->update(['current_version_no' => 2, 'status' => RequestStatus::UnderSpmu]);

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);
        $counts = $this->counts();

        $this->assertSame(1, $outcomes['total']);
        $this->assertSame(1, $counts['in_review']);
        $this->assertSame(0, $counts['revision']);

        /* Its earlier version's filing date is not a second observation. */
        $this->assertSame(1, $this->analytics->requestOutcomeScope($this->from, $this->to)->count());
    }

    public function test_a_returned_request_under_correction_leaves_the_cohort_until_resubmitted(): void
    {
        /*
         * Editing a returned request creates a fresh unsubmitted version and
         * resets the request to DRAFT (BorrowingRequestController). Until it
         * is resubmitted it is not currently a filed request.
         */
        $request = $this->request(RequestStatus::ReturnedForRevision, submittedAt: Carbon::create(2026, 4, 3, 10));
        RequestVersion::query()->create($this->versionData($request, 2, null));
        $request->update(['current_version_no' => 2, 'status' => RequestStatus::Draft]);

        $this->assertSame(0, $this->analytics->requestOutcomes($this->from, $this->to)['total']);
    }

    public function test_custody_release_and_return_do_not_create_a_separate_outcome(): void
    {
        $request = $this->request(RequestStatus::ApprovedReadyForRelease, submittedAt: Carbon::create(2026, 4, 3, 10));

        /* Released and fully returned: the request status never moves past approval. */
        CustodyTransaction::query()->create([
            'custody_no' => 'CUS-OUTCOME-1',
            'request_id' => $request->id,
            'request_version_id' => $request->currentVersion->id,
            'borrower_user_id' => $request->borrower_user_id,
            'status' => 'CLOSED',
            'due_at' => Carbon::create(2026, 4, 8)->endOfDay(),
            'released_at' => Carbon::create(2026, 4, 5, 10),
            'closed_at' => Carbon::create(2026, 4, 7, 10),
        ]);

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);
        $counts = $this->counts();

        $this->assertSame(1, $outcomes['total']);
        $this->assertSame(1, $counts['approved']);
        $this->assertSame(RequestStatus::ApprovedReadyForRelease, $request->fresh()->status);
        $this->assertArrayNotHasKey('released', $counts);
        $this->assertArrayNotHasKey('completed', $counts);
    }

    /* ------------------------------------------------------------------ */
    /* Arithmetic                                                          */
    /* ------------------------------------------------------------------ */

    public function test_groups_are_exclusive_and_sum_to_the_filed_total_with_shares_of_it(): void
    {
        foreach ([RequestStatus::ApprovedReadyForRelease, RequestStatus::ApprovedReadyForRelease, RequestStatus::ApprovedReadyForRelease] as $status) {
            $this->request($status);
        }
        $this->request(RequestStatus::UnderSpmu);
        $this->request(RequestStatus::ReturnedForRevision);
        $this->request(RequestStatus::Rejected);
        $this->request(RequestStatus::Cancelled);
        $this->request(RequestStatus::Expired);

        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);

        $this->assertSame(8, $outcomes['total']);
        $this->assertSame(8, array_sum(array_column($outcomes['groups'], 'count')));
        $this->assertSame(8, $this->analytics->requestOutcomeScope($this->from, $this->to)->count());

        $shares = collect($outcomes['groups'])->pluck('share', 'key');

        /* Shares of the filed total, one decimal, as the rest of Analytics prints rates. */
        $this->assertSame(37.5, $shares['approved']);
        $this->assertSame(12.5, $shares['in_review']);
        $this->assertSame(12.5, $shares['revision']);
        $this->assertSame(12.5, $shares['rejected']);
        $this->assertSame(12.5, $shares['cancelled']);
        $this->assertSame(12.5, $shares['expired']);
        $this->assertEqualsWithDelta(100.0, $shares->sum(), 0.11);

        /* Display order is fixed and complete, zeroes included. */
        $this->assertSame(
            ['approved', 'in_review', 'revision', 'rejected', 'cancelled', 'expired'],
            array_column($outcomes['groups'], 'key')
        );

        $this->assertSame('Most filed requests are currently approved (3 of 8).', $outcomes['summary']);
    }

    public function test_an_empty_cohort_has_no_shares_and_no_fake_distribution(): void
    {
        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);

        $this->assertSame(0, $outcomes['total']);
        $this->assertFalse($outcomes['available']);
        $this->assertSame([null, null, null, null, null, null], array_column($outcomes['groups'], 'share'));
        $this->assertSame('No filed requests are available for outcome analysis in this period.', $outcomes['summary']);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
        ]));

        $page->assertOk();
        $page->assertSee('No filed requests are available for outcome analysis in this period.');

        /* No bar, no legend: an empty cohort is a sentence, not a row of zeroes. */
        $x = $this->xpath($page->getContent());
        $this->assertSame(0, $x->query('//*[contains(@class,"analytics-outcome-seg")]')->length);
        $this->assertSame(0, $x->query('//ol[contains(@class,"analytics-outcome-legend")]')->length);
        $page->assertDontSee('0% Approved', false);
    }

    public function test_the_insight_is_withheld_when_sparse_or_tied(): void
    {
        $this->request(RequestStatus::ApprovedReadyForRelease);
        $this->request(RequestStatus::Rejected);

        $this->assertNull($this->analytics->requestOutcomes($this->from, $this->to)['summary']);

        foreach ([RequestStatus::ApprovedReadyForRelease, RequestStatus::ApprovedReadyForRelease, RequestStatus::Rejected, RequestStatus::Rejected] as $status) {
            $this->request($status);
        }

        /* Six filed, three and three: tied, so no "most" is claimed. */
        $this->assertNull($this->analytics->requestOutcomes($this->from, $this->to)['summary']);
    }

    /* ------------------------------------------------------------------ */
    /* Scope                                                               */
    /* ------------------------------------------------------------------ */

    public function test_division_unit_and_borrower_filters_scope_the_cohort(): void
    {
        $alice = $this->borrower();
        $bob = $this->borrower();

        $this->request(RequestStatus::ApprovedReadyForRelease, borrower: $alice);
        $this->request(RequestStatus::Rejected, borrower: $bob);
        $this->request(RequestStatus::UnderSpmu, division: 'ADMINISTRATION', unit: $this->hrmo(), borrower: $bob);
        $this->request(RequestStatus::Cancelled, division: 'ADMINISTRATION', unit: $this->hrmo(), borrower: $alice);

        $this->assertSame(4, $this->analytics->requestOutcomes($this->from, $this->to)['total']);

        $academic = $this->analytics->requestOutcomes($this->from, $this->to, 'ACADEMIC');
        $this->assertSame(2, $academic['total']);
        $this->assertSame(50.0, collect($academic['groups'])->firstWhere('key', 'approved')['share']);

        $hrmo = $this->analytics->requestOutcomes($this->from, $this->to, 'ADMINISTRATION', $this->hrmo());
        $this->assertSame(2, $hrmo['total']);
        $this->assertSame(1, collect($hrmo['groups'])->firstWhere('key', 'cancelled')['count']);

        $aliceOnly = $this->analytics->requestOutcomes($this->from, $this->to, null, null, $alice->id);
        $this->assertSame(2, $aliceOnly['total']);
        $this->assertSame(
            ['approved' => 1, 'cancelled' => 1],
            collect($aliceOnly['groups'])->filter(fn (array $g): bool => $g['count'] > 0)->pluck('count', 'key')->all()
        );
    }

    public function test_the_period_follows_the_filing_date_not_the_record_date(): void
    {
        /* Created in March, filed in April: April's cohort. */
        $this->request(RequestStatus::UnderSpmu, submittedAt: Carbon::create(2026, 4, 2, 10), createdAt: Carbon::create(2026, 3, 25, 10));

        /* Created in April, filed in May: not April's cohort. */
        $this->request(RequestStatus::UnderSpmu, submittedAt: Carbon::create(2026, 5, 2, 10), createdAt: Carbon::create(2026, 4, 28, 10));

        /* Filed in March: March's cohort. */
        $this->request(RequestStatus::Rejected, submittedAt: Carbon::create(2026, 3, 30, 10));

        $this->assertSame(1, $this->analytics->requestOutcomes($this->from, $this->to)['total']);
    }

    public function test_the_existing_demand_metrics_keep_their_own_population(): void
    {
        $this->request(RequestStatus::UnderSpmu);
        $this->request(RequestStatus::Rejected);
        $this->request(RequestStatus::Cancelled);
        $this->request(RequestStatus::Expired);
        $this->request(RequestStatus::Draft, submittedAt: null);

        /* Requests Filed: rejected in, cancelled/expired/draft out - unchanged. */
        $this->assertSame(2, $this->analytics->demandTotals($this->from, $this->to, null, null)['requests']);
        $this->assertSame(2, $this->analytics->overview($this->from, $this->to, null, null)['total']);
        $this->assertSame(2, $this->analytics->demandComparison($this->from, $this->to, null, null)['requests']['current']);

        /* Request Outcomes: the same two plus the filed-then-closed pair. */
        $outcomes = $this->analytics->requestOutcomes($this->from, $this->to);
        $this->assertSame(4, $outcomes['total']);
        $this->assertSame(2, $outcomes['closed_after_filing']);
    }

    /* ------------------------------------------------------------------ */
    /* Rendering and detail                                                */
    /* ------------------------------------------------------------------ */

    public function test_the_card_draws_each_group_with_its_label_count_share_and_tooltip(): void
    {
        foreach ([RequestStatus::ApprovedReadyForRelease, RequestStatus::ApprovedReadyForRelease, RequestStatus::ApprovedReadyForRelease] as $status) {
            $this->request($status);
        }
        $this->request(RequestStatus::Rejected);

        $page = $this->actingAs($this->spmuHead())->get(route('analytics.index', [
            'section' => 'demand',
            'academic_period' => 'month',
        ]));

        $page->assertOk();
        $page->assertSee('Request Outcomes');
        $page->assertSee('Current workflow outcome of requests filed in the selected period.');

        $x = $this->xpath($page->getContent());

        /* Two drawn segments, widths equal to the shares, each a link with a tooltip. */
        $segments = $x->query('//a[contains(@class,"analytics-outcome-seg")]');
        $this->assertSame(2, $segments->length);

        $approved = $x->query('//a[contains(@class,"analytics-outcome-seg is-approved")]')->item(0);
        $this->assertSame('width: 75%', $approved->getAttribute('style'));
        $this->assertSame('', $approved->getAttribute('data-chart-tip'));
        $this->assertSame('Approved', $approved->getAttribute('data-tip-title'));
        $this->assertSame([['Requests', '3'], ['Share of filed requests', '75%']], json_decode($approved->getAttribute('data-tip-rows'), true));
        $this->assertStringContainsString('detail=outcomes', $approved->getAttribute('href'));
        $this->assertStringContainsString('outcome=approved', $approved->getAttribute('href'));
        $this->assertSame('Approved', trim($x->query('.//span[@class="visually-hidden"]', $approved)->item(0)->textContent));

        /* The legend lists all six groups; zero groups are listed but not linked. */
        $rows = $x->query('//ol[contains(@class,"analytics-outcome-legend")]/li');
        $this->assertSame(6, $rows->length);
        $this->assertSame(2, $x->query('//ol[contains(@class,"analytics-outcome-legend")]/li/a')->length);
        $this->assertStringContainsString('3 · 75%', preg_replace('/\s+/', ' ', $rows->item(0)->textContent));
        $this->assertStringContainsString('0 · 0%', preg_replace('/\s+/', ' ', $rows->item(1)->textContent));

        /* The whole card opens the outcomes detail. */
        $card = $x->query('//section[contains(@class,"analytics-outcomes")]')->item(0);
        $this->assertStringContainsString('detail=outcomes', $card->getAttribute('data-card-detail'));
    }

    public function test_the_detail_states_the_cohort_and_a_group_can_be_opened_on_its_own(): void
    {
        $this->request(RequestStatus::ApprovedReadyForRelease);
        $this->request(RequestStatus::Rejected, borrower: $this->borrower('Rejected Requester'));
        $this->request(RequestStatus::Cancelled);

        $head = $this->spmuHead();

        $whole = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'demand', 'academic_period' => 'month', 'detail' => 'outcomes',
        ]));

        $whole->assertOk();
        $whole->assertSee('data-analytics-detail-panel', false);
        $whole->assertSee('Request Outcomes');
        $whole->assertSee('Current workflow outcome of requests filed in the selected period');
        $whole->assertSee('Cancelled or expired after filing');
        $whole->assertSee('Rejected Requester');
        $whole->assertSee('report=borrowing', false);

        $rejected = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'demand', 'academic_period' => 'month', 'detail' => 'outcomes', 'outcome' => 'rejected',
        ]));

        $rejected->assertOk();
        $rejected->assertSee('Rejected requests');
        $rejected->assertSee('33.3% of filed requests');
        $rejected->assertSee('Rejected Requester');
        /* Reports can represent this group exactly, so the status is carried. */
        $rejected->assertSee('status=REJECTED', false);

        $cancelled = $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'demand', 'academic_period' => 'month', 'detail' => 'outcomes', 'outcome' => 'cancelled',
        ]));

        $cancelled->assertOk();
        $cancelled->assertSee('Cancelled requests');
        /* Reports cannot list cancelled requests, so no source link pretends otherwise. */
        $cancelled->assertDontSee('View source records');
        $cancelled->assertSee('does not include cancelled requests');

        /* An unknown group is not a detail. */
        $this->actingAs($head)->get(route('analytics.index', [
            'section' => 'demand', 'academic_period' => 'month', 'detail' => 'outcomes', 'outcome' => 'released',
        ]))->assertOk()->assertDontSee('data-analytics-detail-panel', false);
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect($this->analytics->requestOutcomes($this->from, $this->to)['groups'])
            ->pluck('count', 'key')
            ->all();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    private function spmuHead(): User
    {
        return User::factory()->create(['access_classification' => AccessClassification::SpmuHead]);
    }

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

    private function hrmo(): string
    {
        return 'Human Resource Management Office';
    }

    /**
     * A request in a given status. submitted_at defaults to a stamp inside
     * April - the filing event - and may be set to null for a never-filed
     * record or a legacy row.
     */
    private function request(
        RequestStatus $status,
        ?Carbon $submittedAt = new Carbon('2026-04-10 10:00:00'),
        ?Carbon $createdAt = null,
        string $division = 'ACADEMIC',
        ?string $unit = null,
        ?User $borrower = null
    ): BorrowingRequest {
        $borrower ??= $this->borrower();
        $createdAt ??= ($submittedAt ?? Carbon::create(2026, 4, 10, 10))->copy()->subHour();

        $request = BorrowingRequest::query()->create([
            'request_no' => 'BR-'.fake()->unique()->numberBetween(100000, 9999999),
            'borrower_user_id' => $borrower->id,
            'accountable_unit_id' => $this->unit->id,
            'current_version_no' => 1,
            'status' => $status,
        ]);

        $request->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        RequestVersion::query()->create(
            $this->versionData($request, 1, $submittedAt, $division, $unit ?? $this->ccs())
        );

        return $request->refresh();
    }

    /** @return array<string, mixed> */
    private function versionData(
        BorrowingRequest $request,
        int $versionNo,
        ?Carbon $submittedAt,
        string $division = 'ACADEMIC',
        ?string $unit = null
    ): array {
        $anchor = $submittedAt ?? Carbon::create(2026, 4, 10, 10);

        return [
            'request_id' => $request->id,
            'version_no' => $versionNo,
            'purpose_event' => 'Outcome fixture',
            'location' => 'Campus',
            'division_code' => $division,
            'office_unit' => $unit ?? $this->ccs(),
            'schedule_date' => $anchor->copy()->addDay()->toDateString(),
            'return_date' => $anchor->copy()->addDays(3)->toDateString(),
            'needed_from' => $anchor->copy()->addDay()->startOfDay(),
            'return_due_at' => $anchor->copy()->addDays(3)->endOfDay(),
            'submitted_at' => $submittedAt,
        ];
    }
}
