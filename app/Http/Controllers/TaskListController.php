<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Workspace;
use App\Rules\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

        $slug = $this->uniqueSlug($project, Str::slug($validated['name']));

        $position = $project->taskLists()->max('position') + 1;

        $taskList = $project->taskLists()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'position' => $position,
            'created_by' => $request->user()->id,
        ]);

        // Auto-watch creator
        $taskList->watchers()->syncWithoutDetaching([$request->user()->id]);

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
            $validated['slug'] = $this->uniqueSlug($project, Str::slug($validated['name']), $taskList->id);
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

        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|array',
            // Only people in this workspace can be assigned.
            'assignee_id' => ['nullable', 'integer', Rule::in($this->memberIds($workspace)), new ProjectMember($taskList->project)],
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
            'reminder_minutes' => 'nullable|integer|in:'.implode(',', TaskListItem::REMINDER_OPTIONS),
        ]);

        $position = $taskList->items()->max('position') + 1;

        $item = $taskList->items()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => 'todo',
            'assignee_id' => $validated['assignee_id'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'due_time' => $validated['due_time'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'recurrence' => $validated['recurrence'] ?? null,
            'reminder_minutes' => $validated['reminder_minutes'] ?? null,
            'position' => $position,
            'created_by' => $request->user()->id,
        ]);

        return back()->with(['newItem' => $item->load('assignee:id,name,email,avatar')]);
    }

    public function updateItem(Request $request, Workspace $workspace, Project $project, TaskList $taskList, TaskListItem $item)
    {
        $this->authorize('manageItems', $taskList);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:500',
            'description' => 'nullable|array',
            'status' => 'sometimes|in:todo,done',
            // Only people in this workspace can be assigned.
            'assignee_id' => ['nullable', 'integer', Rule::in($this->memberIds($workspace)), new ProjectMember($taskList->project)],
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'recurrence' => 'nullable|in:daily,weekly,monthly,yearly',
            'reminder_minutes' => 'nullable|integer|in:'.implode(',', TaskListItem::REMINDER_OPTIONS),
        ]);

        $isCompleting = isset($validated['status'])
            && $validated['status'] === 'done'
            && $item->status !== 'done';

        // Recurring: when completing a recurring item, reset to todo with next due date
        // Without a due date there is nothing to move: a repeating task then just gets done.
        if ($isCompleting && $item->recurrence && $item->due_date) {
            $nextDate = $item->nextDueDate();

            $item->update(array_merge($validated, [
                'status' => 'todo',
                'due_date' => $nextDate ? $nextDate->format('Y-m-d') : $item->getRawOriginal('due_date'),
            ]));

            return back();
        }

        $item->update($validated);

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

        foreach ($validated['items'] as $data) {
            $taskList->items()->where('id', $data['id'])->update(['position' => $data['position']]);
        }

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function memberIds(Workspace $workspace): array
    {
        return $workspace->users()->pluck('users.id')->all();
    }

    private function uniqueSlug(Project $project, string $base, ?int $excludeId = null): string
    {
        $slug = $base ?: 'list';
        $original = $slug;
        $counter = 1;

        while (
            $project->taskLists()
                ->where('slug', $slug)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $slug = $original.'-'.$counter++;
        }

        return $slug;
    }
}
