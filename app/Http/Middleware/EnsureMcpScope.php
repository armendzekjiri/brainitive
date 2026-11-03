<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * MCP needs a token made for it (Codex review 13, step 1, v1.49.0): the `mcp:use` scope (MCP connectors, new CLI /
 * Copilot tokens) or `*`. Scope-less tokens are refused — except the CLI's own personal access tokens issued before
 * v1.49.0 (`brainitive-cli`, no scopes), logged once per token and day so we can see when they're gone. That
 * allowance ends after their lifetime (see docs/ai/KNOWLEDGE.md → "MCP needs mcp:use").
 */
class EnsureMcpScope
{
    public const LEGACY_CLI_TOKEN = 'brainitive-cli';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token === null || $token->can('mcp:use')) {
            return $next($request);
        }

        if (self::isLegacyCliToken($token)) {
            $id = (string) $token->oauth_access_token_id;
            if (Cache::add('mcp-legacy-cli-token:'.$id.':'.now()->toDateString(), true, now()->addDay())) {
                Log::info('MCP used with a legacy scope-less CLI token', ['token_id' => $id, 'user_id' => $request->user()?->getAuthIdentifier()]);
            }

            return $next($request);
        }

        return response()->json(['message' => 'This token can’t use MCP. Sign in again (brainitive login) or reconnect the connector.'], 403);
    }

    /** A scope-less personal access token of the CLI (issued by /api/cli/login or `mcp:token` before v1.49.0). */
    private static function isLegacyCliToken(object $token): bool
    {
        $scopes = $token->oauth_scopes ?? $token->scopes ?? [];
        if (! empty($scopes) || ! isset($token->oauth_access_token_id)) {
            return false;
        }
        $client = $token->client;

        return $token->name === self::LEGACY_CLI_TOKEN && $client instanceof Client && $client->hasGrantType('personal_access');
    }
}
