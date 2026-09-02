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
use App\Models\InventoryItem;
use App\Models\NotificationDelivery;
use App\Models\OverdueCase;
use App\Models\Penalty;
use App\Models\Sanction;
use App\Services\InventoryService;
use App\Services\ReportingPeriodService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View|RedirectResponse
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

        /*
         * Analytics is now its own module. Bookmarks and links that still
         * carry the old ?tab=analytics are forwarded there with the period
         * they were pointing at, rather than silently showing Reports.
         */
        if (strtolower((string) $request->input('tab')) === 'analytics') {
            return redirect()->route('analytics.index', [
                'academic_period' => $periodSelection,
            ]);
        }

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


    /**
     * Resolve the reporting scope shared by Analytics and Reports.
     *
     * Reporting periods are constrained to weekly, monthly, semester, or academic-year scopes. Semester and academic-year dates follow Operational Configuration.
     *
     * @return array{0:Carbon,1:Carbon,2:?AcademicPeriod,3:string}
     */
    /**
     * Delegates to the shared service so Reports and Analytics always read the
     * same dates from the same selection.
     *
     * @return array{0:Carbon,1:Carbon,2:?AcademicPeriod,3:string}
     */
    private function resolveReportingPeriod(
        Request $request,
        $academicPeriods,
        ?AcademicPeriod $activeAcademicPeriod
    ): array {
        return app(ReportingPeriodService::class)->resolve(
            $request,
            $academicPeriods,
            $activeAcademicPeriod
        );
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
