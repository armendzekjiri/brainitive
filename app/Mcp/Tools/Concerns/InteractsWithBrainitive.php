<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Shared helpers for MCP tools: resolve a workspace / project by slug while
 * enforcing that the authenticated user may access it, and run policy checks.
 *
 * Authorization reuses the app's existing access helpers
 * (User::hasWorkspaceAccess / hasProjectAccess) and Gate policies, so the AI
 * can never touch anything the signed-in user couldn't touch in the UI.
 */
trait InteractsWithBrainitive
{
    /**
     * Resolve a workspace by slug, ensuring the user can access it.
     *
     * @throws AuthorizationException
     */
    protected function workspaceForUser(Authenticatable $user, string $slug): Workspace
    {
        $workspace = Workspace::where('slug', $slug)->first();

        if (! $workspace || ! $user->hasWorkspaceAccess($workspace)) {
            throw new AuthorizationException("You do not have access to a workspace named \"{$slug}\".");
        }

        return $workspace;
    }

    /**
     * Resolve a project (by slug) within a workspace, ensuring access.
     *
     * @throws AuthorizationException
     */
    protected function projectForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug): Project
    {
        $workspace = $this->workspaceForUser($user, $workspaceSlug);

        $project = $workspace->projects()->where('slug', $projectSlug)->first();

        if (! $project || ! $user->hasProjectAccess($project)) {
            throw new AuthorizationException("You do not have access to a project named \"{$projectSlug}\".");
        }

        return $project;
    }

    /**
     * Authorize a policy ability for the user, throwing on failure.
     *
     * @throws AuthorizationException
     */
    protected function authorizeFor(Authenticatable $user, string $ability, mixed $arguments): void
    {
        $response = Gate::forUser($user)->inspect($ability, $arguments);

        if ($response->denied()) {
            throw new AuthorizationException($response->message() ?: 'This action is not allowed for your account.');
        }
    }
}
