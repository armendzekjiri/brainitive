<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\CalendarConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Helpers for the MCP calendar tools. Calendar connections are personal (owned
 * by a single user), so every lookup is scoped to the authenticated user — the
 * AI can only ever touch the connecting user's own Google calendars/tasks.
 */
trait ResolvesCalendarConnections
{
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
     * Resolve one connection the user owns, of the expected kind.
     *
     * @throws AuthorizationException
     */
    protected function connectionForUser(Authenticatable $user, int $connectionId, string $kind): CalendarConnection
    {
        $connection = CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where('kind', $kind)
            ->find($connectionId);

        if (! $connection) {
            $noun = $kind === 'tasks' ? 'Google Tasks list' : 'Google calendar';
            throw new AuthorizationException("No connected {$noun} found with id {$connectionId}. Call list_calendar_connections to see the available ids.");
        }

        return $connection;
    }
}
