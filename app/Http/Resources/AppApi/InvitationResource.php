<?php

namespace App\Http\Resources\AppApi;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace invitation as a deep-linked invite shows it in the app (GET /api/v1/invitations/{token}):
 * who invites whom to which workspace, and whether the signed-in user can accept it.
 *
 * @mixin WorkspaceInvitation
 */
class InvitationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $expired = $this->isExpired();
        $accepted = $this->isAccepted();
        $emailMatches = $user !== null && $this->emailMatches($user);
        $member = $user !== null && $this->workspace->hasUser($user);

        return [
            'email' => $this->email,
            'role' => $this->role,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'expired' => $expired,
            'accepted' => $accepted,
            'email_matches' => $emailMatches,
            'already_member' => $member,
            // a member accepting just closes the invitation (same as the web)
            'can_accept' => ! $expired && ! $accepted && $emailMatches,
            'workspace' => [
                'name' => $this->workspace->name,
                // only members learn the slug before accepting; the app opens it right away
                'slug' => $member ? $this->workspace->slug : null,
            ],
            'inviter' => ['name' => $this->inviter?->name],
        ];
    }
}
