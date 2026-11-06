<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithReleases;
use App\Models\Release;
use App\Support\Entitlements\LimitGate;
use App\Support\Mcp\ResourceLocator;
use App\Support\Releases\ReleaseLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a release (fix version) in a project. Names are unique per project: calling it again with an existing name returns that release unchanged (created false), so it is safe to retry. status "released" records a past release (released_at = release_date, default today). notes are Markdown.')]
class CreateReleaseTool extends Tool
{
    use InteractsWithReleases;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->projectRules() + [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:5000',
            'start_date' => 'nullable|date_format:Y-m-d',
            'release_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'notes' => 'nullable|string|max:200000',
            'status' => 'nullable|in:unreleased,released',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'create', [Release::class, $project]);

            $name = trim($v['name']);
            $existing = $project->releases()->get()->first(fn (Release $r) => $this->sameName($r->name, $name));
            if ($existing) {
                $existing->setRelation('project', $project);

                return $this->result(false, $existing);
            }

            $fields = ['name' => $name] + array_intersect_key($v, array_flip(['description', 'start_date', 'release_date']));
            Validator::make($fields, ReleaseLifecycle::rules($project))->validate();
            app(LimitGate::class)->ensureContentRoom($project->workspace);

            $attributes = $fields + ['notes' => $this->notesHtml($v['notes'] ?? null, $project), 'created_by' => $user->id];
            if (($v['status'] ?? null) === Release::STATUS_RELEASED) {
                $attributes['release_date'] = $fields['release_date'] ?? today()->toDateString();
                $attributes += ['status' => Release::STATUS_RELEASED, 'released_at' => Carbon::parse($attributes['release_date'])->startOfDay()];
            }

            $release = $project->releases()->create($attributes);
            $release->setRelation('project', $project);

            return $this->result(true, $release->fresh()->setRelation('project', $project));
        });
    }

    private function result(bool $created, Release $release): Response|ResponseFactory
    {
        return Response::structured([
            'created' => $created,
            'release' => $this->releaseSummary($release),
            'location' => ResourceLocator::locate($release),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('false when a release with that name already existed (returned unchanged).'),
            'release' => $this->releaseSchema($schema),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectArgs($schema) + [
            'name' => $schema->string()->description('Release name, e.g. "v1.4.0" (≤ 100 chars, unique in the project).')->required(),
            'description' => $schema->string(),
            'start_date' => $schema->string()->description('YYYY-MM-DD'),
            'release_date' => $schema->string()->description('YYYY-MM-DD (planned, or actual for status released), not before start_date'),
            'notes' => $schema->string()->description('Release notes as Markdown.'),
            'status' => $schema->string()->enum(['unreleased', 'released'])->description('Default unreleased; released records an already shipped version.'),
        ];
    }
}
