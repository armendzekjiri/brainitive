<?php

namespace App\Support\Collab;

use Illuminate\Http\Request;

/**
 * Live co-editing settings (BRAI-54). Off unless COLLAB_URL and COLLAB_SECRET are both set. The two
 * signing keys are derived from the shared secret with different labels (the realtime server does the
 * same), so a browser token can never be used as the internal API key and the other way round.
 */
final class CollabConfig
{
    /** URL + secret are set: the internal API answers the realtime server. */
    public static function configured(): bool
    {
        return filled(config('collab.url')) && filled(config('collab.secret'));
    }

    /** Live editing is offered to this request's browser. */
    public static function enabled(?Request $request = null): bool
    {
        if (! self::configured()) {
            return false;
        }
        // e2e: only live-docs.spec.ts opts in; every other journey keeps the single-user editor
        if (config('collab.e2e_opt_in') && app()->environment('e2e')) {
            return ($request ?? request())->header('X-E2E-Collab') === '1';
        }

        return true;
    }

    public static function url(): string
    {
        return (string) config('collab.url');
    }

    /** Raw HMAC key for browser connection tokens. */
    public static function tokenKey(): string
    {
        return hash_hmac('sha256', 'collab-token', (string) config('collab.secret'), true);
    }

    /** Bearer key of the internal API (hex), used by the realtime server only. */
    public static function internalKey(): string
    {
        return hash_hmac('sha256', 'collab-internal', (string) config('collab.secret'));
    }

    public static function tokenTtl(): int
    {
        return max(30, (int) config('collab.token_ttl', 120));
    }

    public static function maxStateBytes(): int
    {
        return max(1024, (int) config('collab.max_state_bytes', 16 * 1024 * 1024));
    }
}
