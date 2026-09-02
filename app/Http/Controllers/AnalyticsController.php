<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\AcademicPeriod;
use App\Services\AnalyticsService;
use App\Services\InventoryService;
use App\Services\ReportingPeriodService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Analytics workspace for the SPMU Head.
 *
 * The controller only resolves the filters and hands the prepared figures to
 * the view. Every calculation lives in AnalyticsService, so Reports and
 * Analytics cannot drift apart and the Blade template stays a layout.
 */
class AnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        AnalyticsService $analytics,
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

        $overview = $analytics->overview($from, $to, $divisionFilter, $unitFilter);
        $groups = $analytics->borrowerGroups($from, $to, $divisionFilter, $unitFilter);
        $units = $analytics->unitRankings($from, $to, $divisionFilter, $unitFilter);
        $equipment = $analytics->equipment($from, $to, $divisionFilter, $unitFilter);
        $trend = $analytics->trend($from, $to, $divisionFilter, $unitFilter, $periodSelection);
        $returns = $analytics->returns($from, $to, $divisionFilter, $unitFilter);

        return view('analytics.index', [
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

            'overview' => $overview,
            'groups' => $groups,
            'units' => $units,
            'equipment' => $equipment,
            'trend' => $trend,
            'returns' => $returns,
            'inventory' => $analytics->inventory($inventory),
            'insights' => $analytics->insights($overview, $groups, $units, $equipment, $trend, $returns),
        ]);
    }
}
