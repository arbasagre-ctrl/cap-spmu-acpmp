<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
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
        'borrowers' => 'Borrowers',
        'equipment' => 'Equipment',
        'returns' => 'Returns',
        'forecast' => 'Forecast',
    ];

    public function __invoke(
        Request $request,
        AnalyticsService $analytics,
        ForecastService $forecasts,
        InventoryService $inventory,
        ReportingPeriodService $periods
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
            $section = 'overview';
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

        $data = [
            'section' => $section,
            'sections' => self::SECTIONS,

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
                $unitFilter
            )
        );
    }

    /**
     * The figures a single section needs, and nothing else.
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
        ?string $unit
    ): array {
        if ($section === 'overview') {
            $overview = $analytics->overview($from, $to, $division, $unit);
            $groups = $analytics->borrowerGroups($from, $to, $division, $unit);
            $units = $analytics->unitRankings($from, $to, $division, $unit);
            $equipment = $analytics->equipment($from, $to, $division, $unit);
            $trend = $analytics->trend($from, $to, $division, $unit, 'month');
            $returns = $analytics->returns($from, $to, $division, $unit);

            return [
                'overview' => $overview,
                'groups' => $groups,
                'units' => $units,
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

        if ($section === 'borrowers') {
            return [
                'groups' => $analytics->borrowerGroups($from, $to, $division, $unit),
                'units' => $analytics->unitRankings($from, $to, $division, $unit),
                'unitEquipment' => $unit === null
                    ? null
                    : $analytics->equipmentForUnit($from, $to, $unit),
            ];
        }

        if ($section === 'equipment') {
            return [
                'requested' => $analytics->requestedEquipment($from, $to, $division, $unit),
                'released' => $analytics->equipment($from, $to, $division, $unit),
                'lowAvailability' => $analytics->lowAvailability($inventory, 10),
            ];
        }

        if ($section === 'returns') {
            return [
                'returns' => $analytics->returns($from, $to, $division, $unit),
                'units' => $analytics->unitRankings($from, $to, $division, $unit),
            ];
        }

        /* Forecast. */
        [$forecastFrom, $forecastTo] = $forecasts->forecastWindow($from, $to);

        return [
            'forecastFrom' => $forecastFrom,
            'forecastTo' => $forecastTo,
            'demand' => $forecasts->demand($analytics, $from, $to, $division, $unit),
            'divisionForecast' => $forecasts->divisionDemand($analytics, $from, $to),
            'unitForecast' => $forecasts->unitDemand($analytics, $from, $to),
            'equipmentForecast' => $forecasts->equipment($from, $to),
            'busyPeriod' => $forecasts->busyPeriod($analytics, $from, $to),
            'forecastBasis' => $forecasts->basis(),
        ];
    }
}
