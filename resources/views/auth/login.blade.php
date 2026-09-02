@extends('layouts.app', ['title' => 'Sign in'])

@section('content')

<style>
    /*
    |--------------------------------------------------------------------------
    | Login background decoration polish
    |--------------------------------------------------------------------------
    | Keep the right-side soft circle from the existing design, then render
    | the left circle as a real pseudo-element positioned fully inside the
    | login area so it is no longer cut in half by the page boundary.
    */
    .login-page {
        background:
            radial-gradient(
                circle at 92% 52%,
                rgba(72, 139, 230, 0.10) 0,
                rgba(72, 139, 230, 0.10) 14%,
                transparent 14.2%
            ),
            #f3f7fc;
    }

    .login-page::before {
        content: "";
        position: absolute;
        z-index: 0;
        width: clamp(240px, 21vw, 350px);
        aspect-ratio: 1;
        left: clamp(24px, 4vw, 68px);
        bottom: 24px;
        border-radius: 50%;
        background: rgba(72, 139, 230, 0.08);
        pointer-events: none;
    }

    .login-page .login-card {
        position: relative;
        z-index: 1;
    }

    @media (max-width: 760px) {
        .login-page::before {
            width: 220px;
            left: 18px;
            bottom: 18px;
            opacity: .75;
        }
    }
</style>

<div class="login-page">

    <div class="login-card">

        <div class="login-emblem" aria-hidden="true">
            <x-icon name="shield-lock" />
        </div>

        <div class="login-heading">
            <h1>Welcome back!</h1>

            <p>
                Sign in to continue to
                <strong>SPMU-ACPMP</strong>
            </p>
        </div>


        <form method="POST" action="{{ route('login') }}" class="login-form" data-preserve-submit-label="true">
            @csrf

            {{-- EMAIL --}}
            <div class="login-field">

                <label for="email">
                    CSPC Email Address
                </label>

                <div class="login-input-wrap @error('email') has-error @enderror">

                    <span class="login-input-icon" aria-hidden="true">
                        <x-icon name="email" />
                    </span>

                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                    >

                </div>

                @error('email')
                    <div class="login-error">
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- PASSWORD --}}
            <div class="login-field">

                <div class="login-label-row">
                    <label for="password">
                        Password
                    </label>

                    @if(Route::has('password.request'))
                        <a
                            href="{{ route('password.request') }}"
                            class="login-forgot"
                        >
                            Forgot password?
                        </a>
                    @endif
                </div>


                <div class="login-input-wrap @error('password') has-error @enderror">

                    <span class="login-input-icon" aria-hidden="true">
                        <x-icon name="lock" />
                    </span>

                    <input
                        id="password"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                    <button
                        type="button"
                        class="login-password-toggle"
                        id="loginPasswordToggle"
                        aria-label="Show password"
                        title="Show password"
                    >
                        <x-icon name="eye" />
                    </button>

                </div>

                @error('password')
                    <div class="login-error">
                        {{ $message }}
                    </div>
                @enderror

            </div>


            {{-- REMEMBER ME --}}
            <label class="login-remember">
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    {{ old('remember') ? 'checked' : '' }}
                >

                <span>
                    Keep me signed in
                </span>
            </label>


            {{-- SIGN IN --}}
            <button
                type="submit"
                class="login-submit"
            >

                <span>
                    Sign in
                </span>

                <span class="login-submit-arrow" aria-hidden="true">
                    →
                </span>

            </button>


            {{--
                GOOGLE SIGN-IN
                Rendered only when the server is configured, so a local or
                offline demo never shows a button that cannot work.
            --}}
            @if(filled(config('services.google.client_id')) && filled(config('services.google.client_secret')) && filled(config('services.google.redirect')))
                <div class="login-alt-divider">
                    <span>or</span>
                </div>

                <a
                    class="login-google"
                    href="{{ route('auth.google.redirect') }}"
                >
                    <span class="login-google-icon" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62Z"/>
                            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.02-3.7H.96v2.33A9 9 0 0 0 9 18Z"/>
                            <path fill="#FBBC05" d="M3.98 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.02-2.33Z"/>
                            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.46 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.02 2.33C4.68 5.16 6.66 3.58 9 3.58Z"/>
                        </svg>
                    </span>

                    <span>
                        Sign in with Google
                    </span>
                </a>
            @endif


            {{-- HELP --}}
            <div class="login-help">

                <div class="login-help-divider">
                    <span>
                        Need help?
                    </span>
                </div>

                <p>
                    For employees, faculty, and staff.
                </p>

                <p>
                    Contact ICTU for account access assistance.
                </p>

            </div>

        </form>

    </div>

</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const password = document.getElementById('password');
    const toggle = document.getElementById('loginPasswordToggle');

    if (!password || !toggle) {
        return;
    }

    toggle.addEventListener('click', function () {
        const showing = password.type === 'text';

        password.type = showing ? 'password' : 'text';

        const label = showing ? 'Show password' : 'Hide password';

        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
    });
});
</script>

@endsection