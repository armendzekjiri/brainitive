<?php

namespace App\Http\Controllers;

use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Project settings → Integrations → GitHub: create, rotate the secret of, or remove the
 * project's webhook connection. Project admins only (same as other project settings).
 */
class ProjectGithubController extends Controller
{
    public function store(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->githubConnection()->firstOrCreate([], [
            'uuid' => (string) Str::uuid(),
            'secret' => GithubConnection::newSecret(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'GitHub connection created. Add the webhook to your repository.');
    }

    public function rotate(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        $project->githubConnection()->firstOrFail()->update(['secret' => GithubConnection::newSecret()]);

        return back()->with('success', 'New secret created. Update it in every connected repository’s webhook.');
    }

    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('update', $project);

        // Cascades to deliveries and every linked branch / commit / PR / deployment.
        $project->githubConnection()->delete();

        return back()->with('success', 'GitHub disconnected. Linked development info was removed.');
    }

    /**
     * Settings page data; only for people who may manage the project.
     *
     * @return array<string, mixed>|null
     */
    public static function settingsFor(Project $project): ?array
    {
        $connection = $project->githubConnection;
        if (! $connection) {
            return null;
        }

        return [
            'webhook_url' => $connection->webhookUrl(),
            'secret' => $connection->secret,
            'repositories' => $connection->repositories ?? [],
            'last_delivery_at' => $connection->last_delivery_at?->toIso8601String(),
            'last_event' => $connection->last_event,
            'last_error' => $connection->last_error,
            'linked_issues' => $connection->links()->distinct('item_id')->count('item_id'),
        ];
    }
}
