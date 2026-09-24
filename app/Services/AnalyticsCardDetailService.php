<?php

namespace App\Services;

use App\Support\AnalyticsDrilldown;
use App\Support\PeriodComparison;
use Carbon\CarbonInterface;

/**
 * Card-level Analytics details.
 *
 * AnalyticsDetailService answers questions about a single figure that has
 * record-level backing - one unit, one item, one bucket. A card is a coarser
 * question: "what is this panel telling me?" Some panels have no record list
 * behind them at all, only a reading and the rule that produced it, and those
 * still deserve an answer rather than a dead click.
 *
 * Every descriptor here re-reads the same service method the card itself was
 * rendered from, so a drawer can never disagree with the card above it, and
 * nothing is computed that the services do not already compute. A key that is
 * not recognised still returns a valid detail, so a card can never open an
 * empty panel.
 */
class AnalyticsCardDetailService
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly InventoryService $inventory,
        private readonly ForecastService $forecasts,
    ) {}

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    public function resolve(array $scope, string $key): array
    {
        return match ($key) {
            /* Overview */
            'overview.trend' => $this->trend($scope, 'Borrowing Activity Trend'),
            'overview.insights' => $this->overviewInsights($scope),
            'overview.return-compliance' => $this->returnCompliance($scope),
            'overview.released' => $this->releasedItems($scope, 'Top Released Items'),
            'overview.units' => $this->unitRankings($scope),
            'overview.snapshot' => $this->snapshot($scope),

            /* Demand & Utilization */
            'demand.requested-quantity' => $this->requestedQuantity($scope),
            'demand.released-quantity' => $this->releasedQuantity($scope),
            'demand.active-units' => $this->activeUnits($scope),
            'demand.trend' => $this->trend($scope, 'Borrowing Demand Trend'),
            'demand.division' => $this->divisionDemand($scope),
            'demand.requested-items' => $this->requestedItems($scope),
            'demand.units' => $this->unitRankings($scope),
            'demand.released-items' => $this->releasedItems($scope, 'Top Released Items'),
            'demand.low-usage' => $this->lowUsage($scope),
            'demand.peak' => $this->peak($scope),

            /* Inventory Health */
            'inventory.available' => $this->inventoryState($scope, 'available'),
            'inventory.reserved' => $this->inventoryState($scope, 'reserved'),
            'inventory.custody' => $this->inventoryState($scope, 'custody'),
            'inventory.attention' => $this->inventoryState($scope, 'attention'),
            'inventory.availability' => $this->inventoryDistribution($scope, 'Inventory Availability'),
            'inventory.distribution' => $this->inventoryDistribution($scope, 'Current Inventory Distribution'),
            'inventory.low-availability' => $this->lowAvailability($scope),
            'inventory.utilization' => $this->releasedItems($scope, 'Utilization'),
            'inventory.operational' => $this->operationalInventory($scope),
            'inventory.coverage' => $this->coverage($scope),

            /* Borrowing & Return Performance */
            'returns.trend' => $this->returnTrend($scope),
            'returns.outcome' => $this->returnOutcome($scope),
            'returns.lifecycle' => $this->lifecycle($scope),
            'returns.followup' => $this->overdueFollowUp($scope),
            'returns.condition' => $this->returnCondition($scope),
            'returns.summary' => $this->returnSummary($scope),
            'returns.issues' => $this->returnIssues($scope),

            /* Forecast & Planning */
            'forecast.readiness' => $this->readiness($scope),
            'forecast.scheduled' => $this->scheduled($scope),
            'forecast.outlook' => $this->outlook($scope),
            'forecast.division' => $this->forecastDivision($scope),
            'forecast.unit' => $this->forecastUnit($scope),
            'forecast.equipment' => $this->forecastEquipment($scope),
            'forecast.busy' => $this->busyPeriod($scope),
            'forecast.notes' => $this->planningNotes($scope),
            'forecast.methodology' => $this->methodology($scope),

            default => $this->unknown($key),
        };
    }

    /* ------------------------------------------------------------------ */
    /* Shared shapes                                                       */
    /* ------------------------------------------------------------------ */

    /** A period-scoped reading states its window; a current one states today. */
    private function periodNote(array $scope): string
    {
        return 'Period-scoped: measured over '
            .$scope['from']->format('d M Y').' to '.$scope['to']->format('d M Y')
            .', narrowed by the borrower group and unit selected above.';
    }

    private function currentNote(): string
    {
        return 'Current state: measured as of today, not limited to the selected '
            .'reporting period, and narrowed by the borrower group and unit selected above.';
    }

    private function inventoryCurrentNote(): string
    {
        return 'Institution-wide current inventory snapshot: measured as of today. '
            .'Reporting Period, Division, Office / College / Unit, and Borrower filters do not change physical stock totals.';
    }

    /** The previous period's dates as the panel prints them. */
    private function previousWindowLabel(array $scope): string
    {
        [$from, $to] = $this->analytics->previousWindow($scope['from'], $scope['to']);

        return $from->format('d M Y').' – '.$to->format('d M Y');
    }

    /**
     * Bar width for a detail row, as a share of the largest value in the
     * rows being drawn.
     *
     * Visualisation geometry only: it exists so a bar's width is proportional
     * to the figure printed beside it, and it is never a business metric of
     * its own. A set with no positive value draws no width rather than
     * dividing by zero.
     */
    private function width(float $value, float $highest): int
    {
        if ($highest <= 0 || $value <= 0) {
            return 0;
        }

        return (int) round(min($value, $highest) / $highest * 100);
    }

    /** @return array<string, mixed> */
    private function unknown(string $key): array
    {
        return [
            'title' => 'Detail unavailable',
            'value' => null,
            'context' => 'Analytics detail',
            'empty' => 'This card does not offer a detailed breakdown yet.',
            'note' => 'Requested detail key: '.$key,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Overview                                                            */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function trend(array $scope, string $title): array
    {
        $trend = $this->analytics->trend(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period'], $scope['borrower'] ?? null
        );

        $total = array_sum(array_column($trend['points'], 'count'));

        return [
            'title' => $title,
            'value' => $total,
            'value_label' => $total === 1 ? 'request filed' : 'requests filed',
            'context' => 'Requests filed per '.$trend['granularity'],
            'note' => $this->periodNote($scope)
                .' Each bucket counts requests by the date they were filed, not the quantity '
                .'of items released. Grouping follows the selected reporting period.',
            'bars' => $trend['points'] === [] ? null : array_map(
                static fn (array $point): array => [
                    'label' => $point['label'],
                    'value' => (string) $point['count'],
                    'share' => $point['share'],
                ],
                $trend['points']
            ),
            'empty' => $total === 0 ? 'No borrowing requests were filed during this period.' : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function overviewInsights(array $scope): array
    {
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $low = $this->analytics->lowAvailability($this->inventory);
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $units = $this->analytics->unitRankings(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        $equipment = $this->analytics->equipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 1, $scope['borrower'] ?? null
        );

        $rankedUnits = collect($units['columns'] ?? [])
            ->flatMap(fn (array $column): array => collect($column['units'] ?? [])
                ->map(fn (array $row): array => $row + [
                    'division_label' => $column['label'] ?? '',
                ])
                ->all())
            ->sortByDesc('count')
            ->values();

        $leadUnit = $rankedUnits->first();
        $leadItem = $equipment['items'][0] ?? null;
        $signals = [];

        if (($overview['needs_follow_up'] ?? 0) > 0) {
            $signals[] = [
                'weight' => 10,
                'label' => 'Currently overdue',
                'value' => (string) $overview['needs_follow_up'],
            ];
        }

        if (($low['count'] ?? 0) > 0) {
            $signals[] = [
                'weight' => 20,
                'label' => 'Low availability',
                'value' => $low['count'].' item '.($low['count'] === 1 ? 'type' : 'types'),
            ];
        }

        if ($leadUnit) {
            $signals[] = [
                'weight' => 30,
                'label' => 'Top borrowing unit',
                'value' => $leadUnit['name'].' · '.$leadUnit['count'].' '
                    .($leadUnit['count'] === 1 ? 'request' : 'requests'),
            ];
        }

        if ($leadItem) {
            $signals[] = [
                'weight' => 35,
                'label' => 'Top released item',
                'value' => $leadItem['name'].' · '.($leadItem['released'] + 0).' '.$leadItem['unit'],
            ];
        }

        if (($low['count'] ?? 0) === 0) {
            $signals[] = [
                'weight' => 36,
                'label' => 'Inventory availability',
                'value' => 'No item below threshold',
            ];
        }

        $signals[] = [
            'weight' => 40,
            'label' => 'Return compliance',
            'value' => $returns['on_time_rate'] === null
                ? 'Not measurable'
                : $returns['on_time'].' of '.$returns['completed'].' on time · '.$returns['on_time_rate'].'%',
        ];

        usort($signals, static fn (array $a, array $b): int => $a['weight'] <=> $b['weight']);

        return [
            'title' => 'Priority Insights',
            'value' => null,
            'context' => 'Key operational signals',
            'note' => null,
            'stats' => array_map(
                static fn (array $signal): array => [
                    'label' => $signal['label'],
                    'value' => $signal['value'],
                ],
                array_slice($signals, 0, 4)
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function returnCompliance(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        $completed = (int) $returns['completed'];

        return [
            'title' => 'Return Compliance',
            'value' => $returns['on_time_rate'] === null ? null : $returns['on_time_rate'].'%',
            'value_label' => $returns['on_time_rate'] === null ? null : 'on-time return rate',
            'context' => 'Completed returns in this reporting period',
            'note' => null,
            'stats' => [
                ['label' => 'Completed returns', 'value' => $completed],
                ['label' => 'Returned on time', 'value' => (int) $returns['on_time']],
                ['label' => 'Returned late', 'value' => (int) $returns['late']],
            ],
            /* The rate itself, in percentage points against the previous period. */
            'comparison' => PeriodComparison::block(
                $this->analytics->returnComparison(
                    $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
                )['on_time_rate'],
                $this->previousWindowLabel($scope)
            ),
            'empty' => $completed === 0
                ? 'No completed returns in this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'COMPLETED']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function releasedItems(array $scope, string $title = 'Top Released Items'): array
    {
        $equipment = $this->analytics->equipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
        );

        return [
            'title' => $title,
            'value' => count($equipment['items']),
            'value_label' => 'items released',
            'context' => 'Ranked by quantity physically released',
            'note' => $this->periodNote($scope)
                .' This counts equipment that physically left custody, summed from released '
                .'quantities. How often an item was asked for is a separate measure, reported '
                .'as requested demand.',
            'bars' => $equipment['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => ($row['released'] + 0).' '.$row['unit'],
                    'share' => $row['share'],
                ],
                $equipment['items']
            ),
            'empty' => $equipment['items'] === []
                ? 'No equipment was physically released during this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::utilization(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function requestedItems(array $scope): array
    {
        $requested = $this->analytics->requestedEquipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
        );

        /*
         * The same reading as the Most Requested Items card: ranked by how
         * many requests contained the item, with the requested quantity
         * stated beside it as the secondary figure. `share` here is the
         * service's request-count share, so the bar measures the ranking
         * and never the quantity.
         */
        return [
            'title' => 'Most Requested Items',
            'value' => count($requested['items']),
            'value_label' => 'items requested',
            'context' => 'Ranked by number of requests',
            'note' => $this->periodNote($scope)
                .' A request that was never approved or released still expresses demand, so it '
                .'is counted here. Physical usage is the separate released measure.',
            'bars' => $requested['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => $row['requests'].' '.($row['requests'] === 1 ? 'request' : 'requests')
                        .' · Requested quantity: '.(($row['quantity'] ?? 0) + 0),
                    'share' => $row['share'] ?? 0,
                ],
                $requested['items']
            ),
            'empty' => $requested['items'] === []
                ? 'No equipment was requested during this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function unitRankings(array $scope): array
    {
        $units = $this->analytics->unitRankings(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $bars = [];

        foreach ($units['columns'] as $column) {
            foreach ($column['units'] as $row) {
                $bars[] = [
                    'label' => $row['name'].' · '.$column['label'],
                    'value' => (string) $row['count'],
                    'share' => $row['share'],
                    'sort' => $row['count'],
                ];
            }
        }

        usort($bars, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        return [
            'title' => 'Top Borrowing Units',
            'value' => count($bars),
            'value_label' => count($bars) === 1 ? 'unit with activity' : 'units with activity',
            'context' => 'Ranked by borrowing requests filed',
            'note' => $this->periodNote($scope)
                .' A unit is counted from the version of the request that is current, so a '
                .'revised request is attributed to the unit it now belongs to.',
            'bars' => $bars === [] ? null : array_map(
                static fn (array $bar): array => [
                    'label' => $bar['label'],
                    'value' => $bar['value'],
                    'share' => $bar['share'],
                ],
                $bars
            ),
            'empty' => $bars === [] ? 'No borrowing unit activity was recorded for this period.' : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(array $scope): array
    {
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        return [
            'title' => 'Reporting Period Snapshot',
            'value' => null,
            'context' => 'Outcomes for borrowings filed in this period',
            'note' => $this->periodNote($scope)
                .' Each figure follows the borrowings filed inside the window, so it describes '
                .'what this period produced rather than what is true right now.',
            'stats' => [
                ['label' => 'Approved requests', 'value' => $overview['approved']],
                ['label' => 'Completed returns', 'value' => $returns['completed']],
                ['label' => 'Returned on time', 'value' => $returns['on_time']],
                ['label' => 'Returned late', 'value' => $returns['late']],
                ['label' => 'Open accountability', 'value' => $returns['open_cases']],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Demand & Utilization                                                */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function requestedQuantity(array $scope): array
    {
        $requested = $this->analytics->requestedEquipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
        );

        /*
         * The headline figure comes from the same demandTotals() aggregate the
         * KPI card itself reads, so the detail can never disagree with the
         * number the reader just clicked. requestedEquipment() is only the
         * top-10 breakdown behind the bars below.
         */
        $totals = $this->analytics->demandTotals(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $total = (float) ($totals['requested_quantity'] ?? 0);

        /*
         * This detail is about quantity, so its bars are drawn against the
         * largest requested quantity in the rows shown - never the service's
         * `share`, which is the request-count ranking behind Most Requested
         * Items and would make a bar disagree with the number printed on it.
         * The rows are the ten most frequently requested items, listed here
         * by quantity so the bars read in order; the request count stays a
         * secondary field.
         */
        $rows = collect($requested['items'])
            ->sortByDesc(fn (array $row): float => (float) ($row['quantity'] ?? 0))
            ->values();

        $highest = (float) ($rows->max(fn (array $row): float => (float) ($row['quantity'] ?? 0)) ?: 0);

        /* Filing-date scope in both windows, same filters; only the dates move. */
        $comparison = $this->analytics->demandComparison(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        return [
            'title' => 'Requested Quantity',
            'value' => $total + 0,
            'value_label' => 'units requested',
            'context' => 'What borrowers asked for',
            'note' => $this->periodNote($scope)
                .' Summed from request items, including requests that were never approved or '
                .'released, because an unmet request is still demand. Released quantity is the '
                .'separate measure of what physically went out.',
            'comparison' => PeriodComparison::block(
                $comparison['requested_quantity'], $this->previousWindowLabel($scope), 'units'
            ),
            'bars' => $rows->isEmpty() ? null : $rows->map(
                fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => (($row['quantity'] ?? 0) + 0).' '.($row['unit'] ?? '')
                        .' · '.$row['requests'].' '.($row['requests'] === 1 ? 'request' : 'requests'),
                    'share' => $this->width((float) ($row['quantity'] ?? 0), $highest),
                ]
            )->all(),
            'bars_title' => 'Requested quantity by item (ten most frequently requested)',
            'empty' => $total <= 0 ? 'No equipment was requested during this period.' : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function releasedQuantity(array $scope): array
    {
        $equipment = $this->analytics->equipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10, $scope['borrower'] ?? null
        );

        $totals = $this->analytics->demandTotals(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $total = (float) ($totals['released_quantity'] ?? 0);

        /* released_at in both windows - never the filing date. */
        $comparison = $this->analytics->demandComparison(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        return [
            'title' => 'Released Quantity',
            'value' => $total + 0,
            'value_label' => 'units released',
            'context' => 'What physically left custody',
            'note' => $this->periodNote($scope)
                .' Summed from actual released quantities on custody lines, so it measures real '
                .'asset usage rather than demand.',
            'comparison' => PeriodComparison::block(
                $comparison['released_quantity'], $this->previousWindowLabel($scope), 'units'
            ),
            'bars' => $equipment['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => ($row['released'] + 0).' '.$row['unit'],
                    'share' => $row['share'],
                ],
                $equipment['items']
            ),
            'empty' => $total <= 0 ? 'No equipment was physically released during this period.' : null,
            'reports_url' => AnalyticsDrilldown::utilization(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function activeUnits(array $scope): array
    {
        $detail = $this->unitRankings($scope);

        return array_merge($detail, [
            'title' => 'Active Borrowing Units',
            'context' => 'Units that filed at least one request',
        ]);
    }

    /** @return array<string, mixed> */
    private function divisionDemand(array $scope): array
    {
        $groups = $this->analytics->borrowerGroups(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Demand by Division',
            'value' => $groups['total'],
            'value_label' => $groups['total'] === 1 ? 'request filed' : 'requests filed',
            'context' => 'Share of filed borrowing demand',
            'note' => $this->periodNote($scope)
                .' A division with no activity is left out of the comparison rather than shown '
                .'as a zero share.',
            'bars' => $groups['groups']->isEmpty() ? null : $groups['groups']->map(
                static fn (array $group): array => [
                    'label' => $group['label'],
                    'value' => $group['count'].' · '.$group['percentage'].'%',
                    'share' => $group['percentage'],
                ]
            )->all(),
            'empty' => $groups['total'] === 0
                ? 'No borrowing requests were filed during this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function lowUsage(array $scope): array
    {
        $slow = $this->analytics->slowMovingItems(
            $scope['from'], $scope['to'], 10, $scope['division'], $scope['unit']
        );
        $rows = $slow['items'] ?? [];

        /*
         * slowMovingItems() ranks by released quantity ascending and carries
         * no share of its own. When something in the list did move, a bar is
         * drawn against the largest released quantity shown; when nothing
         * did, every bar would be a fabricated zero, so the rows are listed
         * plainly instead and the state says so.
         */
        $highest = (float) (collect($rows)->max(fn (array $row): float => (float) ($row['released'] ?? 0)) ?: 0);
        $anyMoved = $highest > 0;

        return [
            'title' => 'Low / No Usage Items',
            'value' => count($rows),
            'value_label' => 'items listed',
            'context' => 'Equipment that moved least',
            'note' => $this->periodNote($scope)
                .' The list starts from the catalogue rather than from custody records, so an '
                .'item that never moved still appears - which is the point of the question.',
            'stats' => $rows === [] ? [] : [
                ['label' => 'Items with no release', 'value' => (int) ($slow['never_moved'] ?? 0)],
                ['label' => 'Borrowable items in catalogue', 'value' => (int) ($slow['catalogue'] ?? 0)],
            ],
            'bars' => ! $anyMoved ? null : array_map(
                fn (array $row): array => [
                    'label' => (string) ($row['name'] ?? ''),
                    'value' => (($row['released'] ?? 0) + 0).' released · '
                        .(int) ($row['transactions'] ?? 0).' '
                        .((int) ($row['transactions'] ?? 0) === 1 ? 'release' : 'releases'),
                    'share' => $this->width((float) ($row['released'] ?? 0), $highest),
                ],
                $rows
            ),
            'bars_title' => $anyMoved ? 'Released quantity, least first' : null,
            'table' => ($rows === [] || $anyMoved) ? null : [
                'columns' => ['Item', 'Released', 'Release transactions'],
                'rows' => array_map(
                    static fn (array $row): array => [
                        (string) ($row['name'] ?? ''),
                        'No release',
                        (string) (int) ($row['transactions'] ?? 0),
                    ],
                    $rows
                ),
            ],
            'empty' => $rows === []
                ? 'No catalogue items are available to rank yet.'
                : ($anyMoved ? null : 'None of the listed items was released during this period.'),
            'reports_url' => AnalyticsDrilldown::utilization(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function peak(array $scope): array
    {
        $peak = $this->analytics->peakBorrowing(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        /*
         * peakBorrowing() decides availability and names the peak itself:
         * `peak_day` / `peak_hour` are null below the minimum, and `total` is
         * the number of filed requests the reading was drawn from. The detail
         * reads those keys and nothing else, so it can never show a peak the
         * card withheld or a zero the service did not measure.
         */
        $available = (bool) ($peak['available'] ?? false);
        $peakDay = $available ? ($peak['peak_day'] ?? null) : null;
        $peakHour = $available ? ($peak['peak_hour'] ?? null) : null;
        $observed = (int) ($peak['total'] ?? 0);
        $required = (int) ($peak['requirement'] ?? AnalyticsService::PEAK_MINIMUM_OBSERVATIONS);

        $stats = [
            ['label' => 'Requests observed', 'value' => $observed],
            ['label' => 'Minimum required', 'value' => $required],
        ];

        if ($peakDay !== null) {
            $stats[] = ['label' => 'Busiest weekday', 'value' => $peakDay];
        }

        if ($peakHour !== null) {
            $stats[] = ['label' => 'Busiest hour', 'value' => $peakHour];
        }

        /* The weekday spread the card draws, so the detail shows the same counts. */
        $days = $available ? collect($peak['days'] ?? []) : collect();
        $busiest = (float) ($days->max('count') ?: 0);

        return [
            'title' => 'Peak Borrowing Periods',
            'value' => $peakDay,
            'value_label' => $peakDay !== null ? 'busiest weekday' : null,
            'context' => 'When borrowing concentrates',
            'note' => 'Peak analysis needs a minimum number of recorded requests before a busiest '
                .'period can be distinguished from ordinary variation. Below that threshold the '
                .'reading is withheld rather than estimated from too little data. '
                .$this->periodNote($scope),
            'stats' => $stats,
            'bars' => $days->isEmpty() ? null : $days->map(
                fn (array $day): array => [
                    'label' => (string) $day['label'],
                    'value' => (string) (int) $day['count'],
                    'share' => $this->width((float) $day['count'], $busiest),
                ]
            )->all(),
            'bars_title' => $days->isEmpty() ? null : 'Requests filed by weekday',
            'empty' => $available ? null : ($peak['summary'] ?? 'Not enough activity to determine a reliable peak.'),
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Inventory Health                                                    */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function inventoryState(array $scope, string $facet): array
    {
        $inventory = $this->analytics->inventory($this->inventory);

        /*
         * inventory() nests its figures under 'totals' and has no 'attention'
         * key of its own - the KPI cards derive it from maintenance + problem
         * (inventory-health.blade.php). Reading straight off $inventory here
         * used to miss both, silently falling through to the ?? 0 default and
         * showing every facet as zero regardless of the true stock position.
         */
        $totals = $inventory['totals'] ?? [];
        $available = (float) ($totals['available'] ?? 0);
        $allocated = (float) ($totals['allocated'] ?? 0);
        $onCustody = (float) ($totals['on_custody'] ?? 0);
        $attention = (float) ($totals['attention'] ?? $totals['problem'] ?? 0);

        [$title, $value, $context] = match ($facet) {
            'available' => ['Available Units', $available, 'Usable and free to allocate'],
            'reserved' => ['Reserved / Allocated', $allocated, 'Committed to an approved request'],
            'custody' => ['On Custody', $onCustody, 'Released and not yet returned'],
            default => ['Attention Needed', $attention, 'Held out of circulation'],
        };

        return [
            'title' => $title,
            'value' => $value,
            'value_label' => 'units',
            'context' => $context,
            'note' => $this->inventoryCurrentNote()
                .' Inventory figures describe stock as it stands now, so they do not move when '
                .'the reporting period changes.',
            'stats' => [
                ['label' => 'Available', 'value' => $available],
                ['label' => 'Reserved / allocated', 'value' => $allocated],
                ['label' => 'On custody', 'value' => $onCustody],
                ['label' => 'Attention needed', 'value' => $attention],
            ],
            'reports_url' => match ($facet) {
                'available' => AnalyticsDrilldown::inventory(
                    $scope['period'], ['availability_status' => 'AVAILABLE']
                ),
                'reserved' => AnalyticsDrilldown::inventory(
                    $scope['period'], ['availability_status' => 'ALLOCATED']
                ),
                'custody' => AnalyticsDrilldown::inventory(
                    $scope['period'], ['availability_status' => 'ON_CUSTODY']
                ),
                default => null,
            },
        ];
    }

    /** @return array<string, mixed> */
    private function inventoryDistribution(array $scope, string $title = 'Current Inventory Distribution'): array
    {
        $detail = $this->inventoryState($scope, 'available');

        return array_merge($detail, [
            'title' => $title,
            'value' => null,
            'context' => 'How stock is split across states',
            'note' => $this->inventoryCurrentNote()
                .' Every unit sits in exactly one state, so the states sum to the serviceable total.',
            'reports_url' => AnalyticsDrilldown::inventory($scope['period']),
        ]);
    }

    /** @return array<string, mixed> */
    private function lowAvailability(array $scope): array
    {
        $low = $this->analytics->lowAvailability($this->inventory, 10);

        return [
            'title' => 'Low Availability Watch',
            'value' => $low['count'],
            'value_label' => $low['count'] === 1 ? 'item type' : 'item types',
            'context' => 'At or below '.(int) ($low['threshold'] * 100).'% usable stock',
            'note' => $this->inventoryCurrentNote()
                .' An item is listed when its usable stock falls to or below '
                .(int) ($low['threshold'] * 100).'% of its serviceable total. Units out on '
                .'custody, in laundry or held by an incident are excluded from what counts as usable.',
            'bars' => $low['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => ($row['available'] + 0).' of '.($row['stock'] + 0).' available',
                    'share' => $row['share'],
                ],
                $low['items']
            ),
            'empty' => $low['count'] === 0
                ? 'No item type is currently below the availability threshold.'
                : null,
            'reports_url' => AnalyticsDrilldown::inventory(
                $scope['period'], ['availability_status' => 'LOW_AVAILABILITY']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function operationalInventory(array $scope): array
    {
        $inventory = $this->analytics->inventory($this->inventory);
        $totals = $inventory['totals'] ?? [];

        return [
            'title' => 'Operational Inventory Status',
            'value' => null,
            'context' => 'Stock held outside normal allocation',
            'note' => $this->inventoryCurrentNote()
                .' Laundry is shown separately from condition/incident follow-up. '
                .' Breakdown values describe the same current inventory snapshot as the KPI cards.',
            'stats' => [
                ['label' => 'In laundry', 'value' => $totals['laundry'] ?? 0],
                ['label' => 'Maintenance', 'value' => $totals['maintenance'] ?? 0],
                ['label' => 'Held by incident', 'value' => $totals['incident'] ?? 0],
                ['label' => 'Attention needed', 'value' => $totals['attention'] ?? $totals['problem'] ?? 0],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function coverage(array $scope): array
    {
        $coverage = $this->analytics->stockCoverage(
            $this->inventory, $scope['from'], $scope['to'], 10, $scope['division'], $scope['unit']
        );

        $rows = $coverage['items'] ?? [];

        return [
            'title' => 'Stock Coverage & Risk',
            'value' => count($rows),
            'value_label' => 'items assessed',
            'context' => 'How long current stock lasts at observed demand',
            'note' => 'Coverage divides usable stock by the demand rate observed in the selected '
                .'window. It is only calculated for an item with enough recorded movement over a '
                .'long enough window; below that it is withheld rather than projected from a '
                .'handful of releases. '.$this->periodNote($scope),
            'stats' => [
                ['label' => 'Minimum window (days)', 'value' => AnalyticsService::STOCK_COVERAGE_MINIMUM_WINDOW_DAYS],
                ['label' => 'Minimum releases', 'value' => AnalyticsService::STOCK_COVERAGE_MINIMUM_RELEASES],
            ],
            'empty' => $rows === []
                ? ($coverage['summary'] ?? 'Not enough recorded movement to calculate stock coverage yet.')
                : null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Borrowing & Return Performance                                      */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function returnTrend(array $scope): array
    {
        $trend = $this->analytics->returnTrend(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period'], $scope['borrower'] ?? null
        );

        $stats = [];

        foreach ($trend['points'] as $point) {
            $stats[] = [
                'label' => $point['label'],
                'value' => $point['on_time'].' on time · '.$point['late'].' late',
            ];
        }

        return [
            'title' => 'Return Performance Trend',
            'value' => $trend['plotted'],
            'value_label' => 'completed returns plotted',
            'context' => 'Completed returns per '.$trend['granularity'],
            'note' => $this->periodNote($scope)
                .' Both series are finished borrowings: one came back on or before its due date, '
                .'the other after it. Equipment that is still out is overdue, is never plotted '
                .'here, and is never added to either series.',
            'stats' => $stats === [] ? [] : $stats,
            'empty' => $trend['plotted'] === 0
                ? 'No completed returns during this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'COMPLETED']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function returnOutcome(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        $completed = $returns['completed'];

        return [
            'title' => 'Return Outcome',
            'value' => $completed,
            'value_label' => $completed === 1 ? 'completed return' : 'completed returns',
            'context' => 'Composition of finished returns',
            'note' => $this->periodNote($scope)
                .' Only completed returns have an outcome. A borrowing that is still out has not '
                .'produced one yet, so currently overdue cases are deliberately not a slice of '
                .'this composition.',
            'bars' => $completed === 0 ? null : [
                [
                    'label' => 'Returned on time',
                    'value' => $returns['on_time'].' · '.round($returns['on_time'] / $completed * 100).'%',
                    'share' => (int) round($returns['on_time'] / $completed * 100),
                ],
                [
                    'label' => 'Returned late',
                    'value' => $returns['late'].' · '.round($returns['late'] / $completed * 100).'%',
                    'share' => (int) round($returns['late'] / $completed * 100),
                ],
            ],
            'empty' => $completed === 0
                ? 'Return outcome cannot yet be measured because no returns were completed in this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'COMPLETED']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function lifecycle(array $scope): array
    {
        $stages = $this->analytics->lifecycle(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        $highest = max(1, max(array_column($stages, 'value')));

        return [
            'title' => 'Borrowing Lifecycle',
            'value' => null,
            'context' => 'Where this period\'s borrowings stand',
            'note' => $this->periodNote($scope)
                .' Each stage is a status the workflow actually writes, counted over the same '
                .'population, so the strip follows one set of borrowings through the process.',
            'bars' => array_map(
                static fn (array $stage): array => [
                    'label' => $stage['label'].' — '.$stage['note'],
                    'value' => (string) $stage['value'],
                    'share' => (int) round($stage['value'] / $highest * 100),
                ],
                $stages
            ),
            /*
             * Each stage's records live in a different place - awaiting/
             * preparing release are Borrowing Activity Report rows, on custody
             * belongs to Release & Custody, and returned belongs to Return &
             * Accountability - so no single report reproduces this strip. A
             * link to any one of them would look right for one stage and
             * wrong for the other three, so none is offered here.
             */
        ];
    }

    /** @return array<string, mixed> */
    private function overdueFollowUp(array $scope): array
    {
        $overdue = $this->analytics->currentOverdue($scope['division'], $scope['unit'], 25, $scope['borrower'] ?? null);

        return [
            'title' => 'Current Overdue Follow-up',
            'value' => $overdue['total'],
            'value_label' => $overdue['total'] === 1 ? 'borrowing overdue' : 'borrowings overdue',
            'context' => 'Out past the due date right now',
            'note' => $this->currentNote()
                .' Overdue means the equipment has not come back after its effective due date. '
                .'It is a separate measure from a late return, which is equipment that did come '
                .'back, only after its due date. The two are never combined.',
            'table' => $overdue['cases'] === [] ? null : [
                'columns' => ['Borrower', 'Custody No.', 'Due', 'Days overdue'],
                'rows' => array_map(
                    static fn (array $case): array => [
                        $case['borrower'],
                        $case['custody_no'],
                        optional($case['due_at'])->format('d M Y') ?: '—',
                        $case['days_overdue'] === null ? '—' : (string) $case['days_overdue'],
                    ],
                    $overdue['cases']
                ),
            ],
            'empty' => $overdue['total'] === 0 ? 'No borrowings are currently overdue.' : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'CURRENTLY_OVERDUE']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function returnCondition(array $scope): array
    {
        $conditions = $this->analytics->returnConditions(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        return [
            'title' => 'Return Condition',
            'value' => $conditions['total'],
            'value_label' => 'units inspected',
            'context' => 'Recorded at return inspection',
            'note' => $this->periodNote($scope)
                .' Read from the condition the receiving officer recorded on each return line. '
                .'Only conditions that were actually recorded are listed; a condition nobody '
                .'recorded is not shown as a zero.',
            'bars' => $conditions['rows'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['label'],
                    'value' => ($row['quantity'] + 0).' units',
                    'share' => $row['share'],
                ],
                $conditions['rows']
            ),
            'empty' => $conditions['rows'] === []
                ? 'No return inspections were recorded during this period.'
                : null,
            /*
             * Condition is recorded on a return line, which only exists for a
             * completed physical return, so COMPLETED is exactly the record
             * population the condition breakdown above is summed from.
             */
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'COMPLETED']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function returnSummary(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        $duration = $returns['average_duration'] ?? [];

        /*
         * Two readings against the previous period in one block: the completed
         * count (bars and rows) and the on-time rate (rows, in percentage
         * points). Both come from returnComparison(), which classifies the
         * previous window by physical completion date exactly as this period.
         */
        $comparison = $this->analytics->returnComparison(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $completedBlock = PeriodComparison::block($comparison['completed'], $this->previousWindowLabel($scope), 'returns');
        $rateBlock = PeriodComparison::block($comparison['on_time_rate'], $this->previousWindowLabel($scope));

        $completedBlock['rows'] = array_merge(
            array_map(static fn (array $row): array => ['Completed returns · '.$row[0], $row[1]], $completedBlock['rows']),
            array_map(static fn (array $row): array => ['On-time rate · '.$row[0], $row[1]], $rateBlock['rows'])
        );

        return [
            'title' => 'Return Performance Summary',
            'value' => $returns['completed'],
            'value_label' => $returns['completed'] === 1 ? 'completed return' : 'completed returns',
            'context' => 'Rates and duration over finished returns',
            'note' => $this->periodNote($scope)
                .' A rate needs a denominator. With no completed returns the honest reading is '
                .'"not measurable", which is a different statement from 0%. No service defines a '
                .'compliance threshold, so the measured rate is reported without being graded.',
            'comparison' => $completedBlock,
            'stats' => [
                ['label' => 'Completed returns', 'value' => $returns['completed']],
                [
                    'label' => 'On-time return rate',
                    'value' => $returns['on_time_rate'] === null ? 'Not measurable' : $returns['on_time_rate'].'%',
                ],
                [
                    'label' => 'Average borrowing duration',
                    'value' => $duration['label'] ?? 'Not available',
                ],
            ],
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'COMPLETED']
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function returnIssues(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $incidents = $this->analytics->incidentSummary(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );

        $stats = [
            ['label' => 'Open accountability cases', 'value' => $returns['open_cases']],
            ['label' => 'Incidents recorded', 'value' => $incidents['total']],
            ['label' => 'Incidents still open', 'value' => $incidents['open']],
        ];

        foreach ($incidents['types'] as $type) {
            $stats[] = [
                'label' => $type['type'],
                'value' => $type['count'].' · '.($type['quantity'] + 0).' units',
            ];
        }

        $nothing = $returns['open_cases'] === 0
            && $incidents['total'] === 0;

        return [
            'title' => 'Accountability & Return Issues',
            'value' => null,
            'context' => 'Issues raised against this period\'s borrowings',
            'note' => $this->periodNote($scope)
                .' Categories come from incident types the system actually records; none is '
                .'invented to fill the list.',
            'stats' => $stats,
            'empty' => $nothing
                ? 'No accountability or return-condition issues were recorded during this period.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Forecast & Planning                                                 */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function readiness(array $scope): array
    {
        $demand = $this->forecasts->demand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $available = (bool) ($demand['available'] ?? false);

        /*
         * The readiness facts are the two conditions hasEnoughHistory() itself
         * tests, reported by the service alongside the decision. history rows
         * are generated for every window whether or not it has finished, so
         * counting them would call an unfinished period complete; the facts
         * count only the windows that have actually ended.
         */
        $readiness = $demand['readiness'] ?? [];

        $periodsComplete = (int) ($readiness['periods_complete'] ?? 0);
        $periodsRequired = (int) ($readiness['periods_required'] ?? ForecastService::HISTORY_PERIODS);
        $observations = (int) ($readiness['observations'] ?? 0);
        $observationsRequired = (int) ($readiness['observations_required'] ?? ForecastService::MINIMUM_OBSERVATIONS);

        $stats = [
            ['label' => 'Completed periods', 'value' => $periodsComplete.' / '.$periodsRequired],
            ['label' => 'Recorded requests', 'value' => $observations.' / '.$observationsRequired],
            ['label' => 'Method', 'value' => (string) ($readiness['method'] ?? 'Weighted Moving Average')],
        ];

        return [
            'title' => 'Forecast Readiness',
            'value' => $available ? 'Ready' : 'Not ready',
            'context' => 'Whether a statistical forecast can be produced',
            'note' => 'A forecast is only offered once there are '.$periodsRequired
                .' completed comparable periods carrying at least '.$observationsRequired
                .' recorded requests in total. Below that the projection is withheld rather than '
                .'estimated from too little history, because a weighted average over one or two '
                .'observations states more confidence than the data supports.',
            'stats' => $stats,
            'empty' => $available
                ? null
                : (trim(($demand['reason'] ?? '').' '.($demand['requirement'] ?? '')) ?: 'Not enough completed history to forecast yet.'),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduled(array $scope): array
    {
        [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);
        $scheduled = $this->analytics->scheduledDemand(
            $forecastFrom, $forecastTo, $scope['division'], $scope['unit'], $scope['borrower'] ?? null
        );
        $count = (int) ($scheduled['requests'] ?? 0);
        $groups = collect($scheduled['divisions']['groups'] ?? []);

        return [
            'title' => 'Scheduled Demand',
            'value' => $count,
            'value_label' => $count === 1 ? 'request already filed' : 'requests already filed',
            'context' => 'Known bookings for '.$forecastFrom->format('d M Y').' – '.$forecastTo->format('d M Y'),
            'note' => null,
            'bars' => $groups->isEmpty() ? null : $groups->map(
                static fn (array $group): array => [
                    'label' => $group['label'],
                    'value' => $group['count'].' · '.$group['percentage'].'%',
                    'share' => $group['percentage'],
                ]
            )->all(),
            'empty' => $count === 0
                ? 'No requests have been filed for the next period yet.'
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function outlook(array $scope): array
    {
        $demand = $this->forecasts->demand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $history = $demand['history'] ?? [];
        $available = (bool) ($demand['available'] ?? false);

        /*
         * The same series the Outlook card plots: the history windows, the
         * selected period, and - only when the service produced one - the
         * projection, labelled as such. historyRows() carries counts and
         * weights but no share, so bar widths are drawn against the largest
         * value in this series; nothing here re-derives the projection.
         */
        $series = [];

        foreach ($history as $row) {
            $series[] = [
                'label' => (string) ($row['label'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'suffix' => 'observed · weight '.(int) ($row['weight'] ?? 1),
            ];
        }

        $series[] = [
            'label' => 'This period',
            'count' => (int) ($demand['current'] ?? 0),
            'suffix' => 'observed so far',
        ];

        if ($available) {
            $series[] = [
                'label' => 'Next period',
                'count' => (int) $demand['forecast'],
                'suffix' => 'projected',
            ];
        }

        $highest = (float) max(array_column($series, 'count') ?: [0]);

        return [
            'title' => 'Borrowing Demand Outlook',
            'value' => $available ? $demand['forecast'] : null,
            'value_label' => 'projected requests',
            'context' => 'Historical periods and the resulting projection',
            'note' => 'The projection is a weighted moving average over the '
                .ForecastService::HISTORY_PERIODS.' completed periods before the selected one, '
                .'weighted '.implode(' - ', ForecastService::WEIGHTS).' so the most recent period '
                .'counts most. Results are rounded to whole requests and never fall below zero.',
            'bars' => $history === [] ? null : array_map(
                fn (array $point): array => [
                    'label' => $point['label'],
                    'value' => $point['count'].' '.($point['count'] === 1 ? 'request' : 'requests')
                        .' · '.$point['suffix'],
                    'share' => $this->width((float) $point['count'], $highest),
                ],
                $series
            ),
            'bars_title' => 'Requests per period',
            'empty' => $available
                ? null
                : (trim(($demand['reason'] ?? '').' '.($demand['requirement'] ?? '')) ?: 'Not enough completed history to project demand yet.'),
        ];
    }

    /**
     * The same rows the Forecast & Planning card draws for divisions.
     *
     * divisionDemand() returns `groups` (code, label, short_label, current,
     * forecast). When it is unavailable the card falls back to scheduledDemand()
     * - requests already filed for the next window - under a Scheduled
     * heading, and so does this detail. The two are never merged.
     *
     * @return array<string, mixed>
     */
    private function forecastDivision(array $scope): array
    {
        $forecast = $this->forecasts->divisionDemand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $projected = (bool) ($forecast['available'] ?? false);

        if ($projected) {
            $rows = collect($forecast['groups'] ?? [])
                ->map(static fn (array $row): array => [
                    'label' => (string) ($row['short_label'] ?? $row['label'] ?? ''),
                    'value' => (int) ($row['forecast'] ?? 0),
                ])
                ->sortByDesc('value')
                ->values()
                ->all();
        } else {
            [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);
            $scheduled = $this->analytics->scheduledDemand(
                $forecastFrom, $forecastTo, $scope['division'], $scope['unit'], $scope['borrower'] ?? null
            );

            $rows = collect($scheduled['divisions']['groups'] ?? [])
                ->map(static fn (array $row): array => [
                    'label' => (string) ($row['label'] ?? ''),
                    'value' => (int) ($row['count'] ?? 0),
                ])
                ->sortByDesc('value')
                ->values()
                ->all();
        }

        return $this->forecastBreakdown(
            $scope,
            $rows,
            $projected,
            'Demand by Division',
            'division',
            $projected
                ? 'No division is expected to record borrowing activity next period.'
                : 'No borrowing requests are recorded for the next period yet.'
        );
    }

    /**
     * The same rows the Forecast & Planning card draws for units.
     *
     * unitDemand() returns `units` (unit, observations, current, forecast),
     * already sorted by forecast; the card shows the top five. The scheduled
     * fallback flattens scheduledDemand()'s per-division unit columns and
     * ranks them by recorded requests, exactly as the card does.
     *
     * @return array<string, mixed>
     */
    private function forecastUnit(array $scope): array
    {
        $forecast = $this->forecasts->unitDemand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $projected = (bool) ($forecast['available'] ?? false);

        if ($projected) {
            $rows = collect($forecast['units'] ?? [])
                ->map(static fn (array $row): array => [
                    'label' => (string) ($row['unit'] ?? ''),
                    'value' => (int) ($row['forecast'] ?? 0),
                ])
                ->take(5)
                ->values()
                ->all();
        } else {
            [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);
            $scheduled = $this->analytics->scheduledDemand(
                $forecastFrom, $forecastTo, $scope['division'], $scope['unit'], $scope['borrower'] ?? null
            );

            $rows = collect($scheduled['units']['columns'] ?? [])
                ->flatMap(static fn (array $column): array => collect($column['units'] ?? [])
                    ->map(static fn (array $row): array => [
                        'label' => (string) ($row['name'] ?? ''),
                        'value' => (int) ($row['count'] ?? 0),
                    ])
                    ->all())
                ->sortByDesc('value')
                ->take(5)
                ->values()
                ->all();
        }

        return $this->forecastBreakdown(
            $scope,
            $rows,
            $projected,
            'Demand by Unit',
            'unit',
            $projected
                ? 'No unit is expected to record borrowing activity next period.'
                : 'No unit demand is recorded for the next period yet.'
        );
    }

    /**
     * One shape for both breakdowns. The heading, the wording and the bar
     * label all follow $projected, so a scheduled count is never presented
     * under a forecast heading.
     *
     * @param  list<array{label: string, value: int}>  $rows
     * @return array<string, mixed>
     */
    private function forecastBreakdown(
        array $scope,
        array $rows,
        bool $projected,
        string $subject,
        string $noun,
        string $emptyMessage
    ): array {
        [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);
        $window = $forecastFrom->format('d M').' – '.$forecastTo->format('d M Y');

        /* A row set with nothing in it reads as empty, exactly as the card does. */
        $highest = (float) max(array_column($rows, 'value') ?: [0]);
        $listed = $highest > 0 ? $rows : [];

        return [
            'title' => ($projected ? 'Forecasted ' : 'Scheduled ').$subject,
            'value' => count($listed),
            'value_label' => $noun.(count($listed) === 1 ? '' : 's').' listed',
            'context' => $projected
                ? 'Projected requests for '.$window
                : 'Requests already recorded for '.$window.' (scheduled demand, not a forecast)',
            'note' => $projected
                ? 'A weighted moving average over the completed periods before the selected one, '
                    .'computed for each '.$noun.' from its own filed history.'
                : 'No statistical forecast is available yet, so this lists requests already filed '
                    .'for the next period. These are recorded future requests, not a projection. '
                    .'Scheduled demand and forecasted demand are never merged.',
            'bars' => $listed === [] ? null : array_map(
                fn (array $row): array => [
                    'label' => $row['label'],
                    'value' => $row['value'].' '.($row['value'] === 1 ? 'request' : 'requests')
                        .($projected ? ' projected' : ' already recorded'),
                    'share' => $this->width((float) $row['value'], $highest),
                ],
                $listed
            ),
            'bars_title' => $projected ? 'Projected requests' : 'Requests already recorded',
            'empty' => $listed === [] ? $emptyMessage : null,
        ];
    }

    /** @return array<string, mixed> */
    private function forecastEquipment(array $scope): array
    {
        $forecast = $this->forecasts->equipment(
            $scope['from'], $scope['to'], ForecastService::EQUIPMENT_LIMIT,
            $scope['division'], $scope['unit']
        );
        $rows = $forecast['items'] ?? [];
        $highest = $rows === [] ? 0 : max(array_map(
            static fn (array $row): float => (float) ($row['demand'] ?? 0),
            $rows
        ));

        return [
            'title' => 'Equipment Demand & Availability',
            'value' => count($rows),
            'value_label' => 'items assessed',
            'context' => 'Projected demand against expected availability',
            'note' => 'Expected availability excludes units already reserved, equipment still out '
                .'on custody, linen in laundry, and units held by an incident.',
            'bars' => $rows === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => (string) ($row['name'] ?? ''),
                    'value' => (($row['demand'] ?? 0) + 0).' needed · '
                        .(($row['expected_available'] ?? 0) + 0).' expected',
                    'share' => $highest > 0
                        ? (int) round(((float) ($row['demand'] ?? 0)) / $highest * 100)
                        : 0,
                ],
                $rows
            ),
            'empty' => $rows === []
                ? ($forecast['summary'] ?? $forecast['reason'] ?? 'No equipment has enough recorded movement to project yet.')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function busyPeriod(array $scope): array
    {
        $busy = $this->forecasts->busyPeriod(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        /*
         * busyPeriod() returns `buckets` (label, range, expected, level) and
         * `busiest`, the bucket with the highest expected volume, or null when
         * every slice is zero. The headline comes from that record; when the
         * service withheld the reading there is no bucket to quote, so no
         * figure is shown - an unmeasured slice is not "0 expected requests".
         */
        $available = (bool) ($busy['available'] ?? false);
        $busiest = $available ? ($busy['busiest'] ?? null) : null;
        $buckets = $available ? collect($busy['buckets'] ?? []) : collect();
        $peak = (float) ($buckets->max('expected') ?: 0);
        $peaks = $peak > 0 ? $buckets->where('expected', $peak) : collect();
        $tied = $peaks->count() > 1;

        $stats = [];

        if ($busiest !== null) {
            $stats[] = [
                'label' => $tied ? 'Tied busiest projected periods' : 'Busiest projected period',
                'value' => $peaks->map(fn (array $bucket): string => $bucket['label'].' ('.$bucket['range'].')')->implode(', '),
            ];
            $stats[] = ['label' => $tied ? 'Expected requests per period' : 'Expected requests', 'value' => (int) $busiest['expected']];
            $stats[] = ['label' => 'Level', 'value' => (string) ($busiest['level'] ?? 'High')];
        }

        return [
            'title' => 'Expected Busy Period',
            'value' => $busiest !== null ? $peaks->pluck('label')->implode(', ') : null,
            'value_label' => $busiest !== null ? ($tied ? 'tied busiest projected periods' : 'busiest projected period') : null,
            'context' => 'When the next period is expected to concentrate',
            'note' => 'The expected busy period is read from the same weighted history as the '
                .'demand projection. It is withheld rather than guessed when the history is too '
                .'short to separate a genuine peak from ordinary variation.',
            'stats' => $stats,
            'bars' => $buckets->isEmpty() ? null : $buckets->map(
                fn (array $bucket): array => [
                    'label' => (string) $bucket['label'].' · '.(string) $bucket['range'],
                    'value' => (int) $bucket['expected'].' '.((int) $bucket['expected'] === 1 ? 'request' : 'requests')
                        .' · '.(string) ($bucket['level'] ?? 'Normal'),
                    'share' => $this->width((float) $bucket['expected'], $peak),
                ]
            )->all(),
            'bars_title' => $buckets->isEmpty() ? null : 'Projected requests per slice',
            'empty' => $available
                ? ($busiest === null ? ($busy['summary'] ?? 'Borrowing activity is expected to stay even across the next period.') : null)
                : 'Insufficient history for busy-period forecasting.',
        ];
    }

    /** @return array<string, mixed> */
    private function planningNotes(array $scope): array
    {
        $demand = $this->forecasts->demand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        $low = $this->analytics->lowAvailability($this->inventory);
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Planning Notes',
            'value' => null,
            'context' => 'Current planning signals',
            'note' => 'These are the readings a planner needs alongside the projection. Each is '
                .'restated from a figure computed elsewhere on this page; none is a recommendation '
                .'the system invented.',
            'stats' => [
                [
                    'label' => 'Forecast status',
                    'value' => ($demand['available'] ?? false) ? 'Available' : 'Insufficient history',
                ],
                ['label' => 'Item types low on stock', 'value' => $low['count']],
                ['label' => 'Currently on custody', 'value' => $overview['on_custody']],
                ['label' => 'Currently overdue', 'value' => $overview['needs_follow_up']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function methodology(array $scope): array
    {
        $basis = $this->forecasts->basis();

        $stats = [];

        foreach ($basis as $label => $value) {
            $stats[] = [
                'label' => is_string($label) ? str_replace('_', ' ', ucfirst($label)) : (string) $value,
                'value' => is_array($value) ? implode(' - ', $value) : (string) $value,
            ];
        }

        return [
            'title' => 'Forecast Methodology',
            'value' => null,
            'context' => 'How the projection is produced',
            'note' => 'A weighted moving average over the '.ForecastService::HISTORY_PERIODS
                .' completed periods before the selected one, weighted '
                .implode(' - ', ForecastService::WEIGHTS).' so recent activity counts most. '
                .'A forecast is only shown when those periods are complete and carry at least '
                .ForecastService::MINIMUM_OBSERVATIONS.' recorded requests in total. Results are '
                .'rounded to whole requests and floored at zero. Expected availability excludes '
                .'reserved units, equipment already out, linen in laundry, and units held by an '
                .'incident.',
            'stats' => $stats,
        ];
    }
}
