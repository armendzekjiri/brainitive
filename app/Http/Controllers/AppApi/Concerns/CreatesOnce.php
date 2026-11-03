<?php

namespace App\Http\Controllers\AppApi\Concerns;

use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * App API v1: a create with an optional `request_id` (uuid) runs once (docs/ai/API.md → Writes). A
 * retry with the same id and the same input answers with what the first call created (the
 * controller then sends 200 instead of 201); the same id with other input → 409 `conflict`. Stored
 * like the MCP's writes (`IdempotentRequest`, 7 days), keyed per user and endpoint.
 *
 * A replay answers the model as it is *now*, so the caller must still be allowed to see it (Codex review): an issue
 * moved to a project the caller isn't in, a page moved away … → the same 404 the read endpoint gives, never its
 * data. The check is the model's policy `view` by default; models without one pass `$visible`.
 */
trait CreatesOnce
{
    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $payload  what defines the write (validated input + parent id)
     * @param  Closure(): TModel  $create
     * @param  (Closure(TModel): bool)|null  $visible  whether the caller may still see a replayed model (default: its policy's `view`)
     * @return array{0: TModel, 1: bool} the model and whether it was created by an earlier call
     */
    protected function createOnce(Request $request, string $endpoint, string $class, array $payload, Closure $create, ?Closure $visible = null): array
    {
        $requestId = $request->input('request_id');
        if (! is_string($requestId) || $requestId === '') {
            return [$create(), false];
        }

        $created = null;
        try {
            $run = IdempotentRequest::run($request->user(), 'app-api:'.$endpoint, $requestId, $payload, function () use ($create, &$created) {
                $created = $create();

                return ['id' => $created->getKey()];
            });
        } catch (RequestIdConflict $e) {
            abort(409, $e->getMessage());
        }

        if (! $run['replayed'] && $created !== null) {
            return [$created, false];
        }

        $model = $class::query()->find($run['result']['id'] ?? null);
        abort_if($model === null, 404, 'What this request created no longer exists.');
        // the plain 404 of ResolvesVisible: nothing tells the caller it still exists elsewhere
        abort_unless($visible ? $visible($model) : self::viewable($request, $model), 404);

        return [$model, true];
    }

    private static function viewable(Request $request, Model $model): bool
    {
        $policy = Gate::getPolicyFor($model);
        if ($policy === null || ! method_exists($policy, 'view')) {
            throw new LogicException('createOnce() for '.$model::class.' needs a $visible check: the model has no policy with view().');
        }

        return $request->user()->can('view', $model);
    }
}
