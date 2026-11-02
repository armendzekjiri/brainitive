<?php

namespace App\Support;

use App\Models\User;
use App\Support\AppApi\AppTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * End every session and token of a user: browser sessions (database driver), the remember-me
 * token, OAuth / CLI / MCP access + refresh tokens, and every signed-in desktop / mobile / TV app
 * (AppTokens::revokeAllFor: tokens, live and collab sockets, the webviews' sessions — BRAI-377).
 * Used on suspension, deletion, password change and reset, refresh-token reuse, and when a social
 * login takes over an account whose email was never verified.
 */
class SignOutEverywhere
{
    /**
     * @param  string|null  $keepSessionId  the browser session that stays (the one changing the password)
     * @param  string  $appReason  `app_devices.revoked_reason` of the signed-out apps
     */
    public static function run(User $user, ?string $keepSessionId = null, string $appReason = 'signed_out_everywhere'): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
                ->delete();
        }

        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        foreach ($user->tokens()->where('revoked', false)->get() as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        AppTokens::revokeAllFor($user, $appReason);
    }
}
