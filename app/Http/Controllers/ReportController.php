<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\AcademicPeriod;
use App\Models\BillingStatement;
use App\Models\BorrowerViolation;
use App\Models\BorrowingRequest;
use App\Models\CustodyTransaction;
use App\Models\Incident;
use App\Models\IncidentLine;
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Models\Sanction;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        $academicPeriods = AcademicPeriod::query()
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->get();

        $activeAcademicPeriod = $academicPeriods->first(
            fn (AcademicPeriod $period): bool =>
                $period->status === 'ACTIVE'
        );

        [
            $from,
            $to,
            $selectedAcademicPeriod,
            $periodSelection,
        ] = $this->resolveReportingPeriod(
            $request,
            $academicPeriods,
            $activeAcademicPeriod
        );

        $activeTab = strtolower((string) $request->input('tab', 'analytics')) === 'reports'
            ? 'reports'
            : 'analytics';

        if ($activeTab === 'reports') {
            return $this->reportsTab(
                $request,
                $inventory,
                $from,
                $to,
                $academicPeriods,
                $activeAcademicPeriod,
                $selectedAcademicPeriod,
                $periodSelection
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Inventory snapshot
        |--------------------------------------------------------------------------
        |
        | This remains a current operational inventory snapshot. The selected
        | date range controls activity/performance reports below, not the
        | physical inventory quantities.
        |
        */

        $items = InventoryItem::query()
            ->with([
                'category',
                'unit',
            ])
            ->where('active', true)
            ->orderBy('unique_description')
            ->get();

        $balances = $items->mapWithKeys(
            fn ($item) => [
                $item->id => $inventory->availability(
                    $item,
                    now()->subYears(10)->startOfDay(),
                    now()->addYears(10)->endOfDay()
                ),
            ]
        );

        $inventoryHealth = [
            'total' => $balances->sum(
                fn (array $balance) => (float) (
                    $balance['total']
                    ?? 0
                )
            ),

            'physical_available' => $balances->sum(
                fn (array $balance) => (float) (
                    $balance['current_available']
                    ?? $balance['available']
                    ?? 0
                )
            ),

            'allocated' => $balances->sum(
                fn (array $balance) => (float) (
                    $balance['allocated']
                    ?? $balance['reserved']
                    ?? 0
                )
            ),

            'on_custody' => $balances->sum(
                fn (array $balance) => (float) (
                    $balance['borrowed']
                    ?? 0
                )
            ),

            'laundry' => $balances->sum(
                fn (array $balance) => (float) (
                    $balance['laundry']
                    ?? 0
                )
            ),

            'incident_unavailable' => $balances->sum(
                fn (array $balance) =>
                    (float) (
                        $balance['damaged_maintenance']
                        ?? 0
                    )
                    + (float) (
                        $balance['lost']
                        ?? 0
                    )
                    + (float) (
                        $balance['stolen']
                        ?? 0
                    )
                    + (float) (
                        $balance['destroyed']
                        ?? 0
                    )
                    + (float) (
                        $balance['condemned']
                        ?? 0
                    )
            ),
        ];

        /*
        |--------------------------------------------------------------------------
        | Request and custody activity
        |--------------------------------------------------------------------------
        */

        $requestStatuses = BorrowingRequest::query()
            ->whereBetween(
                'created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                'status, COUNT(*) AS total'
            )
            ->groupBy('status')
            ->pluck(
                'total',
                'status'
            );

        $custodyStatuses = CustodyTransaction::query()
            ->whereBetween(
                'created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                'status, COUNT(*) AS total'
            )
            ->groupBy('status')
            ->pluck(
                'total',
                'status'
            );

        $totalRequests = (int) $requestStatuses->sum();

        $requestStatusSnapshotCounts = BorrowingRequest::query()
            ->with('custody')
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->map(fn (BorrowingRequest $row): string => $this->requestReportStatus($row)[0])
            ->countBy();

        $requestStatusSnapshot = [
            'total' => $totalRequests,
            'obligation_open' => (int) ($requestStatusSnapshotCounts['OBLIGATION_OPEN'] ?? 0),
            'completed' => (int) ($requestStatusSnapshotCounts['COMPLETED'] ?? 0),
            'draft' => (int) ($requestStatusSnapshotCounts['DRAFT'] ?? 0),
        ];

        /*
         * Request Status Overview drill-down. These cards stay on the Analytics
         * page and filter the records immediately below them. Custody status
         * remains authoritative once physical custody exists.
         */
        $allowedStatusFocus = [
            'ALL',
            'DRAFT',
            'OBLIGATION_OPEN',
            'COMPLETED',
        ];

        $statusFocus = strtoupper(trim((string) $request->input('status_focus', '')));
        if (! in_array($statusFocus, $allowedStatusFocus, true)) {
            $statusFocus = null;
        }

        $statusRecordCollection = collect();

        if ($statusFocus) {
            $statusRecordCollection = BorrowingRequest::query()
                ->with([
                    'borrower',
                    'currentVersion',
                    'custody',
                ])
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('created_at')
                ->get();

            if ($statusFocus !== 'ALL') {
                $statusRecordCollection = $statusRecordCollection
                    ->filter(
                        fn (BorrowingRequest $row): bool =>
                            $this->requestReportStatus($row)[0] === $statusFocus
                    )
                    ->values();
            }
        }

        $statusPage = max(1, (int) $request->input('status_page', 1));
        $statusPerPage = 10;

        $requestStatusRecords = new LengthAwarePaginator(
            $statusRecordCollection->forPage($statusPage, $statusPerPage)->values(),
            $statusRecordCollection->count(),
            $statusPerPage,
            $statusPage,
            [
                'path' => route('reports.index'),
                'pageName' => 'status_page',
            ]
        );

        $requestStatusRecords->appends(
            $request->except('status_page')
        );

        $requestOutcomes = collect([
            'Approved' =>
                (int) ($requestStatuses['APPROVED_READY_FOR_RELEASE'] ?? 0)
                + (int) ($requestStatuses['FINAL_APPROVED_AWAITING_DOWNLOAD'] ?? 0),

            'Under Review' =>
                (int) ($requestStatuses['SUBMITTED'] ?? 0)
                + (int) ($requestStatuses['UNDER_SPMU'] ?? 0)
                + (int) ($requestStatuses['UNDER_GSU'] ?? 0)
                + (int) ($requestStatuses['UNDER_VPAF'] ?? 0),

            'Returned for Revision' =>
                (int) ($requestStatuses['RETURNED_FOR_REVISION'] ?? 0),

            'Rejected' =>
                (int) ($requestStatuses['REJECTED'] ?? 0),

            'Cancelled / Expired' =>
                (int) ($requestStatuses['CANCELLED'] ?? 0)
                + (int) ($requestStatuses['EXPIRED'] ?? 0),

            'Draft / Preparing' =>
                (int) ($requestStatuses['DRAFT'] ?? 0)
                + (int) ($requestStatuses['SIGNED'] ?? 0),
        ])->filter(
            fn (int $total) => $total > 0
        );

        /*
        |--------------------------------------------------------------------------
        | SPMU decision performance
        |--------------------------------------------------------------------------
        */

        $approvalDecisionCounts = DB::table('approval_steps')
            ->where(
                'stage_code',
                'SPMU'
            )
            ->whereNotNull('decided_at')
            ->whereBetween(
                'decided_at',
                [
                    $from,
                    $to,
                ]
            )
            ->whereIn(
                'decision',
                [
                    'APPROVED',
                    'REJECTED',
                    'RETURNED_FOR_REVISION',
                ]
            )
            ->selectRaw(
                'decision, COUNT(*) AS total'
            )
            ->groupBy('decision')
            ->pluck(
                'total',
                'decision'
            );

        $approvedDecisionCount =
            (int) (
                $approvalDecisionCounts['APPROVED']
                ?? 0
            );

        $decidedRequestCount =
            (int) $approvalDecisionCounts->sum();

        $approvalRate =
            $decidedRequestCount > 0
                ? round(
                    $approvedDecisionCount
                    / $decidedRequestCount
                    * 100,
                    2
                )
                : 0.0;

        $approvalDurations = DB::table('approval_steps')
            ->where(
                'stage_code',
                'SPMU'
            )
            ->whereNotNull('received_at')
            ->whereNotNull('decided_at')
            ->whereBetween(
                'decided_at',
                [
                    $from,
                    $to,
                ]
            )
            ->get([
                'received_at',
                'decided_at',
            ])
            ->map(
                function ($step): int {
                    $receivedAt = Carbon::parse(
                        $step->received_at
                    );

                    $decidedAt = Carbon::parse(
                        $step->decided_at
                    );

                    return max(
                        0,
                        $receivedAt->diffInSeconds(
                            $decidedAt,
                            false
                        )
                    );
                }
            );

        $averageApprovalSeconds =
            $approvalDurations->isEmpty()
                ? 0
                : (int) round(
                    $approvalDurations->avg()
                );

        $releasedBorrowings = CustodyTransaction::query()
            ->whereNotNull('released_at')
            ->whereBetween(
                'released_at',
                [
                    $from,
                    $to,
                ]
            )
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Accountability indicators
        |--------------------------------------------------------------------------
        */

        $openAccountabilityCustodyIds = OverdueCase::query()
            ->where(
                'status',
                '!=',
                'RESOLVED'
            )
            ->pluck(
                'custody_transaction_id'
            )
            ->merge(
                Incident::query()
                    ->where(
                        'status',
                        '!=',
                        'RESOLVED'
                    )
                    ->pluck(
                        'custody_transaction_id'
                    )
            )
            ->filter()
            ->unique()
            ->values();

        $openAccountabilityCount =
            $openAccountabilityCustodyIds->count();

        $overdueCount = OverdueCase::query()
            ->whereBetween(
                'created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->count();

        $repeatOffenders = BorrowerViolation::query()
            ->where(
                'status',
                'CONFIRMED'
            )
            ->whereBetween(
                'detected_at',
                [
                    $from,
                    $to,
                ]
            )
            ->select(
                'borrower_user_id'
            )
            ->groupBy(
                'borrower_user_id'
            )
            ->havingRaw(
                'COUNT(*) > 1'
            )
            ->get()
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Utilization
        |--------------------------------------------------------------------------
        */

        $utilizationItems = InventoryItem::query()
            ->leftJoin(
                'request_items',
                'request_items.inventory_item_id',
                '=',
                'inventory_items.id'
            )
            ->leftJoin(
                'custody_lines',
                'custody_lines.request_item_id',
                '=',
                'request_items.id'
            )
            ->leftJoin(
                'custody_transactions',
                'custody_transactions.id',
                '=',
                'custody_lines.custody_transaction_id'
            )
            ->where(
                'inventory_items.active',
                true
            )
            ->selectRaw(
                '
                    inventory_items.id,
                    inventory_items.unique_description,
                    COALESCE(
                        SUM(
                            CASE
                                WHEN custody_transactions.released_at
                                    BETWEEN ? AND ?
                                THEN custody_lines.actual_released_quantity
                                ELSE 0
                            END
                        ),
                        0
                    ) AS used_quantity
                ',
                [
                    $from,
                    $to,
                ]
            )
            ->groupBy(
                'inventory_items.id',
                'inventory_items.unique_description'
            )
            ->get();

        $topItems = $utilizationItems
            ->sortByDesc(
                fn ($item) => (float) $item->used_quantity
            )
            ->values()
            ->take(10);

        $leastUtilizedItems = $utilizationItems
            ->sortBy(
                fn ($item) => (float) $item->used_quantity
            )
            ->values()
            ->take(5);

        /*
        |--------------------------------------------------------------------------
        | Borrower activity
        |--------------------------------------------------------------------------
        */

        $mostFrequentBorrowers = BorrowingRequest::query()
            ->join(
                'users',
                'users.id',
                '=',
                'borrowing_requests.borrower_user_id'
            )
            ->whereBetween(
                'borrowing_requests.created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                '
                    users.id,
                    users.full_name,
                    COUNT(borrowing_requests.id) AS borrowing_count
                '
            )
            ->groupBy(
                'users.id',
                'users.full_name'
            )
            ->orderByDesc(
                'borrowing_count'
            )
            ->limit(10)
            ->get();

        $borrowersWithMostLateReturns = OverdueCase::query()
            ->join(
                'users',
                'users.id',
                '=',
                'overdue_cases.borrower_user_id'
            )
            ->whereBetween(
                'overdue_cases.created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                '
                    users.id,
                    users.full_name,
                    COUNT(overdue_cases.id) AS late_return_count
                '
            )
            ->groupBy(
                'users.id',
                'users.full_name'
            )
            ->orderByDesc(
                'late_return_count'
            )
            ->limit(10)
            ->get();

        $borrowersWithMostViolations = BorrowerViolation::query()
            ->join(
                'users',
                'users.id',
                '=',
                'borrower_violations.borrower_user_id'
            )
            ->where(
                'borrower_violations.status',
                'CONFIRMED'
            )
            ->whereBetween(
                'borrower_violations.detected_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                '
                    users.id,
                    users.full_name,
                    COUNT(borrower_violations.id) AS violation_count
                '
            )
            ->groupBy(
                'users.id',
                'users.full_name'
            )
            ->orderByDesc(
                'violation_count'
            )
            ->limit(10)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Asset risk
        |--------------------------------------------------------------------------
        */

        $assetConditionTrends = IncidentLine::query()
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
            ->join(
                'inventory_items',
                'inventory_items.id',
                '=',
                'request_items.inventory_item_id'
            )
            ->whereBetween(
                'incidents.reported_at',
                [
                    $from,
                    $to,
                ]
            )
            ->selectRaw(
                '
                    inventory_items.unique_description,
                    incident_lines.observed_condition,
                    SUM(incident_lines.quantity) AS affected_quantity
                '
            )
            ->groupBy(
                'inventory_items.id',
                'inventory_items.unique_description',
                'incident_lines.observed_condition'
            )
            ->orderByDesc(
                'affected_quantity'
            )
            ->limit(20)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Borrowing activity trend
        |--------------------------------------------------------------------------
        */

        $periodDays =
            $from->copy()
                ->startOfDay()
                ->diffInDays(
                    $to->copy()->startOfDay()
                )
            + 1;

        $trendGranularity = match (true) {
            $periodDays <= 14 =>
                'day',

            $periodDays <= 120 =>
                'week',

            default =>
                'month',
        };

        $bucketStart = function (
            Carbon $date
        ) use (
            $trendGranularity
        ): Carbon {
            return match ($trendGranularity) {
                'day' =>
                    $date->copy()->startOfDay(),

                'week' =>
                    $date->copy()->startOfWeek(
                        Carbon::MONDAY
                    ),

                default =>
                    $date->copy()->startOfMonth(),
            };
        };

        $bucketStep = function (
            Carbon $date
        ) use (
            $trendGranularity
        ): Carbon {
            return match ($trendGranularity) {
                'day' =>
                    $date->addDay(),

                'week' =>
                    $date->addWeek(),

                default =>
                    $date->addMonth(),
            };
        };

        $bucketLabel = function (
            Carbon $date
        ) use (
            $trendGranularity
        ): string {
            return match ($trendGranularity) {
                'day' =>
                    $date->format('d M'),

                'week' =>
                    'Week of '.$date->format('d M'),

                default =>
                    $date->format('M Y'),
            };
        };

        $trend = collect();

        $cursor = $bucketStart(
            $from->copy()
        );

        $lastBucket = $bucketStart(
            $to->copy()
        );

        while ($cursor->lte($lastBucket)) {
            $key = $cursor->toDateString();

            $trend->put(
                $key,
                [
                    'key' => $key,
                    'label' => $bucketLabel(
                        $cursor
                    ),
                    'requests' => 0,
                    'released' => 0,
                ]
            );

            $bucketStep(
                $cursor
            );
        }

        BorrowingRequest::query()
            ->whereBetween(
                'created_at',
                [
                    $from,
                    $to,
                ]
            )
            ->get([
                'created_at',
            ])
            ->each(
                function (
                    BorrowingRequest $borrowingRequest
                ) use (
                    $trend,
                    $bucketStart
                ): void {
                    $key = $bucketStart(
                        Carbon::parse(
                            $borrowingRequest->created_at
                        )
                    )->toDateString();

                    if (! $trend->has($key)) {
                        return;
                    }

                    $row = $trend->get($key);
                    $row['requests']++;
                    $trend->put(
                        $key,
                        $row
                    );
                }
            );

        CustodyTransaction::query()
            ->whereNotNull('released_at')
            ->whereBetween(
                'released_at',
                [
                    $from,
                    $to,
                ]
            )
            ->get([
                'released_at',
            ])
            ->each(
                function (
                    CustodyTransaction $custody
                ) use (
                    $trend,
                    $bucketStart
                ): void {
                    $key = $bucketStart(
                        Carbon::parse(
                            $custody->released_at
                        )
                    )->toDateString();

                    if (! $trend->has($key)) {
                        return;
                    }

                    $row = $trend->get($key);
                    $row['released']++;
                    $trend->put(
                        $key,
                        $row
                    );
                }
            );

        $borrowingTrend = $trend
            ->values();

        $returnCompliance = $this->returnCompliance(
            $from,
            $to
        );

        return view(
            'reports.index',
            [
                'items' =>
                    $items,

                'balances' =>
                    $balances,

                'inventoryHealth' =>
                    $inventoryHealth,

                'requestStatuses' =>
                    $requestStatuses,

                'requestOutcomes' =>
                    $requestOutcomes,

                'custodyStatuses' =>
                    $custodyStatuses,

                'totalRequests' =>
                    $totalRequests,

                'requestStatusSnapshot' =>
                    $requestStatusSnapshot,

                'statusFocus' =>
                    $statusFocus,

                'requestStatusRecords' =>
                    $requestStatusRecords,

                'recordReportStatus' =>
                    fn (BorrowingRequest $row): array => $this->requestReportStatus($row),

                'approvalRate' =>
                    $approvalRate,

                'approvedDecisionCount' =>
                    $approvedDecisionCount,

                'decidedRequestCount' =>
                    $decidedRequestCount,

                'releasedBorrowings' =>
                    $releasedBorrowings,

                'returnCompliance' =>
                    $returnCompliance,

                'openAccountabilityCount' =>
                    $openAccountabilityCount,

                'overdueCount' =>
                    $overdueCount,

                'repeatOffenders' =>
                    $repeatOffenders,

                'averageApprovalSeconds' =>
                    $averageApprovalSeconds,

                'topItems' =>
                    $topItems,

                'leastUtilizedItems' =>
                    $leastUtilizedItems,

                'mostFrequentBorrowers' =>
                    $mostFrequentBorrowers,

                'borrowersWithMostLateReturns' =>
                    $borrowersWithMostLateReturns,

                'borrowersWithMostViolations' =>
                    $borrowersWithMostViolations,

                'assetConditionTrends' =>
                    $assetConditionTrends,

                'borrowingTrend' =>
                    $borrowingTrend,

                'trendGranularity' =>
                    $trendGranularity,

                'from' =>
                    $from,

                'to' =>
                    $to,

                'activeTab' =>
                    'analytics',

                'academicPeriods' =>
                    $academicPeriods,

                'activeAcademicPeriod' =>
                    $activeAcademicPeriod,

                'selectedAcademicPeriod' =>
                    $selectedAcademicPeriod,

                'periodSelection' =>
                    $periodSelection,

                /*
                 * Keep these report values available for CSV/report compatibility.
                 * They are intentionally no longer promoted as headline KPIs.
                 */
                'penaltyTotal' =>
                    Penalty::query()
                        ->whereBetween(
                            'assessed_at',
                            [
                                $from,
                                $to,
                            ]
                        )
                        ->sum('amount'),

                'billingTotal' =>
                    BillingStatement::query()
                        ->whereBetween(
                            'issued_at',
                            [
                                $from,
                                $to,
                            ]
                        )
                        ->sum('total_amount'),

                'sanctionCount' =>
                    Sanction::query()
                        ->whereBetween(
                            'confirmed_at',
                            [
                                $from,
                                $to,
                            ]
                        )
                        ->count(),

                'dueSoonCount' =>
                    CustodyTransaction::query()
                        ->whereIn(
                            'status',
                            [
                                'ACTIVE',
                                'RETURN_PROCESSING',
                            ]
                        )
                        ->whereDate(
                            'due_at',
                            '>=',
                            now()->toDateString()
                        )
                        ->whereDate(
                            'due_at',
                            '<=',
                            now()
                                ->addDay()
                                ->toDateString()
                        )
                        ->count(),
            ]
        );
    }


    /**
     * Resolve the reporting scope shared by Analytics and Reports.
     *
     * Reporting periods are constrained to weekly, monthly, semester, or academic-year scopes. Semester and academic-year dates follow Operational Configuration.
     *
     * @return array{0:Carbon,1:Carbon,2:?AcademicPeriod,3:string}
     */
    private function resolveReportingPeriod(
        Request $request,
        $academicPeriods,
        ?AcademicPeriod $activeAcademicPeriod
    ): array {
        $selection = strtolower(trim((string) $request->input('academic_period', '')));

        // Backward-compatible handling for old links that stored a period ID.
        if ($selection !== '' && ctype_digit($selection)) {
            $legacyPeriod = $academicPeriods->first(
                fn (AcademicPeriod $period): bool => (string) $period->id === $selection
            );

            if ($legacyPeriod) {
                return [
                    Carbon::parse($legacyPeriod->start_date)->startOfDay(),
                    Carbon::parse($legacyPeriod->end_date)->endOfDay(),
                    $legacyPeriod,
                    'semester',
                ];
            }
        }

        if (! in_array($selection, ['week', 'month', 'semester', 'academic_year'], true)) {
            $selection = $activeAcademicPeriod ? 'semester' : 'month';
        }

        if ($selection === 'week') {
            return [now()->startOfWeek()->startOfDay(), now()->endOfWeek()->endOfDay(), null, 'week'];
        }

        if ($selection === 'month') {
            return [now()->startOfMonth()->startOfDay(), now()->endOfMonth()->endOfDay(), null, 'month'];
        }

        if ($selection === 'semester' && $activeAcademicPeriod) {
            return [
                Carbon::parse($activeAcademicPeriod->start_date)->startOfDay(),
                Carbon::parse($activeAcademicPeriod->end_date)->endOfDay(),
                $activeAcademicPeriod,
                'semester',
            ];
        }

        if ($selection === 'academic_year' && $activeAcademicPeriod) {
            $yearPeriods = $academicPeriods->where('academic_year', $activeAcademicPeriod->academic_year);
            $fromDate = $yearPeriods->min('start_date') ?: $activeAcademicPeriod->start_date;
            $toDate = $yearPeriods->max('end_date') ?: $activeAcademicPeriod->end_date;

            return [
                Carbon::parse($fromDate)->startOfDay(),
                Carbon::parse($toDate)->endOfDay(),
                null,
                'academic_year',
            ];
        }

        // When no active period is configured, semester/year safely fall back
        // to the current calendar month instead of exposing arbitrary dates.
        return [now()->startOfMonth()->startOfDay(), now()->endOfMonth()->endOfDay(), null, 'month'];
    }

    /**
     * Resolve the status that should be shown in Request Status reports.
     *
     * The request record may intentionally keep an approval-era status such as
     * APPROVED_READY_FOR_RELEASE. Once a custody transaction exists, the
     * physical custody lifecycle is the authoritative operational status.
     *
     * @return array{0:string,1:?string}
     */
    private function requestReportStatus(
        BorrowingRequest $row
    ): array {
        $custody = $row->custody;

        if ($custody) {
            if (
                $custody->status === 'CLOSED'
                || $custody->closed_at !== null
            ) {
                return [
                    'COMPLETED',
                    'Completed',
                ];
            }

            return match ($custody->status) {
                'ACTIVE' => [
                    'ACTIVE',
                    'Released / On Custody',
                ],

                'RETURN_PROCESSING',
                'PARTIALLY_RETURNED' => [
                    'RETURN_PROCESSING',
                    'Return Processing',
                ],

                'OVERDUE' => [
                    'OVERDUE',
                    'Overdue',
                ],

                'INCIDENT_OPEN' => [
                    'INCIDENT_OPEN',
                    'Incident Open',
                ],

                'OBLIGATION_OPEN' => [
                    'OBLIGATION_OPEN',
                    'Obligation Open',
                ],

                'PREPARING_RELEASE' =>
                    $custody->scheduled_release_at
                        ? [
                            'PICKUP_SCHEDULED',
                            'Pickup Scheduled',
                        ]
                        : [
                            'PREPARING_RELEASE',
                            'Ready for Release',
                        ],

                default => [
                    (string) $custody->status,
                    null,
                ],
            };
        }

        $requestStatus = $row->status->value;

        return match ($requestStatus) {
            'FINAL_APPROVED_AWAITING_DOWNLOAD' => [
                'APPROVED_READY_FOR_RELEASE',
                'Ready for Release',
            ],

            default => [
                $requestStatus,
                null,
            ],
        };
    }

    private function reportsTab(
        Request $request,
        InventoryService $inventory,
        Carbon $from,
        Carbon $to,
        $academicPeriods,
        ?AcademicPeriod $activeAcademicPeriod,
        ?AcademicPeriod $selectedAcademicPeriod,
        string $periodSelection
    ): View {
        $reportTypes = [
            'borrowing' => [
                'label' => 'Borrowing Activity Report',
                'description' => 'Request-level borrowing activity and current workflow status.',
                'export' => 'borrowing',
            ],
            'requests' => [
                'label' => 'Request Status Report',
                'description' => 'Detailed request records using the latest operational status. Once custody exists, custody lifecycle status takes priority over the earlier request approval status.',
                'export' => 'borrowing',
            ],
            'review-turnaround' => [
                'label' => 'SPMU Review Turnaround Report',
                'description' => 'Received-to-decision timing for completed SPMU review actions.',
                'export' => null,
            ],
            'custody' => [
                'label' => 'Custody / Return Report',
                'description' => 'Release, expected return, closure, and current custody state.',
                'export' => null,
            ],
            'inventory' => [
                'label' => 'Inventory State Report',
                'description' => 'Current physical inventory state by item.',
                'export' => 'inventory',
            ],
            'utilization' => [
                'label' => 'Asset Utilization Report',
                'description' => 'Full automatic ranking by physically released quantity.',
                'export' => 'utilization',
            ],
            'overdue' => [
                'label' => 'Overdue Report',
                'description' => 'Overdue cases recorded during the reporting period.',
                'export' => 'overdue',
            ],
            'accountability' => [
                'label' => 'Accountability / Incident Report',
                'description' => 'Property incidents and confirmed borrower violations.',
                'export' => null,
            ],
            'compliance' => [
                'label' => 'Return Compliance Report',
                'description' => 'Released custody compared with transactions that reached final closure.',
                'export' => 'compliance',
            ],
            'borrowers' => [
                'label' => 'Borrower Activity Report',
                'description' => 'Request frequency, late-return cases, and confirmed violations.',
                'export' => null,
            ],
        ];

        $selectedReport = (string) $request->input('report', 'borrowing');

        if (! array_key_exists($selectedReport, $reportTypes)) {
            $selectedReport = 'borrowing';
        }

        $rows = collect();
        $secondaryRows = collect();
        $summary = [];

        if (in_array($selectedReport, ['borrowing', 'requests'], true)) {
            $rows = BorrowingRequest::query()
                ->with(['borrower', 'currentVersion', 'custody'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('created_at')
                ->get()
                ->map(function (BorrowingRequest $row): BorrowingRequest {
                    [
                        $displayStatus,
                        $displayLabel,
                    ] = $this->requestReportStatus($row);

                    $row->setAttribute(
                        'report_display_status',
                        $displayStatus
                    );

                    $row->setAttribute(
                        'report_display_label',
                        $displayLabel
                    );

                    return $row;
                });

            $statusFocus = strtoupper(trim((string) $request->input('status_focus', '')));
            if ($selectedReport === 'requests' && $statusFocus !== '' && $statusFocus !== 'ALL') {
                $rows = $rows
                    ->filter(fn (BorrowingRequest $row): bool => $row->report_display_status === $statusFocus)
                    ->values();
            }

            /*
             * Build the Request Status Report summary from the same derived
             * operational status used by every table row.
             *
             * This prevents the old request-level APPROVED_READY_FOR_RELEASE
             * value from inflating an "Approved / ready" KPI after custody has
             * already advanced to Active, Return Processing, or Completed.
             */
            $statusCounts = $rows
                ->countBy(
                    fn (BorrowingRequest $row): string =>
                        (string) $row->report_display_status
                );

            $summary = collect([
                'Total requests' =>
                    $rows->count(),

                'Under SPMU Review' =>
                    (int) ($statusCounts['UNDER_SPMU'] ?? 0),

                'Ready for Release' =>
                    (int) ($statusCounts['APPROVED_READY_FOR_RELEASE'] ?? 0)
                    + (int) ($statusCounts['PREPARING_RELEASE'] ?? 0),

                'Pickup Scheduled' =>
                    (int) ($statusCounts['PICKUP_SCHEDULED'] ?? 0),

                'Released / On Custody' =>
                    (int) ($statusCounts['ACTIVE'] ?? 0),

                'Return Processing' =>
                    (int) ($statusCounts['RETURN_PROCESSING'] ?? 0),

                'Overdue' =>
                    (int) ($statusCounts['OVERDUE'] ?? 0),

                'Incident Open' =>
                    (int) ($statusCounts['INCIDENT_OPEN'] ?? 0),

                'Obligation Open' =>
                    (int) ($statusCounts['OBLIGATION_OPEN'] ?? 0),

                'Completed' =>
                    (int) ($statusCounts['COMPLETED'] ?? 0),

                'Returned for Revision' =>
                    (int) ($statusCounts['RETURNED_FOR_REVISION'] ?? 0),

                'Rejected' =>
                    (int) ($statusCounts['REJECTED'] ?? 0),

                'Cancelled' =>
                    (int) ($statusCounts['CANCELLED'] ?? 0),

                'Expired' =>
                    (int) ($statusCounts['EXPIRED'] ?? 0),

                'Draft' =>
                    (int) ($statusCounts['DRAFT'] ?? 0),
            ])
                ->filter(
                    fn (int $count, string $label): bool =>
                        $label === 'Total requests'
                        || $count > 0
                )
                ->all();
        } elseif ($selectedReport === 'review-turnaround') {
            $rows = DB::table('approval_steps')
                ->join('request_versions', 'request_versions.id', '=', 'approval_steps.request_version_id')
                ->join('borrowing_requests', 'borrowing_requests.id', '=', 'request_versions.request_id')
                ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
                ->where('approval_steps.stage_code', 'SPMU')
                ->whereNotNull('approval_steps.received_at')
                ->whereNotNull('approval_steps.decided_at')
                ->whereBetween('approval_steps.decided_at', [$from, $to])
                ->select([
                    'borrowing_requests.id AS request_id',
                    'borrowing_requests.request_no',
                    'users.full_name AS borrower_name',
                    'approval_steps.received_at',
                    'approval_steps.decided_at',
                    'approval_steps.decision',
                    'approval_steps.remarks',
                ])
                ->orderByDesc('approval_steps.decided_at')
                ->get()
                ->map(function ($row) {
                    $row->turnaround_seconds = max(
                        0,
                        Carbon::parse($row->received_at)
                            ->diffInSeconds(Carbon::parse($row->decided_at), false)
                    );

                    return $row;
                });

            $averageSeconds = $rows->isEmpty()
                ? 0
                : (int) round($rows->avg('turnaround_seconds'));

            $summary = [
                'Completed SPMU reviews' => $rows->count(),
                'Approved' => $rows->where('decision', 'APPROVED')->count(),
                'Returned for revision' => $rows->where('decision', 'RETURNED_FOR_REVISION')->count(),
                'Average review turnaround' => $averageSeconds,
            ];
        } elseif ($selectedReport === 'custody') {
            $rows = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->where(function ($query) use ($from, $to): void {
                    $query->whereBetween('created_at', [$from, $to])
                        ->orWhereBetween('released_at', [$from, $to])
                        ->orWhereBetween('closed_at', [$from, $to]);
                })
                ->latest('created_at')
                ->get();

            $summary = [
                'Custody records' => $rows->count(),
                'Physically released' => $rows->whereNotNull('released_at')->count(),
                'Closed' => $rows->where('status', 'CLOSED')->count(),
                'Still active / processing' => $rows->filter(
                    fn (CustodyTransaction $row): bool => $row->status !== 'CLOSED'
                )->count(),
            ];
        } elseif ($selectedReport === 'inventory') {
            $rows = InventoryItem::query()
                ->with(['category', 'unit'])
                ->where('active', true)
                ->orderBy('unique_description')
                ->get()
                ->map(function (InventoryItem $item) use ($inventory) {
                    $item->report_balance = $inventory->availability(
                        $item,
                        now()->subYears(10)->startOfDay(),
                        now()->addYears(10)->endOfDay()
                    );

                    return $item;
                });

            $summary = [
                'Tracked inventory items' => $rows->count(),
                'Physical available' => $rows->sum(
                    fn (InventoryItem $item): float =>
                        (float) ($item->report_balance['current_available']
                            ?? $item->report_balance['available']
                            ?? 0)
                ),
                'Allocated' => $rows->sum(
                    fn (InventoryItem $item): float =>
                        (float) ($item->report_balance['allocated']
                            ?? $item->report_balance['reserved']
                            ?? 0)
                ),
                'On custody' => $rows->sum(
                    fn (InventoryItem $item): float =>
                        (float) ($item->report_balance['borrowed'] ?? 0)
                ),
            ];
        } elseif ($selectedReport === 'utilization') {
            $rows = InventoryItem::query()
                ->leftJoin('request_items', 'request_items.inventory_item_id', '=', 'inventory_items.id')
                ->leftJoin('custody_lines', 'custody_lines.request_item_id', '=', 'request_items.id')
                ->leftJoin('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
                ->where('inventory_items.active', true)
                ->selectRaw(
                    'inventory_items.id, inventory_items.unique_description,
                     COALESCE(SUM(CASE WHEN custody_transactions.released_at BETWEEN ? AND ?
                     THEN custody_lines.actual_released_quantity ELSE 0 END), 0) AS used_quantity',
                    [$from, $to]
                )
                ->groupBy('inventory_items.id', 'inventory_items.unique_description')
                ->orderByDesc('used_quantity')
                ->get();

            $summary = [
                'Active items compared' => $rows->count(),
                'Items with utilization' => $rows->filter(
                    fn ($row): bool => (float) $row->used_quantity > 0
                )->count(),
                'No utilization' => $rows->filter(
                    fn ($row): bool => (float) $row->used_quantity <= 0
                )->count(),
                'Released quantity' => $rows->sum(
                    fn ($row): float => (float) $row->used_quantity
                ),
            ];
        } elseif ($selectedReport === 'overdue') {
            $rows = OverdueCase::query()
                ->with(['borrower', 'custody'])
                ->whereBetween('created_at', [$from, $to])
                ->latest('created_at')
                ->get();

            $summary = [
                'Overdue cases' => $rows->count(),
                'Open / unresolved' => $rows->filter(
                    fn (OverdueCase $row): bool => $row->status !== 'RESOLVED'
                )->count(),
                'Resolved' => $rows->where('status', 'RESOLVED')->count(),
                'Repeat borrowers' => $rows->groupBy('borrower_user_id')->filter(
                    fn ($group): bool => $group->count() > 1
                )->count(),
            ];
        } elseif ($selectedReport === 'accountability') {
            $rows = Incident::query()
                ->with(['borrower', 'custody', 'lines'])
                ->whereBetween('reported_at', [$from, $to])
                ->latest('reported_at')
                ->get();

            $secondaryRows = BorrowerViolation::query()
                ->with(['borrower', 'custody'])
                ->where('status', 'CONFIRMED')
                ->whereBetween('detected_at', [$from, $to])
                ->latest('detected_at')
                ->get();

            $summary = [
                'Reported incidents' => $rows->count(),
                'Open incidents' => $rows->filter(
                    fn (Incident $row): bool => $row->status !== 'RESOLVED'
                )->count(),
                'Confirmed violations' => $secondaryRows->count(),
                'Affected quantity' => $rows->sum(
                    fn (Incident $row): float =>
                        $row->lines->sum(fn ($line): float => (float) $line->quantity)
                ),
            ];
        } elseif ($selectedReport === 'compliance') {
            $rows = CustodyTransaction::query()
                ->with(['borrower', 'request'])
                ->whereNotNull('released_at')
                ->whereBetween('released_at', [$from, $to])
                ->orderByDesc('released_at')
                ->get();

            $compliance = $this->returnCompliance($from, $to);

            $summary = [
                'Released custody' => $compliance['released'],
                'Closed custody' => $compliance['closed'],
                'Still open' => max(0, $compliance['released'] - $compliance['closed']),
                'Return compliance' => $compliance['percentage'],
            ];
        } elseif ($selectedReport === 'borrowers') {
            $rows = BorrowingRequest::query()
                ->join('users', 'users.id', '=', 'borrowing_requests.borrower_user_id')
                ->whereBetween('borrowing_requests.created_at', [$from, $to])
                ->selectRaw('users.id, users.full_name, COUNT(borrowing_requests.id) AS request_count')
                ->groupBy('users.id', 'users.full_name')
                ->orderByDesc('request_count')
                ->get();

            $late = OverdueCase::query()
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('borrower_user_id, COUNT(*) AS late_count')
                ->groupBy('borrower_user_id')
                ->pluck('late_count', 'borrower_user_id');

            $violations = BorrowerViolation::query()
                ->where('status', 'CONFIRMED')
                ->whereBetween('detected_at', [$from, $to])
                ->selectRaw('borrower_user_id, COUNT(*) AS violation_count')
                ->groupBy('borrower_user_id')
                ->pluck('violation_count', 'borrower_user_id');

            $rows = $rows->map(function ($row) use ($late, $violations) {
                $row->late_count = (int) ($late[$row->id] ?? 0);
                $row->violation_count = (int) ($violations[$row->id] ?? 0);

                return $row;
            });

            $summary = [
                'Borrowers with requests' => $rows->count(),
                'Requests represented' => $rows->sum(fn ($row): int => (int) $row->request_count),
                'Late-return cases' => $rows->sum(fn ($row): int => (int) $row->late_count),
                'Confirmed violations' => $rows->sum(fn ($row): int => (int) $row->violation_count),
            ];
        }

        return view('reports.detailed', [
            'activeTab' => 'reports',
            'reportTypes' => $reportTypes,
            'selectedReport' => $selectedReport,
            'selectedReportMeta' => $reportTypes[$selectedReport],
            'reportRows' => $rows,
            'secondaryRows' => $secondaryRows,
            'reportSummary' => $summary,
            'exportType' => $reportTypes[$selectedReport]['export'],
            'from' => $from,
            'to' => $to,
            'academicPeriods' => $academicPeriods,
            'activeAcademicPeriod' => $activeAcademicPeriod,
            'selectedAcademicPeriod' => $selectedAcademicPeriod,
            'periodSelection' => $periodSelection,
        ]);
    }

    public function export(
        Request $request,
        string $type,
        InventoryService $inventory
    ): StreamedResponse {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        abort_unless(
            in_array(
                $type,
                [
                    'inventory',
                    'borrowing',
                    'utilization',
                    'overdue',
                    'penalty',
                    'billing',
                    'sanction',
                    'compliance',
                    'notification',
                    'audit',
                ],
                true
            ),
            404
        );

        if (in_array($type, ['notification', 'audit'], true)) {
            abort_unless(
                $request->user()->hasRole(UserRole::Spmu)
                || $request->user()->hasRole(UserRole::Ictu),
                403
            );
        }

        $from = Carbon::parse($request->input('from', now()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();

        return response()->streamDownload(function () use ($type, $from, $to, $inventory): void {
            $output = fopen('php://output', 'w');

            if ($type === 'inventory') {
                fputcsv($output, ['Description', 'Category', 'Unit', 'Total', 'Available', 'Reserved', 'Issued/On Custody', 'Laundry', 'Damaged/Maintenance', 'Lost', 'Stolen', 'Destroyed', 'Condemned']);
                InventoryItem::query()->with(['category', 'unit'])->where('active', true)->orderBy('unique_description')->each(function ($item) use ($output, $inventory): void {
                    $balance = $inventory->availability($item, now()->subYears(10)->startOfDay(), now()->addYears(10)->endOfDay());
                    fputcsv($output, [
                        $item->unique_description,
                        $item->category->category_name,
                        $item->unit->unit_name,
                        $balance['total'],
                        $balance['available'],
                        $balance['allocated'],
                        $balance['borrowed'],
                        $balance['laundry'],
                        $balance['damaged_maintenance'],
                        $balance['lost'],
                        $balance['stolen'],
                        $balance['destroyed'],
                        $balance['condemned'],
                    ]);
                });
            } elseif ($type === 'borrowing') {
                fputcsv($output, ['Request No.', 'Borrower', 'Status', 'Schedule Date', 'Return Date', 'Created', 'SPMU Approved']);

                BorrowingRequest::query()
                    ->with(['borrower', 'currentVersion', 'custody'])
                    ->whereBetween('created_at', [$from, $to])
                    ->each(function (BorrowingRequest $row) use ($output): void {
                        [
                            $displayStatus,
                            $displayLabel,
                        ] = $this->requestReportStatus($row);

                        fputcsv($output, [
                            $row->request_no,
                            $row->borrower->full_name,
                            $displayLabel
                                ?: str($displayStatus)
                                    ->replace('_', ' ')
                                    ->title(),
                            $row->currentVersion?->schedule_date?->toDateString()
                                ?? $row->currentVersion?->needed_from?->toDateString(),
                            $row->currentVersion?->return_date?->toDateString()
                                ?? $row->currentVersion?->return_due_at?->toDateString(),
                            $row->created_at,
                            $row->final_approved_at,
                        ]);
                    });
            } elseif ($type === 'utilization') {
                fputcsv($output, ['Description', 'Released Quantity']);
                InventoryItem::query()
                    ->leftJoin('request_items', 'request_items.inventory_item_id', '=', 'inventory_items.id')
                    ->leftJoin('custody_lines', 'custody_lines.request_item_id', '=', 'request_items.id')
                    ->leftJoin('custody_transactions', 'custody_transactions.id', '=', 'custody_lines.custody_transaction_id')
                    ->where(function ($query) use ($from, $to): void {
                        $query->whereNull('custody_transactions.released_at')->orWhereBetween('custody_transactions.released_at', [$from, $to]);
                    })
                    ->selectRaw('inventory_items.unique_description, COALESCE(SUM(custody_lines.actual_released_quantity), 0) AS used_quantity')
                    ->groupBy('inventory_items.id', 'inventory_items.unique_description')
                    ->orderByDesc('used_quantity')
                    ->each(fn ($row) => fputcsv($output, [$row->unique_description, $row->used_quantity]));
            } elseif ($type === 'overdue') {
                fputcsv($output, ['Custody No.', 'Borrower', 'Expected Return Date', 'Late Fee Rate', 'Accrued Amount', 'Status']);
                OverdueCase::query()->with(['custody', 'borrower'])->whereBetween('created_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->custody->custody_no,
                    $row->borrower->full_name,
                    $row->custody->due_at?->toDateString(),
                    $row->rate_snapshot,
                    $row->accrued_amount,
                    $row->status,
                ]));
            } elseif ($type === 'penalty') {
                fputcsv($output, ['Borrower ID', 'Financial Charge Type', 'Rate', 'Amount', 'Status', 'Assessed']);
                Penalty::query()->whereBetween('assessed_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->borrower_user_id,
                    $row->penalty_type,
                    $row->rate_snapshot,
                    $row->amount,
                    $row->status,
                    $row->assessed_at,
                ]));
            } elseif ($type === 'billing') {
                fputcsv($output, ['Billing No.', 'Borrower ID', 'Amount', 'Status', 'Issued']);
                BillingStatement::query()->whereBetween('issued_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->billing_no,
                    $row->borrower_user_id,
                    $row->total_amount,
                    $row->status,
                    $row->issued_at,
                ]));
            } elseif ($type === 'sanction') {
                fputcsv($output, ['Borrower ID', 'Offense No.', 'Sanction', 'Academic Period ID', 'Status', 'Confirmed']);
                Sanction::query()->whereBetween('confirmed_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->borrower_user_id,
                    $row->offense_no,
                    $row->sanction_label,
                    $row->academic_period_id,
                    $row->status,
                    $row->confirmed_at,
                ]));
            } elseif ($type === 'notification') {
                fputcsv($output, ['Time', 'Event', 'Channel', 'Recipient User ID', 'Address', 'Provider', 'Status', 'Response']);
                NotificationDelivery::query()->with('event')->whereBetween('attempted_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->attempted_at,
                    $row->event->event_code,
                    $row->channel,
                    $row->recipient_user_id,
                    $row->address_snapshot,
                    $row->provider,
                    $row->delivery_status,
                    $row->provider_response,
                ]));
            } elseif ($type === 'audit') {
                fputcsv($output, ['Time', 'Actor User ID', 'Action', 'Record Type', 'Record ID', 'Reason', 'Origin IP']);
                AuditEvent::query()->whereBetween('occurred_at', [$from, $to])->each(fn ($row) => fputcsv($output, [
                    $row->occurred_at,
                    $row->actor_user_id,
                    $row->action_code,
                    $row->record_type,
                    $row->record_id,
                    $row->reason,
                    $row->origin_ip,
                ]));
            } else {
                fputcsv($output, ['Period From', 'Period To', 'Released Custodies', 'Closed Custodies', 'Return Compliance Percent']);
                $compliance = $this->returnCompliance($from, $to);
                fputcsv($output, [
                    $from->toDateString(),
                    $to->toDateString(),
                    $compliance['released'],
                    $compliance['closed'],
                    $compliance['percentage'],
                ]);
            }

            fclose($output);
        }, "spmu-{$type}-report-".now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function audit(Request $request): View
    {
        abort_unless(
            in_array($request->user()?->access_classification, [
                AccessClassification::SpmuHead,
                AccessClassification::IctuMaintainer,
            ], true),
            403
        );
        return view('reports.audit', [
            'events' => AuditEvent::query()->with('actor')->latest('occurred_at')->limit(500)->get(),
        ]);
    }

    public function notifications(Request $request): View
    {
        abort_unless(
            in_array($request->user()?->access_classification, [
                AccessClassification::SpmuHead,
                AccessClassification::IctuMaintainer,
            ], true),
            403
        );
        return view('reports.notifications', [
            'deliveries' => NotificationDelivery::query()->with('event')->latest('created_at')->limit(500)->get(),
        ]);
    }

    /** @return array{released:int,closed:int,percentage:float} */
    private function returnCompliance(Carbon $from, Carbon $to): array
    {
        $released = CustodyTransaction::query()->whereBetween('released_at', [$from, $to])->count();
        $closed = CustodyTransaction::query()
            ->whereBetween('released_at', [$from, $to])
            ->where('status', 'CLOSED')
            ->count();

        return [
            'released' => $released,
            'closed' => $closed,
            'percentage' => $released ? round($closed / $released * 100, 2) : 0,
        ];
    }
}
