<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Services\AnalyticsDetailService;
use App\Services\AnalyticsService;
use App\Services\ForecastService;
use App\Services\InventoryService;
use App\Services\ReportingPeriodService;
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
 * Only the active section's figures are computed. Opening Overview must not
 * pay for the forecast, and opening Forecast must not pay for the returns
 * breakdown.
 */
class AnalyticsController extends Controller
{
    /** The sections offered in the sub-navigation, in display order. */
    public const SECTIONS = [
        'overview' => 'Overview',
        'demand' => 'Demand & Usage',
        'inventory' => 'Inventory Health',
        'returns' => 'Borrowing & Returns',
        'predictive' => 'Predictive Analytics',
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
    ): View {
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

        if (! array_key_exists($division, AnalyticsService::DIVISIONS)) {
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
                $periodSelection
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

            'divisions' => AnalyticsService::DIVISIONS,
            'selectedDivision' => $division,
            'selectedUnit' => $unit,
            'unitOptions' => $unitOptions,
            'selectableUnits' => $selectableUnits,
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
                $periodSelection
            )
        );
    }

    /**
     * The figures a single section needs, and nothing else.
     *
     * Opening Overview must not pay for the forecast, and opening Predictive
     * Analytics must not pay for the returns breakdown.
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
        string $periodSelection
    ): array {
        if ($section === 'overview') {
            $overview = $analytics->overview($from, $to, $division, $unit);
            $groups = $analytics->borrowerGroups($from, $to, $division, $unit);
            $units = $analytics->unitRankings($from, $to, $division, $unit);
            $equipment = $analytics->equipment($from, $to, $division, $unit);

            /*
             * Granularity follows the selected period rather than a fixed
             * value, so a semester view is not bucketed as if it were a month.
             */
            $trend = $analytics->trend($from, $to, $division, $unit, $periodSelection);
            $returns = $analytics->returns($from, $to, $division, $unit);

            return [
                'overview' => $overview,
                'groups' => $groups,
                'units' => $units,
                'equipment' => $equipment,
                'trend' => $trend,
                'comparison' => $analytics->previousPeriod($from, $to, $division, $unit),
                'lowAvailability' => $analytics->lowAvailability($inventory),
                'insights' => $analytics->insights(
                    $overview,
                    $groups,
                    $units,
                    $equipment,
                    $trend,
                    $returns
                ),
            ];
        }

        if ($section === 'demand') {
            return [
                'trend' => $analytics->trend($from, $to, $division, $unit, $periodSelection),
                'requested' => $analytics->requestedEquipment($from, $to, $division, $unit, 10),
                'released' => $analytics->equipment($from, $to, $division, $unit, 10),
                'slowMoving' => $analytics->slowMovingItems($from, $to),
                'groups' => $analytics->borrowerGroups($from, $to, $division, $unit),
                'units' => $analytics->unitRankings($from, $to, $division, $unit),
                'peak' => $analytics->peakBorrowing($from, $to, $division, $unit),
            ];
        }

        if ($section === 'inventory') {
            return [
                'inventory' => $analytics->inventory($inventory),
                'lowAvailability' => $analytics->lowAvailability($inventory, 10),
                'released' => $analytics->equipment($from, $to, $division, $unit, 5),
                'slowMoving' => $analytics->slowMovingItems($from, $to, 5),
                'coverage' => $analytics->stockCoverage($inventory, $from, $to),
            ];
        }

        if ($section === 'returns') {
            return [
                'groups' => $analytics->borrowerGroups($from, $to, $division, $unit),
                'units' => $analytics->unitRankings($from, $to, $division, $unit),
                'returns' => $analytics->returns($from, $to, $division, $unit),
                'borrowers' => $analytics->frequentBorrowers($from, $to, $division, $unit),
                'lateBorrowers' => $analytics->lateReturnBorrowers($from, $to, $division, $unit),
                'incidents' => $analytics->incidentSummary($from, $to, $division, $unit),
            ];
        }

        /* Predictive Analytics. */
        [$forecastFrom, $forecastTo] = $forecasts->forecastWindow($from, $to);

        return [
            'forecastFrom' => $forecastFrom,
            'forecastTo' => $forecastTo,
            'demand' => $forecasts->demand($analytics, $from, $to, $division, $unit),
            'divisionForecast' => $forecasts->divisionDemand($analytics, $from, $to),
            'unitForecast' => $forecasts->unitDemand($analytics, $from, $to),
            'equipmentForecast' => $forecasts->equipment($from, $to),
            'busyPeriod' => $forecasts->busyPeriod($analytics, $from, $to),
            'coverage' => $analytics->stockCoverage($inventory, $from, $to, 5),
            'forecastBasis' => $forecasts->basis(),
        ];
    }
}
