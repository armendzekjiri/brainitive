<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;

/**
 * The `entitlements` shared prop for the current workspace (v1.46.0): plan, limits, usage and the
 * billing link. Null when the workspace is unlimited and there is no billing (self-hosted).
 */
final class EntitlementsPayload
{
    /** @return array<string, mixed>|null */
    public static function for(?Workspace $workspace): ?array
    {
        if (! $workspace) {
            return null;
        }

        $entitlements = app(Entitlements::class);
        $limits = $entitlements->limits($workspace);
        $billingUrl = $entitlements->billingUrl($workspace);
        if ($limits->isUnlimited() && $billingUrl === null) {
            return null;
        }

        $usage = $workspace->usage;

        return [
            'enforced' => $entitlements->enforced(),
            'limits' => $limits->toArray(),
            'usage' => [
                'members' => LimitGate::memberCount($workspace),
                'projects' => $workspace->projects()->count(),
                'contentBytes' => (int) $usage?->content_bytes,
                'fileBytes' => (int) $usage?->file_bytes,
                'measuredAt' => $usage?->measured_at?->toIso8601String(),
            ],
            'billingUrl' => $billingUrl,
        ];
    }
}
