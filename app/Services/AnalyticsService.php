<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BillingStatement;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
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
     * These mirror BorrowingRequestController::officeUnitsByDivision(), which
     * is what the request form writes. Labels are the borrower-facing names.
     */
    public const DIVISIONS = [
        'ACADEMIC' => 'Academic',
        'ADMINISTRATION' => 'Administrative',
        'RESEARCH_INNOVATION_COLLABORATION' => 'Research & Innovation',
    ];

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
     * is counted under the unit it currently belongs to.
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
            ->whereBetween('borrowing_requests.created_at', [$from, $to]);

        if ($division !== null && $division !== '' && $division !== 'all') {
            $query->where('request_versions.division_code', $division);
        }

        if ($unit !== null && $unit !== '' && $unit !== 'all') {
            $query->where('request_versions.office_unit', $unit);
        }

        return $query;
    }

    /**
     * Custody transactions belonging to the filtered requests.
     *
     * Custody is not restricted to the reporting period: "currently on
     * custody" is a present-tense question about items that are out now.
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

        /* Out now: released, not yet closed. */
        $onCustody = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->count();

        /* Past the expected return date and still not closed. */
        $needsFollowUp = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->where(function ($query): void {
                $query->where('status', 'OVERDUE')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('due_at')->where('due_at', '<', now());
                    });
            })
            ->count();

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
            ->orderByDesc('released')
            ->limit($limit)
            ->get();

        $highest = (float) ($rows->max('released') ?: 0);

        return [
            'metric' => 'Units released',
            'items' => $rows->map(fn ($row): array => [
                'name' => $row->name,
                'unit' => $row->unit,
                'released' => (float) $row->released + 0,
                'share' => $highest > 0 ? round((float) $row->released / $highest * 100) : 0,
            ])->all(),
            'summary' => $rows->isEmpty()
                ? 'No items were physically released during this period, so utilisation cannot be ranked yet.'
                : $rows->first()->name.' had the highest utilisation with '
                    .((float) $rows->first()->released + 0).' units released.',
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

        $overdue = $this->custodyScope($from, $to, $division, $unit)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->where(function ($query): void {
                $query->where('status', 'OVERDUE')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('due_at')->where('due_at', '<', now());
                    });
            })
            ->count();

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

        return [
            'on_time' => $onTime,
            'late' => $late,
            'overdue' => $overdue,
            'open_cases' => $openCases,
            'has_data' => $closed->isNotEmpty() || $overdue > 0 || $openCases > 0,
            'summary' => $this->returnsSentence($closed->count(), $onTime, $late, $overdue, $openCases),
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

        $totals = [
            'available' => 0.0,
            'allocated' => 0.0,
            'on_custody' => 0.0,
            'maintenance' => 0.0,
            'problem' => 0.0,
        ];

        foreach ($items as $item) {
            $balance = $inventoryService->availability(
                $item,
                now()->subYears(10)->startOfDay(),
                now()->addYears(10)->endOfDay()
            );

            $totals['available'] += (float) ($balance['current_available'] ?? $balance['available'] ?? 0);
            $totals['allocated'] += (float) ($balance['allocated'] ?? 0) + (float) ($balance['reserved'] ?? 0);
            $totals['on_custody'] += (float) ($balance['borrowed'] ?? 0);
            $totals['maintenance'] += (float) ($balance['damaged_maintenance'] ?? 0);
            $totals['problem'] += (float) ($balance['lost'] ?? 0)
                + (float) ($balance['stolen'] ?? 0)
                + (float) ($balance['destroyed'] ?? 0)
                + (float) ($balance['incident'] ?? 0);
        }

        $totals = array_map(fn (float $value): float => $value + 0, $totals);

        return [
            'item_count' => $items->count(),
            'totals' => $totals,
            'summary' => $items->isEmpty()
                ? 'No active inventory items are recorded yet.'
                : ($totals['problem'] > 0 || $totals['maintenance'] > 0
                    ? ($totals['problem'] + $totals['maintenance']).' units are currently unavailable through maintenance or an open incident.'
                    : 'No inventory units are currently held by maintenance or an incident.'),
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
