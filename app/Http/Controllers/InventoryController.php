<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\CustodyTransaction;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
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
            ->where('active', true)
            ->when(
                $isBorrower,
                fn (Builder $query) => $query
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

        $balances = $items->mapWithKeys(
            fn (InventoryItem $item) => [
                $item->id => $inventory->availability(
                    $item,
                    $from,
                    $to
                ),
            ]
        );

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

        if (! $inventory->active) {
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
                    'actor.email as actor_email',
                    'line.from_state',
                    'line.to_state',
                    'line.quantity',
                    'line.before_quantity',
                    'line.after_quantity',
                ]);

            $filters = $request->validate([
                'history_from' => ['nullable', 'date'],
                'history_to' => ['nullable', 'date'],
                'history_search' => ['nullable', 'string', 'max:120'],
                'history_status' => [
                    'nullable',
                    Rule::in(['ALL', 'OPEN', 'RETURNED', 'OVERDUE']),
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

            $historyQuery = CustodyTransaction::query()
                ->with([
                    'borrower.organizationalUnit',
                    'request.currentVersion',
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
                    ->select(
                        'return_lines.custody_line_id',
                        DB::raw(
                            'MAX(return_transactions.received_at) as actual_return_at'
                        )
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

                    $actualReturnAt = $actualReturnDates->get($line->id);
                    $actualReturnAt = $actualReturnAt
                        ? Carbon::parse($actualReturnAt)
                        : null;

                    $itemStatus = match (true) {
                        $outstanding <= 0 => 'RETURNED',
                        $custody->status === 'OVERDUE' => 'OVERDUE',
                        default => 'ON_CUSTODY',
                    };

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
                        'expected_return_date' =>
                            $version?->return_date
                                ?: $custody->due_at
                                ?: $version?->return_due_at,
                        'released_at' => $custody->released_at,
                        'actual_return_at' => $actualReturnAt,
                        'closed_at' => $custody->closed_at,
                        'issued_quantity' => $issued,
                        'returned_quantity' => $returned,
                        'outstanding_quantity' => $outstanding,
                        'use_location' => $line->requestItem?->use_location,
                        'item_status' => $itemStatus,
                        'custody_status' => $custody->status,
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
                        'OPEN' => $row['outstanding_quantity'] > 0,
                        'RETURNED' => $row['outstanding_quantity'] <= 0,
                        'OVERDUE' => $row['item_status'] === 'OVERDUE',
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
        ]);
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
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeInventoryAdministrator($request);

        $data = $this->validated($request);

        $item = InventoryItem::query()->create($data);

        $audit->record(
            'INVENTORY_ITEM_CREATED',
            $item,
            reason: $request->input('change_reason'),
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
            $balance['allocated']
            + $balance['borrowed']
            + $balance['laundry']
            + $balance['incident'];

        if ((float) $data['total_quantity'] < $committed) {
            throw ValidationException::withMessages([
                'total_quantity' =>
                    "Total quantity cannot be reduced below the active commitment of {$committed}.",
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
            'total_quantity' => [
                'required',
                'numeric',
                'min:0',
            ],
            'condition_code' => [
                'required',
                Rule::in([
                    'SERVICEABLE',
                    'DAMAGED_MAINTENANCE',
                    'CONDEMNED',
                ]),
            ],
            'change_reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

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

        $data['active'] = $request->boolean(
            'active',
            true
        );

        unset($data['change_reason']);

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
