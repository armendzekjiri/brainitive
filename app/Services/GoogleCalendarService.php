<?php

namespace App\Services;

use App\Models\CalendarConnection;
use Carbon\Carbon;
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

    /** Fetch the user's calendar list. Returns an array of {id, summary, backgroundColor, primary}. */
    public function listCalendars(CalendarConnection $connection): array
    {
        if (! $this->ensureFreshToken($connection)) {
            return [];
        }
        $resp = $this->get($connection, '/users/me/calendarList');
        $items = $resp->json('items', []);
        return array_map(fn ($c) => [
            'id' => $c['id'],
            'summary' => $c['summary'] ?? $c['id'],
            'backgroundColor' => $c['backgroundColor'] ?? null,
            'primary' => $c['primary'] ?? false,
            'access_role' => $c['accessRole'] ?? null,
        ], $items);
    }

    /** Fetch events from this connection's calendar between $start and $end. */
    public function listEvents(CalendarConnection $connection, Carbon $start, Carbon $end): array
    {
        if (! $this->ensureFreshToken($connection)) {
            return [];
        }
        $calId = rawurlencode($connection->external_calendar_id);
        $resp = $this->get($connection, "/calendars/{$calId}/events", [
            'timeMin' => $start->copy()->startOfDay()->toRfc3339String(),
            'timeMax' => $end->copy()->endOfDay()->toRfc3339String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'maxResults' => 250,
        ]);
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
        $body = ['summary' => $data['title']];
        if (! empty($data['description'])) {
            $body['description'] = $data['description'];
        }
        if (! empty($data['time'])) {
            $startDateTime = Carbon::parse("{$startDate} {$data['time']}");
            $endDateTime = ! empty($data['end_time'])
                ? Carbon::parse("{$endDate} {$data['end_time']}")
                : $startDateTime->copy()->addMinutes(30);
            $body['start'] = ['dateTime' => $startDateTime->toRfc3339String(), 'timeZone' => config('app.timezone')];
            $body['end'] = ['dateTime' => $endDateTime->toRfc3339String(), 'timeZone' => config('app.timezone')];
        } else {
            $body['start'] = ['date' => $startDate];
            $body['end'] = ['date' => Carbon::parse($endDate)->copy()->addDay()->toDateString()];
        }

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->post(self::API_BASE . "/calendars/{$calId}/events", $body);

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
        if (isset($data['title'])) $body['summary'] = $data['title'];
        if (array_key_exists('description', $data)) $body['description'] = $data['description'];

        $startDate = $data['start_date'] ?? null;
        $startTime = $data['start_time'] ?? null;
        $endDate = $data['end_date'] ?? $startDate;
        $endTime = $data['end_time'] ?? null;

        if ($startDate !== null) {
            if ($startTime) {
                $startDt = Carbon::parse("{$startDate} {$startTime}");
                $endDt = $endTime
                    ? Carbon::parse("{$endDate} {$endTime}")
                    : $startDt->copy()->addMinutes(30);
                $body['start'] = ['dateTime' => $startDt->toRfc3339String(), 'timeZone' => config('app.timezone')];
                $body['end'] = ['dateTime' => $endDt->toRfc3339String(), 'timeZone' => config('app.timezone')];
            } else {
                $body['start'] = ['date' => $startDate];
                $body['end'] = ['date' => Carbon::parse($endDate)->copy()->addDay()->toDateString()];
            }
        }

        $resp = Http::withToken($connection->access_token)
            ->acceptJson()
            ->patch(self::API_BASE . "/calendars/{$calId}/events/{$eventId}", $body);

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

    private function get(CalendarConnection $connection, string $path, array $query = []): Response
    {
        return Http::withToken($connection->access_token)
            ->acceptJson()
            ->get(self::API_BASE . $path, $query);
    }
}
