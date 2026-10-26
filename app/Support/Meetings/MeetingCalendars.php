<?php

namespace App\Support\Meetings;

use App\Models\CalendarConnection;
use App\Models\User;
use App\Support\Calendar\CalendarProviders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The calendars a user can schedule an online meeting in (BRAI-147): their own active, writable
 * calendars whose provider does conferencing, each with the meeting kinds it allows. Asking a
 * provider may cost a request (Google calendarList entry, Graph calendar), so the answer is
 * cached per connection: 12 h when something is allowed, 10 min when not (a failed lookup).
 */
final class MeetingCalendars
{
    /**
     * @return list<array{id: int, provider: string, provider_label: string, name: string, account_email: string, color: string|null, conference_providers: list<string>}>
     */
    public static function for(User $user): array
    {
        $rows = [];
        $connections = CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where('kind', 'calendar')
            ->where('is_active', true)
            ->orderBy('provider')->orderBy('account_email')->orderBy('external_calendar_name')
            ->get();

        foreach ($connections as $connection) {
            if (! CalendarProviders::has($connection->provider)) {
                continue;
            }
            $provider = CalendarProviders::for($connection);
            if (! $provider->isWritable($connection)) {
                continue;
            }
            $kinds = self::conferenceProviders($connection);
            if (! ($provider->capabilities()['conferencing'] ?? false) && $kinds === []) {
                continue; // a provider without video calls (CalDAV, …)
            }
            $rows[] = [
                'id' => $connection->id,
                'provider' => $connection->provider,
                'provider_label' => $provider->label(),
                'name' => (string) ($connection->external_calendar_name ?: $connection->account_email),
                'account_email' => $connection->account_email,
                'color' => $connection->external_calendar_color ?: $provider->defaultColor(),
                'conference_providers' => $kinds,
            ];
        }

        return $rows;
    }

    /** @return list<string> the meeting kinds this calendar allows (cached) */
    public static function conferenceProviders(CalendarConnection $connection): array
    {
        $key = 'meetings.conferencing.'.$connection->id.'.'.($connection->updated_at?->timestamp ?? 0);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $kinds = array_values(array_intersect(['google_meet', 'teams'], CalendarProviders::for($connection)->conferenceProviders($connection)));
        } catch (\Throwable $e) {
            // logged without the message (transport errors carry URLs); the user sees "not available"
            Log::warning('Meeting conferencing lookup failed', ['connection_id' => $connection->id, 'exception' => $e::class]);
            $kinds = [];
        }
        Cache::put($key, $kinds, $kinds === [] ? now()->addMinutes(10) : now()->addHours(12));

        return $kinds;
    }
}
