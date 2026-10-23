<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Release;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\ItemStatusChangedNotification;
use App\Support\Boards\ItemComments;
use App\Support\Boards\ItemMentions;
use App\Support\Boards\Swimlanes;
use App\Support\Github\ItemDevelopment;
use App\Support\Uploads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    /**
     * Store a new board.
     */
    public function store(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('create', [Board::class, $project]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $slug = Str::slug($validated['name']);
        $originalSlug = $slug;
        $counter = 1;

        while ($project->boards()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        $board = $project->boards()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('boards.show', [$workspace->slug, $project->slug, $board->slug])
            ->with('success', 'Board created successfully.');
    }

    /**
     * Display the specified board (Kanban view).
     */
    public function show(Request $request, Workspace $workspace, Project $project, Board $board): Response
    {
        $this->authorize('view', $board);

        // Scrum boards show the running sprint only (no sprint running → empty board)
        $activeSprint = $board->isScrum() ? $board->activeSprint()->first() : null;

        // Cards only carry what the board renders; comments, attachments and history are
        // loaded on demand by itemDetails() when an item is opened.
        $board->load([
            'columns' => function ($query) use ($board, $activeSprint) {
                $query->orderBy('position')->with([
                    'items' => fn ($q) => $this->withCardData($q)
                        ->when($board->isScrum(), fn ($sprintScope) => $sprintScope->where('sprint_id', $activeSprint?->id ?? 0)),
                ]);
            },
        ]);

        $itemTypes = ItemType::forProject($project)->orderBy('id')->get();
        if ($itemTypes->isEmpty()) {
            $itemTypes = ItemType::orderBy('id')->get();
        }
        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $projectLabels = $project->labels()->orderBy('name')->get();

        // All items in the project, for the "Link issue" picker in the item popup.
        $projectItems = Item::whereHas('board', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })->select('id', 'key', 'title', 'board_id')
            ->with('board:id,name,slug')
            ->orderBy('key')
            ->get();

        return Inertia::render('Boards/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board,
            'boardItemTypes' => $itemTypes,
            'priorities' => $priorities,
            'projectUsers' => $projectUsers,
            'projectLabels' => $projectLabels,
            'projectItems' => $projectItems,
            'activeSprint' => $activeSprint?->only(['id', 'name', 'goal', 'start_date', 'end_date']),
            'canManageSprints' => $request->user()->can('update', $board),
            'plannedSprints' => $board->isScrum()
                ? $board->sprints()->where('status', Sprint::STATUS_PLANNED)->get(['id', 'name'])
                : [],
        ]);
    }

    /**
     * Show the board settings page.
     */
    public function edit(Request $request, Workspace $workspace, Project $project, Board $board): Response
    {
        $this->authorize('update', $board);

        $board->load([
            'columns' => function ($query) {
                $query->orderBy('position')->withCount('items');
            },
        ]);

        return Inertia::render('Boards/Edit', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board,
            // Choices for the swimlane rule builder
            'laneOptions' => [
                'priorities' => ItemPriority::forProject($project)->orderBy('order')->get(['id', 'name', 'slug', 'color']),
                'types' => ItemType::forProject($project)->orderBy('id')->get(['id', 'name', 'slug', 'color']),
                'labels' => $project->labels()->orderBy('name')->get(['id', 'name', 'color']),
                'users' => $project->workspace->users()->orderBy('name')->get(['users.id', 'name']),
                // Same order as the issue's "Fix version" picker (undated last on SQLite and Postgres alike)
                'releases' => Release::where('project_id', $project->id)->where('status', '!=', Release::STATUS_ARCHIVED)
                    ->orderByRaw("case status when 'unreleased' then 0 else 1 end")->orderByRaw('release_date is null')->orderBy('release_date')->orderByDesc('id')
                    ->get(['id', 'name', 'status']),
            ],
        ]);
    }

    /**
     * Update board settings.
     */
    public function update(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:kanban,scrum',
        ]);

        if (($validated['type'] ?? $board->type) === 'kanban' && $board->activeSprint()->exists()) {
            return back()->withErrors(['type' => 'Complete the active sprint before turning sprints off.']);
        }

        $board->update($validated);

        return redirect()->back()->with('success', 'Board updated successfully.');
    }

    /**
     * Save the board's swimlanes (custom lane rules and the default lane mode for everyone).
     */
    public function updateSwimlanes(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('update', $board);

        $validated = $request->validate(Swimlanes::rules());
        $settings = $board->settings ?? [];
        $settings['swimlanes'] = Swimlanes::normalize($board, $validated);
        $board->update(['settings' => $settings]);

        return back()->with('success', 'Swimlanes saved.');
    }

    /**
     * Delete a board.
     */
    public function destroy(Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('delete', $board);

        $board->delete();

        return redirect()->route('projects.show', [$workspace->slug, $project->slug])
            ->with('success', 'Board deleted successfully.');
    }

    /**
     * Store a new column.
     */
    public function storeColumn(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
        ]);

        // Slugs are unique per board; two "Review" columns or emoji-only names must not 500.
        $base = Str::slug($validated['name']) ?: 'column';
        $slug = $base;
        for ($n = 2; $board->columns()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }
        $maxPosition = $board->columns()->max('position') ?? 0;

        $board->columns()->create([
            'name' => trim($validated['name']),
            'slug' => $slug,
            'color' => $validated['color'] ?? null,
            'position' => $maxPosition + 1,
        ]);

        return redirect()->back()->with('success', 'Column created successfully.');
    }

    /**
     * Update a column.
     */
    public function updateColumn(Request $request, Workspace $workspace, Project $project, Board $board, BoardColumn $column)
    {
        $this->authorize('update', $board);
        abort_unless($column->board_id === $board->id, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'wip_limit' => 'nullable|integer|min:0',
            'is_done_column' => 'nullable|boolean',
        ]);

        $column->update($validated);

        return redirect()->back()->with('success', 'Column updated successfully.');
    }

    /**
     * Reorder columns.
     */
    public function reorderColumns(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'columns' => 'required|array',
            'columns.*.id' => 'required|integer|exists:board_columns,id',
            'columns.*.position' => 'required|integer|min:0',
        ]);

        foreach ($validated['columns'] as $columnData) {
            BoardColumn::where('id', $columnData['id'])
                ->where('board_id', $board->id)
                ->update(['position' => $columnData['position']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Reorder items within a board (used by timeline drag-and-drop).
     */
    public function reorderItems(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('update', $board);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:items,id',
            'items.*.position' => 'required|integer|min:0',
        ]);

        foreach ($validated['items'] as $itemData) {
            Item::where('id', $itemData['id'])
                ->where('board_id', $board->id)
                ->update(['position' => $itemData['position']]);
        }

        return back()->with('success', 'Items reordered successfully.');
    }

    /**
     * Delete a column.
     */
    public function destroyColumn(Workspace $workspace, Project $project, Board $board, BoardColumn $column)
    {
        $this->authorize('update', $board);
        abort_unless($column->board_id === $board->id, 404);

        // Check if column has items
        if ($column->items()->exists()) {
            return redirect()->back()->withErrors(['column' => 'Cannot delete a column that still has items. Move or delete them first.']);
        }

        $column->delete();

        return redirect()->back()->with('success', 'Column deleted successfully.');
    }

    /**
     * Store a new item in the board.
     */
    public function storeItem(Request $request, Workspace $workspace, Project $project, Board $board)
    {
        $this->authorize('createItems', $board);

        $subtaskTypeId = ItemType::forProject($project)->where('slug', 'subtask')->value('id');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'column_id' => ['required', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'item_type_id' => 'nullable|exists:item_types,id',
            'priority_id' => 'nullable|exists:item_priorities,id',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'sprint_id' => ['nullable', Rule::exists('sprints', 'id')->where('board_id', $board->id)->whereIn('status', [Sprint::STATUS_PLANNED, Sprint::STATUS_ACTIVE])],
            'parent_id' => [
                'nullable',
                'exists:items,id',
                function ($attribute, $value, $fail) use ($board) {
                    if ($value === null) {
                        return;
                    }
                    $parent = Item::find($value);
                    if (! $parent || $parent->board_id !== $board->id) {
                        $fail('Parent task must be on the same board.');

                        return;
                    }
                    if ($parent->depth() >= Item::MAX_DEPTH) {
                        $fail('Subtasks can be nested up to 3 levels (issue → subtask → sub-subtask).');

                        return;
                    }
                },
            ],
        ]);

        $itemTypeId = $validated['item_type_id'] ?? ItemType::where('is_default', true)->first()?->id;
        if ($subtaskTypeId && (int) $itemTypeId === (int) $subtaskTypeId && empty($validated['parent_id'])) {
            return back()->withErrors(['parent_id' => 'Subtasks must have a parent task.']);
        }

        // Get the next position in the column
        $maxPosition = Item::where('column_id', $validated['column_id'])->max('position') ?? 0;

        // Sprint: subtasks follow their parent; on a Scrum board a new card joins the running
        // sprint unless the request says where (null = backlog), like Jira
        $sprintId = match (true) {
            ! empty($validated['parent_id']) => Item::find($validated['parent_id'])?->sprint_id,
            array_key_exists('sprint_id', $validated) => $validated['sprint_id'],
            $board->isScrum() => $board->activeSprint()->value('id'),
            default => null,
        };
        $backlogPosition = (Item::where('board_id', $board->id)->where('sprint_id', $sprintId)->max('backlog_position') ?? 0) + 1;

        $item = $board->items()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'project_id' => $project->id,
            'column_id' => $validated['column_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'item_type_id' => $itemTypeId,
            'priority_id' => $validated['priority_id'] ?? ItemPriority::where('is_default', true)->first()?->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'due_time' => $validated['due_time'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'key' => $project->getNextItemKey(),
            'created_by' => $request->user()->id,
            'reporter_id' => $request->user()->id,
            'position' => $maxPosition + 1,
            'sprint_id' => $sprintId,
            'backlog_position' => $backlogPosition,
        ]);

        // Sync labels if provided
        if (! empty($validated['label_ids'])) {
            $validLabels = $project->labels()->whereIn('id', $validated['label_ids'])->pluck('id');
            $item->labels()->sync($validLabels);
        }

        // Notify assignee if assigned to someone else
        $newAssigneeId = $item->assigned_to;
        if ($newAssigneeId && $newAssigneeId !== $request->user()->id) {
            $assignee = User::find($newAssigneeId);
            if ($assignee) {
                $assignee->notify(new ItemAssignedNotification($item, $request->user()));
            }
        }

        // Process mentions inside description on item creation
        if (! empty($validated['description'])) {
            ItemMentions::notify($validated['description'], $item, $request->user());
        }

        return back()->with('success', 'Item created successfully.');
    }

    /**
     * Return root (non-subtask) items for a board as JSON (used by global create modal).
     */
    public function rootItemsJson(Request $request, Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        $this->authorize('view', $board);

        // Possible parents: top-level issues and their direct subtasks (3 levels max), each
        // issue followed by its subtasks.
        $roots = $board->items()->whereNull('parent_id')->select('id', 'key', 'title', 'column_id')->orderBy('position')->get();
        $children = $board->items()->whereIn('parent_id', $roots->pluck('id'))->select('id', 'key', 'title', 'column_id', 'parent_id')->orderBy('position')->get()->groupBy('parent_id');

        $items = $roots->flatMap(fn (Item $root) => collect([[...$root->only(['id', 'key', 'title', 'column_id']), 'depth' => 0]])
            ->concat(($children[$root->id] ?? collect())->map(fn (Item $c) => [...$c->only(['id', 'key', 'title', 'column_id']), 'depth' => 1])));

        return response()->json($items->values());
    }

    /**
     * Comments, attachments and history of one item (JSON), fetched when it is opened.
     */
    public function itemDetails(Workspace $workspace, Project $project, Board $board, Item $item): JsonResponse
    {
        $this->authorize('view', $item);

        $comments = $item->comments()
            ->whereNull('parent_id')
            ->with(['user:id,name,email,avatar', 'replies'])
            ->orderBy('created_at', 'desc')
            ->get();

        $attachments = $item->attachments()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->each(function (Attachment $attachment) {
                $attachment->url = asset('storage/'.$attachment->path);
                $attachment->formatted_size = $attachment->formatted_size;
            });

        $history = $item->activities()
            ->with('user:id,name,avatar')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'user_id', 'action', 'changes', 'metadata', 'created_at']);

        return response()->json([
            'comments' => $comments,
            'attachments' => $attachments,
            'history' => $history,
            'time_spent' => $item->time_spent,
            'original_estimate' => $item->original_estimate,
            'development' => ItemDevelopment::for($item),
            // Options for the "Fix version" field: open and shipped releases, plus the current one.
            'releases' => Release::where('project_id', $item->board->project_id)
                ->where(fn ($q) => $q->where('status', '!=', Release::STATUS_ARCHIVED)->orWhere('id', $item->release_id))
                ->orderByRaw("case status when 'unreleased' then 0 else 1 end")->orderByRaw('release_date is null')->orderBy('release_date')->orderByDesc('id')
                ->limit(100)->get(['id', 'name', 'status']),
            'release_id' => $item->release_id,
        ]);
    }

    /**
     * Log time worked on an item ("1h 30m"), Jira-style.
     */
    public function logWork(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'time' => Item::durationRules(),
            'note' => 'nullable|string|max:255',
        ]);

        $minutes = Item::parseDuration($validated['time']);
        $item->logWork($minutes, $validated['note'] ?? null);

        return back()->with('success', 'Work logged: '.Item::formatDuration($minutes).'.');
    }

    /**
     * Show a single item (full page view).
     */
    public function showItem(Request $request, Workspace $workspace, Project $project, Board $board, Item $item): Response
    {
        $this->authorize('view', $item);

        $item->load([
            'assignee:id,name,email,avatar',
            'itemType',
            'priority',
            'labels',
            'column',
            'reporter:id,name,email',
            'creator:id,name,email',
            'watchers:id,name,email,avatar',
            'outgoingLinks.targetItem:id,key,title,board_id',
            'outgoingLinks.targetItem.board:id,name,slug',
            'incomingLinks.sourceItem:id,key,title,board_id',
            'incomingLinks.sourceItem.board:id,name,slug',
            'attachments' => function ($q) {
                $q->with('user:id,name,email')->orderBy('created_at', 'desc');
            },
            'comments' => function ($q) {
                $q->whereNull('parent_id')
                    ->with(['user:id,name,email', 'replies'])
                    ->orderBy('created_at', 'desc');
            },
        ]);

        // Add URLs to attachments
        foreach ($item->attachments as $attachment) {
            $attachment->url = asset('storage/'.$attachment->path);
        }

        $itemTypes = ItemType::forProject($project)->get();
        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $projectLabels = $project->labels()->orderBy('name')->get();

        // Columns WITH their items + relations, mirroring BoardController::show, so the
        // item popup/full page can derive subtasks, parent options and a live copy.
        $columns = $board->columns()->orderBy('position')->with([
            'items' => fn ($q) => $this->withCardData($q),
        ])->get();

        // Get all items in the project for linking (excluding current item)
        $projectItems = Item::whereHas('board', function ($q) use ($project) {
            $q->where('project_id', $project->id);
        })->where('id', '!=', $item->id)
            ->select('id', 'key', 'title', 'board_id')
            ->with('board:id,name,slug')
            ->orderBy('key')
            ->get();

        // Get all boards in the project for move-to-board
        $projectBoards = $project->boards()
            ->with('columns:id,board_id,name,position')
            ->select('id', 'name', 'slug')
            ->get();

        return Inertia::render('Items/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board,
            'item' => $item,
            'itemTypes' => $itemTypes,
            'priorities' => $priorities,
            'projectUsers' => $projectUsers,
            'columns' => $columns,
            'projectLabels' => $projectLabels,
            'projectItems' => $projectItems,
            'projectBoards' => $projectBoards,
        ]);
    }

    /**
     * Update item position (drag and drop).
     */
    public function moveItem(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'column_id' => ['required', Rule::exists('board_columns', 'id')->where('board_id', $item->board_id)],
            'position' => 'required|integer|min:0',
            // Moving into a done column: complete the open subtasks (all levels) too.
            'with_subtasks' => 'nullable|boolean',
        ]);

        $oldColumn = $item->column;
        $newColumn = BoardColumn::findOrFail($validated['column_id']);

        $item->moveToColumn($newColumn, $validated['position']);

        if ($newColumn->is_done_column && $request->boolean('with_subtasks')) {
            Item::whereIn('id', $item->descendantIds())
                ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
                ->get()
                ->each(fn (Item $child) => $child->moveToColumn($newColumn, 0));
        }

        // Notify all watchers when the column (status) actually changed
        if ($oldColumn && $oldColumn->id !== $newColumn->id) {
            $item->load('watchers');
            $actorId = $request->user()->id;

            foreach ($item->watchers as $watcher) {
                // Don't notify the person who performed the move
                if ($watcher->id === $actorId) {
                    continue;
                }

                $watcher->notify(new ItemStatusChangedNotification(
                    item: $item,
                    oldColumn: $oldColumn,
                    newColumn: $newColumn,
                    actor: $request->user(),
                ));
            }
        }

        return back();
    }

    /**
     * Toggle watch status on an item.
     */
    public function toggleWatch(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('view', $item);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'watch' => 'required|boolean',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($validated['watch']) {
            $item->addWatcher($user);
        } else {
            $item->removeWatcher($user);
        }

        return back();
    }

    /**
     * Update an item.
     */
    public function updateItem(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $subtaskTypeId = ItemType::forProject($project)->where('slug', 'subtask')->value('id');

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'item_type_id' => 'nullable|exists:item_types,id',
            'priority_id' => 'nullable|exists:item_priorities,id',
            'assigned_to' => 'nullable|exists:users,id',
            'start_date' => 'nullable|date',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
            'story_points' => 'nullable|integer|min:0',
            // Fix version: a release of this project that isn't archived.
            'release_id' => ['nullable', Rule::exists('releases', 'id')->where('project_id', $project->id)->whereIn('status', [Release::STATUS_UNRELEASED, Release::STATUS_RELEASED])],
            'original_estimate' => ['nullable', 'string', 'max:50', function ($attribute, $value, $fail) {
                if (Item::parseDuration($value) === null) {
                    $fail('Use a duration like 45m, 1h 30m, 2h or 1d.');
                }
            }],
            'parent_id' => [
                'nullable',
                'exists:items,id',
                function ($attribute, $value, $fail) use ($board, $item) {
                    if ($value === null) {
                        return;
                    }
                    if ((int) $value === $item->id) {
                        $fail('An item cannot be its own parent.');

                        return;
                    }
                    $parent = Item::find($value);
                    if (! $parent || $parent->board_id !== $board->id) {
                        $fail('Parent task must be on the same board.');

                        return;
                    }
                    if ($parent->depth() + 1 + $item->subtreeHeight() > Item::MAX_DEPTH) {
                        $fail('Subtasks can be nested up to 3 levels (issue → subtask → sub-subtask).');

                        return;
                    }
                    // No cycle: walk parent chain from candidate; must not reach current item
                    $current = $parent;
                    while ($current->parent_id !== null) {
                        $current = Item::find($current->parent_id);
                        if (! $current) {
                            break;
                        }
                        if ($current->id === $item->id) {
                            $fail('Parent would create a circular reference.');

                            return;
                        }
                    }
                },
            ],
        ]);

        $itemTypeId = array_key_exists('item_type_id', $validated) ? $validated['item_type_id'] : $item->item_type_id;
        $effectiveTypeId = $itemTypeId ?? $item->itemType?->id;
        if ($subtaskTypeId && (int) $effectiveTypeId === (int) $subtaskTypeId) {
            $newParentId = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : $item->parent_id;
            if (empty($newParentId)) {
                return back()->withErrors(['parent_id' => 'Subtasks must have a parent task.']);
            }
        }

        if (array_key_exists('original_estimate', $validated)) {
            $validated['original_estimate'] = $validated['original_estimate'] === null
                ? null
                : Item::parseDuration($validated['original_estimate']);
        }

        $oldAssigneeId = $item->assigned_to;

        $item->update($validated);

        // Notify new assignee if changed
        $newAssigneeId = $item->assigned_to;
        if (
            $newAssigneeId
            && $newAssigneeId !== $oldAssigneeId
            && $newAssigneeId !== $request->user()->id
        ) {
            $assignee = User::find($newAssigneeId);
            if ($assignee) {
                $assignee->notify(new ItemAssignedNotification($item, $request->user()));
            }
        }

        // Process mentions in description
        if (isset($validated['description'])) {
            ItemMentions::notify($validated['description'], $item, $request->user());
        }

        return back()->with('success', 'Item updated successfully.');
    }

    /**
     * Duplicate an issue — optionally with its subtasks — next to the original. Copies the
     * content and planning fields (type, priority, assignee, dates, estimate, labels, sprint);
     * never comments, attachments, watchers, work logs or history.
     */
    public function duplicateItem(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('createItems', $board);

        $validated = $request->validate([
            'with_subitems' => 'nullable|boolean',
        ]);

        $copy = DB::transaction(fn () => $this->performItemDuplication(
            $item, $project, $item->parent_id, $request->user()->id, true, (bool) ($validated['with_subitems'] ?? false),
        ));

        return back()->with('success', "Copied as {$copy->key}.");
    }

    /**
     * Recursive helper to duplicate an item and its subitems.
     */
    private function performItemDuplication(Item $originalItem, Project $project, ?int $parentId, int $userId, bool $isRoot, bool $withSubitems): Item
    {
        $newItem = $originalItem->replicate([
            'uuid',
            'key',
            'created_by',
            'reporter_id',
            'completed_at',
            'time_spent',
            'created_at',
            'updated_at',
        ]);

        if ($isRoot) {
            $newItem->title = Str::limit('Copy of '.$originalItem->title, 255, '');
        }

        $newItem->key = $project->getNextItemKey();
        $newItem->project_id = $project->id;
        $newItem->created_by = $userId;
        $newItem->reporter_id = $userId;
        $newItem->parent_id = $parentId;
        $newItem->time_spent = 0;
        $newItem->position = (Item::where('column_id', $originalItem->column_id)->max('position') ?? 0) + 1;
        $newItem->backlog_position = (Item::where('board_id', $originalItem->board_id)->where('sprint_id', $originalItem->sprint_id)->max('backlog_position') ?? 0) + 1;
        $newItem->save();

        $labelIds = $originalItem->labels()->pluck('labels.id');
        if ($labelIds->isNotEmpty()) {
            $newItem->labels()->sync($labelIds);
        }

        if ($withSubitems) {
            foreach ($originalItem->children()->orderBy('position')->get() as $child) {
                $this->performItemDuplication($child, $project, $newItem->id, $userId, false, true);
            }
        }

        return $newItem;
    }

    /**
     * Delete an item.
     */
    public function destroyItem(Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('delete', $item);

        // Soft deletes don't trigger the FK cascade: remove the whole subtree explicitly.
        DB::transaction(function () use ($item) {
            Item::whereIn('id', $item->descendantIds())->get()->each->delete();
            $item->delete();
        });

        return back()->with('success', 'Item deleted successfully.');
    }

    /**
     * Store a new comment on an item.
     */
    public function storeComment(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'body' => 'required|string',
            // A reply belongs to a comment on this issue.
            'parent_id' => ['nullable', Rule::exists('comments', 'id')->where('item_id', $item->id)],
        ]);

        ItemComments::add($item, $request->user(), $validated['body'], $validated['parent_id'] ?? null);

        return back()->with('success', 'Comment added successfully.');
    }

    /**
     * Update item labels.
     */
    public function updateItemLabels(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'labels' => 'array',
            'labels.*' => 'exists:labels,id',
        ]);

        // Ensure labels belong to the project
        $validLabels = $project->labels()->whereIn('id', $validated['labels'] ?? [])->pluck('id');
        $result = $item->labels()->sync($validLabels);

        if ($result['attached'] !== [] || $result['detached'] !== []) {
            $names = $project->labels()->whereIn('id', array_merge($result['attached'], $result['detached']))->pluck('name', 'id');
            ActivityLog::log($item, 'labels_changed', [
                'labels' => [
                    'added' => collect($result['attached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                    'removed' => collect($result['detached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                ],
            ]);
        }

        return back()->with('success', 'Labels updated successfully.');
    }

    /**
     * Store a new item link.
     */
    public function storeItemLink(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'target_item_id' => 'required|exists:items,id',
            'link_type' => 'required|string|in:blocks,is_blocked_by,duplicates,is_duplicated_by,relates_to,clones,is_cloned_by',
        ]);

        // Prevent self-linking
        // Only issues the user can open — otherwise the link would reveal another
        // workspace's issue key and title.
        $target = Item::with('board.project')->find($validated['target_item_id']);
        if (! $target || $request->user()->cannot('view', $target)) {
            return back()->withErrors(['target_item_id' => 'That issue was not found.']);
        }

        if ($validated['target_item_id'] == $item->id) {
            return back()->withErrors(['target_item_id' => 'Cannot link an item to itself.']);
        }

        // Check if link already exists
        $existingLink = ItemLink::where('source_item_id', $item->id)
            ->where('target_item_id', $validated['target_item_id'])
            ->where('link_type', $validated['link_type'])
            ->exists();

        if ($existingLink) {
            return back()->withErrors(['target_item_id' => 'This link already exists.']);
        }

        $link = ItemLink::create([
            'source_item_id' => $item->id,
            'target_item_id' => $validated['target_item_id'],
            'link_type' => $validated['link_type'],
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::log($item, 'linked', null, [
            'link_type' => $link->link_type,
            'item' => $link->targetItem?->key,
        ]);

        return back()->with('success', 'Item link created successfully.');
    }

    /**
     * Delete an item link.
     */
    public function destroyItemLink(Workspace $workspace, Project $project, Board $board, Item $item, ItemLink $link)
    {
        $this->authorize('update', $item);

        // Ensure the link belongs to this item
        if ($link->source_item_id !== $item->id && $link->target_item_id !== $item->id) {
            abort(404);
        }

        $otherItem = $link->source_item_id === $item->id ? $link->targetItem : $link->sourceItem;
        ActivityLog::log($item, 'unlinked', null, [
            'link_type' => $link->source_item_id === $item->id ? $link->link_type : (ItemLink::LINK_TYPES[$link->link_type] ?? $link->link_type),
            'item' => $otherItem?->key,
        ]);

        $link->delete();

        return back()->with('success', 'Item link removed successfully.');
    }

    /**
     * Move item to a different board.
     */
    public function moveItemToBoard(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'target_board_id' => 'required|exists:boards,id',
            'target_column_id' => 'nullable|exists:board_columns,id',
        ]);

        $targetBoard = Board::findOrFail($validated['target_board_id']);

        // Moving in = creating an issue on the target board.
        $this->authorize('createItems', $targetBoard);

        // Get target column - either specified or first column of target board
        if (! empty($validated['target_column_id'])) {
            $targetColumn = BoardColumn::where('id', $validated['target_column_id'])
                ->where('board_id', $targetBoard->id)
                ->firstOrFail();
        } else {
            $targetColumn = $targetBoard->columns()->orderBy('position')->first();
            if (! $targetColumn) {
                return back()->withErrors(['target_board_id' => 'Target board has no columns.']);
            }
        }

        // Update item (and its whole subtree) to the new board and column; it leaves any
        // sprint, which belongs to the old board.
        DB::transaction(function () use ($item, $targetBoard, $targetColumn) {
            Item::whereIn('id', $item->descendantIds())->update([
                'board_id' => $targetBoard->id,
                'project_id' => $targetBoard->project_id,
                'column_id' => $targetColumn->id,
                'sprint_id' => null,
            ]);
            $item->update([
                'board_id' => $targetBoard->id,
                'project_id' => $targetBoard->project_id,
                'column_id' => $targetColumn->id,
                'sprint_id' => null,
                'parent_id' => null, // a parent on the old board can't own it any more
                'position' => 0,
            ]);
        });

        return back()->with('success', 'Item moved successfully.');
    }

    /**
     * Store a new attachment for an item.
     */
    public function storeAttachment(Request $request, Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $file = $request->file('file');
        $filename = Uploads::name($file);
        $path = $file->storeAs('attachments/'.$item->id, $filename, 'public');

        $attachment = $item->attachments()->create([
            'user_id' => $request->user()->id,
            'filename' => $filename,
            'original_filename' => Uploads::displayName($file),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        // Add URL for response
        $attachment->url = asset('storage/'.$path);

        ActivityLog::log($item, 'attachment_added', null, ['name' => $attachment->original_filename]);

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    /**
     * Delete an attachment.
     */
    public function destroyAttachment(Request $request, Workspace $workspace, Project $project, Board $board, Item $item, Attachment $attachment)
    {
        $this->authorize('update', $item);

        // Ensure attachment belongs to the item
        if ($attachment->item_id !== $item->id) {
            abort(404);
        }

        // Delete file from storage
        \Storage::disk('public')->delete($attachment->path);

        ActivityLog::log($item, 'attachment_removed', null, ['name' => $attachment->original_filename]);

        $attachment->delete();

        return back()->with('success', 'Attachment deleted successfully.');
    }

    /**
     * Newest history entries returned per item.
     */
    private const HISTORY_LIMIT = 200;

    /**
     * What a card on the board (and the item popup's "live" copy) needs — no comment or
     * attachment bodies, just their counts.
     *
     * @param  Builder<Item>|HasMany<Item, BoardColumn>  $query
     */
    private function withCardData($query)
    {
        return $query->orderBy('position')
            ->withCount(['children', 'comments', 'attachments'])
            ->with([
                'assignee:id,name,email,avatar',
                'itemType',
                'priority',
                'labels',
                'release:id,name,status,release_date',
                'parent:id,key,title,board_id',
                'watchers:id,name,email,avatar',
                'outgoingLinks.targetItem:id,key,title,board_id',
                'outgoingLinks.targetItem.board:id,name,slug',
                'incomingLinks.sourceItem:id,key,title,board_id',
                'incomingLinks.sourceItem.board:id,name,slug',
            ]);
    }
}
