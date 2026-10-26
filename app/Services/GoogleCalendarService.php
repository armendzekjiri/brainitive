<?php

namespace App\Services;

use App\Models\CalendarConnection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Google Calendar REST API. We use the global Http
 * facade rather than google/apiclient — the surface area we need is small
 * (list calendars, list events, create event, refresh token).
 *
 * Tokens are auto-encrypted on the model; refresh is handled in-process.
 */
class GoogleCalendarService
{
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    /** Ensure the connection's access token is valid, refreshing if needed. */
    public function ensureFreshToken(CalendarConnection $connection): bool
    {
        if (! $connection->isExpired() && $connection->access_token) {
            return true;
        }
        if (! $connection->refresh_token) {
            return false;
        }

        $resp = Http::asForm()->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $resp->successful()) {
            Log::warning('Google token refresh failed', [
                'connection_id' => $connection->id,
                'status' => $resp->status(),
                'body' => $resp->json(),
            ]);
            $connection->update(['is_active' => false]);

            return false;
        }

        $data = $resp->json();
        $connection->forceFill([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600) - 60),
        ])->save();

        return true;
    }

    /**
     * Fetch the user's calendar list. Returns an array of {id, summary, backgroundColor, primary,
     * access_role}, or null when the token is gone or Google refused.
     */
    public function listCalendars(CalendarConnection $connection): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $resp = $this->get($connection, '/users/me/calendarList');
        if (! $resp->successful()) {
            return null;
        }
        $items = $resp->json('items', []);

        return array_map(fn ($c) => [
            'id' => $c['id'],
            'summary' => $c['summary'] ?? $c['id'],
            'backgroundColor' => $c['backgroundColor'] ?? null,
            'primary' => $c['primary'] ?? false,
            'access_role' => $c['accessRole'] ?? null,
        ], $items);
    }

    /**
     * Fetch raw events from this connection's calendar between $start and $end, or null when
     * the token is gone or Google refused (GoogleCalendarProvider turns that into an error).
     */
    public function listEvents(CalendarConnection $connection, CarbonInterface $start, CarbonInterface $end): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $calId = rawurlencode($connection->external_calendar_id);
        $resp = $this->get($connection, "/calendars/{$calId}/events", [
            'timeMin' => $start->copy()->startOfDay()->toRfc3339String(),
            'timeMax' => $end->copy()->endOfDay()->toRfc3339String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);
        if (! $resp->successful()) {
            Log::warning('Google events list failed', [
                'connection_id' => $connection->id,
                'status' => $resp->status(),
            ]);

            return null;
        }

        return $resp->json('items', []);
    }

    /**
     * Create an event on this calendar.
     *
     * @param  array{title:string,date:string,time?:?string,end_date?:?string,end_time?:?string,description?:?string}  $data
     */
    public function createEvent(CalendarConnection $connection, array $data): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $calId = rawurlencode($connection->external_calendar_id);

        // All-day vs timed
        $startDate = $data['date'];
        $endDate = $data['end_date'] ?? $startDate;
        $tz = $data['timezone'] ?? config('app.timezone');
        $body = ['summary' => $data['title']];
        if (! empty($data['description'])) {
            $body['description'] = $data['description'];
        }
        if (! empty($data['location'])) {
            $body['location'] = $data['location'];
        }
        if (! empty($data['time'])) {
            $startDateTime = Carbon::parse("{$startDate} {$data['time']}", $tz);
            $endDateTime = ! empty($data['end_time'])
                ? Carbon::parse("{$endDate} {$data['end_time']}", $tz)
                : $startDateTime->copy()->addMinutes(30);
            $body['start'] = ['dateTime' => $startDateTime->toRfc3339String(), 'timeZone' => $tz];
            $body['end'] = ['dateTime' => $endDateTime->toRfc3339String(), 'timeZone' => $tz];
        } else {
            $body['start'] = ['date' => $startDate];
            $body['end'] = ['date' => Carbon::parse($endDate)->copy()->addDay()->toDateString()];
        }

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->post(self::API_BASE."/calendars/{$calId}/events", $body);

        if (! $resp->successful()) {
            Log::warning('Google event create failed', [
                'connection_id' => $connection->id,
                'status' => $resp->status(),
                'body' => $resp->json(),
            ]);

            return null;
        }

        return $resp->json();
    }

    /**
     * Patch an event on this calendar.
     *
     * Accepts a partial set of fields to change. For drag/resize: pass
     * `start_date`, `start_time` (or null for all-day), `end_date`, `end_time`.
     *
     * @param  array{start_date?:string,start_time?:?string,end_date?:string,end_time?:?string,title?:string,description?:?string}  $data
     */
    public function updateEvent(CalendarConnection $connection, string $eventId, array $data): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $calId = rawurlencode($connection->external_calendar_id);
        $body = [];
        if (isset($data['title'])) {
            $body['summary'] = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $body['description'] = $data['description'];
        }
        if (array_key_exists('location', $data)) {
            $body['location'] = $data['location'];
        }

        $startDate = $data['start_date'] ?? null;
        $startTime = $data['start_time'] ?? null;
        $endDate = $data['end_date'] ?? $startDate;
        $endTime = $data['end_time'] ?? null;
        // The time strings the user dragged to are in THEIR browser timezone, not
        // the server's APP_TIMEZONE. Parse in their TZ and round-trip Google in
        // that same TZ so the displayed time matches what they dropped.
        $tz = $data['timezone'] ?? config('app.timezone');

        if ($startDate !== null) {
            if ($startTime) {
                $startDt = Carbon::parse("{$startDate} {$startTime}", $tz);
                $endDt = $endTime
                    ? Carbon::parse("{$endDate} {$endTime}", $tz)
                    : $startDt->copy()->addMinutes(30);
                $body['start'] = ['dateTime' => $startDt->toRfc3339String(), 'timeZone' => $tz];
                $body['end'] = ['dateTime' => $endDt->toRfc3339String(), 'timeZone' => $tz];
            } else {
                $body['start'] = ['date' => $startDate];
                $body['end'] = ['date' => Carbon::parse($endDate)->copy()->addDay()->toDateString()];
            }
        }

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->patch(self::API_BASE."/calendars/{$calId}/events/".rawurlencode($eventId), $body);

        if (! $resp->successful()) {
            Log::warning('Google event update failed', [
                'connection_id' => $connection->id,
                'event_id' => $eventId,
                'status' => $resp->status(),
                'body' => $resp->json(),
            ]);

            return null;
        }

        return $resp->json();
    }

    /** Fetch a single event with full details (null when missing or on failure). */
    public function getEvent(CalendarConnection $connection, string $eventId): ?array
    {
        if (! $this->ensureFreshToken($connection)) {
            return null;
        }
        $calId = rawurlencode($connection->external_calendar_id);
        $resp = $this->get($connection, "/calendars/{$calId}/events/".rawurlencode($eventId));
        if (! $resp->successful()) {
            Log::warning('Google event fetch failed', [
                'connection_id' => $connection->id,
                'event_id' => $eventId,
                'status' => $resp->status(),
            ]);

            return null;
        }

        return $resp->json();
    }

    /** Delete an event. */
    public function deleteEvent(CalendarConnection $connection, string $eventId): bool
    {
        if (! $this->ensureFreshToken($connection)) {
            return false;
        }
        $calId = rawurlencode($connection->external_calendar_id);
        $resp = Http::withToken($connection->access_token)
            ->delete(self::API_BASE."/calendars/{$calId}/events/".rawurlencode($eventId));

        return $resp->successful() || $resp->status() === 410; // 410 = already gone
    }

    private function get(CalendarConnection $connection, string $path, array $query = []): Response
    {
        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->get(self::API_BASE.$path, $query);
    }
}
