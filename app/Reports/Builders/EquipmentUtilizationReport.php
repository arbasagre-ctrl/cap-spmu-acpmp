<?php

namespace App\Reports\Builders;

use App\Models\InventoryItem;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Support\OrganizationalStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Equipment Utilization Report.
 *
 * Utilization means quantity that physically left the store: the sum of
 * custody_lines.actual_released_quantity for custody transactions whose
 * released_at falls inside the reporting period. Requested and approved
 * quantities are deliberately not used — a request that was approved and
 * then never picked up has utilized nothing.
 *
 * Usage is broken down by the three canonical divisions. Research,
 * Innovation and Collaboration is reported on its own; nothing folds it into
 * Academic or Administrative.
 */
class EquipmentUtilizationReport implements ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset
    {
        $equipment = $filters->get('equipment');
        $division = $filters->get('division');
        $unit = $filters->get('unit');

        $items = InventoryItem::query()
            ->with(['category', 'unit'])
            ->where('active', true)
            ->when($equipment !== null, fn ($query) => $query->whereKey($equipment))
            ->orderBy('unique_description')
            ->get();

        /*
         * One grouped query returns every item's released quantity per
         * division, so the report cost does not grow with the catalogue.
         * The join chain is the authoritative physical-release path:
         * custody line -> custody transaction (released_at) -> request
         * version (the division snapshot taken when the request was filed).
         */
        $usage = DB::table('custody_lines')
            ->join(
                'custody_transactions',
                'custody_transactions.id',
                '=',
                'custody_lines.custody_transaction_id'
            )
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->join(
                'request_versions',
                'request_versions.id',
                '=',
                'custody_transactions.request_version_id'
            )
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$filters->from, $filters->to])
            ->when(
                $equipment !== null,
                fn ($query) => $query->where('request_items.inventory_item_id', $equipment)
            )
            ->when(
                $division !== null,
                fn ($query) => $query->where('request_versions.division_code', $division)
            )
            ->when(
                $unit !== null,
                fn ($query) => $query->where('request_versions.office_unit', $unit)
            )
            ->groupBy('request_items.inventory_item_id', 'request_versions.division_code')
            ->selectRaw(
                'request_items.inventory_item_id AS item_id,
                 request_versions.division_code AS division_code,
                 COALESCE(SUM(custody_lines.actual_released_quantity), 0) AS released_quantity,
                 COUNT(DISTINCT custody_transactions.id) AS transaction_count'
            )
            ->get();

        $byItem = $usage->groupBy('item_id');

        $divisionCodes = OrganizationalStructure::divisionCodes();

        $rows = $items
            ->map(function (InventoryItem $item) use ($byItem, $divisionCodes): array {
                $itemUsage = $byItem->get($item->id, collect());

                $released = (float) $itemUsage->sum(
                    fn ($row): float => (float) $row->released_quantity
                );

                $transactions = (int) $itemUsage->sum(
                    fn ($row): int => (int) $row->transaction_count
                );

                $row = [
                    '_released' => $released,

                    'item' => (string) $item->unique_description,
                    'category' => (string) ($item->category?->category_name ?? ''),
                    'unit' => (string) ($item->unit?->unit_name ?? ''),
                    'released_quantity' => $this->number($released),
                    'transactions' => (string) $transactions,
                    'utilization_state' => $released > 0 ? 'Utilized' : 'No utilization',
                    '_tone_utilization_state' => $released > 0 ? 'positive' : 'neutral',
                ];

                foreach ($divisionCodes as $code) {
                    $row['division_'.strtolower($code)] = $this->number(
                        (float) $itemUsage
                            ->where('division_code', $code)
                            ->sum(fn ($usageRow): float => (float) $usageRow->released_quantity)
                    );
                }

                return $row;
            })
            /* Highest utilization first; the ranking is the point of the report. */
            ->sortByDesc('_released')
            ->values();

        /* Rank is assigned after ordering so it always reads 1..n. */
        $rows = $rows
            ->map(function (array $row, int $index): array {
                return array_merge(['rank' => (string) ($index + 1)], $row);
            })
            ->values();

        $columns = [
            ['key' => 'rank', 'label' => 'Rank', 'align' => 'numeric'],
            ['key' => 'item', 'label' => 'Item'],
            ['key' => 'category', 'label' => 'Category'],
            ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'released_quantity', 'label' => 'Released Qty', 'align' => 'numeric'],
            ['key' => 'transactions', 'label' => 'Number of Releases', 'align' => 'numeric'],
        ];

        foreach ($divisionCodes as $code) {
            $columns[] = [
                'key' => 'division_'.strtolower($code),
                'label' => OrganizationalStructure::shortLabel($code),
                'align' => 'numeric',
            ];
        }

        $columns[] = ['key' => 'utilization_state', 'label' => 'Utilization State', 'badge' => true];

        return new ReportDataset(
            reportKey: 'utilization',
            label: ReportCatalogue::definition('utilization')['label'],
            columns: $columns,
            rows: $rows,
            summary: [
                'Active items compared' => $rows->count(),
                'Items with utilization' => $rows->filter(
                    fn (array $row): bool => $row['_released'] > 0
                )->count(),
                'No utilization' => $rows->filter(
                    fn (array $row): bool => $row['_released'] <= 0
                )->count(),
                'Released quantity' => (int) $rows->sum(
                    fn (array $row): float => $row['_released']
                ),
            ],
        );
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
