<?php

namespace App\Support;

use App\Models\User;
use App\Support\AppApi\AppSignInGeneration;
use App\Support\AppApi\AppTokens;
use App\Support\Live\RealtimeSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * End every session and token of a user: browser sessions (database driver), the remember-me
 * token, OAuth / CLI / MCP access + refresh tokens, and every signed-in desktop / mobile / TV app
 * (AppTokens::revokeAllFor: tokens, live and collab sockets, the webviews' sessions — BRAI-377), and every
 * app sign-in approved but not yet turned into tokens (AppSignInGeneration — Codex review), and every realtime socket of
 * the user's browsers (RealtimeSession: the bumped generation closes them with 4401 "Signed out" — Codex realtime review 1).
 * Used on suspension, deletion, password change and reset, and when a social login takes over an account whose email was never verified.
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

        // pending app approvals (consent-page codes, approved TV codes) die; before revokeAllFor, so a sign-in that
        // won the user row lock first is revoked with the other devices right after
        AppSignInGeneration::bump($user);
        RealtimeSession::endAllFor($user);

        foreach ($user->tokens()->where('revoked', false)->get() as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        AppTokens::revokeAllFor($user, $appReason);
    }
}
