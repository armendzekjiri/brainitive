<?php

namespace App\Notifications;

use App\Models\Workspace;

/**
 * To the workspace admins when a public form (helpdesk widget, lead form, feedback portal) turned a submission
 * away because the workspace is at its plan's content limit (v1.49.0, Codex web review B; category: workspace).
 * At most one per workspace per day (PublicContentRoom), so a busy form can't flood anyone.
 */
class PlanLimitReachedNotification extends CatalogNotification
{
    public const TYPE = 'plan_limit_reached';

    public function __construct(public readonly Workspace $workspace, public readonly string $source) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        return [
            'title' => 'A '.$this->source.' submission was turned away: '.$this->workspace->name.' has used its content storage',
            'body' => 'Public forms can’t accept new submissions until there is room again. Upgrade the plan or free up space.',
            'url' => route('workspaces.edit', $this->workspace->slug),
            'actor' => null,
            'project_name' => $this->workspace->name,
            'subject_key' => null,
        ];
    }
}
