<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Workspace;
use App\Support\TaskLists\TaskLists;
use App\Support\Work\WorkCompletion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TaskListController extends Controller
{
    // ─── List CRUD ────────────────────────────────────────────────────────────

    public function store(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('create', [TaskList::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $taskList = TaskLists::create($project, $request->user(), $validated['name'], $validated['description'] ?? null);

        return redirect()->route('task-lists.show', [$workspace->slug, $project->slug, $taskList->slug])
            ->with('success', 'List created successfully.');
    }

    public function show(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('view', $taskList);

        $taskList->load([
            'items' => function ($q) {
                $q->with(['assignee:id,name,email,avatar', 'creator:id,name'])
                    ->orderBy('status')   // todo first
                    ->orderBy('position');
            },
            'watchers:id,name,email',
            'creator:id,name',
        ]);

        // Project members for assignee picker
        $members = $project->accessibleUsers()
            ->select('users.id', 'users.name', 'users.email', 'users.avatar')
            ->get();

        return Inertia::render('Lists/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'taskList' => $taskList,
            'members' => $members,
            'canManage' => $request->user()->can('manageItems', $taskList),
        ]);
    }

    public function update(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('update', $taskList);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = TaskLists::uniqueSlug($project, $validated['name'], $taskList->id);
        }

        $taskList->update($validated);

        return back()->with('success', 'List updated.');
    }

    public function destroy(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('delete', $taskList);

        $taskList->delete();

        return redirect()->route('projects.show', [$workspace->slug, $project->slug])
            ->with('success', 'List deleted.');
    }

    public function toggleWatch(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('view', $taskList);

        $user = $request->user();
        $watching = $request->boolean('watch', true);

        if ($watching) {
            $taskList->watchers()->syncWithoutDetaching([$user->id]);
        } else {
            $taskList->watchers()->detach($user->id);
        }

        return back();
    }

    // ─── Item CRUD ────────────────────────────────────────────────────────────

    public function storeItem(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate(['description' => 'nullable|array'] + TaskLists::taskRules($taskList));

        $item = TaskLists::createTask($taskList, $request->user(), $validated);

        return back()->with(['newItem' => $item->load('assignee:id,name,email,avatar')]);
    }

    public function updateItem(Request $request, Workspace $workspace, Project $project, TaskList $taskList, TaskListItem $item)
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate(['description' => 'nullable|array'] + TaskLists::taskRules($taskList, update: true));

        // a repeating task rolls on to its next date (shared with My Work and the App API)
        WorkCompletion::updateTask($item, $validated);

        return back();
    }

    /**
     * Duplicate a task right below the original, as a new open task.
     */
    public function duplicateItem(Request $request, Workspace $workspace, Project $project, TaskList $taskList, TaskListItem $item)
    {
        $this->authorize('manageItems', $taskList);

        $copy = $item->replicate(['uuid', 'created_by', 'created_at', 'updated_at']);
        $copy->title = Str::limit('Copy of '.$item->title, 500, '');
        $copy->status = 'todo';
        $copy->created_by = $request->user()->id;
        $copy->position = $item->position + 1;

        DB::transaction(function () use ($taskList, $item, $copy) {
            $taskList->items()->where('position', '>', $item->position)->increment('position');
            $copy->save();
        });

        return back()->with('success', 'Task duplicated.');
    }

    public function destroyItem(Request $request, Workspace $workspace, Project $project, TaskList $taskList, TaskListItem $item)
    {
        $this->authorize('manageItems', $taskList);

        $item->delete();

        return back();
    }

    public function reorderItems(Request $request, Workspace $workspace, Project $project, TaskList $taskList)
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer',
            'items.*.position' => 'required|integer',
        ]);

        TaskLists::reorder($taskList, collect($validated['items'])->mapWithKeys(fn (array $row) => [(int) $row['id'] => (int) $row['position']])->all());

        return response()->json(['ok' => true]);
    }
}
