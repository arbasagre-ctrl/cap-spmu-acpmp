<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\AccessClassification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Post-authentication setup shared by every sign-in method.
 *
 * Both the local credential login and Google OAuth end up here, so the
 * portal checks, the active workspace and last_login_at are decided in one
 * place. Identity may be proven several ways; authorization is resolved only
 * from the local user record.
 */
trait EstablishesAuthenticatedSession
{
    /**
     * Finish signing a user in.
     *
     * The caller has already authenticated the user. This regenerates the
     * session, applies the portal checks, and stores the active workspace.
     *
     * @return string|null An error message when the account may not enter the
     *                     system; null when the session is established. The
     *                     user is logged out again on failure.
     */
    protected function establishAuthenticatedSession(Request $request, User $user): ?string
    {
        $request->session()->regenerate();

        /*
         * Read the raw column: a retired or otherwise unknown classification
         * would fail the model cast before it could be rejected here.
         */
        $classification = AccessClassification::tryFrom(
            strtoupper((string) $user->getRawOriginal('access_classification'))
        );

        if (! $classification?->isPortalEnabled()) {
            $this->abandonSession($request);

            return 'This account uses a retired or invalid system role. Contact ICTU.';
        }

        $workspace = $user->primaryWorkspace();

        if (! $workspace) {
            $this->abandonSession($request);

            return 'This account has no valid portal assignment. Contact ICTU.';
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $request->session()->put('active_workspace', $workspace);

        return null;
    }

    private function abandonSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
