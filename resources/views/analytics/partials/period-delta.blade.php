{{--
    One compact period-over-period line.

    Renders a comparison produced by App\Support\PeriodComparison. The arrow
    is decoration: the sentence itself carries the direction ("Up 14% vs
    previous period"), so a screen reader hears the meaning and colour is
    never the only signal. Styling is neutral on purpose - a fall in Returned
    Late is welcome and a fall in Released Quantity is not, so no colour
    passes judgement.

    When the selected period is still running, the line says so: comparing
    twenty days against a finished thirty is a partial reading, not a wrong
    one, and the reader should know which they are looking at.

    @param array  $comparison  PeriodComparison::count() or ::rate() output
    @param string $deltaClass  extra classes for the host layout (optional)
--}}
@php
    $deltaClass = $deltaClass ?? '';
    $toDate = $comparison['available'] && ! $comparison['current_complete'];

    $visible = trim(($comparison['glyph'] !== '' ? $comparison['glyph'].' ' : '')
        .$comparison['short']
        .($toDate ? ' (to date)' : ''));

    $spoken = $comparison['label'].($toDate ? ', selected period still in progress' : '');
@endphp
<span class="analytics-delta {{ $deltaClass }}{{ $comparison['direction'] ? ' is-'.$comparison['direction'] : '' }}" data-period-delta>
    <span aria-hidden="true">{{ $visible }}</span>
    <span class="visually-hidden">{{ $spoken }}</span>
</span>
