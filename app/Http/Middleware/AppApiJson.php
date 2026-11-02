<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App API v1: every request is treated as JSON, so a missing Accept header from a native client
 * never turns an auth failure into a redirect to the login page.
 */
class AppApiJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
