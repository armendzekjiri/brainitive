<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\Project;
use App\Models\User;

/**
 * Pages follow the project roles (same rules as boards). Unpublished pages are private to
 * their author and admins, like Confluence drafts; folders are always visible.
 */
class PagePolicy
{
    /**
     * Determine whether the user can see the page.
     */
    public function view(User $user, Page $page): bool
    {
        $project = $page->project;

        if (! $user->hasProjectAccess($project)) {
            return false;
        }

        if ($page->isFolder() || ! $page->isDraft()) {
            return true;
        }

        return $page->created_by === $user->id || $this->isAdmin($user, $project);
    }

    /**
     * Determine whether the user can create pages in the project.
     */
    public function create(User $user, Project $project): bool
    {
        return $this->canEdit($user, $project);
    }

    /**
     * Determine whether the user can edit, publish or restore the page.
     */
    public function update(User $user, Page $page): bool
    {
        return $this->canEdit($user, $page->project) && $this->view($user, $page);
    }

    /**
     * Determine whether the user can delete the page.
     */
    public function delete(User $user, Page $page): bool
    {
        if ($this->isAdmin($user, $page->project)) {
            return true;
        }

        return $page->created_by === $user->id && $this->update($user, $page);
    }

    /**
     * Workspace admin/owner or project admin.
     */
    private function isAdmin(User $user, Project $project): bool
    {
        $workspace = $project->workspace;

        if ($workspace->owner_id === $user->id || $user->workspaceRole($workspace) === 'admin') {
            return true;
        }

        return $user->projectRole($project) === 'admin';
    }

    /**
     * Workspace admin/owner or project admin/editor.
     */
    private function canEdit(User $user, Project $project): bool
    {
        if ($this->isAdmin($user, $project)) {
            return true;
        }

        return $user->projectRole($project) === 'editor';
    }
}
