<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\IssueByKeyRequest;
use App\Http\Requests\AppApi\ListDatedIssuesRequest;
use App\Http\Requests\AppApi\StoreIssueRequest;
use App\Http\Requests\AppApi\UpdateIssueRequest;
use App\Http\Resources\AppApi\IssueCollection;
use App\Http\Resources\AppApi\IssueDetailResource;
use App\Http\Resources\AppApi\IssueResource;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Support\Boards\Issues;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Carbon\CarbonImmutable;
use Closure;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — issues: create, view, edit, assign, delete (BRAI-404). The issue dialog's rules and code
 * (Support\Boards\Issues, shared with BoardController), its policies (BoardPolicy::createItems,
 * ItemPolicy::update / delete) and side effects (assignment + mention notifications, history, live boards).
 * Moving between columns: `POST /items/{id}/move` (Boards). Issues are addressed by id or key.
 */
#[Group('Issues', weight: 210)]
class IssueController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * Create an issue on a board.
     *
     * The issue dialog's fields; `description` is Markdown (mentions `@[Name](user:<id>)` notify that
     * project member). Without `column_id` it goes into the board's first column; a Scrum board puts it
     * into the running sprint unless `sprint_id` says where (`null` = backlog). Needs `can.create_items`
     * of the board (403). With `request_id` a retry answers 200 with the issue the first call created.
     *
     * @throws NotFoundHttpException the board doesn't exist or the user can't open it (404)
     * @throws LimitReached the workspace is at its storage limit (402 limit_reached)
     */
    public function store(StoreIssueRequest $request): JsonResponse
    {
        $board = $request->board();
        app(LimitGate::class)->ensureContentRoom($board->project->workspace);
        $data = $request->issueData();

        [$item, $replayed] = $this->createOnce($request, 'items.store', Item::class, ['board' => $board->id] + $request->safe()->except(['request_id']),
            fn () => $this->fieldErrors(fn () => Issues::create($board, $request->user(), $data)));

        $item = self::detail($item);
        if ($replayed) {
            return (new IssueDetailResource($item))->response();
        }

        return (new IssueDetailResource($item))->response()->setStatusCode(201);
    }

    /**
     * One issue with its description, fields, development and what the user may do.
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function show(Request $request, string $item): IssueDetailResource
    {
        return new IssueDetailResource(self::detail($this->visibleItem($request, $item)));
    }

    /**
     * One issue by its key (`WEB-12`), e.g. from a link or a scanned reference.
     *
     * Keys are unique per workspace: when the user can open the key in several workspaces, pass
     * `workspace` (its slug), otherwise 409.
     *
     * @throws NotFoundHttpException no issue with that key the user can open (404)
     * @throws ConflictHttpException the key exists in several of the user's workspaces (409)
     */
    public function byKey(IssueByKeyRequest $request, string $key): IssueDetailResource
    {
        $key = Str::upper($key);
        $projects = Project::query()->whereIn('id', $request->user()->accessibleProjectIds())
            ->when($request->workspaceSlug(), fn ($q, $slug) => $q->whereHas('workspace', fn ($w) => $w->where('slug', $slug)))
            ->select('id');
        $items = Item::query()
            ->where('key', $key)
            ->whereIn('board_id', Board::whereIn('project_id', $projects)->select('id'))
            ->limit(2)
            ->get();

        abort_if($items->isEmpty(), 404);
        abort_if($items->count() > 1, 409, 'This key exists in several of your workspaces: pass ?workspace=<slug>.');

        return new IssueDetailResource(self::detail($items->first()));
    }

    /**
     * Change an issue; only the sent fields change (`null` clears).
     *
     * `description` is Markdown; `label_ids` is the whole list; `fields` sets custom field values
     * (`{"<field id>": value | null}`); `original_estimate` is a duration ("1h 30m", "2d"). Send the
     * `updated_at` you last read to get 409 instead of overwriting someone else's change. Needs
     * `can.update` (403).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     * @throws ConflictHttpException the issue changed since `updated_at` (409)
     */
    public function update(UpdateIssueRequest $request): IssueDetailResource
    {
        $item = $request->issue();
        $since = $request->validated('updated_at');
        if ($since !== null && $item->updated_at !== null && ! $item->updated_at->equalTo(CarbonImmutable::parse($since)->startOfSecond())) {
            abort(409, 'This issue was changed since you loaded it. Load it again and retry.');
        }

        $this->fieldErrors(fn () => Issues::update($item, $request->user(), $request->issueData()));

        return new IssueDetailResource(self::detail($item));
    }

    /**
     * Delete an issue with its subtasks.
     *
     * Its creator, project admins and workspace admins (403 otherwise).
     *
     * @throws NotFoundHttpException the issue doesn't exist or the user can't open it (404)
     */
    public function destroy(Request $request, string $item): Response
    {
        $item = $this->visibleItem($request, $item);
        $this->authorize('delete', $item);

        Issues::delete($item);

        return response()->noContent();
    }

    /**
     * The dated issues of a project for a timeline or calendar: every board's issues (subtasks too) whose start
     * … due dates touch the days `from` … `to`.
     *
     * An issue with only a start or only a due date is that one day. Sorted by its first day (start, else due),
     * cursor-paginated. Reschedule with `PATCH /items/{id}` (`start_date`, `due_date`, times). Unlike the board,
     * done cards are all here (`status=open` leaves them out), as the web's list, timeline and calendar.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function dated(ListDatedIssuesRequest $request, string $project): IssueCollection
    {
        $project = $this->visibleProject($request, $project);
        $boards = Board::where('project_id', $project->id)->with('project.workspace')->get()->keyBy('id');
        $boardId = $request->board();
        if ($boardId !== null && ! $boards->has($boardId)) {
            throw ValidationException::withMessages(['board' => 'That board is not in this project.']);
        }
        [$from, $to] = [$request->from(), $request->to()];
        $since = $request->updatedSince();
        $status = $request->status();

        $dated = Item::query()
            ->select('items.*')
            ->selectRaw('COALESCE(items.start_date, items.due_date) as first_day')
            ->whereIn('items.board_id', $boardId !== null ? [$boardId] : $boards->keys()->all())
            ->where(fn ($q) => $q->whereNotNull('items.start_date')->orWhereNotNull('items.due_date'))
            // first day ≤ to and last day ≥ from
            ->whereRaw('COALESCE(items.start_date, items.due_date) <= ?', [$to])
            ->whereRaw('COALESCE(items.due_date, items.start_date) >= ?', [$from])
            ->when($status === 'open', fn ($q) => $q->whereNull('items.completed_at'))
            ->when($status === 'done', fn ($q) => $q->whereNotNull('items.completed_at'))
            ->withCount(IssueResource::COUNTS);

        // the first day is a column of the sub-select, so the cursor can order by it (and the models keep it)
        $page = Item::query()->fromSub($dated, 'items')
            ->with(IssueResource::WITH)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('first_day')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Item $item) => $item->setRelation('board', $boards[$item->board_id]));

        return new IssueCollection($page);
    }

    /** A fresh issue with what IssueDetailResource needs. */
    public static function detail(Item $item): Item
    {
        $board = $item->board()->with('project.workspace')->firstOrFail();
        $fresh = IssueResource::eagerLoad(Item::query())->with(IssueDetailResource::WITH)->findOrFail($item->id);

        return $fresh->setRelation('board', $board);
    }

    /**
     * Custom field errors come back keyed `values.<id>` (the web's input name): the API's is `fields`.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function fieldErrors(Closure $write): mixed
    {
        try {
            return $write();
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'values.') ? 'fields.'.substr($key, 7) : $key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
