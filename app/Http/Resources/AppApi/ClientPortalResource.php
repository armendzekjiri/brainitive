<?php

namespace App\Http\Resources\AppApi;

use App\Models\ClientPortal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's client portal for the team (read): its address and the user's rights. Clients, settings,
 * publishing and the client's own side: web only.
 *
 * @mixin ClientPortal
 */
class ClientPortalResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $project = $this->project;

        return [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'title' => (string) $this->title,
            'slug' => (string) $this->slug,
            // false: clients can't open it
            'enabled' => (bool) $this->enabled,
            // the clients' sign-in page
            'public_url' => $this->publicUrl(),
            // the team page on the web (clients, publishing, settings)
            'url' => route('client-portal.manage.index', [$project->workspace->slug, $project->slug]),
            'can' => [
                // publish / withdraw deliverables, answer clients (project admins and editors)
                'publish' => $request->user()->can('publish', [ClientPortal::class, $project]),
                // settings and clients (project admins)
                'manage' => $request->user()->can('manage', [ClientPortal::class, $project]),
            ],
        ];
    }
}
