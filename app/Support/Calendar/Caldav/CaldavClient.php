<?php

namespace App\Support\Calendar\Caldav;

use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\RemoteUrlRefused;
use App\Support\Calendar\SafeRemoteUrl;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;

/**
 * The WebDAV / CalDAV requests (RFC 4791, RFC 6764) behind CaldavCalendarProvider (BRAI-141):
 * discovery (current-user-principal → calendar-home-set → calendars), calendar-query REPORTs
 * and object GET / PUT / DELETE. Every request goes through SafeRemoteUrl (SSRF guard, manual
 * redirects, size cap). Hrefs the server returns are untrusted: they are resolved against the
 * URL that answered, and the app password is sent only to hosts of the same site as the server
 * the user entered (iCloud answers on caldav.icloud.com and keeps calendars on pNN-caldav.icloud.com).
 */
final class CaldavClient
{
    private const NS = [
        'd' => 'DAV:',
        'c' => 'urn:ietf:params:xml:ns:caldav',
        'a' => 'http://apple.com/ns/ical/',
    ];

    private string $site;

    public function __construct(private string $serverUrl, private string $username, private string $password)
    {
        $this->site = self::site(SafeRemoteUrl::host($serverUrl));
    }

    /**
     * The principal's calendar home and display name, starting at the URL the user entered
     * (falls back to /.well-known/caldav on the same origin).
     *
     * @return array{home: string, name: string|null}
     */
    public function discover(): array
    {
        $candidates = [$this->serverUrl];
        $wellKnown = SafeRemoteUrl::origin($this->serverUrl).'/.well-known/caldav';
        if (rtrim($this->serverUrl, '/') !== $wellKnown) {
            $candidates[] = $wellKnown;
        }

        foreach ($candidates as $candidate) {
            $principal = $this->findPrincipal($candidate);
            if ($principal === null) {
                continue;
            }
            $found = $this->propfind($principal, 0, '<d:displayname/><c:calendar-home-set/>');
            foreach ($found as $response) {
                $home = $this->hrefIn($response['props']['c:calendar-home-set'] ?? null, $response['base']);
                if ($home !== null) {
                    return ['home' => $home, 'name' => $this->textIn($response['props']['d:displayname'] ?? null)];
                }
            }
        }

        throw new CalendarSyncFailed('No calendars were found at this address. Check the server URL (for iCloud: https://caldav.icloud.com).');
    }

    /**
     * The event calendars in a calendar home.
     *
     * @return list<array{url: string, name: string, color: string|null, read_only: bool}>
     */
    public function calendars(string $home): array
    {
        $out = [];
        $props = '<d:resourcetype/><d:displayname/><a:calendar-color/><c:supported-calendar-component-set/><d:current-user-privilege-set/>';
        foreach ($this->propfind($home, 1, $props) as $response) {
            $type = $response['props']['d:resourcetype'] ?? null;
            if (! $type instanceof DOMElement || $type->getElementsByTagNameNS(self::NS['c'], 'calendar')->length === 0) {
                continue;
            }
            $components = $response['props']['c:supported-calendar-component-set'] ?? null;
            if ($components instanceof DOMElement && ! $this->supportsEvents($components)) {
                continue; // a reminders / tasks list
            }
            $url = $response['url'];
            if (rtrim($url, '/') === rtrim($home, '/') || strlen($url) > 255) {
                continue;
            }
            $name = $this->textIn($response['props']['d:displayname'] ?? null)
                ?? rawurldecode(basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/')));
            $color = $this->textIn($response['props']['a:calendar-color'] ?? null);

            $out[] = [
                'url' => $url,
                'name' => mb_substr($name, 0, 120),
                'color' => $color !== null && preg_match('/^#[0-9a-fA-F]{6}/', $color) ? strtoupper(substr($color, 0, 7)) : null,
                'read_only' => ! $this->canWrite($response['props']['d:current-user-privilege-set'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * calendar-query REPORT: the calendar objects with an event in [$fromUtc, $toUtc).
     *
     * @return list<array{href: string, etag: string|null, data: string}>
     */
    public function events(string $calendarUrl, string $fromUtc, string $toUtc): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .'<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop><d:getetag/><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            .'<c:time-range start="'.$fromUtc.'" end="'.$toUtc.'"/>'
            .'</c:comp-filter></c:comp-filter></c:filter>'
            .'</c:calendar-query>';

        $response = $this->request('REPORT', $calendarUrl, $body, ['Depth' => '1'], $answered);
        $this->assertStatus($response, [207]);

        $out = [];
        foreach ($this->multistatus($response->body(), (string) $answered) as $item) {
            $data = $this->textIn($item['props']['c:calendar-data'] ?? null);
            if ($data !== null) {
                $out[] = ['href' => $item['url'], 'etag' => $this->textIn($item['props']['d:getetag'] ?? null), 'data' => $data];
            }
        }

        return $out;
    }

    /** @return array{data: string, etag: string|null}|null null when the object is gone */
    public function get(string $objectUrl): ?array
    {
        $response = $this->request('GET', $objectUrl, null, ['Accept' => 'text/calendar']);
        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }
        $this->assertStatus($response, [200]);

        return ['data' => $response->body(), 'etag' => $response->header('ETag') ?: null];
    }

    /** PUT an object: create = If-None-Match: *; update = If-Match: $etag (none when the server gave no ETag). */
    public function put(string $objectUrl, string $ics, ?string $etag, bool $create = false): void
    {
        $headers = $create ? ['If-None-Match' => '*'] : ($etag !== null ? ['If-Match' => $etag] : []);
        $response = $this->request('PUT', $objectUrl, $ics, $headers, contentType: 'text/calendar; charset=utf-8');
        $this->assertStatus($response, [200, 201, 204]);
    }

    /** DELETE an object; "already gone" counts as deleted. */
    public function delete(string $objectUrl, ?string $etag): void
    {
        $response = $this->request('DELETE', $objectUrl, null, $etag !== null ? ['If-Match' => $etag] : []);
        if (in_array($response->status(), [404, 410], true)) {
            return;
        }
        $this->assertStatus($response, [200, 202, 204]);
    }

    /**
     * Send one request; the app password only goes to hosts of the server's site.
     *
     * @param  array<string, string>  $headers
     */
    public function request(string $method, string $url, ?string $body = null, array $headers = [], ?string &$answered = null, string $contentType = 'application/xml; charset=utf-8'): Response
    {
        if (self::site(SafeRemoteUrl::host($url)) !== $this->site) {
            throw new RemoteUrlRefused('The calendar server sent us to another host. This server isn’t supported.');
        }
        // basic auth over plain http would send the password readable (the e2e fixture server is http)
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' && ! SafeRemoteUrl::allowPrivate()) {
            throw new RemoteUrlRefused('Use an https:// address: your password must not be sent unencrypted.');
        }

        $response = SafeRemoteUrl::send($method, $url, [
            'headers' => $headers,
            'body' => $body,
            'content_type' => $contentType,
            'auth' => [$this->username, $this->password],
        ], $answered);

        // Redirected to another origin: that answer came without credentials, so its hrefs can't be
        // trusted to say where the password goes next.
        if (! SafeRemoteUrl::sameOrigin((string) $answered, $url)) {
            throw new RemoteUrlRefused('The calendar server sent us to another host. This server isn’t supported.');
        }

        return $response;
    }

    /** @param list<int> $ok */
    public function assertStatus(Response $response, array $ok): void
    {
        $status = $response->status();
        if (in_array($status, $ok, true)) {
            return;
        }

        if ($status === 401) {
            throw new CaldavAuthFailed;
        }

        throw new CalendarSyncFailed(match (true) {
            $status === 403 => 'The calendar server didn’t allow this. Check that you may change this calendar.',
            in_array($status, [404, 410], true) => 'This calendar no longer exists on the server. Remove it, or reconnect.',
            $status === 412 => 'This event was changed in another app. Reload the calendar and try again.',
            $status === 507 => 'The calendar server is out of space.',
            default => "The calendar server didn’t answer (error {$status}). Try again later.",
        });
    }

    /** "Same site" for the app password: example.com for a.b.example.com (co.uk-style suffixes kept). */
    public static function site(string $host): string
    {
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return $host;
        }
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n <= 2) {
            return $host;
        }
        $take = strlen($labels[$n - 1]) === 2 && strlen($labels[$n - 2]) <= 3 ? 3 : 2;

        return implode('.', array_slice($labels, -$take));
    }

    private function findPrincipal(string $url): ?string
    {
        try {
            $found = $this->propfind($url, 0, '<d:current-user-principal/><c:calendar-home-set/>');
        } catch (CalendarSyncFailed $e) {
            // a 401 means the credentials are wrong everywhere; other errors: try the next address
            if ($e instanceof RemoteUrlRefused || $e instanceof CaldavAuthFailed) {
                throw $e;
            }

            return null;
        }
        foreach ($found as $response) {
            if ($principal = $this->hrefIn($response['props']['d:current-user-principal'] ?? null, $response['base'])) {
                return $principal;
            }
            // some servers answer calendar-home-set right here
            if ($this->hrefIn($response['props']['c:calendar-home-set'] ?? null, $response['base'])) {
                return $response['url'];
            }
        }

        return null;
    }

    /**
     * PROPFIND → the multistatus responses.
     *
     * @return list<array{url: string, base: string, props: array<string, DOMElement>}>
     */
    private function propfind(string $url, int $depth, string $props): array
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/">'
            .'<d:prop>'.$props.'</d:prop></d:propfind>';
        $response = $this->request('PROPFIND', $url, $body, ['Depth' => (string) $depth], $answered);
        $this->assertStatus($response, [207]);

        return $this->multistatus($response->body(), (string) $answered);
    }

    /**
     * Parse a 207 Multi-Status body: per response its absolute URL and the props of its 200 propstats.
     *
     * @return list<array{url: string, base: string, props: array<string, DOMElement>}>
     */
    private function multistatus(string $xml, string $base): array
    {
        // no DTDs: no entity expansion tricks; LIBXML_NONET: never fetch anything
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw new CalendarSyncFailed('The calendar server sent an answer we couldn’t read.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        // the string check above misses UTF-16 bodies: refuse any DTD before reading a text node
        if (! $loaded || $dom->doctype !== null) {
            throw new CalendarSyncFailed('The calendar server sent an answer we couldn’t read.');
        }

        $xpath = new DOMXPath($dom);
        foreach (self::NS as $prefix => $ns) {
            $xpath->registerNamespace($prefix, $ns);
        }

        $out = [];
        foreach ($xpath->query('//d:multistatus/d:response') ?: [] as $response) {
            $href = trim((string) $xpath->evaluate('string(d:href)', $response));
            if ($href === '') {
                continue;
            }
            $props = [];
            foreach ($xpath->query('d:propstat', $response) ?: [] as $propstat) {
                $status = (string) $xpath->evaluate('string(d:status)', $propstat);
                if (! preg_match('/\s2\d\d\s/', $status.' ')) {
                    continue;
                }
                foreach ($xpath->query('d:prop/*', $propstat) ?: [] as $prop) {
                    if ($prop instanceof DOMElement) {
                        $prefix = array_search($prop->namespaceURI, self::NS, true);
                        if ($prefix !== false) {
                            $props[$prefix.':'.$prop->localName] = $prop;
                        }
                    }
                }
            }
            $out[] = ['url' => SafeRemoteUrl::resolve($base, $href), 'base' => $base, 'props' => $props];
        }

        return $out;
    }

    private function hrefIn(?DOMElement $prop, string $base): ?string
    {
        if ($prop === null) {
            return null;
        }
        $href = $prop->getElementsByTagNameNS(self::NS['d'], 'href')->item(0)?->textContent;

        return $href !== null && trim($href) !== '' ? SafeRemoteUrl::resolve($base, trim($href)) : null;
    }

    private function textIn(?DOMElement $prop): ?string
    {
        $text = $prop !== null ? trim($prop->textContent) : '';

        return $text !== '' ? $text : null;
    }

    private function supportsEvents(DOMElement $set): bool
    {
        foreach ($set->getElementsByTagNameNS(self::NS['c'], 'comp') as $comp) {
            if (strtoupper($comp->getAttribute('name')) === 'VEVENT') {
                return true;
            }
        }

        return false;
    }

    /** No privilege set = assume writable (the server will refuse with 403 otherwise). */
    private function canWrite(?DOMElement $privileges): bool
    {
        if ($privileges === null) {
            return true;
        }
        foreach (['all', 'write', 'write-content', 'bind'] as $name) {
            if ($privileges->getElementsByTagNameNS(self::NS['d'], $name)->length > 0) {
                return true;
            }
        }

        return false;
    }
}
