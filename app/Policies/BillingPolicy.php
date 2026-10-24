<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

/**
 * Client billing (v1.38.0): rates, amounts, clients, the team time overview and reports are for
 * workspace admins only. Members log and see their own hours, never prices.
 * Use Gate::authorize('manageBilling', $workspace) — registered in AppServiceProvider.
 */
class BillingPolicy
{
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $user->workspaceRole($workspace) === 'admin';
    }
}
