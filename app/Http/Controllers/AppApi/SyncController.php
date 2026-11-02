<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\SyncBatchRequest;
use App\Http\Requests\AppApi\SyncChangesRequest;
use App\Http\Resources\AppApi\SyncChangeResource;
use App\Http\Resources\AppApi\SyncResultResource;
use App\Models\User;
use App\Support\AppApi\AppTokens;
use App\Support\AppApi\ValidationWithExtras;
use App\Support\Offline\OfflineSync;
use App\Support\Sync\ChangeFeed;
use App\Support\Sync\SyncChange;
use App\Support\Sync\SyncRecords;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * App API v1 — offline sync for native clients (BRAI-425). Reads: a delta feed of the content a client
 * keeps offline (`App\Support\Sync\ChangeFeed`). Writes: the device's outbox in batches through the web's
 * offline engine (`App\Support\Offline\OfflineSync`: the same mutation types, appliers, idempotency per
 * request_id, field-level conflicts and policies as `POST /offline/sync`).
 */
#[Group('Sync', weight: 330)]
class SyncController extends Controller
{
    /** Changes per user and minute, over all batches (the web's offline sync allows 240 one-change requests). */
    public const PER_MINUTE = 240;

    /**
     * What changed in the client's offline copy.
     *
     * Start without a cursor (optionally `scopes` = work,task_lists,my_day,captures, `task_lists` = ids to
     * keep and / or `mine`, `captures` = all|inbox): a **full run** (`reset: true`) — page with `next_cursor`
     * while `has_more`, then drop every cached record of the scope the run didn't send. Keep the last
     * `next_cursor` (when `has_more` is false) and call again with it later: a **delta run** with what changed
     * since — `upsert` (add / replace with `data`) and `delete` (drop: deleted, reassigned away, a deleted
     * board / list, a list no longer kept, a capture that left the inbox). Always drop cached records of
     * projects that are not in `project_ids` (lost access, deleted project). Other `task_lists` with a cursor
     * are additive: the new lists come in full, dropped ones as deletes, the rest goes on. A cursor older than
     * 30 days, of another account or unknown, or new `scopes` / `captures` start a full run again. The same
     * change may come twice (apply it by `updated_at`).
     */
    public function changes(SyncChangesRequest $request): JsonResponse
    {
        [$lists, $mine] = $request->taskLists() ?? [null, null];
        $page = ChangeFeed::page($request->user(), $request->cursor(), $request->scopes(), $lists, $request->limit(), $mine, $request->captures());

        return response()->json([
            'data' => SyncChangeResource::collection($page['changes']),
            // the next page while has_more; else keep it for the next sync (never null)
            'next_cursor' => $page['next_cursor'],
            // true: call again with next_cursor now (this run isn't finished)
            'has_more' => $page['has_more'],
            // true: a full run — once has_more is false, drop what this run did not send
            'reset' => $page['reset'],
            /** @var list<int> projects the user can open now: drop cached records of any other project */
            'project_ids' => $page['project_ids'],
            /** @var list<int> the task lists this run keeps (the ids asked for + `mine`, only lists the user can open): drop cached lists not in it */
            'task_lists' => $page['task_lists'],
        ]);
    }

    /**
     * Apply a batch of queued offline changes.
     *
     * `{changes: [{request_id, type, target?, base?, data}]}` (≤ 50), applied in order, each on its own: one
     * result per change in the same order, and one bad change never stops the others. Types and their data
     * are the web's offline mutations (`list_task.create|update|reorder`, `issue.update|comment|create`,
     * `capture.create`, `my_day.add|remove`) plus `work.done`, `timer.start|stop|discard`, `notification.read`
     * and `notifications.read_all`. Updates carry `base.fields` (each changed
     * field as the device saw it): a field someone else changed meanwhile is a `conflict` (409) and is not
     * written — never overwritten. `request_id` makes a change idempotent (30 days): a retry answers the
     * stored result (`replayed: true`); the same id with another change → `invalid`. `entity` is the server's
     * current state as the feed's record of `record_type` (work, task, task_list, capture, my_day, comment, timer,
     * notification, unread_count). `gone`: the timer / notification is no longer there, nothing was written.
     * `target.ref` / `data.work_ref` = the request_id of an earlier create (in this batch or before). Budget:
     * 240 changes a minute per user (`rate_limited`: send it again later).
     */
    public function store(SyncBatchRequest $request): JsonResponse
    {
        $user = $request->user();
        $client = AppTokens::currentDevice($request)?->client;
        $source = $client ? config("app-api.clients.{$client}.name") : null;

        $results = [];
        foreach ((array) $request->input('changes', []) as $change) {
            $results[] = $this->apply($user, is_array($change) ? $change : [], is_string($source) ? $source : null);
        }

        return response()->json(['data' => SyncResultResource::collection($results)]);
    }

    /**
     * @param  array<mixed>  $change
     * @return array{request_id: string|null, type: string|null, status: string, http_status: int, body?: array<string, mixed>, errors?: array<string, list<string>>|null, record?: SyncChange|null}
     */
    private function apply(User $user, array $change, ?string $source): array
    {
        $requestId = is_string($change['request_id'] ?? null) ? $change['request_id'] : null;
        $type = is_string($change['type'] ?? null) ? $change['type'] : null;
        $result = fn (string $status, int $http, array $body = [], ?array $errors = null) => [
            'request_id' => $requestId, 'type' => $type, 'status' => $status, 'http_status' => $http, 'body' => $body, 'errors' => $errors,
        ];

        // the web's envelope rules (OfflineSyncController::store)
        $validator = Validator::make($change, [
            'request_id' => 'required|uuid',
            'type' => ['required', 'string', Rule::in(array_keys(OfflineSync::TYPES))],
            'target' => 'nullable|array',
            'target.id' => 'nullable|integer',
            'target.ref' => 'nullable|uuid',
            'base' => 'nullable|array',
            'base.updated_at' => 'nullable|string|max:40',
            'base.fields' => 'nullable|array|max:10',
            'data' => 'nullable|array|max:20',
        ]);
        if ($validator->fails()) {
            return $result('invalid', 422, ['message' => 'This change is not valid.'], $validator->errors()->toArray());
        }
        if (! RateLimiter::attempt('app-sync:'.$user->id, self::PER_MINUTE, fn () => true, 60)) {
            return $result('rate_limited', 429, ['message' => 'Too many changes. Send this one again in a minute.']);
        }

        $mutation = [
            'id' => $requestId,
            'type' => $type,
            'target' => $change['target'] ?? null,
            'base' => $change['base'] ?? null,
            'data' => (array) ($change['data'] ?? []),
        ];
        // as POST /captures: an idea without a source came from this app
        if ($type === 'capture.create' && $source !== null && empty($mutation['data']['source'])) {
            $mutation['data']['source'] = $source;
        }

        try {
            $outcome = OfflineSync::handle($user, $mutation);
        } catch (ValidationException $e) {
            // a timer stop refused for its activity also says what may be picked (as the REST 422)
            $extras = $e instanceof ValidationWithExtras ? $e->extras : [];

            return $result('invalid', 422, ['message' => $e->getMessage()] + $extras, $e->errors());
        } catch (AuthorizationException) {
            return $result('forbidden', 403, ['message' => 'You are not allowed to make this change.']);
        } catch (ThrottleRequestsException $e) {
            return $result('rate_limited', 429, ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return $result('error', 500, ['message' => 'This change could not be applied. Send it again later.']);
        }

        // the entity as the feed's record (the web engine's snapshot is for the web's own offline mode)
        $record = $outcome->isFinal() ? SyncRecords::find($user, (string) $type, $outcome->entityType, $outcome->entityId, $mutation['data']) : null;

        return $result((string) ($outcome->body['status'] ?? 'applied'), $outcome->status, $outcome->body) + ['record' => $record];
    }
}
