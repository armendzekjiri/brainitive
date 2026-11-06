<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithReleases;
use App\Models\Release;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use App\Support\Releases\ReleaseLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Change a release: name, description, dates, notes (Markdown, replaces them) and/or status. Only the fields you pass change; null clears description / dates / notes. status: released ships an unreleased release (its unfinished issues need move_unfinished_to: another unreleased release id, or 0 to clear their fix version) or restores an archived one; archived; unreleased reopens it. Confirm shipping with the user.')]
class UpdateReleaseTool extends Tool
{
    use InteractsWithReleases;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->projectRules() + [
            'release_id' => 'required|integer',
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:5000',
            'start_date' => 'nullable|date_format:Y-m-d',
            'release_date' => 'nullable|date_format:Y-m-d',
            'notes' => 'nullable|string|max:200000',
            'status' => 'nullable|in:unreleased,released,archived',
            'move_unfinished_to' => 'nullable|integer|min:0',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $release = $this->releaseOf($project, (int) $v['release_id']);
            $this->authorizeFor($user, 'update', $release);

            // Validate the release as it will be (the web's rules), before anything is written.
            $changes = array_intersect_key($v, array_flip(['name', 'description', 'start_date', 'release_date']));
            if (isset($changes['name'])) {
                $changes['name'] = trim($changes['name']);
            }
            $merged = $changes + [
                'name' => $release->name,
                'description' => $release->description,
                'start_date' => $release->start_date?->toDateString(),
                'release_date' => $release->release_date?->toDateString(),
            ];
            Validator::make($merged, ReleaseLifecycle::rules($project, $release))->validate();

            $status = $v['status'] ?? null;
            $ship = $status === Release::STATUS_RELEASED && $release->isUnreleased();
            $moveTo = null;
            if ($ship) {
                $unfinished = $release->items()->whereNull('completed_at')->count();
                if ($unfinished > 0 && ! array_key_exists('move_unfinished_to', $v)) {
                    throw new ToolError("{$unfinished} issue(s) of this release are unfinished. Pass move_unfinished_to: another unreleased release id, or 0 to clear their fix version. Nothing was changed.");
                }
                $moveTo = ((int) ($v['move_unfinished_to'] ?? 0)) ?: null;
            }
            if (! $changes && ! array_key_exists('notes', $v) && ($status === null || $status === $release->status)) {
                throw new ToolError('Nothing to change: pass name, description, start_date, release_date, notes or status.');
            }

            $moved = DB::transaction(function () use ($release, $project, $changes, $merged, $v, $status, $ship, $moveTo) {
                $update = $changes;
                if (array_key_exists('notes', $v)) {
                    $update['notes'] = $this->notesHtml($v['notes'], $project);
                }
                if ($update) {
                    $release->update($update);
                }
                if ($ship) {
                    return ReleaseLifecycle::ship($release, $merged['release_date'] ?? today()->toDateString(), $moveTo);
                }
                if ($status !== null && $status !== $release->status) {
                    ReleaseLifecycle::setStatus($release, $status);
                }

                return 0;
            });

            $release = $release->fresh()->setRelation('project', $project);

            return Response::structured([
                'updated' => true,
                'moved_unfinished' => $moved,
                'release' => $this->releaseSummary($release),
                'location' => ResourceLocator::locate($release),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'moved_unfinished' => $schema->integer()->description('Unfinished issues moved out when shipping.'),
            'release' => $this->releaseSchema($schema),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectArgs($schema) + [
            'release_id' => $schema->integer()->description('Release id from list_releases.')->required(),
            'name' => $schema->string()->description('≤ 100 chars, unique in the project.'),
            'description' => $schema->string(),
            'start_date' => $schema->string()->description('YYYY-MM-DD'),
            'release_date' => $schema->string()->description('YYYY-MM-DD, not before start_date. When shipping: the release day (default the current one, else today).'),
            'notes' => $schema->string()->description('Release notes as Markdown; replaces the current notes (the published page changes only when re-published in the app).'),
            'status' => $schema->string()->enum(['unreleased', 'released', 'archived']),
            'move_unfinished_to' => $schema->integer()->description('When shipping: unreleased release id for the unfinished issues, or 0 to clear their fix version.'),
        ];
    }
}
