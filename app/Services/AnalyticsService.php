<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BillingStatement;
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
    public const DIVISIONS = OrganizationalStructure::SHORT_LABELS;

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
     * Requests created in the period, narrowed by the borrower filters.
     *
     * The join pins each request to its current version so a revised request
     * is counted under the unit it currently belongs to. States listed in
     * excludedFromActivity() are left out, so an abandoned draft never
     * inflates a demand figure.
     */
    public function requestScope(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null
    ): Builder {
        $query = BorrowingRequest::query()
            ->join('request_versions', function ($join): void {
                $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                    ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
            })
            ->whereBetween('borrowing_requests.created_at', [$from, $to])
            /* Drafts, withdrawals and lapsed requests are not activity. */
            ->whereNotIn('borrowing_requests.status', self::excludedFromActivity());

        if ($division !== null && $division !== '' && $division !== 'all') {
            $query->where('request_versions.division_code', $division);
        }

        if ($unit !== null && $unit !== '' && $unit !== 'all') {
            $query->where('request_versions.office_unit', $unit);
        }

        return $query;
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
     * Custody transactions as they stand right now, whatever period the
     * originating request was filed in.
     *
     * "Currently out" and "needs follow-up" are present-tense questions. An
     * item released in August and still held in September is still out in
     * September, so tying these figures to the reporting period would make
     * them vanish from view precisely when they most need attention.
     *
     * The borrower filters still apply, because the SPMU Head narrowing to
     * one division expects every figure on the page to follow.
     */
    private function currentCustodyScope(?string $division, ?string $unit): Builder
    {
        $query = CustodyTransaction::query();

        if (($division === null || $division === '' || $division === 'all')
            && ($unit === null || $unit === '' || $unit === 'all')) {
            return $query;
        }

        return $query->whereIn(
            'request_id',
            BorrowingRequest::query()
                ->join('request_versions', function ($join): void {
                    $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                        ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
                })
                ->when(
                    $division !== null && $division !== '' && $division !== 'all',
                    fn ($inner) => $inner->where('request_versions.division_code', $division)
                )
                ->when(
                    $unit !== null && $unit !== '' && $unit !== 'all',
                    fn ($inner) => $inner->where('request_versions.office_unit', $unit)
                )
                ->select('borrowing_requests.id')
        );
    }

    /** Released, not yet closed - the assets physically out right now. */
    private function currentlyOutQuery(?string $division, ?string $unit): Builder
    {
        return $this->currentCustodyScope($division, $unit)
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
    private function currentlyOverdueQuery(?string $division, ?string $unit): Builder
    {
        return $this->currentlyOutQuery($division, $unit)
            ->where(function ($query): void {
                $query->where('status', 'OVERDUE')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('due_at')->where('due_at', '<', now());
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
        ?string $unit
    ): array {
        $total = (clone $this->requestScope($from, $to, $division, $unit))
            ->count('borrowing_requests.id');

        $approved = (clone $this->requestScope($from, $to, $division, $unit))
            ->whereIn('borrowing_requests.status', $this->approvedStatuses())
            ->count('borrowing_requests.id');

        /*
         * Both of the next two are present-tense: they describe what is out
         * and what is late right now, not what the selected period produced.
         */
        $onCustody = $this->currentlyOutQuery($division, $unit)->count();
        $needsFollowUp = $this->currentlyOverdueQuery($division, $unit)->count();

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

        $groups = collect(self::DIVISIONS)
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

        $columns = collect(self::DIVISIONS)
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
        int $limit = 5
    ): array {
        $custodyIds = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
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
        int $limit = 5
    ): array {
        $versionIds = $this->requestScope($from, $to, $division, $unit)
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
        ?string $unit
    ): array {
        $requests = (clone $this->requestScope($from, $to, $division, $unit))
            ->count('borrowing_requests.id');

        /*
         * Distinct units that actually filed something. An office that exists
         * in the organisation but filed nothing this period is not active, so
         * the count comes from the request versions rather than the org table.
         */
        $activeUnits = (clone $this->requestScope($from, $to, $division, $unit))
            ->whereNotNull('request_versions.office_unit')
            ->where('request_versions.office_unit', '!=', '')
            ->distinct()
            ->count('request_versions.office_unit');

        /* Expressed demand: what the request items asked for. */
        $requestedQuantity = (float) DB::table('request_items')
            ->whereIn(
                'request_items.request_version_id',
                $this->requestScope($from, $to, $division, $unit)->select('request_versions.id')
            )
            ->sum('request_items.requested_quantity');

        /*
         * Actual utilisation: the quantity recorded at physical release. Not
         * the approved quantity, and not the requested quantity.
         */
        $releasedQuantity = (float) DB::table('custody_lines')
            ->whereIn(
                'custody_lines.custody_transaction_id',
                $this->custodyScope($from, $to, $division, $unit)
                    ->whereNotNull('released_at')
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
        string $periodSelection
    ): array {
        $rows = $this->requestScope($from, $to, $division, $unit)
            ->select('borrowing_requests.created_at')
            ->get();

        $buckets = $this->emptyBuckets($from, $to, $periodSelection);

        foreach ($rows as $row) {
            $key = $this->bucketKey($row->created_at, $periodSelection);

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
        ?string $unit
    ): array {
        $closed = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNotNull('closed_at')
            ->get(['closed_at', 'due_at']);

        $onTime = $closed->filter(
            fn ($custody): bool => $custody->due_at === null
                || $custody->closed_at->lessThanOrEqualTo($custody->due_at)
        )->count();

        $late = $closed->count() - $onTime;

        /* Still out and past due, regardless of when it was requested. */
        $overdue = $this->currentlyOverdueQuery($division, $unit)->count();

        $custodyIds = $this->custodyScope($from, $to, $division, $unit)->select('id');

        /*
         * Billing statements carry no custody column of their own; they reach
         * custody through the penalty line that was assessed against it.
         */
        $openCases = Incident::query()
            ->whereIn('custody_transaction_id', $custodyIds)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->count()
            + BillingStatement::query()
                ->whereNotIn('status', ['SETTLED', 'WAIVED', 'VOID'])
                ->whereExists(function ($query) use ($custodyIds): void {
                    $query->select(DB::raw(1))
                        ->from('billing_lines')
                        ->join('penalties', 'penalties.id', '=', 'billing_lines.penalty_id')
                        ->whereColumn('billing_lines.billing_statement_id', 'billing_statements.id')
                        ->whereIn('penalties.custody_transaction_id', $custodyIds);
                })
                ->count();

        $completed = $closed->count();

        return [
            'on_time' => $onTime,
            'late' => $late,
            'overdue' => $overdue,
            'open_cases' => $openCases,
            'completed' => $completed,

            /*
             * Rates are null rather than zero when nothing has been returned:
             * "0% on time" and "no returns yet" are different readings, and
             * only one of them is true here.
             */
            'on_time_rate' => $completed > 0 ? round($onTime / $completed * 100, 1) : null,
            'late_rate' => $completed > 0 ? round($late / $completed * 100, 1) : null,

            'average_duration' => $this->averageCustodyDuration($from, $to, $division, $unit),
            'has_data' => $closed->isNotEmpty() || $overdue > 0 || $openCases > 0,
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
            'problem' => 0.0,
            /*
             * Held by an open incident. Kept apart from `problem`, which also
             * carries lost, stolen and destroyed: only this one is subtracted
             * from current availability, so only this one belongs in a
             * composition of serviceable stock.
             */
            'incident' => 0.0,
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
            $totals['available'] += (float) ($balance['current_available'] ?? 0);

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
            $totals['maintenance'] += (float) ($balance['damaged_maintenance'] ?? 0);
            $totals['laundry'] += (float) ($balance['laundry'] ?? 0);
            $totals['incident'] += (float) ($balance['incident'] ?? 0);
            $totals['problem'] += (float) ($balance['lost'] ?? 0)
                + (float) ($balance['stolen'] ?? 0)
                + (float) ($balance['destroyed'] ?? 0)
                + (float) ($balance['incident'] ?? 0);

            $stock = (float) ($balance['serviceable_total'] ?? 0);

            /* Nothing serviceable to be a share of. */
            if (! $item->borrowable || $stock <= 0) {
                continue;
            }

            $usable = (float) ($balance['current_available'] ?? 0);
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
                : ($totals['problem'] > 0 || $totals['maintenance'] > 0
                    ? ($totals['problem'] + $totals['maintenance']).' units are currently unavailable through maintenance or an open incident.'
                    : 'No inventory units are currently held by maintenance or an incident.'),
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

            $usable = (float) ($balance['current_available'] ?? 0);
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
     * The fast-moving ranking can be answered from custody lines alone, but
     * the slow-moving one cannot: an item borrowed zero times has no custody
     * line to find. This starts from the catalogue and joins activity onto
     * it, so items with no movement are the ones that surface first - which
     * is the whole point of the question.
     *
     * @return array<string, mixed>
     */
    public function slowMovingItems(
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 10
    ): array {
        $released = DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$from, $to])
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
            ->select('borrowing_requests.created_at')
            ->get()
            ->pluck('created_at');

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
        $rows = $this->custodyScope($from, $to, $division, $unit)
            ->join('users', 'users.id', '=', 'custody_transactions.borrower_user_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereNotNull('custody_transactions.closed_at')
            ->whereNotNull('custody_transactions.due_at')
            ->whereColumn('custody_transactions.closed_at', '>', 'custody_transactions.due_at')
            ->groupBy('users.id', 'users.full_name')
            ->select('users.full_name AS name')
            ->selectRaw('COUNT(custody_transactions.id) AS late_returns')
            ->orderByDesc('late_returns')
            ->limit($limit)
            ->get();

        $highest = (int) ($rows->max('late_returns') ?: 0);

        return [
            'borrowers' => $rows->map(fn ($row): array => [
                'name' => (string) $row->name,
                'late_returns' => (int) $row->late_returns,
                'share' => $highest > 0 ? (int) round((int) $row->late_returns / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No borrowing was returned after its due date during this period.'
                : $rows->first()->name.' recorded the most late returns ('
                    .(int) $rows->first()->late_returns.').',
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
        ?string $unit
    ): array {
        $rows = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNotNull('closed_at')
            ->get(['released_at', 'closed_at']);

        if ($rows->isEmpty()) {
            return ['available' => false, 'summary' => 'No completed borrowings to measure yet.'];
        }

        $hours = $rows
            ->map(fn ($row): float => (float) $row->released_at->diffInMinutes($row->closed_at) / 60)
            ->filter(fn (float $value): bool => $value >= 0);

        if ($hours->isEmpty()) {
            return ['available' => false, 'summary' => 'No completed borrowings to measure yet.'];
        }

        $averageHours = $hours->avg();
        $days = $averageHours / 24;

        return [
            'available' => true,
            'count' => $rows->count(),
            'hours' => round($averageHours, 1),
            'days' => round($days, 1),
            'label' => $days >= 1
                ? round($days, 1).' '.(round($days, 1) === 1.0 ? 'day' : 'days')
                : round($averageHours).' '.(round($averageHours) === 1.0 ? 'hour' : 'hours'),
            'summary' => 'A completed borrowing lasted about '
                .($days >= 1 ? round($days, 1).' days' : round($averageHours).' hours')
                .' on average across '.$rows->count().' '
                .($rows->count() === 1 ? 'record' : 'records').'.',
        ];
    }

    /**
     * Incidents and their affected quantity for the period.
     *
     * Nothing is inferred: with no incident records the section reports a
     * clean zero rather than an invented history.
     *
     * @return array<string, mixed>
     */
    public function incidentSummary(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $custodyIds = $this->custodyScope($from, $to, $division, $unit)->select('id');

        $incidents = Incident::query()
            ->whereIn('custody_transaction_id', $custodyIds)
            ->with('lines')
            ->get();

        $released = (float) DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->whereIn('custody_lines.custody_transaction_id', $custodyIds)
            ->whereNotNull('custody_transactions.released_at')
            ->sum('custody_lines.actual_released_quantity');

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
            /* A rate needs a denominator; without releases there is none. */
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
     * Usable availability is InventoryService's current_available, the same
     * authoritative figure the Inventory module uses.
     *
     * @return array<string, mixed>
     */
    /* ------------------------------------------------------------------ */
    /* Section F2 - Return performance detail                              */
    /* ------------------------------------------------------------------ */

    /**
     * Completed returns per bucket, split into on-time and late.
     *
     * Same population as returns(): custody belonging to requests filed in the
     * period. Buckets follow the selected period exactly as trend() does, so a
     * return closed outside the window is not plotted - the card says so.
     *
     * ON TIME and LATE are both finished borrowings. Equipment that is still
     * out is OVERDUE and never appears here; that measure is reported on its
     * own and the two are never added together.
     *
     * @return array<string, mixed>
     */
    public function returnTrend(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        string $periodSelection
    ): array {
        $closed = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNotNull('closed_at')
            ->get(['closed_at', 'due_at']);

        $buckets = [];

        foreach ($this->emptyBuckets($from, $to, $periodSelection) as $key => $bucket) {
            $buckets[$key] = $bucket + ['on_time' => 0, 'late' => 0];
        }

        foreach ($closed as $custody) {
            $key = $this->bucketKey($custody->closed_at, $periodSelection);

            if (! isset($buckets[$key])) {
                continue;
            }

            $onTime = $custody->due_at === null
                || $custody->closed_at->lessThanOrEqualTo($custody->due_at);

            $buckets[$key][$onTime ? 'on_time' : 'late']++;
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
                /* Both series share one scale so the shapes stay comparable. */
                'on_time_share' => (int) round($p['on_time'] / $highest * 100),
                'late_share' => (int) round($p['late'] / $highest * 100),
            ])->all(),
            'plotted' => (int) $points->sum(fn (array $p): int => $p['on_time'] + $p['late']),
            'total' => $closed->count(),
        ];
    }

    /**
     * Where this period's borrowings currently stand.
     *
     * Every stage is a status the workflow actually writes, counted over the
     * requests filed in the selected period, so the strip reads as one
     * population moving through the process rather than five unrelated totals.
     *
     * @return list<array<string, mixed>>
     */
    public function lifecycle(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $approved = (clone $this->requestScope($from, $to, $division, $unit))
            ->whereIn('borrowing_requests.status', $this->approvedStatuses())
            ->count('borrowing_requests.id');

        $preparing = (clone $this->custodyScope($from, $to, $division, $unit))
            ->where('status', 'PREPARING_RELEASE')
            ->count();

        $onCustody = (clone $this->custodyScope($from, $to, $division, $unit))
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->count();

        $returned = (clone $this->custodyScope($from, $to, $division, $unit))
            ->whereNotNull('released_at')
            ->whereNotNull('closed_at')
            ->count();

        return [
            ['key' => 'approved', 'label' => 'Approved', 'value' => $approved, 'note' => 'Cleared for release'],
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
    public function currentOverdue(?string $division, ?string $unit, int $limit = 5): array
    {
        $rows = $this->currentlyOverdueQuery($division, $unit)
            ->with(['borrower', 'request'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        $total = $this->currentlyOverdueQuery($division, $unit)->count();

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
     * What condition equipment came back in.
     *
     * Read straight from return_lines.condition_code, which is the value the
     * receiving officer recorded at inspection. Only codes actually present
     * are listed - a condition nobody recorded is not charted as a zero.
     *
     * @return array<string, mixed>
     */
    public function returnConditions(
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): array {
        $custodyIds = $this->custodyScope($from, $to, $division, $unit)->select('id');

        $rows = DB::table('return_lines')
            ->join('return_transactions', 'return_transactions.id', '=', 'return_lines.return_transaction_id')
            ->whereIn('return_transactions.custody_transaction_id', $custodyIds)
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
                /* FINE is the only code that means nothing went wrong. */
                'is_fine' => strtoupper((string) $row->code) === 'FINE',
            ])->all(),
        ];
    }

    public function stockCoverage(
        InventoryService $inventoryService,
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = 10
    ): array {
        $days = max(1, (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay()) + 1);

        /* A window this short cannot support a daily rate for any item. */
        $windowSufficient = $days >= self::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS;

        $usage = DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$from, $to])
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

            /* No consumption at all offers nothing to project from. */
            if ($released <= 0) {
                continue;
            }

            $balance = $balances[$item->id] ?? [];
            $available = (float) ($balance['current_available'] ?? 0);

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

        /* Shortest cover first; rows without a reading sink to the bottom. */
        usort($rows, fn (array $a, array $b): int => ($a['days_cover'] ?? PHP_INT_MAX) <=> ($b['days_cover'] ?? PHP_INT_MAX));

        $measured = array_values(array_filter($rows, fn (array $row): bool => $row['sufficient']));
        $atRisk = array_values(array_filter($measured, fn (array $row): bool => $row['risk'] === 'High'));
        $insufficient = count($rows) - count($measured);

        return [
            /* True only when at least one item could actually be measured. */
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
     * Units that actually appear on filed requests, grouped by division, so
     * the Unit filter never offers a value with no records behind it.
     *
     * @return Collection<string, list<string>>
     */
    public function unitOptions(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->requestScope($from, $to)
            ->select('request_versions.division_code', 'request_versions.office_unit')
            ->distinct()
            ->get()
            ->filter(fn ($row): bool => filled($row->office_unit) && filled($row->division_code))
            ->groupBy('division_code')
            ->map(fn (Collection $rows): array => $rows->pluck('office_unit')->unique()->sort()->values()->all());
    }
}
