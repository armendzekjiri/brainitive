<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\Caldav\CaldavAuthFailed;
use App\Support\Calendar\Caldav\CaldavClient;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\Ics\IcsComponent;
use App\Support\Calendar\Ics\IcsEvents;
use App\Support\Calendar\Ics\IcsInstance;
use App\Support\Calendar\Ics\IcsProperty;
use App\Support\Calendar\Ics\IcsReader;
use App\Support\Calendar\Ics\IcsTime;
use App\Support\Calendar\PendingConnection;
use App\Support\Calendar\RemoteUrlRefused;
use App\Support\Calendar\SafeRemoteUrl;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Apple iCloud and any CalDAV server (Fastmail, Nextcloud, Zoho, Synology, Radicale, …) —
 * BRAI-141. Connect with server URL + username + app password (the 'form' dialog), pick
 * calendars in the shared picker; read + write.
 *
 * - Rows: account_email = the username (username@host when it isn't an email),
 *   external_calendar_id = the calendar collection's URL, server_url = the URL entered,
 *   credentials = {username, password[, read_only]} (encrypted).
 * - Events: calendar-query REPORT for the range; recurring events are expanded here
 *   (IcsEvents), not with the server's <expand> (support differs, and writes need the master).
 * - Event ids: base64url(object path) [+ "~<occurrence key>" for one occurrence of a series].
 *   An id only ever addresses objects inside the connection's calendar collection.
 * - Writes: create = PUT <calendar>/<uuid>.ics (If-None-Match: *); update = GET + change + PUT
 *   (If-Match); delete = DELETE (If-Match). One occurrence of a series is changed through a
 *   RECURRENCE-ID override and deleted with an EXDATE. Timed events are written in UTC.
 */
class CaldavCalendarProvider extends BaseCalendarProvider
{
    public const ICLOUD = 'https://caldav.icloud.com';

    public function key(): string
    {
        return 'caldav';
    }

    public function label(): string
    {
        return 'Apple iCloud / CalDAV';
    }

    public function badge(): string
    {
        return 'C';
    }

    public function defaultColor(): string
    {
        return '#FF2D55';
    }

    public function connectMode(): string
    {
        return 'form';
    }

    public function capabilities(): array
    {
        return ['write' => true, 'tasks' => false];
    }

    public function formFields(): array
    {
        return [
            [
                'name' => 'server_url',
                'label' => 'Server URL',
                'type' => 'url',
                'required' => false,
                'placeholder' => self::ICLOUD,
                'help' => 'Leave empty for Apple iCloud. Fastmail: https://caldav.fastmail.com, Nextcloud: https://<your cloud>/remote.php/dav.',
            ],
            [
                'name' => 'username',
                'label' => 'Username or Apple ID',
                'type' => 'text',
                'placeholder' => 'name@icloud.com',
            ],
            [
                'name' => 'password',
                'label' => 'App password',
                'type' => 'password',
                'help' => 'iCloud: create an app-specific password at appleid.apple.com → Sign-In and Security. Other servers: an app password (or your password).',
            ],
        ];
    }

    public function connectWithForm(User $user, array $input): array|PendingConnection
    {
        $server = SafeRemoteUrl::normalize(trim((string) ($input['server_url'] ?? '')) ?: self::ICLOUD);
        if ($server === null) {
            throw ValidationException::withMessages(['server_url' => 'Use an https:// address.']);
        }
        if (! SafeRemoteUrl::allowed($server)) {
            throw ValidationException::withMessages(['server_url' => (new RemoteUrlRefused)->getMessage()]);
        }
        $username = trim((string) ($input['username'] ?? ''));
        $password = trim((string) ($input['password'] ?? ''));

        $client = new CaldavClient($server, $username, $password);
        try {
            $found = $client->discover();
            $calendars = $client->calendars($found['home']);
        } catch (CaldavAuthFailed) {
            throw ValidationException::withMessages(['password' => $server === self::ICLOUD
                ? 'Check your Apple ID and app-specific password (not your Apple ID password).'
                : 'Check your username and app password.']);
        } catch (RemoteUrlRefused $e) {
            throw ValidationException::withMessages(['server_url' => $e->getMessage()]);
        }

        if ($calendars === []) {
            throw new CalendarSyncFailed('No calendars with events were found for this account.');
        }

        $primary = collect($calendars)->firstWhere('read_only', false)['url'] ?? null;
        $account = str_contains($username, '@') ? $username : $username.'@'.SafeRemoteUrl::host($server);
        if (mb_strlen($account) > 255) {
            throw ValidationException::withMessages(['username' => 'This username is too long.']);
        }

        return new PendingConnection(
            provider: $this->key(),
            accountEmail: $account,
            accountName: $found['name'] !== null ? Str::limit($found['name'], 120) : null,
            calendars: array_map(fn (array $c) => [
                'id' => $c['url'],
                'name' => $c['name'],
                'color' => $c['color'],
                'primary' => $c['url'] === $primary,
                'read_only' => $c['read_only'],
            ], $calendars),
            secrets: ['credentials' => ['username' => $username, 'password' => $password]],
            serverUrl: Str::limit($server, 2000, ''),
        );
    }

    public function listCalendars(CalendarConnection $connection): array
    {
        $client = $this->client($connection);
        $calendars = $client->calendars($client->discover()['home']);

        return array_map(fn (array $c) => [
            'id' => $c['url'],
            'name' => $c['name'],
            'color' => $c['color'],
            'primary' => false,
            'read_only' => $c['read_only'],
        ], $calendars);
    }

    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        $zone = $timezone ?? (string) config('app.timezone');
        $calendarUrl = $this->calendarUrl($connection);
        // a day of margin on both sides (all-day events are floating on the server); exact filter below
        $from = CarbonImmutable::parse($start->toDateString(), $zone)->subDay()->utc()->format('Ymd\THis\Z');
        $to = CarbonImmutable::parse($end->toDateString(), $zone)->addDays(2)->utc()->format('Ymd\THis\Z');

        $events = [];
        foreach ($this->client($connection)->events($calendarUrl, $from, $to) as $object) {
            if (! $this->inCalendar($object['href'], $calendarUrl) || ($calendar = IcsReader::parse($object['data'])) === null) {
                continue;
            }
            foreach (IcsEvents::between($calendar, $start->toDateString(), $end->toDateString(), $zone) as $instance) {
                $events[] = $instance->normalize($this->eventId($object['href'], $instance->recurrenceKey), $timezone);
            }
        }

        return $events;
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        $target = $this->decodeId($connection, $eventId);
        if ($target === null) {
            return null;
        }
        [$url, $key] = $target;
        $object = $this->client($connection)->get($url);
        $calendar = $object !== null ? IcsReader::parse($object['data']) : null;
        if ($calendar === null) {
            return null;
        }
        $instance = IcsEvents::occurrence($calendar, $calendar->children('VEVENT'), $key, $timezone ?? (string) config('app.timezone'));

        return $instance?->normalize($this->eventId($url, $key), $timezone);
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from(['title' => (string) ($data['title'] ?? '')] + $data);
        $uid = (string) Str::uuid();
        $url = rtrim($this->calendarUrl($connection), '/').'/'.$uid.'.ics';

        $event = new IcsComponent('VEVENT');
        $event->add(new IcsProperty('UID', [], $uid))->add(new IcsProperty('DTSTAMP', [], gmdate('Ymd\THis\Z')))->add(new IcsProperty('CREATED', [], gmdate('Ymd\THis\Z')));
        $this->apply($event, $input);

        $calendar = new IcsComponent('VCALENDAR');
        $calendar->add(new IcsProperty('VERSION', [], '2.0'))
            ->add(new IcsProperty('PRODID', [], '-//Brainitive//Calendar//EN'))
            ->add(new IcsProperty('CALSCALE', [], 'GREGORIAN'));
        $calendar->children[] = $event;

        $this->client($connection)->put($url, $calendar->toString(), null, create: true);

        return $this->normalized($calendar, $url, null, $input->timezone());
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array
    {
        $this->assertWritable($connection);
        [$url, $key] = $this->decodeId($connection, $eventId) ?? throw new CalendarSyncFailed('This event no longer exists.');
        $input = EventInput::from($data);
        $client = $this->client($connection);

        $object = $client->get($url) ?? throw new CalendarSyncFailed('This event no longer exists.');
        $calendar = IcsReader::parse($object['data']) ?? throw new CalendarSyncFailed('This event couldn’t be read.');
        $event = $this->editable($calendar, $key, $input->timezone()) ?? throw new CalendarSyncFailed('This event no longer exists.');
        if ($key === null && $event->first('RRULE') !== null && $input->changesTimes()) {
            // moving a whole series would rewrite its DTSTART in UTC and lose its time zone (DST)
            throw new CalendarSyncFailed('Move one occurrence at a time, or change the whole series in your calendar app.');
        }

        $this->apply($event, $input);
        $client->put($url, $calendar->toString(), $object['etag']);

        return $this->normalized($calendar, $url, $key, $input->timezone());
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->assertWritable($connection);
        [$url, $key] = $this->decodeId($connection, $eventId) ?? throw new CalendarSyncFailed('This event no longer exists.');
        $client = $this->client($connection);

        $object = $client->get($url);
        if ($object === null) {
            return; // already gone
        }
        if ($key === null) {
            $client->delete($url, $object['etag']);

            return;
        }

        // one occurrence: drop its override, and exclude it from the series (if the object has one)
        $calendar = IcsReader::parse($object['data']) ?? throw new CalendarSyncFailed('This event couldn’t be read.');
        $master = $this->master($calendar);
        $time = new IcsTime($calendar, (string) config('app.timezone'));
        $calendar->children = array_values(array_filter($calendar->children, function (IcsComponent $child) use ($time, $key) {
            $rid = $child->name === 'VEVENT' ? $time->parse($child->first('RECURRENCE-ID')) : null;

            return $rid === null || IcsTime::key(...$rid) !== $key;
        }));
        if ($master === null) {
            // only single-occurrence objects (an invitation to some occurrences): keep the others
            if ($calendar->children('VEVENT') === []) {
                $client->delete($url, $object['etag']);
            } else {
                $client->put($url, $calendar->toString(), $object['etag']);
            }

            return;
        }
        $master->add($this->occurrenceProperty('EXDATE', $master, $key, $time));
        $this->touch($master);
        $client->put($url, $calendar->toString(), $object['etag']);
    }

    private function client(CalendarConnection $connection): CaldavClient
    {
        $credentials = (array) $connection->credentials;
        if (empty($credentials['username']) || ! isset($credentials['password'])) {
            throw new CalendarSyncFailed('Reconnect this calendar.');
        }

        return new CaldavClient((string) ($connection->server_url ?: $connection->external_calendar_id), (string) $credentials['username'], (string) $credentials['password']);
    }

    private function calendarUrl(CalendarConnection $connection): string
    {
        $url = (string) $connection->external_calendar_id;

        return str_ends_with($url, '/') ? $url : $url.'/';
    }

    /**
     * Whether an object URL lies inside the calendar collection: same origin, path below it (also
     * once percent-decoded, so %2e%2e can't climb out), no dot segments, no blanks / controls /
     * backslashes (such paths couldn't round-trip through an event id).
     */
    private function inCalendar(string $objectUrl, string $calendarUrl): bool
    {
        $raw = (string) parse_url($objectUrl, PHP_URL_PATH);
        $path = rawurldecode($raw);
        $base = rawurldecode(rtrim((string) parse_url($calendarUrl, PHP_URL_PATH), '/').'/');

        return SafeRemoteUrl::sameOrigin($objectUrl, $calendarUrl)
            && ! preg_match('/[\s\x00-\x1F\\\\]/', $raw.$path)
            && str_starts_with($path, $base) && strlen($path) > strlen($base)
            && ! preg_match('~(^|/)\.\.?(/|$)~', $path);
    }

    private function eventId(string $objectUrl, ?string $key): string
    {
        $path = (string) parse_url($objectUrl, PHP_URL_PATH);

        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=').($key !== null ? '~'.$key : '');
    }

    /**
     * Event id → [object URL, occurrence key], or null when it doesn't address an object of
     * this connection's calendar.
     *
     * @return array{0: string, 1: string|null}|null
     */
    private function decodeId(CalendarConnection $connection, string $eventId): ?array
    {
        if (! preg_match('/^([A-Za-z0-9_-]{4,2800})(?:~(\d{8}(?:T\d{6}Z?)?))?$/', $eventId, $m)) {
            return null;
        }
        $path = base64_decode(strtr($m[1], '-_', '+/'), true);
        if ($path === false || ! str_starts_with($path, '/') || preg_match('/[?#\s\x00-\x1F]/', $path)) {
            return null;
        }
        $calendarUrl = $this->calendarUrl($connection);
        $url = SafeRemoteUrl::origin($calendarUrl).$path;

        return $this->inCalendar($url, $calendarUrl) ? [$url, isset($m[2]) && $m[2] !== '' ? $m[2] : null] : null;
    }

    private function master(IcsComponent $calendar): ?IcsComponent
    {
        foreach ($calendar->children('VEVENT') as $event) {
            if ($event->first('RECURRENCE-ID') === null) {
                return $event;
            }
        }

        return null;
    }

    /**
     * The VEVENT to change: the event itself, the override of occurrence $key, or a new override
     * for it (a copy of the series' details at that occurrence's time).
     */
    private function editable(IcsComponent $calendar, ?string $key, string $zone): ?IcsComponent
    {
        if ($key === null) {
            return $this->master($calendar) ?? ($calendar->children('VEVENT')[0] ?? null);
        }
        $time = new IcsTime($calendar, $zone);
        foreach ($calendar->children('VEVENT') as $event) {
            $rid = $time->parse($event->first('RECURRENCE-ID'));
            if ($rid !== null && IcsTime::key(...$rid) === $key) {
                return $event;
            }
        }

        $master = $this->master($calendar);
        $occurrence = $master !== null ? IcsEvents::occurrence($calendar, [$master], $key, $zone) : null;
        if ($occurrence === null) {
            return null;
        }

        $override = new IcsComponent('VEVENT');
        foreach ($master->properties as $property) {
            if (! in_array($property->name, ['RRULE', 'RDATE', 'EXDATE', 'EXRULE', 'DTSTART', 'DTEND', 'DURATION', 'RECURRENCE-ID'], true)) {
                $override->add($property);
            }
        }
        $override->add($this->occurrenceProperty('RECURRENCE-ID', $master, $key, $time));
        $this->setTimes($override, $occurrence->allDay, $occurrence->start, $occurrence->end);
        $calendar->children[] = $override;

        return $override;
    }

    /** RECURRENCE-ID / EXDATE for occurrence $key, in the same form as the series' DTSTART. */
    private function occurrenceProperty(string $name, IcsComponent $master, string $key, IcsTime $time): IcsProperty
    {
        $isDate = strlen($key) === 8;
        if (! $isDate && ! str_ends_with($key, 'Z')) {
            return new IcsProperty($name, [], $key); // a floating series: wall-clock, like its DTSTART
        }
        $at = $isDate
            ? CarbonImmutable::createFromFormat('!Ymd', $key, 'UTC')
            : CarbonImmutable::createFromFormat('Ymd\THis\Z', $key, 'UTC');
        if ($isDate) {
            return new IcsProperty($name, ['VALUE' => 'DATE'], $at->format('Ymd'));
        }
        $tzid = $master->first('DTSTART')?->param('TZID');
        if ($tzid !== null && $tzid !== '') {
            return new IcsProperty($name, ['TZID' => $tzid], IcsTime::format($at->setTimezone($time->zone($tzid)), false, $tzid));
        }

        return new IcsProperty($name, [], IcsTime::format($at, false));
    }

    /**
     * Apply the write input: title / description / location when sent, the times when start_date
     * is sent; then bump SEQUENCE / DTSTAMP / LAST-MODIFIED.
     */
    private function apply(IcsComponent $event, EventInput $input): void
    {
        foreach (['title' => 'SUMMARY', 'description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $field => $property) {
            if (! $input->has($field)) {
                continue;
            }
            $value = trim((string) $input->get($field));
            if ($value === '' && $field !== 'title') {
                $event->remove($property);
            } else {
                $event->set(IcsProperty::text($property, $value));
            }
        }

        if ($input->changesTimes()) {
            $allDay = $input->isAllDay();
            $start = $allDay ? CarbonImmutable::parse($input->startDate(), 'UTC') : CarbonImmutable::instance($input->start());
            $end = $allDay ? CarbonImmutable::parse($input->endDateExclusive(), 'UTC') : CarbonImmutable::instance($input->end());
            $this->setTimes($event, $allDay, $start, $end);
        }

        $this->touch($event);
    }

    private function setTimes(IcsComponent $event, bool $allDay, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $event->remove('DTSTART', 'DTEND', 'DURATION');
        $params = $allDay ? ['VALUE' => 'DATE'] : [];
        $event->add(new IcsProperty('DTSTART', $params, IcsTime::format($start, $allDay)));
        $event->add(new IcsProperty('DTEND', $params, IcsTime::format($end, $allDay)));
    }

    private function touch(IcsComponent $event): void
    {
        $now = gmdate('Ymd\THis\Z');
        $event->set(new IcsProperty('SEQUENCE', [], (string) (((int) $event->first('SEQUENCE')?->value) + 1)));
        $event->set(new IcsProperty('DTSTAMP', [], $now));
        $event->set(new IcsProperty('LAST-MODIFIED', [], $now));
    }

    /** The normalized event of occurrence $key (or the event) after a write. */
    private function normalized(IcsComponent $calendar, string $url, ?string $key, string $timezone): array
    {
        /** @var IcsInstance|null $instance */
        $instance = IcsEvents::occurrence($calendar, $calendar->children('VEVENT'), $key, $timezone);
        if ($instance === null) {
            throw new CalendarSyncFailed('The calendar server didn’t keep the event. Try again.');
        }

        return $instance->normalize($this->eventId($url, $key), $timezone);
    }
}
