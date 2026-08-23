@props(['user'])

@php
    $initials = collect(
        preg_split('/\s+/', trim($user->full_name))
    )
        ->filter()
        ->take(2)
        ->map(
            fn ($part) =>
                mb_strtoupper(mb_substr($part, 0, 1))
        )
        ->implode('');

    $classificationLabel =
        $user->access_classification?->label()
        ?? 'Authorized User';

    $hasProfilePicture =
        filled($user->profile_picture_path)
        && Route::has('profile.picture.show');

    $profilePictureVersion =
        $user->updated_at?->timestamp
        ?? time();
@endphp

<div
    class="account-menu"
    data-account-menu
>
    <button
        class="account-menu-toggle ui-pressable"
        type="button"
        aria-label="Open account menu for {{ $user->full_name }}"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="account-menu-dropdown"
        title="Account menu"
        data-account-menu-toggle
    >
        @if($hasProfilePicture)
            <span
                class="user-avatar"
                aria-hidden="true"
                style="
                    overflow: hidden;
                    padding: 0;
                    background: transparent;
                "
            >
                <img
                    src="{{ route('profile.picture.show') }}?v={{ $profilePictureVersion }}"
                    alt=""
                    style="
                        display: block;
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                        border-radius: 50%;
                    "
                >
            </span>
        @else
            <span
                class="user-avatar"
                aria-hidden="true"
            >
                {{ $initials ?: 'U' }}
            </span>
        @endif

        <span class="account-menu-name">
            {{ $user->full_name }}
        </span>

        <x-icon
            name="chevron-down"
            size="16"
            class="account-menu-chevron"
        />
    </button>

    <div
        class="account-menu-dropdown"
        id="account-menu-dropdown"
        role="menu"
        aria-hidden="true"
        data-account-menu-dropdown
    >
        <div class="account-menu-identity">
            <strong>{{ $user->full_name }}</strong>
            <span>{{ $classificationLabel }}</span>
        </div>

        <div class="account-menu-actions">
            <a
                href="{{ route('profile.show') }}"
                role="menuitem"
            >
                <x-icon
                    name="profile"
                    size="18"
                />

                <span>Account Settings</span>
            </a>

            <form
                action="{{ route('logout') }}"
                method="post"
            >
                @csrf

                <button
                    class="account-menu-logout"
                    type="submit"
                    role="menuitem"
                >
                    <x-icon
                        name="logout"
                        size="18"
                    />

                    <span>Log out</span>
                </button>
            </form>
        </div>
    </div>
</div>
