<?php

namespace App\Support\Profile;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Token;

/**
 * Apps holding an active Passport token for an account — MCP connectors, the CLI, the browser
 * extension — one row per app (Profile → Connected apps, App API `GET /me/connected-apps`,
 * BRAI-394), and disconnecting one. Desktop / mobile installs are `app_devices`, not Passport
 * (AppTokens); the App API lists both side by side.
 */
final class ConnectedApps
{
    /**
     * @return list<array{client_id: string, name: string, kind: string, scopes: list<string>, last_issued: ?string, expires_at: ?string, tokens: int}>
     */
    public static function for(User $user): array
    {
        return $user->tokens()
            ->with('client:id,name')
            ->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get()
            ->groupBy('client_id')
            ->map(fn ($tokens) => [
                'client_id' => (string) $tokens->first()->client_id,
                'name' => $tokens->first()->client?->name ?? 'Unknown app',
                'kind' => self::kind($tokens->all()),
                'scopes' => $tokens->pluck('scopes')->flatten()->unique()->values()->all(),
                'last_issued' => $tokens->first()->created_at?->toIso8601String(),
                'expires_at' => $tokens->max('expires_at')?->toIso8601String(),
                'tokens' => $tokens->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Disconnect an app: revoke all of this account's tokens (and refresh tokens) for it. Returns how
     * many were active — 0 when the client isn't connected to this account.
     */
    public static function disconnect(User $user, string $clientId): int
    {
        // client ids are uuids: Postgres refuses to compare anything else (a 500, not "not connected")
        if (! Str::isUuid($clientId)) {
            return 0;
        }

        $tokens = $user->tokens()->where('client_id', $clientId)->where('revoked', false)->get();
        foreach ($tokens as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        return $tokens->count();
    }

    /**
     * What kind of app holds the tokens: `cli` (personal access token of `brainitive login`),
     * `extension` (the `capture` scope), `mcp` (an MCP connector, `mcp:use`), else `oauth`.
     *
     * @param  list<Token>  $tokens
     */
    private static function kind(array $tokens): string
    {
        $scopes = collect($tokens)->pluck('scopes')->flatten()->all();

        return match (true) {
            collect($tokens)->contains(fn (Token $token) => $token->name === 'brainitive-cli') => 'cli',
            in_array('capture', $scopes, true) => 'extension',
            in_array('mcp:use', $scopes, true) => 'mcp',
            default => 'oauth',
        };
    }
}
