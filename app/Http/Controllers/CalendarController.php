<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
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

    public function index(Request $request): Response
    {
        $view = $request->get('view', 'month');
        $date = Carbon::parse($request->get('date', now()->toDateString()));

        [$rangeStart, $rangeEnd] = match ($view) {
            'week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            default => [$date->copy()->startOfMonth()->subDays(7), $date->copy()->endOfMonth()->addDays(7)],
        };

        $projectIds = $this->accessibleProjectIds($request);

        $items = Item::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('parent_id')
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                  ->orWhereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
            })
            ->with([
                'board:id,project_id,name,slug',
                'board.project:id,workspace_id,name,slug,key,color',
                'board.project.workspace:id,name,slug',
                'itemType',
                'priority',
                'assignee:id,name,email,avatar',
            ])
            ->get();

        $taskListItems = TaskListItem::query()
            ->whereHas('taskList', function ($q) use ($projectIds) {
                $q->whereIn('project_id', $projectIds);
            })
            ->where(function ($q) use ($rangeStart, $rangeEnd) {
                $q->whereBetween('start_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                  ->orWhereBetween('due_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
            })
            ->with([
                'taskList:id,project_id,name,slug',
                'taskList.project:id,workspace_id,name,slug,key,color',
                'taskList.project.workspace:id,name,slug',
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
                'is_completed' => $item->completed_at !== null,
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
            $events[] = [
                'id' => $tli->id,
                'type' => 'task_list_item',
                'key' => $proj->key . '-L' . $tli->id,
                'title' => $tli->title,
                'start_date' => $tli->start_date?->toDateString(),
                'start_time' => $tli->start_time,
                'due_date' => $tli->due_date?->toDateString(),
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
        }

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'view' => $view,
            'date' => $date->toDateString(),
        ]);
    }

    public function reschedule(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:board_item,task_list_item',
            'id' => 'required|integer',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
        ]);

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
