<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListBoardItemsRequest;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\MoveIssueRequest;
use App\Http\Resources\AppApi\BoardCollection;
use App\Http\Resources\AppApi\BoardDetailResource;
use App\Http\Resources\AppApi\IssueCollection;
use App\Http\Resources\AppApi\IssueResource;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Support\Boards\BoardCards;
use App\Support\Work\WorkCompletion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — boards and their columns, read + move cards (BRAI-403). The web's rules (BoardPolicy::view:
 * everybody who can open the project reads; ItemPolicy::update: project admins / editors and workspace admins
 * move cards) and code (WorkCompletion::moveIssue, so watchers hear about it and open boards update live).
 * Boards and issues are addressed by id. Creating / editing / reordering columns: web only.
 */
#[Group('Boards', weight: 200)]
class BoardController extends Controller
{
    use ResolvesVisible;

    /**
     * The boards of a project, in the web's order.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function index(ListRequest $request, string $project): BoardCollection
    {
        $project = $this->visibleProject($request, $project);
        $since = $request->updatedSince();

        $page = Board::query()
            ->where('project_id', $project->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Board $board) => $board->setRelation('project', $project));

        return new BoardCollection($page);
    }

    /**
     * One board: columns (WIP limits, card counts), swimlanes, done retention, the running sprint, what the
     * user may do.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function show(Request $request, string $board): BoardDetailResource
    {
        $board = $this->visibleBoard($request, $board);
        $counts = BoardCards::query($board)
            ->toBase()
            ->selectRaw('items.column_id, count(*) as aggregate')
            ->groupBy('items.column_id')
            ->pluck('aggregate', 'column_id');

        $board->load(['columns', 'activeSprint']);
        $board->columns->each(fn (BoardColumn $column) => $column->setAttribute('cards_count', (int) ($counts[$column->id] ?? 0)));

        return new BoardDetailResource($board);
    }

    /**
     * The cards of a board, column by column.
     *
     * What the board page shows: subtasks included; a Scrum board only the running sprint (none running →
     * no cards; the backlog and other sprints are under Sprints); a Kanban board leaves out done cards
     * finished before its `done_retention_days` unless `include_old_done=1`. Sorted by `column_id`, then the
     * order inside the column (`position`): place them with `column_id` and the board's column order.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function items(ListBoardItemsRequest $request, string $board): IssueCollection
    {
        $board = $this->visibleBoard($request, $board);
        $column = $request->column();
        if ($column !== null && ! $board->columns()->whereKey($column)->exists()) {
            throw ValidationException::withMessages(['column' => ['That column is not on this board.']]);
        }
        $since = $request->updatedSince();

        $page = IssueResource::eagerLoad(BoardCards::query($board, $request->includeOldDone()))
            ->when($column, fn ($q) => $q->where('column_id', $column))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('column_id')
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Item $item) => $item->setRelation('board', $board));

        return new IssueCollection($page);
    }

    /**
     * Move a card to a column of its board (drag & drop); answers the card.
     *
     * Moving into a done column completes it (with `with_subtasks: true` also its open subtasks); out of one
     * reopens it. Watchers hear about the new status. Needs the right to edit cards (403).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function move(MoveIssueRequest $request): IssueResource
    {
        $item = $request->issue();
        $column = BoardColumn::findOrFail((int) $request->validated('column_id'));

        WorkCompletion::moveIssue($item, $column, (int) ($request->validated('position') ?? 0), $request->user(), $request->boolean('with_subtasks'));

        return new IssueResource(self::card($item));
    }

    /** A fresh card with what IssueResource needs. */
    public static function card(Item $item): Item
    {
        $board = $item->board;
        $fresh = IssueResource::eagerLoad(Item::query())->findOrFail($item->id);

        return $fresh->setRelation('board', $board);
    }
}
