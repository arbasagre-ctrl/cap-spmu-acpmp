<?php

namespace App\Support;

/**
 * Links from an Analytics figure to the records that produced it.
 *
 * Analytics explains what happened; Reports lists the records. Rather than
 * building a second set of record pages, every drill-down here points at the
 * existing Reports module and carries the analytics filters across, so the
 * list that opens is the same scope the figure was counted from.
 *
 * The Reports side validates every parameter and drops anything it does not
 * recognise, so a filter that does not apply to the destination report is
 * ignored rather than producing a wrong list.
 */
final class AnalyticsDrilldown
{
    /**
     * A Reports URL carrying the current analytics scope.
     *
     * @param  array<string, string|null>  $extra  report-specific filters
     */
    public static function report(
        string $report,
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $extra = []
    ): string {
        $parameters = array_filter(
            array_merge(
                [
                    'report' => $report,
                    'academic_period' => $periodSelection,
                    'division' => self::scalar($division),
                    'unit' => self::scalar($unit),
                ],
                $extra
            ),
            static fn ($value): bool => $value !== null && $value !== '' && $value !== 'all'
        );

        return route('reports.index', $parameters);
    }

    /** Borrowing Activity, the record list behind a request count. */
    public static function borrowing(
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $extra = []
    ): string {
        return self::report('borrowing', $periodSelection, $division, $unit, $extra);
    }

    /** Release & Custody, the record list behind an on-custody figure. */
    public static function custody(
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $extra = []
    ): string {
        return self::report('custody', $periodSelection, $division, $unit, $extra);
    }

    /** Return & Accountability, the record list behind a return figure. */
    public static function returns(
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $extra = []
    ): string {
        return self::report('returns', $periodSelection, $division, $unit, $extra);
    }

    /**
     * Inventory Status. Division and unit are deliberately not passed: an
     * inventory snapshot is not owned by a borrowing unit.
     */
    public static function inventory(string $periodSelection, array $extra = []): string
    {
        return self::report('inventory', $periodSelection, null, null, $extra);
    }

    /** Equipment Utilization, the releases behind a usage figure. */
    public static function utilization(
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $extra = []
    ): string {
        return self::report('utilization', $periodSelection, $division, $unit, $extra);
    }

    private static function scalar(?string $value): ?string
    {
        return $value === null || $value === '' || $value === 'all' ? null : $value;
    }
}
