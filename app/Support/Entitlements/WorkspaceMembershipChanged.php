<?php

namespace App\Support\Entitlements;

use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/** A member joined or left a workspace (v1.46.0). brainitive/cloud syncs the Paddle seat count from it. */
final class WorkspaceMembershipChanged
{
    use Dispatchable;

    public function __construct(public readonly Workspace $workspace) {}
}
