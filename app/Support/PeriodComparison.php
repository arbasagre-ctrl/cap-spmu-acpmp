<?php

namespace App\Support;

/**
 * Period-over-period arithmetic and wording for Analytics.
 *
 * This class knows nothing about events or dates. A caller in AnalyticsService
 * measures the same metric over the selected period and over the equal-length
 * period before it - using that metric's own event date each time - and hands
 * the two figures here. Only the arithmetic and the sentence live in one place,
 * so a count and a quantity are compared the same way and a rate is never put
 * through percentage-change maths.
 *
 * WORDING RULES
 * -------------
 * A percentage change is offered only when the previous figure is positive:
 * "up 100% from nothing" is not a reading anyone should act on, and there is
 * no dividing by zero anywhere here. A rate is compared in percentage points,
 * and a rate that could not be measured in either period stays "not
 * measurable" rather than becoming 0%. The wording is factual - up, down, no
 * change - because whether a movement is good depends on the metric.
 */
final class PeriodComparison
{
    /**
     * A count or quantity against its previous-period counterpart.
     *
     * @param  bool  $available  whether the previous period has actually ended
     * @param  bool  $currentComplete  whether the selected period has ended
     * @return array<string, mixed>
     */
    public static function count(
        float|int $current,
        float|int $previous,
        bool $available = true,
        bool $currentComplete = true
    ): array {
        $current = self::number($current);
        $previous = self::number($previous);
        $change = self::number($current - $previous);

        $percent = $previous > 0 ? round($change / $previous * 100, 1) : null;

        $direction = match (true) {
            ! $available => null,
            $change > 0 => 'up',
            $change < 0 => 'down',
            default => 'same',
        };

        [$label, $short] = match (true) {
            ! $available => ['Previous period not yet complete', 'Previous period not yet complete'],
            $previous == 0 && $current == 0 => ['No activity in either period', 'No activity in either period'],
            $previous == 0 => ['Up from 0 in previous period', 'Up from 0 in previous period'],
            $change > 0 => ['Up '.self::percent($percent).' vs previous period', self::percent($percent).' vs previous period'],
            $change < 0 => ['Down '.self::percent($percent).' vs previous period', self::percent($percent).' vs previous period'],
            default => ['No change vs previous period', 'No change vs previous period'],
        };

        return [
            'type' => 'count',
            'available' => $available,
            'current_complete' => $currentComplete,
            'current' => $current,
            'previous' => $previous,
            'change' => $change,
            'percent' => $available ? $percent : null,
            'direction' => $direction,
            'glyph' => self::glyph($direction),
            'label' => $label,
            'short' => $short,
        ];
    }

    /**
     * A rate (already expressed in percent) against its previous-period rate.
     *
     * Either rate may be null, meaning it had no denominator in that period.
     * The difference is in percentage points, never a percentage of a
     * percentage.
     *
     * @return array<string, mixed>
     */
    public static function rate(
        ?float $current,
        ?float $previous,
        bool $available = true,
        bool $currentComplete = true
    ): array {
        $measurable = $available && $current !== null && $previous !== null;
        $points = $measurable ? round($current - $previous, 1) : null;

        $direction = match (true) {
            ! $measurable => null,
            $points > 0 => 'up',
            $points < 0 => 'down',
            default => 'same',
        };

        [$label, $short] = match (true) {
            ! $available => ['Previous period not yet complete', 'Previous period not yet complete'],
            $current === null && $previous === null => ['Rate not measurable in either period', 'Rate not measurable in either period'],
            $current === null => ['Current-period rate not measurable', 'Current-period rate not measurable'],
            $previous === null => ['Previous-period rate not measurable', 'Previous-period rate not measurable'],
            $points > 0 => ['Up '.self::points($points).' vs previous period', self::points($points).' vs previous period'],
            $points < 0 => ['Down '.self::points($points).' vs previous period', self::points($points).' vs previous period'],
            default => ['No change vs previous period', 'No change vs previous period'],
        };

        return [
            'type' => 'rate',
            'available' => $available,
            'current_complete' => $currentComplete,
            'measurable' => $measurable,
            'current' => $current,
            'previous' => $previous,
            'points' => $points,
            'direction' => $direction,
            'glyph' => self::glyph($direction),
            'label' => $label,
            'short' => $short,
        ];
    }

    /**
     * The rows and, for a count, the two-bar strip a detail panel shows.
     *
     * Bar widths are display geometry only: each bar is drawn against the
     * larger of the two figures so the pair can be read at a glance. Nothing
     * here is a business figure the comparison did not already carry.
     *
     * @param  array<string, mixed>  $comparison
     * @return array<string, mixed>
     */
    public static function detail(array $comparison, string $unit = ''): array
    {
        /* "1 request", "3 requests": the plural unit is given, the singular derived. */
        $with = static fn (float|int $value): string => $unit === ''
            ? self::trim($value)
            : self::trim($value).' '.(abs($value) == 1 && str_ends_with($unit, 's') ? substr($unit, 0, -1) : $unit);

        if (($comparison['type'] ?? 'count') === 'rate') {
            $format = static fn (?float $rate): string => $rate === null ? 'Not measurable' : self::trim($rate).'%';

            $difference = match (true) {
                ! $comparison['available'] => 'Previous period not yet complete',
                ! $comparison['measurable'] => 'Not measurable',
                $comparison['points'] > 0 => '+'.self::points($comparison['points']),
                $comparison['points'] < 0 => '−'.self::points(abs($comparison['points'])),
                default => 'No change',
            };

            return [
                'rows' => [
                    ['Current period', $format($comparison['current'])],
                    ['Previous period', $format($comparison['previous'])],
                    ['Difference', $difference],
                ],
                'bars' => null,
            ];
        }

        $current = $comparison['current'];
        $previous = $comparison['previous'];
        $highest = max($current, $previous, 0);

        $change = match (true) {
            ! $comparison['available'] => 'Previous period not yet complete',
            $comparison['change'] > 0 => '+'.$with($comparison['change'])
                .($comparison['percent'] !== null ? ' (+'.self::percent($comparison['percent']).')' : ' (from 0)'),
            $comparison['change'] < 0 => '−'.$with(abs($comparison['change']))
                .' (−'.self::percent(abs((float) $comparison['percent'])).')',
            default => $current == 0 ? 'No activity in either period' : 'No change',
        };

        return [
            'rows' => [
                ['Current period', $with($current)],
                ['Previous period', $comparison['available'] ? $with($previous) : '—'],
                ['Change', $change],
            ],
            'bars' => ! $comparison['available'] || $highest <= 0 ? null : [
                ['label' => 'Previous period', 'value' => $with($previous), 'share' => (int) round($previous / $highest * 100)],
                ['label' => 'Current period', 'value' => $with($current), 'share' => (int) round($current / $highest * 100)],
            ],
        ];
    }

    /**
     * The complete comparison block a detail panel renders.
     *
     * @param  array<string, mixed>  $comparison
     * @param  string  $windowLabel  the previous period's dates, already formatted
     * @return array<string, mixed>
     */
    public static function block(array $comparison, string $windowLabel, string $unit = ''): array
    {
        $detail = self::detail($comparison, $unit);

        return [
            'title' => 'Compared with the previous period',
            'window' => $windowLabel,
            'note' => match (true) {
                ! $comparison['available'] => 'The previous period has not ended yet, so there is no complete period to compare against.',
                ! $comparison['current_complete'] => 'The selected period is still in progress: this compares what has been recorded so far against a completed period of the same length.',
                default => null,
            },
            'rows' => $detail['rows'],
            'bars' => $detail['bars'],
        ];
    }

    /** "14%" or "14.3%" - one decimal, never a trailing .0. */
    public static function percent(?float $percent): string
    {
        return self::trim(abs((float) $percent)).'%';
    }

    /** "3.2 percentage points", "1 percentage point". */
    public static function points(?float $points): string
    {
        $value = abs((float) $points);

        return self::trim($value).' percentage '.($value === 1.0 ? 'point' : 'points');
    }

    /** Whole numbers print without a decimal; everything else keeps one. */
    public static function trim(float|int $value): string
    {
        $rounded = round((float) $value, 1);

        return $rounded == (int) $rounded
            ? (string) (int) $rounded
            : number_format($rounded, 1, '.', '');
    }

    private static function number(float|int $value): float|int
    {
        return is_float($value) && $value == (int) $value ? (int) $value : $value;
    }

    private static function glyph(?string $direction): string
    {
        return match ($direction) {
            'up' => '↑',
            'down' => '↓',
            default => '',
        };
    }
}
