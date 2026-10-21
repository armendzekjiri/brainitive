<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Board issues as other modules see them (whiteboard cards, plan rows): one query, one
 * summary shape and one way to create issues in bulk.
 */
class ProjectIssues
{
    /**
     * Items on any board of the project, with what a summary needs.
     *
     * @return Builder<Item>
     */
    public static function query(Project $project): Builder
    {
        return Item::query()
            ->whereIn('board_id', Board::where('project_id', $project->id)->select('id'))
            ->with([
                'board:id,name,slug',
                'column:id,board_id,name,position,is_done_column',
                'board.columns:id,board_id,position',
                'assignee:id,name',
            ])
            ->withCount([
                'children',
                'children as done_children_count' => fn (Builder $q) => $q->whereHas('column', fn (Builder $c) => $c->where('is_done_column', true)),
            ]);
    }

    /**
     * Search by key or title (newest first), or fetch given ids.
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, Item>
     */
    public static function find(Project $project, ?string $search, ?array $ids, int $limit = 20): Collection
    {
        $query = self::query($project);

        if ($ids !== null) {
            return $query->whereIn('items.id', $ids)->get();
        }

        $search = trim($search ?? '');

        return $query
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('key', 'like', "%{$search}%")))
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array{id: int, key: string, title: string, status: string|null, status_category: string, assignee: array{id: int, name: string}|null, board: string|null, url: string, start_date: string|null, due_date: string|null, progress: int|null}>
     */
    public static function summaries(Workspace $workspace, Project $project, Collection $items): array
    {
        return $items->map(fn (Item $item) => [
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'status' => $item->column?->name,
            'status_category' => self::statusCategory($item),
            'assignee' => $item->assignee?->only(['id', 'name']),
            'board' => $item->board?->name,
            'url' => route('boards.items.show', [$workspace->slug, $project->slug, $item->board->slug, $item->id]),
            'start_date' => $item->start_date?->toDateString(),
            'due_date' => $item->due_date?->toDateString(),
            'progress' => self::progress($item),
        ])->values()->all();
    }

    /**
     * Same rule as the board UI: done column → done, first column → to do, anything else → in progress.
     */
    public static function statusCategory(Item $item): string
    {
        if ($item->column === null) {
            return 'todo';
        }
        if ($item->column->is_done_column) {
            return 'done';
        }

        return $item->board->columns->sortBy('position')->first()?->id === $item->column_id ? 'todo' : 'in_progress';
    }

    /**
     * Done → 100; otherwise the share of done subtasks, else logged time vs. estimate; null when unknown.
     */
    public static function progress(Item $item): ?int
    {
        if (self::statusCategory($item) === 'done') {
            return 100;
        }
        if (($item->children_count ?? 0) > 0) {
            return (int) round(($item->done_children_count ?? 0) / $item->children_count * 100);
        }
        if ($item->original_estimate > 0) {
            return (int) min(99, round(($item->time_spent ?? 0) / $item->original_estimate * 100));
        }

        return null;
    }

    /**
     * Create issues in the board's first column (and the backlog on Scrum boards).
     *
     * @param  list<array{title: string, start_date?: string|null, due_date?: string|null, assigned_to?: int|null}>  $issues
     * @return Collection<int, Item> in the given order
     */
    public static function create(Board $board, Project $project, User $user, array $issues): Collection
    {
        $column = $board->columns()->first();
        if (! $column instanceof BoardColumn) {
            throw new \DomainException('This board has no columns yet.');
        }

        $typeId = ItemType::where('is_default', true)->value('id');
        $priorityId = ItemPriority::where('is_default', true)->value('id');

        $ids = DB::transaction(function () use ($issues, $board, $column, $project, $typeId, $priorityId, $user) {
            $position = Item::where('column_id', $column->id)->max('position') ?? 0;
            $backlogPosition = Item::where('board_id', $board->id)->whereNull('sprint_id')->max('backlog_position') ?? 0;

            $ids = [];
            foreach ($issues as $issue) {
                $ids[] = $board->items()->create([
                    'title' => trim($issue['title']),
                    'project_id' => $project->id,
                    'column_id' => $column->id,
                    'item_type_id' => $typeId,
                    'priority_id' => $priorityId,
                    'key' => $project->getNextItemKey(),
                    'start_date' => $issue['start_date'] ?? null,
                    'due_date' => $issue['due_date'] ?? null,
                    'assigned_to' => $issue['assigned_to'] ?? null,
                    'created_by' => $user->id,
                    'reporter_id' => $user->id,
                    'position' => ++$position,
                    'backlog_position' => ++$backlogPosition,
                ])->id;
            }

            return $ids;
        });

        return self::query($project)->whereIn('items.id', $ids)->orderBy('items.id')->get();
    }
}
