<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MyWorkController extends Controller
{
    /**
     * Get project IDs the current user can access (workspace member + project role or workspace admin).
     */
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

    /**
     * Display the current user's assigned items (My Work), grouped by project.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $projectIds = $this->accessibleProjectIds($request);
        $boardIds = Board::whereIn('project_id', $projectIds)->pluck('id');

        $query = Item::query()
            ->where('assigned_to', $user->id)
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

        $filter = $request->string('filter')->toString();
        $today = Carbon::today();

        if ($filter === 'today') {
            $query->whereDate('due_date', $today);
        } elseif ($filter === 'overdue') {
            $query->whereNull('completed_at')->whereDate('due_date', '<', $today);
        } elseif ($filter === 'week') {
            $query->whereBetween('due_date', [$today, $today->copy()->endOfWeek()]);
        }
        // 'all' or any other value: no extra filter

        $items = $query->get();

        // Group by project for the frontend
        $grouped = $items->groupBy(function (Item $item) {
            return $item->board->project_id;
        });

        $projectsPayload = [];
        foreach ($grouped as $projectId => $projectItems) {
            $project = $projectItems->first()->board->project;
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
                'items' => $projectItems->map(function (Item $item) {
                    return [
                        'id' => $item->id,
                        'uuid' => $item->uuid,
                        'key' => $item->key,
                        'title' => $item->title,
                        'description' => $item->description,
                        'due_date' => $item->due_date?->toDateString(),
                        'completed_at' => $item->completed_at?->toIso8601String(),
                        'position' => $item->position,
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
                })->values()->all(),
            ];
        }

        return Inertia::render('MyWork/Index', [
            'filter' => $filter ?: 'all',
            'projects' => $projectsPayload,
            'counts' => [
                'all' => Item::where('assigned_to', $user->id)->whereIn('board_id', $boardIds)->whereNull('parent_id')->count(),
                'today' => Item::where('assigned_to', $user->id)->whereIn('board_id', $boardIds)->whereNull('parent_id')->whereDate('due_date', $today)->count(),
                'overdue' => Item::where('assigned_to', $user->id)->whereIn('board_id', $boardIds)->whereNull('parent_id')->whereNull('completed_at')->whereDate('due_date', '<', $today)->count(),
                'week' => Item::where('assigned_to', $user->id)->whereIn('board_id', $boardIds)->whereNull('parent_id')->whereBetween('due_date', [$today, $today->copy()->endOfWeek()])->count(),
            ],
        ]);
    }
}
