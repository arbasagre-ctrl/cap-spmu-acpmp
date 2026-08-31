@php
    $emptyTitle = $emptyTitle ?? 'No special operational dates configured.';
    $emptyMessage = $emptyMessage ?? 'Add a special date to override the normal weekly schedule.';
@endphp

<div class="special-dates-empty-content">
    <svg class="special-dates-empty-art" width="132" height="104" viewBox="0 0 132 104" fill="none" aria-hidden="true" focusable="false">
        <circle cx="66" cy="52" r="37" fill="var(--blue-50)" />
        <g stroke="var(--interactive)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="52" y="39" width="28" height="26" rx="3.5" />
            <path d="M52 47h28M60 35v7M72 35v7" />
            <path d="M58.5 54.5h3M64.5 54.5h3M70.5 54.5h3M58.5 60h3M64.5 60h3" />
        </g>
        <g fill="var(--info-border)">
            <path d="m20 40 1.5 3.6L25 45l-3.5 1.4L20 50l-1.5-3.6L15 45l3.5-1.4L20 40Z" />
            <path d="m112 62 1.2 2.9 2.8 1.1-2.8 1.1-1.2 2.9-1.2-2.9-2.8-1.1 2.8-1.1 1.2-2.9Z" />
        </g>
        <g fill="var(--success-border)">
            <path d="m110 30 1.5 3.6 3.5 1.4-3.5 1.4-1.5 3.6-1.5-3.6L106 35l3.5-1.4L110 30Z" />
            <path d="m26 70 1.2 2.9 2.8 1.1-2.8 1.1L26 79l-1.2-2.9L22 75l2.8-1.1L26 70Z" />
        </g>
    </svg>

    <h4>{{ $emptyTitle }}</h4>
    <p>{{ $emptyMessage }}</p>
</div>
