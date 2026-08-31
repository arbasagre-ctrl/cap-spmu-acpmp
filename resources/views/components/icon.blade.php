@props(['name', 'size' => 20])

<svg {{ $attributes->class('ui-icon') }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($name)
        @case('dashboard')
            <path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" />
            @break
        @case('inventory')
            <path d="m4 7 8-4 8 4-8 4-8-4Z" /><path d="m4 7 8 4 8-4M4 7v10l8 4 8-4V7M12 11v10" />
            @break
        @case('calendar')
            <rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" />
            @break
        @case('requests')
            <path d="M6 3h9l3 3v15H6z" /><path d="M14 3v4h4M9 12h6M9 16h6M9 8h2" />
            @break
        @case('custody')
            <path d="M7 7h11M14 3l4 4-4 4M17 17H6M10 13l-4 4 4 4" />
            @break
        @case('accountability')
            <path d="M12 3 2.8 20h18.4L12 3Z" /><path d="M12 9v5M12 17.5v.1" />
            @break
        @case('approval')
            <path d="M20 6 9 17l-5-5" />
            @break
        @case('reports')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z" />
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8" />
            @break
        @case('delegation')
            <path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM17 11l2 2 4-4" />
            @break
        @case('notifications')
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M14 21h-4" />
            @break
        @case('printer')
            <path d="M7 9V4h10v5" /><path d="M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" /><path d="M7 14h10v6H7z" /><path d="M8 18h8" />
            @break
        @case('profile')
            <circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" />
            @break
        @case('logout')
            <path d="M10 17l5-5-5-5M15 12H3M15 3h5a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1h-5" />
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16" />
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18" />
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('plus-circle')
            <circle cx="12" cy="12" r="9" /><path d="M12 8.5v7M8.5 12h7" />
            @break
        @case('clipboard-check')
            <path d="M9 4H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2" /><rect x="9" y="2" width="6" height="4" rx="1" /><path d="m9 14 2 2 4-4" />
            @break
        @case('shield-check')
            <path d="M12 2 3 5.5V11c0 5 3 8 9 9.5 6-1.5 9-4.5 9-9.5V5.5L12 2Z" /><path d="m8.5 11.6 2.4 2.4 4.6-4.6" />
            @break
        @case('file-search')
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3" /><path d="M14 3l5 5v3" /><path d="M14 3v5h5" /><circle cx="16.5" cy="16.5" r="3.5" /><path d="m19.2 19.2 2.3 2.3" />
            @break
        @case('mask')
            <path d="M3.5 12h-1M22.5 12h-1" /><ellipse cx="8" cy="12" rx="4" ry="3.4" /><ellipse cx="16" cy="12" rx="4" ry="3.4" /><path d="M12 12h0" />
            @break
        @case('trash')
            <path d="M4 7h16" /><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" /><path d="M6 7v13a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7" /><path d="M10 11.5v6M14 11.5v6" />
            @break
        @case('edit')
            <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z" />
            @break
        @case('upload')
            <path d="M12 16V4M7 9l5-5 5 5" /><path d="M5 20h14" />
            @break
        @case('chevron-right')
            <path d="m9 18 6-6-6-6" />
            @break
        @case('chevron-down')
            <path d="m6 9 6 6 6-6" />
            @break
        @case('information')
            <circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 7.5v.1" />
            @break
        @case('success')
            <circle cx="12" cy="12" r="9" /><path d="m8 12 2.5 2.5L16 9" />
            @break
        @case('error')
            <circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16.5v.1" />
            @break
        @case('email')
            <path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" /><path d="m22 6-10 7-10-7" />
            @break
        @case('lock')
            <rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
            @break
        @case('eye')
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" /><circle cx="12" cy="12" r="3" />
            @break
        @case('shield-lock')
            <path d="M12 2 3 5.5V11c0 5 3 8 9 9.5 6-1.5 9-4.5 9-9.5V5.5L12 2Z" /><rect x="10" y="9" width="4" height="6" rx="0.5" /><path d="M12 15v1" />
            @break
        @case('help')
            <circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17v.01" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" /><path d="m20 20-4.4-4.4" />
            @break
        @case('box')
            <path d="m3.5 8 8.5-4.5L20.5 8 12 12.5 3.5 8Z" /><path d="M3.5 8v8L12 20.5l8.5-4.5V8M12 12.5V20.5" />
            @break
        @case('bookmark')
            <path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1Z" />
            @break
        @case('warning')
            <path d="M12 3 2.8 20h18.4L12 3Z" /><path d="M12 9v5M12 17.5v.1" />
            @break
        @case('check-circle')
            <circle cx="12" cy="12" r="9" /><path d="m8.2 12.2 2.6 2.6 5-5.6" />
            @break
        @case('banknote')
            <rect x="2" y="6" width="20" height="12" rx="2" /><circle cx="12" cy="12" r="2.5" /><path d="M6 12h.01M18 12h.01" />
            @break
        @case('external-link')
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" /><path d="M15 3h6v6" /><path d="M10 14 21 3" />
            @break
        @case('arrow-left')
            <path d="M20 12H4" /><path d="m10 6-6 6 6 6" />
            @break
        @case('lightbulb')
            <path d="M9 18h6" /><path d="M10 21.5h4" /><path d="M12 2.5a6.5 6.5 0 0 0-3.7 11.84c.5.35.8.9.85 1.5l.05.66h5.6l.05-.66c.05-.6.35-1.15.85-1.5A6.5 6.5 0 0 0 12 2.5Z" />
            @break
        @case('save')
            <path d="M5 3h11l3 3v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" /><path d="M8 3v6h7V3" /><path d="M8 14h8v7H8z" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5.3l3.4 2" />
            @break
        @case('calendar-clock')
            <path d="M20 11V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6" /><path d="M15 2v4M7 2v4M2 9.5h18" /><circle cx="17.5" cy="17" r="4.6" /><path d="M17.5 14.9v2.2l1.6.9" />
            @break
        @case('id-badge')
            <rect x="4" y="3" width="16" height="18" rx="2" /><path d="M9.2 3h5.6v2.6H9.2z" /><circle cx="12" cy="11.2" r="2.2" /><path d="M8.4 17.4a3.7 3.7 0 0 1 7.2 0" />
            @break
        @case('cycle')
            <path d="M3.5 12a8.5 8.5 0 0 1 14.4-6.1L21 8.7" /><path d="M21 4.2v4.5h-4.5" /><path d="M20.5 12a8.5 8.5 0 0 1-14.4 6.1L3 15.3" /><path d="M3 19.8v-4.5h4.5" />
            @break
        @case('location')
            <path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z" /><circle cx="12" cy="10" r="2.5" />
            @break
        @case('linen')
            <path d="m8 3-6 4 3 5 3-2v11h8V10l3 2 3-5-6-4a4 4 0 0 1-8 0Z" />
            @break
        @case('receipt')
            <path d="M5 3h14v19l-3-2-4 2-4-2-3 2V3Z" /><path d="M8 7h8M8 11h8M8 15h5" />
            @break
        @case('more')
            <circle cx="12" cy="5" r="1.4" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none" /><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none" />
            @break
        @case('sort')
            <path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3" />
            @break
        @default
            <circle cx="12" cy="12" r="9" />
    @endswitch
</svg>
