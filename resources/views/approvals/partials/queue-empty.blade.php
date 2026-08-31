@php
    $emptyId = $emptyId ?? null;
    $emptyHidden = $emptyHidden ?? false;
    $emptyVariant = $emptyVariant ?? null;
@endphp

<section
    class="approval-queue-empty {{ $emptyVariant === 'no-results' ? 'approval-queue-no-results' : '' }}"
    @if($emptyId) id="{{ $emptyId }}" @endif
    @if($emptyHidden) hidden @endif
>
    <div class="approval-queue-empty-content">
        <span class="approval-queue-empty-icon" aria-hidden="true">
            <svg class="ui-icon" width="44" height="44" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8h-4a3 3 0 0 0-3 3v27a3 3 0 0 0 3 3h16" />
                <path d="M30 8h4a3 3 0 0 1 3 3v11" />
                <rect x="18" y="5" width="12" height="7" rx="2" />
                <path d="M18 21h9M18 28h6" />
                <circle cx="35" cy="34" r="8" />
                <path d="m31.6 34 2.4 2.4 4.4-4.8" />
            </svg>
        </span>

        <h2>{{ $emptyTitle }}</h2>
        <p>{{ $emptyMessage }}</p>
    </div>
</section>
