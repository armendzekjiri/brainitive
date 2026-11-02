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
 * is logged out on its next request, and the remember-me token is cycled so the webview's cookie can't bring
 * it back. Other sessions of the user stay. Only sessions with `app_device_id` are checked, at most one cached
 * query a minute per device (AppWebSessions).
 */
class EndRevokedAppDeviceSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has(AppWebSessions::SESSION_KEY)) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();
        if (! $user) {
            $request->session()->forget(AppWebSessions::SESSION_KEY);

            return $next($request);
        }

        if (AppWebSessions::deviceActiveFor((int) $request->session()->get(AppWebSessions::SESSION_KEY), (int) $user->getAuthIdentifier())) {
            return $next($request);
        }

        // logout() cycles the remember token: the webview's remember-me cookie stops working too
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'This app was signed out. Sign in again.';

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => $message], 401)
            : redirect()->route('login')->with('status', $message);
    }
}
