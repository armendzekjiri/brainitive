<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\Release;
use App\Support\Boards\IssueMarkdown;
use App\Support\Mcp\ToolError;
use App\Support\SafeHtml;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Shared bits of the MCP release tools: resolve a release of a project and describe releases the same way
 * everywhere. Rules for changing them live in `App\Support\Releases\ReleaseLifecycle` (shared with the web).
 */
trait InteractsWithReleases
{
    use InteractsWithBrainitive;

    /**
     * @throws ToolError
     */
    protected function releaseOf(Project $project, int $releaseId): Release
    {
        $release = $project->releases()->find($releaseId);
        if (! $release) {
            throw new ToolError('No release with that id in this project. Call list_releases.');
        }
        $release->setRelation('project', $project);

        return $release;
    }

    /** Release notes Markdown (with `@[Name](user:<id>)` mentions) → the sanitized HTML the web editor stores. */
    protected function notesHtml(?string $markdown, Project $project): ?string
    {
        $html = IssueMarkdown::toHtml($markdown, $project);

        return $html === null ? null : SafeHtml::clean($html);
    }

    /**
     * @return array<string, mixed>
     */
    protected function releaseSummary(Release $release): array
    {
        $project = $release->project;
        $progress = $release->progress();

        return [
            'id' => $release->id,
            'name' => $release->name,
            'status' => $release->status,
            'description' => $release->description ?? '',
            'start_date' => $release->start_date?->toDateString() ?? '',
            'release_date' => $release->release_date?->toDateString() ?? '',
            'released_at' => $release->released_at?->toIso8601String() ?? '',
            'overdue' => $release->isOverdue(),
            'progress' => [
                'total' => (int) $progress['total'],
                'done' => (int) $progress['done'],
                'in_progress' => (int) $progress['in_progress'],
                'todo' => (int) $progress['todo'],
            ],
            'public_url' => $release->isPublished() ? route('releases.public', $release->public_token) : '',
            'url' => route('releases.show', [$project->workspace->slug, $project->slug, $release->id]),
        ];
    }

    protected function releaseSchema(JsonSchema $schema)
    {
        return $schema->object([
            'id' => $schema->integer(),
            'name' => $schema->string(),
            'status' => $schema->string()->description('unreleased | released | archived'),
            'description' => $schema->string(),
            'start_date' => $schema->string(),
            'release_date' => $schema->string(),
            'released_at' => $schema->string(),
            'overdue' => $schema->boolean()->description('Unreleased and past its release date.'),
            'progress' => $schema->object([
                'total' => $schema->integer(),
                'done' => $schema->integer(),
                'in_progress' => $schema->integer(),
                'todo' => $schema->integer(),
            ]),
            'public_url' => $schema->string()->description('The published release notes page; empty when not published.'),
            'url' => $schema->string(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    protected function projectArgs(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
        ];
    }

    protected function projectRules(): array
    {
        return ['workspace' => 'required|string', 'project' => 'required|string'];
    }

    protected function releasesUrl(Project $project): string
    {
        return route('releases.index', [$project->workspace->slug, $project->slug]);
    }
}
