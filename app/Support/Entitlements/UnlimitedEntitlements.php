<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;

/** Self-hosted default: every workspace may use everything, and there is no billing. */
final class UnlimitedEntitlements implements Entitlements
{
    public function enforced(): bool
    {
        return false;
    }

    public function limits(Workspace $workspace): Limits
    {
        return Limits::unlimited();
    }

    public function freeWorkspacesPerOwner(): ?int
    {
        return null;
    }

    public function billingUrl(Workspace $workspace): ?string
    {
        return null;
    }
}
