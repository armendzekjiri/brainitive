<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Whiteboard ↔ board issues: put live issue cards on a whiteboard and turn stickies into issues.
 */
class WhiteboardController extends Controller
{
    /**
     * Issues of this project for the whiteboard: a search (`q`) or a refresh of the cards on the board (`ids`).
     */
    public function issues(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('view', $page);

        $validated = $request->validate([
            'q' => 'nullable|string|max:255',
            'ids' => 'nullable|array|max:500',
            'ids.*' => 'integer',
        ]);

        $query = $this->projectItems($project);

        if (isset($validated['ids'])) {
            $query->whereIn('items.id', $validated['ids']);
        } else {
            $search = trim($validated['q'] ?? '');
            $query->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('key', 'like', "%{$search}%")))
                ->latest('updated_at')
                ->limit(20);
        }

        return response()->json(['items' => $this->cards($workspace, $project, $query->get())]);
    }

    /**
     * Create one issue per sticky title on a board (first column, backlog on Scrum boards).
     */
    public function storeIssues(Request $request, Workspace $workspace, Project $project, Page $page): JsonResponse
    {
        $this->authorize('update', $page);

        $validated = $request->validate([
            'board_id' => ['required', 'integer', Rule::exists('boards', 'id')->where('project_id', $project->id)],
            'titles' => 'required|array|min:1|max:50',
            'titles.*' => 'required|string|max:255',
        ]);

        $board = Board::findOrFail($validated['board_id']);
        $this->authorize('createItems', $board);

        $column = $board->columns()->first();
        if (! $column instanceof BoardColumn) {
            return response()->json(['message' => 'This board has no columns yet.'], 422);
        }

        $typeId = ItemType::where('is_default', true)->value('id');
        $priorityId = ItemPriority::where('is_default', true)->value('id');
        $userId = $request->user()->id;

        $items = DB::transaction(function () use ($validated, $board, $column, $project, $typeId, $priorityId, $userId) {
            $position = Item::where('column_id', $column->id)->max('position') ?? 0;
            $backlogPosition = Item::where('board_id', $board->id)->whereNull('sprint_id')->max('backlog_position') ?? 0;

            $items = collect();
            foreach ($validated['titles'] as $title) {
                $items->push($board->items()->create([
                    'title' => trim($title),
                    'project_id' => $project->id,
                    'column_id' => $column->id,
                    'item_type_id' => $typeId,
                    'priority_id' => $priorityId,
                    'key' => $project->getNextItemKey(),
                    'created_by' => $userId,
                    'reporter_id' => $userId,
                    'position' => ++$position,
                    'backlog_position' => ++$backlogPosition,
                ]));
            }

            return $items;
        });

        $created = $this->projectItems($project)->whereIn('items.id', $items->pluck('id'))->orderBy('items.id')->get();

        return response()->json(['items' => $this->cards($workspace, $project, $created)], 201);
    }

    /**
     * @return Builder<Item>
     */
    private function projectItems(Project $project): Builder
    {
        return Item::query()
            ->whereIn('board_id', Board::where('project_id', $project->id)->select('id'))
            ->with([
                'board:id,name,slug',
                'column:id,board_id,name,position,is_done_column',
                'board.columns:id,board_id,position',
                'assignee:id,name',
            ]);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{id: int, key: string, title: string, status: string|null, status_category: string, assignee: array{id: int, name: string}|null, board: string|null, url: string}>
     */
    private function cards(Workspace $workspace, Project $project, Collection $items): array
    {
        return $items->map(fn (Item $item) => [
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'status' => $item->column?->name,
            'status_category' => $this->statusCategory($item),
            'assignee' => $item->assignee?->only(['id', 'name']),
            'board' => $item->board?->name,
            'url' => route('boards.items.show', [$workspace->slug, $project->slug, $item->board->slug, $item->id]),
        ])->values()->all();
    }

    /**
     * Same rule as the board UI: done column → done, first column → to do, anything else → in progress.
     */
    private function statusCategory(Item $item): string
    {
        if ($item->column === null) {
            return 'todo';
        }
        if ($item->column->is_done_column) {
            return 'done';
        }

        return $item->board->columns->sortBy('position')->first()?->id === $item->column_id ? 'todo' : 'in_progress';
    }
}
