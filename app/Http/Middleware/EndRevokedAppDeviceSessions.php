<?php

namespace App\Http\Middleware;

use App\Support\AppApi\AppWebSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A web session an app's webview opened (/app/session/{code}) ends with its app device (BRAI-377): once the
 * device is revoked or deleted (sign-out, "Sign out" in Profile, refresh reuse, password change …) the session
 * is logged out on its next request, and the remember-me token is cycled so a remember-me cookie the webview got
 * before the Codex review can't bring it back. Other sessions of the user stay. Only sessions with
 * `app_device_id` are checked, at most one cached query a minute per device (AppWebSessions).
 *
 * Codex review, finding 1: a webview session that was rebuilt from a remember-me cookie (the session expired or
 * was lost, so it has no `app_device_id`) is signed out of this browser only — without cycling the token, so the
 * user's normal browsers stay remembered. The app then opens a fresh session with its token (it lands on /login),
 * which a revoked device can't.
 */
class EndRevokedAppDeviceSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        if (! $request->session()->has(AppWebSessions::SESSION_KEY)) {
            return $this->rememberedWebview($request) ? $this->signedOut($request, cycleRememberToken: false) : $next($request);
        }

        $user = Auth::guard('web')->user();
        if (! $user) {
            $request->session()->forget(AppWebSessions::SESSION_KEY);

            return $next($request);
        }

        if (AppWebSessions::deviceActiveFor((int) $request->session()->get(AppWebSessions::SESSION_KEY), (int) $user->getAuthIdentifier())) {
            return $next($request);
        }

        return $this->signedOut($request, cycleRememberToken: true);
    }

    /**
     * A native app's webview whose user was just signed in from the remember-me cookie (no device binding). Not on
     * /app/session itself: that link replaces the session with a device-bound one and expires the cookie.
     */
    private function rememberedWebview(Request $request): bool
    {
        if (! AppWebSessions::fromAppWebview($request) || $request->routeIs('app.session', 'app.session.confirm')) {
            return false;
        }

        $guard = Auth::guard('web');

        return $guard->check() && $guard->viaRemember();
    }

    private function signedOut(Request $request, bool $cycleRememberToken): Response
    {
        $guard = Auth::guard('web');
        if ($cycleRememberToken) {
            // logout() cycles the remember token: a webview's remember-me cookie (older sign-ins) stops working too
            $guard->logout();
        } else {
            // this browser only: drops the session login and expires the recaller cookie, the token stays
            $guard->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'This app was signed out. Sign in again.';

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => $message], 401)
            : redirect()->route('login')->with('status', $message);
    }
}
