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
use App\Reports\ReportCatalogue;
use App\Reports\ReportFilters;
use App\Services\AuditService;
use App\Services\InventoryService;
use App\Services\ReportService;
use App\Services\ReportingPeriodService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Records shown on one page of a generated report. */
    private const PER_PAGE = 10;

    public function index(Request $request): View|RedirectResponse
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        /*
         * Analytics is its own module. Bookmarks that still carry the old
         * ?tab=analytics are forwarded there with the period they pointed at,
         * rather than silently showing Reports.
         */
        if (strtolower((string) $request->input('tab')) === 'analytics') {
            return redirect()->route('analytics.index', [
                'academic_period' => $request->input('academic_period', 'month'),
            ]);
        }

        $scope = $this->resolveScope($request, $request->input('report'));

        $dataset = app(ReportService::class)->generate($scope['filters'], $request->user());

        /*
         * The screen paginates; CSV and print render the whole dataset. All
         * three come from this one generate() call, so a page of records can
         * never disagree with the export beside it.
         */
        $page = LengthAwarePaginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginator(
            $dataset->rows->forPage($page, self::PER_PAGE)->values(),
            $dataset->count(),
            self::PER_PAGE,
            $page,
            [
                'path' => route('reports.index'),
                'query' => $scope['filters']->toQuery(),
            ]
        );

        return view('reports.detailed', [
            'activeTab' => 'reports',
            'reportGroups' => ReportCatalogue::grouped(),
            'selectedReport' => $scope['report'],
            'selectedReportMeta' => ReportCatalogue::definition($scope['report']),
            'dataset' => $dataset,
            'records' => $paginator,
            'reportFilters' => $scope['filters'],
            'exportType' => ReportCatalogue::definition($scope['report'])['export'],
            'from' => $scope['from'],
            'to' => $scope['to'],
            'academicPeriods' => $scope['academicPeriods'],
            'activeAcademicPeriod' => $scope['activeAcademicPeriod'],
            'selectedAcademicPeriod' => $scope['selectedAcademicPeriod'],
            'periodSelection' => $scope['periodSelection'],
        ]);
    }

    /**
     * The printable copy of a generated report.
     *
     * Print resolves its scope through resolveScope() exactly as the screen
     * and the CSV do, so the printed page is the same report with the same
     * filters — the whole record set rather than one page of it.
     */
    public function print(Request $request, string $type): View
    {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        abort_unless(ReportCatalogue::has($type) || ReportCatalogue::has(
            ReportCatalogue::resolveKey($type)
        ), 404);

        $scope = $this->resolveScope($request, $type);

        return view('reports.print', [
            'dataset' => app(ReportService::class)->generate($scope['filters'], $request->user()),
        ]);
    }

    /**
     * Resolve one reporting scope: period, academic period, and validated
     * filters for a report key.
     *
     * Screen, CSV and print all call this, which is what guarantees the three
     * outputs describe the same records. The reporting period itself comes
     * from ReportingPeriodService, the resolver Analytics also uses.
     *
     * @return array<string, mixed>
     */
    private function resolveScope(Request $request, ?string $reportKey): array
    {
        $academicPeriods = AcademicPeriod::query()
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->get();

        $activeAcademicPeriod = $academicPeriods->first(
            fn (AcademicPeriod $period): bool => $period->status === 'ACTIVE'
        );

        [
            $from,
            $to,
            $selectedAcademicPeriod,
            $periodSelection,
        ] = $this->resolveReportingPeriod($request, $academicPeriods, $activeAcademicPeriod);

        /*
         * Links made before the builder existed carry explicit from/to dates
         * and no period selection. Those still resolve to the range they
         * asked for instead of silently switching to the current month.
         */
        if (! $request->filled('academic_period') && $request->filled('from')) {
            $from = Carbon::parse($request->input('from'))->startOfDay();
            $to = Carbon::parse($request->input('to', now()->toDateString()))->endOfDay();
            $selectedAcademicPeriod = null;
        }

        $report = ReportCatalogue::resolveKey($reportKey);

        return [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'periodSelection' => $periodSelection,
            'academicPeriods' => $academicPeriods,
            'activeAcademicPeriod' => $activeAcademicPeriod,
            'selectedAcademicPeriod' => $selectedAcademicPeriod,
            'filters' => ReportFilters::fromRequest(
                $request,
                $report,
                $from,
                $to,
                $periodSelection,
                $selectedAcademicPeriod
            ),
        ];
    }

    /**
     * Delegates to the shared service so Reports and Analytics always read
     * the same dates from the same selection.
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
                    'approval',
                    'custody',
                    'returns',
                    'laundry',
                    'gate-pass',
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

        /*
         * A migrated report exports the very dataset the screen rendered:
         * same builder, same filters, same rows. Only the rendering differs.
         */
        if (ReportCatalogue::has($type) && ReportCatalogue::isMigrated($type)) {
            return $this->exportDataset($request, $type);
        }

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

    /**
     * Stream a migrated report as CSV from the shared dataset pipeline.
     *
     * The reporting period and filters are resolved exactly as the screen
     * resolved them, so "Export CSV" always returns the report on screen.
     */
    /**
     * Stream a report as CSV from the shared dataset pipeline.
     *
     * The scope is resolved by resolveScope(), the same method the screen
     * used, so "Export CSV" always returns the report on screen — same
     * report type, period, filters, and status rules.
     */
    private function exportDataset(Request $request, string $type): StreamedResponse
    {
        $scope = $this->resolveScope($request, $type);

        $service = app(ReportService::class);
        $dataset = $service->generate($scope['filters'], $request->user());

        /*
         * Exporting is a deliberate act that produces a file leaving the
         * system, so it is recorded through the central AuditService rather
         * than a log table of the module's own.
         */
        app(AuditService::class)->record(
            'report.exported',
            AuditEvent::class,
            null,
            trim(
                $dataset->label
                .' · '.($dataset->meta['period_label'] ?? '')
                .' · '.$dataset->count().' records'
            )
        );

        return response()->streamDownload(
            function () use ($service, $dataset): void {
                $output = fopen('php://output', 'w');
                $service->writeCsv($dataset, $output);
                fclose($output);
            },
            $service->filename($dataset),
            ['Content-Type' => 'text/csv']
        );
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
