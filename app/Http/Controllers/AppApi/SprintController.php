<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\ListSprintsRequest;
use App\Http\Requests\AppApi\MoveToSprintRequest;
use App\Http\Resources\AppApi\BurndownResource;
use App\Http\Resources\AppApi\IssueCollection;
use App\Http\Resources\AppApi\IssueResource;
use App\Http\Resources\AppApi\SprintCollection;
use App\Http\Resources\AppApi\SprintMoveResource;
use App\Http\Resources\AppApi\SprintResource;
use App\Http\Resources\AppApi\VelocityResource;
use App\Models\Board;
use App\Models\Item;
use App\Models\Sprint;
use App\Support\Boards\SprintLifecycle;
use App\Support\Boards\SprintReport;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use Dedoc\Scramble\Attributes\Group;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — Scrum: sprints, backlog, moving issues between them, burndown and velocity (BRAI-408). The
 * backlog page's rules (everybody who can open the board reads; BoardPolicy::update plans) and code
 * (SprintLifecycle::moveIssues, shared with the MCP; SprintReport). Creating, starting, completing sprints
 * and ranking the backlog: web only.
 */
#[Group('Sprints', weight: 220)]
class SprintController extends Controller
{
    use ResolvesVisible;

    /**
     * The sprints of a board, in planning order.
     *
     * `status` = comma-separated `active`, `planned`, `completed` (default `active,planned`: what the backlog page
     * shows). A Kanban board has none.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function index(ListSprintsRequest $request, string $board): SprintCollection
    {
        $board = $this->visibleBoard($request, $board);
        $since = $request->updatedSince();

        $page = self::withCounts(Sprint::query()->where('board_id', $board->id)->whereIn('status', $request->statuses()))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Sprint $sprint) => $sprint->setRelation('board', $board));

        return new SprintCollection($page);
    }

    /**
     * One sprint with its issue counts and story points.
     *
     * @throws NotFoundHttpException the sprint doesn't exist or the user can't open its board (404)
     */
    public function show(Request $request, string $sprint): SprintResource
    {
        $sprint = $this->visibleSprint($request, $sprint);
        $board = $sprint->board;
        $loaded = self::withCounts(Sprint::query())->findOrFail($sprint->id);

        return new SprintResource($loaded->setRelation('board', $board));
    }

    /**
     * The issues of a sprint, in backlog order (top-level issues; their subtasks are in the sprint too).
     *
     * @throws NotFoundHttpException the sprint doesn't exist or the user can't open its board (404)
     */
    public function items(ListRequest $request, string $sprint): IssueCollection
    {
        $sprint = $this->visibleSprint($request, $sprint);

        return $this->issues($request, $sprint->board, $sprint->id);
    }

    /**
     * The backlog of a Scrum board: top-level issues in no sprint, in backlog order.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function backlog(ListRequest $request, string $board): IssueCollection
    {
        return $this->issues($request, $this->visibleBoard($request, $board), null);
    }

    /**
     * Move issues into a planned or the active sprint (at the end); subtasks follow their parent.
     *
     * Needs the right to plan the board (`can.manage_sprints`, 403). Ids that aren't top-level issues of the
     * board or a completed sprint → 422 and nothing moves. `request_id` makes a retry answer the first result.
     *
     * @throws NotFoundHttpException the sprint doesn't exist or the user can't open its board (404)
     */
    public function moveToSprint(MoveToSprintRequest $request): SprintMoveResource
    {
        return $this->move($request, $request->board(), $request->sprint());
    }

    /**
     * Move issues to the backlog (out of their sprint); subtasks follow their parent.
     *
     * Same rules as moving into a sprint.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function moveToBacklog(MoveToSprintRequest $request): SprintMoveResource
    {
        return $this->move($request, $request->board(), null);
    }

    /**
     * A sprint's burndown: remaining story points (or issues) per day and the ideal line.
     *
     * @throws NotFoundHttpException the sprint doesn't exist or the user can't open its board (404)
     */
    public function burndown(Request $request, string $sprint): BurndownResource
    {
        $sprint = $this->visibleSprint($request, $sprint);

        return new BurndownResource(['sprint_id' => $sprint->id] + SprintReport::burndown($sprint));
    }

    /**
     * Velocity: committed vs. completed of the board's last completed sprints, oldest first (not paginated).
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     */
    public function velocity(Request $request, string $board): AnonymousResourceCollection
    {
        return VelocityResource::collection(SprintReport::velocity($this->visibleBoard($request, $board)));
    }

    private function issues(ListRequest $request, Board $board, ?int $sprintId): IssueCollection
    {
        $since = $request->updatedSince();
        $page = IssueResource::eagerLoad(Item::query()->where('board_id', $board->id)->whereNull('parent_id'))
            ->where('sprint_id', $sprintId)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('backlog_position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Item $item) => $item->setRelation('board', $board));

        return new IssueCollection($page);
    }

    private function move(MoveToSprintRequest $request, Board $board, ?Sprint $sprint): SprintMoveResource
    {
        if (! $board->isScrum()) {
            throw ValidationException::withMessages(['item_ids' => 'This is not a Scrum board.']);
        }
        $ids = $request->itemIds();
        $write = fn () => ['moved' => SprintLifecycle::moveIssues($board, $sprint, $ids)];

        try {
            $requestId = $request->validated('request_id');
            $result = is_string($requestId)
                ? IdempotentRequest::run($request->user(), 'app-api:sprints.move', $requestId, ['board' => $board->id, 'sprint' => $sprint?->id, 'items' => $ids], $write)['result']
                : $write();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['item_ids' => $e->getMessage()]);
        } catch (RequestIdConflict $e) {
            abort(409, $e->getMessage());
        }

        return new SprintMoveResource(['moved' => (int) $result['moved'], 'sprint_id' => $sprint?->id]);
    }

    /**
     * @param  Builder<Sprint>  $query
     * @return Builder<Sprint>
     */
    private static function withCounts(Builder $query): Builder
    {
        $top = fn (Builder $q) => $q->whereNull('parent_id');

        return $query
            ->withCount(['items as issues_count' => $top, 'items as done_issues_count' => fn (Builder $q) => $q->whereNull('parent_id')->whereNotNull('completed_at')])
            ->withSum(['items as points_total' => $top], 'story_points')
            ->withSum(['items as points_done' => fn (Builder $q) => $q->whereNull('parent_id')->whereNotNull('completed_at')], 'story_points');
    }
}
