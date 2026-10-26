<?php

namespace App\Http\Middleware;

use App\Support\Collab\CollabConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The internal API of the realtime server (BRAI-54): `Authorization: Bearer <internal key>`, the key
 * derived from COLLAB_SECRET (never a browser token). 404 while live editing isn't configured.
 */
class VerifyCollabSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CollabConfig::configured()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! hash_equals(CollabConfig::internalKey(), (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
