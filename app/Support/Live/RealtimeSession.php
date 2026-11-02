<?php

namespace App\Support\Live;

use App\Models\User;
use App\Support\AppApi\AppSignInGeneration;
use App\Support\Collab\CollabConfig;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Binds the web's realtime tokens (live channels and live co-editing) to the browser session that asked for them —
 * Codex realtime review, finding 1. App API tokens are bound to their device instead (`dev`, 4410).
 *
 * - **`sid`**: an HMAC of the session id (never the id itself), so it needs no session write — the token endpoints
 *   run with ReadOnlySession. Stable from sign-in (`regenerate()`) to sign-out. Signing out (every `Logout` /
 *   `CurrentDeviceLogout`: the logout button, a suspended user, an app webview whose device was revoked …) ends it:
 *   remembered here for ENDED_TTL and pushed to the realtime server (`Live::sessionEnded` →
 *   `POST /internal/live/sessions/ended`), which closes that session's sockets with **4401 "Signed out"** and refuses
 *   its remaining tokens. Other sessions of the user (other browsers) stay connected.
 * - **`gen`**: the user's `app_auth_generation` when the token was issued. `SignOutEverywhere` bumps it and pushes the
 *   new value: every web socket of the user with an older `gen` closes (4401 "Signed out") — the session a password
 *   change keeps reconnects at once with a fresh token.
 * - Missed pushes: the realtime server asks on connect and in its minute re-checks (`sessions` in
 *   `internal.collab.access` / `internal.collab.live.access`); the answers carry `ended_sessions` and the users'
 *   current `generations`.
 */
final class RealtimeSession
{
    /**
     * How long an ended session is remembered: far longer than a token lives (2 min) and than the realtime server
     * keeps a socket whose re-checks get no answer (it fails closed after a few minutes).
     */
    public const ENDED_TTL = 6 * 3600;

    /** `sid` shape (the realtime server checks it too) */
    public const ID = '/\A[a-f0-9]{32}\z/';

    private const ENDED_PREFIX = 'realtime-session-ended:';

    /**
     * The claims of a web token for `$user`: `sid` (this request's session, when there is one) and `gen`.
     *
     * @return array{sid?: string, gen: int}
     */
    public static function claims(User $user): array
    {
        $claims = ['gen' => (int) ($user->app_auth_generation ?? 0)];
        $id = self::id(self::session());

        return $id === null ? $claims : ['sid' => $id] + $claims;
    }

    /** The realtime id of a session: HMAC of its id, 32 hex characters. */
    public static function id(?Session $session): ?string
    {
        $sessionId = $session?->getId();
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return substr(hash_hmac('sha256', 'realtime-session|'.$sessionId, LiveConfig::tokenKey()), 0, 32);
    }

    /** The session signs out (Logout / CurrentDeviceLogout, before it is invalidated): its sockets close. */
    public static function end(?Session $session = null): void
    {
        $id = self::id($session ?? self::session());
        if ($id === null || ! CollabConfig::configured()) {
            return;
        }
        try {
            Cache::put(self::ENDED_PREFIX.$id, true, self::ENDED_TTL);
        } catch (Throwable $e) {
            // no cache: the push below still closes the sockets at once
            report($e);
        }
        Live::sessionEnded($id);
    }

    /** SignOutEverywhere (after AppSignInGeneration::bump): every web socket of the user closes. */
    public static function endAllFor(User $user): void
    {
        Live::userSignedOut((int) $user->id, AppSignInGeneration::current($user));
    }

    /**
     * Of the asked session ids, those that were signed out.
     *
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    public static function endedAmong(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && preg_match(self::ID, $id) === 1)));
        if ($ids === []) {
            return [];
        }
        $found = Cache::many(array_map(fn (string $id) => self::ENDED_PREFIX.$id, $ids));

        return array_values(array_filter($ids, fn (string $id) => ($found[self::ENDED_PREFIX.$id] ?? null) === true));
    }

    /**
     * The users' current generations (their web tokens with an older `gen` are signed out).
     *
     * @param  iterable<User>  $users
     * @return array<string, int>
     */
    public static function generations(iterable $users): array
    {
        $generations = [];
        foreach ($users as $user) {
            $generations[(string) $user->id] = (int) ($user->app_auth_generation ?? 0);
        }

        return $generations;
    }

    private static function session(): ?Session
    {
        $request = request();

        return $request->hasSession() ? $request->session() : null;
    }
}
