<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\InventoryItem;
use App\Support\AnalyticsDrilldown;
use App\Support\OrganizationalStructure;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The internal Analytics detail behind a figure.
 *
 * Clicking a number in Analytics is a question about that number, not a
 * request to leave for the records module. This service answers the question
 * in place: what the metric means, what it is made of, and what the records
 * behind it look like. Reports stays one deliberate step further on, offered
 * as a secondary action once the reader has understood the figure.
 *
 * Every detail returns the same shape, so a single partial renders all of
 * them and there is no second detail system to maintain. Calculations are
 * reused from AnalyticsService rather than restated here; only the
 * record-level listings, which no chart needed before, are queried here.
 */
class AnalyticsDetailService
{
    /** Records listed inside a detail before it stops being a summary. */
    public const RECORD_LIMIT = 25;

    /** Detail keys the panel can resolve. */
    public const TYPES = [
        'requests',
        'currently-out',
        'follow-up',
        'low-availability',
        'equipment',
        'division',
        'unit',
        'returns',
        'borrower',
        'coverage',
        'forecast',
        'trend',
    ];

    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly InventoryService $inventory,
        private readonly ForecastService $forecasts,
    ) {}

    /**
     * Resolve one detail, or null when the key is not offered.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(
        string $type,
        Request $request,
        CarbonInterface $from,
        CarbonInterface $to,
        ?string $division,
        ?string $unit,
        string $periodSelection
    ): ?array {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $scope = [
            'from' => $from,
            'to' => $to,
            'division' => $division,
            'unit' => $unit,
            'period' => $periodSelection,
        ];

        $detail = match ($type) {
            'requests' => $this->requests($scope),
            'currently-out' => $this->currentlyOut($scope),
            'follow-up' => $this->followUp($scope),
            'low-availability' => $this->lowAvailability($scope),
            'equipment' => $this->equipment($scope, (string) $request->input('item', '')),
            'division' => $this->division($scope, (string) $request->input('for', '')),
            'unit' => $this->unit($scope, (string) $request->input('for', '')),
            'returns' => $this->returns($scope, (string) $request->input('state', 'on-time')),
            'borrower' => $this->borrower($scope, (string) $request->input('for', '')),
            'coverage' => $this->coverage($scope, (string) $request->input('item', '')),
            'forecast' => $this->forecast($scope, (string) $request->input('metric', 'demand')),
            'trend' => $this->trend($scope, (int) $request->input('bucket', 0)),
        };

        if ($detail === null) {
            return null;
        }

        return array_merge(
            [
                'type' => $type,
                'period_label' => $from->format('d M Y').' – '.$to->format('d M Y'),
                'filters' => $this->filterLabel($division, $unit),
                'stats' => [],
                'table' => null,
                'bars' => null,
                'note' => null,
                'empty' => null,
                'reports_url' => null,
                'reports_label' => 'View source records in Reports',
            ],
            $detail
        );
    }

    /* ------------------------------------------------------------------ */
    /* Overview figures                                                    */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $scope */
    private function requests(array $scope): array
    {
        $overview = $this->analytics->overview(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $counts = $this->analytics
            ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
            ->select('borrowing_requests.status')
            ->selectRaw('COUNT(borrowing_requests.id) AS total')
            ->groupBy('borrowing_requests.status')
            ->pluck('total', 'status');

        $stats = [['label' => 'Filed activity', 'value' => $overview['total']]];

        foreach ($counts as $status => $total) {
            $case = $status instanceof RequestStatus
                ? $status
                : RequestStatus::tryFrom((string) $status);

            $stats[] = [
                'label' => $case?->label() ?? (string) $status,
                'value' => (int) $total,
            ];
        }

        $groups = $this->analytics->borrowerGroups(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return [
            'title' => 'Requests',
            'value' => $overview['total'],
            'context' => 'Filed borrowing demand and review workload',
            'note' => 'Request Activity measures filed borrowing demand and review workload, '
                .'including requests that were rejected. It is not a measure of actual asset usage. '
                .'Drafts, cancellations and expired requests are excluded.',
            'stats' => $stats,
            'bars' => $groups['total'] > 0
                ? $groups['groups']->map(fn (array $group): array => [
                    'label' => $group['label'],
                    'value' => $group['count'].' · '.$group['percentage'].'%',
                    'share' => $group['percentage'],
                ])->all()
                : null,
            'bars_title' => 'By division',
            'empty' => $overview['total'] === 0
                ? 'No borrowing requests were filed during this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function currentlyOut(array $scope): array
    {
        $rows = $this->currentCustody($scope)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->with(['borrower', 'request', 'lines'])
            ->orderBy('due_at')
            ->limit(self::RECORD_LIMIT)
            ->get();

        $total = $this->currentCustody($scope)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->count();

        return [
            'title' => 'Currently Out',
            'value' => $total,
            'context' => 'Current physical custody, as of today',
            'note' => 'Equipment that was physically released and has not yet been returned. '
                .'This is current state: it does not depend on when the request was filed.',
            'table' => $rows->isEmpty() ? null : [
                'columns' => ['Borrower', 'Custody No.', 'Released', 'Quantity', 'Due', 'Status'],
                'rows' => $rows->map(fn (CustodyTransaction $custody): array => [
                    (string) ($custody->borrower?->full_name ?? ''),
                    (string) $custody->custody_no,
                    $this->date($custody->released_at),
                    $this->quantity($custody),
                    $this->date($custody->due_at),
                    $this->custodyLabel($custody->status),
                ])->all(),
            ],
            'empty' => $total === 0 ? 'No equipment is currently out on custody.' : null,
            'reports_url' => AnalyticsDrilldown::custody(
                $scope['period'], $scope['division'], $scope['unit'], ['custody_status' => 'ACTIVE']
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function followUp(array $scope): array
    {
        $query = fn () => $this->currentCustody($scope)
            ->whereNotNull('released_at')
            ->whereNull('closed_at')
            ->whereNotIn('status', ['CLOSED', 'CANCELLED'])
            ->where(function ($inner): void {
                $inner->where('status', 'OVERDUE')
                    ->orWhere(function ($due): void {
                        $due->whereNotNull('due_at')->where('due_at', '<', now());
                    });
            });

        $rows = $query()
            ->with(['borrower', 'request'])
            ->orderBy('due_at')
            ->limit(self::RECORD_LIMIT)
            ->get();

        $total = $query()->count();

        return [
            'title' => 'Need Follow-up',
            'value' => $total,
            'context' => 'Currently overdue custody, as of today',
            'note' => 'OVERDUE means the equipment has not come back after its effective due date. '
                .'It is a separate measure from a LATE RETURN, which is equipment that did come back, '
                .'only after its due date. The two are never combined.',
            'table' => $rows->isEmpty() ? null : [
                'columns' => ['Borrower', 'Custody No.', 'Due', 'Days overdue', 'Issue', 'State'],
                'rows' => $rows->map(function (CustodyTransaction $custody): array {
                    $days = $custody->due_at
                        ? (int) $custody->due_at->startOfDay()->diffInDays(now()->startOfDay())
                        : null;

                    return [
                        (string) ($custody->borrower?->full_name ?? ''),
                        (string) $custody->custody_no,
                        $this->date($custody->due_at),
                        $days === null ? '—' : (string) max(0, $days),
                        'Not yet returned',
                        $this->custodyLabel($custody->status),
                    ];
                })->all(),
            ],
            'empty' => $total === 0
                ? 'No borrowing is currently overdue.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => 'CURRENTLY_OVERDUE']
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function lowAvailability(array $scope): array
    {
        $low = $this->analytics->lowAvailability($this->inventory, self::RECORD_LIMIT);

        return [
            'title' => 'Low Availability',
            'value' => $low['count'],
            'context' => 'Current inventory status, as of today',
            'note' => 'Borrowable equipment whose usable stock is at or below '
                .((int) ($low['threshold'] * 100)).'% of its serviceable total. '
                .'Units currently out, reserved, in laundry or held by an incident are already '
                .'excluded by the Inventory module.',
            'table' => $low['items'] === [] ? null : [
                'columns' => ['Item', 'Serviceable Total', 'Currently Available', 'Availability', 'Status'],
                'rows' => collect($low['items'])->map(fn (array $row): array => [
                    $row['name'],
                    (string) $row['stock'],
                    (string) $row['available'],
                    $row['share'].'%',
                    $row['status'],
                ])->all(),
            ],
            'empty' => $low['count'] === 0 ? $low['summary'] : null,
            'reports_url' => AnalyticsDrilldown::inventory(
                $scope['period'], ['availability_status' => 'FULLY_COMMITTED']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Equipment                                                           */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $scope */
    private function equipment(array $scope, string $itemId): ?array
    {
        $item = InventoryItem::query()->find($itemId);

        if (! $item) {
            return null;
        }

        $requested = (float) DB::table('request_items')
            ->whereIn(
                'request_items.request_version_id',
                $this->analytics
                    ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
                    ->select('request_versions.id')
            )
            ->where('request_items.inventory_item_id', $item->id)
            ->sum('request_items.requested_quantity');

        $appearances = DB::table('request_items')
            ->whereIn(
                'request_items.request_version_id',
                $this->analytics
                    ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
                    ->select('request_versions.id')
            )
            ->where('request_items.inventory_item_id', $item->id)
            ->count();

        $released = (float) DB::table('custody_lines')
            ->join('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
            ->join('request_items', 'request_items.id', '=', 'custody_lines.request_item_id')
            ->whereNotNull('custody_transactions.released_at')
            ->whereBetween('custody_transactions.released_at', [$scope['from'], $scope['to']])
            ->where('request_items.inventory_item_id', $item->id)
            ->sum('custody_lines.actual_released_quantity');

        $balance = $this->inventory->portfolio(
            collect([$item]), now()->startOfDay(), now()->endOfDay()
        )[$item->id] ?? [];

        return [
            'title' => $item->unique_description,
            'value' => $released + 0,
            'value_label' => 'units released',
            'context' => 'Equipment detail',
            'note' => 'EXPRESSED DEMAND is what was requested. ACTUAL USAGE is what was physically '
                .'released. They are different measures and are never combined.',
            'stats' => [
                ['label' => 'Requested quantity (expressed demand)', 'value' => $requested + 0],
                ['label' => 'Appears in requests', 'value' => $appearances],
                ['label' => 'Released quantity (actual usage)', 'value' => $released + 0],
                ['label' => 'Currently available', 'value' => (float) ($balance['current_available'] ?? 0) + 0],
            ],
            'empty' => ($requested <= 0 && $released <= 0)
                ? 'This item was neither requested nor released during this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::utilization(
                $scope['period'], $scope['division'], $scope['unit'], ['equipment' => $item->id]
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function coverage(array $scope, string $itemId): ?array
    {
        $coverage = $this->analytics->stockCoverage(
            $this->inventory, $scope['from'], $scope['to'], 200
        );

        $row = collect($coverage['items'])->firstWhere('item_id', (int) $itemId);

        if (! $row) {
            return null;
        }

        $stats = [
            ['label' => 'Current usable stock', 'value' => $row['available']],
            ['label' => 'Observed usage window', 'value' => $coverage['window_days'].' days'],
            ['label' => 'Release events observed', 'value' => $row['releases']],
        ];

        if ($row['sufficient']) {
            $stats[] = ['label' => 'Average daily usage', 'value' => $row['per_day']];
            $stats[] = ['label' => 'Estimated days of coverage', 'value' => $row['days_cover'] ?? '—'];
            $stats[] = ['label' => 'Risk classification', 'value' => ($row['risk'] ?? '—').' Risk'];
        }

        return [
            'title' => $row['name'],
            'value' => $row['sufficient'] ? ($row['days_cover'] ?? 0) : null,
            'value_label' => $row['sufficient'] ? 'estimated days of coverage' : null,
            'context' => 'Stock coverage estimate',
            'note' => $row['sufficient']
                ? 'An estimate, not a probability. It assumes usage continues at the observed rate.'
                : 'Insufficient usage history. At least '
                    .$coverage['requirement']['window_days'].' observed days and '
                    .$coverage['requirement']['releases'].' separate release events are required '
                    .'before stock coverage is estimated, so no daily rate, coverage or risk '
                    .'classification is shown for this item.',
            'stats' => $stats,
            'reports_url' => AnalyticsDrilldown::utilization(
                $scope['period'], null, null, ['equipment' => $row['item_id']]
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Organisation                                                        */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $scope */
    private function division(array $scope, string $code): ?array
    {
        if (! array_key_exists($code, OrganizationalStructure::DIVISIONS)) {
            return null;
        }

        return $this->organisation(
            $scope,
            OrganizationalStructure::label($code),
            'Division detail',
            $code,
            null
        );
    }

    /** @param array<string, mixed> $scope */
    private function unit(array $scope, string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }

        return $this->organisation($scope, $name, 'Unit detail', $scope['division'], $name);
    }

    /** @param array<string, mixed> $scope */
    private function organisation(
        array $scope,
        string $title,
        string $context,
        ?string $division,
        ?string $unit
    ): array {
        $overview = $this->analytics->overview($scope['from'], $scope['to'], $division, $unit);
        $requested = $this->analytics->requestedEquipment($scope['from'], $scope['to'], $division, $unit, 5);
        $released = $this->analytics->equipment($scope['from'], $scope['to'], $division, $unit, 5);
        $returns = $this->analytics->returns($scope['from'], $scope['to'], $division, $unit);

        return [
            'title' => $title,
            'value' => $overview['total'],
            'value_label' => 'requests filed',
            'context' => $context,
            'stats' => [
                ['label' => 'Currently out', 'value' => $overview['on_custody']],
                ['label' => 'Returned on time', 'value' => $returns['on_time']],
                ['label' => 'Returned late', 'value' => $returns['late']],
                ['label' => 'Currently overdue', 'value' => $returns['overdue']],
            ],
            'bars' => $released['items'] === [] ? null : collect($released['items'])
                ->map(fn (array $row): array => [
                    'label' => $row['name'],
                    'value' => $row['released'].' '.$row['unit'],
                    'share' => $row['share'],
                ])->all(),
            'bars_title' => 'Most actually used (released quantity)',
            'table' => $requested['items'] === [] ? null : [
                'columns' => ['Most requested item', 'Requests', 'Requested quantity'],
                'rows' => collect($requested['items'])->map(fn (array $row): array => [
                    $row['name'],
                    (string) $row['requests'],
                    (string) $row['quantity'],
                ])->all(),
            ],
            'empty' => $overview['total'] === 0
                ? 'No borrowing requests were filed here during this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing($scope['period'], $division, $unit),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function borrower(array $scope, string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }

        $requests = $this->analytics
            ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
            ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
            ->where('users.full_name', $name)
            ->with(['currentVersion'])
            ->orderByDesc('borrowing_requests.created_at')
            ->limit(self::RECORD_LIMIT)
            ->get(['borrowing_requests.*']);

        return [
            'title' => $name,
            'value' => $requests->count(),
            'value_label' => 'requests filed',
            'context' => 'Borrower detail',
            'note' => 'Filed borrowing activity for this borrower in the selected period.',
            'table' => $requests->isEmpty() ? null : [
                'columns' => ['Request No.', 'Purpose', 'Office / Unit', 'Filed', 'Status'],
                'rows' => $requests->map(fn (BorrowingRequest $row): array => [
                    (string) $row->request_no,
                    (string) ($row->currentVersion?->purpose_event ?? ''),
                    (string) ($row->currentVersion?->office_unit ?? ''),
                    $this->date($row->created_at),
                    $row->status->label(),
                ])->all(),
            ],
            'empty' => $requests->isEmpty()
                ? 'No borrowing requests were filed by this borrower during this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Returns                                                             */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $scope */
    private function returns(array $scope, string $state): array
    {
        $returns = $this->analytics->returns(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        return match ($state) {
            'late' => $this->closedReturns($scope, $returns, true),
            'overdue' => array_merge($this->followUp($scope), [
                'title' => 'Currently Overdue',
                'value' => $returns['overdue'],
            ]),
            'accountability' => $this->openAccountability($scope, $returns),
            default => $this->closedReturns($scope, $returns, false),
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $returns
     */
    private function closedReturns(array $scope, array $returns, bool $late): array
    {
        $query = $this->analytics
            ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
            ->select('borrowing_requests.id');

        $rows = CustodyTransaction::query()
            ->whereIn('request_id', $query)
            ->whereNotNull('released_at')
            ->whereNotNull('closed_at')
            ->when(
                $late,
                fn ($inner) => $inner->whereNotNull('due_at')->whereColumn('closed_at', '>', 'due_at'),
                fn ($inner) => $inner->where(function ($either): void {
                    $either->whereNull('due_at')->orWhereColumn('closed_at', '<=', 'due_at');
                })
            )
            ->with(['borrower'])
            ->orderByDesc('closed_at')
            ->limit(self::RECORD_LIMIT)
            ->get();

        return [
            'title' => $late ? 'Returned Late' : 'Returned On Time',
            'value' => $late ? $returns['late'] : $returns['on_time'],
            'context' => 'Completed returns in this reporting period',
            'note' => $late
                ? 'A LATE RETURN is finished: the equipment came back, only after its effective due '
                    .'date. Equipment that has not come back at all is counted separately as OVERDUE.'
                : 'Completed returns closed on or before the effective due date.',
            'table' => $rows->isEmpty() ? null : [
                'columns' => ['Borrower', 'Custody No.', 'Due', 'Returned', $late ? 'Days late' : 'Result'],
                'rows' => $rows->map(function (CustodyTransaction $custody) use ($late): array {
                    $days = $custody->due_at && $custody->closed_at
                        ? (int) $custody->due_at->startOfDay()->diffInDays($custody->closed_at->startOfDay())
                        : null;

                    return [
                        (string) ($custody->borrower?->full_name ?? ''),
                        (string) $custody->custody_no,
                        $this->date($custody->due_at),
                        $this->date($custody->closed_at),
                        $late ? (string) max(0, $days ?? 0) : 'On time',
                    ];
                })->all(),
            ],
            'empty' => ($late ? $returns['late'] : $returns['on_time']) === 0
                ? ($late
                    ? 'No late returns were recorded for this reporting period.'
                    : 'No on-time returns were recorded for this reporting period.')
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['return_status' => $late ? 'RETURNED_LATE' : 'RETURNED_ON_TIME']
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $returns
     */
    private function openAccountability(array $scope, array $returns): array
    {
        $custodyIds = CustodyTransaction::query()
            ->whereIn(
                'request_id',
                $this->analytics
                    ->requestScope($scope['from'], $scope['to'], $scope['division'], $scope['unit'])
                    ->select('borrowing_requests.id')
            )
            ->select('id');

        $incidents = Incident::query()
            ->whereIn('custody_transaction_id', $custodyIds)
            ->whereNotIn('status', ['RESOLVED', 'CLOSED', 'VOID_CORRECTION'])
            ->with(['borrower', 'custody'])
            ->orderByDesc('reported_at')
            ->limit(self::RECORD_LIMIT)
            ->get();

        return [
            'title' => 'Open Accountability',
            'value' => $returns['open_cases'],
            'context' => 'Unresolved incident or billing case',
            'table' => $incidents->isEmpty() ? null : [
                'columns' => ['Incident No.', 'Borrower', 'Custody No.', 'Type', 'Reported', 'Status'],
                'rows' => $incidents->map(fn (Incident $incident): array => [
                    (string) $incident->incident_no,
                    (string) ($incident->borrower?->full_name ?? ''),
                    (string) ($incident->custody?->custody_no ?? ''),
                    str((string) $incident->incident_type)->replace('_', ' ')->title()->toString(),
                    $this->date($incident->reported_at),
                    str((string) $incident->status)->replace('_', ' ')->title()->toString(),
                ])->all(),
            ],
            'empty' => $returns['open_cases'] === 0
                ? 'No accountability case is open for this reporting period.'
                : null,
            'reports_url' => AnalyticsDrilldown::returns(
                $scope['period'], $scope['division'], $scope['unit'],
                ['open_accountability' => 'OPEN']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Predictive                                                          */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $scope */
    private function forecast(array $scope, string $metric): array
    {
        [$forecastFrom, $forecastTo] = $this->forecasts->forecastWindow($scope['from'], $scope['to']);

        if ($metric === 'equipment') {
            return $this->equipmentForecast($scope, $forecastFrom, $forecastTo);
        }

        if ($metric === 'busy') {
            return $this->busyForecast($scope, $forecastFrom, $forecastTo);
        }

        $demand = $this->forecasts->demand(
            $this->analytics, $scope['from'], $scope['to'], $scope['division'], $scope['unit']
        );

        $history = $demand['history'] ?? [];
        $available = $demand['available'] ?? false;

        return [
            'title' => 'Forecasted Demand',
            'value' => $available ? $demand['forecast'] : null,
            'value_label' => $available ? 'projected requests' : null,
            'context' => 'Projected for '.$forecastFrom->format('d M').' – '.$forecastTo->format('d M Y'),
            'note' => $available
                ? 'A weighted moving average over completed comparable periods. Deterministic '
                    .'arithmetic, not a trained model. This is an estimate, not a guarantee.'
                : ($demand['reason'] ?? '').' '.($demand['requirement'] ?? ''),
            'stats' => $available ? [
                ['label' => 'Current period', 'value' => $demand['current']],
                ['label' => 'Projected next period', 'value' => $demand['forecast']],
                ['label' => 'Direction', 'value' => ucfirst($demand['direction'])],
            ] : [],
            'table' => $history === [] ? null : [
                'columns' => ['Historical period', 'Observed requests', 'Weight'],
                'rows' => collect($history)->map(fn (array $row): array => [
                    $row['label'],
                    (string) $row['count'],
                    (string) $row['weight'],
                ])->all(),
            ],
            'formula' => $available ? $this->weightedFormula($history, $demand['forecast']) : null,
            'empty' => (! $available && $history === [])
                ? 'No completed comparable periods have been recorded yet.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function equipmentForecast(array $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $forecast = $this->forecasts->equipment($scope['from'], $scope['to']);
        $items = $forecast['items'] ?? [];

        return [
            'title' => 'Equipment Shortage Risk',
            'value' => $forecast['at_risk_count'] ?? 0,
            'value_label' => 'equipment types to watch',
            'context' => 'Projected for '.$from->format('d M').' – '.$to->format('d M Y'),
            'note' => 'Expected availability comes from the Inventory module and already excludes '
                .'reserved units, equipment still out, linen under Laundry Operations, and units '
                .'held by an incident.',
            'table' => $items === [] ? null : [
                'columns' => ['Item', 'Forecast demand', 'Expected availability', 'Outlook'],
                'rows' => collect($items)->map(fn (array $row): array => [
                    $row['name'],
                    (string) $row['demand'],
                    (string) $row['expected_available'],
                    $row['status'],
                ])->all(),
            ],
            'empty' => $items === []
                ? ($forecast['reason'] ?? $forecast['summary'] ?? 'Not enough historical data to forecast equipment demand.')
                : null,
            'reports_url' => AnalyticsDrilldown::utilization($scope['period']),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function busyForecast(array $scope, CarbonInterface $from, CarbonInterface $to): array
    {
        $busy = $this->forecasts->busyPeriod($this->analytics, $scope['from'], $scope['to']);
        $available = $busy['available'] ?? false;

        return [
            'title' => 'Expected Busy Period',
            'value' => null,
            'context' => 'Projected for '.$from->format('d M').' – '.$to->format('d M Y'),
            'note' => $available
                ? 'The shape comes from history: the average share each slice of a period has '
                    .'carried before is applied to the projected total.'
                : 'Not enough historical data to project how demand falls across the next period.',
            'table' => $available ? [
                'columns' => ['Slice', 'Range', 'Expected requests', 'Level'],
                'rows' => collect($busy['buckets'])->map(fn (array $row): array => [
                    $row['label'],
                    $row['range'],
                    (string) $row['expected'],
                    $row['level'],
                ])->all(),
            ] : null,
            'empty' => $available ? null : 'Not enough historical data yet.',
            'reports_url' => AnalyticsDrilldown::borrowing($scope['period']),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function trend(array $scope, int $bucket): ?array
    {
        $trend = $this->analytics->trend(
            $scope['from'], $scope['to'], $scope['division'], $scope['unit'], $scope['period']
        );

        $point = $trend['points'][$bucket] ?? null;

        if ($point === null) {
            return null;
        }

        return [
            'title' => $point['label'],
            'value' => $point['count'],
            'value_label' => 'requests filed',
            'context' => 'Borrowing activity, grouped by '.$trend['granularity'],
            'note' => 'Counts requests filed, not released quantity, so it does not describe asset usage.',
            'empty' => $point['count'] === 0
                ? 'No borrowing requests were filed in this '.$trend['granularity'].'.'
                : null,
            'reports_url' => AnalyticsDrilldown::borrowing(
                $scope['period'], $scope['division'], $scope['unit']
            ),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** @param list<array<string, mixed>> $history */
    private function weightedFormula(array $history, int $forecast): string
    {
        /* Oldest first in the table; the formula reads most recent first. */
        $ordered = array_reverse($history);

        $numerator = collect($ordered)
            ->map(fn (array $row): string => $row['count'].'×'.$row['weight'])
            ->implode(' + ');

        $weights = collect($ordered)->sum('weight');
        $raw = collect($ordered)->sum(fn (array $row): int => $row['count'] * $row['weight']);

        return '('.$numerator.') ÷ '.$weights.' = '
            .round($raw / max(1, $weights), 2).' ≈ '.$forecast;
    }

    /** @param array<string, mixed> $scope */
    private function currentCustody(array $scope): \Illuminate\Database\Eloquent\Builder
    {
        $division = $scope['division'];
        $unit = $scope['unit'];

        $query = CustodyTransaction::query();

        if ($division === null && $unit === null) {
            return $query;
        }

        return $query->whereIn(
            'request_id',
            BorrowingRequest::query()
                ->join('request_versions', function ($join): void {
                    $join->on('request_versions.request_id', '=', 'borrowing_requests.id')
                        ->on('request_versions.version_no', '=', 'borrowing_requests.current_version_no');
                })
                ->when($division !== null, fn ($inner) => $inner->where('request_versions.division_code', $division))
                ->when($unit !== null, fn ($inner) => $inner->where('request_versions.office_unit', $unit))
                ->select('borrowing_requests.id')
        );
    }

    private function quantity(CustodyTransaction $custody): string
    {
        $released = (float) $custody->lines->sum(
            fn ($line): float => (float) $line->actual_released_quantity
        );

        return rtrim(rtrim(number_format($released, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function custodyLabel(?string $status): string
    {
        return match ($status) {
            'PREPARING_RELEASE' => 'Preparing Release',
            'ACTIVE' => 'Released / On Custody',
            'RETURN_PROCESSING' => 'Return Processing',
            'PARTIALLY_RETURNED' => 'Partially Returned',
            'OVERDUE' => 'Overdue',
            'INCIDENT_OPEN' => 'Incident Open',
            'OBLIGATION_OPEN' => 'Obligation Open',
            'CLOSED' => 'Completed',
            default => str((string) $status)->replace('_', ' ')->title()->toString(),
        };
    }

    private function filterLabel(?string $division, ?string $unit): ?string
    {
        $parts = array_filter([
            $division ? OrganizationalStructure::label($division) : null,
            $unit,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function date(mixed $value): string
    {
        return $value ? Carbon::parse($value)->format('d M Y') : '—';
    }
}
