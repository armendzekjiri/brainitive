<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;

/**
 * Plan limits for Brainitive Cloud (v1.46.0). The public app only asks this contract; the default
 * binding (UnlimitedEntitlements) allows everything, so self-hosted installs have no limits.
 * The private brainitive/cloud package binds its own implementation (Paddle subscriptions).
 * Checks go through LimitGate, never through this contract directly.
 */
interface Entitlements
{
    /** False until paid plans launch: limits are shown, nothing is blocked. */
    public function enforced(): bool;

    public function limits(Workspace $workspace): Limits;

    /** How many free-plan workspaces one user may own; null = no limit. */
    public function freeWorkspacesPerOwner(): ?int;

    /** "Plan & billing" page of the workspace; null hides every billing link. */
    public function billingUrl(Workspace $workspace): ?string;
}
