<?php

namespace App\Support\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Invite somebody to a workspace (Settings → Members on the web, App API `POST
 * /workspaces/{slug}/invitations`, BRAI-390). An existing account is added at once; for an
 * unknown address a pending invitation (30 days) is created or refreshed and mailed — the web asks
 * "send an invitation?" first, the apps ask in their own UI. The caller authorizes
 * (WorkspacePolicy::update).
 */
final class WorkspaceInvites
{
    public const ROLES = ['admin', 'editor', 'member', 'viewer'];

    public const MEMBER_ADDED = 'member_added';

    public const INVITATION_SENT = 'invitation_sent';

    /** No account with this address and the caller didn't confirm sending an invitation. */
    public const CONFIRM_REQUIRED = 'confirm_required';

    /**
     * @return array{result: string, email: string, role: string, user: User|null, invitation: WorkspaceInvitation|null}
     *
     * @throws ValidationException already a member (`email`)
     * @throws LimitReached the workspace is at its member limit
     */
    public static function invite(Workspace $workspace, User $inviter, string $email, string $role, bool $sendInvitation): array
    {
        $email = strtolower($email);
        $user = User::where('email', $email)->first();
        $result = fn (string $kind, ?WorkspaceInvitation $invitation = null) => [
            'result' => $kind, 'email' => $email, 'role' => $role, 'user' => $kind === self::MEMBER_ADDED ? $user : null, 'invitation' => $invitation,
        ];

        if ($user && $workspace->users()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This user is already a member of this workspace.']);
        }

        // Member limit (Cloud plans), before the "send invitation?" question: an open invitation to
        // this address is already counted
        app(LimitGate::class)->ensureCanAddMembers($workspace, $workspace->invitations()->forEmail($email)->valid()->exists() ? 0 : 1);

        if ($user) {
            $workspace->addUser($user, $role, $inviter);
            $workspace->invitations()->forEmail($email)->whereNull('accepted_at')->update(['accepted_at' => now()]);

            return $result(self::MEMBER_ADDED);
        }

        if (! $sendInvitation) {
            return $result(self::CONFIRM_REQUIRED);
        }

        // create or refresh a pending invitation (30 days) and send the email
        $invitation = $workspace->invitations()->forEmail($email)->valid()->first();

        if ($invitation) {
            $invitation->update(['role' => $role, 'expires_at' => now()->addDays(30)]);
        } else {
            $invitation = $workspace->invitations()->create([
                'email' => $email,
                'role' => $role,
                'invited_by' => $inviter->id,
            ]);
        }

        Notification::route('mail', $email)->notify(new WorkspaceInvitationNotification($invitation, $workspace, $inviter));

        return $result(self::INVITATION_SENT, $invitation);
    }
}
