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
                    /*
                     * The global Borrower filter is not one of this link's
                     * explicit parameters: every caller already reflects the
                     * currently selected division/unit for its own row, and
                     * borrower is never a per-row dimension the way those two
                     * are. It is carried forward from the current request
                     * instead, so opening a card or bucket detail can never
                     * silently drop the reader's borrower selection.
                     */
                    'borrower' => self::scalar((string) request()->query('borrower', '')),
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
