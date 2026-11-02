<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListWorkLogsRequest;
use App\Http\Requests\AppApi\StoreWorkLogRequest;
use App\Http\Requests\AppApi\TimesheetRequest;
use App\Http\Requests\AppApi\UpdateWorkLogRequest;
use App\Http\Resources\AppApi\TimesheetResource;
use App\Http\Resources\AppApi\WorkLogCollection;
use App\Http\Resources\AppApi\WorkLogResource;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\TimeEntries;
use App\Support\Billing\TimeLog;
use App\Support\Billing\TimeLogActivities;
use Closure;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — work logs and my timesheet (BRAI-400): the user's own logged time, the web's "My
 * timesheet" (TimeEntries, shared with TimesheetController). Never a rate or an amount; rates,
 * clients, VAT and invoices are web only. Billed entries (`locked`) are read-only (422 `entry`).
 * Other people's entries are a 404 here (workspace admins change them on the web's time overview).
 */
#[Group('Time tracking', weight: 190)]
class WorkLogController extends Controller
{
    use CreatesOnce;

    /**
     * The user's own work logs, newest day first.
     *
     * `workspace` (slug) narrows to one workspace (default: every workspace the user is a member of);
     * `from` / `to` (Y-m-d) to days.
     *
     * @throws NotFoundHttpException `workspace` is not one of the user's (404)
     */
    public function index(ListWorkLogsRequest $request): WorkLogCollection
    {
        $user = $request->user();
        $data = $request->validated();
        $workspaceIds = $user->workspaces()->pluck('workspaces.id');
        if (isset($data['workspace'])) {
            $workspace = Workspace::where('slug', $data['workspace'])->whereIn('id', $workspaceIds)->first();
            abort_unless($workspace instanceof Workspace, 404);
            $workspaceIds = collect([$workspace->id]);
        }
        $since = $request->updatedSince();

        $page = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->when($data['from'] ?? null, fn ($q, string $from) => $q->where('spent_on', '>=', $from))
            ->when($data['to'] ?? null, fn ($q, string $to) => $q->where('spent_on', '<=', $to))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->with([...TimeEntries::WITH, 'workspace:id,slug,name'])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());

        $accessible = array_flip($user->accessibleProjectIds());
        $page->setCollection($page->getCollection()->map(fn (TimeEntry $entry) => new WorkLogResource($entry, $accessible)));

        return new WorkLogCollection($page);
    }

    /**
     * Log time on an issue (`issue_id`) or on a project.
     *
     * The web timesheet's form: a project the user can log on (422 `project_id`), an issue of it they may
     * edit (422 `issue_id`), an activity while the workspace has any (422 `activity_id`). With `request_id`
     * a retry answers 200 with the work log the first call created.
     */
    public function store(StoreWorkLogRequest $request): JsonResponse
    {
        $user = $request->user();
        $workspace = $request->workspace();
        abort_unless($workspace instanceof Workspace, 422); // the rules already refused the project
        $data = $request->safe()->except(['request_id']);
        $form = ['item_id' => $data['issue_id'] ?? null] + collect($data)->except('issue_id')->all();

        [$entry, $replayed] = $this->createOnce($request, 'work-logs.store', TimeEntry::class, $data,
            fn () => $this->asIssueErrors(fn () => TimeEntries::create($user, $workspace, $form)),
            fn (TimeEntry $entry) => (bool) $this->own($user, (string) $entry->id));

        $resource = new WorkLogResource($this->loaded($entry), array_flip($user->accessibleProjectIds()));
        if ($replayed) {
            return $resource->response();
        }

        return $resource->response()->setStatusCode(201);
    }

    /**
     * One of the user's work logs.
     *
     * @throws NotFoundHttpException not the user's own entry, or a workspace they left (404)
     */
    public function show(Request $request, string $log): WorkLogResource
    {
        $user = $request->user();

        return new WorkLogResource($this->loaded($this->own($user, $log)), array_flip($user->accessibleProjectIds()));
    }

    /**
     * Change a work log (only the sent fields).
     *
     * The rest is taken from the entry and the whole form is checked as on the web. Billed (`locked`)
     * → 422 `entry`; time logged before the timesheet existed (`source: legacy`): only date, activity and
     * note change.
     *
     * @throws NotFoundHttpException not the user's own entry (404)
     */
    public function update(UpdateWorkLogRequest $request, string $log): WorkLogResource
    {
        $user = $request->user();
        $entry = $request->ownEntry();
        $workspace = $entry->workspace;
        TimeEntries::guard($user, $workspace, $entry);

        $entry = $this->asIssueErrors(fn () => TimeEntries::update($user, $workspace, $entry, $request->form()));

        return new WorkLogResource($this->loaded($entry), array_flip($user->accessibleProjectIds()));
    }

    /**
     * Delete a work log; its minutes come off the issue's total.
     *
     * Billed (`locked`) or logged before the timesheet existed → 422 `entry`.
     *
     * @throws NotFoundHttpException not the user's own entry (404)
     */
    public function destroy(Request $request, string $log): Response
    {
        $user = $request->user();
        $entry = $this->own($user, $log);
        TimeEntries::guard($user, $entry->workspace, $entry);
        TimeEntries::delete($entry);

        return response()->noContent();
    }

    /**
     * My timesheet: one workspace, one week (Monday–Sunday) of the user's own time.
     *
     * The entries, minutes per day, the projects the user can log on and the activities (never a rate).
     * `week` = any day of the week, default the user's current one.
     *
     * @throws NotFoundHttpException not a member of the workspace (404)
     */
    public function timesheet(TimesheetRequest $request): TimesheetResource
    {
        $user = $request->user();
        $workspace = Workspace::where('slug', $request->validated('workspace'))->first();
        abort_unless($workspace instanceof Workspace && $user->can('view', $workspace), 404);

        $week = TimeEntries::week($user, $request->validated('week'));
        $entries = TimeEntries::between($user, $workspace, $week['start'], $week['end'])->load('workspace:id,slug,name');

        return new TimesheetResource([
            'workspace' => $workspace,
            'week' => $week,
            'entries' => $entries,
            'accessible' => array_flip($user->accessibleProjectIds()),
            'projects' => Project::whereIn('id', TimeLog::loggableProjectIds($user, $workspace))->orderBy('name')->get(['id', 'name', 'key']),
            'activities' => TimeLogActivities::options($workspace),
            'activity_required' => TimeLogActivities::required($workspace),
            'default_activity_id' => TimeLogActivities::defaultFor($user, $workspace),
        ]);
    }

    private function own(User $user, string $id): TimeEntry
    {
        $entry = preg_match('/^[1-9][0-9]{0,17}$/', $id) ? TimeEntry::with('workspace')->where('user_id', $user->id)->find((int) $id) : null;
        abort_unless($entry instanceof TimeEntry && $entry->workspace !== null && $user->hasWorkspaceAccess($entry->workspace), 404);

        return $entry;
    }

    private function loaded(TimeEntry $entry): TimeEntry
    {
        return $entry->load([...TimeEntries::WITH, 'workspace:id,slug,name']);
    }

    /**
     * The shared form calls the issue `item_id`; the App API calls it `issue_id`.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function asIssueErrors(Closure $write): mixed
    {
        try {
            return $write();
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (! isset($errors['item_id'])) {
                throw $e;
            }
            $errors['issue_id'] = $errors['item_id'];
            unset($errors['item_id']);

            throw ValidationException::withMessages($errors);
        }
    }
}
