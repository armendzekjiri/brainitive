<?php

namespace App\Support\Live;

use App\Support\Collab\CollabConfig;
use Illuminate\Http\Request;

/**
 * Live channel settings (BRAI-151). On whenever live co-editing is configured (COLLAB_URL + secret)
 * and LIVE_ENABLED isn't false. Keys are derived from the same shared secret with their own labels
 * (realtime/src/live/token.js does the same), so neither ever equals a co-editing key.
 */
final class LiveConfig
{
    public static function configured(): bool
    {
        return CollabConfig::configured() && (bool) config('live.enabled', true);
    }

    /** Live channels are offered to this request's browser. */
    public static function enabled(?Request $request = null): bool
    {
        if (! self::configured()) {
            return false;
        }
        // e2e: only the specs that start the live server opt in; the other journeys stay as they are
        if (config('collab.e2e_opt_in') && app()->environment('e2e')) {
            return ($request ?? request())->header('X-E2E-Live') === '1';
        }

        return true;
    }

    /** The browser's WebSocket URL (full ws(s) URL or a path on this host). */
    public static function url(): string
    {
        $url = (string) config('live.url');
        if ($url !== '') {
            return $url;
        }
        $collab = CollabConfig::url();

        return $collab.(str_contains($collab, '?') ? '&' : '?').'live=1';
    }

    /**
     * The WebSocket URL for native clients (App API): always absolute. A path (`LIVE_URL=/collab?live=1`)
     * is resolved against APP_URL (https → wss, http → ws).
     */
    public static function absoluteUrl(): string
    {
        $url = self::url();
        if (preg_match('#\Awss?://#i', $url) === 1) {
            return $url;
        }
        $app = rtrim((string) config('app.url'), '/');
        $base = preg_replace('#\Ahttp(s?)://#i', 'ws$1://', $app) ?? $app;

        return $base.'/'.ltrim($url, '/');
    }

    /** Where to POST events (the realtime server's own address), null = publishing is off. */
    public static function publishUrl(): ?string
    {
        if (! self::configured()) {
            return null;
        }
        $url = (string) config('live.publish_url');
        if ($url !== '') {
            return rtrim($url, '/');
        }
        // local dev: ws://127.0.0.1:1234 → http://127.0.0.1:1234 (the same process)
        $parts = parse_url(self::url());
        if (($parts['scheme'] ?? '') === 'ws' && in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true) && isset($parts['port'])) {
            return "http://{$parts['host']}:{$parts['port']}";
        }

        return null;
    }

    /** Raw HMAC key for browser channel tokens. */
    public static function tokenKey(): string
    {
        return hash_hmac('sha256', 'live-token', (string) config('collab.secret'), true);
    }

    /** Bearer key of the realtime server's publish endpoint (hex). */
    public static function publishKey(): string
    {
        return hash_hmac('sha256', 'live-publish', (string) config('collab.secret'));
    }

    public static function tokenTtl(): int
    {
        return max(30, (int) config('live.token_ttl', 120));
    }
}
