<?php

namespace App\Services;

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
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period']
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
        ];
    }

    /** @return array<string, mixed> */
    private function overviewInsights(array $scope): array
    {
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        $low = $this->analytics->lowAvailability($this->inventory);
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Priority Insights',
            'value' => null,
            'context' => 'Signals ordered by operational weight',
            'note' => 'The panel mixes two kinds of reading. Currently overdue and low '
                .'availability are current-state figures, true as of today. The top borrowing '
                .'unit, the top released item and return compliance are period-scoped, measured '
                .'over the selected reporting period. Rows are ordered so a live risk always '
                .'outranks an informational pattern, and a reading with no data is omitted '
                .'rather than shown as zero.',
            'stats' => [
                ['label' => 'Currently overdue', 'value' => $overview['needs_follow_up']],
                ['label' => 'Item types low on stock', 'value' => $low['count']],
                ['label' => 'Completed returns', 'value' => $returns['completed']],
                [
                    'label' => 'On-time return rate',
                    'value' => $returns['on_time_rate'] === null
                        ? 'Not measurable'
                        : $returns['on_time_rate'].'%',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function releasedItems(array $scope, string $title = 'Top Released Items'): array
    {
        $equipment = $this->analytics->equipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10
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
        ];
    }

    /** @return array<string, mixed> */
    private function requestedItems(array $scope): array
    {
        $requested = $this->analytics->requestedEquipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10
        );

        return [
            'title' => 'Most Requested Items',
            'value' => count($requested['items']),
            'value_label' => 'items requested',
            'context' => 'Ranked by requested quantity',
            'note' => $this->periodNote($scope)
                .' A request that was never approved or released still expresses demand, so it '
                .'is counted here. Physical usage is the separate released measure.',
            'bars' => $requested['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => ($row['requested'] ?? 0) + 0 .' '.($row['unit'] ?? ''),
                    'share' => $row['share'] ?? 0,
                ],
                $requested['items']
            ),
            'empty' => $requested['items'] === []
                ? 'No equipment was requested during this period.'
                : null,
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
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(array $scope): array
    {
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Reporting Period Snapshot',
            'value' => null,
            'context' => 'Outcomes for borrowings filed in this period',
            'note' => $this->periodNote($scope)
                .' Each figure follows the borrowings filed inside the window, so it describes '
                .'what this period produced rather than what is true right now.',
            'stats' => [
                ['label' => 'Approved for release', 'value' => $overview['approved']],
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
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10
        );

        $total = array_sum(array_map(
            static fn (array $row): float => (float) ($row['requested'] ?? 0),
            $requested['items']
        ));

        return [
            'title' => 'Requested Quantity',
            'value' => $total + 0,
            'value_label' => 'units requested',
            'context' => 'What borrowers asked for',
            'note' => $this->periodNote($scope)
                .' Summed from request items, including requests that were never approved or '
                .'released, because an unmet request is still demand. Released quantity is the '
                .'separate measure of what physically went out.',
            'bars' => $requested['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => (($row['requested'] ?? 0) + 0).' '.($row['unit'] ?? ''),
                    'share' => $row['share'] ?? 0,
                ],
                $requested['items']
            ),
            'empty' => $total <= 0 ? 'No equipment was requested during this period.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function releasedQuantity(array $scope): array
    {
        $equipment = $this->analytics->equipment(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], 10
        );

        $total = array_sum(array_map(
            static fn (array $row): float => (float) $row['released'],
            $equipment['items']
        ));

        return [
            'title' => 'Released Quantity',
            'value' => $total + 0,
            'value_label' => 'units released',
            'context' => 'What physically left custody',
            'note' => $this->periodNote($scope)
                .' Summed from actual released quantities on custody lines, so it measures real '
                .'asset usage rather than demand.',
            'bars' => $equipment['items'] === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => ($row['released'] + 0).' '.$row['unit'],
                    'share' => $row['share'],
                ],
                $equipment['items']
            ),
            'empty' => $total <= 0 ? 'No equipment was physically released during this period.' : null,
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
        ];
    }

    /** @return array<string, mixed> */
    private function lowUsage(array $scope): array
    {
        $slow = $this->analytics->slowMovingItems($scope['from'], $scope['to'], 10);
        $rows = $slow['items'] ?? [];

        return [
            'title' => 'Low / No Usage Items',
            'value' => count($rows),
            'value_label' => 'items listed',
            'context' => 'Equipment that moved least',
            'note' => $this->periodNote($scope)
                .' The list starts from the catalogue rather than from custody records, so an '
                .'item that never moved still appears - which is the point of the question.',
            'bars' => $rows === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => (string) ($row['name'] ?? ''),
                    'value' => (($row['released'] ?? 0) + 0).' released',
                    'share' => (int) ($row['share'] ?? 0),
                ],
                $rows
            ),
            'empty' => $rows === [] ? 'No catalogue items are available to rank yet.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function peak(array $scope): array
    {
        $peak = $this->analytics->peakBorrowing(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $available = (bool) ($peak['available'] ?? false);

        return [
            'title' => 'Peak Borrowing Periods',
            'value' => $available ? ($peak['peak_label'] ?? null) : null,
            'context' => 'When borrowing concentrates',
            'note' => 'Peak analysis needs a minimum number of recorded requests before a busiest '
                .'period can be distinguished from ordinary variation. Below that threshold the '
                .'reading is withheld rather than estimated from too little data. '
                .$this->periodNote($scope),
            'stats' => [
                ['label' => 'Requests observed', 'value' => $peak['observations'] ?? 0],
                ['label' => 'Minimum required', 'value' => AnalyticsService::PEAK_MINIMUM_OBSERVATIONS],
            ],
            'empty' => $available ? null : ($peak['summary'] ?? 'Not enough borrowing activity to identify a peak period yet.'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Inventory Health                                                    */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function inventoryState(array $scope, string $facet): array
    {
        $inventory = $this->analytics->inventory($this->inventory);

        [$title, $value, $context] = match ($facet) {
            'available' => ['Available Units', $inventory['available'] ?? 0, 'Usable and free to allocate'],
            'reserved' => ['Reserved / Allocated', $inventory['reserved'] ?? 0, 'Committed to an approved request'],
            'custody' => ['On Custody', $inventory['on_custody'] ?? 0, 'Released and not yet returned'],
            default => ['Attention Needed', $inventory['attention'] ?? 0, 'Held out of circulation'],
        };

        return [
            'title' => $title,
            'value' => $value,
            'value_label' => 'units',
            'context' => $context,
            'note' => $this->currentNote()
                .' Inventory figures describe stock as it stands now, so they do not move when '
                .'the reporting period changes.',
            'stats' => [
                ['label' => 'Available', 'value' => $inventory['available'] ?? 0],
                ['label' => 'Reserved / allocated', 'value' => $inventory['reserved'] ?? 0],
                ['label' => 'On custody', 'value' => $inventory['on_custody'] ?? 0],
                ['label' => 'Attention needed', 'value' => $inventory['attention'] ?? 0],
            ],
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
            'note' => $this->currentNote()
                .' Every unit sits in exactly one state, so the states sum to the serviceable total.',
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
            'note' => $this->currentNote()
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
        ];
    }

    /** @return array<string, mixed> */
    private function operationalInventory(array $scope): array
    {
        $inventory = $this->analytics->inventory($this->inventory);

        return [
            'title' => 'Operational Inventory Status',
            'value' => null,
            'context' => 'Stock held outside normal circulation',
            'note' => $this->currentNote()
                .' These units are serviceable but unavailable: they are in laundry, held for '
                .'maintenance, or tied to an unresolved incident.',
            'stats' => [
                ['label' => 'In laundry', 'value' => $inventory['laundry'] ?? 0],
                ['label' => 'Maintenance', 'value' => $inventory['maintenance'] ?? 0],
                ['label' => 'Held by incident', 'value' => $inventory['incident'] ?? 0],
                ['label' => 'Attention needed', 'value' => $inventory['attention'] ?? 0],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function coverage(array $scope): array
    {
        $coverage = $this->analytics->stockCoverage(
            $this->inventory, $scope['from'], $scope['to'], 10
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
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period']
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
        ];
    }

    /** @return array<string, mixed> */
    private function returnOutcome(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
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
        ];
    }

    /** @return array<string, mixed> */
    private function lifecycle(array $scope): array
    {
        $stages = $this->analytics->lifecycle(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
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
        ];
    }

    /** @return array<string, mixed> */
    private function overdueFollowUp(array $scope): array
    {
        $overdue = $this->analytics->currentOverdue($scope['division'], $scope['unit'], 25);

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
        ];
    }

    /** @return array<string, mixed> */
    private function returnCondition(array $scope): array
    {
        $conditions = $this->analytics->returnConditions(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
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
        ];
    }

    /** @return array<string, mixed> */
    private function returnSummary(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $duration = $returns['average_duration'] ?? [];

        return [
            'title' => 'Return Performance Summary',
            'value' => $returns['completed'],
            'value_label' => $returns['completed'] === 1 ? 'completed return' : 'completed returns',
            'context' => 'Rates and duration over finished returns',
            'note' => $this->periodNote($scope)
                .' A rate needs a denominator. With no completed returns the honest reading is '
                .'"not measurable", which is a different statement from 0%. No service defines a '
                .'compliance threshold, so the measured rate is reported without being graded.',
            'stats' => [
                ['label' => 'Completed returns', 'value' => $returns['completed']],
                [
                    'label' => 'On-time return rate',
                    'value' => $returns['on_time_rate'] === null ? 'Not measurable' : $returns['on_time_rate'].'%',
                ],
                [
                    'label' => 'Late return rate',
                    'value' => $returns['late_rate'] === null ? 'Not measurable' : $returns['late_rate'].'%',
                ],
                [
                    'label' => 'Average borrowing duration',
                    'value' => $duration['label'] ?? 'Not available',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function returnIssues(array $scope): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );
        $incidents = $this->analytics->incidentSummary(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $stats = [
            ['label' => 'Open accountability cases', 'value' => $returns['open_cases']],
            ['label' => 'Incidents recorded', 'value' => $incidents['total']],
            ['label' => 'Incidents still open', 'value' => $incidents['open']],
            ['label' => 'Late returns recorded', 'value' => $returns['late']],
        ];

        foreach ($incidents['types'] as $type) {
            $stats[] = [
                'label' => $type['type'],
                'value' => $type['count'].' · '.($type['quantity'] + 0).' units',
            ];
        }

        $nothing = $returns['open_cases'] === 0
            && $incidents['total'] === 0
            && $returns['late'] === 0;

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
        $history = $demand['history'] ?? [];

        $stats = [
            ['label' => 'Completed periods required', 'value' => ForecastService::HISTORY_PERIODS],
            ['label' => 'Completed periods available', 'value' => count($history)],
            ['label' => 'Minimum observations', 'value' => ForecastService::MINIMUM_OBSERVATIONS],
            [
                'label' => 'Observations recorded',
                'value' => array_sum(array_map(
                    static fn (array $row): int => (int) ($row['count'] ?? 0),
                    $history
                )),
            ],
        ];

        return [
            'title' => 'Forecast Readiness',
            'value' => $available ? 'Ready' : 'Not ready',
            'context' => 'Whether a statistical forecast can be produced',
            'note' => 'A forecast is only offered once there are '.ForecastService::HISTORY_PERIODS
                .' completed comparable periods carrying at least '.ForecastService::MINIMUM_OBSERVATIONS
                .' recorded requests in total. Below that the projection is withheld rather than '
                .'estimated from too little history, because a weighted average over one or two '
                .'observations states more confidence than the data supports.',
            'stats' => $stats,
            'empty' => $available ? null : ($demand['summary'] ?? 'Not enough completed history to forecast yet.'),
        ];
    }

    /** @return array<string, mixed> */
    private function scheduled(array $scope): array
    {
        [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);

        $count = $this->analytics
            ->requestScope($forecastFrom, $forecastTo, $scope['division'], $scope['unit'])
            ->count('borrowing_requests.id');

        $groups = $this->analytics->borrowerGroups(
            $forecastFrom, $forecastTo, $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Scheduled Demand',
            'value' => $count,
            'value_label' => $count === 1 ? 'request already filed' : 'requests already filed',
            'context' => 'Known bookings for '.$forecastFrom->format('d M Y').' – '.$forecastTo->format('d M Y'),
            'note' => 'Scheduled demand is a count of requests that already exist for the next '
                .'period. It is a record, not a projection, and it is never merged with the '
                .'forecasted demand produced by the weighted average - the two answer different '
                .'questions and can legitimately disagree.',
            'bars' => $groups['groups']->isEmpty() ? null : $groups['groups']->map(
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
                static fn (array $row): array => [
                    'label' => (string) ($row['label'] ?? ''),
                    'value' => (string) ($row['count'] ?? 0),
                    'share' => (int) ($row['share'] ?? 0),
                ],
                $history
            ),
            'empty' => $available ? null : ($demand['summary'] ?? 'Not enough completed history to project demand yet.'),
        ];
    }

    /** @return array<string, mixed> */
    private function forecastDivision(array $scope): array
    {
        $forecast = $this->forecasts->divisionDemand($this->analytics, $scope['from'], $scope['to']);

        return $this->forecastBreakdown($scope, $forecast, 'Demand by Division', 'division');
    }

    /** @return array<string, mixed> */
    private function forecastUnit(array $scope): array
    {
        $forecast = $this->forecasts->unitDemand($this->analytics, $scope['from'], $scope['to']);

        return $this->forecastBreakdown($scope, $forecast, 'Demand by Unit', 'unit');
    }

    /**
     * @param  array<string, mixed>  $forecast
     * @return array<string, mixed>
     */
    private function forecastBreakdown(array $scope, array $forecast, string $title, string $noun): array
    {
        $rows = $forecast['rows'] ?? [];

        return [
            'title' => $title,
            'value' => count($rows),
            'value_label' => $noun.($rows === [] || count($rows) === 1 ? '' : 's').' listed',
            'context' => 'Projected against recorded history',
            'note' => 'Each '.$noun.' is guarded on its own history: one with too few completed '
                .'observations shows its scheduled bookings instead of a projection, because a '
                .'weighted average over one observation is not a forecast. '
                .'Scheduled demand and forecasted demand are never merged.',
            'bars' => $rows === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => (string) ($row['label'] ?? $row['name'] ?? ''),
                    'value' => (string) ($row['forecast'] ?? $row['count'] ?? 0),
                    'share' => (int) ($row['share'] ?? 0),
                ],
                $rows
            ),
            'empty' => $rows === []
                ? 'No '.$noun.' has enough recorded activity to report yet.'
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function forecastEquipment(array $scope): array
    {
        $forecast = $this->forecasts->equipment($scope['from'], $scope['to']);
        $rows = $forecast['items'] ?? [];

        return [
            'title' => 'Equipment Demand & Availability',
            'value' => count($rows),
            'value_label' => 'items assessed',
            'context' => 'Projected demand against expected availability',
            'note' => 'Expected availability excludes units already reserved, equipment still out '
                .'on custody, linen in laundry, and units held by an incident, because none of '
                .'those can be handed to a new borrower. An item is only projected when its own '
                .'history is long enough; otherwise its scheduled bookings are shown instead.',
            'bars' => $rows === [] ? null : array_map(
                static fn (array $row): array => [
                    'label' => (string) ($row['name'] ?? ''),
                    'value' => (($row['forecast'] ?? 0) + 0).' needed · '
                        .(($row['available'] ?? 0) + 0).' expected',
                    'share' => (int) ($row['share'] ?? 0),
                ],
                $rows
            ),
            'empty' => $rows === []
                ? ($forecast['summary'] ?? 'No equipment has enough recorded movement to project yet.')
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function busyPeriod(array $scope): array
    {
        $busy = $this->forecasts->busyPeriod($this->analytics, $scope['from'], $scope['to']);
        $available = (bool) ($busy['available'] ?? false);

        return [
            'title' => 'Expected Busy Period',
            'value' => $available ? ($busy['label'] ?? null) : null,
            'context' => 'When the next period is expected to concentrate',
            'note' => 'The expected busy period is read from the same weighted history as the '
                .'demand projection. It is withheld rather than guessed when the history is too '
                .'short to separate a genuine peak from ordinary variation.',
            'stats' => [
                ['label' => 'Completed periods required', 'value' => ForecastService::HISTORY_PERIODS],
                ['label' => 'Expected requests', 'value' => $busy['expected'] ?? 0],
            ],
            'empty' => $available
                ? null
                : ($busy['summary'] ?? 'Not enough completed history to expect a busy period yet.'),
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
