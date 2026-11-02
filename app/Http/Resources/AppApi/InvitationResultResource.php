<?php

namespace App\Http\Resources\AppApi;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The outcome of `POST /workspaces/{slug}/invitations`: an existing account was added
 * (`member_added`, `member` set) or an invitation was mailed (`invitation_sent`, `expires_at` set).
 *
 * @property-read array{result: string, email: string, role: string, user: User|null, invitation: WorkspaceInvitation|null} $resource
 */
class InvitationResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];
        $invitation = $this->resource['invitation'];

        return [
            // member_added | invitation_sent
            'result' => (string) $this->resource['result'],
            'email' => (string) $this->resource['email'],
            'role' => (string) $this->resource['role'],
            'member' => $user instanceof User ? [
                'id' => (int) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_display,
            ] : null,
            // the invitation link works until then (30 days)
            'expires_at' => $invitation instanceof WorkspaceInvitation ? $invitation->expires_at?->toIso8601ZuluString() : null,
        ];
    }
}
