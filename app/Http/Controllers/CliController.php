<?php

namespace App\Http\Controllers;

use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Rules\ProjectMember;
use App\Support\AccountLoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CliController extends Controller
{
    // ─── Auth ─────────────────────────────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Same per-account limit as the web login (the route throttle is per IP only)
        if (AccountLoginThrottle::tooManyAttempts($validated['email'])) {
            return response()->json(['message' => 'Too many failed logins for this account. Try again later.'], 429);
        }

        if (! Auth::attempt($validated)) {
            AccountLoginThrottle::hit($validated['email']);
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        AccountLoginThrottle::clear($validated['email']);

        $user = Auth::user();

        if (! $user->hasVerifiedEmail()) {
            Auth::logout();

            return response()->json(['message' => 'Verify your email address first (check your inbox).'], 403);
        }

        if ($user->isSuspended()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => ['This account is suspended.'],
            ]);
        }

        // Revoke any previous CLI tokens so there is only ever one active.
        // Wrapped in try/catch in case the OAuth tables have not been migrated
        // yet on this environment.
        try {
            $user->tokens()->where('name', 'brainitive-cli')->get()->each->revoke();
        } catch (\Exception) {
            // Tables missing — will be created after `php artisan migrate`
        }

        // Passport personal access token (Bearer). Validated by the `api`
        // guard (config/auth.php) — the same guard the MCP server uses.
        $token = $user->createToken('brainitive-cli')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->only('id', 'name', 'email')]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Access + refresh token, like Profile → Connected apps (the CLI calls this on `brainitive logout`).
        $token = $request->user()->currentAccessToken();
        $token?->revoke();
        $token?->refreshToken?->revoke();

        return response()->json(['message' => 'Logged out.']);
    }

    // ─── Workspaces ───────────────────────────────────────────────────────────

    public function workspaces(Request $request): JsonResponse
    {
        $user = $request->user();

        $workspaces = $user->workspaces()
            ->withCount('projects')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug']);

        return response()->json(['workspaces' => $workspaces]);
    }

    // ─── Task Lists ───────────────────────────────────────────────────────────

    public function lists(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceSlug = $request->query('workspace');

        // Load all task lists the user has access to across their workspaces
        $lists = TaskList::query()
            ->whereHas('project.workspace', function ($q) use ($user, $workspaceSlug) {
                $q->whereHas('users', fn ($u) => $u->where('users.id', $user->id));
                if ($workspaceSlug) {
                    $q->where('slug', $workspaceSlug);
                }
            })
            ->with([
                'project:id,name,slug,workspace_id',
                'project.workspace:id,name,slug',
            ])
            ->withCount([
                'items',
                'items as todo_count' => fn ($q) => $q->where('status', 'todo'),
                'items as done_count' => fn ($q) => $q->where('status', 'done'),
            ])
            ->orderBy('project_id')
            ->orderBy('position')
            ->get();

        return response()->json(['lists' => $lists]);
    }

    public function showList(Request $request, TaskList $taskList): JsonResponse
    {
        $this->authorize('view', $taskList);

        $taskList->load([
            'project:id,name,slug,workspace_id',
            'project.workspace:id,name,slug',
            'items' => function ($q) {
                $q->with('assignee:id,name,email')
                    ->orderByRaw("CASE status WHEN 'todo' THEN 0 ELSE 1 END")
                    ->orderBy('position');
            },
        ]);

        return response()->json(['list' => $taskList]);
    }

    // ─── Items ────────────────────────────────────────────────────────────────

    public function storeItem(Request $request, TaskList $taskList): JsonResponse
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'assignee_id' => ['nullable', 'integer', new ProjectMember($taskList->project)],
            'due_date' => 'nullable|date',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
        ]);

        $position = $taskList->items()->max('position') + 1;

        $item = $taskList->items()->create([
            'title' => $validated['title'],
            'status' => 'todo',
            'assignee_id' => $validated['assignee_id'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'position' => $position,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['item' => $item->load('assignee:id,name,email')], 201);
    }

    public function updateItem(Request $request, TaskList $taskList, TaskListItem $item): JsonResponse
    {
        $this->authorize('manageItems', $taskList);
        abort_unless($item->task_list_id === $taskList->id, 404);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:500',
            'status' => 'sometimes|in:todo,done',
            'assignee_id' => ['nullable', 'integer', new ProjectMember($taskList->project)],
            'due_date' => 'nullable|date',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
        ]);

        $isCompleting = isset($validated['status'])
            && $validated['status'] === 'done'
            && $item->status !== 'done';

        // Without a due date there is nothing to move: a repeating task then just gets done.
        if ($isCompleting && $item->recurrence && $item->due_date) {
            $nextDate = $item->nextDueDate();
            $item->update(array_merge($validated, [
                'status' => 'todo',
                'due_date' => $nextDate ? $nextDate->format('Y-m-d') : $item->getRawOriginal('due_date'),
            ]));

            return response()->json([
                'item' => $item->fresh()->load('assignee:id,name,email'),
                'recurrence_bounced' => true,
                'next_due_date' => $nextDate?->format('Y-m-d'),
            ]);
        }

        $item->update($validated);

        return response()->json(['item' => $item->fresh()->load('assignee:id,name,email')]);
    }

    public function destroyItem(Request $request, TaskList $taskList, TaskListItem $item): JsonResponse
    {
        $this->authorize('manageItems', $taskList);
        abort_unless($item->task_list_id === $taskList->id, 404);

        $item->delete();

        return response()->json(['message' => 'Item deleted.']);
    }
}
