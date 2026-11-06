<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithReleases;
use App\Models\Release;
use App\Support\Releases\ReleaseLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the releases (fix versions) of a project: unreleased by release date, then released, then archived — with dates, issue progress and links.')]
class ListReleasesTool extends Tool
{
    use InteractsWithReleases;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->projectRules());
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'viewAny', [Release::class, $project]);

            return Response::structured([
                'releases' => ReleaseLifecycle::ordered($project)->get()
                    ->each(fn (Release $r) => $r->setRelation('project', $project))
                    ->map(fn (Release $r) => $this->releaseSummary($r))->values()->all(),
                'can_manage' => $user->can('create', [Release::class, $project]),
                'releases_url' => $this->releasesUrl($project),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'releases' => $schema->array()->items($this->releaseSchema($schema)),
            'can_manage' => $schema->boolean()->description('Whether you may create / change releases here.'),
            'releases_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectArgs($schema);
    }
}
