<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Determine whether the user can view the ticket.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->hasProjectAccess($ticket->helpdesk->project);
    }

    /**
     * Determine whether the user can update the ticket.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        $project = $ticket->helpdesk->project;

        // Workspace admin can update any ticket
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin or editor can update
        $projectRole = $user->projectRole($project);
        return in_array($projectRole, ['admin', 'editor']);
    }

    /**
     * Determine whether the user can delete the ticket.
     */
    public function delete(User $user, Ticket $ticket): bool
    {
        $project = $ticket->helpdesk->project;

        // Workspace admin can delete any ticket
        $workspaceRole = $user->workspaceRole($project->workspace);
        if ($workspaceRole === 'admin') {
            return true;
        }

        // Project admin can delete
        $projectRole = $user->projectRole($project);
        if ($projectRole === 'admin') {
            return true;
        }

        // Agent can delete their own tickets (if they created them)
        // Note: Ticket doesn't have created_by field, but we could check assigned_to
        return $ticket->assigned_to === $user->id;
    }

    /**
     * Determine whether the user can move the ticket.
     */
    public function move(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    /**
     * Determine whether the user can assign the ticket.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    /**
     * Determine whether the user can reply to the ticket.
     */
    public function reply(User $user, Ticket $ticket): bool
    {
        // Anyone with view access can reply (agents)
        return $user->hasProjectAccess($ticket->helpdesk->project);
    }

    /**
     * Determine whether the user can add internal notes.
     */
    public function addInternalNote(User $user, Ticket $ticket): bool
    {
        // Only project admin or editor can add internal notes
        $project = $ticket->helpdesk->project;
        $projectRole = $user->projectRole($project);
        return in_array($projectRole, ['admin', 'editor']);
    }
}