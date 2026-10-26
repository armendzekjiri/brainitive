<?php

namespace App\Support\Calendar;

use App\Support\OutboundUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Requests to calendar servers users choose (CalDAV / iCloud, ICS links) — BRAI-141. Builds on
 * App\Support\OutboundUrl (http(s) only, no user:pass@, every resolved address public, the
 * connection pinned to the checked address) and adds what calendars need:
 *  - webcal(s):// links are rewritten to https://;
 *  - only ports 80 / 443 and config('calendar.allowed_ports');
 *  - redirects followed by hand, at most 3, every Location checked again; credentials are sent
 *    only to the origin (scheme + host + port) they were given for;
 *  - connect / total timeouts and a response size cap;
 *  - transport failures become a generic CalendarSyncFailed (their messages carry URLs, which for
 *    ICS links hold secret tokens: never shown, never logged).
 * Private / loopback targets are allowed only with APP_ENV=e2e + CALENDAR_LINKS_ALLOW_PRIVATE (the
 * Playwright fixture server on 127.0.0.1); every other environment ignores the flag.
 */
final class SafeRemoteUrl
{
    public const MAX_REDIRECTS = 3;

    private const USER_AGENT = 'Brainitive-Calendar/1.0 (+https://brainitive.app)';

    public static function allowPrivate(): bool
    {
        return app()->environment('e2e') && (bool) config('calendar.allow_private_links');
    }

    /** webcal:// → https://, surrounding blanks and the #fragment removed. Null for other schemes. */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('~^webcals?://~i', $url)) {
            $url = 'https://'.substr($url, strpos($url, '://') + 3);
        }
        $url = explode('#', $url, 2)[0];
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /**
     * Guzzle options for a request to $url, or null when it isn't allowed (scheme, credentials
     * in the URL, port, private / reserved address, unresolvable host).
     *
     * @return array<string, mixed>|null
     */
    public static function options(string $url): ?array
    {
        $url = self::normalize($url);
        if ($url === null || ($parts = parse_url($url)) === false) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ! self::allowPrivate() && ! in_array((int) $port, self::allowedPorts(), true)) {
            return null;
        }

        return OutboundUrl::options($url, self::allowPrivate());
    }

    public static function allowed(string $url): bool
    {
        return self::options($url) !== null;
    }

    /** @return list<int> */
    public static function allowedPorts(): array
    {
        return array_values(array_unique(array_merge([80, 443], array_map('intval', (array) config('calendar.allowed_ports', [])))));
    }

    /** The host of a URL, for display (never the path or query: ICS links carry their secret there). */
    public static function host(string $url): string
    {
        return strtolower((string) parse_url((string) self::normalize($url), PHP_URL_HOST));
    }

    /** scheme://host[:port] of a URL (default ports dropped). */
    public static function origin(string $url): string
    {
        $p = parse_url($url) ?: [];
        $scheme = strtolower($p['scheme'] ?? '');
        $port = $p['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower($p['host'] ?? '').($port !== null && (int) $port !== $default ? ':'.$port : '');
    }

    public static function sameOrigin(string $a, string $b): bool
    {
        return self::origin($a) === self::origin($b);
    }

    /** Resolve a (possibly relative) reference a server returned against $base (RFC 3986 §5.2, simplified). */
    public static function resolve(string $base, string $ref): string
    {
        $ref = trim(explode('#', $ref, 2)[0]);
        if ($ref === '') {
            return $base;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref)) {
            return $ref;
        }
        $p = parse_url($base) ?: [];
        $scheme = $p['scheme'] ?? 'https';
        if (str_starts_with($ref, '//')) {
            return $scheme.':'.$ref;
        }
        $origin = $scheme.'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($ref, '/')) {
            return $origin.self::removeDotSegments($ref);
        }
        $dir = preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/') ?: '/';
        if (str_starts_with($ref, '?')) {
            return $origin.($p['path'] ?? '/').$ref;
        }

        return $origin.self::removeDotSegments($dir.$ref);
    }

    /**
     * Send a request to a user-supplied URL, following up to MAX_REDIRECTS redirects by hand.
     * $finalUrl receives the URL that answered.
     *
     * @param  array{headers?: array<string, string>, body?: string|null, content_type?: string, auth?: array{0: string, 1: string}|null, max_bytes?: int, timeout?: int}  $opts
     *
     * @throws RemoteUrlRefused when the URL or a redirect target isn't allowed
     * @throws CalendarSyncFailed on transport errors, too many redirects or a too big response
     */
    public static function send(string $method, string $url, array $opts = [], ?string &$finalUrl = null): Response
    {
        $url = self::normalize($url) ?? throw new RemoteUrlRefused;
        $authOrigin = self::origin($url);
        $maxBytes = (int) ($opts['max_bytes'] ?? config('calendar.max_response_bytes', 5_000_000));
        $body = $opts['body'] ?? null;

        for ($hop = 0; ; $hop++) {
            $options = self::options($url) ?? throw new RemoteUrlRefused;
            $response = self::once($method, $url, $options, $opts, $body, self::origin($url) === $authOrigin, $maxBytes);

            $location = $response->header('Location');
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true) || $location === '') {
                $finalUrl = $url;

                return $response;
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw new CalendarSyncFailed('The calendar server redirected too many times.');
            }
            if ($response->status() === 303) {
                $method = 'GET';
                $body = null;
            }
            $url = self::normalize(self::resolve($url, $location)) ?? throw new RemoteUrlRefused;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $opts
     */
    private static function once(string $method, string $url, array $options, array $opts, ?string $body, bool $withAuth, int $maxBytes): Response
    {
        $sink = new CappedStream($maxBytes);
        $tooBig = false;
        $request = Http::connectTimeout(5)
            ->timeout((int) ($opts['timeout'] ?? 20))
            ->withUserAgent(self::USER_AGENT)
            ->withHeaders($opts['headers'] ?? [])
            ->withOptions($options + [
                // a declared Content-Length over the cap: abort before the body
                'on_headers' => function ($headers) use ($maxBytes, &$tooBig) {
                    if ((int) $headers->getHeaderLine('Content-Length') > $maxBytes) {
                        $tooBig = true;
                        throw new \RuntimeException('Response too large');
                    }
                },
                // chunked / wrong Content-Length: the sink refuses the byte past the cap and curl stops
                'sink' => $sink,
            ]);

        if ($withAuth && ! empty($opts['auth'])) {
            $request = $request->withBasicAuth((string) $opts['auth'][0], (string) $opts['auth'][1]);
        }
        if ($body !== null) {
            $request = $request->withBody($body, $opts['content_type'] ?? 'application/xml; charset=utf-8');
        }

        try {
            $response = $request->send($method, $url);
        } catch (Throwable) {
            // never the exception's message: it contains the URL (an ICS link's secret token)
            throw new CalendarSyncFailed($sink->exceeded || $tooBig
                ? self::tooBigMessage($maxBytes)
                : 'Couldn’t reach the calendar server. Check the address, or try again later.');
        }

        if (strlen($response->body()) > $maxBytes) {
            throw new CalendarSyncFailed(self::tooBigMessage($maxBytes));
        }

        return $response;
    }

    private static function tooBigMessage(int $maxBytes): string
    {
        return 'This calendar is too big to load (more than '.max(1, intdiv($maxBytes, 1_000_000)).' MB).';
    }

    private static function removeDotSegments(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $out = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }
        $result = implode('/', $out);
        if (preg_match('~/\.\.?$~', $path)) {
            $result .= '/';
        }

        return ($result === '' ? '/' : $result).($query !== null ? '?'.$query : '');
    }
}
