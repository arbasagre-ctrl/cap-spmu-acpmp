@php
    $emptyId = $emptyId ?? null;
    $emptyHidden = $emptyHidden ?? false;
@endphp

<section
    class="custody-oversight-empty"
    @if($emptyId) id="{{ $emptyId }}" @endif
    @if($emptyHidden) hidden @endif
>
    <div class="custody-oversight-empty-content">
        <span class="custody-oversight-empty-icon" aria-hidden="true">
            <svg class="ui-icon" width="42" height="42" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 8h-4a3 3 0 0 0-3 3v27a3 3 0 0 0 3 3h22a3 3 0 0 0 3-3V11a3 3 0 0 0-3-3h-4" />
                <rect x="17" y="5" width="14" height="7" rx="2" />
                <path d="M17 22h14M17 30h9" />
            </svg>
        </span>

        <h2>{{ $emptyTitle }}</h2>
        <p>{{ $emptyMessage }}</p>
    </div>
</section>
