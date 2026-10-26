<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Release;
use App\Support\Legal\PublicOperator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Published release notes — only the reviewed snapshot (`published_notes`), name and date.
 * No issue keys, links or project internals.
 */
class PublicReleaseController extends Controller
{
    public function show(string $token): Response
    {
        $release = Release::with('project:id,name,changelog_token,workspace_id', 'project.workspace')->where('public_token', $token)->whereNotNull('published_at')->firstOrFail();

        return Inertia::render('Releases/Public', [
            'project' => ['name' => $release->project->name],
            'releases' => [$this->entry($release)],
            'changelogUrl' => $release->project->changelog_token ? route('changelog.public', $release->project->changelog_token) : null,
            'operator' => PublicOperator::for($release->project->workspace), // who publishes it (BRAI-156)
        ]);
    }

    public function changelog(string $token): Response
    {
        $project = Project::where('changelog_token', $token)->firstOrFail();
        $releases = $project->releases()->whereNotNull('published_at')->whereNotNull('public_token')
            ->orderByRaw('coalesce(release_date, published_at) desc')->orderByDesc('id')
            ->paginate(10);
        abort_if($releases->total() === 0, 404);

        return Inertia::render('Releases/Public', [
            'project' => ['name' => $project->name],
            'releases' => collect($releases->items())->map(fn (Release $r) => $this->entry($r)),
            'changelogUrl' => null,
            'pagination' => ['current' => $releases->currentPage(), 'last' => $releases->lastPage(), 'next' => $releases->nextPageUrl(), 'prev' => $releases->previousPageUrl()],
            'operator' => PublicOperator::for($project->workspace), // who publishes it (BRAI-156)
        ]);
    }

    /** @return array<string, mixed> */
    private function entry(Release $release): array
    {
        return [
            'name' => $release->name,
            'date' => ($release->release_date ?? $release->published_at)?->toDateString(),
            'html' => $release->published_notes,
            'url' => route('releases.public', $release->public_token),
        ];
    }
}
