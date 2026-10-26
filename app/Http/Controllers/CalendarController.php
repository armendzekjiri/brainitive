<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskListItem;
use App\Services\GoogleTasksService;
use App\Support\Calendar\CalendarProvider;
use App\Support\Calendar\CalendarProviderException;
use App\Support\Calendar\CalendarProviders;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CalendarController extends Controller
{
    private const CALENDAR_VIEWS = ['month', 'week', 'day'];

    /** Source filter keys; 'external' = every connected calendar (any provider). */
    private const SOURCES = ['board', 'list', 'external'];

    private function accessibleProjectIds(Request $request): array
    {
        $user = $request->user();
        $workspaceIds = $user->workspaces()->pluck('workspaces.id');

        return Project::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(function ($q) use ($user) {
                $q->whereHas('workspace', function ($wq) use ($user) {
                    $wq->whereHas('users', function ($uq) use ($user) {
                        $uq->where('users.id', $user->id)->whereIn('workspace_user.role', ['owner', 'admin']);
                    });
                })
                    ->orWhereHas('users', function ($uq) use ($user) {
                        $uq->where('users.id', $user->id);
                    });
            })
            ->pluck('id')
            ->all();
    }

    private function resolveView(Request $request): string
    {
        $requestedView = $request->get('view');
        if (in_array($requestedView, self::CALENDAR_VIEWS, true)) {
            return $requestedView;
        }

        $savedView = $request->user()->calendar_view;
        if (in_array($savedView, self::CALENDAR_VIEWS, true)) {
            return $savedView;
        }

        return 'month';
    }

    /**
     * The source filter from the URL. Links saved before BRAI-140 say 'google' for what is
     * now 'external' (every connected calendar), so map it.
     *
     * @return list<string>
     */
    private function resolveSources(Request $request): array
    {
        $sources = array_map(
            fn ($s) => $s === 'google' ? 'external' : $s,
            array_filter((array) $request->get('sources', self::SOURCES), 'is_string'),
        );
        $sources = array_values(array_unique(array_intersect($sources, self::SOURCES)));

        return $sources ?: self::SOURCES;
    }

    public function index(Request $request, GoogleTasksService $tasksService): Response
    {
        $view = $this->resolveView($request);
        $date = Carbon::parse($request->get('date', now()->toDateString()));

        // Filters
        $projectFilter = array_filter(array_map('intval', (array) $request->get('projects', [])));
        $meOnly = $request->boolean('me_only');
        $showCompleted = $request->boolean('show_completed', true);
        $sourceFilter = $this->resolveSources($request);
        $connectionFilter = array_filter(array_map('intval', (array) $request->get('connections', [])));
        $userId = $request->user()->id;

        [$rangeStart, $rangeEnd] = match ($view) {
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            default => [$date->copy()->startOfMonth()->subDays(7), $date->copy()->endOfMonth()->addDays(7)],
        };

        $accessibleIds = $this->accessibleProjectIds($request);
        $projectIds = $projectFilter
            ? array_values(array_intersect($accessibleIds, $projectFilter))
            : $accessibleIds;

        $items = collect();
        if (in_array('board', $sourceFilter, true)) {
            $items = Item::query()
                ->whereIn('project_id', $projectIds)
                ->whereNull('parent_id')
                // Deleting a board soft-deletes only the board, not its items: skip those orphans.
                ->whereHas('board')
                ->when($meOnly, fn ($q) => $q->where('assigned_to', $userId))
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
        }

        $taskListItems = collect();
        if (in_array('list', $sourceFilter, true)) {
            $taskListItems = TaskListItem::query()
                ->whereHas('taskList', function ($q) use ($projectIds) {
                    $q->whereIn('project_id', $projectIds);
                })
                ->when($meOnly, fn ($q) => $q->where('assignee_id', $userId))
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
        }

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
                'project' => [
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
                ],
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
                'color' => $proj->color,
                'assignee' => $tli->assignee ? [
                    'id' => $tli->assignee->id,
                    'name' => $tli->assignee->name,
                    'avatar_display' => $tli->assignee->avatar_display ?? null,
                ] : null,
                'project' => [
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
                ],
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
            $occurrences = $this->projectRecurrence(
                $tli->due_date,
                $tli->recurrence,
                $rangeStart,
                $rangeEnd,
            );

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

        // ── External calendars (every provider) ──────────────────────────
        // Active connections, plus ones switched off because their access failed (expired Google
        // token): those keep showing their sync_error ("Reconnect …") but aren't asked for events.
        $connections = CalendarConnection::query()
            ->where('user_id', $userId)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('sync_error'))
            ->orderBy('account_email')
            ->orderBy('external_calendar_name')
            ->get();

        $activeConnections = $connections->where('is_active', true);
        if ($connectionFilter) {
            $activeConnections = $activeConnections->whereIn('id', $connectionFilter);
        }

        // Timed events are shown in the user's own zone (kept in step with the browser);
        // without one, each provider's own wall-clock (what Google showed before).
        $displayTimezone = $request->user()->timezone ? $request->user()->localTimezone() : null;

        if (in_array('external', $sourceFilter, true)) {
            foreach ($activeConnections as $conn) {
                array_push($events, ...$this->externalEvents($conn, $tasksService, $rangeStart, $rangeEnd, $displayTimezone));
            }
        }

        // Projects + boards + task lists for filter dropdown AND quick-create form.
        $accessibleProjects = Project::query()
            ->whereIn('id', $accessibleIds)
            ->with([
                'workspace:id,name,slug',
                'boards' => function ($q) {
                    $q->select('id', 'project_id', 'name', 'slug', 'position')
                        ->orderBy('position')
                        ->with(['columns' => function ($cq) {
                            $cq->select('id', 'board_id', 'position')->orderBy('position');
                        }]);
                },
                'taskLists' => function ($q) {
                    $q->select('id', 'project_id', 'name', 'slug', 'position')->orderBy('position');
                },
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'key', 'color', 'workspace_id'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'color' => $p->color,
                'workspace' => $p->workspace ? [
                    'id' => $p->workspace->id,
                    'name' => $p->workspace->name,
                    'slug' => $p->workspace->slug,
                ] : null,
                'boards' => $p->boards->map(fn ($b) => [
                    'id' => $b->id,
                    'name' => $b->name,
                    'slug' => $b->slug,
                    'first_column_id' => $b->columns->first()?->id,
                ])->values(),
                'task_lists' => $p->taskLists->map(fn ($tl) => [
                    'id' => $tl->id,
                    'name' => $tl->name,
                    'slug' => $tl->slug,
                ])->values(),
            ]);

        $projectsForFilter = $accessibleProjects->map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'color' => $p['color'],
            'workspace' => $p['workspace'] ? ['id' => $p['workspace']['id'], 'name' => $p['workspace']['name']] : null,
        ]);

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'view' => $view,
            'date' => $date->toDateString(),
            'projectsForFilter' => $projectsForFilter,
            'accessibleProjects' => $accessibleProjects,
            'connections' => $connections->map(fn ($c) => $this->connectionRow($c))->values(),
            'calendarProviders' => CalendarProviders::catalog(),
            'filters' => [
                'projects' => $projectFilter,
                'me_only' => $meOnly,
                'show_completed' => $showCompleted,
                'sources' => $sourceFilter,
                'connections' => $connectionFilter,
            ],
        ]);
    }

    public function updateView(Request $request)
    {
        $validated = $request->validate([
            'view' => 'required|in:month,week,day',
        ]);

        $request->user()->update([
            'calendar_view' => $validated['view'],
        ]);

        return response()->noContent();
    }

    /** Convert a Google Tasks task into our normalized CalendarEvent shape. */
    private function mapGoogleTask(CalendarConnection $conn, array $gt): array
    {
        $dueRaw = $gt['due'] ?? null;
        // Google Tasks `due` is RFC3339. If it ends in T00:00:00.000Z and the
        // user explicitly set "no time", we treat it as all-day. But if there's
        // a real time, we use it.
        $startDate = null;
        $startTime = null;
        if ($dueRaw) {
            $dt = Carbon::parse($dueRaw);
            $startDate = $dt->toDateString();
            // Heuristic: tasks set without a time-of-day get sent as UTC midnight
            // by our buildDueField. Anything else is a real time.
            if (! ($dt->format('H:i:s') === '00:00:00' && $dt->utcOffset() === 0)) {
                $startTime = $dt->format('H:i');
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
        ];
    }

    /**
     * One connection's events for the page. A failing provider never breaks the page: the
     * error is stored on the connection (sync_error, shown as a warning) and it shows nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function externalEvents(CalendarConnection $conn, GoogleTasksService $tasksService, Carbon $rangeStart, Carbon $rangeEnd, ?string $timezone): array
    {
        try {
            if ($conn->kind === 'tasks') {
                // Google Tasks stay Google-only (not part of the provider contract).
                $events = $conn->provider === 'google'
                    ? array_map(fn ($gt) => $this->mapGoogleTask($conn, $gt), $tasksService->listTasks($conn, $rangeStart, $rangeEnd))
                    : [];
            } else {
                $provider = CalendarProviders::for($conn);
                $readOnly = ! $provider->isWritable($conn);
                $events = array_map(
                    fn (array $event) => $this->mapExternalEvent($conn, $provider, $event, $readOnly),
                    $provider->listEvents($conn, $rangeStart, $rangeEnd, $timezone),
                );
            }
            $conn->clearSyncError();

            return $events;
        } catch (Throwable $e) {
            if (! $e instanceof CalendarProviderException) {
                Log::warning('Calendar provider failed', [
                    'connection_id' => $conn->id,
                    'provider' => $conn->provider,
                    'error' => get_class($e), // never the message: transport errors carry URLs with secrets
                ]);
            }
            $conn->recordSyncError($e instanceof CalendarProviderException
                ? $e->getMessage()
                : 'Couldn’t load this calendar. Try again later, or reconnect it.');

            return [];
        }
    }

    /**
     * A connection for the page (filter list, quick create, manage dialog). Never secrets.
     *
     * @return array<string, mixed>
     */
    private function connectionRow(CalendarConnection $c): array
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

    /**
     * A normalized provider event (NormalizedEvent) → the page's CalendarEvent shape.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function mapExternalEvent(CalendarConnection $conn, CalendarProvider $provider, array $event, bool $readOnly): array
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
        ];
    }

    /**
     * Project a task-list-item's recurrence forward into [start, end].
     * Returns a Collection of Carbon dates (always at start-of-day).
     */
    private function projectRecurrence(?Carbon $baseDate, ?string $recurrence, Carbon $rangeStart, Carbon $rangeEnd): Collection
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

    public function reschedule(Request $request)
    {
        $validated = $request->validate([
            // 'google_event' = the pre-BRAI-140 name of 'external_event' (open tabs).
            'type' => 'required|in:board_item,task_list_item,external_event,google_event',
            'id' => 'required',
            'connection_id' => 'required_if:type,external_event,google_event|nullable|integer',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'timezone' => 'nullable|string|max:64',
        ]);

        if (in_array($validated['type'], ['external_event', 'google_event'], true)) {
            $connection = CalendarConnection::where('user_id', $request->user()->id)
                ->findOrFail($validated['connection_id']);

            // Read-only provider → CalendarReadOnly → 422 / errors.calendar.
            CalendarProviders::writable($connection)->updateEvent($connection, (string) $validated['id'], [
                'start_date' => $validated['start_date'] ?? $validated['due_date'] ?? null,
                'start_time' => $validated['start_time'] ?? null,
                'end_date' => $validated['due_date'] ?? $validated['start_date'] ?? null,
                'end_time' => $validated['due_time'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
            ]);

            return back();
        }

        $projectIds = $this->accessibleProjectIds($request);

        if ($validated['type'] === 'board_item') {
            $item = Item::whereIn('project_id', $projectIds)->findOrFail($validated['id']);
            $this->authorize('update', $item);
            $item->update(array_filter([
                'due_date' => $validated['due_date'] ?? $item->due_date,
                'due_time' => $validated['due_time'] ?? $item->due_time,
                'start_date' => $validated['start_date'] ?? $item->start_date,
                'start_time' => $validated['start_time'] ?? $item->start_time,
            ], fn ($v) => $v !== null));
        } else {
            $item = TaskListItem::whereHas('taskList', function ($q) use ($projectIds) {
                $q->whereIn('project_id', $projectIds);
            })->findOrFail($validated['id']);
            $this->authorize('manageItems', $item->taskList);
            $item->update(array_filter([
                'due_date' => $validated['due_date'] ?? $item->due_date,
                'due_time' => $validated['due_time'] ?? $item->due_time,
                'start_date' => $validated['start_date'] ?? $item->start_date,
                'start_time' => $validated['start_time'] ?? $item->start_time,
            ], fn ($v) => $v !== null));
        }

        return back();
    }
}
