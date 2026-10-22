<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-extension tokens (`capture` scope) may only use /api/ext/v1. Keep them off the
 * MCP server and the CLI API, which can read everything the user can.
 */
class RejectCaptureTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->can('capture') && $token->cant('mcp:use')) {
            return response()->json(['message' => 'This token can only be used by the browser extension.'], 403);
        }

        return $next($request);
    }
}
