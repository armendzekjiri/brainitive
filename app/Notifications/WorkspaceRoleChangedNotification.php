<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;

/** An admin changed your role in a workspace (category: workspace). */
class WorkspaceRoleChangedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $actor,
        public readonly string $oldRole,
        public readonly string $newRole,
    ) {}

    public function catalogType(): string
    {
        return 'workspace_role_changed';
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => "{$this->actor->name} made you {$this->newRole} in {$this->workspace->name}",
            'body' => "Your role changed from {$this->oldRole} to {$this->newRole}.",
            'url' => route('workspaces.show', $this->workspace->slug),
            'actor' => $this->actor,
            'project_name' => $this->workspace->name,
            'subject_key' => null,
        ];
    }
}
