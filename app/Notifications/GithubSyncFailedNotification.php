<?php

namespace App\Notifications;

use App\Models\GithubConnection;
use Illuminate\Support\Str;

/**
 * To the project admins when the project's GitHub connection lost access — the GitHub App was
 * uninstalled or suspended, or a followed repository was removed from the installation (v1.42.0,
 * BRAI-61; category: workspace). Sent once per loss (GithubConnection::access_lost_at).
 */
class GithubSyncFailedNotification extends CatalogNotification
{
    public const TYPE = 'github_sync_failed';

    public function __construct(public readonly GithubConnection $githubConnection, public readonly string $reason) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $project = $this->githubConnection->project;
        $workspace = $project?->workspace;

        return [
            'title' => 'GitHub stopped syncing for '.Str::limit((string) ($project?->name ?? 'a project'), 80),
            'body' => $this->reason,
            'url' => $project && $workspace ? route('projects.edit', [$workspace->slug, $project->slug]).'?tab=integrations' : url('/'),
            'actor' => null,
            'project_name' => $project?->name,
            'subject_key' => null,
        ];
    }
}
