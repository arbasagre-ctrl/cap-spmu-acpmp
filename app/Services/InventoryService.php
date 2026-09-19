<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Models\RequestVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Get the inventory balance for the selected borrowing period.
     *
     * Important distinction:
     *
     * - allocated:
     *   Approved quantities reserved for the selected period but not yet released.
     *
     * - borrowed:
     *   Actual quantity physically released and CURRENTLY under borrower custody.
     *   This is what the Inventory page should display as "On custody".
     *
     * - borrowed_for_period:
     *   Current borrowed quantities whose custody period overlaps the selected
     *   availability period. This value is used when calculating date-based
     *   availability.
     *
     * - laundry:
     *   Returned linen/items that are still undergoing required laundry processing.
     *
     * - incident:
     *   Quantities unavailable because of recorded incident disposition.
     */
    public function availability(
        InventoryItem $item,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        /*
        |--------------------------------------------------------------------------
        | ALLOCATED FOR SELECTED PERIOD
        |--------------------------------------------------------------------------
        |
        | Count only the remaining quantity that has been reserved but has not
        | yet been physically released or restored.
        |
        */

        $allocated = (float) DB::table('allocations')
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'allocations.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->whereIn(
                'allocations.status',
                [
                    'ACTIVE',
                    'PARTIALLY_RELEASED',
                ]
            )
            ->where(
                'allocations.period_start',
                '<=',
                $to
            )
            ->where(
                'allocations.period_end',
                '>=',
                $from
            )
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN (
                                COALESCE(allocations.allocated_quantity, 0)
                                - COALESCE(allocations.released_quantity, 0)
                                - COALESCE(allocations.restored_quantity, 0)
                            ) > 0
                            THEN (
                                COALESCE(allocations.allocated_quantity, 0)
                                - COALESCE(allocations.released_quantity, 0)
                                - COALESCE(allocations.restored_quantity, 0)
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
                '
            )
            ->value('quantity');

        /*
        |--------------------------------------------------------------------------
        | CURRENT ACTIVE RESERVATIONS
        |--------------------------------------------------------------------------
        |
        | Borrower-facing availability is a current stock reference, not a
        | selected-period forecast. Count every remaining approved allocation,
        | regardless of its borrowing dates. Pending requests have no allocation
        | and therefore do not reduce this balance.
        |
        */

        $reserved = (float) DB::table('allocations')
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'allocations.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->whereIn(
                'allocations.status',
                [
                    'ACTIVE',
                    'PARTIALLY_RELEASED',
                ]
            )
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN (
                                COALESCE(allocations.allocated_quantity, 0)
                                - COALESCE(allocations.released_quantity, 0)
                                - COALESCE(allocations.restored_quantity, 0)
                            ) > 0
                            THEN (
                                COALESCE(allocations.allocated_quantity, 0)
                                - COALESCE(allocations.released_quantity, 0)
                                - COALESCE(allocations.restored_quantity, 0)
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
                '
            )
            ->value('quantity');


        /*
        |--------------------------------------------------------------------------
        | CURRENT PHYSICAL CUSTODY
        |--------------------------------------------------------------------------
        |
        | This is NOT filtered by the selected availability dates.
        |
        | Example:
        |
        | actual_released_quantity = 2
        | returned_quantity        = 0
        |
        | borrowed / On custody    = 2
        |
        | After one is returned:
        |
        | actual_released_quantity = 2
        | returned_quantity        = 1
        |
        | borrowed / On custody    = 1
        |
        */

        $borrowed = (float) DB::table('custody_lines')
            ->join(
                'custody_transactions',
                'custody_transactions.id',
                '=',
                'custody_lines.custody_transaction_id'
            )
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'custody_lines.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->whereNotNull(
                'custody_transactions.released_at'
            )
            ->whereIn(
                'custody_transactions.status',
                [
                    'ACTIVE',
                    'RETURN_PROCESSING',
                    'OVERDUE',
                    
                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN',
                ]
            )
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN (
                                COALESCE(custody_lines.actual_released_quantity, 0)
                                - COALESCE(custody_lines.returned_quantity, 0)
                            ) > 0
                            THEN (
                                COALESCE(custody_lines.actual_released_quantity, 0)
                                - COALESCE(custody_lines.returned_quantity, 0)
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
                '
            )
            ->value('quantity');


        /*
        |--------------------------------------------------------------------------
        | BORROWED QUANTITY AFFECTING SELECTED PERIOD
        |--------------------------------------------------------------------------
        |
        | This is separate from the current On Custody quantity above.
        |
        | It is used only for date-based availability calculations.
        |
        | A physically borrowed item affects the selected period when:
        |
        | released_at <= selected period end
        | due_at      >= selected period start
        |
        */

        $borrowedForPeriod = (float) DB::table('custody_lines')
            ->join(
                'custody_transactions',
                'custody_transactions.id',
                '=',
                'custody_lines.custody_transaction_id'
            )
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'custody_lines.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->whereNotNull(
                'custody_transactions.released_at'
            )
            ->whereIn(
                'custody_transactions.status',
                [
                    'ACTIVE',
                    'RETURN_PROCESSING',
                    'OVERDUE',
                    
                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN',
                ]
            )
            ->where(
                'custody_transactions.released_at',
                '<=',
                $to
            )
            ->where(
                'custody_transactions.due_at',
                '>=',
                $from
            )
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN (
                                COALESCE(custody_lines.actual_released_quantity, 0)
                                - COALESCE(custody_lines.returned_quantity, 0)
                            ) > 0
                            THEN (
                                COALESCE(custody_lines.actual_released_quantity, 0)
                                - COALESCE(custody_lines.returned_quantity, 0)
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
                '
            )
            ->value('quantity');


        /*
        |--------------------------------------------------------------------------
        | LAUNDRY
        |--------------------------------------------------------------------------
        |
        | Returned items requiring laundry remain unavailable until the laundry
        | record has been fully verified or cancelled.
        |
        */

        $legacyLaundry = (float) DB::table('laundry_records')
            ->join(
                'return_lines',
                'return_lines.id',
                '=',
                'laundry_records.return_line_id'
            )
            ->join(
                'custody_lines',
                'custody_lines.id',
                '=',
                'return_lines.custody_line_id'
            )
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'custody_lines.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->whereNotIn(
                'laundry_records.status',
                [
                    'VERIFIED',
                    'CANCELLED',
                ]
            )
            ->sum(
                'return_lines.quantity_received'
            );

        /*
        |--------------------------------------------------------------------------
        | LAUNDRY (current LaundryJob-based workflow)
        |--------------------------------------------------------------------------
        |
        | The legacy query above only ever matches pre-LaundryJob historical
        | data (CustodyService only creates a laundry_records row when no
        | LaundryJob exists for the return line). Every current-workflow
        | linen return instead tracks state on laundry_job_lines, which the
        | legacy query never sees — without this second query, returned
        | linen would incorrectly become available again the moment SPMU
        | records the return inspection, before Laundry has even received
        | it, let alone finished washing it.
        |
        | Still-unavailable quantity = however much was returned with a
        | LAUNDRY disposition, minus whatever has actually been restored to
        | AVAILABLE by the completed-form return encoding so far
        | (laundry_job_lines.completed_quantity records the serviceable portion
        | restored automatically — the damaged-during-wash portion is never
        | subtracted here, since it never moves to AVAILABLE either).
        |
        | Written with a portable CASE/COALESCE expression (no GREATEST()/
        | multi-arg MAX()) so it runs identically on MariaDB/MySQL
        | (production) and SQLite (the automated test suite).
        |
        | legacyLaundry and this query must never double-count the same
        | return line. A return line already counted by legacyLaundry above
        | (it has a laundry_records row) is excluded here via the left join
        | to laundry_records plus whereNull below. A return line with
        | NEITHER a laundry_job_lines row NOR a laundry_records row is
        | genuinely untracked by either workflow (not legacy-tracked) and
        | must still count as unavailable here, via the left join's zero
        | default for laundry_job_lines.completed_quantity - it would
        | otherwise silently become available to nobody's tracking.
        */

        $currentLaundry = (float) DB::table('return_lines')
            ->join(
                'custody_lines',
                'custody_lines.id',
                '=',
                'return_lines.custody_line_id'
            )
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'custody_lines.request_item_id'
            )
            ->leftJoin(
                'laundry_job_lines',
                'laundry_job_lines.custody_line_id',
                '=',
                'custody_lines.id'
            )
            ->leftJoin(
                'laundry_records',
                'laundry_records.return_line_id',
                '=',
                'return_lines.id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->where(
                'return_lines.disposition_state',
                'LAUNDRY'
            )
            ->whereNull('laundry_records.id')
            ->selectRaw(
                '
                COALESCE(
                    SUM(
                        CASE
                            WHEN (
                                COALESCE(return_lines.quantity_received, 0)
                                - COALESCE(laundry_job_lines.completed_quantity, 0)
                            ) > 0
                            THEN (
                                COALESCE(return_lines.quantity_received, 0)
                                - COALESCE(laundry_job_lines.completed_quantity, 0)
                            )
                            ELSE 0
                        END
                    ),
                    0
                ) AS quantity
                '
            )
            ->value('quantity');

        $laundry = $legacyLaundry + $currentLaundry;


        /*
        |--------------------------------------------------------------------------
        | INCIDENT QUANTITIES
        |--------------------------------------------------------------------------
        |
        | Quantities routed into incident handling are unavailable inventory.
        |
        */

        /*
        |--------------------------------------------------------------------------
        | INCIDENT STATE BREAKDOWN
        |--------------------------------------------------------------------------
        |
        | Incident lines remain part of the historical accountability record even
        | after the borrower has settled an obligation. Physical inventory is restored only by a recorded physical disposition.
        | Repair, replacement, and recovery are posted automatically when the
        | Action Officer verifies the Head-required compliance; write-off remains
        | a formal Admin inventory disposition. The original incident record stays
        | unchanged as the accountability history.
        |
        */

        $incidentStates = DB::table('incident_lines')
            ->join(
                'custody_lines',
                'custody_lines.id',
                '=',
                'incident_lines.custody_line_id'
            )
            ->join(
                'request_items',
                'request_items.id',
                '=',
                'custody_lines.request_item_id'
            )
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->selectRaw(
                '
                incident_lines.disposition_state,
                COALESCE(
                    SUM(incident_lines.quantity),
                    0
                ) AS quantity
                '
            )
            ->groupBy(
                'incident_lines.disposition_state'
            )
            ->pluck(
                'quantity',
                'disposition_state'
            );

        $incidentClosures = $this->incidentClosureStateTotals([$item->id])[$item->id] ?? [];

        $incidentStates = $incidentStates->mapWithKeys(
            fn ($quantity, $state): array => [
                $state => max(
                    0,
                    (float) $quantity - (float) ($incidentClosures[$state] ?? 0)
                ),
            ]
        );

        $incident = (float) $incidentStates->sum();

        /*
        |--------------------------------------------------------------------------
        | ADMIN-RECORDED CONDITION HOLD
        |--------------------------------------------------------------------------
        |
        | A physical issue discovered outside a borrower accountability case (for
        | example during AO item preparation) can place otherwise free stock under
        | maintenance. These formal Stock Card movements are separate from incident
        | lines and remain unavailable until returned to service or retired.
        |
        */

        $conditionHold = $this->conditionHoldTotals([$item->id])[$item->id] ?? 0.0;


        /*
        |--------------------------------------------------------------------------
        | TOTAL SERVICEABLE INVENTORY
        |--------------------------------------------------------------------------
        */

        $total = (float) $item->total_quantity;

        $serviceableTotal =
            $item->active
            && $item->condition_code === 'SERVICEABLE'
                ? $total
                : 0.0;


        /*
        |--------------------------------------------------------------------------
        | AVAILABLE FOR SELECTED PERIOD
        |--------------------------------------------------------------------------
        |
        | Notice that we use $borrowedForPeriod here instead of $borrowed.
        |
        | This preserves the system's date-aware reservation functionality.
        |
        | For example:
        |
        | Total = 6
        | Currently borrowed = 2
        |
        | If the selected borrowing period overlaps those borrowed items:
        |
        | Available = 4
        |
        | If the selected period happens after those items are expected back:
        |
        | Available for that future period may correctly be 6.
        |
        */
        $currentAvailable = max(
            0,
            $serviceableTotal
            - $borrowed
            - $laundry
            - $incident
            - $conditionHold
        );

        $borrowerAvailable = max(
            0,
            $serviceableTotal
            - $reserved
            - $borrowed
            - $laundry
            - $incident
            - $conditionHold
        );

        $available = max(
            0,
            $serviceableTotal
            - $allocated
            - $borrowedForPeriod
            - $laundry
            - $incident
            - $conditionHold
        );


        /*
        |--------------------------------------------------------------------------
        | FINAL BALANCE
        |--------------------------------------------------------------------------
        */

        return [
            'total' => $total,

            /*
             * Reserved but not yet physically released.
             */
            'allocated' => $allocated,

            /*
             * All current approved reservations, regardless of period.
             */
            'reserved' => $reserved,

            /*
             * Current actual physical custody.
             *
             * Inventory page:
             * "On custody"
             */
            'borrowed' => $borrowed,

            /*
             * Current borrowed quantities that overlap the selected
             * availability period.
             */
            'borrowed_for_period' => $borrowedForPeriod,

            /*
             * Other unavailable inventory states.
             */
            'laundry' => $laundry,

            'incident' => $incident,

            /*
             * Formal Head/Admin condition holds that are not borrower incidents.
             */
            'condition_hold' => (float) $conditionHold,

            /*
             * Incident state breakdown plus formal maintenance holds.
             */
            'damaged_maintenance' =>
                (float) (
                    $incidentStates['DAMAGED_MAINTENANCE']
                    ?? 0
                )
                + (float) $conditionHold
                + (
                    $item->condition_code === 'DAMAGED_MAINTENANCE'
                        ? $total
                        : 0
                ),

            'lost' =>
                (float) (
                    $incidentStates['LOST']
                    ?? 0
                ),

            'stolen' =>
                (float) (
                    $incidentStates['STOLEN']
                    ?? 0
                ),

            'destroyed' =>
                (float) (
                    $incidentStates['DESTROYED']
                    ?? 0
                ),

            'condemned' =>
                $item->condition_code === 'CONDEMNED'
                    ? $total
                    : 0.0,

            /*
             * Quantity available for the selected borrowing period.
             */
            'current_available' => $currentAvailable,

            /*
             * Informational current quantity shown to borrowers.
             */
            'borrower_available' => $borrowerAvailable,

            'available' => $available,
        ];
    }


    /**
     * The same balance as availability(), for many items at once.
     *
     * availability() answers for a single item and runs roughly eight queries
     * doing it. Asking it about every active item - which is what an inventory
     * summary needs - multiplies that by the size of the catalogue. This method
     * computes the identical components with one grouped query each, so the
     * query count is fixed no matter how many items are passed in.
     *
     * The arithmetic is deliberately identical to availability(); the tests
     * assert the two agree item by item.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryItem>|iterable<InventoryItem>  $items
     * @return array<int, array<string, float>>
     */
    public function portfolio(
        iterable $items,
        CarbonInterface $from,
        CarbonInterface $to
    ): array {
        $items = collect($items);
        $ids = $items->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $allocated = $this->allocationTotals($ids, $from, $to);
        $reserved = $this->allocationTotals($ids, null, null);
        $borrowed = $this->custodyTotals($ids, null, null);
        $borrowedForPeriod = $this->custodyTotals($ids, $from, $to);
        $laundry = $this->laundryTotals($ids);
        [$incident, $incidentStates] = $this->incidentTotals($ids);
        $conditionHolds = $this->conditionHoldTotals($ids);

        $balances = [];

        foreach ($items as $item) {
            $id = $item->id;

            $total = (float) $item->total_quantity;
            $serviceableTotal = $item->active && $item->condition_code === 'SERVICEABLE'
                ? $total
                : 0.0;

            $itemLaundry = (float) ($laundry[$id] ?? 0);
            $itemIncident = (float) ($incident[$id] ?? 0);
            $itemConditionHold = (float) ($conditionHolds[$id] ?? 0);
            $itemBorrowed = (float) ($borrowed[$id] ?? 0);
            $itemStates = $incidentStates[$id] ?? [];

            $balances[$id] = [
                'total' => $total,
                'serviceable_total' => $serviceableTotal,
                'allocated' => (float) ($allocated[$id] ?? 0),
                'reserved' => (float) ($reserved[$id] ?? 0),
                'borrowed' => $itemBorrowed,
                'borrowed_for_period' => (float) ($borrowedForPeriod[$id] ?? 0),
                'laundry' => $itemLaundry,
                'incident' => $itemIncident,
                'condition_hold' => $itemConditionHold,
                'damaged_maintenance' => (float) ($itemStates['DAMAGED_MAINTENANCE'] ?? 0)
                    + $itemConditionHold
                    + ($item->condition_code === 'DAMAGED_MAINTENANCE' ? $total : 0.0),
                'lost' => (float) ($itemStates['LOST'] ?? 0),
                'stolen' => (float) ($itemStates['STOLEN'] ?? 0),
                'destroyed' => (float) ($itemStates['DESTROYED'] ?? 0),
                'condemned' => $item->condition_code === 'CONDEMNED' ? $total : 0.0,
                'current_available' => max(
                    0,
                    $serviceableTotal - $itemBorrowed - $itemLaundry - $itemIncident - $itemConditionHold
                ),
                'borrower_available' => max(
                    0,
                    $serviceableTotal
                    - (float) ($reserved[$id] ?? 0)
                    - $itemBorrowed
                    - $itemLaundry
                    - $itemIncident
                    - $itemConditionHold
                ),
                'available' => max(
                    0,
                    $serviceableTotal
                    - (float) ($allocated[$id] ?? 0)
                    - (float) ($borrowedForPeriod[$id] ?? 0)
                    - $itemLaundry
                    - $itemIncident
                    - $itemConditionHold
                ),
            ];
        }

        return $balances;
    }

    /**
     * Remaining approved allocation per item. Passing null dates gives the
     * period-independent "reserved" figure.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function allocationTotals(array $ids, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $query = DB::table('allocations')
            ->join('request_items', 'request_items.id', '=', 'allocations.request_item_id')
            ->whereIn('request_items.inventory_item_id', $ids)
            ->whereIn('allocations.status', ['ACTIVE', 'PARTIALLY_RELEASED']);

        if ($from !== null && $to !== null) {
            $query->where('allocations.period_start', '<=', $to)
                ->where('allocations.period_end', '>=', $from);
        }

        return $query
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ('
                .'COALESCE(allocations.allocated_quantity, 0)'
                .' - COALESCE(allocations.released_quantity, 0)'
                .' - COALESCE(allocations.restored_quantity, 0)) > 0 THEN ('
                .'COALESCE(allocations.allocated_quantity, 0)'
                .' - COALESCE(allocations.released_quantity, 0)'
                .' - COALESCE(allocations.restored_quantity, 0)) ELSE 0 END), 0) AS quantity'
            )
            ->pluck('quantity', 'item_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * Quantity physically out on custody per item. Passing dates narrows it to
     * custody overlapping that window.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function custodyTotals(array $ids, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $query = DB::table('custody_lines')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->join(
                'custody_transactions',
                'custody_transactions.id',
                '=',
                'custody_lines.custody_transaction_id'
            )
            ->whereIn('request_items.inventory_item_id', $ids)
            ->whereNotNull('custody_transactions.released_at')
            ->whereIn('custody_transactions.status', [
                'ACTIVE',
                'RETURN_PROCESSING',
                'OVERDUE',
                'INCIDENT_OPEN',
                'OBLIGATION_OPEN',
            ]);

        if ($from !== null && $to !== null) {
            $query->where('custody_transactions.released_at', '<=', $to)
                ->where('custody_transactions.due_at', '>=', $from);
        }

        return $query
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ('
                .'COALESCE(custody_lines.actual_released_quantity, 0)'
                .' - COALESCE(custody_lines.returned_quantity, 0)) > 0 THEN ('
                .'COALESCE(custody_lines.actual_released_quantity, 0)'
                .' - COALESCE(custody_lines.returned_quantity, 0)) ELSE 0 END), 0) AS quantity'
            )
            ->pluck('quantity', 'item_id')
            ->map(fn ($value): float => (float) $value)
            ->all();
    }

    /**
     * Returned stock still held by Laundry Operations, per item.
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function laundryTotals(array $ids): array
    {
        $current = DB::table('return_lines')
            ->join('custody_lines', 'custody_lines.id', '=', 'return_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->leftJoin(
                'laundry_job_lines',
                'laundry_job_lines.custody_line_id',
                '=',
                'custody_lines.id'
            )
            /*
             * A return line already counted by $legacy below (it has a
             * laundry_records row) must not also be counted here - see
             * availability()'s matching laundryTotals-equivalent query for
             * the full explanation. A return line with neither a
             * laundry_job_lines row nor a laundry_records row is genuinely
             * untracked (not legacy-tracked), so it must still count here
             * via the left join's zero default, same as before.
             */
            ->leftJoin(
                'laundry_records',
                'laundry_records.return_line_id',
                '=',
                'return_lines.id'
            )
            ->whereIn('request_items.inventory_item_id', $ids)
            ->where('return_lines.disposition_state', 'LAUNDRY')
            ->whereNull('laundry_records.id')
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ('
                .'COALESCE(return_lines.quantity_received, 0)'
                .' - COALESCE(laundry_job_lines.completed_quantity, 0)) > 0 THEN ('
                .'COALESCE(return_lines.quantity_received, 0)'
                .' - COALESCE(laundry_job_lines.completed_quantity, 0)) ELSE 0 END), 0) AS quantity'
            )
            ->pluck('quantity', 'item_id');

        $legacy = DB::table('laundry_records')
            ->join('return_lines', 'return_lines.id', '=', 'laundry_records.return_line_id')
            ->join('custody_lines', 'custody_lines.id', '=', 'return_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereIn('request_items.inventory_item_id', $ids)
            ->whereNotIn('laundry_records.status', ['VERIFIED', 'CANCELLED'])
            ->groupBy('request_items.inventory_item_id')
            ->select('request_items.inventory_item_id AS item_id')
            ->selectRaw('COALESCE(SUM(COALESCE(return_lines.quantity_received, 0)), 0) AS quantity')
            ->pluck('quantity', 'item_id');

        $totals = [];

        foreach ($ids as $id) {
            $totals[$id] = (float) ($current[$id] ?? 0) + (float) ($legacy[$id] ?? 0);
        }

        return $totals;
    }

    /**
     * Incident-held quantity per item, with its disposition breakdown.
     *
     * @param  list<int>  $ids
     * @return array{0: array<int, float>, 1: array<int, array<string, float>>}
     */
    private function incidentTotals(array $ids): array
    {
        $rows = DB::table('incident_lines')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereIn('request_items.inventory_item_id', $ids)
            ->groupBy('request_items.inventory_item_id', 'incident_lines.disposition_state')
            ->select(
                'request_items.inventory_item_id AS item_id',
                'incident_lines.disposition_state AS state'
            )
            ->selectRaw('COALESCE(SUM(incident_lines.quantity), 0) AS quantity')
            ->get();

        $closures = $this->incidentClosureStateTotals($ids);
        $totals = [];
        $states = [];

        foreach ($rows as $row) {
            $remaining = max(
                0,
                (float) $row->quantity
                - (float) ($closures[$row->item_id][$row->state] ?? 0)
            );

            if ($remaining <= 0) {
                continue;
            }

            $totals[$row->item_id] = ($totals[$row->item_id] ?? 0) + $remaining;
            $states[$row->item_id][$row->state] = $remaining;
        }

        return [$totals, $states];
    }

    /**
     * Inventory movements that physically close an incident-held quantity.
     * A Head decision alone never restores inventory; repair/replacement/recovery
     * close only after Action Officer verification, while write-off is recorded by Admin.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, float>>
     */
    private function incidentClosureStateTotals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('inventory_transaction_lines as line')
            ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->whereIn('line.inventory_item_id', $ids)
            ->where('tx.source_type', Incident::class)
            ->whereIn('tx.transaction_type', [
                'INVENTORY_INCIDENT_RESTORATION',
                'INVENTORY_INCIDENT_WRITE_OFF',
            ])
            ->whereIn('line.to_state', ['AVAILABLE', 'RETIRED'])
            ->groupBy('line.inventory_item_id', 'line.from_state')
            ->select(
                'line.inventory_item_id AS item_id',
                'line.from_state AS state'
            )
            ->selectRaw('COALESCE(SUM(line.quantity), 0) AS quantity')
            ->get();

        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row->item_id][(string) $row->state] = (float) $row->quantity;
        }

        return $states;
    }

    /**
     * Outstanding quantity placed under maintenance directly by the SPMU Head.
     * These movements are used for verified physical discrepancies that are not
     * tied to a borrower Incident (for example, a condition issue found during
     * pre-release preparation).
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function conditionHoldTotals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placed = DB::table('inventory_transaction_lines as line')
            ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->whereIn('line.inventory_item_id', $ids)
            ->where('tx.transaction_type', 'INVENTORY_CONDITION_HOLD')
            ->where('line.to_state', 'DAMAGED_MAINTENANCE')
            ->groupBy('line.inventory_item_id')
            ->select('line.inventory_item_id AS item_id')
            ->selectRaw('COALESCE(SUM(line.quantity), 0) AS quantity')
            ->pluck('quantity', 'item_id');

        $closed = DB::table('inventory_transaction_lines as line')
            ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->whereIn('line.inventory_item_id', $ids)
            ->whereIn('tx.transaction_type', [
                'INVENTORY_CONDITION_RESTORATION',
                'INVENTORY_CONDITION_RETIREMENT',
            ])
            ->where('line.from_state', 'DAMAGED_MAINTENANCE')
            ->groupBy('line.inventory_item_id')
            ->select('line.inventory_item_id AS item_id')
            ->selectRaw('COALESCE(SUM(line.quantity), 0) AS quantity')
            ->pluck('quantity', 'item_id');

        $totals = [];

        foreach ($ids as $id) {
            $totals[(int) $id] = max(
                0,
                (float) ($placed[$id] ?? 0) - (float) ($closed[$id] ?? 0)
            );
        }

        return $totals;
    }

    /**
     * Current manual maintenance hold for a single inventory item.
     */
    public function conditionHoldQuantity(InventoryItem $item): float
    {
        return (float) ($this->conditionHoldTotals([$item->id])[$item->id] ?? 0);
    }

    /**
     * Manual Inventory Overview actions are intentionally narrower than the
     * automatic transactional movements posted by borrowing, Laundry, and
     * Accountability workflows. Laundry remains the routine cleaning path for
     * linen, but a laundry-managed item may still need a physical maintenance
     * hold when it is actually damaged (for example, torn and awaiting repair).
     *
     * @param  array<string, mixed>|null  $balance
     * @return array<string, bool|float>
     */
    public function manualAdjustmentCapabilities(
        InventoryItem $item,
        ?array $balance = null
    ): array {
        $balance ??= $this->availability($item, now(), now()->addSecond());

        $conditionHold = (float) ($balance['condition_hold'] ?? $this->conditionHoldQuantity($item));
        $freeServiceable = (float) ($balance['borrower_available'] ?? $balance['available'] ?? 0);
        $usesLaundryWorkflow = (bool) $item->laundry_required;

        return [
            'uses_laundry_workflow' => $usesLaundryWorkflow,
            'free_serviceable' => $freeServiceable,
            'condition_hold' => $conditionHold,
            'can_place_under_maintenance' => $item->active
                && $item->condition_code === 'SERVICEABLE'
                && $freeServiceable > 0,
            'can_return_maintenance_to_service' => $conditionHold > 0,
            'can_retire_maintenance_stock' => $conditionHold > 0,
        ];
    }

    /**
     * Remaining physical incident quantity for one item/incident/state after
     * prior repair, replacement, recovery, or write-off transactions.
     */
    public function remainingIncidentQuantity(
        InventoryItem $item,
        Incident $incident,
        string $state
    ): float {
        $state = strtoupper($state);

        $recorded = (float) DB::table('incident_lines')
            ->join('custody_lines', 'custody_lines.id', '=', 'incident_lines.custody_line_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->where('incident_lines.incident_id', $incident->id)
            ->where('request_items.inventory_item_id', $item->id)
            ->where('incident_lines.disposition_state', $state)
            ->sum('incident_lines.quantity');

        if ($recorded <= 0) {
            return 0.0;
        }

        $closed = (float) DB::table('inventory_transaction_lines as line')
            ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
            ->where('line.inventory_item_id', $item->id)
            ->where('tx.source_type', Incident::class)
            ->where('tx.source_id', $incident->id)
            ->where('line.from_state', $state)
            ->whereIn('tx.transaction_type', [
                'INVENTORY_INCIDENT_RESTORATION',
                'INVENTORY_INCIDENT_WRITE_OFF',
            ])
            ->sum('line.quantity');

        return max(0, $recorded - $closed);
    }

    /**
     * Apply the physical inventory effect of a verified accountability
     * compliance action. The Head/Admin chooses the required action; the AO
     * verifies the physical completion. Inventory changes happen only here,
     * at verification time, never when the Head decision is first recorded.
     *
     * @return list<array<string, mixed>>
     */
    public function recordIncidentCompliance(
        Incident $incident,
        User $actor,
        string $complianceAction
    ): array {
        $complianceAction = strtoupper(trim($complianceAction));

        $inventoryAction = match ($complianceAction) {
            'REPAIR' => 'RETURN_TO_SERVICE',
            'REPLACEMENT' => 'REPLACEMENT_RECEIVED',
            'RECOVERY' => 'ITEM_RECOVERED',
            default => throw ValidationException::withMessages([
                'compliance_action' => 'The recorded compliance action cannot update Inventory automatically.',
            ]),
        };

        $allowedStates = match ($complianceAction) {
            'REPAIR' => ['DAMAGED_MAINTENANCE'],
            'REPLACEMENT' => ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED', 'REPLACEMENT_REQUIRED'],
            'RECOVERY' => ['LOST', 'STOLEN'],
            default => [],
        };

        $rows = DB::table('incident_lines as incident_line')
            ->join('custody_lines as custody_line', 'custody_line.id', '=', 'incident_line.custody_line_id')
            ->join('request_items as request_item', 'request_item.id', '=', 'custody_line.request_item_id')
            ->where('incident_line.incident_id', $incident->id)
            ->groupBy('request_item.inventory_item_id', 'incident_line.disposition_state')
            ->get([
                'request_item.inventory_item_id',
                'incident_line.disposition_state',
                DB::raw('COALESCE(SUM(incident_line.quantity), 0) as recorded_quantity'),
            ]);

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'incident' => 'No inventory quantity is linked to this property accountability case.',
            ]);
        }

        $results = [];

        foreach ($rows as $row) {
            $state = strtoupper((string) $row->disposition_state);

            if (! in_array($state, $allowedStates, true)) {
                throw ValidationException::withMessages([
                    'compliance_action' => 'The recorded compliance action does not match the affected property condition.',
                ]);
            }

            $item = InventoryItem::query()->findOrFail((int) $row->inventory_item_id);
            $remaining = $this->remainingIncidentQuantity($item, $incident, $state);

            // A pre-existing manual reconciliation may already have closed the
            // physical quantity. Do not create a duplicate Stock Card movement.
            if ($remaining <= 0) {
                continue;
            }

            $results[] = $this->recordAdjustment($item, $actor, [
                'action' => $inventoryAction,
                'incident_id' => $incident->id,
                'incident_state' => $state,
                'quantity' => $remaining,
            ]);
        }

        return $results;
    }

    /**
     * Record the opening stock of a newly created inventory item in the same
     * read-only Stock Card used by operational movements.
     */
    public function recordInitialStock(
        InventoryItem $item,
        ?User $actor = null,
        ?string $sourceReference = null
    ): void
    {
        $quantity = (float) $item->total_quantity;

        if ($quantity <= 0) {
            return;
        }

        $transactionId = DB::table('inventory_transactions')->insertGetId([
            'actor_user_id' => $actor?->id,
            'transaction_type' => 'INVENTORY_INITIAL_STOCK',
            'source_type' => InventoryItem::class,
            'source_id' => $item->id,
            'reason' => filled($sourceReference)
                ? 'Opening stock recorded when the inventory item was created. Source / Reference: '.trim($sourceReference)
                : 'Opening stock recorded when the inventory item was created.',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_transaction_lines')->insert([
            'inventory_transaction_id' => $transactionId,
            'inventory_item_id' => $item->id,
            'from_state' => 'OPENING_BALANCE',
            'to_state' => $item->condition_code === 'SERVICEABLE'
                ? 'AVAILABLE'
                : $item->condition_code,
            'quantity' => $quantity,
            'before_quantity' => 0,
            'after_quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Formal SPMU Head inventory reconciliation. This is intentionally separate
     * from the metadata Edit screen so stock/condition changes always leave a
     * Stock Card movement and cannot silently change an approved commitment.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordAdjustment(
        InventoryItem $item,
        User $actor,
        array $data
    ): array {
        return DB::transaction(function () use ($item, $actor, $data): array {
            $locked = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            $action = strtoupper((string) ($data['action'] ?? ''));
            $reason = trim((string) ($data['reason'] ?? ''));

            if ($action === 'RETURN_MAINTENANCE_TO_SERVICE' && $reason === '') {
                $reason = 'Physically verified serviceable and returned to service.';
            }

            if (in_array($action, [
                'STOCK_ADDITION',
                'PHYSICAL_COUNT_CORRECTION',
                'PLACE_UNDER_MAINTENANCE',
                'RETIRE_MAINTENANCE_STOCK',
                'WRITE_OFF_RETIRED',
            ], true) && $reason === '') {
                throw ValidationException::withMessages([
                    'reason' => 'This inventory adjustment requires a documented source, reason, or formal basis.',
                ]);
            }

            $beforeTotal = (float) $locked->total_quantity;
            $beforeBalance = $this->availability($locked, now(), now()->addSecond());

            $transactionType = null;
            $sourceType = InventoryItem::class;
            $sourceId = $locked->id;
            $fromState = 'AVAILABLE';
            $toState = 'AVAILABLE';
            $quantity = 0.0;
            $afterTotal = $beforeTotal;
            $actionLabel = '';

            if ($action === 'STOCK_ADDITION') {
                $quantity = (float) ($data['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Enter the quantity of newly received stock.',
                    ]);
                }

                $transactionType = 'INVENTORY_STOCK_ADDITION';
                $fromState = 'OUTSIDE_STOCK';
                $toState = 'AVAILABLE';
                $afterTotal = $beforeTotal + $quantity;
                $actionLabel = 'Additional Stock Received';
            } elseif ($action === 'PHYSICAL_COUNT_CORRECTION') {
                $target = (float) ($data['new_total_quantity'] ?? -1);
                if ($target < 0) {
                    throw ValidationException::withMessages([
                        'new_total_quantity' => 'Enter the verified physical stock total.',
                    ]);
                }

                $committed = (float) $beforeBalance['reserved']
                    + (float) $beforeBalance['borrowed']
                    + (float) $beforeBalance['laundry']
                    + (float) $beforeBalance['incident']
                    + (float) ($beforeBalance['condition_hold'] ?? 0);

                if ($target < $committed) {
                    throw ValidationException::withMessages([
                        'new_total_quantity' => "Physical stock total cannot be lower than the active committed/unavailable quantity of {$committed}.",
                    ]);
                }

                if (abs($target - $beforeTotal) < 0.0001) {
                    throw ValidationException::withMessages([
                        'new_total_quantity' => 'The verified physical stock total is unchanged.',
                    ]);
                }

                $quantity = abs($target - $beforeTotal);
                $transactionType = 'INVENTORY_PHYSICAL_COUNT_CORRECTION';
                $fromState = $target > $beforeTotal ? 'COUNT_CORRECTION' : 'AVAILABLE';
                $toState = $target > $beforeTotal ? 'AVAILABLE' : 'COUNT_CORRECTION';
                $afterTotal = $target;
                $actionLabel = 'Physical Count Correction';
            } elseif (in_array($action, [
                'PLACE_UNDER_MAINTENANCE',
                'RETURN_MAINTENANCE_TO_SERVICE',
                'RETIRE_MAINTENANCE_STOCK',
            ], true)) {
                $quantity = (float) ($data['quantity'] ?? 0);
                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Enter the quantity covered by this physical stock adjustment.',
                    ]);
                }

                $conditionHold = $this->conditionHoldQuantity($locked);

                if (in_array($action, ['RETURN_MAINTENANCE_TO_SERVICE', 'RETIRE_MAINTENANCE_STOCK'], true)
                    && $conditionHold <= 0) {
                    throw ValidationException::withMessages([
                        'action' => 'No units are currently under the manual maintenance hold.',
                    ]);
                }

                if ($action === 'PLACE_UNDER_MAINTENANCE') {
                    if (! $locked->active || $locked->condition_code !== 'SERVICEABLE') {
                        throw ValidationException::withMessages([
                            'action' => 'Maintenance hold can only be recorded for an active serviceable inventory record.',
                        ]);
                    }

                    $freeQuantity = (float) ($beforeBalance['borrower_available'] ?? 0);
                    if ($quantity > $freeQuantity) {
                        throw ValidationException::withMessages([
                            'quantity' => "Only {$freeQuantity} uncommitted serviceable unit(s) can be placed under maintenance.",
                        ]);
                    }

                    $transactionType = 'INVENTORY_CONDITION_HOLD';
                    $fromState = 'AVAILABLE';
                    $toState = 'DAMAGED_MAINTENANCE';
                    $actionLabel = 'Placed Under Maintenance';
                } elseif ($action === 'RETURN_MAINTENANCE_TO_SERVICE') {
                    if ($quantity > $conditionHold) {
                        throw ValidationException::withMessages([
                            'quantity' => "Only {$conditionHold} unit(s) are currently under the manual maintenance hold.",
                        ]);
                    }

                    $transactionType = 'INVENTORY_CONDITION_RESTORATION';
                    $fromState = 'DAMAGED_MAINTENANCE';
                    $toState = 'AVAILABLE';
                    $actionLabel = 'Maintenance Stock Returned to Service';
                } else {
                    if ($quantity > $conditionHold) {
                        throw ValidationException::withMessages([
                            'quantity' => "Only {$conditionHold} unit(s) are currently under the manual maintenance hold.",
                        ]);
                    }

                    $transactionType = 'INVENTORY_CONDITION_RETIREMENT';
                    $fromState = 'DAMAGED_MAINTENANCE';
                    $toState = 'RETIRED';
                    $afterTotal = $beforeTotal - $quantity;

                    $remainingHold = max(0, $conditionHold - $quantity);
                    $committedAfter = (float) $beforeBalance['reserved']
                        + (float) $beforeBalance['borrowed']
                        + (float) $beforeBalance['laundry']
                        + (float) $beforeBalance['incident']
                        + $remainingHold;

                    if ($afterTotal < $committedAfter) {
                        throw ValidationException::withMessages([
                            'quantity' => 'The retirement would reduce Total Stock below active inventory commitments.',
                        ]);
                    }

                    $actionLabel = 'Maintenance Stock Retired / Condemned';
                }
            } elseif (in_array($action, [
                'RETURN_TO_SERVICE',
                'REPLACEMENT_RECEIVED',
                'ITEM_RECOVERED',
                'WRITE_OFF_RETIRED',
            ], true)) {
                $incidentId = (int) ($data['incident_id'] ?? 0);
                $state = strtoupper((string) ($data['incident_state'] ?? ''));
                $quantity = (float) ($data['quantity'] ?? 0);
                $incident = Incident::query()->find($incidentId);

                if (! $incident || $state === '') {
                    throw ValidationException::withMessages([
                        'incident_source' => 'Select the related accountability incident.',
                    ]);
                }

                if ($action === 'WRITE_OFF_RETIRED'
                    && ! in_array(strtoupper((string) $incident->status), ['RESOLVED', 'CLOSED'], true)) {
                    throw ValidationException::withMessages([
                        'incident_source' => 'Stock write-off is available only after the related accountability case has a final resolution.',
                    ]);
                }

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Enter the quantity covered by this reconciliation.',
                    ]);
                }

                $allowedStates = match ($action) {
                    'RETURN_TO_SERVICE' => ['DAMAGED_MAINTENANCE'],
                    'ITEM_RECOVERED' => ['LOST', 'STOLEN'],
                    'REPLACEMENT_RECEIVED' => ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED', 'REPLACEMENT_REQUIRED'],
                    'WRITE_OFF_RETIRED' => ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED'],
                    default => [],
                };

                if (! in_array($state, $allowedStates, true)) {
                    throw ValidationException::withMessages([
                        'incident_source' => 'The selected accountability incident is not valid for this action.',
                    ]);
                }

                $remaining = $this->remainingIncidentQuantity($locked, $incident, $state);
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages([
                        'quantity' => "Only {$remaining} unit(s) remain under this inventory exception.",
                    ]);
                }

                $sourceType = Incident::class;
                $sourceId = $incident->id;
                $fromState = $state;

                if ($action === 'WRITE_OFF_RETIRED') {
                    $transactionType = 'INVENTORY_INCIDENT_WRITE_OFF';
                    $toState = 'RETIRED';
                    $afterTotal = $beforeTotal - $quantity;
                    $remainingIncident = max(0, (float) $beforeBalance['incident'] - $quantity);
                    $committedAfter = (float) $beforeBalance['reserved']
                        + (float) $beforeBalance['borrowed']
                        + (float) $beforeBalance['laundry']
                        + $remainingIncident;

                    if ($afterTotal < $committedAfter) {
                        throw ValidationException::withMessages([
                            'quantity' => 'The write-off would reduce Total Stock below active inventory commitments.',
                        ]);
                    }

                    $actionLabel = 'Stock Retired / Written Off';
                } else {
                    $transactionType = 'INVENTORY_INCIDENT_RESTORATION';
                    $toState = 'AVAILABLE';
                    $actionLabel = match ($action) {
                        'RETURN_TO_SERVICE' => 'Returned to Service / Repaired',
                        'REPLACEMENT_RECEIVED' => 'Replacement Received',
                        'ITEM_RECOVERED' => 'Item Recovered',
                    };
                }
            } else {
                throw ValidationException::withMessages([
                    'action' => 'Select a valid inventory adjustment action.',
                ]);
            }

            if ($afterTotal < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Inventory quantity cannot be negative.',
                ]);
            }

            if (abs($afterTotal - $beforeTotal) >= 0.0001) {
                $locked->update(['total_quantity' => $afterTotal]);
            }

            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'actor_user_id' => $actor->id,
                'transaction_type' => $transactionType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reason' => $reason !== ''
                    ? $actionLabel.' — '.$reason
                    : $actionLabel,
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $lineId = DB::table('inventory_transaction_lines')->insertGetId([
                'inventory_transaction_id' => $transactionId,
                'inventory_item_id' => $locked->id,
                'from_state' => $fromState,
                'to_state' => $toState,
                'quantity' => $quantity,
                'before_quantity' => (float) ($beforeBalance['borrower_available'] ?? 0),
                'after_quantity' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $fresh = $locked->fresh();
            $afterBalance = $this->availability($fresh, now(), now()->addSecond());

            DB::table('inventory_transaction_lines')
                ->where('id', $lineId)
                ->update([
                    'after_quantity' => (float) ($afterBalance['borrower_available'] ?? 0),
                    'updated_at' => now(),
                ]);

            return [
                'action' => $action,
                'action_label' => $actionLabel,
                'quantity' => $quantity,
                'before_total' => $beforeTotal,
                'after_total' => (float) $fresh->total_quantity,
                'before_available' => (float) ($beforeBalance['borrower_available'] ?? 0),
                'after_available' => (float) ($afterBalance['borrower_available'] ?? 0),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
        }, 3);
    }

    /**
     * Reserve inventory only after SPMU verification/approval.
     *
     * @return list<Allocation>
     */
    public function allocate(
        RequestVersion $version,
        ?CarbonInterface $reservationStartsAt = null
    ): array {
        return DB::transaction(function () use ($version, $reservationStartsAt): array {
            $version->loadMissing(
                'items.inventoryItem'
            );

            $allocations = [];
            $reservationStart = $reservationStartsAt ?: $version->needed_from;

            $transactionId = DB::table(
                'inventory_transactions'
            )->insertGetId([
                'actor_user_id' => auth()->id(),
                'transaction_type' => 'SPMU_APPROVAL_RESERVATION',
                'source_type' => RequestVersion::class,
                'source_id' => $version->id,
                'reason' => 'Atomic reservation after SPMU verification/approval.',
                'correlation_id' => (string) Str::uuid(),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);


            foreach ($version->items as $requestItem) {
                /*
                 * Lock the inventory item so that another simultaneous
                 * approval cannot over-allocate the same inventory.
                 */
                $item = InventoryItem::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        $requestItem->inventory_item_id
                    );


                /*
                 * Recalculate availability using the exact requested
                 * borrowing period.
                 */
                $balance = $this->availability(
                    $item,
                    $reservationStart,
                    $version->return_due_at
                );


                $requested = (float) $requestItem->requested_quantity;


                /*
                 * Prevent allocation when:
                 *
                 * - item is not borrowable
                 * - requested quantity is invalid
                 * - current availability is insufficient
                 */
                if (
                    ! $item->borrowable
                    || $item->condition_code !== 'SERVICEABLE'
                    || $requested <= 0
                    || $balance['available'] < $requested
                ) {
                    throw ValidationException::withMessages([
                        'inventory' =>
                            "{$item->unique_description} has only "
                            .$balance['available']
                            .' available for the requested period. '
                            .'The verified approved request cannot be fulfilled as documented and must be returned for revision.',
                    ]);
                }


                /*
                 * Approved quantity remains exactly the quantity in the verified approved request.
                 * SPMU does not silently reduce it to match stock.
                 */
                $requestItem->update([
                    'approved_quantity' =>
                        $requestItem->requested_quantity,
                ]);


                /*
                 * Create active reservation. Internal ALLOCATED terminology is retained
                 * for compatibility; user-facing language is Reserved.
                 */
                $allocation = Allocation::query()->create([
                    'request_item_id' => $requestItem->id,
                    'period_start' => $reservationStart,
                    'period_end' => $version->return_due_at,
                    'allocated_quantity' =>
                        $requestItem->requested_quantity,
                    'released_quantity' => 0,
                    'restored_quantity' => 0,
                    'status' => 'ACTIVE',
                    'allocated_at' => now(),
                ]);


                $allocations[] = $allocation;


                /*
                 * Record inventory ledger movement:
                 *
                 * AVAILABLE -> ALLOCATED (displayed as RESERVED)
                 */
                DB::table(
                    'inventory_transaction_lines'
                )->insert([
                    'inventory_transaction_id' =>
                        $transactionId,

                    'inventory_item_id' =>
                        $item->id,

                    'from_state' =>
                        'AVAILABLE',

                    'to_state' =>
                        'ALLOCATED',

                    'quantity' =>
                        $requested,

                    'effective_from' =>
                        $reservationStart,

                    'effective_to' =>
                        $version->return_due_at,

                    'before_quantity' =>
                        $balance['available'],

                    'after_quantity' =>
                        $balance['available'] - $requested,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);
            }


            return $allocations;
        }, 3);
    }


    /**
     * Restore an allocation when an approved but unreleased request
     * is cancelled, expired, or otherwise released from reservation.
     */
    public function restore(
        BorrowingRequest $request,
        string $status,
        string $reason
    ): void {
        DB::transaction(
            function () use (
                $request,
                $status,
                $reason
            ): void {
                $request->loadMissing(
                    'currentVersion.items.allocation'
                );


                /*
                 * Create one inventory transaction header for this
                 * restoration operation.
                 */
                $transactionId = DB::table(
                    'inventory_transactions'
                )->insertGetId([
                    'actor_user_id' =>
                        auth()->id(),

                    'transaction_type' =>
                        'ALLOCATION_RESTORATION',

                    'source_type' =>
                        BorrowingRequest::class,

                    'source_id' =>
                        $request->id,

                    'reason' =>
                        $reason,

                    'correlation_id' =>
                        (string) Str::uuid(),

                    'occurred_at' =>
                        now(),

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]);


                foreach (
                    $request->currentVersion->items
                    as $requestItem
                ) {
                    $allocation =
                        $requestItem->allocation;


                    /*
                     * Ignore allocations that are already released,
                     * restored, cancelled, expired, or otherwise closed.
                     */
                    if (
                        ! $allocation
                        || ! in_array(
                            $allocation->status,
                            [
                                'ACTIVE',
                                'PARTIALLY_RELEASED',
                            ],
                            true
                        )
                    ) {
                        continue;
                    }


                    /*
                     * Calculate only the portion still reserved.
                     */
                    $remaining = max(
                        0,
                        (float) $allocation->allocated_quantity
                        - (float) $allocation->released_quantity
                        - (float) $allocation->restored_quantity
                    );


                    if ($remaining <= 0) {
                        continue;
                    }


                    /*
                     * Restore the remaining reservation.
                     */
                    $allocation->update([
                        'restored_quantity' =>
                            (float) $allocation->restored_quantity
                            + $remaining,

                        'status' =>
                            $status,
                    ]);


                    /*
                     * Record inventory ledger movement:
                     *
                     * ALLOCATED -> AVAILABLE
                     */
                    DB::table(
                        'inventory_transaction_lines'
                    )->insert([
                        'inventory_transaction_id' =>
                            $transactionId,

                        'inventory_item_id' =>
                            $requestItem->inventory_item_id,

                        'from_state' =>
                            'ALLOCATED',

                        'to_state' =>
                            'AVAILABLE',

                        'quantity' =>
                            $remaining,

                        'effective_from' =>
                            $allocation->period_start,

                        'effective_to' =>
                            $allocation->period_end,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);
                }
            },
            3
        );
    }
}
