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

    $filterSearch = $filterable
        ? \Illuminate\Support\Str::lower(trim(collect([
            $event['reference'] ?? null,
            $event['purpose'] ?? null,
            $event['office'] ?? null,
        ])->filter()->implode(' ')))
        : '';

    $phaseText = \Illuminate\Support\Str::lower((string) ($phaseLabel ?? ''));
    $phaseCategories = collect();

    if ($event['is_overdue']) {
        $phaseCategories->push('attention');
    }
    if (\Illuminate\Support\Str::contains($phaseText, ['returned'])) {
        $phaseCategories->push('returned');
    }
    if (\Illuminate\Support\Str::contains($phaseText, ['return due', 'adjusted return', 'original return'])) {
        $phaseCategories->push('return');
    }
    if (\Illuminate\Support\Str::contains($phaseText, ['pickup', 'release', 'approved use begins', 'ongoing borrowing'])) {
        $phaseCategories->push('pickup');
    }

    if ($phaseCategories->isEmpty()) {
        $phaseCategories->push(match (true) {
            $event['is_overdue'] => 'attention',
            $event['is_closed'] => 'returned',
            $event['is_due_soon'] => 'return',
            default => 'pickup',
        });
    }

    $phaseCategories = $phaseCategories->unique()->values();
    $phaseCategory = match (true) {
        $phaseCategories->contains('attention') => 'attention',
        $phaseCategories->contains('returned') => 'returned',
        $phaseCategories->contains('return') => 'return',
        default => 'pickup',
    };
@endphp

<button
    type="button"
    {{ $attributes->class([
        'calendar-event',
        'calendar-event-'.$variant,
        'calendar-event-'.$tone,
        'calendar-phase-'.$phaseCategory,
        'calendar-event-own' => $event['own_record'],
        'ui-pressable',
    ]) }}
    data-calendar-event="{{ $event['key'] }}"
    data-calendar-sort-date="{{ $event['start_at']->timestamp }}"
    @if($filterable)
        data-calendar-filter-statuses="{{ $filterStatuses }}"
        data-calendar-filter-search="{{ $filterSearch }}"
        data-calendar-phase-categories="{{ $phaseCategories->implode(' ') }}"
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
