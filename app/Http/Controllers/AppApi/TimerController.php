<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\AnswersWithETag;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\DiscardTimerRequest;
use App\Http\Requests\AppApi\StartTimerRequest;
use App\Http\Requests\AppApi\StopTimerRequest;
use App\Http\Resources\AppApi\TimerStateResource;
use App\Http\Resources\AppApi\TimerStopResource;
use App\Models\BillingActivity;
use App\Models\Item;
use App\Models\User;
use App\Models\WorkTimer;
use App\Support\AppApi\ValidationWithExtras;
use App\Support\Billing\TimeEntries;
use App\Support\Billing\TimeLogActivities;
use App\Support\Billing\WorkTimers;
use App\Support\MyWork\WorkRef;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — the focus timer (BRAI-400): the desktop tray's start / stop and running time. The same
 * timer as the web's top-bar pill (one per user, WorkTimers): started in the app it shows on the web and
 * the other way round. Stopping logs the reviewed time as a work log (Item::logWork), exactly once.
 */
#[Group('Time tracking', weight: 190)]
class TimerController extends Controller
{
    use AnswersWithETag;

    /**
     * The user's running timer (or none) and what the stop dialog needs.
     *
     * Answers an `ETag`; send it back as `If-None-Match` to get 304 while nothing changed. The elapsed
     * time ticks by itself and does not change the tag: count from `timer.started_at` on a 304.
     */
    public function show(Request $request): JsonResponse|Response
    {
        $response = $this->state($request->user())->response($request);
        // the elapsed time ticks by itself (the app counts from `started_at`): it isn't a change
        $data = $response->getData(true);
        unset($data['data']['timer']['elapsed_seconds'], $data['data']['timer']['elapsed_minutes']);

        return $this->withETag($request, $response, (string) json_encode($data));
    }

    /**
     * Start the timer on an issue.
     *
     * Needs the right to edit the issue (= log work on it, 403). Only one timer runs per user: another
     * one running → 409 (stop it first). List tasks can't be timed (422).
     *
     * @throws NotFoundHttpException not an issue the user can open (404)
     * @throws AuthorizationException the user may not log work on it (403)
     * @throws HttpException a timer is already running (409 conflict)
     */
    public function start(StartTimerRequest $request): JsonResponse
    {
        $user = $request->user();
        $ref = (string) $request->validated('id');
        if ((WorkRef::parse($ref)[0] ?? null) === WorkRef::TASK) {
            throw ValidationException::withMessages(['id' => ['List tasks have no work log and can’t be timed. Pick an issue.']]);
        }
        $item = WorkRef::visible($user, $ref);
        abort_unless($item instanceof Item, 404);
        $this->authorize('update', $item);

        abort_if(WorkTimers::start($user, $item) === null, 409, 'A timer is already running — stop it first.');

        return (new TimerStateResource(WorkTimer::where('user_id', $user->id)->first(), $user))->response()->setStatusCode(201);
    }

    /**
     * Stop the timer and log the time.
     *
     * `time` defaults to the elapsed minutes, `spent_on` to the user's today. Answers what happened:
     * `logged` (+ the work log), `already_stopped` (a retry, or stopped elsewhere: nothing logged) or
     * `no_access` (the issue can't be logged on any more: stopped, nothing logged). A missing (or unusable)
     * activity where one is required → 422 on `activity_id` and the timer keeps running; that answer also
     * carries `activities: [{id, name}]` (what can be picked) and `default_activity_id` (preselect it, or
     * null), so the app can show a picker and retry.
     */
    public function stop(StopTimerRequest $request): TimerStopResource
    {
        $user = $request->user();
        $data = $request->validated();
        $timer = WorkTimer::where('user_id', $user->id)->whereKey((int) $data['timer_id'])->first();
        $minutes = isset($data['time']) ? (int) Item::parseDuration($data['time']) : ($timer?->elapsedMinutes() ?? 1);

        try {
            $stopped = WorkTimers::stop($user, (int) $data['timer_id'], $minutes, $data['note'] ?? null, $data['activity_id'] ?? null, $data['spent_on'] ?? null);
        } catch (ValidationException $e) {
            // the stop was rolled back (the timer runs on): tell the app what it may pick, so it can retry
            $workspaceId = isset($e->errors()['activity_id']) ? $timer?->item?->board?->project?->workspace_id : null;
            if ($workspaceId === null) {
                throw $e;
            }

            throw ValidationWithExtras::with($e->errors(), [
                'activities' => TimeLogActivities::active($workspaceId)->map(fn (BillingActivity $a) => ['id' => $a->id, 'name' => $a->name])->values()->all(),
                'default_activity_id' => TimeLogActivities::defaultFor($user, $workspaceId, $timer->item),
            ]);
        }
        $stopped['entry']?->load([...TimeEntries::WITH, 'workspace']);

        return new TimerStopResource($stopped, array_flip($user->accessibleProjectIds()));
    }

    /** Stop the timer without logging anything. Answers the timer state (not running). */
    public function discard(DiscardTimerRequest $request): TimerStateResource
    {
        WorkTimers::discard($request->user(), (int) $request->validated('timer_id'));

        return $this->state($request->user());
    }

    private function state(User $user): TimerStateResource
    {
        return new TimerStateResource(WorkTimer::where('user_id', $user->id)->first(), $user);
    }
}
