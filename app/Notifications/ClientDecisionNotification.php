<?php

namespace App\Notifications;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableDecision;
use App\Support\ClientPortal\ClientPortalLinks;

/**
 * A client approved or requested changes on a deliverable (v1.42.0, BRAI-73; category: reviews).
 * To the publisher and the project admins. The client is a guest, so there is no actor avatar.
 */
class ClientDecisionNotification extends CatalogNotification
{
    public const TYPE = 'client_decision';

    public function __construct(
        public readonly ClientDeliverable $deliverable,
        public readonly ClientDeliverableDecision $decision,
    ) {}

    public function catalogType(): string
    {
        return self::TYPE;
    }

    protected function message(object $notifiable): array
    {
        $what = $this->decision->decision === ClientDeliverable::STATUS_APPROVED ? 'approved' : 'requested changes on';
        $project = $this->deliverable->portal?->project;

        return [
            'title' => "{$this->decision->identity_name} {$what} “{$this->deliverable->title}” (v{$this->decision->version})",
            'body' => $this->decision->note,
            'url' => ClientPortalLinks::team($this->deliverable),
            'actor' => null,
            'project_name' => $project ? "{$project->name} · Client portal" : 'Client portal',
            'subject_key' => null,
        ];
    }
}
