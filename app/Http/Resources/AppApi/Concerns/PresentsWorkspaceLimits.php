<?php

namespace App\Http\Resources\AppApi\Concerns;

use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;

/**
 * The Cloud plan limits of a workspace (Entitlements contract) in the App API shape, shared by
 * `GET /me` and `GET /workspaces/{slug}`. All null + `unlimited: true` on self-hosted installs.
 */
trait PresentsWorkspaceLimits
{
    /** @return array{plan_name: string, free: bool, unlimited: bool, members: int|null, projects: int|null, content_bytes: int|null, file_bytes: int|null, versions: array{keep_latest: int|null, keep_days: int|null}, grace_until: string|null} */
    protected function limitsOf(Workspace $workspace, Entitlements $entitlements): array
    {
        $limits = $entitlements->limits($workspace);

        return [
            'plan_name' => $limits->plan,
            'free' => $limits->free,
            'unlimited' => $limits->isUnlimited(),
            'members' => $limits->members,
            'projects' => $limits->projects,
            'content_bytes' => $limits->contentBytes,
            'file_bytes' => $limits->fileBytes,
            'versions' => [
                'keep_latest' => $limits->versions->keepLatest,
                'keep_days' => $limits->versions->keepDays,
            ],
            'grace_until' => $limits->graceUntil?->toIso8601ZuluString(),
        ];
    }
}
