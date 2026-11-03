<?php

namespace App\Http\Resources\AppApi;

use App\Http\Resources\AppApi\Concerns\PresentsWorkspaceLimits;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\LimitGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/workspaces/{slug}` (BRAI-388): one workspace with the user's role, what they may do,
 * the plan limits and usage (what the web's `entitlements` prop shows every member) and counts.
 * Settings (name, logo, members' roles, billing) are changed on the web only.
 *
 * @mixin Workspace
 */
class WorkspaceDetailResource extends JsonResource
{
    use PresentsWorkspaceLimits;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $workspace = $this->resource;
        $entitlements = app(Entitlements::class);
        $usage = $workspace->usage;

        return [
            ...(new WorkspaceResource($workspace))->toArray($request),
            'description' => $this->description,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'counts' => [
                // the projects this user can open (workspace admins: all), as on the web
                'projects' => Project::where('workspace_id', $workspace->id)->whereIn('id', $user->accessibleProjectIds())->count(),
                'members' => $workspace->users()->count(),
            ],
            'can' => [
                'update' => $user->can('update', $workspace),
                'invite_members' => $user->can('update', $workspace),
                'create_projects' => $user->can('createProjects', $workspace),
            ],
            'limits_enforced' => (bool) $entitlements->enforced(),
            'limits' => $this->limitsOf($workspace, $entitlements),
            'usage' => [
                // members + open invitations, as the member limit counts them
                'members' => LimitGate::memberCount($workspace),
                'projects' => $workspace->projects()->count(),
                'content_bytes' => (int) $usage?->content_bytes,
                'file_bytes' => (int) $usage?->file_bytes,
                'measured_at' => $usage?->measured_at?->toIso8601ZuluString(),
            ],
            // "Plan & billing" on the web; null on self-hosted installs
            'billing_url' => self::billingUrl($entitlements, $workspace),
        ];
    }

    private static function billingUrl(Entitlements $entitlements, Workspace $workspace): ?string
    {
        return $entitlements->billingUrl($workspace);
    }
}
