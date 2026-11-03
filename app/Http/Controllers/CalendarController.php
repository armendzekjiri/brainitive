<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\Calendar\CalendarEvents;
use App\Support\Calendar\CalendarProviders;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    private const CALENDAR_VIEWS = ['month', 'week', 'day'];

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
            array_filter((array) $request->get('sources', CalendarEvents::SOURCES), 'is_string'),
        );
        $sources = array_values(array_unique(array_intersect($sources, CalendarEvents::SOURCES)));

        return $sources ?: CalendarEvents::SOURCES;
    }

    public function index(Request $request): Response
    {
        $view = $this->resolveView($request);
        $date = Carbon::parse($request->get('date', now()->toDateString()));

        // Filters
        $projectFilter = array_filter(array_map('intval', (array) $request->get('projects', [])));
        $meOnly = $request->boolean('me_only');
        $showCompleted = $request->boolean('show_completed', true);
        $sourceFilter = $this->resolveSources($request);
        $connectionFilter = array_filter(array_map('intval', (array) $request->get('connections', [])));

        [$rangeStart, $rangeEnd] = match ($view) {
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            default => [$date->copy()->startOfMonth()->subDays(7), $date->copy()->endOfMonth()->addDays(7)],
        };

        $accessibleIds = $this->accessibleProjectIds($request);
        $projectIds = $projectFilter
            ? array_values(array_intersect($accessibleIds, $projectFilter))
            : $accessibleIds;

        // Active connections, plus ones switched off because their access failed (shown with their sync_error)
        $connections = CalendarEvents::connections($request->user());
        $activeConnections = $connections->where('is_active', true);
        if ($connectionFilter) {
            $activeConnections = $activeConnections->whereIn('id', $connectionFilter);
        }

        // Timed events are shown in the user's own zone (kept in step with the browser);
        // without one, each provider's own wall-clock (what Google showed before).
        $displayTimezone = $request->user()->timezone ? $request->user()->localTimezone() : null;

        // dated issues / tasks, connected calendars, bookings (shared with GET /api/v1/calendar/events)
        $events = CalendarEvents::between(
            $request->user(), $rangeStart, $rangeEnd, $projectIds, $sourceFilter, $meOnly, $showCompleted, $activeConnections, $displayTimezone,
        )['events'];

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
            'connections' => $connections->map(fn ($c) => CalendarEvents::connectionRow($c))->values(),
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
