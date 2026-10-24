<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Workspace;

/** Someone you invited joined the workspace (category: workspace). */
class InvitationAcceptedNotification extends CatalogNotification
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $member,
        public readonly string $role,
    ) {}

    public function catalogType(): string
    {
        return 'invitation_accepted';
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => "{$this->member->name} joined {$this->workspace->name}",
            'body' => "Accepted your invitation as {$this->role}.",
            'url' => route('workspaces.edit', $this->workspace->slug),
            'actor' => $this->member,
            'project_name' => $this->workspace->name,
            'subject_key' => null,
        ];
    }
}
