<?php

namespace App\Services;

use App\Models\AcademicPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Reporting period resolution shared by Reports and Analytics.
 *
 * Both modules must answer "which dates does 'This Semester' mean?" the same
 * way, otherwise the same filter would produce different numbers on the two
 * pages. This is the single answer; the logic is unchanged from the version
 * that previously lived inside ReportController.
 */
class ReportingPeriodService
{
    /**
     * @param  \Illuminate\Support\Collection<int, AcademicPeriod>  $academicPeriods
     * @return array{0:Carbon,1:Carbon,2:?AcademicPeriod,3:string}
     */
    public function resolve(
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
}
