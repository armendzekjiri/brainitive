<?php

namespace App\Http\Controllers\Extension;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SearchController;
use App\Models\InstanceSettings;
use App\Models\Item;
use App\Models\TaskListItem;
use App\Support\Captures\CaptureDestinations;
use App\Support\Mcp\ResourceLocator;
use App\Support\MyWork\AssignedWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read endpoints for the browser extension: who am I, where can I save, search, My Work.
 * Only ever returns what the signed-in user could see in the app.
 */
class ExtensionController extends Controller
{
    /**
     * Sign-out: revokes the calling capture token and its refresh token (the extension calls this
     * before clearing its local session; `/api/cli/logout` rejects capture tokens).
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $token?->revoke();
        $token?->refreshToken?->revoke();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar_display,
            ],
            'instance' => [
                'name' => InstanceSettings::current()?->name ?: config('app.name'),
                'url' => url('/'),
            ],
        ]);
    }

    /**
     * Everything the user can save into, grouped by workspace (shared with the capture inbox).
     */
    public function destinations(Request $request): JsonResponse
    {
        return response()->json(['workspaces' => CaptureDestinations::for($request->user())]);
    }

    /**
     * Same results as the in-app search (⌘K).
     */
    public function search(Request $request): JsonResponse
    {
        return app(SearchController::class)->extension($request);
    }

    /**
     * Open issues and tasks assigned to the user, soonest due first.
     */
    public function myWork(Request $request): JsonResponse
    {
        $user = $request->user();
        $projectIds = $user->accessibleProjectIds();

        // the shared "assigned to me, still open" query (also GET /api/v1/me/work)
        $issues = AssignedWork::issues($user, $projectIds)
            ->with(['board.project.workspace', 'column:id,name'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Item $item) => [
                'type' => 'issue',
                'id' => $item->id,
                'key' => $item->key,
                'title' => $item->title,
                'status' => $item->column?->name,
                'due_date' => $item->due_date?->toDateString(),
                'project' => $item->board->project->name,
                'url' => route('boards.items.show', [$item->board->project->workspace->slug, $item->board->project->slug, $item->board->slug, $item->id]),
            ]);

        $tasks = AssignedWork::tasks($user, $projectIds)
            ->with('taskList.project.workspace')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->limit(50)
            ->get()
            ->map(fn (TaskListItem $task) => [
                'type' => 'task',
                'id' => $task->id,
                'key' => null,
                'title' => $task->title,
                'status' => $task->taskList->name,
                'due_date' => $task->due_date?->toDateString(),
                'project' => $task->taskList->project->name,
                'url' => ResourceLocator::locate($task)['url'],
            ]);

        return response()->json(['items' => $issues->concat($tasks)->values()]);
    }
}
