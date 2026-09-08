<?php

namespace App\Support;

/**
 * Links from an Analytics figure to its own internal detail.
 *
 * This is the primary click. Clicking a number in Analytics is a question
 * about that number, so it opens a detail inside Analytics rather than
 * navigating to the record module; AnalyticsDrilldown handles the secondary
 * step out to Reports once the reader has understood the figure.
 *
 * The current section, reporting period, division and unit are always carried
 * forward, so closing the detail returns the reader to exactly the state they
 * opened it from.
 */
final class AnalyticsDetailLink
{
    /**
     * @param  array<string, string|int|null>  $parameters  detail-specific keys
     */
    public static function to(
        string $detail,
        string $section,
        string $periodSelection,
        ?string $division = null,
        ?string $unit = null,
        array $parameters = []
    ): string {
        $query = array_filter(
            array_merge(
                [
                    'section' => $section,
                    'academic_period' => $periodSelection,
                    'group' => self::scalar($division),
                    'unit' => self::scalar($unit),
                    'detail' => $detail,
                ],
                $parameters
            ),
            static fn ($value): bool => $value !== null && $value !== '' && $value !== 'all'
        );

        return route('analytics.index', $query);
    }

    private static function scalar(?string $value): ?string
    {
        return $value === null || $value === '' || $value === 'all' ? null : $value;
    }
}
