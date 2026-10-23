<?php

namespace App\Http\Middleware;

use App\Models\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the OAuth flows the MCP package and Passport expose:
 *  - POST /oauth/register (anonymous Dynamic Client Registration): throttled, and https redirect
 *    URIs must be on a known client host (config mcp.allowed_redirect_hosts — wildcards for the
 *    per-install browser-extension hosts) or loopback. Otherwise anyone could register "Claude"
 *    with their own redirect and phish a token through the consent screen.
 *  - /oauth/authorize: accounts that still have to verify their email can't connect apps.
 */
class GuardOAuthFlows
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('oauth/register')) {
            $key = 'oauth-register:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 20)) {
                return response()->json(['error' => 'too_many_requests', 'error_description' => 'Too many client registrations. Try again later.'], 429);
            }
            RateLimiter::hit($key, 3600);

            foreach ((array) $request->input('redirect_uris', []) as $uri) {
                if (is_string($uri) && ! self::allowedRedirect($uri)) {
                    return response()->json([
                        'error' => 'invalid_redirect_uri',
                        'error_description' => 'This redirect host is not allowed on this instance (see MCP_REDIRECT_HOSTS).',
                    ], 400);
                }
            }
        }

        if ($request->is('oauth/authorize') && ($user = $request->user())
            && InstanceSettings::requiresEmailVerification() && $user->email_verified_at === null) {
            return $request->isMethod('GET')
                ? redirect()->route('verification.notice')
                : abort(403, 'Verify your email address before connecting apps.');
        }

        return $next($request);
    }

    /** http(s) URIs must be loopback or an allowed host; custom schemes are checked by the package. */
    public static function allowedRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }
        if ($scheme !== 'https') {
            return false;
        }
        foreach ((array) config('mcp.allowed_redirect_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '*' || $host === $allowed || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }
}
