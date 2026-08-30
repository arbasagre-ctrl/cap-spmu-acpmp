<?php

namespace App\Services;

use App\Models\Allocation;
use App\Models\BorrowingRequest;
use App\Models\InventoryItem;
use App\Models\RequestVersion;
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
        | AVAILABLE by internal Laundry completion so far (laundry_job_lines
        | .completed_quantity is only ever set by completeProcessing(), to
        | the cleaned portion — the damaged-during-wash portion is never
        | subtracted here, since it never moves to AVAILABLE either).
        |
        | Written with a portable CASE/COALESCE expression (no GREATEST()/
        | multi-arg MAX()) so it runs identically on MariaDB/MySQL
        | (production) and SQLite (the automated test suite).
        |
        | legacyLaundry and this query can never double-count the same
        | return line: a laundry_records row only exists when no LaundryJob
        | was created for that return, and a laundry_job_lines row only
        | exists when one was - the two are mutually exclusive per line.
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
            ->where(
                'request_items.inventory_item_id',
                $item->id
            )
            ->where(
                'return_lines.disposition_state',
                'LAUNDRY'
            )
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

        $incident = (float) DB::table('incident_lines')
            ->join(
                'incidents',
                'incidents.id',
                '=',
                'incident_lines.incident_id'
            )
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
            ->sum(
                'incident_lines.quantity'
            );


        /*
        |--------------------------------------------------------------------------
        | INCIDENT STATE BREAKDOWN
        |--------------------------------------------------------------------------
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
        );

        $borrowerAvailable = max(
            0,
            $serviceableTotal
            - $reserved
            - $borrowed
            - $laundry
            - $incident
        );

        $available = max(
            0,
            $serviceableTotal
            - $allocated
            - $borrowedForPeriod
            - $laundry
            - $incident
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
             * Incident state breakdown.
             */
            'damaged_maintenance' =>
                (float) (
                    $incidentStates['DAMAGED_MAINTENANCE']
                    ?? 0
                )
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
     * Reserve inventory only after SPMU verification/approval.
     *
     * @return list<Allocation>
     */
    public function allocate(RequestVersion $version): array
    {
        return DB::transaction(function () use ($version): array {
            $version->loadMissing(
                'items.inventoryItem'
            );

            $allocations = [];

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
                    $version->needed_from,
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
                    'period_start' => $version->needed_from,
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
                        $version->needed_from,

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
