<?php

namespace App\Notifications;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Support\ClientPortal\ClientPortalLinks;

/**
 * A client commented on a deliverable (v1.42.0, BRAI-73; category: reviews). To the publisher and
 * the project admins.
 */
class ClientCommentedNotification extends CatalogNotification
{
    public const TYPE = 'client_commented';

    public function __construct(
        public readonly ClientDeliverable $deliverable,
        public readonly ClientDeliverableComment $comment,
    ) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $project = $this->deliverable->portal?->project;

        return [
            'title' => "{$this->comment->author_name} commented on “{$this->deliverable->title}”",
            'body' => $this->comment->body,
            'url' => ClientPortalLinks::team($this->deliverable),
            'actor' => null,
            'project_name' => $project ? "{$project->name} · Client portal" : 'Client portal',
            'subject_key' => null,
        ];
    }
}
