<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithReleases;
use App\Support\Boards\IssueMarkdown;
use App\Support\Releases\ReleaseLifecycle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read one release: dates, progress, release notes (Markdown) and its issues (key, title, state done / in_progress / todo, column; up to 500, by key).')]
class GetReleaseTool extends Tool
{
    use InteractsWithReleases;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->projectRules() + ['release_id' => 'required|integer']);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $release = $this->releaseOf($project, (int) $v['release_id']);
            $this->authorizeFor($user, 'view', $release);
            $workspace = $project->workspace;

            return Response::structured([
                'release' => $this->releaseSummary($release),
                'notes' => IssueMarkdown::toMarkdown($release->notes) ?? '',
                'notes_changed_since_published' => $release->isPublished() && $release->notes !== $release->published_notes,
                'issues' => ReleaseLifecycle::itemsWithState($release)->map(fn (array $row) => [
                    'key' => $row['item']->key ?? '',
                    'title' => $row['item']->title,
                    'state' => $row['state'],
                    'column' => $row['item']->column?->name ?? '',
                    'is_subtask' => $row['item']->parent_id !== null,
                    'url' => $row['item']->board ? route('boards.items.show', [$workspace->slug, $project->slug, $row['item']->board->slug, $row['item']->id]) : '',
                ])->values()->all(),
                'can_manage' => $user->can('update', $release),
            ]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'release' => $this->releaseSchema($schema),
            'notes' => $schema->string()->description('The release notes as Markdown (working copy; empty when none).'),
            'notes_changed_since_published' => $schema->boolean(),
            'issues' => $schema->array()->items($schema->object([
                'key' => $schema->string(),
                'title' => $schema->string(),
                'state' => $schema->string()->description('done | in_progress | todo'),
                'column' => $schema->string(),
                'is_subtask' => $schema->boolean(),
                'url' => $schema->string(),
            ])),
            'can_manage' => $schema->boolean(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->projectArgs($schema) + [
            'release_id' => $schema->integer()->description('Release id from list_releases.')->required(),
        ];
    }
}
