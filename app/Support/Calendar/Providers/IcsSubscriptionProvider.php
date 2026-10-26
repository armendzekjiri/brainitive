<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\Ics\IcsComponent;
use App\Support\Calendar\Ics\IcsEvents;
use App\Support\Calendar\Ics\IcsInstance;
use App\Support\Calendar\Ics\IcsReader;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\RemoteUrlRefused;
use App\Support\Calendar\SafeRemoteUrl;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A read-only calendar from an ICS / iCal / webcal link (BRAI-141): Google's "secret address in
 * iCal format", an Outlook published calendar, an Apple public calendar, holidays, timetables.
 *
 * - Each link is its own connection: account_email = server_url = the link's host,
 *   external_calendar_id = sha1(link). The link itself (it usually carries a secret token) lives
 *   only in the encrypted `credentials`.
 * - The file is fetched through SafeRemoteUrl (SSRF guard, 5 MB cap), cached per connection for
 *   config('calendar.ics_refresh_minutes') and then revalidated with ETag / If-Modified-Since.
 * - Event ids: the first 24 hex chars of sha1(UID), plus "~<occurrence key>" for an occurrence of
 *   a recurring event (IcsTime::key of its original start).
 */
class IcsSubscriptionProvider extends BaseCalendarProvider
{
    public function key(): string
    {
        return 'ics';
    }

    public function label(): string
    {
        return 'Calendar link (ICS)';
    }

    public function badge(): string
    {
        return 'L';
    }

    public function defaultColor(): string
    {
        return '#0D9488';
    }

    public function connectMode(): string
    {
        return 'url';
    }

    public function formFields(): array
    {
        return [[
            'name' => 'url',
            'label' => 'Calendar link',
            'type' => 'url',
            'help' => 'The calendar’s iCal / ICS address (https:// or webcal://). Google: Settings → your calendar → “Secret address in iCal format”. Read-only, refreshed about every 15 minutes.',
            'placeholder' => 'https://calendar.google.com/calendar/ical/…/basic.ics',
        ]];
    }

    public function connectWithForm(User $user, array $input): array|PendingConnection
    {
        $url = SafeRemoteUrl::normalize((string) ($input['url'] ?? ''));
        if ($url === null) {
            throw ValidationException::withMessages(['url' => 'Use an https://, http:// or webcal:// link.']);
        }
        if (! SafeRemoteUrl::allowed($url)) {
            throw ValidationException::withMessages(['url' => (new RemoteUrlRefused)->getMessage()]);
        }

        try {
            $fetched = $this->download($url, null);
        } catch (RemoteUrlRefused $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
        $calendar = IcsReader::parse($fetched['body'])
            ?? throw ValidationException::withMessages(['url' => 'This link didn’t return a calendar. Copy the calendar’s iCal / ICS address (it usually ends in .ics).']);

        $host = SafeRemoteUrl::host($url);
        $connection = CalendarConnection::upsertFor($user->id, $this->key(), 'calendar', $host, sha1($url), [
            'external_calendar_name' => Str::limit($calendar->text('X-WR-CALNAME') ?? $host, 120),
            'external_calendar_color' => $this->color($calendar),
            'server_url' => $host,
            'credentials' => ['url' => $url],
            'is_active' => true,
            'sync_error' => null,
            'sync_error_at' => null,
        ]);
        $this->remember($connection, $url, $fetched); // the first page load needs no second download

        return [$connection];
    }

    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        $calendar = $this->calendar($connection);
        $zone = $timezone ?? (string) config('app.timezone');

        return array_map(
            fn (IcsInstance $instance) => $instance->normalize($this->eventId($instance), $timezone),
            IcsEvents::between($calendar, $start->toDateString(), $end->toDateString(), $zone),
        );
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        if (! preg_match('/^([0-9a-f]{24})(?:~(\d{8}(?:T\d{6}Z?)?))?$/', $eventId, $m)) {
            return null;
        }
        $calendar = $this->calendar($connection);
        $events = array_values(array_filter(
            $calendar->children('VEVENT'),
            fn (IcsComponent $event) => $this->uidHash(IcsEvents::uidOf($event)) === $m[1],
        ));
        if ($events === []) {
            return null;
        }
        $instance = IcsEvents::occurrence($calendar, $events, isset($m[2]) && $m[2] !== '' ? $m[2] : null, $timezone ?? (string) config('app.timezone'));

        return $instance?->normalize($this->eventId($instance), $timezone);
    }

    private function eventId(IcsInstance $instance): string
    {
        return $this->uidHash($instance->uid).($instance->recurrenceKey !== null ? '~'.$instance->recurrenceKey : '');
    }

    private function uidHash(string $uid): string
    {
        return substr(sha1($uid), 0, 24);
    }

    /** The parsed calendar of a connection (cached download). */
    private function calendar(CalendarConnection $connection): IcsComponent
    {
        return IcsReader::parse($this->body($connection))
            ?? throw new CalendarSyncFailed('This link no longer returns a calendar. Check the link, or remove it.');
    }

    private function body(CalendarConnection $connection): string
    {
        $url = SafeRemoteUrl::normalize((string) (((array) $connection->credentials)['url'] ?? ''))
            ?? throw new CalendarSyncFailed('Connect this calendar link again.');
        $key = $this->cacheKey($connection, $url);

        $cached = Cache::get($key);
        $cached = is_array($cached) && isset($cached['body'], $cached['fetched_at']) ? $cached : null;
        if ($cached !== null && $cached['fetched_at'] > now()->subMinutes(max(1, (int) config('calendar.ics_refresh_minutes', 15)))->getTimestamp()) {
            return $this->unpack($cached['body']);
        }

        $fetched = $this->download($url, $cached);
        $this->remember($connection, $url, $fetched);

        return $fetched['body'];
    }

    /**
     * GET the link (conditional when we have a cached copy). A 304 reuses the cached body.
     *
     * @param  array{body: string, etag?: string|null, last_modified?: string|null}|null  $cached
     * @return array{body: string, etag: string|null, last_modified: string|null}
     */
    private function download(string $url, ?array $cached): array
    {
        $headers = ['Accept' => 'text/calendar, text/plain;q=0.9, */*;q=0.5'];
        if (! empty($cached['etag'])) {
            $headers['If-None-Match'] = $cached['etag'];
        }
        if (! empty($cached['last_modified'])) {
            $headers['If-Modified-Since'] = $cached['last_modified'];
        }

        $response = SafeRemoteUrl::send('GET', $url, ['headers' => $headers]);

        if ($response->status() === 304 && $cached !== null) {
            return [
                'body' => $this->unpack($cached['body']),
                'etag' => $response->header('ETag') ?: ($cached['etag'] ?? null),
                'last_modified' => $response->header('Last-Modified') ?: ($cached['last_modified'] ?? null),
            ];
        }
        $this->assertOk($response);

        return [
            'body' => $response->body(),
            'etag' => $response->header('ETag') ?: null,
            'last_modified' => $response->header('Last-Modified') ?: null,
        ];
    }

    private function assertOk(Response $response): void
    {
        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return;
        }

        throw new CalendarSyncFailed(match (true) {
            in_array($status, [401, 403], true) => 'This calendar link is private or was reset. Copy the secret address again and reconnect.',
            in_array($status, [404, 410], true) => 'This calendar link no longer exists. Check the link, or remove it.',
            default => "The calendar link didn’t answer (error {$status}). Try again later.",
        });
    }

    /** @param array{body: string, etag: string|null, last_modified: string|null} $fetched */
    private function remember(CalendarConnection $connection, string $url, array $fetched): void
    {
        Cache::put($this->cacheKey($connection, $url), [
            // compressed + base64: feeds are big and some cache stores dislike binary
            'body' => base64_encode((string) gzcompress($fetched['body'], 6)),
            'etag' => $fetched['etag'],
            'last_modified' => $fetched['last_modified'],
            'fetched_at' => now()->getTimestamp(),
        ], now()->addDay());
    }

    private function unpack(string $stored): string
    {
        $raw = base64_decode($stored, true);

        return $raw !== false && ($body = @gzuncompress($raw)) !== false ? $body : '';
    }

    private function cacheKey(CalendarConnection $connection, string $url): string
    {
        return 'calendar:ics:'.$connection->id.':'.sha1($url);
    }

    /** X-APPLE-CALENDAR-COLOR (#RRGGBB[AA]) as #RRGGBB, else null (the provider colour). */
    private function color(IcsComponent $calendar): ?string
    {
        $color = trim((string) $calendar->first('X-APPLE-CALENDAR-COLOR')?->value);

        return preg_match('/^#[0-9a-fA-F]{6}/', $color) ? strtoupper(substr($color, 0, 7)) : null;
    }
}
