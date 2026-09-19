<?php

namespace Tests\Unit;

use App\Support\PeriodComparison;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic and wording behind every period-over-period line.
 *
 * Every expectation is a figure worked out by hand: a rule that drifts - a
 * rate put through percentage-change maths, a division by a zero previous
 * period, an unmeasurable rate quietly becoming 0% - fails here.
 */
class PeriodComparisonTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* Counts and quantities                                               */
    /* ------------------------------------------------------------------ */

    public function test_percentage_change_is_calculated_when_the_previous_period_is_positive(): void
    {
        $up = PeriodComparison::count(120, 100);

        $this->assertSame(20, $up['change']);
        $this->assertSame(20.0, $up['percent']);
        $this->assertSame('up', $up['direction']);
        $this->assertSame('↑', $up['glyph']);
        $this->assertSame('Up 20% vs previous period', $up['label']);
        $this->assertSame('20% vs previous period', $up['short']);

        $down = PeriodComparison::count(80, 100);

        $this->assertSame(-20, $down['change']);
        $this->assertSame(-20.0, $down['percent']);
        $this->assertSame('down', $down['direction']);
        $this->assertSame('↓', $down['glyph']);
        $this->assertSame('Down 20% vs previous period', $down['label']);

        $same = PeriodComparison::count(100, 100);

        $this->assertSame(0, $same['change']);
        $this->assertSame(0.0, $same['percent']);
        $this->assertSame('same', $same['direction']);
        $this->assertSame('', $same['glyph']);
        $this->assertSame('No change vs previous period', $same['label']);
    }

    public function test_fractional_percentages_keep_one_decimal_and_whole_ones_none(): void
    {
        $this->assertSame('Up 14.3% vs previous period', PeriodComparison::count(24, 21)['label']);
        $this->assertSame(14.3, PeriodComparison::count(24, 21)['percent']);
        $this->assertSame('Down 8% vs previous period', PeriodComparison::count(92, 100)['label']);
    }

    public function test_quantities_compare_like_counts(): void
    {
        $comparison = PeriodComparison::count(183.0, 199.0);

        $this->assertSame(183, $comparison['current']);
        $this->assertSame(199, $comparison['previous']);
        $this->assertSame(-16, $comparison['change']);
        $this->assertSame(-8.0, $comparison['percent']);
        $this->assertSame('Down 8% vs previous period', $comparison['label']);
    }

    public function test_a_zero_previous_period_never_divides(): void
    {
        $comparison = PeriodComparison::count(7, 0);

        $this->assertNull($comparison['percent']);
        $this->assertSame(7, $comparison['change']);
        $this->assertStringNotContainsString('%', $comparison['label']);
        $this->assertStringNotContainsString('INF', $comparison['label']);
    }

    public function test_previous_zero_with_current_activity_says_up_from_zero(): void
    {
        $comparison = PeriodComparison::count(7, 0);

        $this->assertSame('up', $comparison['direction']);
        $this->assertSame('Up from 0 in previous period', $comparison['label']);
        $this->assertSame('Up from 0 in previous period', $comparison['short']);
    }

    public function test_both_periods_at_zero_say_no_activity_in_either_period(): void
    {
        $comparison = PeriodComparison::count(0, 0);

        $this->assertSame('same', $comparison['direction']);
        $this->assertNull($comparison['percent']);
        $this->assertSame('No activity in either period', $comparison['label']);
    }

    public function test_an_unfinished_previous_period_is_not_compared(): void
    {
        $comparison = PeriodComparison::count(5, 0, available: false);

        $this->assertFalse($comparison['available']);
        $this->assertNull($comparison['direction']);
        $this->assertNull($comparison['percent']);
        $this->assertSame('Previous period not yet complete', $comparison['label']);
    }

    public function test_a_running_current_period_is_flagged_but_still_compared(): void
    {
        $comparison = PeriodComparison::count(12, 10, available: true, currentComplete: false);

        $this->assertFalse($comparison['current_complete']);
        $this->assertSame('Up 20% vs previous period', $comparison['label']);
    }

    /* ------------------------------------------------------------------ */
    /* Rates                                                               */
    /* ------------------------------------------------------------------ */

    public function test_rates_are_compared_in_percentage_points_not_percentage_change(): void
    {
        $comparison = PeriodComparison::rate(91.4, 88.2);

        $this->assertTrue($comparison['measurable']);
        $this->assertSame(3.2, $comparison['points']);
        $this->assertSame('up', $comparison['direction']);
        $this->assertSame('Up 3.2 percentage points vs previous period', $comparison['label']);
        $this->assertSame('3.2 percentage points vs previous period', $comparison['short']);

        /* (91.4 - 88.2) / 88.2 would be 3.6%; that figure must appear nowhere. */
        $this->assertStringNotContainsString('3.6', $comparison['label']);
        $this->assertArrayNotHasKey('percent', $comparison);

        $down = PeriodComparison::rate(50.0, 66.7);
        $this->assertSame(-16.7, $down['points']);
        $this->assertSame('Down 16.7 percentage points vs previous period', $down['label']);

        $one = PeriodComparison::rate(51.0, 50.0);
        $this->assertSame('Up 1 percentage point vs previous period', $one['label']);

        $same = PeriodComparison::rate(75.0, 75.0);
        $this->assertSame('No change vs previous period', $same['label']);
    }

    public function test_a_rate_with_no_completed_returns_is_not_measurable(): void
    {
        $noPrevious = PeriodComparison::rate(91.4, null);

        $this->assertFalse($noPrevious['measurable']);
        $this->assertNull($noPrevious['points']);
        $this->assertNull($noPrevious['direction']);
        $this->assertSame('Previous-period rate not measurable', $noPrevious['label']);

        $noCurrent = PeriodComparison::rate(null, 88.2);

        $this->assertFalse($noCurrent['measurable']);
        $this->assertSame('Current-period rate not measurable', $noCurrent['label']);

        $neither = PeriodComparison::rate(null, null);

        $this->assertFalse($neither['measurable']);
        $this->assertSame('Rate not measurable in either period', $neither['label']);

        /* Not measurable is never restated as a zero movement. */
        foreach ([$noPrevious, $noCurrent, $neither] as $comparison) {
            $this->assertStringNotContainsString('0 percentage', $comparison['label']);
            $this->assertStringNotContainsString('No change', $comparison['label']);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Detail block                                                        */
    /* ------------------------------------------------------------------ */

    public function test_the_detail_block_states_both_periods_and_the_change(): void
    {
        $block = PeriodComparison::block(PeriodComparison::count(24, 21), '02 Mar 2026 – 31 Mar 2026', 'requests');

        $this->assertSame('Compared with the previous period', $block['title']);
        $this->assertSame('02 Mar 2026 – 31 Mar 2026', $block['window']);
        $this->assertNull($block['note']);
        $this->assertSame([
            ['Current period', '24 requests'],
            ['Previous period', '21 requests'],
            ['Change', '+3 requests (+14.3%)'],
        ], $block['rows']);

        /* Two bars against the larger figure: display geometry only. */
        $this->assertSame([
            ['label' => 'Previous period', 'value' => '21 requests', 'share' => 88],
            ['label' => 'Current period', 'value' => '24 requests', 'share' => 100],
        ], $block['bars']);
    }

    public function test_the_detail_block_for_a_rate_uses_points_and_no_bars(): void
    {
        $block = PeriodComparison::block(PeriodComparison::rate(91.4, 88.2), 'window');

        $this->assertSame([
            ['Current period', '91.4%'],
            ['Previous period', '88.2%'],
            ['Difference', '+3.2 percentage points'],
        ], $block['rows']);
        $this->assertNull($block['bars']);

        $unmeasurable = PeriodComparison::block(PeriodComparison::rate(91.4, null), 'window');

        $this->assertSame(['Previous period', 'Not measurable'], $unmeasurable['rows'][1]);
        $this->assertSame(['Difference', 'Not measurable'], $unmeasurable['rows'][2]);
    }

    public function test_the_detail_block_handles_zero_and_incomplete_periods(): void
    {
        $fromZero = PeriodComparison::block(PeriodComparison::count(7, 0), 'window', 'units');
        $this->assertSame(['Change', '+7 units (from 0)'], $fromZero['rows'][2]);

        $single = PeriodComparison::block(PeriodComparison::count(1, 2), 'window', 'returns');
        $this->assertSame(['Current period', '1 return'], $single['rows'][0]);
        $this->assertSame(['Change', '−1 return (−50%)'], $single['rows'][2]);
        $this->assertSame(0, $fromZero['bars'][0]['share']);
        $this->assertSame(100, $fromZero['bars'][1]['share']);

        $nothing = PeriodComparison::block(PeriodComparison::count(0, 0), 'window');
        $this->assertSame(['Change', 'No activity in either period'], $nothing['rows'][2]);
        $this->assertNull($nothing['bars']);

        $running = PeriodComparison::block(PeriodComparison::count(5, 10, true, false), 'window');
        $this->assertStringContainsString('still in progress', $running['note']);

        $unfinished = PeriodComparison::block(PeriodComparison::count(5, 0, false), 'window');
        $this->assertStringContainsString('has not ended yet', $unfinished['note']);
        $this->assertSame(['Previous period', '—'], $unfinished['rows'][1]);
        $this->assertNull($unfinished['bars']);
    }
}
