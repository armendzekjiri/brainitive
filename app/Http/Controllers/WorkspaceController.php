<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Display a listing of the user's workspaces.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Instance admins see all workspaces
        if ($user->isInstanceAdmin()) {
            $workspaces = Workspace::withCount(['projects', 'users'])
                ->with('owner:id,name,email')
                ->latest()
                ->get();
        } else {
            $workspaces = $user->workspaces()
                ->withCount(['projects', 'users'])
                ->with('owner:id,name,email')
                ->latest()
                ->get();
        }

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
            'isInstanceAdmin' => $user->isInstanceAdmin(),
        ]);
    }

    /**
     * Show the form for creating a new workspace.
     */
    public function create(): Response
    {
        return Inertia::render('Workspaces/Create');
    }

    /**
     * Store a newly created workspace.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $workspace = Workspace::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'owner_id' => $request->user()->id,
        ]);

        // Add the creator as an admin
        $workspace->addUser($request->user(), 'admin');

        return redirect()->route('workspaces.show', $workspace)
            ->with('success', 'Workspace created successfully.');
    }

    /**
     * Display the specified workspace.
     */
    public function show(Request $request, Workspace $workspace): Response
    {
        $this->authorize('view', $workspace);

        $workspace->load([
            'projects' => function ($query) {
                $query->withCount(['boards', 'pages'])->latest();
            },
            'bookmarks' => function ($query) {
                $query->limit(6)->orderBy('position');
            },
            'snippetCollections' => function ($query) {
                $query->withCount('snippets')->limit(6);
            },
            'users:id,name,email',
            'owner:id,name,email',
        ]);

        // Only show projects the user has access to
        $workspace->setRelation(
            'projects',
            $workspace->projects->filter(fn ($project) => $request->user()->hasProjectAccess($project))->values()
        );

        $userRole = $request->user()->isInstanceAdmin()
            ? 'admin'
            : $request->user()->workspaceRole($workspace);

        return Inertia::render('Workspaces/Show', [
            'workspace' => $workspace,
            'userRole' => $userRole,
        ]);
    }

    /**
     * Return workspace members as JSON (for assignee dropdowns, etc.).
     */
    public function users(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        $users = $workspace->users()->get(['users.id', 'name', 'email', 'avatar']);

        return response()->json($users);
    }

    /**
     * Build the props array for the Workspaces/Edit page (shared by edit() and inviteMember when returning confirm step).
     */
    private function editPageProps(Request $request, Workspace $workspace): array
    {
        $workspace->load(['users:id,name,email', 'owner:id,name,email']);

        $pendingInvitations = $workspace->invitations()
            ->valid()
            ->with('inviter:id,name')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'email' => $inv->email,
                'role' => $inv->role,
                'expires_at' => $inv->expires_at->toIso8601String(),
                'created_at' => $inv->created_at->toIso8601String(),
                'inviter' => $inv->inviter ? ['id' => $inv->inviter->id, 'name' => $inv->inviter->name] : null,
            ]);

        $userRole = $request->user()->isInstanceAdmin()
            ? 'admin'
            : $request->user()->workspaceRole($workspace);

        return [
            'workspace' => $workspace,
            'pendingInvitations' => $pendingInvitations,
            'userRole' => $userRole,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'industries' => [
                'technology' => 'Technology',
                'healthcare' => 'Healthcare',
                'finance' => 'Finance & Banking',
                'education' => 'Education',
                'retail' => 'Retail & E-commerce',
                'manufacturing' => 'Manufacturing',
                'marketing' => 'Marketing & Advertising',
                'consulting' => 'Consulting',
                'real_estate' => 'Real Estate',
                'entertainment' => 'Entertainment & Media',
                'nonprofit' => 'Non-Profit',
                'government' => 'Government',
                'other' => 'Other',
            ],
            'companySizes' => [
                '1-10' => '1-10 employees',
                '11-50' => '11-50 employees',
                '51-200' => '51-200 employees',
                '201-500' => '201-500 employees',
                '501-1000' => '501-1000 employees',
                '1001-5000' => '1001-5000 employees',
                '5001+' => '5001+ employees',
            ],
        ];
    }

    /**
     * Show the form for editing the specified workspace.
     */
    public function edit(Request $request, Workspace $workspace): Response
    {
        $this->authorize('update', $workspace);

        return Inertia::render('Workspaces/Edit', $this->editPageProps($request, $workspace));
    }

    /**
     * Update the specified workspace.
     */
    public function update(Request $request, Workspace $workspace)
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:255',
            'industry' => 'nullable|string|max:100',
            'company_size' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
            'currency' => 'nullable|string|max:10',
            'is_public' => 'nullable|boolean',
            'logo' => 'nullable|file|image|max:2048',
            'header_image' => 'nullable|file|image|max:4096',
            'social_links' => 'nullable|array',
            'social_links.twitter' => 'nullable|url|max:255',
            'social_links.linkedin' => 'nullable|url|max:255',
            'social_links.github' => 'nullable|url|max:255',
            'branding' => 'nullable|array',
            'branding.primary_color' => 'nullable|string|max:20',
            'branding.secondary_color' => 'nullable|string|max:20',
        ]);

        // Only update logo/header when a new file was uploaded; never overwrite with null
        if (! $request->hasFile('logo')) {
            unset($validated['logo']);
        } else {
            $validated['logo'] = $request->file('logo')->store('workspaces/logos', 'public');
        }

        if (! $request->hasFile('header_image')) {
            unset($validated['header_image']);
        } else {
            $validated['header_image'] = $request->file('header_image')->store('workspaces/headers', 'public');
        }

        $workspace->update($validated);

        return redirect()->back()
            ->with('success', 'Workspace updated successfully.');
    }

    /**
     * Remove the specified workspace.
     */
    public function destroy(Workspace $workspace)
    {
        $this->authorize('delete', $workspace);

        $workspace->delete();

        return redirect()->route('workspaces.index')
            ->with('success', 'Workspace deleted successfully.');
    }

    /**
     * Invite a member to the workspace.
     * If the email belongs to an existing user, add them. If not, ask for confirmation then create a pending invitation (30 days) and send an email.
     */
    public function inviteMember(Request $request, Workspace $workspace)
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,editor,member,viewer',
            'confirm_send_invitation' => 'sometimes|boolean',
        ]);

        $email = strtolower($validated['email']);
        $user = User::where('email', $email)->first();

        if ($user) {
            // Existing user: add to workspace if not already a member
            if ($workspace->users()->where('user_id', $user->id)->exists()) {
                return redirect()->back()
                    ->withErrors(['email' => 'This user is already a member of this workspace.']);
            }
            $workspace->addUser($user, $validated['role'], $request->user());

            return redirect()->back()
                ->with('success', "{$user->name} has been added to the workspace.")
                ->with('invite_result', 'member_added');
        }

        // No user with this email: return 409 so frontend can show "send invitation?" modal without redirect
        if (! ($validated['confirm_send_invitation'] ?? false)) {
            return response()->json([
                'confirm_required' => true,
                'email' => $email,
                'role' => $validated['role'],
            ], 409);
        }

        // Confirmed: create or refresh a pending invitation (30 days) and send email
        $invitation = $workspace->invitations()
            ->forEmail($email)
            ->valid()
            ->first();

        if ($invitation) {
            $invitation->update(['role' => $validated['role'], 'expires_at' => now()->addDays(30)]);
        } else {
            $invitation = $workspace->invitations()->create([
                'email' => $email,
                'role' => $validated['role'],
                'invited_by' => $request->user()->id,
            ]);
        }

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitationNotification($invitation, $workspace, $request->user()));

        return redirect()->back()
            ->with('success', "An invitation has been sent to {$email}. If they sign up within 30 days, they'll join this workspace automatically.")
            ->with('invite_result', 'invitation_sent');
    }

    /**
     * Update a member's role in the workspace.
     */
    public function updateMemberRole(Request $request, Workspace $workspace, User $user)
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'role' => 'required|in:admin,editor,member,viewer',
        ]);

        // Don't allow changing owner's role
        if ($user->id === $workspace->owner_id) {
            return redirect()->back()
                ->withErrors(['role' => 'Cannot change the owner\'s role.']);
        }

        $workspace->users()->updateExistingPivot($user->id, [
            'role' => $validated['role'],
        ]);

        return redirect()->back()
            ->with('success', "Updated {$user->name}'s role to {$validated['role']}.");
    }

    /**
     * Remove a member from the workspace.
     */
    public function removeMember(Request $request, Workspace $workspace, User $user)
    {
        $this->authorize('update', $workspace);

        // Don't allow removing the owner
        if ($user->id === $workspace->owner_id) {
            return redirect()->back()
                ->withErrors(['user' => 'Cannot remove the workspace owner.']);
        }

        $workspace->users()->detach($user->id);

        return redirect()->back()
            ->with('success', "Removed {$user->name} from the workspace.");
    }

    /**
     * Cancel a pending workspace invitation.
     */
    public function cancelInvitation(Request $request, Workspace $workspace, WorkspaceInvitation $invitation)
    {
        $this->authorize('update', $workspace);

        if ($invitation->workspace_id !== $workspace->id) {
            abort(404);
        }

        if ($invitation->accepted_at !== null) {
            return redirect()->back()
                ->with('error', 'This invitation has already been accepted.');
        }

        $email = $invitation->email;
        $invitation->delete();

        return redirect()->back()
            ->with('success', "Invitation to {$email} has been cancelled.");
    }
}
