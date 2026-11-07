<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListBoardItemsRequest;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\MoveIssueRequest;
use App\Http\Resources\AppApi\BoardCollection;
use App\Http\Resources\AppApi\BoardDetailResource;
use App\Http\Resources\AppApi\BoardViewResource;
use App\Http\Resources\AppApi\IssueCollection;
use App\Http\Resources\AppApi\IssueResource;
use App\Http\Resources\AppApi\ProjectConfigResource;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardView;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Release;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\Boards\BoardCards;
use App\Support\Boards\BoardFilters;
use App\Support\Boards\BoardViewConfig;
use App\Support\Work\WorkCompletion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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

        $boards = ApiKeyAccess::forUser($request->user())?->boardIds();

        $page = Board::query()
            ->where('project_id', $project->id)
            // a personal API key limited to boards: those boards only
            ->when($boards !== null, fn ($q) => $q->whereIn('id', $boards))
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
     * Filters are the board page's (and a saved view's `params`, sent as they are): `q`, `assignee`
     * (me | unassigned | user-<id>), `status` / `labels` / `priority` / `type` (comma-separated ids), `quick`
     * (recent, due, blocked) and custom fields as `f<field id>` (none | any | yes | no | ~text | option
     * ids[,none] | min..max).
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

        $query = BoardFilters::apply(BoardCards::query($board, $request->includeOldDone()), $board, $request->filters(), $request->user());
        $page = IssueResource::eagerLoad($query)
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

    /**
     * What an issue form and the board filters offer in a project: issue types, priorities, labels, custom
     * fields (with options) and fix versions.
     *
     * Setting them up (project settings): web only.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function config(Request $request, string $project): ProjectConfigResource
    {
        $project = $this->visibleProject($request, $project);

        $project->setRelation('configTypes', ItemType::forProject($project)->orderByRaw('project_id is not null')->orderBy('id')->get());
        $project->setRelation('configPriorities', ItemPriority::forProject($project)->orderBy('order')->orderBy('id')->get());
        $project->setRelation('configLabels', $project->labels()->orderBy('name')->get());
        $project->setRelation('configFields', $project->itemFields()->with('options')->orderBy('position')->orderBy('id')->get());
        $project->setRelation('configReleases', Release::where('project_id', $project->id)
            ->whereIn('status', [Release::STATUS_UNRELEASED, Release::STATUS_RELEASED])
            ->orderByRaw("case status when 'unreleased' then 0 else 1 end")->orderByRaw('release_date is null')->orderBy('release_date')->orderByDesc('id')
            ->limit(100)->get());

        return new ProjectConfigResource($project);
    }

    /**
     * The saved views of a board the user may use: shared ones and their own, in the web's order.
     *
     * Each view's `params` are the board filters as `GET /boards/{id}/items` takes them. Creating, changing,
     * sharing views and picking the default: web only.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function views(Request $request, string $board): AnonymousResourceCollection
    {
        $board = $this->visibleBoard($request, $board);
        $user = $request->user();
        $defaultId = (int) DB::table('board_view_defaults')->where('user_id', $user->id)->where('board_id', $board->id)->value('board_view_id');

        $views = $board->views()->visibleTo($user)->with('user:id,name')->get()->each(function (BoardView $view) use ($board, $defaultId) {
            ['params' => $params, 'dropped' => $dropped] = BoardViewConfig::sanitize((array) $view->config, $board);
            $view->setAttribute('api_params', $params);
            $view->setAttribute('api_dropped', $dropped);
            $view->setAttribute('api_default', $view->id === $defaultId);
        });

        return BoardViewResource::collection($views);
    }

    /** A fresh card with what IssueResource needs. */
    public static function card(Item $item): Item
    {
        $board = $item->board;
        $fresh = IssueResource::eagerLoad(Item::query())->findOrFail($item->id);

        return $fresh->setRelation('board', $board);
    }
}
