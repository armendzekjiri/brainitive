<?php

namespace App\Http\Controllers;

use App\Models\WorkspaceInvitation;
use App\Support\Notifications\WorkspaceNotifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the invitation page (works for both guests and authenticated users).
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = WorkspaceInvitation::with(['workspace:id,name,slug', 'inviter:id,name'])
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return redirect()->route('login')
                ->with('error', 'This invitation link is invalid.');
        }

        if ($invitation->isExpired()) {
            return redirect()->route('login')
                ->with('error', 'This invitation has expired.');
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('workspaces.show', $invitation->workspace->slug)
                ->with('info', 'You have already accepted this invitation.');
        }

        $workspace = $invitation->workspace;
        $inviter = $invitation->inviter;
        $user = $request->user();

        // after signing up / in, come back here to accept (the link proves the address)
        if (! $user) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        // Authenticated user with matching email can accept
        $canAccept = $user && strtolower($user->email) === strtolower($invitation->email);

        return Inertia::render('Invitations/Show', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ],
            'inviter' => [
                'name' => $inviter->name,
            ],
            'canAccept' => $canAccept,
            'auth' => ['user' => $user ? ['email' => $user->email] : null],
        ]);
    }

    /**
     * Accept the invitation (authenticated user; email must match invitation).
     */
    public function accept(Request $request, string $token)
    {
        $user = $request->user();

        $invitation = WorkspaceInvitation::with(['workspace', 'inviter'])
            ->where('token', $token)
            ->first();

        if (! $invitation) {
            return redirect()->route('workspaces.index')
                ->with('error', 'This invitation link is invalid.');
        }

        if ($invitation->isExpired()) {
            return redirect()->route('workspaces.index')
                ->with('error', 'This invitation has expired.');
        }

        if ($invitation->isAccepted()) {
            return redirect()->route('workspaces.show', $invitation->workspace->slug)
                ->with('info', 'You have already accepted this invitation.');
        }

        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return redirect()->back()
                ->with('error', 'This invitation was sent to a different email address. Please sign in with the account that received the invitation.');
        }

        $invitation->markAsAccepted();
        $invitation->workspace->addUser($user, $invitation->role, $invitation->inviter ?? null);
        WorkspaceNotifications::invitationAccepted($invitation, $user);

        return redirect()->route('workspaces.show', $invitation->workspace->slug)
            ->with('success', "You've joined {$invitation->workspace->name}.");
    }
}
