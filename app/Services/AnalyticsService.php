<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Analytics for the SPMU Head.
 *
 * Every figure here is derived from the same authoritative records the
 * operational modules use. Two rules run through the whole class:
 *
 *  1. Borrower affiliation comes from the request version snapshot
 *     (division_code / office_unit), which is captured when the request is
 *     filed. Nothing is inferred from names, emails or free text.
 *
 *  2. Once a custody transaction exists, custody is authoritative for
 *     operational state. Request status is used only before physical custody.
 *
 * Each method returns data ready for rendering, including the plain-language
 * sentence that goes underneath it, so the Blade template only lays out.
 */
class AnalyticsService
{
    /**
     * The division codes a request version can carry, in display order.
     *
     * OrganizationalStructure is the single source: the request form writes
     * these codes and Analytics reads them back, so neither side can drift.
     * Research, Innovation and Collaboration is reported as its own division;
     * no rule folds it into Academic or Administrative.
     */
    /** @return array<string, string> */
    public static function divisions(): array
    {
        return OrganizationalStructure::divisions();
    }

    /**
     * At or below this share of serviceable stock, an equipment type is
     * reported as low availability. See lowAvailability() for why the value
     * lives here rather than in the database or a template.
     */
    public const LOW_AVAILABILITY_RATIO = 0.25;

    /**
     * Request states that are not substantive borrowing activity.
     *
     * A draft was never filed, a cancelled request was withdrawn before it
     * proceeded, and an expired one lapsed without being acted on. None of
     * the three represents activity the unit actually carried out, so
     * counting them would overstate demand.
     *
     * REJECTED is deliberately kept. It was filed, reviewed and decided on:
     * it is real demand that SPMU handled, and hiding it would understate
     * both the demand signal and the review workload.
     *
     * @return list<RequestStatus>
     */
    public static function excludedFromActivity(): array
    {
        return [
            RequestStatus::Draft,
            RequestStatus::Cancelled,
            RequestStatus::Expired,
        ];
    }

    /**
     * Estimated days of stock coverage below which an item is flagged.
     *
     * Centralised so the thresholds cannot drift between the service and a
     * template. These are planning bands, not probabilities.
     */
    public const STOCKOUT_RISK_HIGH_DAYS = 30;

    public const STOCKOUT_RISK_MEDIUM_DAYS = 60;

    /**
     * Requests needed before a weekday or hour peak is reported.
     *
     * Below this, the busiest bucket is an artefact of a handful of
     * records rather than a pattern anyone should plan around.
     */
    public const PEAK_MINIMUM_OBSERVATIONS = 10;

    /**
     * The shortest observation window a usage rate may be drawn from.
     *
     * Dividing a few releases by a three-day window produces a daily rate
     * that looks precise and means nothing. Two weeks is the floor.
     */
    public const STOCK_COVERAGE_MINIMUM_WINDOW_DAYS = 14;

    /**
     * Separate release events an item needs before its usage rate is used.
     *
     * One isolated release is an event, not a rate. Projecting days of
     * coverage from it would dress a single data point up as a trend, so
     * the item reports insufficient history instead.
     */
    public const STOCK_COVERAGE_MINIMUM_RELEASES = 3;

    public function __construct(
        private readonly ReturnMetricsService $returnMetrics
    ) {}

    /**
     * Requests that reached the system's authoritative approved state.
     *
     * @return list<RequestStatus>
     */
    private function approvedStatuses(): array
    {
        return [
            RequestStatus::ApprovedReadyForRelease,
            RequestStatus::FinalApprovedAwaitingDownload,
        ];
    }

    /**
     * Requests actually filed in the period, narrowed by borrower filters.
     *
     * submitted_at is the filing event. created_at is used only as a legacy
     * fallback for older substantive records that predate explicit submission
     * timestamps. Drafts, withdrawals and expired requests are not activity.
     */
    public function requestScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null,
        ?int $borrower = null
    ): Builder {
        $query = BorrowingRequest::query()
            ->join('request_versions', function ($join): void {
                $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                    ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
            })
            ->where(function ($scope) use ($from, $to): void {
                $scope->whereBetween('request_versions.submitted_at', [$from, $to])
                    ->orWhere(function ($legacy) use ($from, $to): void {
                        $legacy->whereNull('request_versions.submitted_at')
                            ->whereBetween('borrowing_requests.created_at', [$from, $to]);
                    });
            })
            ->whereNotIn('borrowing_requests.status', array_map(
                static fn (RequestStatus $status): string => $status->value,
                self::excludedFromActivity()
            ));

        if ($division !== null && $division !== '' && $division !== 'all') {
            $query->where('request_versions.division_code', $division);
        }

        if ($unit !== null && $unit !== '' && $unit !== 'all') {
            $query->where('request_versions.office_unit', $unit);
        }

        if ($borrower !== null) {
            $query->where('borrowing_requests.borrower_user_id', $borrower);
        }

        return $query;
    }

    /**
     * Future borrowing dates already represented by filed requests.
     *
     * Scheduled demand is not a forecast. A request filed today for next
     * month belongs to next month's scheduled demand even though its filing
     * timestamp is today. That is why this scope follows the requested
     * schedule date rather than submitted_at.
     */
    private function scheduledRequestScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): Builder {
        $excluded = [
            RequestStatus::Draft,
            RequestStatus::Signed,
            RequestStatus::Rejected,
            RequestStatus::Cancelled,
            RequestStatus::Expired,
        ];

        $query = BorrowingRequest::query()
            ->join('request_versions', function ($join): void {
                $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                    ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
            })
            ->whereNotIn(
                'borrowing_requests.status',
                array_map(static fn (RequestStatus $status): string => $status->value, $excluded)
            )
            ->where(function ($schedule) use ($from, $to): void {
                $schedule->whereBetween('request_versions.schedule_date', [
                    Carbon::parse($from)->toDateString(),
                    Carbon::parse($to)->toDateString(),
                ])->orWhere(function ($legacy) use ($from, $to): void {
                    $legacy->whereNull('request_versions.schedule_date')
                        ->whereBetween('request_versions.needed_from', [$from, $to]);
                });
            });

        if ($division !== null && $division !== '' && $division !== 'all') {
            $query->where('request_versions.division_code', $division);
        }

        if ($unit !== null && $unit !== '' && $unit !== 'all') {
            $query->where('request_versions.office_unit', $unit);
        }

        if ($borrower !== null) {
            $query->where('borrowing_requests.borrower_user_id', $borrower);
        }

        return $query;
    }

    /**
     * Known demand already filed for a future schedule window.
     *
     * @return array{requests:int,divisions:array<string,mixed>,units:array<string,mixed>}
     */
    public function scheduledDemand(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null,
        ?int $borrower = null
    ): array {
        $base = $this->scheduledRequestScope($from, $to, $division, $unit, $borrower);
        $requests = (clone $base)->count('borrowing_requests.id');

        $divisionCounts = (clone $base)
            ->select('request_versions.division_code')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('request_versions.division_code')
            ->pluck('total', 'division_code');

        $groups = collect(self::divisions())
            ->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
                'count' => (int) ($divisionCounts[$code] ?? 0),
                'percentage' => $requests > 0
                    ? (int) round(((int) ($divisionCounts[$code] ?? 0)) / $requests * 100)
                    : 0,
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values();

        $unitRows = (clone $base)
            ->select('request_versions.division_code', 'request_versions.office_unit')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('request_versions.division_code', 'request_versions.office_unit')
            ->get();

        $columns = collect(self::divisions())
            ->map(function (string $label, string $code) use ($unitRows): array {
                $units = $unitRows
                    ->where('division_code', $code)
                    ->filter(fn ($row): bool => filled($row->office_unit))
                    ->sortByDesc('total')
                    ->values();
                $highest = (int) ($units->max('total') ?: 0);

                return [
                    'code' => $code,
                    'label' => $label,
                    'units' => $units->map(fn ($row): array => [
                        'name' => (string) $row->office_unit,
                        'count' => (int) $row->total,
                        'share' => $highest > 0
                            ? (int) round(((int) $row->total) / $highest * 100)
                            : 0,
                    ])->all(),
                    'leader' => $units->first()?->office_unit,
                    'leader_count' => (int) ($units->first()->total ?? 0),
                ];
            })
            ->filter(fn (array $column): bool => $column['units'] !== [])
            ->values();

        return [
            'requests' => $requests,
            'divisions' => [
                'total' => $requests,
                'groups' => $groups,
                'summary' => $requests === 0
                    ? 'No requests are scheduled for the next period yet.'
                    : $requests.' '.($requests === 1 ? 'request is' : 'requests are').' already scheduled for the next period.',
            ],
            'units' => [
                'columns' => $columns,
                'summary' => $columns->map(fn (array $column): string =>
                    $column['leader'].' has the most scheduled demand among '
                    .strtolower($column['label']).' units ('.$column['leader_count'].').'
                )->all(),
            ],
        ];
    }

    /**
     * Custody transactions belonging to requests filed inside the period.
     *
     * Use this for questions about what the period produced - how many of
     * this period's borrowings were returned late, for example.
     */
    private function custodyScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): Builder {
        return CustodyTransaction::query()->whereIn(
            'request_id',
            $this->requestScope($from, $to, $division, $unit)->select('borrowing_requests.id')
        );
    }

    /**
     * Physical releases that happened inside the reporting period.
     *
     * Release analytics follow released_at, not the date the borrower filed
     * the request. The request-version snapshot is used only for Division /
     * Office filtering.
     */
    private function releaseCustodyScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): Builder {
        $query = CustodyTransaction::query()
            ->whereNotNull('released_at')
            ->whereBetween('released_at', [$from, $to]);

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $query->whereIn('request_version_id', $versionIds);
        }

        if ($borrower !== null) {
            $query->where('borrower_user_id', $borrower);
        }

        return $query;
    }

    /**
     * Return records whose physical completion event may fall in the period.
     *
     * closed_at is included only for historical rows created before physical
     * return receipts were persisted. The final date/state is always decided
     * by ReturnMetricsService after the candidates are loaded.
     */
    private function returnCandidateScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): Builder {
        $query = CustodyTransaction::query()
            ->with([
                'borrower',
                'request',
                'lines.requestItem.inventoryItem',
                'returns',
                'laundryJob',
            ])
            ->whereNotNull('released_at')
            ->where(function ($events) use ($from, $to): void {
                $events->whereBetween('closed_at', [$from, $to])
                    ->orWhereHas(
                        'returns',
                        fn ($returns) => $returns->whereBetween('received_at', [$from, $to])
                    )
                    ->orWhereHas(
                        'laundryJob',
                        fn ($laundry) => $laundry->whereBetween('worker_received_at', [$from, $to])
                    );
            });

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $query->whereIn('request_version_id', $versionIds);
        }

        if ($borrower !== null) {
            $query->where('borrower_user_id', $borrower);
        }

        return $query;
    }

    /**
     * Completed returns whose authoritative physical-return date is inside the
     * selected period.
     *
     * @return Collection<int, array{custody:CustodyTransaction,returned_at:Carbon,state:string}>
     */
    private function completedReturnRecords(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): Collection {
        return $this->returnCandidateScope($from, $to, $division, $unit, $borrower)
            ->get()
            ->map(function (CustodyTransaction $custody): ?array {
                $returnedAt = $this->returnMetrics->completionDate($custody);

                if (! $returnedAt) {
                    return null;
                }

                return [
                    'custody' => $custody,
                    'returned_at' => $returnedAt,
                    'state' => $this->returnMetrics->state($custody),
                ];
            })
            ->filter(function (?array $row) use ($from, $to): bool {
                if ($row === null) {
                    return false;
                }

                return $row['returned_at']->betweenIncluded(
                    Carbon::parse($from)->startOfDay(),
                    Carbon::parse($to)->endOfDay()
                );
            })
            ->values();
    }

    /**
     * Unresolved accountability opened in the selected period, de-duplicated
     * to one case per custody transaction.
     */
    private function openAccountabilityCount(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): int {
        $incidentIds = Incident::query()
            ->whereBetween('reported_at', [$from, $to])
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->pluck('custody_transaction_id');

        $lateReturnIds = DB::table('overdue_cases')
            ->whereNotNull('actual_return_date')
            ->whereBetween('actual_return_date', [
                Carbon::parse($from)->toDateString(),
                Carbon::parse($to)->toDateString(),
            ])
            ->where('status', '!=', 'RESOLVED')
            ->pluck('custody_transaction_id');

        $billingIds = DB::table('billing_statements')
            ->join('billing_lines', 'billing_lines.billing_statement_id', '=', 'billing_statements.id')
            ->join('penalties', 'penalties.id', '=', 'billing_lines.penalty_id')
            ->whereBetween('billing_statements.issued_at', [$from, $to])
            ->whereNotIn('billing_statements.status', ['SETTLED', 'WAIVED', 'VOID'])
            ->pluck('penalties.custody_transaction_id');

        $ids = $incidentIds
            ->concat($lateReturnIds)
            ->concat($billingIds)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        $query = CustodyTransaction::query()->whereIn('id', $ids);

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $query->whereIn('request_version_id', $versionIds);
        }

        if ($borrower !== null) {
            $query->where('borrower_user_id', $borrower);
        }

        return $query->count();
    }

        /**
     * Custody transactions as they stand right now, whatever period the
     * originating request was filed in. Borrower affiliation comes from the
     * immutable request-version snapshot captured on the custody transaction.
     */
    private function currentCustodyScope(?string $division, ?string $unit, ?int $borrower = null): Builder
    {
        $query = CustodyTransaction::query();

        if ($borrower !== null) {
            $query->where('borrower_user_id', $borrower);
        }

        if (($division === null || $division === '' || $division === 'all')
            && ($unit === null || $unit === '' || $unit === 'all')) {
            return $query;
        }

        $versionIds = DB::table('request_versions')
            ->when(
                $division !== null && $division !== '' && $division !== 'all',
                fn ($versions) => $versions->where('division_code', $division)
            )
            ->when(
                $unit !== null && $unit !== '' && $unit !== 'all',
                fn ($versions) => $versions->where('office_unit', $unit)
            )
            ->select('id');

        return $query->whereIn('request_version_id', $versionIds);
    }

    /** Released, not yet closed - the assets physically out right now. */
    private function currentlyOutQuery(?string $division, ?string $unit, ?int $borrower = null): Builder
    {
        return $this->currentCustodyScope($division, $unit, $borrower)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED']);
    }

    /**
     * Out now and past the effective due date.
     *
     * This is OVERDUE - still not returned. It is deliberately not the same
     * measure as a late return, which is an item that did come back, only
     * after its due date. The two are never added together.
     */
    private function currentlyOverdueQuery(?string $division, ?string $unit, ?int $borrower = null): Builder
    {
        return $this->currentlyOutQuery($division, $unit, $borrower)
            ->where(function ($query): void {
                $query->where('status', 'OVERDUE')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('due_at')->where('due_at', '<', now()->startOfDay());
                    });
            });
    }

    /* ------------------------------------------------------------------ */
    /* Section A - Overview                                                */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function overview(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $total = (clone $this->requestScope($from, $to, $division, $unit, $borrower))
            ->count('borrowing_requests.id');

        $approved = (clone $this->requestScope($from, $to, $division, $unit, $borrower))
            ->whereIn('borrowing_requests.status', $this->approvedStatuses())
            ->count('borrowing_requests.id');

        /*
         * Both of the next two are present-tense: they describe what is out
         * and what is late right now, not what the selected period produced.
         */
        $onCustody = $this->currentlyOutQuery($division, $unit, $borrower)->count();
        $needsFollowUp = $this->currentlyOverdueQuery($division, $unit, $borrower)->count();

        return [
            'total' => $total,
            'approved' => $approved,
            'on_custody' => $onCustody,
            'needs_follow_up' => $needsFollowUp,
            'summary' => $this->overviewSentence($total, $approved, $onCustody, $needsFollowUp),
        ];
    }

    private function overviewSentence(int $total, int $approved, int $onCustody, int $followUp): string
    {
        if ($total === 0) {
            return 'No borrowing requests were filed during this period.';
        }

        $sentence = $total.' borrowing '.($total === 1 ? 'request was' : 'requests were')
            .' filed during this period, and '.$approved.' '
            .($approved === 1 ? 'was' : 'were').' approved.';

        if ($onCustody > 0) {
            $sentence .= ' '.$onCustody.' '.($onCustody === 1 ? 'borrowing is' : 'borrowings are')
                .' currently on custody';

            $sentence .= $followUp > 0
                ? ', of which '.$followUp.' '.($followUp === 1 ? 'needs' : 'need').' return follow-up.'
                : ', all within their return date.';
        }

        return $sentence;
    }

    /* ------------------------------------------------------------------ */
    /* Section B - Borrower distribution                                   */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function borrowerGroups(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $counts = $this->requestScope($from, $to, $division, $unit)
            ->select('request_versions.division_code')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('request_versions.division_code')
            ->pluck('total', 'division_code');

        $total = (int) $counts->sum();

        $groups = collect(self::divisions())
            ->map(fn (string $label, string $code): array => [
                'code' => $code,
                'label' => $label,
                'count' => (int) ($counts[$code] ?? 0),
                /* Guarded: a zero total must never produce a percentage. */
                'percentage' => $total > 0 ? round(((int) ($counts[$code] ?? 0)) / $total * 100) : 0,
            ])
            ->values();

        $unspecified = (int) ($counts[null] ?? 0) + (int) ($counts[''] ?? 0);

        if ($unspecified > 0) {
            $groups->push([
                'code' => null,
                'label' => 'Unspecified unit',
                'count' => $unspecified,
                'percentage' => $total > 0 ? round($unspecified / $total * 100) : 0,
            ]);
        }

        /* Groups with no activity add nothing to a comparison. */
        $visible = $groups->filter(fn (array $group): bool => $group['count'] > 0)->values();
        $leader = $visible->sortByDesc('count')->first();

        return [
            'total' => $total,
            'groups' => $visible,
            'summary' => $total === 0
                ? 'No borrowing requests were filed during this period, so there is nothing to compare yet.'
                : ($leader
                    ? 'Most borrowing activity came from '.$leader['label'].' units ('
                        .$leader['percentage'].'% of requests).'
                    : ''),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section C - Academic & administrative units                         */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function unitRankings(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        int $limit = 5
    ): array {
        $rows = $this->requestScope($from, $to, $division, $unit)
            ->select('request_versions.division_code', 'request_versions.office_unit')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('request_versions.division_code', 'request_versions.office_unit')
            ->get();

        $columns = collect(self::divisions())
            ->map(function (string $label, string $code) use ($rows, $limit): array {
                $units = $rows
                    ->where('division_code', $code)
                    ->filter(fn ($row): bool => filled($row->office_unit))
                    ->sortByDesc('total')
                    ->take($limit)
                    ->values();

                $highest = (int) ($units->max('total') ?: 0);

                return [
                    'code' => $code,
                    'label' => $label,
                    'units' => $units->map(fn ($row): array => [
                        'name' => $row->office_unit,
                        'count' => (int) $row->total,
                        /* Bar width is relative to the leader in its own column. */
                        'share' => $highest > 0 ? round((int) $row->total / $highest * 100) : 0,
                    ])->all(),
                    'leader' => $units->first()?->office_unit,
                    'leader_count' => (int) ($units->first()->total ?? 0),
                ];
            })
            ->filter(fn (array $column): bool => $column['units'] !== [])
            ->values();

        $sentences = $columns
            ->map(fn (array $column): string => $column['leader']
                .' recorded the highest borrowing activity among '
                .strtolower($column['label']).' units ('
                .$column['leader_count'].' '
                .($column['leader_count'] === 1 ? 'request' : 'requests').').')
            ->all();

        return [
            'columns' => $columns,
            'summary' => $sentences,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section D - Most borrowed assets                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Ranked by quantity physically released, not by how many requests
     * mentioned the item: one request for 100 chairs is 100 units of use,
     * while one request for a projector is one.
     *
     * @return array<string, mixed>
     */
    public function equipment(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        int $limit = 5,
        ?int $borrower = null
    ): array {
        $custodyIds = $this->releaseCustodyScope($from, $to, $division, $unit, $borrower)
            ->select('id');

        $rows = DB::table('custody_lines')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereIn('custody_lines.custody_transaction_id', $custodyIds)
            ->groupBy('request_items.description_snapshot', 'request_items.unit_snapshot')
            ->select('request_items.description_snapshot AS name', 'request_items.unit_snapshot AS unit')
            ->selectRaw('SUM(custody_lines.actual_released_quantity) AS released')
            /* A representative id so the row can link to its own records. */
            ->selectRaw('MIN(request_items.inventory_item_id) AS item_id')
            ->orderByDesc('released')
            ->limit($limit)
            ->get();

        $highest = (float) ($rows->max('released') ?: 0);

        return [
            'metric' => 'Units released',
            'items' => $rows->map(fn ($row): array => [
                'name' => $row->name,
                'unit' => $row->unit,
                'item_id' => $row->item_id,
                'released' => (float) $row->released + 0,
                'share' => $highest > 0 ? round((float) $row->released / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No items were physically released during this period, so utilisation cannot be ranked yet.'
                : $rows->first()->name.' had the highest utilisation with '
                    .((float) $rows->first()->released + 0).' units released.',
        ];
    }

    /**
     * What borrowers asked for, which is not the same as what went out.
     *
     * A request that was never approved or released still expresses demand, so
     * this ranking counts request items. Utilisation is a separate question
     * answered by equipment(), which counts physical releases only.
     *
     * @return array<string, mixed>
     */
    public function requestedEquipment(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        int $limit = 5,
        ?int $borrower = null
    ): array {
        $versionIds = $this->requestScope($from, $to, $division, $unit, $borrower)
            ->select('request_versions.id');

        $rows = DB::table('request_items')
            ->whereIn('request_items.request_version_id', $versionIds)
            ->groupBy('request_items.description_snapshot', 'request_items.unit_snapshot')
            ->select(
                'request_items.description_snapshot AS name',
                'request_items.unit_snapshot AS unit'
            )
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('SUM(request_items.requested_quantity) AS quantity')
            ->selectRaw('MIN(request_items.inventory_item_id) AS item_id')
            ->orderByDesc('requests')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();

        $highest = (int) ($rows->max('requests') ?: 0);

        return [
            'metric' => 'Requests containing the item',
            'items' => $rows->map(fn ($row): array => [
                'name' => $row->name,
                'unit' => $row->unit,
                'item_id' => $row->item_id,
                'requests' => (int) $row->requests,
                'quantity' => (float) $row->quantity + 0,
                'share' => $highest > 0 ? (int) round((int) $row->requests / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No equipment was requested during this period.'
                : $rows->first()->name.' was the most frequently requested equipment, appearing in '
                    .(int) $rows->first()->requests.' requests.',
        ];
    }

    /**
     * The four headline figures for Demand & Utilization.
     *
     * Demand and utilisation are kept apart here as they are everywhere else
     * in this class. Requests filed and requested quantity describe what was
     * asked for; released quantity describes what physically left the store.
     * They are never added together and never substituted for one another.
     *
     * Four scalar aggregates against the scope the rest of the tab uses, so
     * the KPI row costs four counted queries and never walks a result set.
     *
     * @return array{
     *     requests:int, requested_quantity:float, released_quantity:float, active_units:int
     * }
     */
    public function demandTotals(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $requests = (clone $this->requestScope($from, $to, $division, $unit, $borrower))
            ->count('borrowing_requests.id');

        /*
         * Distinct units that actually filed something. An office that exists
         * in the organisation but filed nothing this period is not active, so
         * the count comes from the request versions rather than the org table.
         */
        $activeUnits = (clone $this->requestScope($from, $to, $division, $unit, $borrower))
            ->whereNotNull('request_versions.office_unit')
            ->where('request_versions.office_unit', '!=', '')
            ->distinct()
            ->count('request_versions.office_unit');

        /* Expressed demand: what the request items asked for. */
        $requestedQuantity = (float) DB::table('request_items')
            ->whereIn(
                'request_items.request_version_id',
                $this->requestScope($from, $to, $division, $unit, $borrower)->select('request_versions.id')
            )
            ->sum('request_items.requested_quantity');

        /*
         * Actual utilisation: the quantity recorded at physical release. Not
         * the approved quantity, and not the requested quantity.
         */
        $releasedQuantity = (float) DB::table('custody_lines')
            ->whereIn(
                'custody_lines.custody_transaction_id',
                $this->releaseCustodyScope($from, $to, $division, $unit, $borrower)
                    ->select('id')
            )
            ->sum('custody_lines.actual_released_quantity');

        return [
            'requests' => $requests,
            'requested_quantity' => $requestedQuantity + 0,
            'released_quantity' => $releasedQuantity + 0,
            'active_units' => $activeUnits,
        ];
    }

    /**
     * The equipment a single organisational unit asks for most.
     *
     * Counted the same way as requestedEquipment(): one per request the item
     * appears in, with the total quantity shown alongside so the two readings
     * are never confused.
     *
     * @return array<string, mixed>
     */
    public function equipmentForUnit(
        CarbonInterface $from,
        CarbonInterface $to,
        string $unit,
        int $limit = 5
    ): array {
        $result = $this->requestedEquipment($from, $to, null, $unit, $limit);

        $result['unit'] = $unit;
        $result['summary'] = $result['items'] === []
            ? 'No borrowing requests were recorded for '.$unit.' during this period.'
            : $unit.' most often requested '.$result['items'][0]['name'].', in '
                .$result['items'][0]['requests'].' requests.';

        return $result;
    }

    /**
     * The same request count for the period immediately before this one.
     *
     * Returned as a plain difference. A percentage is deliberately not offered
     * when the previous period is zero, because "up 100%" from nothing is not
     * a reading anyone should act on.
     *
     * @return array<string, mixed>
     */
    public function previousPeriod(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $days = max(1, Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);

        $previousTo = Carbon::parse($from)->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        $current = $this->requestScope($from, $to, $division, $unit)->count('borrowing_requests.id');
        $previous = $this->requestScope($previousFrom, $previousTo, $division, $unit)
            ->count('borrowing_requests.id');

        $hasComparison = $previousTo->isPast();
        $change = $current - $previous;

        return [
            'available' => $hasComparison,
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'from' => $previousFrom,
            'to' => $previousTo,
            'summary' => match (true) {
                ! $hasComparison => 'No previous period data',
                $previous === 0 && $current === 0 => 'No requests in either period',
                $previous === 0 => 'First period with recorded requests',
                $change > 0 => '+'.$change.' from the previous period',
                $change < 0 => $change.' from the previous period',
                default => 'Unchanged from the previous period',
            },
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section E - Borrowing trends                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Grouping follows the selected period: a week reads by day, a month by
     * week, a semester or academic year by month.
     *
     * @return array<string, mixed>
     */
    public function trend(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        string $periodSelection,
        ?int $borrower = null
    ): array {
        $rows = $this->requestScope($from, $to, $division, $unit, $borrower)
            ->selectRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) AS filed_at')
            ->get();

        $buckets = $this->emptyBuckets($from, $to, $periodSelection);

        foreach ($rows as $row) {
            $key = $this->bucketKey(Carbon::parse($row->filed_at), $periodSelection);

            if (isset($buckets[$key])) {
                $buckets[$key]['count']++;
            }
        }

        $points = collect($buckets)->values();

        /*
         * Trailing empty buckets are dropped from the plot: a month that has
         * only started reads as one bar, not as one bar and four flat lines
         * that look like a reporting fault. Interior gaps are kept because a
         * quiet week between two busy ones is real information.
         */
        $lastIndex = $points->reverse()->values()->search(fn (array $point): bool => $point['count'] > 0);

        if ($lastIndex !== false) {
            $points = $points->take($points->count() - $lastIndex)->values();
        }

        $highest = (int) $points->max('count');
        $peak = $points->firstWhere('count', $highest);

        return [
            'granularity' => match ($periodSelection) {
                'week' => 'day',
                'month' => 'week',
                default => 'month',
            },
            'points' => $points->map(fn (array $point): array => [
                'label' => $point['label'],
                'count' => $point['count'],
                'share' => $highest > 0 ? round($point['count'] / $highest * 100) : 0,
            ])->all(),
            'summary' => $highest === 0
                ? 'No borrowing requests were filed during this period.'
                : 'Borrowing activity was highest during '.$peak['label']
                    .' with '.$highest.' '.($highest === 1 ? 'request' : 'requests').'.',
        ];
    }

    /** @return array<string, array{label:string, count:int}> */
    private function emptyBuckets(CarbonInterface $from, CarbonInterface $to, string $periodSelection): array
    {
        $buckets = [];
        $cursor = $from->copy();
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($to) && $guard < 400) {
            $key = $this->bucketKey($cursor, $periodSelection);

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['label' => $this->bucketLabel($cursor, $periodSelection), 'count' => 0];
            }

            $cursor = match ($periodSelection) {
                'week' => $cursor->addDay(),
                'month' => $cursor->addWeek(),
                default => $cursor->addMonth(),
            };

            $guard++;
        }

        return $buckets;
    }

    private function bucketKey(CarbonInterface $moment, string $periodSelection): string
    {
        return match ($periodSelection) {
            'week' => $moment->format('Y-m-d'),
            'month' => $moment->format('Y-m').'-w'.(int) ceil($moment->day / 7),
            default => $moment->format('Y-m'),
        };
    }

    private function bucketLabel(CarbonInterface $moment, string $periodSelection): string
    {
        return match ($periodSelection) {
            'week' => $moment->format('D, d M'),
            'month' => 'Week '.(int) ceil($moment->day / 7).' of '.$moment->format('F'),
            default => $moment->format('F Y'),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Section F - Returns & accountability                                */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function returns(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $completedRows = $this->completedReturnRecords($from, $to, $division, $unit, $borrower);

        $onTime = $completedRows
            ->where('state', ReturnMetricsService::RETURNED_ON_TIME)
            ->count();

        $late = $completedRows
            ->where('state', ReturnMetricsService::RETURNED_LATE)
            ->count();

        /* Still out and past due is a present-tense measure. */
        $overdue = $this->currentlyOverdueQuery($division, $unit, $borrower)->count();

        /*
         * One custody equals one accountability case in Analytics. Incident,
         * late-return and billing records are de-duplicated by custody id so a
         * single obligation never appears as two or three separate cases.
         */
        $openCases = $this->openAccountabilityCount($from, $to, $division, $unit, $borrower);

        $completed = $completedRows->count();

        return [
            'on_time' => $onTime,
            'late' => $late,
            'overdue' => $overdue,
            'open_cases' => $openCases,
            'completed' => $completed,

            'on_time_rate' => $completed > 0 ? round($onTime / $completed * 100, 1) : null,
            'late_rate' => $completed > 0 ? round($late / $completed * 100, 1) : null,

            'average_duration' => $this->averageCustodyDuration($from, $to, $division, $unit, $borrower),
            'has_data' => $completed > 0 || $overdue > 0 || $openCases > 0,
            'summary' => $this->returnsSentence($completed, $onTime, $late, $overdue, $openCases),
        ];
    }

    private function returnsSentence(int $closed, int $onTime, int $late, int $overdue, int $cases): string
    {
        if ($closed === 0 && $overdue === 0 && $cases === 0) {
            return 'No completed returns are available for this period yet.';
        }

        $parts = [];

        if ($closed > 0) {
            $parts[] = $onTime === $closed
                ? 'All '.$closed.' completed '.($closed === 1 ? 'return was' : 'returns were').' on time.'
                : $onTime.' of '.$closed.' completed returns were on time and '.$late.' '
                    .($late === 1 ? 'was' : 'were').' late.';
        }

        if ($overdue > 0) {
            $parts[] = $overdue.' active '.($overdue === 1 ? 'borrowing requires' : 'borrowings require')
                .' return follow-up.';
        }

        if ($cases > 0) {
            $parts[] = $cases.' accountability '.($cases === 1 ? 'case remains' : 'cases remain').' open.';
        }

        return implode(' ', $parts);
    }

    /* ------------------------------------------------------------------ */
    /* Section G - Inventory status                                        */
    /* ------------------------------------------------------------------ */

    /**
     * A summary only. Inventory Overview stays the operational module.
     *
     * @return array<string, mixed>
     */
    public function inventory(InventoryService $inventoryService): array
    {
        $items = InventoryItem::query()->where('active', true)->get();

        /*
         * One batched call rather than one availability() call per item: the
         * arithmetic is identical, but the query count no longer grows with
         * the size of the catalogue.
         */
        $balances = $inventoryService->portfolio(
            $items,
            now()->subYears(10)->startOfDay(),
            now()->addYears(10)->endOfDay()
        );

        $totals = [
            'serviceable' => 0.0,
            'available' => 0.0,
            'allocated' => 0.0,
            'on_custody' => 0.0,
            'maintenance' => 0.0,
            /*
             * `problem` is the incident-held quantity. Lost/stolen/destroyed
             * are condition subtypes of those same incident lines and must not
             * be added again or one physical unit is counted twice.
             */
            'problem' => 0.0,
            'incident' => 0.0,
            'attention' => 0.0,
            'laundry' => 0.0,
        ];

        /*
         * Per-item availability, read off the balances already fetched above.
         * No further query: the ranking is a second pass over the same rows the
         * totals are summed from.
         */
        $availability = [];

        foreach ($items as $item) {
            $balance = $balances[$item->id] ?? [];

            $totals['serviceable'] += (float) ($balance['serviceable_total'] ?? 0);
            $totals['available'] += (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? 0);

            /*
             * allocated and reserved are the same units read over two windows:
             * allocated is the allocation total inside the window passed in,
             * reserved is the all-time total. This method asks for a window of
             * plus and minus ten years, so the two are the same number and
             * adding them counted every reserved unit twice. The rest of the
             * codebase reads `allocated ?? reserved`, one or the other, and
             * this now follows it.
             */
            $totals['allocated'] += (float) ($balance['reserved'] ?? 0);

            $totals['on_custody'] += (float) ($balance['borrowed'] ?? 0);
            $maintenance = (float) ($balance['damaged_maintenance'] ?? 0);
            $incident = (float) ($balance['incident'] ?? 0);
            $terminalIncidentStates = (float) ($balance['lost'] ?? 0)
                + (float) ($balance['stolen'] ?? 0)
                + (float) ($balance['destroyed'] ?? 0);

            $totals['maintenance'] += $maintenance;
            $totals['laundry'] += (float) ($balance['laundry'] ?? 0);
            $totals['incident'] += $incident;
            $totals['problem'] += $incident;

            /*
             * Maintenance and lost/stolen/destroyed are breakdowns of the
             * incident quantity for a normal SERVICEABLE item. `max()` also
             * covers the legacy whole-item maintenance condition without
             * stacking the same quantity on top of an incident again.
             */
            $totals['attention'] += max($incident, $maintenance, $terminalIncidentStates);

            $stock = (float) ($balance['serviceable_total'] ?? 0);

            /* Nothing serviceable to be a share of. */
            if (! $item->borrowable || $stock <= 0) {
                continue;
            }

            $usable = (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? 0);
            $share = $usable / $stock;

            $availability[] = [
                'item_id' => $item->id,
                'name' => $item->unique_description,
                'available' => $usable + 0,
                'stock' => $stock + 0,
                'on_custody' => (float) ($balance['borrowed'] ?? 0) + 0,
                'laundry' => (float) ($balance['laundry'] ?? 0) + 0,
                'incident' => (float) ($balance['incident'] ?? 0) + 0,
                'share' => (int) round($share * 100),
                /* The existing threshold, not a new one. */
                'low' => $share <= self::LOW_AVAILABILITY_RATIO,
                'status' => $usable <= 0 ? 'Unavailable' : ($share <= self::LOW_AVAILABILITY_RATIO ? 'Limited' : 'Healthy'),
            ];
        }

        /* Health first: the tightest stock is the row worth reading. */
        usort($availability, fn (array $a, array $b): int => $a['share'] <=> $b['share']);

        $totals = array_map(fn (float $value): float => $value + 0, $totals);

        return [
            'item_count' => $items->count(),
            'totals' => $totals,
            'availability' => $availability,
            'threshold' => self::LOW_AVAILABILITY_RATIO,
            'summary' => $items->isEmpty()
                ? 'No active inventory items are recorded yet.'
                : ($totals['attention'] > 0
                    ? ($totals['attention'] + 0).' units currently need condition or incident follow-up.'
                    : 'No inventory units currently need condition or incident follow-up.'),
        ];
    }

    /**
     * Equipment types whose usable stock has run low.
     *
     * THRESHOLD
     * ---------
     * The schema carries no reorder level or minimum-stock column, so there is
     * no existing rule to reuse. This is the project's rule, defined once here
     * rather than in a Blade template: an active, borrowable item is Limited
     * when a quarter or less of its serviceable stock is usable right now, and
     * Unavailable when none of it is. Anything that is currently out, reserved,
     * held by an incident, or waiting on Laundry Operations is already excluded
     * by the Inventory module's own availability rule.
     *
     * @return array<string, mixed>
     */
    public function lowAvailability(InventoryService $inventoryService, int $limit = 5): array
    {
        $items = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->get();

        $balances = $inventoryService->portfolio(
            $items,
            now()->startOfDay(),
            now()->endOfDay()
        );

        $rows = [];

        foreach ($items as $item) {
            $balance = $balances[$item->id] ?? [];
            $stock = (float) ($balance['serviceable_total'] ?? 0);

            if ($stock <= 0) {
                continue;
            }

            $usable = (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? 0);
            $share = $usable / $stock;

            if ($share > self::LOW_AVAILABILITY_RATIO) {
                continue;
            }

            $rows[] = [
                'item_id' => $item->id,
                'name' => $item->unique_description,
                'available' => $usable + 0,
                'stock' => $stock + 0,
                'laundry' => (float) ($balance['laundry'] ?? 0) + 0,
                'laundry_required' => (bool) $item->laundry_required,
                'status' => $usable <= 0 ? 'Unavailable' : 'Limited',
                'share' => (int) round($share * 100),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['share'] <=> $b['share']);

        $watch = $rows === [] ? null : $rows[0];

        return [
            'count' => count($rows),
            'items' => array_slice($rows, 0, $limit),
            'watch' => $watch,
            'threshold' => self::LOW_AVAILABILITY_RATIO,
            'summary' => $watch === null
                ? 'No equipment currently requires availability attention.'
                : $watch['name'].' has '.($watch['available'] + 0).' of '.($watch['stock'] + 0)
                    .' units available. Review upcoming bookings before approving additional requests.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Key insights                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Three to five sentences, each built from a figure already shown above.
     *
     * @return list<string>
     */
    public function insights(
        array $overview,
        array $groups,
        array $units,
        array $equipment,
        array $trend,
        array $returns
    ): array {
        $insights = [];

        if ($overview['total'] > 0 && $groups['groups']->isNotEmpty()) {
            $leader = $groups['groups']->sortByDesc('count')->first();
            $insights[] = $leader['label'].' units generated '.$leader['percentage']
                .'% of borrowing requests during this period.';
        }

        foreach (array_slice($units['summary'], 0, 2) as $sentence) {
            $insights[] = $sentence;
        }

        if ($equipment['items'] !== []) {
            $top = $equipment['items'][0];
            $insights[] = $top['name'].' had the highest utilisation with '
                .$top['released'].' units released.';
        }

        if ($trend['points'] !== [] && $overview['total'] > 0) {
            $insights[] = $trend['summary'];
        }

        if ($returns['overdue'] > 0) {
            $insights[] = $returns['overdue'].' active '
                .($returns['overdue'] === 1 ? 'borrowing requires' : 'borrowings require')
                .' return follow-up.';
        } elseif ($returns['open_cases'] > 0) {
            $insights[] = $returns['open_cases'].' accountability '
                .($returns['open_cases'] === 1 ? 'case remains' : 'cases remain').' open.';
        }

        /*
         * Each section already prints its own reading, so an insight that
         * repeats one word for word adds nothing. Exact repeats are dropped
         * rather than padding the list to five.
         */
        $alreadySaid = array_merge(
            array_filter([
                $overview['summary'] ?? null,
                $groups['summary'] ?? null,
                $equipment['summary'] ?? null,
                $trend['summary'] ?? null,
                $returns['summary'] ?? null,
            ]),
            $units['summary'] ?? []
        );

        $insights = array_values(array_filter(
            array_unique($insights),
            fn (string $insight): bool => ! in_array($insight, $alreadySaid, true)
        ));

        return array_slice($insights, 0, 5);
    }

    /* ------------------------------------------------------------------ */
    /* Section H - Movement                                                */
    /* ------------------------------------------------------------------ */

        /**
     * Equipment that moved least, including equipment that never moved.
     *
     * Release activity follows released_at and respects the selected borrower
     * affiliation snapshot. Catalogue rows with no matching release remain in
     * the result at zero, which is the point of this view.
     *
     * @return array<string, mixed>
     */
    public function slowMovingItems(
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 10,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $releasedQuery = DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$from, $to]);

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $releasedQuery->whereIn('custody_transactions.request_version_id', $versionIds);
        }

        $released = $releasedQuery
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw('SUM(custody_lines.actual_released_quantity) AS quantity')
            ->selectRaw('COUNT(DISTINCT custody_transactions.id) AS transactions')
            ->selectRaw('MAX(custody_transactions.released_at) AS last_activity')
            ->get()
            ->keyBy('item_id');

        $items = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->orderBy('unique_description')
            ->get();

        $rows = $items->map(function (InventoryItem $item) use ($released): array {
            $activity = $released->get($item->id);

            return [
                'item_id' => $item->id,
                'name' => $item->unique_description,
                'released' => (float) ($activity->quantity ?? 0) + 0,
                'transactions' => (int) ($activity->transactions ?? 0),
                'last_activity' => $activity->last_activity ?? null,
            ];
        });

        $sorted = $rows
            ->sortBy([
                fn (array $a, array $b): int => $a['released'] <=> $b['released'],
                fn (array $a, array $b): int => $a['transactions'] <=> $b['transactions'],
            ])
            ->take($limit)
            ->values();

        $neverMoved = $rows->filter(fn (array $row): bool => $row['released'] <= 0)->count();

        return [
            'items' => $sorted->all(),
            'never_moved' => $neverMoved,
            'catalogue' => $items->count(),
            'summary' => $items->isEmpty()
                ? 'No borrowable equipment is recorded yet.'
                : ($neverMoved > 0
                    ? $neverMoved.' of '.$items->count().' borrowable '
                        .($neverMoved === 1 ? 'item was' : 'items were')
                        .' not released at all during this period.'
                    : 'Every borrowable item was released at least once during this period.'),
        ];
    }

    /**
     * When borrowing requests are actually filed.
     *
     * Both readings come from the same timestamps the rest of the module
     * uses. A peak is only reported once there are enough requests for one
     * bucket to mean anything; below that the honest answer is that the
     * pattern is not yet visible.
     *
     * @return array<string, mixed>
     */
    public function peakBorrowing(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $timestamps = $this->requestScope($from, $to, $division, $unit)
            ->selectRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) AS filed_at')
            ->get()
            ->pluck('filed_at')
            ->map(fn ($moment) => Carbon::parse($moment));

        $total = $timestamps->count();

        /* One or two requests cannot establish a weekday or hour pattern. */
        $reliable = $total >= self::PEAK_MINIMUM_OBSERVATIONS;

        $days = array_fill(0, 7, 0);
        $hours = array_fill(0, 24, 0);

        foreach ($timestamps as $moment) {
            $days[(int) $moment->dayOfWeek]++;
            $hours[(int) $moment->hour]++;
        }

        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $peakDay = array_search(max($days), $days, true);
        $peakHour = array_search(max($hours), $hours, true);

        return [
            'available' => $reliable,
            'total' => $total,
            'requirement' => self::PEAK_MINIMUM_OBSERVATIONS,
            'days' => collect($dayNames)
                ->map(fn (string $name, int $index): array => [
                    'label' => $name,
                    'count' => $days[$index],
                    'share' => max($days) > 0 ? (int) round($days[$index] / max($days) * 100) : 0,
                ])
                ->all(),
            'peak_day' => $reliable && max($days) > 0 ? $dayNames[$peakDay] : null,
            'peak_hour' => $reliable && max($hours) > 0
                ? Carbon::createFromTime((int) $peakHour)->format('g A')
                : null,
            'summary' => $reliable
                ? 'Most borrowing requests were filed on '.$dayNames[$peakDay]
                    .', around '.Carbon::createFromTime((int) $peakHour)->format('g A').'.'
                : 'Not enough activity to determine a reliable peak.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section I - Borrower behaviour                                      */
    /* ------------------------------------------------------------------ */

    /**
     * The borrowers who filed the most requests in the period.
     *
     * Grouped on the borrower account, with the unit taken from the request
     * version snapshot so the affiliation matches the rest of the module.
     *
     * @return array<string, mixed>
     */
    public function frequentBorrowers(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        int $limit = 10
    ): array {
        $rows = $this->requestScope($from, $to, $division, $unit)
            ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
            ->groupBy('users.id', 'users.full_name', 'request_versions.office_unit')
            ->select('users.id AS borrower_id', 'users.full_name AS name', 'request_versions.office_unit AS unit')
            ->selectRaw('COUNT(borrowing_requests.id) AS requests')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get();

        $highest = (int) ($rows->max('requests') ?: 0);

        return [
            'borrowers' => $rows->map(fn ($row): array => [
                'name' => (string) $row->name,
                'unit' => (string) ($row->unit ?? ''),
                'requests' => (int) $row->requests,
                'share' => $highest > 0 ? (int) round((int) $row->requests / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No borrowing requests were filed during this period.'
                : $rows->first()->name.' filed the most borrowing requests ('
                    .(int) $rows->first()->requests.').',
        ];
    }

    /**
     * Borrowers whose completed returns came back after the due date.
     *
     * A LATE RETURN is a finished borrowing: the item is back, only later
     * than it was due. Nothing here counts an item that is still out - that
     * is overdue, and it is reported separately.
     *
     * @return array<string, mixed>
     */
    public function lateReturnBorrowers(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        int $limit = 10
    ): array {
        $rows = $this->completedReturnRecords($from, $to, $division, $unit)
            ->where('state', ReturnMetricsService::RETURNED_LATE)
            ->groupBy(fn (array $row): int => (int) $row['custody']->borrower_user_id)
            ->map(function (Collection $group): array {
                /** @var CustodyTransaction $custody */
                $custody = $group->first()['custody'];

                return [
                    'name' => (string) ($custody->borrower?->full_name ?? 'Unknown borrower'),
                    'late_returns' => $group->count(),
                ];
            })
            ->sortByDesc('late_returns')
            ->take($limit)
            ->values();

        $highest = (int) ($rows->max('late_returns') ?: 0);

        return [
            'borrowers' => $rows->map(fn (array $row): array => [
                'name' => $row['name'],
                'late_returns' => $row['late_returns'],
                'share' => $highest > 0 ? (int) round($row['late_returns'] / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No borrowing was returned after its due date during this period.'
                : $rows->first()['name'].' recorded the most late returns ('
                    .$rows->first()['late_returns'].').',
        ];
    }

    /**
     * How long a completed borrowing typically lasts.
     *
     * Measured from physical release to closure over custody that has both,
     * so an unreturned borrowing cannot pull the average down.
     *
     * @return array<string, mixed>
     */
    public function averageCustodyDuration(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $records = $this->completedReturnRecords($from, $to, $division, $unit, $borrower);

        if ($records->isEmpty()) {
            return ['available' => false, 'summary' => 'No completed borrowings to measure yet.'];
        }

        $hours = $records
            ->map(function (array $row): ?float {
                /** @var CustodyTransaction $custody */
                $custody = $row['custody'];
                $returnedAt = $this->returnMetrics->completionMoment($custody);

                if (! $custody->released_at || ! $returnedAt) {
                    return null;
                }

                return (float) $custody->released_at->diffInMinutes($returnedAt) / 60;
            })
            ->filter(fn (?float $value): bool => $value !== null && $value >= 0)
            ->values();

        if ($hours->isEmpty()) {
            return ['available' => false, 'summary' => 'No completed borrowings to measure yet.'];
        }

        $averageHours = (float) $hours->avg();
        $days = $averageHours / 24;

        return [
            'available' => true,
            'count' => $hours->count(),
            'hours' => round($averageHours, 1),
            'days' => round($days, 1),
            'label' => $days >= 1
                ? round($days, 1).' '.(round($days, 1) === 1.0 ? 'day' : 'days')
                : round($averageHours).' '.(round($averageHours) === 1.0 ? 'hour' : 'hours'),
            'summary' => 'A completed borrowing lasted about '
                .($days >= 1 ? round($days, 1).' days' : round($averageHours).' hours')
                .' on average across '.$hours->count().' '
                .($hours->count() === 1 ? 'record' : 'records').'.',
        ];
    }

        /**
     * Incidents opened during the selected period and their affected quantity.
     *
     * Incident analytics follow reported_at. The release denominator follows
     * physical released_at over the same period and filter scope.
     *
     * @return array<string, mixed>
     */
    public function incidentSummary(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $incidentQuery = Incident::query()
            ->whereBetween('reported_at', [$from, $to])
            ->with('lines');

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')
            || $borrower !== null) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $custodyIds = CustodyTransaction::query()
                ->whereIn('request_version_id', $versionIds)
                ->when($borrower !== null, fn ($q) => $q->where('borrower_user_id', $borrower))
                ->select('id');

            $incidentQuery->whereIn('custody_transaction_id', $custodyIds);
        }

        $incidents = $incidentQuery->get();

        $released = (float) DB::table('custody_lines')
            ->whereIn(
                'custody_transaction_id',
                $this->releaseCustodyScope($from, $to, $division, $unit, $borrower)->select('id')
            )
            ->sum('actual_released_quantity');

        $byType = $incidents
            ->groupBy('incident_type')
            ->map(fn (Collection $group): array => [
                'count' => $group->count(),
                'quantity' => (float) $group->sum(
                    fn ($incident): float => (float) $incident->lines->sum('quantity')
                ) + 0,
            ]);

        $affected = (float) $byType->sum('quantity');

        return [
            'total' => $incidents->count(),
            'open' => $incidents->filter(
                fn ($incident): bool => ! in_array($incident->status, ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'], true)
            )->count(),
            'types' => $byType->map(fn (array $row, string $type): array => [
                'type' => str($type)->replace('_', ' ')->title()->toString(),
                'count' => $row['count'],
                'quantity' => $row['quantity'],
            ])->values()->all(),
            'affected_quantity' => $affected + 0,
            'released_quantity' => $released + 0,
            'rate' => $released > 0 ? round($affected / $released * 100, 2) : null,
            'summary' => $incidents->isEmpty()
                ? 'No property incidents were recorded for this period.'
                : $incidents->count().' '.($incidents->count() === 1 ? 'incident was' : 'incidents were')
                    .' recorded, affecting '.($affected + 0).' units.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section J - Stock coverage                                          */
    /* ------------------------------------------------------------------ */

    /**
     * An approximate reading of how long current stock would last.
     *
     *     average daily usage    = quantity released in the period / days in the period
     *     estimated days cover   = usable availability now / average daily usage
     *
     * MINIMUM HISTORY
     * ---------------
     * The arithmetic will produce a number from any input, which is exactly
     * why it is guarded. A rate drawn from a window shorter than
     * STOCK_COVERAGE_MINIMUM_WINDOW_DAYS, or from fewer than
     * STOCK_COVERAGE_MINIMUM_RELEASES separate releases, describes a couple
     * of events rather than a pattern. In that case the item reports
     * insufficient history and no daily rate, coverage or risk band is
     * produced at all - a wrong-looking "High Risk" is worse than an honest
     * blank. The rule lives here so no template can reimplement it.
     *
     * This remains an estimate wherever it does appear. It assumes usage
     * continues at the observed rate, which no borrowing pattern guarantees,
     * and it carries no probability because the data does not support one.
     *
     * Usable availability is InventoryService's borrower_available: stock
     * that is physically usable and not already reserved for another approved
     * request. This keeps planning figures consistent with allocation rules.
     *
     * @return array<string, mixed>
     */
    /* ------------------------------------------------------------------ */
    /* Section F2 - Return performance detail                              */
    /* ------------------------------------------------------------------ */

        /**
     * Completed physical returns per bucket, split into on-time and late.
     *
     * This uses the exact same completed-return population and classification
     * as returns(), so the trend always reconciles with the KPI totals.
     *
     * @return array<string, mixed>
     */
    public function returnTrend(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        string $periodSelection,
        ?int $borrower = null
    ): array {
        $completed = $this->completedReturnRecords($from, $to, $division, $unit, $borrower);
        $buckets = [];

        foreach ($this->emptyBuckets($from, $to, $periodSelection) as $key => $bucket) {
            $buckets[$key] = $bucket + ['on_time' => 0, 'late' => 0];
        }

        foreach ($completed as $row) {
            $key = $this->bucketKey($row['returned_at'], $periodSelection);

            if (! isset($buckets[$key])) {
                continue;
            }

            $bucket = $row['state'] === ReturnMetricsService::RETURNED_LATE ? 'late' : 'on_time';
            $buckets[$key][$bucket]++;
        }

        $points = collect($buckets)->values();
        $highest = (int) max(1, $points->max(fn (array $p): int => $p['on_time'] + $p['late']));

        return [
            'granularity' => match ($periodSelection) {
                'week' => 'day',
                'month' => 'week',
                default => 'month',
            },
            'points' => $points->map(fn (array $p): array => [
                'label' => $p['label'],
                'on_time' => $p['on_time'],
                'late' => $p['late'],
                'on_time_share' => (int) round($p['on_time'] / $highest * 100),
                'late_share' => (int) round($p['late'] / $highest * 100),
            ])->all(),
            'plotted' => (int) $points->sum(fn (array $p): int => $p['on_time'] + $p['late']),
            'total' => $completed->count(),
        ];
    }

        /**
     * Mutually exclusive current stage of borrowings filed in the period.
     *
     * Custody state takes precedence once custody exists. A returned custody
     * cannot simultaneously remain in Awaiting Release, which keeps this strip
     * a true current-stage snapshot instead of a cumulative funnel.
     *
     * @return list<array<string, mixed>>
     */
    public function lifecycle(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $requests = $this->requestScope($from, $to, $division, $unit, $borrower)
            ->select('borrowing_requests.id', 'borrowing_requests.status')
            ->get();

        $custodies = CustodyTransaction::query()
            ->whereIn('request_id', $requests->pluck('id'))
            ->with(['lines.requestItem.inventoryItem', 'returns', 'laundryJob'])
            ->get()
            ->keyBy('request_id');

        $approvedValues = array_map(
            static fn (RequestStatus $status): string => $status->value,
            $this->approvedStatuses()
        );

        $awaiting = 0;
        $preparing = 0;
        $onCustody = 0;
        $returned = 0;

        foreach ($requests as $request) {
            /** @var CustodyTransaction|null $custody */
            $custody = $custodies->get($request->id);

            if ($custody) {
                if ($custody->released_at !== null) {
                    if ($this->returnMetrics->completionMoment($custody) !== null) {
                        $returned++;
                    } else {
                        $onCustody++;
                    }

                    continue;
                }

                if ((string) $custody->status === 'PREPARING_RELEASE') {
                    $preparing++;
                    continue;
                }
            }

            $status = $request->status instanceof RequestStatus
                ? $request->status->value
                : (string) $request->status;

            if (in_array($status, $approvedValues, true)) {
                $awaiting++;
            }
        }

        return [
            ['key' => 'approved', 'label' => 'Awaiting release', 'value' => $awaiting, 'note' => 'Approved, not yet in preparation'],
            ['key' => 'preparing', 'label' => 'Preparing release', 'value' => $preparing, 'note' => 'Being prepared by SPMU'],
            ['key' => 'custody', 'label' => 'On custody', 'value' => $onCustody, 'note' => 'Released, not yet returned'],
            ['key' => 'returned', 'label' => 'Returned', 'value' => $returned, 'note' => 'Physical return recorded'],
        ];
    }

    /**
     * The borrowings that are out past their due date right now.
     *
     * Present tense on purpose, and filtered by borrower exactly as the other
     * current-state figures are: an item released in August and still held in
     * September is overdue in September. Ordered by the oldest due date, which
     * is the one needing attention first.
     *
     * @return array<string, mixed>
     */
    public function currentOverdue(?string $division, ?string $unit, int $limit = 5, ?int $borrower = null): array
    {
        $rows = $this->currentlyOverdueQuery($division, $unit, $borrower)
            ->with(['borrower', 'request'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        $total = $this->currentlyOverdueQuery($division, $unit, $borrower)->count();

        return [
            'total' => $total,
            'shown' => $rows->count(),
            'cases' => $rows->map(function ($custody): array {
                /* Whole calendar days after the due date, never negative. */
                $days = $custody->due_at
                    ? max(0, (int) $custody->due_at->startOfDay()->diffInDays(now()->startOfDay()))
                    : null;

                return [
                    'custody_id' => $custody->id,
                    'borrower' => (string) ($custody->borrower?->full_name ?? 'Unknown borrower'),
                    'custody_no' => (string) $custody->custody_no,
                    'request_no' => (string) ($custody->request?->request_no ?? ''),
                    'due_at' => $custody->due_at,
                    'days_overdue' => $days,
                ];
            })->all(),
        ];
    }

    /**
     * Condition quantities actually recorded at return inspection in-period.
     *
     * The event date is return_transactions.received_at, not the original
     * request filing date.
     *
     * @return array<string, mixed>
     */
    public function returnConditions(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        $query = DB::table('return_lines')
            ->join('return_transactions', 'return_transactions.id', '=', 'return_lines.return_transaction_id')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'return_transactions.custody_transaction_id')
            ->whereBetween('return_transactions.received_at', [$from, $to]);

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $query->whereIn('custody_transactions.request_version_id', $versionIds);
        }

        if ($borrower !== null) {
            $query->where('custody_transactions.borrower_user_id', $borrower);
        }

        $rows = $query
            ->groupBy('return_lines.condition_code')
            ->select('return_lines.condition_code AS code')
            ->selectRaw('SUM(return_lines.quantity_received) AS quantity')
            ->orderByDesc('quantity')
            ->get();

        $highest = (float) ($rows->max('quantity') ?: 0);
        $total = (float) $rows->sum('quantity');

        return [
            'total' => $total + 0,
            'rows' => $rows->map(fn ($row): array => [
                'code' => (string) $row->code,
                'label' => str((string) $row->code)->replace('_', ' ')->title()->toString(),
                'quantity' => (float) $row->quantity + 0,
                'share' => $highest > 0 ? (int) round((float) $row->quantity / $highest * 100) : 0,
                'is_fine' => strtoupper((string) $row->code) === 'FINE',
            ])->all(),
        ];
    }

    public function stockCoverage(
        InventoryService $inventoryService,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 10,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $days = max(1, (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);
        $windowSufficient = $days >= self::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS;

        $usageQuery = DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$from, $to]);

        if (($division !== null && $division !== '' && $division !== 'all')
            || ($unit !== null && $unit !== '' && $unit !== 'all')) {
            $versionIds = DB::table('request_versions')
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($versions) => $versions->where('division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($versions) => $versions->where('office_unit', $unit)
                )
                ->select('id');

            $usageQuery->whereIn('custody_transactions.request_version_id', $versionIds);
        }

        $usage = $usageQuery
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw('SUM(custody_lines.actual_released_quantity) AS quantity')
            ->selectRaw('COUNT(DISTINCT custody_transactions.id) AS releases')
            ->get()
            ->keyBy('item_id');

        $items = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->get();

        $balances = $inventoryService->portfolio($items, now()->startOfDay(), now()->endOfDay());
        $rows = [];

        foreach ($items as $item) {
            $activity = $usage->get($item->id);
            $released = (float) ($activity->quantity ?? 0);
            $releases = (int) ($activity->releases ?? 0);

            if ($released <= 0) {
                continue;
            }

            $balance = $balances[$item->id] ?? [];
            $available = (float) ($balance['borrower_available'] ?? $balance['current_available'] ?? 0);
            $sufficient = $windowSufficient && $releases >= self::STOCK_COVERAGE_MINIMUM_RELEASES;
            $perDay = $sufficient ? $released / $days : null;
            $cover = ($perDay !== null && $perDay > 0) ? $available / $perDay : null;

            $rows[] = [
                'item_id' => $item->id,
                'name' => $item->unique_description,
                'available' => $available + 0,
                'released' => $released + 0,
                'releases' => $releases,
                'sufficient' => $sufficient,
                'per_day' => $perDay === null ? null : round($perDay, 2),
                'days_cover' => $cover === null ? null : (int) floor($cover),
                'risk' => $sufficient ? $this->stockoutRisk($cover) : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a['days_cover'] ?? PHP_INT_MAX) <=> ($b['days_cover'] ?? PHP_INT_MAX));

        $measured = array_values(array_filter($rows, fn (array $row): bool => $row['sufficient']));
        $atRisk = array_values(array_filter($measured, fn (array $row): bool => $row['risk'] === 'High'));
        $insufficient = count($rows) - count($measured);

        return [
            'available' => $measured !== [],
            'window_days' => $days,
            'window_sufficient' => $windowSufficient,
            'items' => array_slice($rows, 0, $limit),
            'measured' => count($measured),
            'insufficient' => $insufficient,
            'high_risk' => count($atRisk),
            'thresholds' => [
                'high' => self::STOCKOUT_RISK_HIGH_DAYS,
                'medium' => self::STOCKOUT_RISK_MEDIUM_DAYS,
            ],
            'requirement' => [
                'window_days' => self::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS,
                'releases' => self::STOCK_COVERAGE_MINIMUM_RELEASES,
            ],
            'summary' => $this->coverageSentence($rows, $measured, $atRisk, $windowSufficient),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $measured
     * @param  list<array<string, mixed>>  $atRisk
     */
    private function coverageSentence(
        array $rows,
        array $measured,
        array $atRisk,
        bool $windowSufficient
    ): string {
        if ($rows === []) {
            return 'No equipment was released during this period, so stock coverage cannot be estimated yet.';
        }

        if (! $windowSufficient) {
            return 'The selected period is shorter than '
                .self::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS
                .' days, which is too short to draw a usage rate from. Choose a longer reporting period.';
        }

        if ($measured === []) {
            return 'No equipment has been released often enough to estimate stock coverage yet. '
                .'An item needs at least '.self::STOCK_COVERAGE_MINIMUM_RELEASES
                .' separate releases in the period before a usage rate is projected from it.';
        }

        return $atRisk === []
            ? 'No equipment is estimated to run short within '
                .self::STOCKOUT_RISK_HIGH_DAYS.' days at the observed usage rate.'
            : count($atRisk).' '.(count($atRisk) === 1 ? 'item is' : 'items are')
                .' estimated to last under '.self::STOCKOUT_RISK_HIGH_DAYS
                .' days at the observed usage rate.';
    }

    /** High | Medium | Low, or null when usage gives no reading. */
    private function stockoutRisk(?float $daysCover): ?string
    {
        if ($daysCover === null) {
            return null;
        }

        return match (true) {
            $daysCover < self::STOCKOUT_RISK_HIGH_DAYS => 'High',
            $daysCover <= self::STOCKOUT_RISK_MEDIUM_DAYS => 'Medium',
            default => 'Low',
        };
    }

    /* ------------------------------------------------------------------ */
    /* Filter options                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Units offered in the Unit filter, grouped by division.
     *
     * Sourced from the same authoritative organizational master Reports and
     * Requests use (OrganizationalStructure::unitsByDivision()), not from
     * filed-request activity: a current active unit must stay selectable
     * even with zero requests in the selected period. Reporting Period /
     * Division / Unit still narrow the RESULTS elsewhere in this class; they
     * must never narrow which units exist to choose from.
     *
     * @return Collection<string, list<string>>
     */
    public function unitOptions(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return collect(OrganizationalStructure::unitsByDivision());
    }
}
