<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    private function accessibleProjectIds(Request $request): array
    {
        $user = $request->user();
        if ($user->isInstanceAdmin()) {
            return Project::pluck('id')->all();
        }

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

    public function index(Request $request, GoogleCalendarService $googleService): Response
    {
        $view = $request->get('view', 'month');
        $date = Carbon::parse($request->get('date', now()->toDateString()));

        // Filters
        $projectFilter = array_filter(array_map('intval', (array) $request->get('projects', [])));
        $meOnly = $request->boolean('me_only');
        $showCompleted = $request->boolean('show_completed', true);
        $sourceFilter = (array) $request->get('sources', ['board', 'list', 'google']);
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
                'key' => $proj->key . '-L' . $tli->id,
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

        // ── External calendars (Google) ─────────────────────────────────
        $connections = CalendarConnection::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('account_email')
            ->orderBy('external_calendar_name')
            ->get();

        $activeConnections = $connectionFilter
            ? $connections->whereIn('id', $connectionFilter)
            : $connections;

        if (in_array('google', $sourceFilter, true)) {
            foreach ($activeConnections->where('provider', 'google') as $conn) {
                $googleEvents = $googleService->listEvents($conn, $rangeStart, $rangeEnd);
                foreach ($googleEvents as $ge) {
                    $events[] = $this->mapGoogleEvent($conn, $ge);
                }
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
            'connections' => $connections->map(fn ($c) => [
                'id' => $c->id,
                'provider' => $c->provider,
                'account_email' => $c->account_email,
                'account_name' => $c->account_name,
                'external_calendar_id' => $c->external_calendar_id,
                'external_calendar_name' => $c->external_calendar_name,
                'external_calendar_color' => $c->external_calendar_color,
                'is_active' => $c->is_active,
            ]),
            'filters' => [
                'projects' => $projectFilter,
                'me_only' => $meOnly,
                'show_completed' => $showCompleted,
                'sources' => $sourceFilter,
                'connections' => $connectionFilter,
            ],
        ]);
    }

    /** Convert a Google Calendar API event into our normalized CalendarEvent shape. */
    private function mapGoogleEvent(CalendarConnection $conn, array $ge): array
    {
        $allDay = isset($ge['start']['date']);
        $startDate = $allDay ? $ge['start']['date'] : substr($ge['start']['dateTime'] ?? '', 0, 10);
        $startTime = $allDay ? null : substr($ge['start']['dateTime'] ?? '', 11, 5);
        $endRaw = $ge['end']['date'] ?? $ge['end']['dateTime'] ?? null;
        $endDate = $allDay
            ? ($endRaw ? Carbon::parse($endRaw)->subDay()->toDateString() : $startDate)
            : substr($endRaw ?? '', 0, 10);
        $endTime = $allDay ? null : substr($endRaw ?? '', 11, 5);

        $color = $conn->external_calendar_color ?: '#4285F4';

        return [
            'id' => $ge['id'],
            'type' => 'google_event',
            'key' => '',
            'title' => $ge['summary'] ?? '(untitled)',
            'start_date' => $startDate ?: null,
            'start_time' => $startTime ?: null,
            'due_date' => $endDate ?: $startDate,
            'due_time' => $endTime ?: null,
            'is_completed' => false,
            'color' => $color,
            'assignee' => null,
            'project' => null,
            'source' => [
                'type' => 'google',
                'id' => $conn->id,
                'name' => $conn->external_calendar_name,
                'slug' => $conn->external_calendar_id,
            ],
            'href_params' => [],
            'item_type' => null,
            'priority' => null,
            'external_url' => $ge['htmlLink'] ?? null,
            'connection_id' => $conn->id,
            'account_email' => $conn->account_email,
        ];
    }

    /**
     * Project a task-list-item's recurrence forward into [start, end].
     * Returns a Collection of Carbon dates (always at start-of-day).
     */
    private function projectRecurrence(?Carbon $baseDate, ?string $recurrence, Carbon $rangeStart, Carbon $rangeEnd): \Illuminate\Support\Collection
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

    public function reschedule(Request $request, GoogleCalendarService $googleService)
    {
        $validated = $request->validate([
            'type' => 'required|in:board_item,task_list_item,google_event',
            'id' => 'required',
            'connection_id' => 'required_if:type,google_event|nullable|integer|exists:calendar_connections,id',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
        ]);

        if ($validated['type'] === 'google_event') {
            $connection = CalendarConnection::where('user_id', $request->user()->id)
                ->findOrFail($validated['connection_id']);

            $googleService->updateEvent($connection, (string) $validated['id'], [
                'start_date' => $validated['start_date'] ?? $validated['due_date'] ?? null,
                'start_time' => $validated['start_time'] ?? null,
                'end_date' => $validated['due_date'] ?? $validated['start_date'] ?? null,
                'end_time' => $validated['due_time'] ?? null,
            ]);

            return back();
        }

        $projectIds = $this->accessibleProjectIds($request);

        if ($validated['type'] === 'board_item') {
            $item = Item::whereIn('project_id', $projectIds)->findOrFail($validated['id']);
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
