<?php

namespace App\Support\Live;

use App\Models\User;
use App\Support\AppApi\AppSignInGeneration;
use App\Support\Collab\CollabConfig;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 * - **An allowlist, not only a denylist** (Codex follow-up review): a session can end without a Logout event — it
 *   expires (`SESSION_LIFETIME` without a saved request), or remember-me rebuilds the login under a new session id.
 *   So every issued `sid` is remembered as active (cache, the session lifetime, refreshed with each token) with its
 *   session id, and endedAmong() answers ended for a `sid` that is unknown, signed out, or — with the database session
 *   driver — whose session row is gone, signed out or expired. Fails closed: a socket whose session no Laravel request
 *   can use any more is closed (4401) and reconnects only with a new token.
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

    private const ACTIVE_PREFIX = 'realtime-session-active:';

    /**
     * The claims of a web token for `$user`: `sid` (this request's session, when there is one) and `gen`.
     *
     * @return array{sid?: string, gen: int}
     */
    public static function claims(User $user): array
    {
        $claims = ['gen' => (int) ($user->app_auth_generation ?? 0)];
        $session = self::session();
        $id = self::id($session);
        if ($id === null) {
            return $claims;
        }
        try {
            Cache::put(self::ACTIVE_PREFIX.$id, ['session' => (string) $session->getId(), 'user' => (int) $user->id], self::lifetime());
        } catch (Throwable $e) {
            // no cache: the realtime server refuses the token (unknown session) — closed, never open
            report($e);
        }

        return ['sid' => $id] + $claims;
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
            Cache::forget(self::ACTIVE_PREFIX.$id);
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
     * Of the asked session ids, those no browser can use any more: signed out, never issued (or forgotten), or — with
     * the database session driver — whose session is gone, signed out or expired.
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
        $ended = Cache::many(array_map(fn (string $id) => self::ENDED_PREFIX.$id, $ids));
        $active = Cache::many(array_map(fn (string $id) => self::ACTIVE_PREFIX.$id, $ids));
        $sessions = [];
        foreach ($ids as $id) {
            $entry = $active[self::ACTIVE_PREFIX.$id] ?? null;
            if (($ended[self::ENDED_PREFIX.$id] ?? null) !== true && is_array($entry) && is_string($entry['session'] ?? null)) {
                $sessions[$id] = $entry;
            }
        }
        $alive = self::aliveSessions($sessions);

        return array_values(array_filter($ids, fn (string $id) => ! isset($alive[$id])));
    }

    /**
     * Which of the candidates' Laravel sessions still exist, signed in as their user and not expired (database driver;
     * other drivers can't be listed — there the active marker's lifetime is the check).
     *
     * @param  array<string, array{session: string, user?: int}>  $candidates  sid → its session
     * @return array<string, true>
     */
    private static function aliveSessions(array $candidates): array
    {
        if ($candidates === [] || config('session.driver') !== 'database') {
            return array_map(fn () => true, $candidates);
        }
        $rows = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->whereIn('id', array_values(array_map(fn (array $entry) => $entry['session'], $candidates)))
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
            ->pluck('user_id', 'id');
        $alive = [];
        foreach ($candidates as $sid => $entry) {
            $owner = $rows[$entry['session']] ?? null;
            if ($owner !== null && (int) $owner === (int) ($entry['user'] ?? 0)) {
                $alive[$sid] = true;
            }
        }

        return $alive;
    }

    /** Seconds an issued `sid` stays known without a new token: the session lifetime (≥ 10 minutes). */
    private static function lifetime(): int
    {
        return max(600, (int) config('session.lifetime', 120) * 60);
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
