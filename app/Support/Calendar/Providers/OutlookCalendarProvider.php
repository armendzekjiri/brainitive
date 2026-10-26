<?php

namespace App\Support\Calendar\Providers;

use App\Models\CalendarConnection;
use App\Support\Calendar\BaseCalendarProvider;
use App\Support\Calendar\CalendarReadOnly;
use App\Support\Calendar\CalendarSyncFailed;
use App\Support\Calendar\EventInput;
use App\Support\Calendar\NormalizedEvent;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Outlook.com / Microsoft 365 calendars through Microsoft Graph (BRAI-140).
 *
 * OAuth 2.0 authorization code + PKCE against the Microsoft identity platform v2 (tenant from
 * config, default `common` = personal and work / school accounts); the flow itself lives in
 * OutlookCalendarConnectController, the token endpoint calls live here. Access tokens are
 * refreshed shortly before they expire and once on a 401; a refused refresh is a sync error
 * ("Reconnect Outlook") and the connection stays.
 *
 * Every request sends `Prefer: outlook.timezone="UTC"`, so Graph's wall-clock times are UTC
 * (other zones — IANA or Windows names — are still understood). calendarView expands
 * recurring series into instances; each instance has its own id. Events are always addressed
 * under the connected calendar (/me/calendars/{id}/events/{eventId}), so a connection can't
 * reach events of another calendar of the account.
 */
class OutlookCalendarProvider extends BaseCalendarProvider
{
    public const SCOPES = 'offline_access User.Read Calendars.ReadWrite';

    public const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** calendarView pages followed at most (× PAGE_SIZE events). */
    private const MAX_PAGES = 20;

    private const PAGE_SIZE = 100;

    private const EXPIRED = 'Outlook access expired. Reconnect Outlook.';

    private const DOWN = 'Outlook didn’t answer. Try again later.';

    public function key(): string
    {
        return 'outlook';
    }

    public function label(): string
    {
        return 'Outlook / Microsoft 365';
    }

    public function badge(): string
    {
        return 'O';
    }

    public function defaultColor(): string
    {
        return '#0078D4';
    }

    public function connectMode(): string
    {
        return 'oauth';
    }

    /** Only with an app registration (client id + secret) configured. */
    public function available(): bool
    {
        return filled(config('services.microsoft.client_id')) && filled(config('services.microsoft.client_secret'));
    }

    public function redirectRoute(): ?string
    {
        return 'calendar.connections.outlook.redirect';
    }

    public function capabilities(): array
    {
        return ['write' => true, 'tasks' => false, 'conferencing' => true];
    }

    /**
     * Teams, only when this calendar allows Teams-for-business meetings
     * (`allowedOnlineMeetingProviders`). Personal Microsoft accounts usually don't; a failed
     * lookup offers nothing.
     */
    public function conferenceProviders(CalendarConnection $connection): array
    {
        if ($connection->kind !== 'calendar' || ! $this->isWritable($connection)) {
            return [];
        }
        try {
            $response = $this->send($connection, 'GET', $this->calendarUrl($connection), ['query' => ['$select' => 'allowedOnlineMeetingProviders']]);
        } catch (CalendarSyncFailed) {
            return [];
        }
        $allowed = $response->successful() ? $response->json('allowedOnlineMeetingProviders') : null;

        return is_array($allowed) && in_array('teamsForBusiness', $allowed, true) ? ['teams'] : [];
    }

    // ── OAuth (used by OutlookCalendarConnectController) ─────────────────────────────────────

    /** The Microsoft sign-in page for this app, with state + PKCE (S256) challenge. */
    public function authorizeUrl(string $state, string $codeChallenge): string
    {
        return $this->loginBase().'/authorize?'.http_build_query([
            'client_id' => (string) config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Swap the authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     *
     * @throws CalendarSyncFailed
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $codeVerifier,
            'scope' => self::SCOPES,
        ]);

        $access = $response->json('access_token');
        $refresh = $response->json('refresh_token');
        if (! $response->successful() || ! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            Log::warning('Outlook code exchange failed', ['status' => $response->status(), 'error' => $this->errorCode($response)]);

            throw new CalendarSyncFailed('Microsoft didn’t accept the sign-in. Try connecting again.');
        }

        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => (int) ($response->json('expires_in') ?? 3600)];
    }

    /**
     * The signed-in Microsoft account (for account_email / account_name).
     *
     * @return array{email: string, name: string|null}
     */
    public function account(CalendarConnection $connection): array
    {
        $response = $this->send($connection, 'GET', self::GRAPH.'/me', ['query' => ['$select' => 'displayName,mail,userPrincipalName']]);
        $email = $response->json('mail') ?: $response->json('userPrincipalName');
        if (! $response->successful() || ! is_string($email) || $email === '') {
            $this->fail($connection, $response, 'me', 'Could not read your Microsoft account. Try connecting again.');
        }
        $name = $response->json('displayName');

        return ['email' => $email, 'name' => is_string($name) && $name !== '' ? $name : null];
    }

    // ── Contract ─────────────────────────────────────────────────────────────────────────────

    public function listCalendars(CalendarConnection $connection): array
    {
        $calendars = [];
        foreach ($this->pages($connection, self::GRAPH.'/me/calendars', [
            '$select' => 'id,name,hexColor,canEdit,isDefaultCalendar',
            '$top' => self::PAGE_SIZE,
        ], 'Could not load your Outlook calendars.') as $calendar) {
            if (! is_string($calendar['id'] ?? null) || $calendar['id'] === '') {
                continue;
            }
            $color = $calendar['hexColor'] ?? null;
            $calendars[] = [
                'id' => $calendar['id'],
                'name' => (string) (($calendar['name'] ?? '') ?: 'Calendar'),
                'color' => is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : null,
                'primary' => (bool) ($calendar['isDefaultCalendar'] ?? false),
                'read_only' => ! ($calendar['canEdit'] ?? false),
            ];
        }

        return $calendars;
    }

    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): array
    {
        $events = [];
        foreach ($this->pages($connection, $this->calendarUrl($connection).'/calendarView', [
            'startDateTime' => Carbon::instance($start)->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => Carbon::instance($end)->addDay()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$select' => 'id,subject,body,bodyPreview,location,start,end,isAllDay,isCancelled,webLink,isOnlineMeeting,onlineMeeting,onlineMeetingProvider',
            '$orderby' => 'start/dateTime',
            '$top' => self::PAGE_SIZE,
        ], 'Outlook didn’t answer. Try again later, or reconnect this calendar.') as $raw) {
            $event = $this->normalize($raw, $timezone);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function getEvent(CalendarConnection $connection, string $eventId, ?string $timezone = null): ?array
    {
        $response = $this->send($connection, 'GET', $this->eventUrl($connection, $eventId));
        // 400 = a malformed id (ErrorInvalidIdMalformed): as good as not found.
        if (in_array($response->status(), [400, 404, 410], true)) {
            return null;
        }
        if (! $response->successful()) {
            $this->fail($connection, $response, 'get', self::DOWN);
        }

        return $this->normalize((array) $response->json(), $timezone);
    }

    public function createEvent(CalendarConnection $connection, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from($data);

        $body = ['subject' => (string) ($data['title'] ?? '')] + $this->timesPayload($input);
        if (filled($data['description'] ?? null)) {
            $body['body'] = ['contentType' => 'text', 'content' => (string) $data['description']];
        }
        if (filled($data['location'] ?? null)) {
            $body['location'] = ['displayName' => (string) $data['location']];
        }
        // Online meeting + attendees (BRAI-147): Outlook sends the invitations itself.
        $attendees = $this->requestedAttendees($data);
        if ($attendees !== []) {
            $body['attendees'] = array_map(fn (array $a) => [
                'emailAddress' => array_filter(['address' => $a['email'], 'name' => $a['name']]),
                'type' => 'required',
            ], $attendees);
        }
        if ($this->requestedConference($connection, $data) === 'teams') {
            $body['isOnlineMeeting'] = true;
            $body['onlineMeetingProvider'] = 'teamsForBusiness';
        }

        $response = $this->send($connection, 'POST', $this->calendarUrl($connection).'/events', ['json' => $body], write: true);
        if (! $response->successful()) {
            $this->fail($connection, $response, 'create', 'Outlook rejected the event. Try again, or reconnect Outlook.');
        }
        $created = (array) $response->json();

        // The Teams link can lag behind the create: read the event once more.
        if (! empty($body['isOnlineMeeting']) && ! is_string($created['onlineMeeting']['joinUrl'] ?? null) && is_string($created['id'] ?? null)) {
            $again = $this->send($connection, 'GET', $this->calendarUrl($connection).'/events/'.rawurlencode($created['id']));
            if ($again->successful()) {
                $created = (array) $again->json();
            }
        }

        return $this->normalize($created, null)
            ?? throw new CalendarSyncFailed('Outlook rejected the event. Try again, or reconnect Outlook.');
    }

    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): array
    {
        $this->assertWritable($connection);
        $input = EventInput::from($data);

        $body = [];
        if ($input->has('title')) {
            $body['subject'] = (string) $input->get('title');
        }
        if ($input->has('description')) {
            $body['body'] = ['contentType' => 'text', 'content' => (string) $input->get('description')];
        }
        if ($input->has('location')) {
            $body['location'] = ['displayName' => (string) $input->get('location')];
        }
        if ($input->changesTimes()) {
            $body += $this->timesPayload($input);
        }

        $response = $this->send($connection, 'PATCH', $this->eventUrl($connection, $eventId), ['json' => (object) $body], write: true);
        if (! $response->successful()) {
            $this->fail($connection, $response, 'update', 'Outlook rejected the change. Try again, or reconnect Outlook.');
        }

        return $this->normalize((array) $response->json(), null)
            ?? throw new CalendarSyncFailed('Outlook rejected the change. Try again, or reconnect Outlook.');
    }

    public function deleteEvent(CalendarConnection $connection, string $eventId): void
    {
        $this->assertWritable($connection);
        $response = $this->send($connection, 'DELETE', $this->eventUrl($connection, $eventId), write: true);
        if ($response->successful() || in_array($response->status(), [404, 410], true)) {
            return; // deleted, or already gone
        }

        $this->fail($connection, $response, 'delete', 'Outlook refused to delete the event. Try again, or reconnect Outlook.');
    }

    // ── Mapping ──────────────────────────────────────────────────────────────────────────────

    /**
     * Graph event → normalized event. All-day `end` is exclusive in Graph (midnight of the day
     * after); the normalized end_date is inclusive. Timed events are converted to $timezone, else
     * reported in the zone Graph used (UTC with our Prefer header). Null for cancelled events or
     * events without an id / start.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    public function normalize(array $event, ?string $timezone): ?array
    {
        $id = $event['id'] ?? null;
        $start = $event['start'] ?? null;
        if (! is_string($id) || $id === '' || ! is_array($start) || ! is_string($start['dateTime'] ?? null) || ! empty($event['isCancelled'])) {
            return null;
        }
        $end = is_array($event['end'] ?? null) && is_string($event['end']['dateTime'] ?? null) ? $event['end'] : null;

        $webLink = $event['webLink'] ?? null;
        $extra = [
            'description' => $this->description($event),
            'location' => is_string($event['location']['displayName'] ?? null) && trim($event['location']['displayName']) !== '' ? trim($event['location']['displayName']) : null,
            'external_url' => is_string($webLink) && str_starts_with(strtolower($webLink), 'https://') ? $webLink : null,
            // BRAI-147: the online meeting's join link (Teams for business; others → 'other')
            'conference_url' => is_array($event['onlineMeeting'] ?? null) ? ($event['onlineMeeting']['joinUrl'] ?? null) : null,
            'conference_provider' => ($event['onlineMeetingProvider'] ?? null) === 'teamsForBusiness' ? 'teams' : 'other',
        ];
        $title = is_string($event['subject'] ?? null) ? $event['subject'] : null;

        if (! empty($event['isAllDay'])) {
            // All-day events are floating: midnight to midnight in whatever zone was asked for.
            $endInclusive = $end ? Carbon::parse(substr($end['dateTime'], 0, 10))->subDay()->toDateString() : null;

            return NormalizedEvent::allDay(self::publicId($id), $title, substr($start['dateTime'], 0, 10), $endInclusive, $extra);
        }

        $startAt = $this->instant($start);
        $endAt = $end ? $this->instant($end) : $startAt->copy();

        return NormalizedEvent::timed(self::publicId($id), $title, $startAt, $endAt, $timezone, $extra);
    }

    /**
     * Graph ids are base64-like and may contain "/", which the event routes ([^/]+) can't carry:
     * such ids travel base64url-encoded behind a "b64." prefix (Graph ids never start with it).
     */
    public static function publicId(string $graphId): string
    {
        return str_contains($graphId, '/') ? 'b64.'.rtrim(strtr(base64_encode($graphId), '+/', '-_'), '=') : $graphId;
    }

    public static function graphId(string $publicId): string
    {
        if (! str_starts_with($publicId, 'b64.')) {
            return $publicId;
        }
        $decoded = base64_decode(strtr(substr($publicId, 4), '-_', '+/'), true);

        // never "." / ".." (another path once in the URL), even when hidden in the encoding
        return is_string($decoded) && trim($decoded, '.') !== '' ? $decoded : $publicId;
    }

    /** @return array<string, mixed> start / end / isAllDay for a write */
    private function timesPayload(EventInput $input): array
    {
        $tz = $input->timezone();
        if ($input->isAllDay()) {
            return [
                'isAllDay' => true,
                'start' => ['dateTime' => $input->startDate().'T00:00:00', 'timeZone' => $tz],
                'end' => ['dateTime' => $input->endDateExclusive().'T00:00:00', 'timeZone' => $tz],
            ];
        }

        return [
            'isAllDay' => false,
            'start' => ['dateTime' => $input->start()->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
            'end' => ['dateTime' => $input->end()->format('Y-m-d\TH:i:s'), 'timeZone' => $tz],
        ];
    }

    /** @param array<string, mixed> $dateTime Graph dateTimeTimeZone (7-digit fractions, no offset) */
    private function instant(array $dateTime): Carbon
    {
        $zone = is_string($dateTime['timeZone'] ?? null) ? $dateTime['timeZone'] : 'UTC';
        if ($zone !== 'UTC' && ! in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            // A Windows zone name ("W. Europe Standard Time") when Graph ignored the Prefer header.
            $iana = class_exists(\IntlTimeZone::class) ? \IntlTimeZone::getIDForWindowsID($zone) : false;
            $zone = is_string($iana) && $iana !== '' ? $iana : 'UTC';
        }

        return Carbon::parse(substr((string) $dateTime['dateTime'], 0, 19), $zone);
    }

    /** @param array<string, mixed> $event */
    private function description(array $event): ?string
    {
        $body = $event['body'] ?? null;
        $text = null;
        if (is_array($body) && is_string($body['content'] ?? null)) {
            $text = strtolower((string) ($body['contentType'] ?? 'text')) === 'html'
                ? html_entity_decode(strip_tags($body['content']), ENT_QUOTES | ENT_HTML5)
                : $body['content'];
        }
        if (($text === null || trim($text) === '') && is_string($event['bodyPreview'] ?? null)) {
            $text = $event['bodyPreview'];
        }
        $text = $text !== null ? trim($text) : '';

        return $text !== '' ? $text : null;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────────────────────

    private function calendarUrl(CalendarConnection $connection): string
    {
        return self::GRAPH.'/me/calendars/'.rawurlencode((string) $connection->external_calendar_id);
    }

    private function eventUrl(CalendarConnection $connection, string $eventId): string
    {
        return $this->calendarUrl($connection).'/events/'.rawurlencode(self::graphId($eventId));
    }

    /**
     * The items of a Graph collection, following @odata.nextLink (only to Graph, at most
     * MAX_PAGES pages).
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(CalendarConnection $connection, string $url, array $query, string $failure): \Generator
    {
        $options = ['query' => $query];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->send($connection, 'GET', $url, $options);
            if ($response->status() === 404) {
                $this->fail($connection, $response, 'list', 'This Outlook calendar no longer exists. Disconnect it, or reconnect Outlook.');
            }
            if (! $response->successful()) {
                $this->fail($connection, $response, 'list', $failure);
            }
            foreach ((array) $response->json('value', []) as $item) {
                if (is_array($item)) {
                    yield $item;
                }
            }

            $next = ((array) $response->json())['@odata.nextLink'] ?? null; // not json('…'): the key has a dot
            if (! is_string($next) || ! str_starts_with($next, self::GRAPH.'/')) {
                return;
            }
            $url = $next; // carries the query (skip token) itself
            $options = [];
        }
    }

    /**
     * One Graph request with a fresh token: refreshes once on a 401, waits once for a short
     * Retry-After on 429 / 503, and turns auth / throttling / transport failures into
     * CalendarSyncFailed (a 403 on a write into CalendarReadOnly). Other statuses are returned.
     *
     * @param  array<string, mixed>  $options
     */
    private function send(CalendarConnection $connection, string $method, string $url, array $options = [], bool $write = false): Response
    {
        $this->ensureFreshToken($connection);
        $response = $this->attempt($connection, $method, $url, $options);

        if ($response->status() === 401 && $connection->refresh_token) {
            $this->refresh($connection);
            $response = $this->attempt($connection, $method, $url, $options);
        }

        if (in_array($response->status(), [429, 503], true)) {
            $retryAfter = $response->header('Retry-After');
            if (ctype_digit($retryAfter) && (int) $retryAfter <= 2) {
                Sleep::for(max(1, (int) $retryAfter))->seconds();
                $response = $this->attempt($connection, $method, $url, $options);
            }
        }

        if (in_array($response->status(), [429, 503], true)) {
            Log::warning('Outlook calendar throttled', ['connection_id' => $connection->id, 'status' => $response->status()]);
            $retryAfter = $response->header('Retry-After');
            $wait = ctype_digit($retryAfter) && (int) $retryAfter > 0 && (int) $retryAfter < 120 ? ((int) $retryAfter).' seconds' : 'a minute';

            throw new CalendarSyncFailed("Outlook is busy right now. Try again in {$wait}.");
        }
        if ($response->status() === 401) {
            throw new CalendarSyncFailed(self::EXPIRED);
        }
        if ($response->status() === 403) {
            if ($write) {
                throw new CalendarReadOnly;
            }
            $this->fail($connection, $response, 'read', 'Outlook refused access to this calendar. Reconnect Outlook.');
        }

        return $response;
    }

    /** @param array<string, mixed> $options */
    private function attempt(CalendarConnection $connection, string $method, string $url, array $options): Response
    {
        try {
            return Http::withToken((string) $connection->access_token)
                ->acceptJson()
                ->withHeaders(['Prefer' => 'outlook.timezone="UTC", outlook.body-content-type="text"'])
                ->connectTimeout(10)
                ->timeout(30)
                ->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new CalendarSyncFailed(self::DOWN);
        }
    }

    private function ensureFreshToken(CalendarConnection $connection): void
    {
        if ($connection->access_token && ! $connection->isExpired()) {
            return;
        }

        $this->refresh($connection);
    }

    /**
     * New access token from the refresh token. Microsoft rotates refresh tokens: the new one is
     * saved too (forceFill + save, so the encrypted casts apply). Unsaved probe connections
     * (the OAuth callback) are only updated in memory.
     */
    private function refresh(CalendarConnection $connection): void
    {
        if (! $connection->refresh_token) {
            throw new CalendarSyncFailed(self::EXPIRED);
        }

        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $connection->refresh_token,
            'scope' => self::SCOPES,
        ]);
        $access = $response->json('access_token');
        if (! $response->successful() || ! is_string($access) || $access === '') {
            Log::warning('Outlook token refresh failed', [
                'connection_id' => $connection->id,
                'status' => $response->status(),
                'error' => $this->errorCode($response),
            ]);

            // A refused refresh token (revoked, expired, password changed): the user must sign in
            // again. The connection is kept; the page shows this as its sync warning.
            throw new CalendarSyncFailed($response->serverError() ? self::DOWN : self::EXPIRED);
        }

        $refresh = $response->json('refresh_token');
        $connection->forceFill([
            'access_token' => $access,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : $connection->refresh_token,
            'token_expires_at' => now()->addSeconds(max(0, (int) ($response->json('expires_in') ?? 3600) - 60)),
        ]);
        if ($connection->exists) {
            $connection->save();
        }
    }

    /** @param array<string, string> $params */
    private function tokenRequest(array $params): Response
    {
        try {
            return Http::asForm()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(30)
                ->post($this->loginBase().'/token', $params + [
                    'client_id' => (string) config('services.microsoft.client_id'),
                    'client_secret' => (string) config('services.microsoft.client_secret'),
                ]);
        } catch (ConnectionException) {
            throw new CalendarSyncFailed(self::DOWN);
        }
    }

    private function loginBase(): string
    {
        $tenant = (string) (config('services.microsoft.tenant') ?: 'common');
        if (! preg_match('/^[A-Za-z0-9.-]{1,100}$/', $tenant)) {
            $tenant = 'common';
        }

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0";
    }

    private function redirectUri(): string
    {
        return (string) (config('services.microsoft.redirect') ?: route('calendar.connections.outlook.callback'));
    }

    /** Graph / identity error code (e.g. "invalid_grant", "ErrorItemNotFound") — never messages. */
    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code') ?? $response->json('error');

        return is_string($code) ? substr($code, 0, 80) : null;
    }

    /** Log the failure (status + error code only: no URLs, no bodies) and throw $message. */
    private function fail(CalendarConnection $connection, Response $response, string $operation, string $message): never
    {
        Log::warning('Outlook calendar request failed', [
            'connection_id' => $connection->id,
            'operation' => $operation,
            'status' => $response->status(),
            'error' => $this->errorCode($response),
        ]);

        throw new CalendarSyncFailed($message);
    }
}
