<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Board;
use App\Models\Item;
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
     * Resolve a board (by slug) inside a project the user can open.
     *
     * @throws AuthorizationException
     */
    protected function boardForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug, string $boardSlug): Board
    {
        $project = $this->projectForUser($user, $workspaceSlug, $projectSlug);
        $board = $project->boards()->where('slug', $boardSlug)->first();

        if (! $board) {
            throw new AuthorizationException("No board named \"{$boardSlug}\" in this project.");
        }

        return $board;
    }

    /**
     * Resolve an item by id that must sit on that board (no foreign ids).
     *
     * @throws AuthorizationException
     */
    protected function boardItemForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug, string $boardSlug, int $itemId): Item
    {
        $board = $this->boardForUser($user, $workspaceSlug, $projectSlug, $boardSlug);
        $item = $board->items()->find($itemId);

        if (! $item) {
            throw new AuthorizationException('No item with that id on this board. Call get_board to see item ids.');
        }

        return $item;
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

    /**
     * Resolve an assignee reference to a user id. Accepts the literal "me"
     * (the signed-in user) or the email of a member of the project's workspace.
     * Returns null for an empty reference.
     *
     * @throws AuthorizationException
     */
    protected function resolveAssigneeId(Authenticatable $user, Project $project, ?string $assignee): ?int
    {
        $assignee = $assignee !== null ? trim($assignee) : null;
        if ($assignee === null || $assignee === '') {
            return null;
        }

        if (strtolower($assignee) === 'me') {
            return $user->id;
        }

        $member = $project->workspace->users()->where('email', $assignee)->first();
        if (! $member) {
            throw new AuthorizationException("No workspace member found with email \"{$assignee}\". Use list_project_members to see who can be assigned, or pass \"me\".");
        }

        return $member->id;
    }
}
