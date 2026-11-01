<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppApi\InvitationResource;
use App\Http\Resources\AppApi\WorkspaceResource;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App API v1 — deep-linked workspace invitations (BRAI-386). The same rules as the web
 * (App\Http\Controllers\InvitationController): only the invited address accepts, expired ones
 * can't be, a workspace over its member limit blocks (402), a member just closes the invitation.
 */
class InvitationController extends Controller
{
    public function show(string $token): InvitationResource
    {
        return new InvitationResource($this->find($token));
    }

    /** Join the workspace → the workspace with the user's role. */
    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $user = $request->user();
        $workspace = $invitation->workspace;

        // already done (a retry, or accepted on the web): fine when the user is in the workspace
        if ($invitation->isAccepted()) {
            abort_unless($workspace->hasUser($user), 409, 'This invitation has already been accepted.');

            return (new WorkspaceResource($workspace))->response();
        }

        abort_if($invitation->isExpired(), 410, 'This invitation has expired.');
        abort_unless(
            $invitation->emailMatches($user),
            403,
            'This invitation was sent to a different email address. Sign in with the account that received it.',
        );

        $invitation->acceptBy($user); // LimitReached → 402 limit_reached

        return (new WorkspaceResource($workspace))->response();
    }

    private function find(string $token): WorkspaceInvitation
    {
        $invitation = WorkspaceInvitation::with(['workspace', 'inviter'])->where('token', $token)->first();
        // a soft-deleted workspace's invitation is as good as unknown
        abort_unless($invitation && $invitation->workspace, 404);

        return $invitation;
    }
}
