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

class MyWorkController extends Controller
{
    private function applyBoardScope($query, string $scope, int $userId)
    {
        return $scope === 'created'
            ? $query->where('created_by', $userId)
            : $query->where('assigned_to', $userId);
    }

    private function applyListScope($query, string $scope, int $userId)
    {
        return $scope === 'created'
            ? $query->where('created_by', $userId)
            : $query->where('assignee_id', $userId);
    }

    private function applyBoardDateFilter($query, string $filter, Carbon $today)
    {
        if ($filter === 'today') {
            return $query->whereDate('due_date', $today);
        }

        if ($filter === 'overdue') {
            return $query->whereNull('completed_at')->whereDate('due_date', '<', $today);
        }

        if ($filter === 'week') {
            return $query->whereBetween('due_date', [$today, $today->copy()->endOfWeek()]);
        }

        return $query;
    }

    private function applyListDateFilter($query, string $filter, Carbon $today)
    {
        if ($filter === 'today') {
            return $query->whereDate('due_date', $today);
        }

        if ($filter === 'overdue') {
            return $query->where('status', '!=', 'done')->whereDate('due_date', '<', $today);
        }

        if ($filter === 'week') {
            return $query->whereBetween('due_date', [$today, $today->copy()->endOfWeek()]);
        }

        return $query;
    }

    private function mapBoardItem(Item $item): array
    {
        return [
            'id' => $item->id,
            'work_type' => 'board',
            'uuid' => $item->uuid,
            'key' => $item->key,
            'title' => $item->title,
            'description' => $item->description,
            'due_date' => $item->due_date?->toDateString(),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'position' => $item->position,
            'source' => [
                'type' => 'board',
                'id' => $item->board->id,
                'name' => $item->board->name,
                'slug' => $item->board->slug,
            ],
            'board' => [
                'id' => $item->board->id,
                'name' => $item->board->name,
                'slug' => $item->board->slug,
            ],
            'column' => $item->column ? [
                'id' => $item->column->id,
                'name' => $item->column->name,
                'is_done_column' => $item->column->is_done_column,
            ] : null,
            'item_type' => $item->itemType,
            'priority' => $item->priority,
            'assignee' => $item->assignee,
        ];
    }

    private function mapListItem(TaskListItem $item): array
    {
        $project = $item->taskList->project;

        return [
            'id' => $item->id,
            'work_type' => 'list',
            'uuid' => $item->uuid,
            'key' => $project->key.'-L'.$item->id,
            'title' => $item->title,
            'description' => $item->description,
            'due_date' => $item->due_date?->toDateString(),
            'completed_at' => $item->status === 'done' ? $item->updated_at?->toIso8601String() : null,
            'position' => $item->position,
            'status' => $item->status,
            'source' => [
                'type' => 'list',
                'id' => $item->taskList->id,
                'name' => $item->taskList->name,
                'slug' => $item->taskList->slug,
            ],
            'task_list' => [
                'id' => $item->taskList->id,
                'name' => $item->taskList->name,
                'slug' => $item->taskList->slug,
            ],
            'column' => [
                'id' => $item->status === 'done' ? 1 : 0,
                'name' => $item->status === 'done' ? 'Done' : 'To do',
                'is_done_column' => $item->status === 'done',
            ],
            'item_type' => null,
            'priority' => null,
            'assignee' => $item->assignee,
        ];
    }

    /**
     * Display the current user's work items, grouped by project.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $projectIds = $request->user()->accessibleProjectIds();
        $boardIds = Board::whereIn('project_id', $projectIds)->pluck('id');
        $taskListIds = TaskList::whereIn('project_id', $projectIds)->pluck('id');
        $scope = $request->string('scope')->toString() === 'created' ? 'created' : 'assigned';
        $filterInput = $request->string('filter')->toString();
        $filter = in_array($filterInput, ['today', 'overdue', 'week'], true) ? $filterInput : 'all';
        $today = Carbon::today();

        $boardQuery = Item::query()
            ->whereIn('board_id', $boardIds)
            ->whereNull('parent_id')
            ->with([
                'board:id,project_id,name,slug',
                'board.project:id,workspace_id,name,slug,key,icon,color',
                'board.project.workspace:id,name,slug',
                'column:id,name,is_done_column',
                'itemType',
                'priority',
                'assignee:id,name,email',
            ])
            ->orderByRaw('CASE WHEN completed_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('due_date')
            ->orderBy('position');

        $this->applyBoardDateFilter(
            $this->applyBoardScope($boardQuery, $scope, $user->id),
            $filter,
            $today,
        );

        $listQuery = TaskListItem::query()
            ->whereIn('task_list_id', $taskListIds)
            ->with([
                'taskList:id,project_id,name,slug',
                'taskList.project:id,workspace_id,name,slug,key,icon,color',
                'taskList.project.workspace:id,name,slug',
                'assignee:id,name,email',
            ])
            ->orderByRaw("CASE WHEN status = 'done' THEN 1 ELSE 0 END")
            ->orderBy('due_date')
            ->orderBy('position');

        $this->applyListDateFilter(
            $this->applyListScope($listQuery, $scope, $user->id),
            $filter,
            $today,
        );

        $items = $boardQuery->get()
            ->map(function (Item $item) {
                return [
                    'project_id' => $item->board->project_id,
                    'sort_completed' => $item->completed_at ? 1 : 0,
                    'sort_due' => $item->due_date?->toDateString() ?? '9999-12-31',
                    'sort_position' => $item->position,
                    'payload' => $this->mapBoardItem($item),
                    'project' => $item->board->project,
                ];
            })
            ->concat(
                $listQuery->get()->map(function (TaskListItem $item) {
                    return [
                        'project_id' => $item->taskList->project_id,
                        'sort_completed' => $item->status === 'done' ? 1 : 0,
                        'sort_due' => $item->due_date?->toDateString() ?? '9999-12-31',
                        'sort_position' => $item->position,
                        'payload' => $this->mapListItem($item),
                        'project' => $item->taskList->project,
                    ];
                })
            )
            ->sortBy([
                ['sort_completed', 'asc'],
                ['sort_due', 'asc'],
                ['sort_position', 'asc'],
            ])
            ->values();

        // Group by project for the frontend
        $grouped = $items->groupBy('project_id');

        $projectsPayload = [];
        foreach ($grouped as $projectId => $projectItems) {
            $project = $projectItems->first()['project'];
            $projectsPayload[] = [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'key' => $project->key,
                'icon' => $project->icon,
                'icon_display' => $project->icon_display,
                'color' => $project->color,
                'workspace' => $project->workspace ? [
                    'id' => $project->workspace->id,
                    'name' => $project->workspace->name,
                    'slug' => $project->workspace->slug,
                ] : null,
                'items' => $projectItems->pluck('payload')->values()->all(),
            ];
        }

        $baseBoardCountQuery = fn () => Item::query()
            ->whereIn('board_id', $boardIds)
            ->whereNull('parent_id');

        $baseListCountQuery = fn () => TaskListItem::query()
            ->whereIn('task_list_id', $taskListIds);

        $countFor = function (string $scopeName, string $filterName) use ($baseBoardCountQuery, $baseListCountQuery, $user, $today) {
            $boardCount = $this->applyBoardDateFilter(
                $this->applyBoardScope($baseBoardCountQuery(), $scopeName, $user->id),
                $filterName,
                $today,
            )->count();

            $listCount = $this->applyListDateFilter(
                $this->applyListScope($baseListCountQuery(), $scopeName, $user->id),
                $filterName,
                $today,
            )->count();

            return $boardCount + $listCount;
        };

        return Inertia::render('MyWork/Index', [
            'scope' => $scope,
            'filter' => $filter,
            'projects' => $projectsPayload,
            'counts' => [
                'all' => $countFor($scope, 'all'),
                'today' => $countFor($scope, 'today'),
                'overdue' => $countFor($scope, 'overdue'),
                'week' => $countFor($scope, 'week'),
            ],
            'scopeCounts' => [
                'assigned' => $countFor('assigned', 'all'),
                'created' => $countFor('created', 'all'),
            ],
        ]);
    }
}
