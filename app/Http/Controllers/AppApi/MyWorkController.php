<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\AnswersWithETag;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\AddMyDayItemRequest;
use App\Http\Requests\AppApi\ListWorkRequest;
use App\Http\Requests\AppApi\WorkDoneRequest;
use App\Http\Resources\AppApi\MyDayResource;
use App\Http\Resources\AppApi\WorkItemCollection;
use App\Http\Resources\AppApi\WorkItemResource;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Models\User;
use App\Support\MyDay\MyDayPlan;
use App\Support\MyWork\AssignedWork;
use App\Support\MyWork\WorkRef;
use App\Support\Work\WorkCompletion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — My Work and My Day (BRAI-396): the work assigned to the user (AssignedWork, shared
 * with the browser extension's ext/v1/my-work), the hand-picked plan for today (MyDayPlan, shared
 * with the web's My Day) and the circle that completes / reopens work (WorkCompletion, shared with
 * the board and the web's My Work). Work is addressed as `issue:<id>` / `task:<id>`; work the user
 * can't open is a 404.
 */
#[Group('My Work', weight: 130)]
class MyWorkController extends Controller
{
    use AnswersWithETag;

    /**
     * The work assigned to the user.
     *
     * Board issues and list tasks of every project the user can open, soonest due first (no due date
     * last). `due` = overdue | today | upcoming (or several, comma separated), by the user's local date;
     * `status` = open (default) | done | all. For a cheap refresh pass `updated_since` with
     * `status=all`: entries that were completed since then come back with `done: true`.
     *
     * Answers an `ETag` (the page and the user); send it back as `If-None-Match` to get 304 while that page is unchanged.
     *
     * @response WorkItemCollection<CursorPaginator<int, mixed>>
     */
    public function index(ListWorkRequest $request): JsonResponse|Response
    {
        $user = $request->user();
        $page = AssignedWork::combined($user, $request->due(), $request->status(), $request->updatedSince(), MyDayPlan::today($user))
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        // rows {kind, id, sort_due} → the models; they keep the row's sort keys, which next_cursor is built from
        $rows = $page->getCollection();
        $models = WorkRef::load($rows->map(fn (object $row) => [$row->kind, (int) $row->id]))
            ->keyBy(fn ($work) => WorkRef::of($work));
        $page->setCollection($rows
            ->map(fn (object $row) => $models->get($row->kind.':'.$row->id)?->setAttribute('kind', $row->kind)->setAttribute('sort_due', $row->sort_due))
            ->filter()
            ->values());

        return $this->withETag($request, (new WorkItemCollection($page))->response($request));
    }

    /**
     * Complete or reopen a piece of work.
     *
     * The web's circle in My Work: an issue moves to its board's first done column (or back to the first
     * open one) and its watchers are told; a list task changes its status (a repeating one with a due date
     * moves on to its next date and stays open). Needs edit rights (403); a board without a done column → 422.
     *
     * @throws NotFoundHttpException not work the user can open (404)
     */
    public function update(WorkDoneRequest $request, string $id): WorkItemResource
    {
        $work = $this->visible($request, $id);
        $this->complete($request->user(), $work, $request->boolean('done'));

        return new WorkItemResource($this->fresh($work));
    }

    /** My Day: what the user planned for their local today, in their order. Answers an `ETag`: send it as `If-None-Match` to get 304 while nothing changed. */
    public function myDay(Request $request): JsonResponse|Response
    {
        return $this->withETag($request, $this->day($request->user())->response($request));
    }

    /**
     * Put work on today's plan (at the end; twice changes nothing). Answers the updated My Day.
     *
     * Work the user can't open (or a malformed id) → 422 on `id`.
     */
    public function addToMyDay(AddMyDayItemRequest $request): MyDayResource
    {
        $work = WorkRef::visible($request->user(), $request->string('id')->toString());
        if ($work === null) {
            throw ValidationException::withMessages(['id' => ['There is no such issue or task you can open.']]);
        }

        MyDayPlan::add($request->user(), $this->myDayType($work), $work->id);

        return $this->day($request->user());
    }

    /** Take work off today's plan (the work itself is not touched). Answers the updated My Day. */
    public function removeFromMyDay(Request $request, string $id): MyDayResource
    {
        $parsed = WorkRef::parse($id);
        abort_if($parsed === null, 404);

        MyDayPlan::remove($request->user(), WorkRef::MY_DAY_TYPES[$parsed[0]], $parsed[1]);

        return $this->day($request->user());
    }

    /**
     * Complete or reopen work on today's plan, as `PATCH /me/work/{id}`. Answers the updated My Day.
     *
     * @throws NotFoundHttpException not work the user can open (404)
     */
    public function updateMyDayItem(WorkDoneRequest $request, string $id): MyDayResource
    {
        $work = $this->visible($request, $id);
        $this->complete($request->user(), $work, $request->boolean('done'));

        return $this->day($request->user());
    }

    /** "Bring over": unfinished work of the last 30 days' plans onto today. Answers the updated My Day. */
    public function carryOver(Request $request): MyDayResource
    {
        MyDayPlan::carryOver($request->user());

        return $this->day($request->user());
    }

    private function day(User $user): MyDayResource
    {
        $date = MyDayPlan::today($user);

        return new MyDayResource([
            'date' => $date,
            'items' => MyDayPlan::forDate($user, $date, WorkRef::ISSUE_WITH, WorkRef::TASK_WITH)->pluck('work')->values(),
            'carry_over_count' => MyDayPlan::carryOverCandidates($user, $date)->count(),
        ]);
    }

    private function visible(Request $request, string $id): Item|TaskListItem
    {
        $work = WorkRef::visible($request->user(), $id);
        abort_if($work === null, 404);

        return $work;
    }

    /** @throws AuthorizationException without edit rights (403) */
    private function complete(User $user, Item|TaskListItem $work, bool $done): void
    {
        if ($work instanceof Item) {
            $this->authorizeForUser($user, 'update', $work);
        } else {
            $this->authorizeForUser($user, 'manageItems', $work->taskList);
        }

        WorkCompletion::setDone($work, $done, $user);
    }

    private function fresh(Item|TaskListItem $work): Item|TaskListItem
    {
        return $work->fresh($work instanceof Item ? WorkRef::ISSUE_WITH : WorkRef::TASK_WITH) ?? $work;
    }

    private function myDayType(Item|TaskListItem $work): string
    {
        return $work instanceof Item ? 'board' : 'list';
    }
}
