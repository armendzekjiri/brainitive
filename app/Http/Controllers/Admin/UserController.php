<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\InstanceAdmin;
use App\Models\Project;
use App\Models\User;
use App\Support\Admin\UserDeletion;
use App\Support\SignOutEverywhere;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account management for instance admins. Exposes account metadata and counts only —
 * never the content of a user's workspaces.
 */
class UserController extends Controller
{
    private const FILTERS = ['all', 'admins', 'suspended', 'unverified'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $adminIds = InstanceAdmin::pluck('user_id');

        $users = User::query()
            ->select(['id', 'name', 'email', 'avatar', 'email_verified_at', 'suspended_at', 'last_login_at', 'created_at', 'google_id', 'github_id'])
            ->withCount(['workspaces', 'ownedWorkspaces'])
            ->addSelect(['projects_count' => $this->accessibleProjectsCount()])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->when($filter === 'admins', fn ($q) => $q->whereIn('id', $adminIds))
            ->when($filter === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($filter === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => $this->row($user, $adminIds->contains($user->id)));

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => ['q' => $search, 'filter' => $filter],
        ]);
    }

    /**
     * Drawer details: the user's workspaces (name, role, project count) and what deleting
     * them would change.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $workspaces = $user->workspaces()
            ->withCount(['projects', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn ($workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $role = ($workspace->owner_id === $user->id ? 'owner' : $workspace->pivot->role),
                'all_projects' => in_array($role, ['owner', 'admin'], true),
                'projects_count' => $workspace->projects_count,
                'members_count' => $workspace->users_count,
                'project_memberships' => DB::table('project_user')
                    ->join('projects', 'projects.id', '=', 'project_user.project_id')
                    ->where('project_user.user_id', $user->id)
                    ->where('projects.workspace_id', $workspace->id)
                    ->count(),
            ]);

        return response()->json([
            'workspaces' => $workspaces,
            'delete_blockers' => UserDeletion::blockers($user, $request->user()),
            'delete_impact' => UserDeletion::impact($user),
            'has_api_tokens' => $user->tokens()->where('revoked', false)->exists(),
        ]);
    }

    /**
     * Create an account (useful while sign-up is closed). The user sets their own password
     * through a reset link — the admin never chooses one.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'send_email' => ['boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => str()->random(64),
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        AdminAuditLog::record('user.created', $user);

        if ($request->boolean('send_email')) {
            Password::sendResetLink(['email' => $user->email]);

            return response()->json(['message' => "Account created. A link to set a password was emailed to {$user->email}."]);
        }

        return response()->json($this->resetLink($user) + ['message' => 'Account created. Share this link so they can set a password.']);
    }

    public function sendPasswordResetEmail(User $user): RedirectResponse
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->with('error', __($status));
        }

        AdminAuditLog::record('user.password_reset_emailed', $user);

        return back()->with('success', "Password reset email sent to {$user->email}.");
    }

    /**
     * A one-time reset link the admin hands over themselves (works without SMTP).
     */
    public function createPasswordResetLink(User $user): JsonResponse
    {
        AdminAuditLog::record('user.password_reset_link_created', $user);

        return response()->json($this->resetLink($user));
    }

    public function verifyEmail(User $user): RedirectResponse
    {
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            AdminAuditLog::record('user.email_verified', $user);
        }

        return back()->with('success', "{$user->email} is marked as verified.");
    }

    public function resendVerification(User $user): RedirectResponse
    {
        if ($user->email_verified_at) {
            return back()->with('error', 'This email address is already verified.');
        }

        $user->sendEmailVerificationNotification();
        AdminAuditLog::record('user.verification_resent', $user);

        return back()->with('success', "Verification email sent to {$user->email}.");
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You can’t suspend yourself.');
        }

        if ($user->isInstanceAdmin() && InstanceAdmin::count() <= 1) {
            return back()->with('error', 'You can’t suspend the last instance admin.');
        }

        $user->forceFill(['suspended_at' => now()])->save();
        $this->signOutEverywhere($user);
        AdminAuditLog::record('user.suspended', $user);

        return back()->with('success', "{$user->name} is suspended and signed out everywhere.");
    }

    public function unsuspend(User $user): RedirectResponse
    {
        $user->forceFill(['suspended_at' => null])->save();
        AdminAuditLog::record('user.unsuspended', $user);

        return back()->with('success', "{$user->name} can sign in again.");
    }

    public function grantAdmin(User $user): RedirectResponse
    {
        InstanceAdmin::firstOrCreate(['user_id' => $user->id]);
        AdminAuditLog::record('user.admin_granted', $user);

        return back()->with('success', "{$user->name} is now an instance admin.");
    }

    public function revokeAdmin(Request $request, User $user): RedirectResponse
    {
        if (InstanceAdmin::count() <= 1 && $user->isInstanceAdmin()) {
            return back()->with('error', 'The instance needs at least one admin.');
        }

        InstanceAdmin::where('user_id', $user->id)->delete();
        AdminAuditLog::record('user.admin_revoked', $user);

        if ($user->is($request->user())) {
            return redirect()->route('dashboard')->with('success', 'You are no longer an instance admin.');
        }

        return back()->with('success', "{$user->name} is no longer an instance admin.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $request->validate(['confirm_email' => ['required', 'string']]);

        if (mb_strtolower($request->input('confirm_email')) !== mb_strtolower($user->email)) {
            return back()->with('error', 'The email address doesn’t match.');
        }

        if ($blockers = UserDeletion::blockers($user, $request->user())) {
            return back()->with('error', $blockers[0]);
        }

        $impact = UserDeletion::impact($user);
        $this->signOutEverywhere($user);
        AdminAuditLog::record('user.deleted', $user, $impact);
        UserDeletion::delete($user);

        return redirect()->route('admin.users.index')->with('success', "{$user->email} was deleted.");
    }

    /**
     * Projects the user can open: every project in workspaces they administer, plus the
     * ones they're assigned to. Correlated to `users.id`.
     */
    private function accessibleProjectsCount(): Builder
    {
        return Project::query()
            ->selectRaw('count(*)')
            ->where(fn ($q) => $q
                ->whereIn('projects.workspace_id', DB::table('workspace_user')
                    ->select('workspace_id')
                    ->whereColumn('workspace_user.user_id', 'users.id')
                    ->where('role', 'admin'))
                ->orWhereIn('projects.id', DB::table('project_user')
                    ->select('project_id')
                    ->whereColumn('project_user.user_id', 'users.id')));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(User $user, bool $isAdmin): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_display' => $user->avatar_display,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'suspended_at' => $user->suspended_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'sign_in_methods' => array_values(array_filter([
                'password',
                $user->google_id ? 'google' : null,
                $user->github_id ? 'github' : null,
            ])),
            'is_instance_admin' => $isAdmin,
            'workspaces_count' => $user->workspaces_count,
            'owned_workspaces_count' => $user->owned_workspaces_count,
            'projects_count' => $user->projects_count,
        ];
    }

    /**
     * @return array{url: string, expires_in_minutes: int}
     */
    private function resetLink(User $user): array
    {
        $token = Password::broker()->createToken($user);

        return [
            'url' => route('password.reset', ['token' => $token, 'email' => $user->email]),
            'expires_in_minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ];
    }

    /**
     * End web sessions (database driver) and revoke API / MCP / CLI tokens.
     */
    private function signOutEverywhere(User $user): void
    {
        SignOutEverywhere::run($user);
    }
}
