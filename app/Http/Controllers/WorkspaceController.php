<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
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
                $query->withCount('boards')->latest();
            },
            'users:id,name,email',
            'owner:id,name,email',
        ]);

        $userRole = $request->user()->isInstanceAdmin()
            ? 'admin'
            : $request->user()->workspaceRole($workspace);

        return Inertia::render('Workspaces/Show', [
            'workspace' => $workspace,
            'userRole' => $userRole,
        ]);
    }

    /**
     * Show the form for editing the specified workspace.
     */
    public function edit(Request $request, Workspace $workspace): Response
    {
        $this->authorize('update', $workspace);

        $workspace->load(['users:id,name,email', 'owner:id,name,email']);

        $userRole = $request->user()->isInstanceAdmin()
            ? 'admin'
            : $request->user()->workspaceRole($workspace);

        return Inertia::render('Workspaces/Edit', [
            'workspace' => $workspace,
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
        ]);
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
            'logo' => 'nullable|image|max:2048',
            'header_image' => 'nullable|image|max:4096',
            'social_links' => 'nullable|array',
            'social_links.twitter' => 'nullable|url|max:255',
            'social_links.linkedin' => 'nullable|url|max:255',
            'social_links.github' => 'nullable|url|max:255',
            'branding' => 'nullable|array',
            'branding.primary_color' => 'nullable|string|max:20',
            'branding.secondary_color' => 'nullable|string|max:20',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('workspaces/logos', 'public');
            $validated['logo'] = $logoPath;
        }

        // Handle header image upload
        if ($request->hasFile('header_image')) {
            $headerPath = $request->file('header_image')->store('workspaces/headers', 'public');
            $validated['header_image'] = $headerPath;
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
     */
    public function inviteMember(Request $request, Workspace $workspace)
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,editor,member,viewer',
        ]);

        // Find user by email
        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return redirect()->back()
                ->withErrors(['email' => 'No user found with this email address.']);
        }

        // Check if user is already a member
        if ($workspace->users()->where('user_id', $user->id)->exists()) {
            return redirect()->back()
                ->withErrors(['email' => 'This user is already a member of this workspace.']);
        }

        // Add user to workspace
        $workspace->addUser($user, $validated['role']);

        return redirect()->back()
            ->with('success', "Successfully invited {$user->name} to the workspace.");
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
}
