<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * In front of `/mcp`, before authentication (Codex recheck, item AA): the per-user throttle (`throttle:120,1,mcp`)
 * only sees signed-in callers, so unauthenticated attempts weren't limited at all. Here every 401 counts against
 * the caller's IP — 60 per minute, then 429 — while authenticated requests never count, so real clients behind
 * one address aren't slowed down. Bodies over MAX_BYTES are refused (413) before anything parses them.
 */
class GuardMcpEndpoint
{
    public const UNAUTHENTICATED_PER_MINUTE = 60;

    /** Room for the largest tool input (a 10 MB page body, ContentLimits::PAGE_BODY) plus JSON-RPC framing. */
    public const MAX_BYTES = 12 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $length = (int) $request->headers->get('Content-Length', '0');
        if ($length > self::MAX_BYTES || strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'The request is too large.'], 413);
        }

        $key = 'mcp-unauthenticated:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::UNAUTHENTICATED_PER_MINUTE)) {
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $response = $next($request);
        if ($response->getStatusCode() === 401) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }
}
