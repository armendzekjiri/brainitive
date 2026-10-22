<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out users an instance admin has suspended. Their API / MCP tokens are revoked at
 * suspension time, so this only needs to cover web sessions.
 */
class EnsureUserIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            $message = 'Your account has been suspended. Contact your administrator.';

            // Token requests (MCP, CLI, browser extension) have no session to end.
            if (! $request->hasSession()) {
                return response()->json(['message' => $message], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => $message], 403)
                : redirect()->route('login')->with('status', $message);
        }

        return $next($request);
    }
}
