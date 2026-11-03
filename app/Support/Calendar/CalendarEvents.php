<?php

namespace App\Support\Calendar;

use App\Models\Booking;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Models\User;
use App\Services\GoogleTasksService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the calendar shows for a user in a date range, in the web page's CalendarEvent shape: dated
 * issues and list tasks of the projects they can open (repeating tasks projected into the range), the
 * events of their connected calendars (any provider) and Google Tasks, and their own bookings. Shared
 * by the calendar page (CalendarController) and the App API (GET /api/v1/calendar/events).
 *
 * Dates / times are the user's wall clock; connected-calendar and booking events carry the zone they
 * are in (`timezone`), so they can be turned into instants.
 */
final class CalendarEvents
{
    /** Source filter keys; 'external' = every connected calendar (any provider). */
    public const SOURCES = ['board', 'list', 'external', 'booking'];

    /**
     * @param  array<int, int>  $projectIds  projects whose dated work to show (already limited to what the user can open)
     * @param  list<string>  $sources  any of self::SOURCES
     * @param  Collection<int, CalendarConnection>  $connections  the user's active connections to read
     * @param  string|null  $timezone  zone for connected-calendar times (null: each provider's own wall clock)
     * @return array{events: list<array<string, mixed>>, errors: list<array{connection_id: int, message: string}>}
     */
    public static function between(
        User $user,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        array $projectIds,
        array $sources,
        bool $meOnly,
        bool $showCompleted,
        Collection $connections,
        ?string $timezone,
    ): array {
        $events = [];
        $errors = [];

        if (in_array('board', $sources, true)) {
            array_push($events, ...self::issueEvents($user, $rangeStart, $rangeEnd, $projectIds, $meOnly, $showCompleted));
        }

        if (in_array('list', $sources, true)) {
            array_push($events, ...self::taskEvents($user, $rangeStart, $rangeEnd, $projectIds, $meOnly, $showCompleted));
        }

        if (in_array('external', $sources, true)) {
            $tasksService = app(GoogleTasksService::class);
            foreach ($connections as $conn) {
                [$external, $error] = self::externalEvents($conn, $tasksService, $rangeStart, $rangeEnd, $timezone);
                array_push($events, ...$external);
                if ($error !== null) {
                    $errors[] = ['connection_id' => $conn->id, 'message' => $error];
                }
            }
        }

        if (in_array('booking', $sources, true)) {
            array_push($events, ...self::bookingEvents($user, $rangeStart, $rangeEnd, $events, $timezone ?? $user->localTimezone()));
        }

        return ['events' => $events, 'errors' => $errors];
    }

    /**
     * The user's connections the calendar lists: active ones, plus ones switched off because their
     * access failed (expired Google token) — those keep showing their sync_error ("Reconnect …").
     *
     * @return Collection<int, CalendarConnection>
     */
    public static function connections(User $user): Collection
    {
        return CalendarConnection::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('sync_error'))
            ->orderBy('account_email')
            ->orderBy('external_calendar_name')
            ->get();
    }

    /**
     * A connection for the page (filter list, quick create, manage dialog) and the App API. Never secrets.
     *
     * @return array<string, mixed>
     */
    public static function connectionRow(CalendarConnection $c): array
    {
        $provider = CalendarProviders::has($c->provider) ? CalendarProviders::for($c) : null;
        $isTasks = $c->kind === 'tasks';

        return [
            'id' => $c->id,
            'provider' => $c->provider,
            'provider_label' => $isTasks ? 'Google Tasks' : ($provider?->label() ?? $c->provider),
            'provider_badge' => $provider?->badge() ?? '?',
            'kind' => $c->kind,
            'account_email' => $c->account_email,
            'account_name' => $c->account_name,
            'external_calendar_id' => $c->external_calendar_id,
            'external_calendar_name' => $c->external_calendar_name,
            'external_calendar_color' => $c->external_calendar_color ?: $provider?->defaultColor(),
            'server_url' => $c->server_url,
            'is_active' => $c->is_active,
            'read_only' => $isTasks ? false : ! ($provider?->isWritable($c) ?? false),
            'sync_error' => $c->sync_error,
            'sync_error_at' => $c->sync_error_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function issueEvents(User $user, Carbon $rangeStart, Carbon $rangeEnd, array $projectIds, bool $meOnly, bool $showCompleted): array
    {
        $items = Item::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('parent_id')
            // Deleting a board soft-deletes only the board, not its items: skip those orphans.
            ->whereHas('board')
            ->when($meOnly, fn ($q) => $q->where('assigned_to', $user->id))
            ->when(! $showCompleted, function ($q) {
                // Hide items that are either marked completed_at OR sitting in a
                // "Done" column (some legacy items have one but not the other).
                $q->whereNull('completed_at')
                    ->whereHas('column', fn ($cq) => $cq->where('is_done_column', false));
            })
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                    ->orWhereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
            })
            ->with([
                'board:id,project_id,name,slug',
                'board.project:id,workspace_id,name,slug,key,color',
                'board.project.workspace:id,name,slug',
                'column:id,name,is_done_column',
                'itemType',
                'priority',
                'assignee:id,name,email,avatar',
            ])
            ->get();

        $events = [];
        foreach ($items as $item) {
            $proj = $item->board->project;
            $events[] = [
                'id' => $item->id,
                'type' => 'board_item',
                'key' => $item->key,
                'title' => $item->title,
                'start_date' => $item->start_date?->toDateString(),
                'start_time' => $item->start_time,
                'due_date' => $item->due_date?->toDateString(),
                'due_time' => $item->due_time,
                'is_completed' => $item->completed_at !== null
                    || ($item->column?->is_done_column ?? false),
                'color' => $proj->color,
                'assignee' => $item->assignee ? [
                    'id' => $item->assignee->id,
                    'name' => $item->assignee->name,
                    'avatar_display' => $item->assignee->avatar_display ?? null,
                ] : null,
                'project' => self::project($proj),
                'source' => [
                    'type' => 'board',
                    'id' => $item->board->id,
                    'name' => $item->board->name,
                    'slug' => $item->board->slug,
                ],
                'href_params' => [
                    $proj->workspace->slug,
                    $proj->slug,
                    $item->board->slug,
                    $item->id,
                ],
                'item_type' => $item->itemType,
                'priority' => $item->priority,
            ];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private static function taskEvents(User $user, Carbon $rangeStart, Carbon $rangeEnd, array $projectIds, bool $meOnly, bool $showCompleted): array
    {
        $taskListItems = TaskListItem::query()
            ->whereHas('taskList', function ($q) use ($projectIds) {
                $q->whereIn('project_id', $projectIds);
            })
            ->when($meOnly, fn ($q) => $q->where('assignee_id', $user->id))
            ->when(! $showCompleted, fn ($q) => $q->where('status', '!=', 'done'))
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                    ->orWhereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                    ->orWhereNotNull('recurrence'); // include recurring items for projection
            })
            ->with([
                'taskList:id,project_id,name,slug',
                'taskList.project:id,workspace_id,name,slug,key,color',
                'taskList.project.workspace:id,name,slug',
                'assignee:id,name,email,avatar',
            ])
            ->get();

        $events = [];
        foreach ($taskListItems as $tli) {
            $proj = $tli->taskList->project;
            $baseEvent = [
                'id' => $tli->id,
                'type' => 'task_list_item',
                'key' => $proj->key.'-L'.$tli->id,
                'title' => $tli->title,
                'start_time' => $tli->start_time,
                'due_time' => $tli->due_time,
                'is_completed' => $tli->status === 'done',
                'recurrence' => $tli->recurrence,
                'color' => $proj->color,
                'assignee' => $tli->assignee ? [
                    'id' => $tli->assignee->id,
                    'name' => $tli->assignee->name,
                    'avatar_display' => $tli->assignee->avatar_display ?? null,
                ] : null,
                'project' => self::project($proj),
                'source' => [
                    'type' => 'list',
                    'id' => $tli->taskList->id,
                    'name' => $tli->taskList->name,
                    'slug' => $tli->taskList->slug,
                ],
                'href_params' => [
                    $proj->workspace->slug,
                    $proj->slug,
                    $tli->taskList->slug,
                ],
                'item_type' => null,
                'priority' => null,
            ];

            // Compute occurrence dates: base due_date + projected recurrences within range
            $occurrences = self::projectRecurrence($tli->due_date, $tli->recurrence, $rangeStart, $rangeEnd);

            foreach ($occurrences as $idx => $occDate) {
                // Only the first occurrence keeps the start_date; projected ones omit it.
                $isOriginal = $idx === 0 && $occDate->equalTo($tli->due_date);
                $events[] = array_merge($baseEvent, [
                    'start_date' => $isOriginal ? $tli->start_date?->toDateString() : null,
                    'due_date' => $occDate->toDateString(),
                    'is_recurring_instance' => ! $isOriginal,
                ]);
            }

            // Items with no due_date but with start_date (rare) — still render
            if ($occurrences->isEmpty() && $tli->start_date) {
                $events[] = array_merge($baseEvent, [
                    'start_date' => $tli->start_date->toDateString(),
                    'due_date' => null,
                    'is_recurring_instance' => false,
                ]);
            }
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private static function project($proj): array
    {
        return [
            'id' => $proj->id,
            'name' => $proj->name,
            'slug' => $proj->slug,
            'key' => $proj->key,
            'color' => $proj->color,
            'workspace' => [
                'id' => $proj->workspace->id,
                'name' => $proj->workspace->name,
                'slug' => $proj->workspace->slug,
            ],
        ];
    }

    /** Convert a Google Tasks task into our normalized CalendarEvent shape. */
    private static function mapGoogleTask(CalendarConnection $conn, array $gt): array
    {
        $dueRaw = $gt['due'] ?? null;
        // Google Tasks `due` is RFC3339. If it ends in T00:00:00.000Z and the
        // user explicitly set "no time", we treat it as all-day. But if there's
        // a real time, we use it.
        $startDate = null;
        $startTime = null;
        $zone = null;
        if ($dueRaw) {
            $dt = Carbon::parse($dueRaw);
            $startDate = $dt->toDateString();
            // Heuristic: tasks set without a time-of-day get sent as UTC midnight
            // by our buildDueField. Anything else is a real time.
            if (! ($dt->format('H:i:s') === '00:00:00' && $dt->utcOffset() === 0)) {
                $startTime = $dt->format('H:i');
                $zone = $dt->getTimezone()->getName();
            }
        }

        return [
            'id' => $gt['id'],
            'type' => 'google_task',
            'key' => '',
            'title' => $gt['title'] ?? '(untitled task)',
            'start_date' => $startDate,
            'start_time' => $startTime,
            'due_date' => $startDate,
            'due_time' => $startTime,
            'is_completed' => ($gt['status'] ?? null) === 'completed',
            'color' => '#0E83DC',
            'assignee' => null,
            'project' => null,
            'source' => [
                'type' => 'external',
                'id' => $conn->id,
                'name' => $conn->external_calendar_name,
                'slug' => $conn->external_calendar_id,
            ],
            'href_params' => [],
            'item_type' => null,
            'priority' => null,
            'external_url' => $gt['selfLink'] ?? null,
            'connection_id' => $conn->id,
            'account_email' => $conn->account_email,
            'provider' => 'google',
            'provider_label' => 'Google Tasks',
            'provider_badge' => '✓',
            'read_only' => false,
            'timezone' => $zone,
        ];
    }

    /**
     * One connection's events. A failing provider never breaks the calendar: the error is stored on
     * the connection (sync_error, shown as a warning), it shows nothing and the message is returned.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private static function externalEvents(CalendarConnection $conn, GoogleTasksService $tasksService, Carbon $rangeStart, Carbon $rangeEnd, ?string $timezone): array
    {
        try {
            if ($conn->kind === 'tasks') {
                // Google Tasks stay Google-only (not part of the provider contract).
                $events = $conn->provider === 'google'
                    ? array_map(fn ($gt) => self::mapGoogleTask($conn, $gt), $tasksService->listTasks($conn, $rangeStart, $rangeEnd))
                    : [];
            } else {
                $provider = CalendarProviders::for($conn);
                $readOnly = ! $provider->isWritable($conn);
                $events = array_map(
                    fn (array $event) => self::mapExternalEvent($conn, $provider, $event, $readOnly),
                    $provider->listEvents($conn, $rangeStart, $rangeEnd, $timezone),
                );
            }
            $conn->clearSyncError();

            return [$events, null];
        } catch (Throwable $e) {
            if (! $e instanceof CalendarProviderException) {
                Log::warning('Calendar provider failed', [
                    'connection_id' => $conn->id,
                    'provider' => $conn->provider,
                    'error' => get_class($e), // never the message: transport errors carry URLs with secrets
                ]);
            }
            $message = $e instanceof CalendarProviderException
                ? $e->getMessage()
                : 'Couldn’t load this calendar. Try again later, or reconnect it.';
            $conn->recordSyncError($message);

            return [[], $message];
        }
    }

    /**
     * The user's own bookings (booking pages, BRAI-158) as read-only events. A booking whose event is
     * already shown from its connected calendar isn't listed twice.
     *
     * @param  list<array<string, mixed>>  $shown  events built so far
     * @return list<array<string, mixed>>
     */
    private static function bookingEvents(User $user, Carbon $rangeStart, Carbon $rangeEnd, array $shown, string $timezone): array
    {
        $external = [];
        foreach ($shown as $event) {
            if (($event['type'] ?? null) === 'external_event') {
                $external[($event['connection_id'] ?? '').':'.$event['id']] = true;
            }
        }

        return Booking::confirmed()
            ->where('user_id', $user->id)
            ->where('starts_at', '<', $rangeEnd->copy()->endOfDay())
            ->where('ends_at', '>', $rangeStart->copy()->startOfDay())
            ->whereHas('type.page', fn ($q) => $q->where('user_id', $user->id))
            ->with('type.page:id,uuid,title')
            ->get()
            ->reject(fn (Booking $b) => $b->external_event_id !== null && isset($external[$b->calendar_connection_id.':'.$b->external_event_id]))
            ->map(function (Booking $b) use ($timezone) {
                $start = $b->starts_at->setTimezone($timezone);
                $end = $b->ends_at->setTimezone($timezone);

                return [
                    'id' => 'booking-'.$b->uuid,
                    'type' => 'booking',
                    'key' => '',
                    'title' => $b->type->name.': '.$b->guest_name,
                    'start_date' => $start->toDateString(),
                    'start_time' => $start->format('H:i'),
                    'due_date' => $end->toDateString(),
                    'due_time' => $end->format('H:i'),
                    'is_completed' => false,
                    'color' => '#0E83DC',
                    'assignee' => null,
                    'project' => null,
                    'source' => ['type' => 'booking', 'id' => $b->type->page->id, 'name' => $b->type->page->title, 'slug' => $b->type->page->uuid],
                    'href_params' => ['bookingPage' => $b->type->page->uuid, 'tab' => 'bookings'],
                    'item_type' => null,
                    'priority' => null,
                    'read_only' => true,
                    'conference_url' => $b->conference_url,
                    'timezone' => $timezone,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A normalized provider event (NormalizedEvent) → the page's CalendarEvent shape.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function mapExternalEvent(CalendarConnection $conn, CalendarProvider $provider, array $event, bool $readOnly): array
    {
        return [
            'id' => $event['id'],
            'type' => 'external_event',
            'key' => '',
            'title' => $event['title'],
            'start_date' => $event['start_date'] ?: null,
            'start_time' => $event['start_time'] ?: null,
            'due_date' => ($event['end_date'] ?? null) ?: ($event['start_date'] ?: null),
            'due_time' => $event['end_time'] ?: null,
            'is_completed' => false,
            'color' => $conn->external_calendar_color ?: $provider->defaultColor(),
            'assignee' => null,
            'project' => null,
            'source' => [
                'type' => 'external',
                'id' => $conn->id,
                'name' => $conn->external_calendar_name,
                'slug' => $conn->external_calendar_id,
            ],
            'href_params' => [],
            'item_type' => null,
            'priority' => null,
            'external_url' => $event['external_url'] ?? null,
            'connection_id' => $conn->id,
            'account_email' => $conn->account_email,
            'provider' => $conn->provider,
            'provider_label' => $provider->label(),
            'provider_badge' => $provider->badge(),
            'read_only' => $readOnly,
            'all_day' => (bool) $event['all_day'],
            'location' => $event['location'] ?? null,
            // the event's video call, when it has one (BRAI-147; optional normalized keys)
            'conference_url' => $event['conference_url'] ?? null,
            'conference_provider' => $event['conference_provider'] ?? null,
            // the zone of the wall-clock times (timed events), so an API can turn them into instants
            'timezone' => $event['timezone'] ?? null,
        ];
    }

    /**
     * Project a task-list-item's recurrence forward into [start, end].
     * Returns a Collection of Carbon dates (always at start-of-day).
     */
    private static function projectRecurrence(?Carbon $baseDate, ?string $recurrence, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        $dates = collect();
        if (! $baseDate) {
            return $dates;
        }

        $base = $baseDate->copy()->startOfDay();

        // No recurrence — just the base date if in range
        if (! $recurrence) {
            if ($base->betweenIncluded($rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay())) {
                $dates->push($base);
            }

            return $dates;
        }

        // Recurring — step backward to find the first occurrence in or before the range,
        // then step forward through the range.
        $step = function (Carbon $d, int $dir) use ($recurrence): Carbon {
            return match ($recurrence) {
                'daily' => $d->copy()->addDays($dir),
                'weekly' => $d->copy()->addWeeks($dir),
                'monthly' => $d->copy()->addMonths($dir),
                'yearly' => $d->copy()->addYears($dir),
                default => $d->copy(),
            };
        };

        $cursor = $base->copy();
        // Walk forward until we're at or past rangeStart
        while ($cursor->lt($rangeStart)) {
            $cursor = $step($cursor, 1);
            // Safety: avoid infinite loop
            if ($cursor->year - $rangeEnd->year > 1) {
                return $dates;
            }
        }
        // Walk backward if we overshot
        while ($step($cursor, -1)->gte($rangeStart)) {
            $cursor = $step($cursor, -1);
        }

        // Emit all occurrences in range
        $safety = 0;
        while ($cursor->lte($rangeEnd) && $safety < 500) {
            $dates->push($cursor->copy());
            $cursor = $step($cursor, 1);
            $safety++;
        }

        return $dates;
    }
}
