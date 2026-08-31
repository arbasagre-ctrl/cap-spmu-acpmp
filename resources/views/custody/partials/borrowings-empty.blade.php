@php
    $emptyHidden = $emptyHidden ?? false;
@endphp

<div class="borrowings-empty" @if($emptyHidden) hidden @endif>
    <div class="borrowings-empty-content">
        <svg
            class="borrowings-empty-illustration"
            width="200"
            height="170"
            viewBox="0 0 200 170"
            fill="none"
            aria-hidden="true"
            focusable="false"
        >
            {{-- Soft circular backdrop --}}
            <circle class="borrowings-empty-backdrop" cx="98" cy="80" r="70" />

            {{-- Open box: flaps, rim, then the two front faces --}}
            <g class="borrowings-empty-box" stroke-width="2.1" stroke-linejoin="round" stroke-linecap="round">
                <path d="M54 84 34 74 80 52l20 10Z" />
                <path d="M146 84l20-10-46-22-20 10Z" />
                <path d="M100 62l46 22-46 22-46-22Z" />
                <path d="M54 84v32l46 22v-32Z" />
                <path d="M146 84v32l-46 22v-32Z" />
            </g>

            {{-- Checklist resting against the box --}}
            <g class="borrowings-empty-clipboard" stroke-width="2.2" stroke-linejoin="round">
                <rect x="120" y="90" width="46" height="56" rx="5" />
                <rect class="borrowings-empty-clip" x="134" y="84" width="18" height="10" rx="3" />
            </g>

            <g class="borrowings-empty-checks" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <rect x="128" y="105" width="10" height="10" rx="2.5" />
                <path d="m130.4 109.8 1.9 1.9 3.4-3.8" />
                <path d="M144 110h15" />
                <rect x="128" y="123" width="10" height="10" rx="2.5" />
                <path d="m130.4 127.8 1.9 1.9 3.4-3.8" />
                <path d="M144 128h15" />
            </g>

            {{-- Sparkles --}}
            <g class="borrowings-empty-sparkles">
                <path d="M45 44c.4 4 1.2 4.8 5.2 5.2-4 .4-4.8 1.2-5.2 5.2-.4-4-1.2-4.8-5.2-5.2 4-.4 4.8-1.2 5.2-5.2Z" />
                <path d="M160 41c.3 3.1 1 3.8 4.1 4.1-3.1.3-3.8 1-4.1 4.1-.3-3.1-1-3.8-4.1-4.1 3.1-.3 3.8-1 4.1-4.1Z" />
                <path d="M38 112c.3 2.7.9 3.3 3.6 3.6-2.7.3-3.3.9-3.6 3.6-.3-2.7-.9-3.3-3.6-3.6 2.7-.3 3.3-.9 3.6-3.6Z" />
                <path d="M172 88c.3 2.5.8 3 3.3 3.3-2.5.3-3 .8-3.3 3.3-.3-2.5-.8-3-3.3-3.3 2.5-.3 3-.8 3.3-3.3Z" />
            </g>
        </svg>

        <h2>{{ $emptyTitle }}</h2>
        <p>{{ $emptyMessage }}</p>

        <a class="button secondary ui-pressable borrowings-empty-action" href="{{ route('requests.index') }}">
            <x-icon name="requests" size="18" />
            View My Requests
        </a>
    </div>
</div>
