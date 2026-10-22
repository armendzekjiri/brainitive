<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace housekeeping for instance admins: owner, size and dates — never content.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $workspaces = Workspace::query()
            ->select(['id', 'name', 'slug', 'owner_id', 'created_at'])
            ->with('owner:id,name,email')
            ->withCount(['users', 'projects'])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereHas('owner', fn ($o) => $o->whereRaw('LOWER(email) LIKE ?', [$term])));
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'owner' => $workspace->owner?->only(['id', 'name', 'email']),
                'members_count' => $workspace->users_count,
                'projects_count' => $workspace->projects_count,
                'created_at' => $workspace->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Workspaces', [
            'managedWorkspaces' => $workspaces,
            'filters' => ['q' => $search],
        ]);
    }

    /**
     * Members who could take over ownership.
     */
    public function members(Workspace $managedWorkspace): JsonResponse
    {
        return response()->json([
            'members' => $managedWorkspace->users()
                ->orderBy('name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->id === $managedWorkspace->owner_id ? 'owner' : $user->pivot->role,
                ]),
        ]);
    }

    public function transfer(Request $request, Workspace $managedWorkspace): RedirectResponse
    {
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $newOwner = $managedWorkspace->users()->where('users.id', $validated['user_id'])->first();

        if (! $newOwner) {
            return back()->with('error', 'The new owner must already be a member of the workspace.');
        }

        if ($newOwner->id === $managedWorkspace->owner_id) {
            return back()->with('error', "{$newOwner->name} already owns this workspace.");
        }

        $previousOwner = $managedWorkspace->owner;

        DB::transaction(function () use ($managedWorkspace, $newOwner) {
            $managedWorkspace->forceFill(['owner_id' => $newOwner->id])->save();
            $managedWorkspace->users()->updateExistingPivot($newOwner->id, ['role' => 'admin']);
        });

        AdminAuditLog::record('workspace.ownership_transferred', $managedWorkspace, [
            'from' => $previousOwner?->email,
            'to' => $newOwner->email,
        ]);

        return back()->with('success', "{$newOwner->name} now owns {$managedWorkspace->name}.");
    }

    public function destroy(Request $request, Workspace $managedWorkspace): RedirectResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);

        if (trim($request->input('confirm_name')) !== $managedWorkspace->name) {
            return back()->with('error', 'The workspace name doesn’t match.');
        }

        AdminAuditLog::record('workspace.deleted', $managedWorkspace, [
            'owner' => $managedWorkspace->owner?->email,
            'projects' => $managedWorkspace->projects()->count(),
            'members' => $managedWorkspace->users()->count(),
        ]);

        $managedWorkspace->delete();

        return redirect()->route('admin.workspaces.index')->with('success', "{$managedWorkspace->name} was deleted.");
    }
}
