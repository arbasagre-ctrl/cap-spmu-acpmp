<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Models\User;
use App\Services\AnalyticsDetailService;
use App\Services\AnalyticsService;
use App\Services\ForecastService;
use App\Services\InventoryService;
use App\Services\ReportingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Analytics workspace for the SPMU Head.
 *
 * The controller resolves the filters and the active section, then hands the
 * prepared figures to the view. Every calculation lives in AnalyticsService or
 * ForecastService, so Reports and Analytics cannot drift apart and the Blade
 * template stays a layout.
 *
 * Only the active section's figures are computed. Overview also prepares a
 * compact cross-tab summary (demand, inventory, returns and forecast readiness)
 * so it can act as the executive snapshot without duplicating each tab's full
 * rankings, tables or forecast workspace.
 */
class AnalyticsController extends Controller
{
    /** The sections offered in the sub-navigation, in display order. */
    public const SECTIONS = [
        'overview' => 'Overview',
        'demand' => 'Demand & Utilization',
        'inventory' => 'Inventory Health',
        'returns' => 'Borrowing & Return Performance',
        'predictive' => 'Forecast & Planning',
    ];

    /**
     * The partial each section renders.
     *
     * Kept apart from the section key so the URL stays short while the file
     * name still says what it holds.
     */
    public const SECTION_PARTIALS = [
        'overview' => 'overview',
        'demand' => 'demand-usage',
        'inventory' => 'inventory-health',
        'returns' => 'borrowing-returns',
        'predictive' => 'predictive',
    ];

    /**
     * Section keys that were renamed, so an existing link or bookmark still
     * opens the section that absorbed it rather than silently falling back
     * to Overview.
     */
    public const SECTION_ALIASES = [
        'borrowers' => 'returns',
        'equipment' => 'demand',
        'forecast' => 'predictive',
    ];

    public function __invoke(
        Request $request,
        AnalyticsService $analytics,
        ForecastService $forecasts,
        InventoryService $inventory,
        ReportingPeriodService $periods,
        AnalyticsDetailService $details
    ): View|JsonResponse {
        abort_unless(
            $request->user()?->access_classification === AccessClassification::SpmuHead,
            403
        );

        $academicPeriods = AcademicPeriod::query()
            ->orderByRaw("CASE WHEN status = 'ACTIVE' THEN 0 ELSE 1 END")
            ->orderByDesc('start_date')
            ->get();

        $activeAcademicPeriod = $academicPeriods->first(
            fn (AcademicPeriod $period): bool => $period->status === 'ACTIVE'
        );

        [$from, $to, $selectedAcademicPeriod, $periodSelection] = $periods->resolve(
            $request,
            $academicPeriods,
            $activeAcademicPeriod
        );

        $section = (string) $request->input('section', 'overview');

        if (! array_key_exists($section, self::SECTIONS)) {
            $section = self::SECTION_ALIASES[$section] ?? 'overview';
        }

        /* Units are offered per division, so the two filters stay consistent. */
        $unitOptions = $analytics->unitOptions($from, $to);

        $division = (string) $request->input('group', 'all');

        if (! array_key_exists($division, AnalyticsService::divisions())) {
            $division = 'all';
        }

        $unit = (string) $request->input('unit', 'all');

        $selectableUnits = $division === 'all'
            ? $unitOptions->flatten()->unique()->sort()->values()->all()
            : ($unitOptions[$division] ?? []);

        /* A unit that does not belong to the chosen group resets to All. */
        if ($unit !== 'all' && ! in_array($unit, $selectableUnits, true)) {
            $unit = 'all';
        }

        $divisionFilter = $division === 'all' ? null : $division;
        $unitFilter = $unit === 'all' ? null : $unit;

        /*
         * The Borrower picker is cascading, not global. It asks this same
         * endpoint for borrowers that actually filed a request inside the
         * selected Reporting Period + Division + Office / Unit.
         */
        if ($request->boolean('borrower_options')) {
            return $this->borrowerOptions($request, $analytics, $from, $to, $divisionFilter, $unitFilter);
        }

        $borrowerFilter = $this->resolveBorrower($request, $analytics, $from, $to, $divisionFilter, $unitFilter);
        $selectedBorrowerLabel = $borrowerFilter !== null
            ? (User::query()->find($borrowerFilter)?->full_name ?? 'Selected borrower')
            : 'All borrowers';

        /*
         * Semester and academic-year scopes fall back to the current calendar
         * month when Operational Configuration holds no academic period. The
         * fallback is kept - the page must still render - but it is surfaced,
         * because a month of data labelled as a semester would be a false
         * reading rather than a small one.
         */
        $academicPeriodMissing = in_array(
            strtolower((string) $request->input('academic_period', '')),
            ['semester', 'academic_year'],
            true
        ) && $activeAcademicPeriod === null;

        /*
         * A detail is an internal Analytics view of one figure, opened over
         * the section that produced it. Reports stays one deliberate step
         * further on, offered inside the panel as a secondary action.
         */
        $detailKey = trim((string) $request->input('detail', ''));

        $detail = $detailKey === ''
            ? null
            : $details->resolve(
                $detailKey,
                $request,
                $from,
                $to,
                $divisionFilter,
                $unitFilter,
                $periodSelection,
                $borrowerFilter
            );

        $data = [
            'section' => $section,
            'sectionPartial' => self::SECTION_PARTIALS[$section],
            'detail' => $detail,
            'sections' => self::SECTIONS,
            'academicPeriodMissing' => $academicPeriodMissing,

            'from' => $from,
            'to' => $to,
            'periodSelection' => $periodSelection,
            'academicPeriods' => $academicPeriods,
            'activeAcademicPeriod' => $activeAcademicPeriod,
            'selectedAcademicPeriod' => $selectedAcademicPeriod,

            'divisions' => AnalyticsService::divisions(),
            'selectedDivision' => $division,
            'selectedUnit' => $unit,
            'unitOptions' => $unitOptions,
            'selectableUnits' => $selectableUnits,
            'selectedBorrower' => $borrowerFilter,
            'selectedBorrowerLabel' => $selectedBorrowerLabel,
        ];

        return view(
            'analytics.index',
            $data + $this->sectionData(
                $section,
                $analytics,
                $forecasts,
                $inventory,
                $from,
                $to,
                $divisionFilter,
                $unitFilter,
                $periodSelection,
                $borrowerFilter
            )
        );
    }

    /**
     * A submitted borrower id, accepted only when it actually filed a request
     * inside the current Reporting Period + Division + Office / Unit scope.
     *
     * Cascading validation, not a stale global id: changing an upstream
     * filter can silently invalidate a previously selected borrower, and this
     * resets it to "All borrowers" rather than keep filtering by someone who
     * no longer belongs to the current scope.
     */
    private function resolveBorrower(
        Request $request,
        AnalyticsService $analytics,
        \Carbon\CarbonInterface $from,
        \Carbon\CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): ?int {
        $submitted = (int) $request->input('borrower', 0);

        if ($submitted <= 0) {
            return null;
        }

        $exists = $analytics->requestScope($from, $to, $division, $unit)
            ->where('borrowing_requests.borrower_user_id', $submitted)
            ->exists();

        return $exists ? $submitted : null;
    }

    /**
     * Borrowers who filed a request inside the current Reporting Period +
     * Division + Office / Unit scope, for the Borrower picker.
     *
     * Mirrors ReportController::borrowerOptions() so the two pickers behave
     * identically: searchable, capped, and scoped to the same upstream
     * filters rather than listing every borrower in the system.
     */
    private function borrowerOptions(
        Request $request,
        AnalyticsService $analytics,
        \Carbon\CarbonInterface $from,
        \Carbon\CarbonInterface $to,
        ?string $division,
        ?string $unit
    ): JsonResponse {
        $ids = $analytics->requestScope($from, $to, $division, $unit)
            ->pluck('borrowing_requests.borrower_user_id')
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['options' => [], 'count' => 0, 'shown' => 0, 'has_more' => false]);
        }

        $search = mb_substr(trim((string) $request->input('q', '')), 0, 100);
        $selectedBorrower = (int) $request->input('selected_borrower', 0);
        $limit = 50;

        $query = User::query()->whereIn('id', $ids->all());

        if ($search !== '') {
            $query->where(function ($borrowers) use ($search): void {
                $borrowers
                    ->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        $count = (clone $query)->count();

        $users = $query
            ->orderBy('full_name')
            ->orderBy('email')
            ->limit($limit)
            ->get(['id', 'full_name', 'email']);

        /*
         * Preserve a currently selected borrower even when they fall outside
         * the first 50 names, so the browser can validate the selection
         * after an upstream Division / Office / period change without
         * falsely resetting it.
         */
        if ($selectedBorrower > 0
            && $ids->contains($selectedBorrower)
            && ! $users->contains('id', $selectedBorrower)) {
            $selected = User::query()->find($selectedBorrower, ['id', 'full_name', 'email']);

            if ($selected) {
                $users->push($selected);
            }
        }

        return response()->json([
            'options' => $users->map(fn (User $user): array => [
                'value' => (string) $user->id,
                'name' => (string) $user->full_name,
                'email' => (string) $user->email,
            ])->all(),
            'count' => $count,
            'shown' => $users->count(),
            'has_more' => $count > $users->count(),
        ]);
    }

    /**
     * The figures a single section needs, and nothing else.
     *
     * Overview intentionally computes only the small cross-tab figures needed
     * by its Analytics Summary. It still does not build the detailed rankings,
     * return breakdowns or predictive tables owned by the other tabs.
     *
     * @return array<string, mixed>
     */
    private function sectionData(
        string $section,
        AnalyticsService $analytics,
        ForecastService $forecasts,
        InventoryService $inventory,
        \Carbon\CarbonInterface $from,
        \Carbon\CarbonInterface $to,
        ?string $division,
        ?string $unit,
        string $periodSelection,
        ?int $borrower = null
    ): array {
        if ($section === 'overview') {
            $overview = $analytics->overview($from, $to, $division, $unit, $borrower);
            /*
             * Borrower Distribution and Top Borrowing Units are breakdowns
             * across borrowers/units. A single selected borrower would make
             * either one trivial, so they stay scoped to Division / Office
             * only and are not additionally narrowed to one borrower.
             */
            $groups = $analytics->borrowerGroups($from, $to, $division, $unit);
            $units = $analytics->unitRankings($from, $to, $division, $unit);
            $equipment = $analytics->equipment($from, $to, $division, $unit, 5, $borrower);

            /*
             * Granularity follows the selected period rather than a fixed
             * value, so a semester view is not bucketed as if it were a month.
             */
            $trend = $analytics->trend($from, $to, $division, $unit, $periodSelection, $borrower);
            $returns = $analytics->returns($from, $to, $division, $unit, $borrower);

            /*
             * Compact cross-tab summary only. These are headline values from
             * the detailed workspaces; Overview does not recreate their tables
             * or rankings.
             */
            $demandSummary = $analytics->demandTotals($from, $to, $division, $unit, $borrower);
            $peakSummary = $analytics->peakBorrowing($from, $to, $division, $unit);
            $inventorySummary = $analytics->inventory($inventory);

            [$forecastFrom, $forecastTo] = $forecasts->forecastWindow($from, $to);
            $forecastDemand = $forecasts->demand($analytics, $from, $to, $division, $unit);
            $scheduledDemand = $analytics->scheduledDemand($forecastFrom, $forecastTo, $division, $unit, $borrower);
            $scheduledRequests = (int) $scheduledDemand['requests'];

            return [
                'overview' => $overview,
                'groups' => $groups,
                'units' => $units,
                'equipment' => $equipment,
                'trend' => $trend,
                'lowAvailability' => $analytics->lowAvailability($inventory),
                'insights' => $analytics->insights(
                    $overview,
                    $groups,
                    $units,
                    $equipment,
                    $trend,
                    $returns
                ),
                'returns' => $returns,
                'demandSummary' => $demandSummary,
                'peakSummary' => $peakSummary,
                'inventorySummary' => $inventorySummary,
                'forecastSummary' => [
                    'ready' => (bool) ($forecastDemand['available'] ?? false),
                    'forecast' => $forecastDemand['forecast'] ?? null,
                    'scheduled' => $scheduledRequests,
                    'from' => $forecastFrom,
                    'to' => $forecastTo,
                ],
            ];
        }

        if ($section === 'demand') {
            return [
                /* Headline figures: filed demand, expressed demand, actual release. */
                'totals' => $analytics->demandTotals($from, $to, $division, $unit, $borrower),
                'trend' => $analytics->trend($from, $to, $division, $unit, $periodSelection, $borrower),
                'requested' => $analytics->requestedEquipment($from, $to, $division, $unit, 10, $borrower),
                'released' => $analytics->equipment($from, $to, $division, $unit, 10, $borrower),
                'slowMoving' => $analytics->slowMovingItems($from, $to, 10, $division, $unit),
                'groups' => $analytics->borrowerGroups($from, $to, $division, $unit),
                'units' => $analytics->unitRankings($from, $to, $division, $unit),
                'peak' => $analytics->peakBorrowing($from, $to, $division, $unit),
            ];
        }

        if ($section === 'inventory') {
            return [
                'inventory' => $analytics->inventory($inventory),
                'lowAvailability' => $analytics->lowAvailability($inventory, 10),
                'released' => $analytics->equipment($from, $to, $division, $unit, 5, $borrower),
                /* Quiet items are reported on Demand & Utilization, not here. */
                'coverage' => $analytics->stockCoverage($inventory, $from, $to, 10, $division, $unit),
            ];
        }

        if ($section === 'returns') {
            /*
             * Borrowing demand belongs to Demand & Utilization, so the borrower
             * and unit rankings are no longer computed for this tab. What
             * replaces them answers the tab's own question: how returns,
             * overdue follow-up and accountability outcomes are performing.
             */
            return [
                'returns' => $analytics->returns($from, $to, $division, $unit, $borrower),
                'returnTrend' => $analytics->returnTrend($from, $to, $division, $unit, $periodSelection, $borrower),
                'lifecycle' => $analytics->lifecycle($from, $to, $division, $unit, $borrower),
                'currentOverdue' => $analytics->currentOverdue($division, $unit, 5, $borrower),
                'returnConditions' => $analytics->returnConditions($from, $to, $division, $unit, $borrower),
                'incidents' => $analytics->incidentSummary($from, $to, $division, $unit, $borrower),
            ];
        }

        /* Predictive Analytics. */
        [$forecastFrom, $forecastTo] = $forecasts->forecastWindow($from, $to);

        return [
            'forecastFrom' => $forecastFrom,
            'forecastTo' => $forecastTo,
            /*
             * Known scheduled demand: requests already filed for the period the
             * forecast covers. These are records that exist, not a projection,
             * so they are fetched and reported apart from anything the weighted
             * average produces and are never merged with it.
             */
            'scheduled' => $analytics->scheduledDemand($forecastFrom, $forecastTo, $division, $unit, $borrower),

            /*
             * The projected trend, division/unit/equipment forecasts and busy
             * period are institution-level projections drawn from historical
             * completed periods. Borrower scope only applies to Scheduled
             * Demand above, which is a real, already-filed record set rather
             * than a projection.
             */
            'demand' => $forecasts->demand($analytics, $from, $to, $division, $unit),
            'divisionForecast' => $forecasts->divisionDemand($analytics, $from, $to, $division, $unit),
            'unitForecast' => $forecasts->unitDemand($analytics, $from, $to, $division, $unit),
            'equipmentForecast' => $forecasts->equipment($from, $to, ForecastService::EQUIPMENT_LIMIT, $division, $unit),
            'busyPeriod' => $forecasts->busyPeriod($analytics, $from, $to, $division, $unit),
            'coverage' => $analytics->stockCoverage($inventory, $from, $to, 5, $division, $unit),
            'forecastBasis' => $forecasts->basis(),
        ];
    }
}
