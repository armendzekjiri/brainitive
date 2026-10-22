<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the instance admin console (`/admin/*`).
 */
class EnsureInstanceAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isInstanceAdmin(), 403);

        return $next($request);
    }
}
