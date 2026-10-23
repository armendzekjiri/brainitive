<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * End every session and token of a user: browser sessions (database driver), the remember-me
 * token, and OAuth / CLI / MCP access + refresh tokens. Used on suspension, on password change
 * and reset, and when a social login takes over an account whose email was never verified.
 */
class SignOutEverywhere
{
    public static function run(User $user, ?string $keepSessionId = null): void
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
    }
}
