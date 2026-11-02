<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\CalendarConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Response;

/**
 * Helpers for the MCP calendar tools. Calendar connections are personal (owned
 * by a single user), so every lookup is scoped to the authenticated user — the
 * AI can only ever touch the connecting user's own calendars (any provider, see
 * App\Support\Calendar\CalendarProviders) and Google Tasks lists.
 */
trait ResolvesCalendarConnections
{
    /** Calendar / Google Tasks writes per user and minute over all MCP write tools (Codex review, finding 17). */
    public const WRITES_PER_MINUTE = 30;

    /**
     * A tool error once the user's write budget for this minute is used up, else null (and one write is counted).
     * Writes reach other people too (invitations, Google's cancellation emails), so a runaway assistant stops here.
     */
    protected function calendarWriteLimited(Authenticatable $user): ?Response
    {
        $allowed = RateLimiter::attempt('mcp-calendar-writes:'.$user->getAuthIdentifier(), self::WRITES_PER_MINUTE, fn () => true, 60);

        return $allowed ? null : Response::error('Too many calendar changes in a minute ('.self::WRITES_PER_MINUTE.'). Wait a minute, then continue.');
    }

    /**
     * Active connections of a given kind ('calendar' or 'tasks'), or all kinds.
     *
     * @return Collection<int, CalendarConnection>
     */
    protected function connectionsFor(Authenticatable $user, ?string $kind = null): Collection
    {
        return CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderBy('external_calendar_name')
            ->get();
    }

    /**
     * Resolve one connection the user owns, of the expected kind, and switched on: a calendar the user turned off
     * (or that was switched off because its access expired) is off for the AI too (Codex review, finding 15).
     *
     * @throws AuthorizationException
     */
    protected function connectionForUser(Authenticatable $user, int $connectionId, string $kind): CalendarConnection
    {
        $connection = CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind)
            ->where('is_active', true)
            ->find($connectionId);

        if (! $connection) {
            $noun = $kind === 'tasks' ? 'Google Tasks list' : 'calendar';
            throw new AuthorizationException("No connected {$noun} found with id {$connectionId}. Call list_calendar_connections to see the available ids.");
        }

        return $connection;
    }
}
