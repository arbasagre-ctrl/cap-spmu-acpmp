<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Support\OrganizationalStructure;
use App\Support\PeriodComparison;
use App\Support\RequestOutcomes;
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
     * Completed-return records already built in this request, by scope.
     *
     * Request-local only: the service instance lives for one request, so a
     * scope resolved once - returns(), the previous-period comparison, the
     * trend and both late-rate breakdowns all ask for the same window - is
     * loaded and classified once. Nothing here outlives the request and no
     * external cache store is involved.
     *
     * @var array<string, Collection<int, array{custody:CustodyTransaction,returned_at:Carbon,state:string}>>
     */
    private array $completedReturnRecordsCache = [];

    /**
     * Completed returns whose authoritative physical-return date is inside the
     * selected period.
     *
     * The result is memoised per scope for the life of this service instance.
     * Consumers read it - filter, map, group, count - and never mutate it;
     * Collection operations return new collections, so the cached instance
     * stays what it was when it was built.
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
        $key = implode('|', [
            Carbon::parse($from)->toDateTimeString(),
            Carbon::parse($to)->toDateTimeString(),
            ($division === null || $division === '' || $division === 'all') ? '' : $division,
            ($unit === null || $unit === '' || $unit === 'all') ? '' : $unit,
            $borrower === null ? '' : (string) $borrower,
        ]);

        return $this->completedReturnRecordsCache[$key] ??= $this->loadCompletedReturnRecords(
            $from, $to, $division, $unit, $borrower
        );
    }

    /**
     * The uncached build behind completedReturnRecords().
     *
     * @return Collection<int, array{custody:CustodyTransaction,returned_at:Carbon,state:string}>
     */
    private function loadCompletedReturnRecords(
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

    /**
     * Released and physically still out right now.
     *
     * Administrative closure is not the signal. After a physical return the
     * accountability workflow legitimately keeps a custody OBLIGATION_OPEN or
     * INCIDENT_OPEN with closed_at null until the case is settled, and that
     * custody is a completed return, not property that is out. So beyond the
     * released / not-closed guard, a custody counts only while the physical
     * signal the rest of the system uses says so - see physicallyOutstanding().
     */
    private function currentlyOutQuery(?string $division, ?string $unit, ?int $borrower = null): Builder
    {
        return $this->physicallyOutstanding(
            $this->currentCustodyScope($division, $unit, $borrower)
                ->whereNotNull('released_at')
                ->whereNull('closed_at')
                ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
        );
    }

    /**
     * Narrow a custody query to property that has not physically come back.
     *
     * The facts ReturnMetricsService::completionMoment() reads, translated
     * to SQL so the population is filtered in the database rather than by
     * classifying every custody in PHP. A custody is still out when:
     *
     *  - a custody line still carries released quantity not accounted for
     *    at return inspection (returned_quantity below actual_released_
     *    quantity) - the rule the Dashboard, the submission gate, the
     *    overdue scheduler and LateReturnService::assess() apply, so a
     *    partial return stays out;
     *  - or it carries linen whose authoritative physical receipt
     *    (laundry_jobs.worker_received_at) has not been recorded. Linen is
     *    accounted for at inspection from the Laundry Form but is out until
     *    Laundry personnel physically receive it;
     *  - or it carries no linen and no Return Inspection receipt exists at
     *    all. Without that receipt there is no physical return event to
     *    complete on, which is exactly how ReturnMetricsService reads it.
     *
     * Nothing here reads status, closed_at, accountability, billing or
     * verification timestamps: administrative closure is not physical return.
     */
    private function physicallyOutstanding(Builder $query): Builder
    {
        $carriesLinen = static function (Builder $custody): void {
            $custody->whereHas('lines.requestItem.inventoryItem', function ($items): void {
                $items->where('laundry_required', true);
            });
        };

        return $query->where(function (Builder $physical) use ($carriesLinen): void {
            $physical
                ->whereHas('lines', function ($lines): void {
                    $lines->whereColumn('returned_quantity', '<', 'actual_released_quantity');
                })
                ->orWhere(function (Builder $linen) use ($carriesLinen): void {
                    $carriesLinen($linen);
                    $linen->whereDoesntHave('laundryJob', function ($jobs): void {
                        $jobs->whereNotNull('worker_received_at');
                    });
                })
                ->orWhere(function (Builder $unreceived) use ($carriesLinen): void {
                    $unreceived
                        ->whereNot($carriesLinen)
                        ->whereDoesntHave('returns', function ($returns): void {
                            $returns->whereNotNull('received_at');
                        });
                });
        });
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

    /* ------------------------------------------------------------------ */
    /* Period-over-period comparison                                       */
    /* ------------------------------------------------------------------ */

    /**
     * The equal-length period immediately before the selected one.
     *
     * This is the module's one definition of "the previous period", shared
     * with ForecastService::historyWindows(): the same number of days,
     * ending the day before the selected period starts. A calendar month is
     * therefore compared with the same number of days before it, not with
     * the previous calendar month; ReportingPeriodService offers no other
     * equivalent-period rule, and inventing one here would put two
     * definitions of "previous" on the same page.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function previousWindow(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = max(1, Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);

        $previousTo = Carbon::parse($from)->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1)->startOfDay();

        return [$previousFrom, $previousTo];
    }

    /**
     * Filed demand and physical release, each against the previous period.
     *
     * Every figure is measured twice by demandTotals() - once over the
     * selected period and once over previousWindow() - so each keeps its own
     * event date: requests and requested quantity follow the filing date,
     * released quantity follows released_at. The same division, unit and
     * borrower scope applies to both windows; only the dates move.
     *
     * Nothing present-tense is compared here. Currently Out and Currently
     * Overdue describe today and have no previous-period counterpart.
     *
     * @return array<string, mixed>
     */
    public function demandComparison(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        [$previousFrom, $previousTo] = $this->previousWindow($from, $to);
        $available = $previousTo->isPast();
        $currentComplete = Carbon::parse($to)->isPast();

        $current = $this->demandTotals($from, $to, $division, $unit, $borrower);
        $previous = $available
            ? $this->demandTotals($previousFrom, $previousTo, $division, $unit, $borrower)
            : ['requests' => 0, 'requested_quantity' => 0, 'released_quantity' => 0];

        return [
            'from' => $previousFrom,
            'to' => $previousTo,
            'available' => $available,
            'current_complete' => $currentComplete,
            'requests' => PeriodComparison::count(
                $current['requests'], $previous['requests'], $available, $currentComplete
            ),
            'requested_quantity' => PeriodComparison::count(
                $current['requested_quantity'], $previous['requested_quantity'], $available, $currentComplete
            ),
            'released_quantity' => PeriodComparison::count(
                $current['released_quantity'], $previous['released_quantity'], $available, $currentComplete
            ),
        ];
    }

    /**
     * Completed returns against the previous period.
     *
     * Both windows use completedReturnRecords(): a return belongs to the
     * period its physical completion date falls in, classified by
     * ReturnMetricsService exactly as the KPI cards are. The on-time rate is
     * compared in percentage points and is only compared at all when both
     * periods had a completed return to measure it from.
     *
     * Currently Overdue and open accountability are deliberately absent:
     * one is today's backlog and the other is a hybrid of opening date and
     * present status, and neither has an equivalent previous-period reading.
     *
     * @return array<string, mixed>
     */
    public function returnComparison(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null
    ): array {
        [$previousFrom, $previousTo] = $this->previousWindow($from, $to);
        $available = $previousTo->isPast();
        $currentComplete = Carbon::parse($to)->isPast();

        $current = $this->completedReturnCounts($from, $to, $division, $unit, $borrower);
        $previous = $available
            ? $this->completedReturnCounts($previousFrom, $previousTo, $division, $unit, $borrower)
            : ['completed' => 0, 'on_time' => 0, 'late' => 0, 'on_time_rate' => null];

        return [
            'from' => $previousFrom,
            'to' => $previousTo,
            'available' => $available,
            'current_complete' => $currentComplete,
            'completed' => PeriodComparison::count(
                $current['completed'], $previous['completed'], $available, $currentComplete
            ),
            'on_time' => PeriodComparison::count(
                $current['on_time'], $previous['on_time'], $available, $currentComplete
            ),
            'late' => PeriodComparison::count(
                $current['late'], $previous['late'], $available, $currentComplete
            ),
            'on_time_rate' => PeriodComparison::rate(
                $current['on_time_rate'], $previous['on_time_rate'], $available, $currentComplete
            ),
        ];
    }

    /**
     * Completed returns in the period, split by outcome, with the on-time
     * rate. The single counting rule behind returns() and returnComparison().
     *
     * @return array{completed:int,on_time:int,late:int,on_time_rate:?float}
     */
    private function completedReturnCounts(
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

        $completed = $completedRows->count();

        return [
            'completed' => $completed,
            'on_time' => $onTime,
            'late' => $late,
            'on_time_rate' => $completed > 0 ? round($onTime / $completed * 100, 1) : null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Section D2 - Request outcomes                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Filed requests needed before a leading outcome is stated as an
     * insight. Below this, "most requests are approved" describes two
     * records, not a pattern.
     */
    public const OUTCOME_INSIGHT_MINIMUM = 5;

    /**
     * Requests formally filed in the period, whatever became of them since.
     *
     * This is the Request Outcomes cohort and it is deliberately not
     * requestScope(). The activity scope leaves CANCELLED and EXPIRED
     * requests out because they are not work the unit carried out; for an
     * outcome reading they are outcomes, so a request that was filed and
     * later cancelled or expired belongs in the denominator.
     *
     * FILING
     * ------
     * The filing event is request_versions.submitted_at on the current
     * version - the stamp submit() writes when a draft or a returned request
     * goes to SPMU. The same legacy fallback requestScope() uses is kept for
     * older rows without that stamp, but only for statuses that prove filing
     * on their own (RequestOutcomes::legacyFilingProofStatuses()): a
     * cancelled or expired row with no submitted_at cannot be shown to have
     * been filed and is left out rather than assumed. A DRAFT never enters,
     * including a returned request whose corrected version has not been
     * resubmitted yet; it rejoins the cohort of its resubmission date.
     *
     * One request is one observation: the join is on the current version,
     * exactly as everywhere else in this class.
     */
    public function requestOutcomeScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null,
        ?int $borrower = null
    ): Builder {
        $legacyProof = array_map(
            static fn (RequestStatus $status): string => $status->value,
            RequestOutcomes::legacyFilingProofStatuses()
        );

        $query = BorrowingRequest::query()
            ->join('request_versions', function ($join): void {
                $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                    ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
            })
            ->where('borrowing_requests.status', '!=', RequestStatus::Draft->value)
            ->where(function ($filed) use ($from, $to, $legacyProof): void {
                $filed->whereBetween('request_versions.submitted_at', [$from, $to])
                    ->orWhere(function ($legacy) use ($from, $to, $legacyProof): void {
                        $legacy->whereNull('request_versions.submitted_at')
                            ->whereBetween('borrowing_requests.created_at', [$from, $to])
                            ->whereIn('borrowing_requests.status', $legacyProof);
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
     * Current workflow outcome of the requests filed in the period.
     *
     * A cohort reading, not a decision log: a request filed in September and
     * approved in October is Approved in September's cohort. Every request
     * in the cohort lands in exactly one group through RequestOutcomes, and
     * the groups sum to the total by construction. Shares are shares of the
     * filed total - never of quantity, approvals or active requests.
     *
     * @return array<string, mixed>
     */
    public function requestOutcomes(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null,
        ?int $borrower = null
    ): array {
        $counts = $this->requestOutcomeScope($from, $to, $division, $unit, $borrower)
            ->select('borrowing_requests.status')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('borrowing_requests.status')
            ->pluck('total', 'status');

        $byGroup = array_fill_keys(array_keys(RequestOutcomes::GROUPS), 0);

        foreach ($counts as $status => $count) {
            $case = $status instanceof RequestStatus ? $status : RequestStatus::from((string) $status);
            $group = RequestOutcomes::groupFor($case);

            /* The scope already excludes drafts; a null here would be a scope fault. */
            if ($group !== null) {
                $byGroup[$group] += (int) $count;
            }
        }

        $total = array_sum($byGroup);

        $groups = [];

        foreach (RequestOutcomes::GROUPS as $key => $label) {
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'phrase' => RequestOutcomes::PHRASES[$key],
                'count' => $byGroup[$key],
                /* Guarded: an empty cohort has no shares, not 0% shares. */
                'share' => $total > 0 ? round($byGroup[$key] / $total * 100, 1) : null,
                'statuses' => array_map(
                    static fn (RequestStatus $status): string => $status->label(),
                    RequestOutcomes::statusesFor($key)
                ),
            ];
        }

        /*
         * The cohort is the activity scope plus the requests it leaves out,
         * so the reader can reconcile the total with Requests Filed.
         */
        $closedAfterFiling = $byGroup['cancelled'] + $byGroup['expired'];

        $ranked = collect($groups)->filter(fn (array $g): bool => $g['count'] > 0)->sortByDesc('count')->values();
        $leader = $ranked->first();
        $runnerUp = $ranked->get(1);
        $tied = $leader !== null && $runnerUp !== null && $runnerUp['count'] === $leader['count'];

        return [
            'total' => $total,
            'available' => $total > 0,
            'closed_after_filing' => $closedAfterFiling,
            'groups' => $groups,
            'leader' => $leader,
            'summary' => match (true) {
                $total === 0 => 'No filed requests are available for outcome analysis in this period.',
                $total < self::OUTCOME_INSIGHT_MINIMUM || $tied || $leader === null => null,
                default => 'Most filed requests are currently '.$leader['phrase']
                    .' ('.$leader['count'].' of '.$total.').',
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
        $counts = $this->completedReturnCounts($from, $to, $division, $unit, $borrower);
        $onTime = $counts['on_time'];
        $late = $counts['late'];
        $completed = $counts['completed'];

        /* Still out and past due is a present-tense measure. */
        $overdue = $this->currentlyOverdueQuery($division, $unit, $borrower)->count();

        /*
         * One custody equals one accountability case in Analytics. Incident,
         * late-return and billing records are de-duplicated by custody id so a
         * single obligation never appears as two or three separate cases.
         */
        $openCases = $this->openAccountabilityCount($from, $to, $division, $unit, $borrower);

        return [
            'on_time' => $onTime,
            'late' => $late,
            'overdue' => $overdue,
            'open_cases' => $openCases,
            'completed' => $completed,

            'on_time_rate' => $counts['on_time_rate'],
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
     * Late-return share among completed returns, by division or by unit.
     *
     * The population is completedReturnRecords() - the very records behind
     * Returned On Time, Returned Late and the return trend: physically
     * completed returns whose authoritative completion date (Return
     * Inspection receipt, or the laundry receipt for linen) falls in the
     * period, classified by ReturnMetricsService. Nothing still out is in
     * it, so a currently overdue custody can never enter a denominator,
     * while a late return whose accountability is still open is a completed
     * return and does. Requests filed, quantities released and current
     * backlog play no part.
     *
     *     late_rate = returned late / completed returns in the same segment
     *
     * Attribution follows the request-version snapshot the custody carries
     * (division_code / office_unit at borrowing time), the same source the
     * filters and Reports use, never the borrower's present-day profile. A
     * unit is identified by division code plus unit name, as unitRankings()
     * identifies it, so like-named units in two divisions stay apart.
     *
     * Every segment with at least one completed return is listed; a segment
     * with none is absent rather than shown at 0%. Rows are ordered by late
     * rate, then completed returns, then label - a stable order, not a
     * grading, and each rate is always accompanied by its counts.
     *
     * @param  'division'|'unit'  $level
     * @return array<string, mixed>
     */
    public function lateReturnRates(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null,
        string $level = 'division'
    ): array {
        $records = $this->completedReturnRecords($from, $to, $division, $unit, $borrower);

        /* One query for every custody's borrowing-time snapshot. */
        $custodies = new \Illuminate\Database\Eloquent\Collection(
            $records->map(fn (array $row): CustodyTransaction => $row['custody'])->all()
        );
        $custodies->loadMissing('requestVersion');

        $labels = self::divisions();
        $segments = [];

        foreach ($records as $row) {
            /** @var CustodyTransaction $custody */
            $custody = $row['custody'];
            $version = $custody->requestVersion;
            $code = trim((string) ($version?->division_code ?? ''));
            $unitName = trim((string) ($version?->office_unit ?? ''));

            if ($level === 'unit') {
                /* A unit is a division plus a name; a nameless snapshot has no unit to report. */
                if ($unitName === '') {
                    continue;
                }

                $key = ($code !== '' ? $code : 'unspecified').'|'.$unitName;
                $label = $unitName;
            } else {
                $key = $code !== '' ? $code : 'unspecified';
                $label = $code !== '' ? ($labels[$code] ?? OrganizationalStructure::label($code)) : 'Unspecified';
            }

            $segments[$key] ??= [
                'key' => $key,
                'code' => $code !== '' ? $code : null,
                'division_label' => $code !== '' ? ($labels[$code] ?? OrganizationalStructure::label($code)) : 'Unspecified',
                'unit' => $level === 'unit' ? $unitName : null,
                'label' => $label,
                'completed' => 0,
                'on_time' => 0,
                'late' => 0,
            ];

            $segments[$key]['completed']++;

            if ($row['state'] === ReturnMetricsService::RETURNED_LATE) {
                $segments[$key]['late']++;
            } else {
                $segments[$key]['on_time']++;
            }
        }

        $groups = collect($segments)
            ->map(function (array $segment): array {
                $segment['late_rate'] = $segment['completed'] > 0
                    ? round($segment['late'] / $segment['completed'] * 100, 1)
                    : null;

                return $segment;
            })
            ->sortBy([
                fn (array $a, array $b): int => ($b['late_rate'] ?? -1) <=> ($a['late_rate'] ?? -1),
                fn (array $a, array $b): int => $b['completed'] <=> $a['completed'],
                fn (array $a, array $b): int => strcmp($a['label'], $b['label']),
            ])
            ->values();

        $completed = $records->count();
        $late = $records->where('state', ReturnMetricsService::RETURNED_LATE)->count();

        /*
         * The one-line reading names the segment with the most late returns,
         * a count, not the highest rate: a rate over a handful of returns
         * says less than a count, and the bars already rank the rates. Two
         * segments with the same count are a tie, and a tie is not a reading.
         */
        $byLate = $groups->sortBy([
            fn (array $a, array $b): int => $b['late'] <=> $a['late'],
            fn (array $a, array $b): int => strcmp($a['label'], $b['label']),
        ])->values();
        $leader = $byLate->first();
        $runnerUp = $byLate->get(1);
        $tied = $leader !== null && $runnerUp !== null && $runnerUp['late'] === $leader['late'];

        return [
            'level' => $level,
            'available' => $completed > 0,
            'completed' => $completed,
            'on_time' => $completed - $late,
            'late' => $late,
            'late_rate' => $completed > 0 ? round($late / $completed * 100, 1) : null,
            'groups' => $groups->all(),
            'summary' => match (true) {
                $completed === 0 => 'No completed returns are available for late-return rate analysis in this period.',
                $leader === null || $leader['late'] === 0 || $tied => null,
                default => $leader['label'].' recorded '.$leader['late'].' late '
                    .($leader['late'] === 1 ? 'return' : 'returns').' among '
                    .$leader['completed'].' completed '.($leader['completed'] === 1 ? 'return' : 'returns').'.',
            },
        ];
    }

    /**
     * The completed returns behind one late-rate segment, for a detail table.
     *
     * The same records lateReturnRates() counted, narrowed to the segment,
     * late returns first, then most recent completion, then custody number
     * so the order is stable.
     *
     * @return array{completed:int,late:int,rows:Collection<int, array<string, mixed>>}
     */
    public function lateReturnRateRecords(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        ?int $borrower = null,
        ?string $segmentDivision = null,
        ?string $segmentUnit = null,
        int $limit = 25
    ): array {
        $records = $this->completedReturnRecords($from, $to, $division, $unit, $borrower);

        $custodies = new \Illuminate\Database\Eloquent\Collection(
            $records->map(fn (array $row): CustodyTransaction => $row['custody'])->all()
        );
        $custodies->loadMissing('requestVersion');

        $matching = $records->filter(function (array $row) use ($segmentDivision, $segmentUnit): bool {
            $version = $row['custody']->requestVersion;
            $code = trim((string) ($version?->division_code ?? ''));
            $unitName = trim((string) ($version?->office_unit ?? ''));

            if ($segmentDivision !== null && ($segmentDivision === 'unspecified' ? $code !== '' : $code !== $segmentDivision)) {
                return false;
            }

            return $segmentUnit === null || $unitName === $segmentUnit;
        });

        $sorted = $matching
            ->sortBy([
                fn (array $a, array $b): int =>
                    (int) ($b['state'] === ReturnMetricsService::RETURNED_LATE) <=> (int) ($a['state'] === ReturnMetricsService::RETURNED_LATE),
                fn (array $a, array $b): int => $b['returned_at']->timestamp <=> $a['returned_at']->timestamp,
                fn (array $a, array $b): int => strcmp((string) $a['custody']->custody_no, (string) $b['custody']->custody_no),
            ])
            ->values();

        return [
            'completed' => $matching->count(),
            'late' => $matching->where('state', ReturnMetricsService::RETURNED_LATE)->count(),
            'rows' => $sorted->take($limit)->values(),
        ];
    }

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
     * Whole operational days a custody is past its effective due date.
     *
     * The effective due date is custody_transactions.due_at - the same date
     * LateReturnService::expectedReturn() enforces and currentlyOverdueQuery()
     * tests. Counted in calendar days from the due date's day to today's
     * day, never negative, and null only for a custody with no due date at
     * all. This is the one arithmetic behind every "days overdue" figure.
     */
    public function daysOverdue(CustodyTransaction $custody): ?int
    {
        if (! $custody->due_at) {
            return null;
        }

        return max(0, (int) $custody->due_at->copy()->startOfDay()->diffInDays(now()->startOfDay()));
    }

    /**
     * The aging bands for the current overdue backlog, in display order.
     *
     * No other part of the application defines overdue-age bands, so these
     * are declared once here. They are exact and exclusive: a custody with
     * 7 days is in the first band, with 8 in the second, with 31 in the
     * third. The bounds are inclusive; `max` null means open-ended.
     */
    public const OVERDUE_AGING_BANDS = [
        ['key' => '1-7', 'label' => '1–7 days', 'min' => 1, 'max' => 7],
        ['key' => '8-30', 'label' => '8–30 days', 'min' => 8, 'max' => 30],
        ['key' => '31-plus', 'label' => '31+ days', 'min' => 31, 'max' => null],
    ];

    /**
     * The band a days-overdue figure falls in.
     *
     * The overdue population is defined by status or due date, not by this
     * arithmetic, so a custody the workflow has flagged OVERDUE but which is
     * not yet a full day past due (or has no due date) is banded as
     * 'unbanded' rather than forced into 1–7. That keeps every band exact and
     * still lets the bands sum to the Currently Overdue count.
     */
    public function overdueAgingBand(?int $days): string
    {
        if ($days === null || $days < 1) {
            return 'unbanded';
        }

        foreach (self::OVERDUE_AGING_BANDS as $band) {
            if ($days >= $band['min'] && ($band['max'] === null || $days <= $band['max'])) {
                return $band['key'];
            }
        }

        return 'unbanded';
    }

    /**
     * The current overdue backlog grouped by how long it has been overdue.
     *
     * Current state only: the population is currentlyOverdueQuery() - the
     * very query behind the Currently Overdue KPI - under the same division,
     * unit and borrower scope and with no reporting-period condition, so the
     * groups always sum to that KPI. One custody transaction is one
     * observation. A custody that has physically come back, however late,
     * is not in this query and so never appears here.
     *
     * @return array<string, mixed>
     */
    public function overdueAging(?string $division, ?string $unit, ?int $borrower = null): array
    {
        /* Only the columns the banding needs; the backlog is read once. */
        $rows = $this->currentlyOverdueQuery($division, $unit, $borrower)
            ->get(['id', 'due_at', 'status']);

        $counts = array_fill_keys(array_column(self::OVERDUE_AGING_BANDS, 'key'), 0) + ['unbanded' => 0];

        foreach ($rows as $custody) {
            $counts[$this->overdueAgingBand($this->daysOverdue($custody))]++;
        }

        $total = $rows->count();
        /* The guard reconciles the total but is not a drawn band. */
        $highest = max(array_map(static fn (array $band): int => $counts[$band['key']], self::OVERDUE_AGING_BANDS));

        $groups = [];

        foreach (self::OVERDUE_AGING_BANDS as $band) {
            $count = $counts[$band['key']];

            $groups[] = [
                'key' => $band['key'],
                'label' => $band['label'],
                'min' => $band['min'],
                'max' => $band['max'],
                'count' => $count,
                /* Share of the currently overdue backlog; none when there is no backlog. */
                'share' => $total > 0 ? round($count / $total * 100, 1) : null,
                /* Display geometry only: width against the largest band. */
                'width' => $highest > 0 ? (int) round($count / $highest * 100) : 0,
            ];
        }

        $ranked = collect($groups)->filter(fn (array $g): bool => $g['count'] > 0)->sortByDesc('count')->values();
        $leader = $ranked->first();
        $tied = $leader !== null && $ranked->get(1) !== null && $ranked->get(1)['count'] === $leader['count'];
        $longest = $counts['31-plus'];

        return [
            'total' => $total,
            'available' => $total > 0,
            'groups' => $groups,
            'unbanded' => $counts['unbanded'],
            'leader' => $leader,
            'insight' => match (true) {
                $total === 0 => null,
                $longest > 0 => $longest.' current overdue '.($longest === 1 ? 'borrowing has' : 'borrowings have')
                    .' been overdue for more than 30 days.',
                $leader !== null && ! $tied => 'Most currently overdue borrowings are within '.$leader['label'].' past due.',
                default => null,
            },
        ];
    }

    /**
     * The custody physically out right now, soonest due first, for a detail
     * listing - the Currently Out KPI's own population.
     *
     * @return array{total:int,rows:Collection<int, CustodyTransaction>}
     */
    public function currentlyOutRecords(
        ?string $division,
        ?string $unit,
        ?int $borrower = null,
        int $limit = 25
    ): array {
        return [
            'total' => $this->currentlyOutQuery($division, $unit, $borrower)->count(),
            'rows' => $this->currentlyOutQuery($division, $unit, $borrower)
                ->with(['borrower', 'request', 'lines'])
                ->orderBy('due_at')
                ->orderBy('custody_no')
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * The custody physically out past its due date right now, oldest due
     * first, for a detail listing - the Currently Overdue KPI's own population.
     *
     * @return array{total:int,rows:Collection<int, CustodyTransaction>}
     */
    public function currentlyOverdueRecords(
        ?string $division,
        ?string $unit,
        ?int $borrower = null,
        int $limit = 25
    ): array {
        return [
            'total' => $this->currentlyOverdueQuery($division, $unit, $borrower)->count(),
            'rows' => $this->currentlyOverdueQuery($division, $unit, $borrower)
                ->with(['borrower', 'request'])
                ->orderBy('due_at')
                ->orderBy('custody_no')
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * The currently overdue custodies in one aging band (or all of them),
     * longest overdue first, for a detail listing.
     *
     * The same currentlyOverdueQuery() population as the KPI and the aging
     * groups; the band is applied with the same daysOverdue() arithmetic, so
     * a listing can never show a custody its band did not count. Ties on age
     * fall back to custody number so the order is stable.
     *
     * @return array{total:int,count:int,rows:Collection<int, CustodyTransaction>}
     */
    public function overdueAgingRecords(
        ?string $division,
        ?string $unit,
        ?int $borrower = null,
        ?string $band = null,
        int $limit = 25
    ): array {
        $all = $this->currentlyOverdueQuery($division, $unit, $borrower)
            ->with(['borrower', 'request', 'requestVersion'])
            ->get();

        $matching = $all
            ->filter(fn (CustodyTransaction $custody): bool =>
                $band === null || $this->overdueAgingBand($this->daysOverdue($custody)) === $band
            )
            ->sortBy([
                fn (CustodyTransaction $a, CustodyTransaction $b): int =>
                    ($this->daysOverdue($b) ?? -1) <=> ($this->daysOverdue($a) ?? -1),
                fn (CustodyTransaction $a, CustodyTransaction $b): int =>
                    strcmp((string) $a->custody_no, (string) $b->custody_no),
            ])
            ->values();

        return [
            'total' => $all->count(),
            'count' => $matching->count(),
            'rows' => $matching->take($limit)->values(),
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
                $days = $this->daysOverdue($custody);

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
