@props(['event', 'phaseLabel' => null, 'variant' => 'cell', 'filterable' => false])

@php
    $tone = match (true) {
        $event['is_overdue'] => 'overdue',
        $event['is_closed'] => 'closed',
        $event['is_due_soon'] => 'due-soon',
        $event['is_active'] => 'active',
        default => 'scheduled',
    };
    $filterStatuses = $filterable
        ? collect([
            'active' => $event['is_active'],
            'due-soon' => $event['is_due_soon'],
            'overdue' => $event['is_overdue'],
            'returned' => $event['is_closed'],
        ])->filter()->keys()->implode(' ')
        : '';
@endphp

<button
    type="button"
    {{ $attributes->class([
        'calendar-event',
        'calendar-event-'.$variant,
        'calendar-event-'.$tone,
        'calendar-event-own' => $event['own_record'],
        'ui-pressable',
    ]) }}
    data-calendar-event="{{ $event['key'] }}"
    @if($filterable)
        data-calendar-filter-statuses="{{ $filterStatuses }}"
        data-calendar-own-record="{{ $event['own_record'] ? 'true' : 'false' }}"
    @endif
>
    @if($phaseLabel)<span class="calendar-event-phase">{{ $phaseLabel }}</span>@endif
    <span class="calendar-event-reference">{{ $event['reference'] }}</span>
    @if($variant !== 'cell' && $event['purpose'])<span class="calendar-event-purpose">{{ $event['purpose'] }}</span>@endif
    <span class="calendar-event-meta">
        <span>{{ $event['item_count'] }} item {{ \Illuminate\Support\Str::plural('type', $event['item_count']) }}</span>
        <x-status-badge :status="$event['status']" />
    </span>
    @if($variant === 'list')
        <span class="calendar-event-period">{{ $event['start_at']->format('d M Y') }} <span aria-hidden="true">&rarr;</span> {{ $event['due_at']->format('d M Y') }}</span>
    @endif
</button>
