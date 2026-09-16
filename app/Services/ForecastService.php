<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Demand forecasting for the SPMU Head.
 *
 * METHOD
 * ------
 * A weighted moving average over the comparable periods immediately before the
 * selected one. If the SPMU Head is looking at September, the history is the
 * three preceding months; if they are looking at a week, it is the three
 * preceding weeks. The most recent period carries the most weight:
 *
 *     forecast = (3*P-1 + 2*P-2 + 1*P-3) / 6
 *
 * The method is deterministic: the same database and the same selected period
 * always produce the same number. There is no model, no training and no
 * randomness, so nothing here may be described as machine learning.
 *
 * MINIMUM HISTORY
 * ---------------
 * A forecast is only produced when all three preceding periods have already
 * finished AND they contain at least MINIMUM_OBSERVATIONS requests between
 * them. Two isolated records are not a trend, so the service reports
 * insufficient history instead of a number nobody should act on.
 *
 * ROUNDING
 * --------
 * Every forecast is rounded half-up to a whole request or unit and floored at
 * zero. Fractional requests do not exist, and a negative forecast is
 * meaningless.
 */
class ForecastService
{
    /** Comparable periods used as history, most recent first. */
    public const HISTORY_PERIODS = 3;

    /** Weights applied to those periods, most recent first. */
    public const WEIGHTS = [3, 2, 1];

    /** Total requests required across the history window before forecasting. */
    public const MINIMUM_OBSERVATIONS = 3;

    /** Requests a single unit needs across history before it is forecast. */
    public const UNIT_MINIMUM_OBSERVATIONS = 3;

    /**
     * Shortfall tolerated before an item is called a possible shortage,
     * expressed as a share of expected demand.
     */
    public const LIMITED_TOLERANCE = 0.25;

    /** Equipment types reported in the forecast table. */
    public const EQUIPMENT_LIMIT = 8;

    public function __construct(private readonly InventoryService $inventory) {}

    /* ------------------------------------------------------------------ */
    /* Periods                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The period immediately after the selected one, of the same length.
     *
     * Working in whole days keeps a calendar month forecasting a calendar
     * month rather than drifting by a few hours each time.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function forecastWindow(CarbonInterface $from, CarbonInterface $to): array
    {
        $days = $this->lengthInDays($from, $to);

        $start = Carbon::parse($to)->addDay()->startOfDay();

        return [$start, $start->copy()->addDays($days - 1)->endOfDay()];
    }

    /**
     * The comparable periods before the selected one, most recent first.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    public function historyWindows(
        CarbonInterface $from,
        CarbonInterface $to,
        int $count = self::HISTORY_PERIODS
    ): array {
        $days = $this->lengthInDays($from, $to);
        $windows = [];
        $end = Carbon::parse($from)->subDay()->endOfDay();

        for ($index = 0; $index < $count; $index++) {
            $start = $end->copy()->subDays($days - 1)->startOfDay();
            $windows[] = [$start, $end->copy()];
            $end = $start->copy()->subDay()->endOfDay();
        }

        return $windows;
    }

    private function lengthInDays(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(
            1,
            Carbon::parse($from)->startOfDay()->diffInDays(
                Carbon::parse($to)->startOfDay()
            ) + 1
        );
    }

    /* ------------------------------------------------------------------ */
    /* The weighted moving average                                         */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<float|int>  $values  Most recent period first.
     */
    public function weightedAverage(array $values): float
    {
        $weightSum = 0;
        $total = 0.0;

        foreach ($values as $index => $value) {
            $weight = self::WEIGHTS[$index] ?? 1;
            $total += $weight * (float) $value;
            $weightSum += $weight;
        }

        /* No history means no denominator; the caller reports insufficiency. */
        return $weightSum > 0 ? $total / $weightSum : 0.0;
    }

    /** Whole units, never negative. */
    public function round(float $value): int
    {
        return (int) max(0, round($value));
    }

    /**
     * Has enough of the past been recorded to project the next period?
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @param  list<int>  $counts
     */
    public function hasEnoughHistory(array $windows, array $counts): bool
    {
        if (count($windows) < self::HISTORY_PERIODS) {
            return false;
        }

        /* Every history period must already be over. A period still running
           would be counted as if it were complete and drag the average down. */
        foreach ($windows as [, $end]) {
            if ($end->isFuture()) {
                return false;
            }
        }

        return array_sum($counts) >= self::MINIMUM_OBSERVATIONS;
    }

    /* ------------------------------------------------------------------ */
    /* A - Borrowing demand                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Expected request volume for the next comparable period.
     *
     * @return array<string, mixed>
     */
    public function demand(
        AnalyticsService $analytics,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $windows = $this->historyWindows($from, $to);

        $counts = array_map(
            fn (array $window): int => $analytics
                ->requestScope($window[0], $window[1], $division, $unit)
                ->count('borrowing_requests.id'),
            $windows
        );

        $current = $analytics->requestScope($from, $to, $division, $unit)
            ->count('borrowing_requests.id');

        $previous = $counts[0] ?? 0;

        $readiness = $this->readinessFacts($windows, $counts);

        if (! $this->hasEnoughHistory($windows, $counts)) {
            return [
                'available' => false,
                'current' => $current,
                'previous' => $previous,
                'history' => $this->historyRows($windows, $counts),
                'readiness' => $readiness,
                'reason' => 'Not enough historical data to generate a reliable forecast.',
                'requirement' => 'Forecasts become available once '
                    .self::HISTORY_PERIODS.' completed periods with at least '
                    .self::MINIMUM_OBSERVATIONS.' borrowing requests have been recorded.',
            ];
        }

        $forecast = $this->round($this->weightedAverage($counts));

        return [
            'available' => true,
            'current' => $current,
            'previous' => $previous,
            'forecast' => $forecast,
            'history' => $this->historyRows($windows, $counts),
            'readiness' => $readiness,
            'direction' => $this->direction($forecast, $current),
            'change' => $forecast - $current,
            'summary' => $this->demandSentence($forecast, $current),
        ];
    }

    /**
     * The two conditions hasEnoughHistory() tests, stated so a reader can see
     * which one is holding a forecast back.
     *
     * Reporting only: the same windows and counts the check itself uses, so
     * this can never disagree with it, and nothing here is queried again.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @param  list<int>  $counts
     * @return array<string, mixed>
     */
    private function readinessFacts(array $windows, array $counts): array
    {
        $complete = count(array_filter(
            $windows,
            static fn (array $window): bool => ! $window[1]->isFuture()
        ));

        $observations = array_sum($counts);

        return [
            'periods_complete' => $complete,
            'periods_required' => self::HISTORY_PERIODS,
            'periods_met' => $complete >= self::HISTORY_PERIODS,
            'observations' => $observations,
            'observations_required' => self::MINIMUM_OBSERVATIONS,
            'observations_met' => $observations >= self::MINIMUM_OBSERVATIONS,
            'method' => 'Weighted Moving Average',
            'weights' => self::WEIGHTS,
        ];
    }

    /**
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    private function historyRows(array $windows, array $counts): array
    {
        $rows = [];

        foreach ($windows as $index => [$start, $end]) {
            $rows[] = [
                'label' => $start->format('d M').' – '.$end->format('d M Y'),
                'count' => $counts[$index] ?? 0,
                'weight' => self::WEIGHTS[$index] ?? 1,
            ];
        }

        return array_reverse($rows);
    }

    /** higher | lower | similar */
    public function direction(int $forecast, int $current): string
    {
        if ($current === 0) {
            return $forecast > 0 ? 'higher' : 'similar';
        }

        /* Within a tenth of the current volume is noise, not a movement. */
        $margin = max(1, (int) round($current * 0.1));

        return match (true) {
            $forecast > $current + $margin => 'higher',
            $forecast < $current - $margin => 'lower',
            default => 'similar',
        };
    }

    /**
     * "1 request" / "2 requests".
     *
     * Wording only. The count is passed straight through; nothing here
     * rounds, adjusts or reinterprets a forecast value.
     */
    private function requestCount(int $count): string
    {
        return $count.' '.($count === 1 ? 'request' : 'requests');
    }

    private function demandSentence(int $forecast, int $current): string
    {
        return match ($this->direction($forecast, $current)) {
            'higher' => 'Borrowing activity is expected to increase next period, from '
                .$current.' to about '.$this->requestCount($forecast).'.',
            'lower' => 'Borrowing activity is expected to ease next period, from '
                .$current.' to about '.$this->requestCount($forecast).'.',
            default => 'Borrowing activity is expected to stay close to the current '
                .$this->requestCount($current).'.',
        };
    }

    /* ------------------------------------------------------------------ */
    /* B - Division and unit demand                                        */
    /* ------------------------------------------------------------------ */

        /**
     * Expected volume per canonical division, scoped by the active Analytics
     * filters when one is selected.
     *
     * @return array<string, mixed>
     */
    public function divisionDemand(
        AnalyticsService $analytics,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $windows = $this->historyWindows($from, $to);
        $history = $this->groupedHistory($windows, 'request_versions.division_code', $division, $unit);

        if (! $this->hasEnoughHistory($windows, $this->periodTotals($history, count($windows)))) {
            return ['available' => false, 'groups' => []];
        }

        $groups = [];

        foreach (OrganizationalStructure::DIVISIONS as $code => $label) {
            if ($division !== null && $code !== $division) {
                continue;
            }

            $counts = $this->seriesFor($history, $code, count($windows));

            $groups[] = [
                'code' => $code,
                'label' => $label,
                'short_label' => OrganizationalStructure::shortLabel($code),
                'current' => $analytics->requestScope($from, $to, $code, $unit)
                    ->count('borrowing_requests.id'),
                'forecast' => $this->round($this->weightedAverage($counts)),
            ];
        }

        $leader = collect($groups)->sortByDesc('forecast')->first();
        $hasSignal = collect($groups)->sum('forecast') > 0;

        return [
            'available' => true,
            'groups' => $groups,
            'leader' => $hasSignal ? $leader : null,
            'summary' => $hasSignal && $leader
                ? $leader['label'].' units are expected to remain the primary borrowing group, with about '
                    .$this->requestCount($leader['forecast']).'.'
                : 'No division is expected to record borrowing activity next period.',
        ];
    }

        /**
     * The unit expected to borrow most, within the active Analytics scope.
     *
     * @return array<string, mixed>
     */
    public function unitDemand(
        AnalyticsService $analytics,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $windows = $this->historyWindows($from, $to);
        $history = $this->groupedHistory($windows, 'request_versions.office_unit', $division, $unit);

        if (! $this->hasEnoughHistory($windows, $this->periodTotals($history, count($windows)))) {
            return ['available' => false, 'reason' => 'Insufficient history'];
        }

        $units = [];

        foreach ($history as $unitName => $byPeriod) {
            $counts = $this->seriesFor($history, (string) $unitName, count($windows));

            if (array_sum($counts) < self::UNIT_MINIMUM_OBSERVATIONS) {
                continue;
            }

            $units[] = [
                'unit' => (string) $unitName,
                'observations' => array_sum($counts),
                'current' => $analytics->requestScope($from, $to, $division, (string) $unitName)
                    ->count('borrowing_requests.id'),
                'forecast' => $this->round($this->weightedAverage($counts)),
            ];
        }

        if ($units === []) {
            return ['available' => false, 'reason' => 'Insufficient history'];
        }

        usort($units, fn (array $a, array $b): int => $b['forecast'] <=> $a['forecast']);

        return [
            'available' => true,
            'units' => $units,
            'leader' => $units[0],
            'summary' => $units[0]['unit'].' is expected to borrow most next period, with about '
                .$this->requestCount($units[0]['forecast']).'.',
        ];
    }

        /**
     * Request counts per history period, grouped by one request-version column.
     * Filing follows submitted_at (created_at only for legacy rows) and uses
     * the same activity exclusions as AnalyticsService.
     *
     * @param  list<array{0: Carbon, 1: Carbon}>  $windows
     * @return array<string, array<int, int>>
     */
    private function groupedHistory(
        array $windows,
        string $column,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $history = [];
        $excluded = array_map(
            static fn ($status): string => $status->value,
            AnalyticsService::excludedFromActivity()
        );

        foreach ($windows as $index => [$start, $end]) {
            $rows = DB::table('borrowing_requests')
                ->join('request_versions', function ($join): void {
                    $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                        ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
                })
                ->where(function ($scope) use ($start, $end): void {
                    $scope->whereBetween('request_versions.submitted_at', [$start, $end])
                        ->orWhere(function ($legacy) use ($start, $end): void {
                            $legacy->whereNull('request_versions.submitted_at')
                                ->whereBetween('borrowing_requests.created_at', [$start, $end]);
                        });
                })
                ->whereNotIn('borrowing_requests.status', $excluded)
                ->when($division !== null, fn ($query) => $query->where('request_versions.division_code', $division))
                ->when($unit !== null, fn ($query) => $query->where('request_versions.office_unit', $unit))
                ->groupBy($column)
                ->select($column.' AS bucket')
                ->selectRaw('COUNT(borrowing_requests.id) AS total')
                ->get();

            foreach ($rows as $row) {
                if ($row->bucket === null || $row->bucket === '') {
                    continue;
                }

                $history[$row->bucket][$index] = (int) $row->total;
            }
        }

        return $history;
    }

    /**
     * @param  array<string, array<int, int>>  $history
     * @return list<int>
     */
    private function seriesFor(array $history, string $key, int $periods): array
    {
        $series = [];

        for ($index = 0; $index < $periods; $index++) {
            $series[] = (int) ($history[$key][$index] ?? 0);
        }

        return $series;
    }

    /**
     * @param  array<string, array<int, int>>  $history
     * @return list<int>
     */
    private function periodTotals(array $history, int $periods): array
    {
        $totals = array_fill(0, $periods, 0);

        foreach ($history as $byPeriod) {
            foreach ($byPeriod as $index => $count) {
                $totals[$index] = ($totals[$index] ?? 0) + (int) $count;
            }
        }

        return $totals;
    }

    /* ------------------------------------------------------------------ */
    /* C - Equipment demand against expected availability                  */
    /* ------------------------------------------------------------------ */

        /**
     * Expected demand per equipment type, compared with expected availability.
     * Historical demand uses the same filed-request event and activity scope as
     * Analytics, including the current Division / Office filters.
     *
     * @return array<string, mixed>
     */
    public function equipment(
        CarbonInterface $from,
        CarbonInterface $to,
        int $limit = self::EQUIPMENT_LIMIT,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $windows = $this->historyWindows($from, $to);
        [$forecastFrom, $forecastTo] = $this->forecastWindow($from, $to);
        $excluded = array_map(
            static fn ($status): string => $status->value,
            AnalyticsService::excludedFromActivity()
        );

        $history = [];
        $names = [];
        $periodTotals = array_fill(0, count($windows), 0);

        foreach ($windows as $index => [$start, $end]) {
            $base = DB::table('request_versions')
                ->join('borrowing_requests', function ($join): void {
                    $join->on('borrowing_requests.id', '=', 'request_versions.request_id')
                        ->on('borrowing_requests.current_version_no', '=', 'request_versions.version_no');
                })
                ->where(function ($scope) use ($start, $end): void {
                    $scope->whereBetween('request_versions.submitted_at', [$start, $end])
                        ->orWhere(function ($legacy) use ($start, $end): void {
                            $legacy->whereNull('request_versions.submitted_at')
                                ->whereBetween('borrowing_requests.created_at', [$start, $end]);
                        });
                })
                ->whereNotIn('borrowing_requests.status', $excluded)
                ->when($division !== null, fn ($query) => $query->where('request_versions.division_code', $division))
                ->when($unit !== null, fn ($query) => $query->where('request_versions.office_unit', $unit));

            $periodTotals[$index] = (int) (clone $base)
                ->distinct()
                ->count('borrowing_requests.id');

            $rows = (clone $base)
                ->join('request_items', 'request_items.request_version_id', '=', 'request_versions.id')
                ->groupBy('request_items.inventory_item_id', 'request_items.description_snapshot')
                ->select(
                    'request_items.inventory_item_id AS item_id',
                    'request_items.description_snapshot AS name'
                )
                ->selectRaw('SUM(request_items.requested_quantity) AS quantity')
                ->get();

            foreach ($rows as $row) {
                $history[$row->item_id][$index] = (float) $row->quantity;
                $names[$row->item_id] = $row->name;
            }
        }

        if (! $this->hasEnoughHistory($windows, $periodTotals)) {
            return [
                'available' => false,
                'items' => [],
                'reason' => 'Not enough historical data to forecast equipment demand.',
            ];
        }

        $forecasts = [];

        foreach ($history as $itemId => $byPeriod) {
            $demand = $this->round(
                $this->weightedAverage($this->seriesFor($history, (string) $itemId, count($windows)))
            );

            if ($demand > 0) {
                $forecasts[$itemId] = $demand;
            }
        }

        if ($forecasts === []) {
            return [
                'available' => true,
                'items' => [],
                'window' => [$forecastFrom, $forecastTo],
                'summary' => 'No equipment demand is expected next period.',
            ];
        }

        arsort($forecasts);
        $forecasts = array_slice($forecasts, 0, $limit, true);

        $items = InventoryItem::query()
            ->whereIn('id', array_keys($forecasts))
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($forecasts as $itemId => $demand) {
            $item = $items->get($itemId);

            if (! $item) {
                continue;
            }

            $balance = $this->inventory->availability($item, $forecastFrom, $forecastTo);
            $expected = (float) ($balance['available'] ?? 0);
            $shortage = max(0.0, $demand - $expected);

            $rows[] = [
                'item_id' => $itemId,
                'name' => $names[$itemId] ?? $item->unique_description,
                'demand' => $demand,
                'expected_available' => $expected + 0,
                'shortage' => $shortage + 0,
                'laundry_held' => (float) ($balance['laundry'] ?? 0) + 0,
                'laundry_required' => (bool) $item->laundry_required,
                'status' => $this->availabilityStatus($demand, $expected),
            ];
        }

        $atRisk = array_values(array_filter(
            $rows,
            fn (array $row): bool => $row['status'] !== 'Sufficient'
        ));

        return [
            'available' => true,
            'items' => $rows,
            'at_risk' => $atRisk,
            'at_risk_count' => count($atRisk),
            'window' => [$forecastFrom, $forecastTo],
            'summary' => $atRisk === []
                ? 'Expected availability covers the forecast demand for every equipment type.'
                : count($atRisk).' equipment '.(count($atRisk) === 1 ? 'type' : 'types')
                    .' may not fully cover expected demand next period.',
        ];
    }

    /**
     * Sufficient | Limited | Possible Shortage.
     *
     * A small shortfall is called Limited so the SPMU Head can plan; only a
     * gap wider than LIMITED_TOLERANCE of demand is escalated.
     */
    public function availabilityStatus(float $demand, float $expectedAvailable): string
    {
        if ($demand <= 0 || $expectedAvailable >= $demand) {
            return 'Sufficient';
        }

        $shortage = $demand - $expectedAvailable;

        return $shortage <= $demand * self::LIMITED_TOLERANCE
            ? 'Limited'
            : 'Possible Shortage';
    }

    /* ------------------------------------------------------------------ */
    /* D - Expected busy period                                            */
    /* ------------------------------------------------------------------ */

        /**
     * How forecast volume is expected to fall across the next period.
     * Historical shape follows filed-request timestamps and the active filters.
     *
     * @return array<string, mixed>
     */
    public function busyPeriod(
        AnalyticsService $analytics,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division = null,
        ?string $unit = null
    ): array {
        $demand = $this->demand($analytics, $from, $to, $division, $unit);

        if (! ($demand['available'] ?? false)) {
            return ['available' => false];
        }

        $windows = $this->historyWindows($from, $to);
        $days = $this->lengthInDays($from, $to);
        $sliceDays = $days > 10 ? 7 : 1;
        $slices = (int) ceil($days / $sliceDays);

        if ($slices < 2) {
            return ['available' => false];
        }

        $shape = array_fill(0, $slices, 0.0);

        foreach ($windows as [$start, $end]) {
            $rows = $analytics->requestScope($start, $end, $division, $unit)
                ->selectRaw('COALESCE(request_versions.submitted_at, borrowing_requests.created_at) AS filed_at')
                ->get();

            foreach ($rows as $row) {
                $offset = Carbon::parse($start)->startOfDay()
                    ->diffInDays(Carbon::parse($row->filed_at)->startOfDay());

                $slice = min($slices - 1, max(0, (int) floor($offset / $sliceDays)));
                $shape[$slice]++;
            }
        }

        $shapeTotal = array_sum($shape);

        if ($shapeTotal <= 0) {
            return ['available' => false];
        }

        [$forecastFrom] = $this->forecastWindow($from, $to);
        $buckets = [];

        foreach ($shape as $index => $observed) {
            $start = $forecastFrom->copy()->addDays($index * $sliceDays);
            $end = $start->copy()->addDays($sliceDays - 1);

            $buckets[] = [
                'label' => $sliceDays === 7 ? 'Week '.($index + 1) : $start->format('D d M'),
                'range' => $start->format('d M').' – '.$end->format('d M'),
                'expected' => $this->round($demand['forecast'] * ($observed / $shapeTotal)),
            ];
        }

        $values = array_column($buckets, 'expected');
        $mean = count($values) > 0 ? array_sum($values) / count($values) : 0.0;
        $peak = max($values);

        foreach ($buckets as $index => $bucket) {
            $buckets[$index]['level'] = $this->busyLevel($bucket['expected'], $mean, $peak);
        }

        $busiest = $buckets[array_search($peak, $values, true)] ?? null;

        return [
            'available' => true,
            'buckets' => $buckets,
            'busiest' => $peak > 0 ? $busiest : null,
            'summary' => $peak > 0 && $busiest
                ? 'Higher borrowing activity is expected during '.$busiest['label'].' ('.$busiest['range'].').'
                : 'Borrowing activity is expected to stay even across the next period.',
        ];
    }

    /** Normal | Moderate | High */
    public function busyLevel(int $expected, float $mean, int $peak): string
    {
        if ($peak <= 0 || $mean <= 0) {
            return 'Normal';
        }

        return match (true) {
            $expected >= $peak && $expected > $mean => 'High',
            $expected >= $mean => 'Moderate',
            default => 'Normal',
        };
    }

    /* ------------------------------------------------------------------ */
    /* Transparency                                                        */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function basis(): array
    {
        return [
            'summary' => 'Forecasts are based on borrowing activity in the '
                .self::HISTORY_PERIODS.' periods before the one selected, together with '
                .'current reservations, equipment still out on custody, expected returns '
                .'and inventory availability.',
            'details' => [
                'The '.self::HISTORY_PERIODS.' periods before the selected one are counted, each the same length as the selected period.',
                'A weighted average is applied so the most recent period counts most: weights '
                    .implode(', ', self::WEIGHTS).'.',
                'A forecast is only shown once those periods are complete and contain at least '
                    .self::MINIMUM_OBSERVATIONS.' borrowing requests in total.',
                'Results are rounded to whole requests or units and never fall below zero.',
                'Expected equipment availability comes from the Inventory module and already excludes reserved units, equipment still out, linen under Laundry Operations, and units held by an incident.',
            ],
        ];
    }
}
