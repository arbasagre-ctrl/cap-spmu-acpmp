<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AuditEvent;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\LaundryJob;
use App\Models\LaundryRecord;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\RequestVersion;
use App\Models\ReturnTransaction;
use App\Models\UnitOfMeasure;
use App\Services\AuditService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        $workspace = strtoupper((string) $request->user()->primaryWorkspace());
        $isBorrower = $workspace === 'BORROWER';

        /*
         * Borrower Inventory is a current, informational availability view only.
         * It must not imply or create a reservation.
         *
         * Current database mapping:
         * SERVICEABLE = Good / suitable for borrowing.
         */
        if ($isBorrower) {
            $from = now();
            $to = now()->addSecond();
        } else {
            $from = Carbon::parse(
                $request->input(
                    'from',
                    now()->addDay()->format('Y-m-d').' 08:00'
                )
            );

            $to = Carbon::parse(
                $request->input(
                    'to',
                    now()->addDays(7)->format('Y-m-d').' 17:00'
                )
            );

            if ($to->lte($from)) {
                $to = $from->copy()->addDay();
            }
        }

        $search = trim((string) $request->input('q', ''));
        $categoryId = $request->integer('category');

        $itemsQuery = InventoryItem::query()
            ->with(['category', 'unit'])
            ->when(
                $isBorrower,
                fn (Builder $query) => $query
                    ->where('active', true)
                    ->where('borrowable', true)
                    ->where('condition_code', 'SERVICEABLE')
            )
            ->when(
                $search !== '',
                function (Builder $query) use ($search): void {
                    $query->where(
                        function (Builder $inner) use ($search): void {
                            $inner
                                ->where(
                                    'unique_description',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'specification',
                                    'like',
                                    "%{$search}%"
                                );

                            if (preg_match('/^INV-?(\d+)$/i', $search, $match)) {
                                $inner->orWhereKey((int) $match[1]);
                            }
                        }
                    );
                }
            )
            ->when(
                $categoryId > 0,
                fn (Builder $query) => $query
                    ->where('category_id', $categoryId)
            )
            ->orderBy('unique_description');

        $items = $itemsQuery->get();

        /*
         * One batched inventory calculation for the whole visible catalogue.
         * portfolio() returns the same balance shape as availability(), but
         * avoids running the same grouped stock queries once per item.
         */
        $balances = collect($inventory->portfolio($items, $from, $to));

        /*
         * Borrowers must see only assets that are:
         * - active
         * - borrowable
         * - in Good/SERVICEABLE condition
         * - currently available in a positive quantity
         *
         * This is display filtering only. No reservation is created here.
         */
        if ($isBorrower) {
            $items = $items
                ->filter(
                    function (InventoryItem $item) use ($balances): bool {
                        $balance = $balances->get($item->id, []);

                        return (float) (
                            $balance['borrower_available']
                            ?? $balance['available']
                            ?? 0
                        ) > 0;
                    }
                )
                ->values();

            $visibleItemIds = $items
                ->pluck('id')
                ->all();

            $balances = $balances
                ->only($visibleItemIds);
        }

        $categories = InventoryCategory::query()
            ->where('active', true)
            ->when(
                $isBorrower,
                fn (Builder $query) => $query->whereHas(
                    'items',
                    fn (Builder $items) => $items
                        ->where('active', true)
                        ->where('borrowable', true)
                        ->where('condition_code', 'SERVICEABLE')
                )
            )
            ->orderBy('category_name')
            ->get();

        return view(
            'inventory.index',
            compact(
                'items',
                'balances',
                'from',
                'to',
                'categories',
                'search',
                'categoryId',
                'workspace'
            )
        );
    }

    public function show(
        Request $request,
        InventoryItem $inventory,
        InventoryService $service
    ): View {
        $workspace = strtoupper(
            (string) $request->user()->primaryWorkspace()
        );

        if (! in_array($workspace, ['BORROWER', 'SPMU'], true)) {
            abort(403);
        }

        if (! $inventory->active && $workspace === 'BORROWER') {
            abort(404);
        }

        $inventory->loadMissing(['category', 'unit']);

        $balance = $service->availability(
            $inventory,
            now(),
            now()->addSecond()
        );

        /*
         * Borrowers may open details only for inventory that is actually
         * visible in the Borrower Inventory list.
         */
        if ($workspace === 'BORROWER') {
            $available = (float) (
                $balance['borrower_available']
                ?? $balance['available']
                ?? 0
            );

            if (
                ! $inventory->borrowable
                || $inventory->condition_code !== 'SERVICEABLE'
                || $available <= 0
            ) {
                abort(404);
            }
        }

        /*
         * SPMU item-centric borrowing history.
         *
         * Only ACTUAL physical releases are included. Draft/submitted requests
         * and approved reservations that were never issued are intentionally
         * excluded. The selected period is treated as an overlap window so a
         * borrowing that started before the first date but remained physically
         * out during the window is still visible.
         */
        $historyFrom = null;
        $historyTo = null;
        $historySearch = '';
        $historyStatus = 'ALL';
        $borrowingHistory = collect();
        $stockCard = collect();
        $stockCardReferences = [];
        $lastInventoryActivityAt = null;
        $currentInventorySources = collect();
        $inventoryIssueSources = collect();
        $eligibleWriteOffSources = collect();
        $inventoryAdjustmentCapabilities = [];

        $historySummary = [
            'borrowers' => 0,
            'records' => 0,
            'issued' => 0.0,
            'returned' => 0.0,
            'outstanding' => 0.0,
        ];

        if ($workspace === 'SPMU') {
            $stockCard = DB::table('inventory_transaction_lines as line')
                ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
                ->leftJoin('users as actor', 'actor.id', '=', 'tx.actor_user_id')
                ->where('line.inventory_item_id', $inventory->id)
                ->orderByDesc('tx.occurred_at')
                ->limit(100)
                ->get([
                    'tx.id',
                    'tx.transaction_type',
                    'tx.reason',
                    'tx.occurred_at',
                    'actor.full_name as actor_name',
                    'actor.email as actor_email',
                    'tx.source_type',
                    'tx.source_id',
                    'line.from_state',
                    'line.to_state',
                    'line.quantity',
                    'line.before_quantity',
                    'line.after_quantity',
                ]);

            $stockCardReferences = $this->resolveStockCardReferences($stockCard);
            $lastInventoryActivityAt = $stockCard->first()?->occurred_at;

            /*
             * CURRENT INVENTORY SOURCE RECORDS
             * --------------------------------
             * These rows explain the live non-available states shown by
             * InventoryService. They are intentionally read-only and reuse the
             * same operational tables that feed the inventory balance instead
             * of creating a second inventory-status system.
             */
            $reservationSources = DB::table('allocations as allocation')
                ->join('request_items as item_line', 'item_line.id', '=', 'allocation.request_item_id')
                ->join('request_versions as version', 'version.id', '=', 'item_line.request_version_id')
                ->join('borrowing_requests as request_record', 'request_record.id', '=', 'version.request_id')
                ->leftJoin('users as borrower', 'borrower.id', '=', 'request_record.borrower_user_id')
                ->where('item_line.inventory_item_id', $inventory->id)
                ->whereIn('allocation.status', ['ACTIVE', 'PARTIALLY_RELEASED'])
                ->whereRaw('(COALESCE(allocation.allocated_quantity, 0) - COALESCE(allocation.released_quantity, 0) - COALESCE(allocation.restored_quantity, 0)) > 0')
                ->orderBy('allocation.period_start')
                ->get([
                    'allocation.id as allocation_id',
                    'allocation.status',
                    'allocation.period_start',
                    'allocation.period_end',
                    'request_record.id as request_id',
                    'request_record.request_no',
                    'borrower.full_name as borrower_name',
                    DB::raw('(COALESCE(allocation.allocated_quantity, 0) - COALESCE(allocation.released_quantity, 0) - COALESCE(allocation.restored_quantity, 0)) as source_quantity'),
                ])
                ->map(function ($row): array {
                    return [
                        'group' => 'RESERVED',
                        'group_label' => 'Reserved',
                        'reference' => $row->request_no ?: 'Request #'.$row->request_id,
                        'quantity' => (float) $row->source_quantity,
                        'status' => $row->status === 'PARTIALLY_RELEASED'
                            ? 'Partially released'
                            : 'Awaiting release',
                        'primary' => $row->borrower_name ?: 'Approved borrowing request',
                        'secondary' => $row->period_start
                            ? Carbon::parse($row->period_start)->format('d M Y').' – '.Carbon::parse($row->period_end)->format('d M Y')
                            : null,
                        'url' => $row->request_id
                            ? route('requests.show', ['borrowingRequest' => $row->request_id])
                            : null,
                        'action_label' => 'View Request',
                    ];
                });

            $custodySources = DB::table('custody_lines as custody_line')
                ->join('custody_transactions as custody', 'custody.id', '=', 'custody_line.custody_transaction_id')
                ->join('request_items as item_line', 'item_line.id', '=', 'custody_line.request_item_id')
                ->leftJoin('users as borrower', 'borrower.id', '=', 'custody.borrower_user_id')
                ->where('item_line.inventory_item_id', $inventory->id)
                ->whereNotNull('custody.released_at')
                ->whereIn('custody.status', [
                    'ACTIVE',
                    'RETURN_PROCESSING',
                    'OVERDUE',
                    'INCIDENT_OPEN',
                    'OBLIGATION_OPEN',
                ])
                ->whereRaw('(COALESCE(custody_line.actual_released_quantity, 0) - COALESCE(custody_line.returned_quantity, 0)) > 0')
                ->orderBy('custody.due_at')
                ->get([
                    'custody.id as custody_id',
                    'custody.custody_no',
                    'custody.status',
                    'custody.released_at',
                    'custody.due_at',
                    'borrower.full_name as borrower_name',
                    DB::raw('(COALESCE(custody_line.actual_released_quantity, 0) - COALESCE(custody_line.returned_quantity, 0)) as source_quantity'),
                ])
                ->map(function ($row): array {
                    $status = match ((string) $row->status) {
                        'OVERDUE' => 'Overdue',
                        'RETURN_PROCESSING' => 'Return processing',
                        'INCIDENT_OPEN' => 'Issue open',
                        'OBLIGATION_OPEN' => 'Obligation open',
                        default => 'On custody',
                    };

                    return [
                        'group' => 'CUSTODY',
                        'group_label' => 'On custody',
                        'reference' => $row->custody_no ?: 'Custody #'.$row->custody_id,
                        'quantity' => (float) $row->source_quantity,
                        'status' => $status,
                        'primary' => $row->borrower_name ?: 'Borrower custody',
                        'secondary' => $row->due_at
                            ? 'Expected return '.Carbon::parse($row->due_at)->format('d M Y')
                            : null,
                        'url' => $row->custody_id
                            ? route('custody.show', ['custody' => $row->custody_id])
                            : null,
                        'action_label' => 'View Custody',
                    ];
                });

            $laundrySources = DB::table('return_lines as return_line')
                ->join('custody_lines as custody_line', 'custody_line.id', '=', 'return_line.custody_line_id')
                ->join('request_items as item_line', 'item_line.id', '=', 'custody_line.request_item_id')
                ->join('custody_transactions as custody', 'custody.id', '=', 'custody_line.custody_transaction_id')
                ->leftJoin('users as borrower', 'borrower.id', '=', 'custody.borrower_user_id')
                ->leftJoin('laundry_job_lines as laundry_line', 'laundry_line.custody_line_id', '=', 'custody_line.id')
                ->leftJoin('laundry_jobs as laundry_job', 'laundry_job.id', '=', 'laundry_line.laundry_job_id')
                ->where('item_line.inventory_item_id', $inventory->id)
                ->where('return_line.disposition_state', 'LAUNDRY')
                ->whereRaw('(COALESCE(return_line.quantity_received, 0) - COALESCE(laundry_line.completed_quantity, 0)) > 0')
                ->orderByDesc('return_line.id')
                ->get([
                    'return_line.id as return_line_id',
                    'custody.id as custody_id',
                    'custody.custody_no',
                    'borrower.full_name as borrower_name',
                    'laundry_job.id as laundry_job_id',
                    'laundry_job.status as laundry_status',
                    DB::raw('(COALESCE(return_line.quantity_received, 0) - COALESCE(laundry_line.completed_quantity, 0)) as source_quantity'),
                ])
                ->map(function ($row): array {
                    $status = match ((string) $row->laundry_status) {
                        'FOR_LAUNDRY' => 'Laundry pending',
                        'TURNED_OVER_TO_LAUNDRY' => 'Reconciliation pending',
                        'LAUNDRY_COMPLETED' => 'Completed',
                        default => 'In laundry',
                    };

                    return [
                        'group' => 'LAUNDRY',
                        'group_label' => 'Laundry',
                        'reference' => $row->custody_no
                            ? 'Laundry · '.$row->custody_no
                            : 'Laundry return #'.$row->return_line_id,
                        'quantity' => (float) $row->source_quantity,
                        'status' => $status,
                        'primary' => $row->borrower_name ?: 'Returned item for laundry',
                        'secondary' => null,
                        // Inventory oversight is shared by the SPMU Head and Action Officer.
                        // Link to the shared custody detail instead of the AO-only Laundry
                        // workspace so the reference never returns 403 for the Head.
                        'url' => $row->custody_id
                            ? route('custody.show', ['custody' => $row->custody_id])
                            : null,
                        'action_label' => $row->custody_id ? 'View Custody' : null,
                    ];
                });

            $incidentClosures = DB::table('inventory_transaction_lines as line')
                ->join('inventory_transactions as tx', 'tx.id', '=', 'line.inventory_transaction_id')
                ->where('line.inventory_item_id', $inventory->id)
                ->where('tx.source_type', Incident::class)
                ->whereIn('tx.transaction_type', [
                    'INVENTORY_INCIDENT_RESTORATION',
                    'INVENTORY_INCIDENT_WRITE_OFF',
                ])
                ->groupBy('tx.source_id', 'line.from_state')
                ->select(
                    'tx.source_id as incident_id',
                    'line.from_state as disposition_state'
                )
                ->selectRaw('COALESCE(SUM(line.quantity), 0) as closed_quantity')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    ((int) $row->incident_id).'|'.strtoupper((string) $row->disposition_state)
                        => (float) $row->closed_quantity,
                ]);

            $incidentSources = DB::table('incident_lines as incident_line')
                ->join('incidents as incident', 'incident.id', '=', 'incident_line.incident_id')
                ->join('custody_lines as custody_line', 'custody_line.id', '=', 'incident_line.custody_line_id')
                ->join('request_items as item_line', 'item_line.id', '=', 'custody_line.request_item_id')
                ->join('custody_transactions as custody', 'custody.id', '=', 'custody_line.custody_transaction_id')
                ->leftJoin('users as borrower', 'borrower.id', '=', 'incident.borrower_user_id')
                ->where('item_line.inventory_item_id', $inventory->id)
                ->groupBy(
                    'incident.id',
                    'incident.incident_no',
                    'incident.status',
                    'incident.reported_at',
                    'incident_line.disposition_state',
                    'custody.id',
                    'custody.custody_no',
                    'borrower.full_name'
                )
                ->orderByDesc('incident.reported_at')
                ->get([
                    'incident.id as incident_id',
                    'incident.incident_no',
                    'incident.status as incident_status',
                    'incident.reported_at',
                    'incident_line.disposition_state',
                    'custody.id as custody_id',
                    'custody.custody_no',
                    'borrower.full_name as borrower_name',
                    DB::raw('COALESCE(SUM(incident_line.quantity), 0) as source_quantity'),
                ])
                ->map(function ($row) use ($incidentClosures): ?array {
                    $state = strtoupper((string) $row->disposition_state);
                    $remaining = max(
                        0,
                        (float) $row->source_quantity
                        - (float) ($incidentClosures[((int) $row->incident_id).'|'.$state] ?? 0)
                    );

                    if ($remaining <= 0) {
                        return null;
                    }

                    $condition = match ($state) {
                        'DAMAGED_MAINTENANCE' => 'Damaged / under repair',
                        'LOST' => 'Lost',
                        'STOLEN' => 'Stolen',
                        'DESTROYED' => 'Destroyed',
                        default => str((string) $row->disposition_state)->replace('_', ' ')->title()->toString(),
                    };

                    $accountabilityResolved = in_array(
                        strtoupper((string) $row->incident_status),
                        ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'],
                        true
                    );

                    return [
                        'group' => 'ISSUE',
                        'group_label' => 'Inventory exception',
                        'reference' => $row->incident_no ?: 'Incident #'.$row->incident_id,
                        'quantity' => $remaining,
                        'incident_id' => (int) $row->incident_id,
                        'incident_state' => $state,
                        'incident_status' => strtoupper((string) $row->incident_status),
                        'status' => $accountabilityResolved
                            ? 'Accountability resolved · Still unavailable'
                            : 'Accountability pending · Still unavailable',
                        'primary' => $condition,
                        'secondary' => collect([
                            $row->borrower_name,
                            $row->custody_no,
                        ])->filter()->join(' · '),
                        'url' => route('accountability.index'),
                        'action_label' => 'View Accountability',
                    ];
                })
                ->filter()
                ->values();

            $masterConditionSources = collect();
            if ($inventory->condition_code !== 'SERVICEABLE') {
                $masterConditionSources->push([
                    'group' => 'CONDITION',
                    'group_label' => 'Item condition',
                    'reference' => 'INV-'.str_pad((string) $inventory->id, 4, '0', STR_PAD_LEFT),
                    'quantity' => (float) $inventory->total_quantity,
                    'status' => $inventory->condition_code === 'CONDEMNED'
                        ? 'Condemned'
                        : 'Damaged / under repair',
                    'primary' => 'Master inventory condition',
                    'secondary' => 'Applies to the inventory item record.',
                    'url' => null,
                    'action_label' => null,
                ]);
            }

            $conditionHoldSources = collect();
            $conditionHoldQuantity = (float) ($balance['condition_hold'] ?? 0);
            if ($conditionHoldQuantity > 0) {
                $conditionHoldSources->push([
                    'group' => 'CONDITION_HOLD',
                    'group_label' => 'Maintenance hold',
                    'reference' => 'INV-'.str_pad((string) $inventory->id, 4, '0', STR_PAD_LEFT),
                    'quantity' => $conditionHoldQuantity,
                    'status' => 'Unavailable pending physical disposition',
                    'primary' => 'Admin-recorded maintenance hold',
                    'secondary' => 'Review the Stock Card for the adjustment history.',
                    'url' => route('inventory.show', [
                        'inventory' => $inventory->id,
                        'tab' => 'stock-card',
                    ]),
                    'action_label' => 'View Stock Card',
                ]);
            }

            $inventoryIssueSources = $incidentSources
                ->map(fn (array $source): array => [
                    'incident_id' => $source['incident_id'],
                    'incident_state' => $source['incident_state'],
                    'incident_status' => $source['incident_status'],
                    'reference' => $source['reference'],
                    'condition' => $source['primary'],
                    'quantity' => $source['quantity'],
                    'status' => $source['status'],
                ])
                ->values();

            $eligibleWriteOffSources = $inventoryIssueSources
                ->filter(fn (array $source): bool =>
                    in_array($source['incident_state'], ['DAMAGED_MAINTENANCE', 'LOST', 'STOLEN', 'DESTROYED'], true)
                    && in_array($source['incident_status'], ['RESOLVED', 'CLOSED'], true)
                )
                ->values();

            $inventoryAdjustmentCapabilities = $service->manualAdjustmentCapabilities($inventory, $balance);
            $inventoryAdjustmentCapabilities['can_write_off_retired'] = $eligibleWriteOffSources->isNotEmpty();

            $preparationDiscrepancySources = $this->currentPreparationDiscrepancySources($inventory);

            $currentInventorySources = collect()
                ->concat($preparationDiscrepancySources)
                ->concat($reservationSources)
                ->concat($custodySources)
                ->concat($laundrySources)
                ->concat($incidentSources)
                ->concat($conditionHoldSources)
                ->concat($masterConditionSources)
                ->values();

            $filters = $request->validate([
                'history_from' => ['nullable', 'date'],
                'history_to' => ['nullable', 'date'],
                'history_search' => ['nullable', 'string', 'max:120'],
                'history_status' => [
                    'nullable',
                    Rule::in([
                        'ALL',
                        'ON_CUSTODY',
                        'OVERDUE',
                        'RETURNED_ON_TIME',
                        'RETURNED_LATE',
                        'IN_LAUNDRY',
                    ]),
                ],
            ]);

            $historyFrom = Carbon::parse(
                $filters['history_from']
                    ?? now()->startOfMonth()->toDateString()
            )->startOfDay();

            $historyTo = Carbon::parse(
                $filters['history_to']
                    ?? now()->addMonthNoOverflow()->endOfMonth()->toDateString()
            )->endOfDay();

            if ($historyTo->lt($historyFrom)) {
                throw ValidationException::withMessages([
                    'history_to' =>
                        'History end date cannot be earlier than the start date.',
                ]);
            }

            $historySearch = trim(
                (string) ($filters['history_search'] ?? '')
            );

            $historyStatus = strtoupper(
                (string) ($filters['history_status'] ?? 'ALL')
            );

            if (! $inventory->laundry_required && $historyStatus === 'IN_LAUNDRY') {
                $historyStatus = 'ALL';
            }

            $historyQuery = CustodyTransaction::query()
                ->with([
                    'borrower.organizationalUnit',
                    'request.currentVersion',
                    'returns',
                    'laundryJob',
                    'incidents',
                    'lines' => function ($query) use ($inventory): void {
                        $query
                            ->where('actual_released_quantity', '>', 0)
                            ->whereHas(
                                'requestItem',
                                fn (Builder $requestItem) => $requestItem
                                    ->where(
                                        'inventory_item_id',
                                        $inventory->id
                                    )
                            )
                            ->with('requestItem');
                    },
                ])
                ->whereNotNull('released_at')
                ->where('released_at', '<=', $historyTo)
                ->where(function (Builder $query) use ($historyFrom): void {
                    $query
                        ->whereNull('closed_at')
                        ->orWhere('closed_at', '>=', $historyFrom);
                })
                ->whereHas(
                    'lines',
                    function (Builder $query) use ($inventory): void {
                        $query
                            ->where('actual_released_quantity', '>', 0)
                            ->whereHas(
                                'requestItem',
                                fn (Builder $requestItem) => $requestItem
                                    ->where(
                                        'inventory_item_id',
                                        $inventory->id
                                    )
                            );
                    }
                )
                ->when(
                    $historySearch !== '',
                    function (Builder $query) use ($historySearch): void {
                        $like = "%{$historySearch}%";

                        $query->where(
                            function (Builder $match) use ($like): void {
                                $match
                                    ->where('custody_no', 'like', $like)
                                    ->orWhereHas(
                                        'borrower',
                                        function (Builder $borrower) use ($like): void {
                                            $borrower
                                                ->where('full_name', 'like', $like)
                                                ->orWhereHas(
                                                    'organizationalUnit',
                                                    fn (Builder $unit) => $unit
                                                        ->where(
                                                            'unit_name',
                                                            'like',
                                                            $like
                                                        )
                                                );
                                        }
                                    )
                                    ->orWhereHas(
                                        'request',
                                        function (Builder $borrowingRequest) use ($like): void {
                                            $borrowingRequest
                                                ->where('request_no', 'like', $like)
                                                ->orWhereHas(
                                                    'currentVersion',
                                                    function (Builder $version) use ($like): void {
                                                        $version
                                                            ->where(
                                                                'purpose_event',
                                                                'like',
                                                                $like
                                                            )
                                                            ->orWhere(
                                                                'location',
                                                                'like',
                                                                $like
                                                            );
                                                    }
                                                );
                                        }
                                    );
                            }
                        );
                    }
                )
                ->orderByDesc('released_at');

            $custodies = $historyQuery->get();

            $lineIds = $custodies
                ->flatMap(fn (CustodyTransaction $custody) => $custody->lines)
                ->pluck('id')
                ->filter()
                ->values();

            $actualReturnDates = $lineIds->isEmpty()
                ? collect()
                : DB::table('return_lines')
                    ->join(
                        'return_transactions',
                        'return_transactions.id',
                        '=',
                        'return_lines.return_transaction_id'
                    )
                    ->whereIn('return_lines.custody_line_id', $lineIds)
                    ->whereNotNull('return_transactions.received_at')
                    ->select(
                        'return_lines.custody_line_id',
                        DB::raw('MAX(return_transactions.received_at) as actual_return_at')
                    )
                    ->groupBy('return_lines.custody_line_id')
                    ->pluck('actual_return_at', 'custody_line_id');

            $borrowingHistory = $custodies
                ->map(function (CustodyTransaction $custody) use (
                    $inventory,
                    $actualReturnDates
                ): ?array {
                    $line = $custody->lines->first(
                        fn ($candidate) =>
                            (int) $candidate->requestItem?->inventory_item_id
                                === (int) $inventory->id
                            && (float) $candidate->actual_released_quantity > 0
                    );

                    if (! $line) {
                        return null;
                    }

                    $issued = (float) $line->actual_released_quantity;
                    $returned = min(
                        $issued,
                        max(0, (float) $line->returned_quantity)
                    );
                    $outstanding = max(0, $issued - $returned);
                    $version = $custody->request?->currentVersion;

                    $expectedReturnValue = $custody->due_at
                        ?: $version?->return_date
                        ?: $version?->return_due_at;
                    $expectedReturnDate = $expectedReturnValue
                        ? Carbon::parse($expectedReturnValue)->startOfDay()
                        : null;

                    if ($inventory->laundry_required) {
                        $actualReturnAt = $custody->laundryJob?->worker_received_at
                            ? Carbon::parse($custody->laundryJob->worker_received_at)
                            : null;
                    } else {
                        $actualReturnValue = $actualReturnDates->get($line->id);
                        $actualReturnAt = $actualReturnValue
                            ? Carbon::parse($actualReturnValue)
                            : null;
                    }

                    /* Legacy closed rows may predate explicit receipt records. */
                    if (! $actualReturnAt && $outstanding <= 0 && $custody->closed_at) {
                        $actualReturnAt = Carbon::parse($custody->closed_at);
                    }

                    $laundryActive = (bool) $inventory->laundry_required
                        && $custody->laundryJob
                        && ! in_array(
                            (string) $custody->laundryJob->status,
                            ['LAUNDRY_COMPLETED'],
                            true
                        );

                    $itemStatus = match (true) {
                        $laundryActive => 'IN_LAUNDRY',
                        $outstanding > 0
                            && (
                                $custody->status === 'OVERDUE'
                                || ($expectedReturnDate && now()->startOfDay()->greaterThan($expectedReturnDate))
                            ) => 'OVERDUE',
                        $outstanding > 0 => 'ON_CUSTODY',
                        $actualReturnAt
                            && $expectedReturnDate
                            && $actualReturnAt->copy()->startOfDay()->greaterThan($expectedReturnDate) => 'RETURNED_LATE',
                        default => 'RETURNED_ON_TIME',
                    };

                    $activeAccountability = $custody->activeAccountabilityIndicator();
                    $hasIncidentHistory = $custody->incidents->isNotEmpty();
                    $accountabilityLabel = $activeAccountability['label']
                        ?? ($hasIncidentHistory ? 'Accountability resolved' : null);

                    return [
                        'custody' => $custody,
                        'line' => $line,
                        'borrower' => $custody->borrower,
                        'office' =>
                            $custody->borrower?->organizationalUnit?->unit_name,
                        'request_no' => $custody->request?->request_no,
                        'purpose' => $version?->purpose_event,
                        'location' => $version?->location,
                        'schedule_date' =>
                            $version?->schedule_date
                                ?: $version?->needed_from,
                        'expected_return_date' => $expectedReturnDate,
                        'released_at' => $custody->released_at,
                        'actual_return_at' => $actualReturnAt,
                        'closed_at' => $custody->closed_at,
                        'issued_quantity' => $issued,
                        'returned_quantity' => $returned,
                        'outstanding_quantity' => $outstanding,
                        'use_location' => $line->requestItem?->use_location,
                        'item_status' => $itemStatus,
                        'custody_status' => $custody->status,
                        'accountability_label' => $accountabilityLabel,
                        'accountability_active' => $activeAccountability !== null,
                    ];
                })
                ->filter()
                ->filter(function (array $row) use (
                    $historyFrom,
                    $historyTo,
                    $historyStatus
                ): bool {
                    $releasedAt = $row['released_at'];
                    $actualReturnAt = $row['actual_return_at'];

                    $overlaps = $releasedAt
                        && $releasedAt->lte($historyTo)
                        && (
                            ! $actualReturnAt
                            || $actualReturnAt->gte($historyFrom)
                        );

                    if (! $overlaps) {
                        return false;
                    }

                    return match ($historyStatus) {
                        'ON_CUSTODY' => $row['item_status'] === 'ON_CUSTODY',
                        'OVERDUE' => $row['item_status'] === 'OVERDUE',
                        'RETURNED_ON_TIME' => $row['item_status'] === 'RETURNED_ON_TIME',
                        'RETURNED_LATE' => $row['item_status'] === 'RETURNED_LATE',
                        'IN_LAUNDRY' => $row['item_status'] === 'IN_LAUNDRY',
                        default => true,
                    };
                })
                ->values();

            $historySummary = [
                'borrowers' => $borrowingHistory
                    ->pluck('borrower.id')
                    ->filter()
                    ->unique()
                    ->count(),
                'records' => $borrowingHistory->count(),
                'issued' => (float) $borrowingHistory->sum(
                    'issued_quantity'
                ),
                'returned' => (float) $borrowingHistory->sum(
                    'returned_quantity'
                ),
                'outstanding' => (float) $borrowingHistory->sum(
                    'outstanding_quantity'
                ),
            ];
        }

        return view('inventory.show', [
            'item' => $inventory,
            'balance' => $balance,
            'isBorrower' => $workspace === 'BORROWER',
            'isSpmu' => $workspace === 'SPMU',
            'historyFrom' => $historyFrom,
            'historyTo' => $historyTo,
            'historySearch' => $historySearch,
            'historyStatus' => $historyStatus,
            'borrowingHistory' => $borrowingHistory,
            'historySummary' => $historySummary,
            'stockCard' => $stockCard,
            'stockCardReferences' => $stockCardReferences,
            'lastInventoryActivityAt' => $lastInventoryActivityAt,
            'currentInventorySources' => $currentInventorySources,
            'inventoryIssueSources' => $inventoryIssueSources,
            'eligibleWriteOffSources' => $eligibleWriteOffSources,
            'inventoryAdjustmentCapabilities' => $inventoryAdjustmentCapabilities,
        ]);
    }

    /**
     * Open AO-reported preparation discrepancies for this inventory item.
     * This is visibility only: the Inventory record changes only through a
     * formal SPMU Head reconciliation action.
     */
    private function currentPreparationDiscrepancySources(InventoryItem $inventory)
    {
        $lineIds = DB::table('custody_lines as custody_line')
            ->join('request_items as request_item', 'request_item.id', '=', 'custody_line.request_item_id')
            ->where('request_item.inventory_item_id', $inventory->id)
            ->pluck('custody_line.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($lineIds === []) {
            return collect();
        }

        $reports = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->where('action_code', 'PREPARATION_ISSUE_REPORTED')
            ->orderByDesc('occurred_at')
            ->get()
            ->filter(fn (AuditEvent $event): bool => in_array(
                (int) data_get($event->after_json, 'custody_line_id'),
                $lineIds,
                true
            ));

        if ($reports->isEmpty()) {
            return collect();
        }

        $closedIds = AuditEvent::query()
            ->where('record_type', CustodyTransaction::class)
            ->whereIn('action_code', [
                'PREPARATION_ISSUE_RESOLVED',
                'PREPARATION_ISSUE_CLOSED_UNFULFILLED',
            ])
            ->get()
            ->map(fn (AuditEvent $event): int => (int) data_get(
                $event->after_json,
                'preparation_issue_event_id'
            ))
            ->filter()
            ->unique();

        $custodies = CustodyTransaction::query()
            ->whereIn('id', $reports->pluck('record_id')->filter()->unique())
            ->get(['id', 'custody_no'])
            ->keyBy('id');

        return $reports
            ->reject(fn (AuditEvent $event): bool => $closedIds->contains($event->id))
            ->map(function (AuditEvent $event) use ($custodies): array {
                $custody = $custodies->get((int) $event->record_id);
                $approved = (float) data_get($event->after_json, 'approved_quantity', 0);
                $ready = data_get($event->after_json, 'observed_usable_quantity');
                $secondary = collect([
                    data_get($event->after_json, 'issue_label'),
                    $approved > 0 ? 'Approved quantity '.($approved + 0) : null,
                    $ready !== null ? 'Physically ready '.((float) $ready + 0) : null,
                ])->filter()->join(' · ');

                return [
                    'group' => 'DISCREPANCY',
                    'group_label' => 'Preparation discrepancy',
                    'reference' => $custody?->custody_no ?: 'Release record #'.$event->record_id,
                    'quantity' => max(0, $approved),
                    'status' => 'Admin review required',
                    'primary' => (string) data_get($event->after_json, 'item_name', 'Inventory discrepancy'),
                    'secondary' => $secondary ?: null,
                    'url' => $custody
                        ? route('custody.show', ['custody' => $custody->id])
                        : null,
                    'action_label' => $custody ? 'View Release Record' : null,
                ];
            })
            ->values();
    }

    /**
     * Human-readable source references for the read-only Stock Card.
     *
     * Inventory transactions already store source_type/source_id. Exposing
     * those references here makes each movement traceable without changing
     * workflow data or creating a second audit trail.
     *
     * @return array<int, array{label: string, kind: string}>
     */
    private function resolveStockCardReferences($entries): array
    {
        $entries = collect($entries);
        $resolved = [];

        $groups = $entries
            ->filter(fn ($entry) => filled($entry->source_type) && $entry->source_id)
            ->groupBy('source_type');

        foreach ($groups as $sourceType => $rows) {
            $ids = $rows->pluck('source_id')->map(fn ($id) => (int) $id)->unique()->values();
            $records = collect();
            $kind = class_basename((string) $sourceType);

            if ($sourceType === RequestVersion::class) {
                $records = RequestVersion::query()
                    ->with('request:id,request_no')
                    ->whereIn('id', $ids)
                    ->get()
                    ->mapWithKeys(fn (RequestVersion $version) => [
                        $version->id => [
                            'label' => $version->request?->request_no ?: 'Request version #'.$version->id,
                            'url' => $version->request
                                ? route('requests.show', ['borrowingRequest' => $version->request->id])
                                : null,
                            'action_label' => 'View Request',
                        ],
                    ]);
                $kind = 'Borrowing request';
            } elseif ($sourceType === BorrowingRequest::class) {
                $records = BorrowingRequest::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'request_no'])
                    ->mapWithKeys(fn (BorrowingRequest $requestRecord) => [
                        $requestRecord->id => [
                            'label' => $requestRecord->request_no ?: 'Request #'.$requestRecord->id,
                            'url' => route('requests.show', ['borrowingRequest' => $requestRecord->id]),
                            'action_label' => 'View Request',
                        ],
                    ]);
                $kind = 'Borrowing request';
            } elseif ($sourceType === CustodyTransaction::class) {
                $records = CustodyTransaction::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'custody_no'])
                    ->mapWithKeys(fn (CustodyTransaction $custody) => [
                        $custody->id => [
                            'label' => $custody->custody_no ?: 'Custody #'.$custody->id,
                            'url' => route('custody.show', ['custody' => $custody->id]),
                            'action_label' => 'View Custody',
                        ],
                    ]);
                $kind = 'Custody';
            } elseif ($sourceType === ReturnTransaction::class) {
                $records = ReturnTransaction::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'return_no', 'custody_transaction_id'])
                    ->mapWithKeys(fn (ReturnTransaction $return) => [
                        $return->id => [
                            'label' => $return->return_no ?: 'Return #'.$return->id,
                            // The operational Return workspace is intentionally AO-only. Use the
                            // shared custody detail as the provenance target so SPMU Head
                            // inventory oversight remains read-only and never hits a 403.
                            'url' => $return->custody_transaction_id
                                ? route('custody.show', ['custody' => $return->custody_transaction_id])
                                : null,
                            'action_label' => 'View Return Record',
                        ],
                    ]);
                $kind = 'Physical return';
            } elseif ($sourceType === Incident::class) {
                $records = Incident::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'incident_no'])
                    ->mapWithKeys(fn (Incident $incident) => [
                        $incident->id => [
                            'label' => $incident->incident_no ?: 'Incident #'.$incident->id,
                            'url' => route('accountability.index'),
                            'action_label' => 'View Accountability',
                        ],
                    ]);
                $kind = 'Incident';
            } elseif ($sourceType === LaundryJob::class) {
                $records = LaundryJob::query()
                    ->with('custody:id,custody_no')
                    ->whereIn('id', $ids)
                    ->get()
                    ->mapWithKeys(fn (LaundryJob $job) => [
                        $job->id => [
                            'label' => $job->custody?->custody_no
                                ? 'Laundry · '.$job->custody->custody_no
                                : 'Laundry job #'.$job->id,
                            // Laundry operations are AO-only, while Inventory oversight is shared.
                            // Keep the source traceable through the shared custody detail.
                            'url' => $job->custody
                                ? route('custody.show', ['custody' => $job->custody->id])
                                : null,
                            'action_label' => $job->custody ? 'View Custody' : null,
                        ],
                    ]);
                $kind = 'Laundry';
            } elseif ($sourceType === InventoryItem::class) {
                $records = InventoryItem::query()
                    ->whereIn('id', $ids)
                    ->get(['id', 'unique_description'])
                    ->mapWithKeys(fn (InventoryItem $item) => [
                        $item->id => [
                            'label' => 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT),
                            'url' => route('inventory.show', ['inventory' => $item->id]),
                            'action_label' => 'View Item',
                        ],
                    ]);
                $kind = 'Inventory adjustment';
            } elseif ($sourceType === LaundryRecord::class) {
                $records = $ids->mapWithKeys(fn ($id) => [
                    $id => [
                        'label' => 'Laundry record #'.$id,
                        'url' => null,
                        'action_label' => null,
                    ],
                ]);
                $kind = 'Laundry';
            } else {
                $records = $ids->mapWithKeys(fn ($id) => [
                    $id => [
                        'label' => class_basename((string) $sourceType).' #'.$id,
                        'url' => null,
                        'action_label' => null,
                    ],
                ]);
            }

            foreach ($rows as $entry) {
                $record = $records->get((int) $entry->source_id, []);

                $resolved[(int) $entry->id] = [
                    'label' => (string) ($record['label'] ?? $kind.' #'.$entry->source_id),
                    'kind' => $kind,
                    'url' => $record['url'] ?? null,
                    'action_label' => $record['action_label'] ?? null,
                ];
            }
        }

        return $resolved;
    }

    public function create(Request $request): View
    {
        $this->authorizeInventoryAdministrator($request);

        return view('inventory.form', [
            'item' => new InventoryItem,
            'categories' => InventoryCategory::where(
                'active',
                true
            )->get(),
            'units' => UnitOfMeasure::where(
                'active',
                true
            )->get(),
        ]);
    }

    public function availabilityData(
        Request $request,
        InventoryService $inventory
    ): JsonResponse {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        /*
         * The borrowing-request availability lookup should expose only
         * active, borrowable, serviceable inventory.
         */
        $items = InventoryItem::query()
            ->where('active', true)
            ->where('borrowable', true)
            ->where('condition_code', 'SERVICEABLE')
            ->get();

        return response()->json(
            $items->mapWithKeys(
                fn (InventoryItem $item) => [
                    $item->id => $inventory->availability(
                        $item,
                        $from,
                        $to
                    ),
                ]
            )
        );
    }

    public function store(
        Request $request,
        AuditService $audit,
        InventoryService $inventoryService
    ): RedirectResponse {
        $this->authorizeInventoryAdministrator($request);

        $data = $this->validated($request);

        $item = InventoryItem::query()->create($data);
        $inventoryService->recordInitialStock(
            $item,
            $request->user(),
            $request->input('initial_stock_source')
        );

        $audit->record(
            'INVENTORY_ITEM_CREATED',
            $item,
            reason: filled($request->input('initial_stock_source'))
                ? 'Initial inventory item created. Source / Reference: '.trim((string) $request->input('initial_stock_source'))
                : 'Initial inventory item created.',
            after: $item->toArray()
        );

        return redirect()
            ->route('inventory.index')
            ->with(
                'status',
                'Inventory item created.'
            );
    }

    public function edit(
        Request $request,
        InventoryItem $inventory
    ): View {
        $this->authorizeInventoryAdministrator($request);

        return view('inventory.form', [
            'item' => $inventory,
            'categories' => InventoryCategory::where(
                'active',
                true
            )->get(),
            'units' => UnitOfMeasure::where(
                'active',
                true
            )->get(),
        ]);
    }

    public function update(
        Request $request,
        InventoryItem $inventory,
        InventoryService $service,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeInventoryAdministrator($request);

        $data = $this->validated(
            $request,
            $inventory
        );

        $balance = $service->availability(
            $inventory,
            now()->subYears(10),
            now()->addYears(10)
        );

        $committed =
            $balance['reserved']
            + $balance['borrowed']
            + $balance['laundry']
            + $balance['incident']
            + ($balance['condition_hold'] ?? 0);

        if (! $data['active'] && $inventory->active && $committed > 0) {
            throw ValidationException::withMessages([
                'active' =>
                    "This item cannot be archived while {$committed} unit(s) are reserved, on custody, in laundry, or under an inventory exception.",
            ]);
        }

        $before = $inventory->toArray();

        $inventory->update($data);

        $audit->record(
            'INVENTORY_ITEM_UPDATED',
            $inventory,
            reason: $request->input('change_reason'),
            before: $before,
            after: $inventory->fresh()->toArray()
        );

        return redirect()
            ->route('inventory.index')
            ->with(
                'status',
                'Inventory item updated with an audit record.'
            );
    }

    public function adjust(
        Request $request,
        InventoryItem $inventory,
        InventoryService $service,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeInventoryAdministrator($request);

        $data = $request->validate([
            'action' => [
                'required',
                Rule::in([
                    'STOCK_ADDITION',
                    'PHYSICAL_COUNT_CORRECTION',
                    'PLACE_UNDER_MAINTENANCE',
                    'RETURN_MAINTENANCE_TO_SERVICE',
                    'RETIRE_MAINTENANCE_STOCK',
                    'WRITE_OFF_RETIRED',
                ]),
            ],
            'incident_source' => ['nullable', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
            'new_total_quantity' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $requiredReasonActions = [
            'STOCK_ADDITION',
            'PHYSICAL_COUNT_CORRECTION',
            'PLACE_UNDER_MAINTENANCE',
            'RETIRE_MAINTENANCE_STOCK',
            'WRITE_OFF_RETIRED',
        ];

        if (in_array($data['action'], $requiredReasonActions, true) && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => match ($data['action']) {
                    'STOCK_ADDITION' => 'Enter the source or reference for the newly received stock.',
                    'PHYSICAL_COUNT_CORRECTION' => 'Enter the reason for the physical count correction.',
                    'PLACE_UNDER_MAINTENANCE' => 'Enter the verified condition or reason for the maintenance hold.',
                    'RETIRE_MAINTENANCE_STOCK' => 'Enter the retirement or condemnation basis.',
                    'WRITE_OFF_RETIRED' => 'Enter the approved write-off basis.',
                    default => 'Enter the required inventory adjustment basis.',
                },
            ]);
        }

        if (filled($data['incident_source'] ?? null)) {
            if (! preg_match('/^(\d+)\|([A-Z_]+)$/', (string) $data['incident_source'], $match)) {
                throw ValidationException::withMessages([
                    'incident_source' => 'Select a valid accountability incident.',
                ]);
            }

            $data['incident_id'] = (int) $match[1];
            $data['incident_state'] = (string) $match[2];
        }

        $before = $inventory->fresh()->toArray();
        $result = $service->recordAdjustment($inventory, $request->user(), $data);
        $fresh = $inventory->fresh();

        $auditReason = filled($data['reason'] ?? null)
            ? trim((string) $data['reason'])
            : ($data['action'] === 'RETURN_MAINTENANCE_TO_SERVICE'
                ? 'Maintenance stock returned to service after physical verification.'
                : $result['action_label'].' recorded.');

        $audit->record(
            'INVENTORY_ADJUSTMENT_RECORDED',
            $fresh,
            reason: $auditReason,
            before: $before,
            after: array_merge($fresh->toArray(), ['adjustment' => $result])
        );

        return redirect()
            ->route('inventory.show', ['inventory' => $fresh->id, 'tab' => 'overview'])
            ->with('status', $result['action_label'].' recorded in the Stock Card.');
    }

    private function authorizeInventoryAdministrator(Request $request): void
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403,
            'Only the SPMU Head / Administrator may create or edit inventory items.'
        );
    }

    private function validated(
        Request $request,
        ?InventoryItem $item = null
    ): array {
        $data = $request->validate([
            'category_id' => [
                'required',
                'exists:inventory_categories,id',
            ],
            'unit_id' => [
                'required',
                'exists:units_of_measure,id',
            ],
            'unique_description' => [
                'required',
                'string',
                'max:255',
                Rule::unique('inventory_items')
                    ->where(
                        fn ($query) => $query->where(
                            'category_id',
                            $request->integer('category_id')
                        )
                    )
                    ->ignore($item?->id),
            ],
            'specification' => [
                'nullable',
                'string',
            ],
            'change_reason' => $item
                ? ['required', 'string', 'max:1000']
                : ['nullable', 'string', 'max:1000'],
            'initial_stock_source' => $item
                ? ['nullable']
                : ['nullable', 'string', 'max:1000'],
        ]);

        if ($item) {
            // Physical stock and condition are reconciled from Inventory Overview
            // so every change produces a formal Stock Card movement.
            $data['total_quantity'] = $item->total_quantity;
            $data['condition_code'] = $item->condition_code;
        } else {
            $stockData = $request->validate([
                'total_quantity' => ['required', 'integer', 'min:0'],
                'condition_code' => [
                    'required',
                    Rule::in([
                        'SERVICEABLE',
                        'DAMAGED_MAINTENANCE',
                        'CONDEMNED',
                    ]),
                ],
            ]);

            $data = array_merge($data, $stockData);
        }

        $data['borrowable'] = $request->boolean(
            'borrowable'
        );

        $data['off_campus_allowed'] = $request->boolean(
            'off_campus_allowed'
        );

        $data['laundry_required'] = $request->boolean(
            'laundry_required'
        );

        $data['provisional'] = $request->boolean(
            'provisional'
        );

        $data['active'] = $request->boolean('active');

        unset($data['change_reason'], $data['initial_stock_source']);

        if (
            $data['off_campus_allowed']
            && strcasecmp(
                $data['unique_description'],
                'Barricade'
            ) !== 0
        ) {
            throw ValidationException::withMessages([
                'off_campus_allowed' =>
                    'Current policy permits off-campus use only for Barricade.',
            ]);
        }

        return $data;
    }
}
