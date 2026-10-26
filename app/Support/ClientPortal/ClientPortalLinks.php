<?php

namespace App\Support\ClientPortal;

use App\Models\ClientDeliverable;

/** Absolute links into the client portal for notifications and mails (v1.42.0, BRAI-73). */
final class ClientPortalLinks
{
    /** The team's page of a deliverable (decisions, comments). */
    public static function team(ClientDeliverable $deliverable): string
    {
        $project = $deliverable->portal?->project;
        $workspace = $project?->workspace;

        return $project && $workspace
            ? route('client-portal.manage.deliverables.show', [$workspace->slug, $project->slug, $deliverable->id])
            : url('/');
    }

    /** The client's page of a deliverable. */
    public static function client(ClientDeliverable $deliverable): string
    {
        return route('client-portal.deliverable', [$deliverable->portal->slug, $deliverable->uuid]);
    }
}
