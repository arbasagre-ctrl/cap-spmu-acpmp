<?php

namespace App\Reports\Builders;

use App\Models\InventoryItem;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportFilters;
use App\Services\InventoryService;
use Illuminate\Support\Collection;

/**
 * Inventory Status Report.
 *
 * State comes from InventoryService::portfolio(), which computes the same
 * balance as availability() — the authoritative figure the borrowing form,
 * allocation and custody screens read — for the whole catalogue in a fixed
 * number of queries. Reports never recomputes stock from raw tables, so a
 * report can never contradict what an officer sees when releasing an item.
 *
 * The balance is taken over a deliberately wide window because this report
 * states the item's present physical position, not its position within the
 * reporting period. The reporting period still appears in the metadata so the
 * reader knows when the snapshot was taken.
 */
class InventoryStatusReport implements ReportBuilder
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function build(ReportFilters $filters): ReportDataset
    {
        $equipment = $filters->get('equipment');
        $availability = $filters->get('availability_status');

        $items = InventoryItem::query()
            ->with(['category', 'unit'])
            ->where('active', true)
            ->when(
                $equipment !== null,
                fn ($query) => $query->whereKey($equipment)
            )
            ->orderBy('unique_description')
            ->get();

        $windowFrom = now()->subYears(10)->startOfDay();
        $windowTo = now()->addYears(10)->endOfDay();

        /*
         * portfolio() computes exactly what availability() does but with a
         * fixed number of grouped queries, so the report no longer runs ~8
         * queries per catalogue item. Linen still in laundry is excluded from
         * available stock by the same arithmetic the rest of the system uses:
         * returned is not available until laundry processing completes.
         */
        $balances = $this->inventory->portfolio($items, $windowFrom, $windowTo);

        $rows = $items
            ->map(function (InventoryItem $item) use ($balances): array {
                $balance = $balances[$item->id] ?? [];

                $available = (float) ($balance['current_available'] ?? $balance['available'] ?? 0);
                $allocated = (float) ($balance['allocated'] ?? $balance['reserved'] ?? 0);
                $borrowed = (float) ($balance['borrowed'] ?? 0);
                $laundry = (float) ($balance['laundry'] ?? 0);

                $unserviceable = (float) ($balance['damaged_maintenance'] ?? 0)
                    + (float) ($balance['lost'] ?? 0)
                    + (float) ($balance['stolen'] ?? 0)
                    + (float) ($balance['destroyed'] ?? 0)
                    + (float) ($balance['condemned'] ?? 0);

                return [
                    '_available' => $available,
                    '_allocated' => $allocated,
                    '_borrowed' => $borrowed,
                    '_laundry' => $laundry,
                    '_unserviceable' => $unserviceable,

                    'item' => (string) $item->unique_description,
                    'category' => (string) ($item->category?->category_name ?? ''),
                    'unit' => (string) ($item->unit?->unit_name ?? ''),
                    'total' => $this->number($balance['total'] ?? 0),
                    'available' => $this->number($available),
                    'allocated' => $this->number($allocated),
                    'borrowed' => $this->number($borrowed),
                    'laundry' => $this->number($laundry),
                    'damaged_maintenance' => $this->number($balance['damaged_maintenance'] ?? 0),
                    'lost' => $this->number($balance['lost'] ?? 0),
                    'stolen' => $this->number($balance['stolen'] ?? 0),
                    'destroyed' => $this->number($balance['destroyed'] ?? 0),
                    'condemned' => $this->number($balance['condemned'] ?? 0),
                    'availability_status' => match (true) {
                        $available > 0 => 'Available',
                        $unserviceable > 0 => 'Unserviceable stock',
                        $laundry > 0 => 'In laundry',
                        $borrowed > 0 => 'Fully on custody',
                        default => 'Fully committed',
                    },
                    '_tone_availability_status' => match (true) {
                        $available > 0 => 'positive',
                        $unserviceable > 0 => 'critical',
                        default => 'attention',
                    },
                ];
            })
            ->when(
                $availability !== null,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $this->matchesAvailability($row, (string) $availability)
                )
            )
            ->values();

        return new ReportDataset(
            reportKey: 'inventory',
            label: ReportCatalogue::definition('inventory')['label'],
            columns: [
                ['key' => 'item', 'label' => 'Item'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'unit', 'label' => 'Unit'],
                ['key' => 'total', 'label' => 'Total', 'align' => 'numeric'],
                ['key' => 'available', 'label' => 'Available', 'align' => 'numeric'],
                ['key' => 'allocated', 'label' => 'Allocated', 'align' => 'numeric'],
                ['key' => 'borrowed', 'label' => 'On Custody', 'align' => 'numeric'],
                ['key' => 'laundry', 'label' => 'Laundry', 'align' => 'numeric'],
                ['key' => 'damaged_maintenance', 'label' => 'Damaged / Maintenance', 'align' => 'numeric'],
                ['key' => 'lost', 'label' => 'Lost', 'align' => 'numeric'],
                ['key' => 'stolen', 'label' => 'Stolen', 'align' => 'numeric'],
                ['key' => 'destroyed', 'label' => 'Destroyed', 'align' => 'numeric'],
                ['key' => 'condemned', 'label' => 'Condemned', 'align' => 'numeric'],
                ['key' => 'availability_status', 'label' => 'Availability Status', 'badge' => true],
            ],
            rows: $rows,
            summary: [
                'Tracked inventory items' => $rows->count(),
                'Physical available' => (int) $rows->sum(fn (array $row): float => $row['_available']),
                'Allocated' => (int) $rows->sum(fn (array $row): float => $row['_allocated']),
                'On custody' => (int) $rows->sum(fn (array $row): float => $row['_borrowed']),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesAvailability(array $row, string $availability): bool
    {
        return match ($availability) {
            'AVAILABLE' => $row['_available'] > 0,
            'FULLY_COMMITTED' => $row['_available'] <= 0,
            'ALLOCATED' => $row['_allocated'] > 0,
            'ON_CUSTODY' => $row['_borrowed'] > 0,
            'IN_LAUNDRY' => $row['_laundry'] > 0,
            'UNSERVICEABLE' => $row['_unserviceable'] > 0,
            default => true,
        };
    }

    private function number(mixed $value): string
    {
        $value = (float) $value;

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
