<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Auth\Concerns\EstablishesAuthenticatedSession;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Google sign-in.
 *
 * Google proves who the person is. SPMU-ACPMP decides whether that person may
 * enter: the returned address must already belong to an active local user, and
 * that local record supplies the classification and workspace. Nothing here
 * creates users, assigns roles, or writes profile data back from Google.
 */
class GoogleAuthController extends Controller
{
    use EstablishesAuthenticatedSession;

    /**
     * Send the visitor to Google's consent screen.
     */
    public function redirect(): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in is not configured on this server. Use your CSPC email and password.',
            ]);
        }

        try {
            $driver = Socialite::driver('google')->scopes(['openid', 'email', 'profile']);

            /*
             * The hosted-domain parameter only pre-filters Google's own account
             * chooser. It is a convenience, never the security check - the
             * returned address is still validated server-side below.
             */
            $hint = $this->allowedDomains()[0] ?? null;

            if ($hint !== null) {
                $driver->with(['hd' => $hint]);
            }

            return $driver->redirect();
        } catch (Throwable $exception) {
            return $this->fail($exception, 'Google sign-in is unavailable right now. Use your CSPC email and password.');
        }
    }

    /**
     * Handle Google's callback and sign in the matching local account.
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! $this->isConfigured()) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in is not configured on this server. Use your CSPC email and password.',
            ]);
        }

        /* The visitor dismissed Google's consent screen. */
        if ($request->filled('error')) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google sign-in was cancelled. You can try again or use your CSPC email and password.',
            ]);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            return $this->fail($exception, 'Google sign-in could not be completed. Please try again.');
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));

        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google did not return an email address for this account. Use your CSPC email and password.',
            ]);
        }

        if (! $this->domainAllowed($email)) {
            return redirect()->route('login')->withErrors([
                'email' => 'This Google account is not from an allowed domain. Contact ICTU for account access assistance.',
            ]);
        }

        /*
         * Strictly a lookup. A verified Google identity is not an account:
         * students hold valid institutional Google accounts without being
         * authorized SPMU-ACPMP users, so no record is ever created here.
         */
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'email' => 'This Google account is not registered in SPMU-ACPMP. Contact ICTU for account access assistance.',
            ]);
        }

        /* The same account-status rule the credential login applies. */
        if ($user->account_status !== AccountStatus::Active) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account is inactive. Contact ICTU if you believe this is an error.',
            ]);
        }

        Auth::login($user);

        $error = $this->establishAuthenticatedSession($request, $user);

        if ($error !== null) {
            return redirect()->route('login')->withErrors(['email' => $error]);
        }

        return redirect()->intended(route('dashboard'));
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    /**
     * Optional allow-list, comma separated. Blank means every Google account
     * may reach the local user lookup, which still decides access.
     *
     * @return list<string>
     */
    private function allowedDomains(): array
    {
        return collect(explode(',', (string) config('services.google.allowed_domains')))
            ->map(fn (string $domain) => strtolower(trim(ltrim($domain, '@'))))
            ->filter()
            ->values()
            ->all();
    }

    private function domainAllowed(string $email): bool
    {
        $domains = $this->allowedDomains();

        if ($domains === []) {
            return true;
        }

        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));

        return $domain !== '' && in_array($domain, $domains, true);
    }

    /**
     * Log the real cause for ICTU and show the visitor a plain message. OAuth
     * secrets, tokens and stack traces never reach the response.
     */
    private function fail(Throwable $exception, string $message): RedirectResponse
    {
        Log::warning('Google sign-in failed.', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
