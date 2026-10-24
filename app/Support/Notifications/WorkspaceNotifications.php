<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\InvitationAcceptedNotification;
use App\Notifications\WorkspaceRoleChangedNotification;

/** invitation_accepted (to the inviter) and workspace_role_changed (to the member). */
class WorkspaceNotifications
{
    /** Both accept paths: the invite link (InvitationController) and sign-up (acceptPendingForUser). */
    public static function invitationAccepted(WorkspaceInvitation $invitation, User $member): void
    {
        $inviter = $invitation->inviter;
        $workspace = $invitation->workspace;
        if (! $inviter || ! $workspace || $inviter->id === $member->id || ! $workspace->users()->where('users.id', $inviter->id)->exists()) {
            return;
        }

        $inviter->notify(new InvitationAcceptedNotification($workspace, $member, (string) $invitation->role));
    }

    public static function roleChanged(Workspace $workspace, User $member, User $actor, ?string $oldRole, string $newRole): void
    {
        if ($oldRole === $newRole || $member->id === $actor->id) {
            return;
        }

        $member->notify(new WorkspaceRoleChangedNotification($workspace, $actor, (string) $oldRole, $newRole));
    }
}
