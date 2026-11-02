<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListRequest;
use App\Http\Requests\AppApi\ListTasksRequest;
use App\Http\Requests\AppApi\ReorderTasksRequest;
use App\Http\Requests\AppApi\StoreTaskListRequest;
use App\Http\Requests\AppApi\StoreTaskRequest;
use App\Http\Requests\AppApi\UpdateTaskRequest;
use App\Http\Resources\AppApi\TaskCollection;
use App\Http\Resources\AppApi\TaskListCollection;
use App\Http\Resources\AppApi\TaskListDetailResource;
use App\Http\Resources\AppApi\TaskResource;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Support\Mcp\PageContentConverter;
use App\Support\TaskLists\TaskLists;
use App\Support\Work\WorkCompletion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — task lists and their to-dos (BRAI-397). Same rules as the web (TaskListPolicy: everybody
 * who can open the project reads; project admins / editors and workspace admins add and change tasks) and
 * the same code (TaskLists, shared with the web, the CLI and the MCP; WorkCompletion for done / repeating
 * tasks). A list or project the user can't open is a 404. Lists and tasks are addressed by id.
 */
#[Group('Task lists', weight: 150)]
class TaskListController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * The task lists of a project, in the web's order.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function index(ListRequest $request, string $project): TaskListCollection
    {
        $project = $this->visibleProject($request, $project);
        $since = $request->updatedSince();

        $page = TaskLists::withCounts($project->taskLists()->getQuery())
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (TaskList $list) => $list->setRelation('project', $project));

        return new TaskListCollection($page);
    }

    /**
     * Create a task list at the end of the project (the user watches it).
     *
     * Project admins / editors and workspace admins (403 otherwise). With `request_id` a retry answers
     * 200 with the list the first call created.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function store(StoreTaskListRequest $request): JsonResponse
    {
        $project = $request->project();
        $data = $request->safe()->only(['name', 'description']);

        [$list, $replayed] = $this->createOnce($request, 'task-lists.store', TaskList::class, ['project' => $project->id] + $data,
            fn () => TaskLists::create($project, $request->user(), $data['name'], $data['description'] ?? null));

        $list = $this->withCounts($list);
        if ($replayed) {
            return (new TaskListDetailResource($list))->response();
        }

        return (new TaskListDetailResource($list))->response()->setStatusCode(201);
    }

    /**
     * One task list: counts, what the user may do, whether they watch it.
     *
     * @throws NotFoundHttpException the list doesn't exist or the user can't open it (404)
     */
    public function show(Request $request, string $list): TaskListDetailResource
    {
        return new TaskListDetailResource($this->withCounts($this->visibleTaskList($request, $list)));
    }

    /**
     * The tasks of a list, in the list's order.
     *
     * `status` = todo | done | all (default). For a refresh pass `updated_since`; deleted tasks don't
     * come back, so reload the whole list now and then.
     *
     * @throws NotFoundHttpException the list doesn't exist or the user can't open it (404)
     */
    public function items(ListTasksRequest $request, string $list): TaskCollection
    {
        $list = $this->visibleTaskList($request, $list);
        $status = $request->status();
        $since = $request->updatedSince();

        $page = TaskListItem::query()
            ->where('task_list_id', $list->id)
            ->with('assignee')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('position')
            ->orderBy('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (TaskListItem $task) => $task->setRelation('taskList', $list));

        return new TaskCollection($page);
    }

    /**
     * Add a task at the end of the list.
     *
     * `notes` is Markdown. Needs the right to change tasks (403). With `request_id` a retry answers 200
     * with the task the first call created.
     *
     * @throws NotFoundHttpException the list doesn't exist or the user can't open it (404)
     */
    public function storeItem(StoreTaskRequest $request): JsonResponse
    {
        $list = $request->taskList();
        $data = $this->attributes($request->safe()->except(['request_id']));

        [$task, $replayed] = $this->createOnce($request, 'task-lists.items.store', TaskListItem::class, ['list' => $list->id] + $request->safe()->except(['request_id']),
            fn () => TaskLists::createTask($list, $request->user(), $data),
            fn (TaskListItem $task) => $task->taskList !== null && $request->user()->can('view', $task->taskList));

        $task = $this->prepared($task, $list);
        if ($replayed) {
            return (new TaskResource($task))->response();
        }

        return (new TaskResource($task))->response()->setStatusCode(201);
    }

    /**
     * Change a task; only the sent fields change.
     *
     * `status: done` completes it — a repeating task with a due date stays open and moves to its next
     * date instead, as on the web. Needs the right to change tasks (403).
     *
     * @throws NotFoundHttpException the list or task doesn't exist, or the user can't open it (404)
     */
    public function updateItem(UpdateTaskRequest $request, string $list, string $item): TaskResource
    {
        $list = $request->taskList();
        $task = $this->taskOf($list, $item);

        WorkCompletion::updateTask($task, $this->attributes($request->validated()));

        return new TaskResource($this->prepared($task->refresh(), $list));
    }

    /**
     * Delete a task.
     *
     * Needs the right to change tasks (403).
     *
     * @throws NotFoundHttpException the list or task doesn't exist, or the user can't open it (404)
     */
    public function destroyItem(Request $request, string $list, string $item): Response
    {
        $list = $this->visibleTaskList($request, $list);
        $this->authorize('manageItems', $list);

        $this->taskOf($list, $item)->delete();

        return response()->noContent();
    }

    /**
     * Put the tasks in a new order; answers the list.
     *
     * Send every task id of the list, top to bottom. Needs the right to change tasks (403).
     *
     * @throws NotFoundHttpException the list doesn't exist or the user can't open it (404)
     */
    public function reorder(ReorderTasksRequest $request): TaskListDetailResource
    {
        $list = $request->taskList();
        $positions = [];
        foreach (array_values($request->validated('ids')) as $index => $id) {
            $positions[(int) $id] = $index + 1;
        }
        TaskLists::reorder($list, $positions);

        return new TaskListDetailResource($this->withCounts($list));
    }

    private function withCounts(TaskList $list): TaskList
    {
        $list->loadMissing('project.workspace');
        $list->loadCount([
            'items as todo_count' => fn ($q) => $q->where('status', 'todo'),
            'items as done_count' => fn ($q) => $q->where('status', 'done'),
        ]);

        return $list;
    }

    private function prepared(TaskListItem $task, TaskList $list): TaskListItem
    {
        return $task->load('assignee')->setRelation('taskList', $list);
    }

    private function taskOf(TaskList $list, string $id): TaskListItem
    {
        $task = preg_match('/^[1-9][0-9]{0,17}$/', $id) ? TaskListItem::where('task_list_id', $list->id)->find((int) $id) : null;
        abort_unless($task instanceof TaskListItem, 404);

        return $task;
    }

    /**
     * Validated input → task columns: the Markdown `notes` become the editor's document.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        if (array_key_exists('notes', $input)) {
            $notes = trim((string) $input['notes']);
            $input['description'] = $notes === '' ? null : PageContentConverter::htmlToPageContent(PageContentConverter::markdownToHtml($notes));
            unset($input['notes']);
        }

        return $input;
    }
}
