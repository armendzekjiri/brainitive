<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Workspace;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $slug = \Illuminate\Support\Str::slug($validated['name']);
        $originalSlug = $slug;
        $counter = 1;

        while ($project->boards()->where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
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

        $board->load([
            'columns' => function ($query) {
                $query->orderBy('position')->with([
                    'items' => function ($q) {
                        $q->orderBy('position')
                            ->withCount('children')
                            ->with([
                                'assignee:id,name,email,avatar',
                                'itemType',
                                'priority',
                                'labels',
                                'parent:id,key,title,board_id',
                                'attachments' => function ($a) {
                                    $a->with('user:id,name,email')->orderBy('created_at', 'desc');
                                },
                                'comments' => function ($c) {
                                    $c->whereNull('parent_id')
                                        ->with(['user:id,name,email', 'replies'])
                                        ->orderBy('created_at', 'desc');
                                }
                            ]);
                    }
                ]);
            },
        ]);

        // Add URLs to attachments
        foreach ($board->columns as $column) {
            foreach ($column->items as $item) {
                foreach ($item->attachments as $attachment) {
                    $attachment->url = asset('storage/' . $attachment->path);
                    $attachment->formatted_size = $attachment->formatted_size;
                }
            }
        }

        $itemTypes = ItemType::forProject($project)->get();
        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $projectLabels = $project->labels()->orderBy('name')->get();

        return Inertia::render('Boards/Show', [
            'workspace' => $workspace,
            'project' => $project,
            'board' => $board,
            'itemTypes' => $itemTypes,
            'priorities' => $priorities,
            'projectUsers' => $projectUsers,
            'projectLabels' => $projectLabels,
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
        ]);

        $board->update($validated);

        return redirect()->back()->with('success', 'Board updated successfully.');
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

        $slug = \Illuminate\Support\Str::slug($validated['name']);
        $maxPosition = $board->columns()->max('position') ?? 0;

        $board->columns()->create([
            'name' => $validated['name'],
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
     * Delete a column.
     */
    public function destroyColumn(Workspace $workspace, Project $project, Board $board, BoardColumn $column)
    {
        $this->authorize('update', $board);

        // Check if column has items
        if ($column->items()->count() > 0) {
            return redirect()->back()->withErrors(['column' => 'Cannot delete column with items. Move or delete items first.']);
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
            'column_id' => 'required|exists:board_columns,id',
            'item_type_id' => 'nullable|exists:item_types,id',
            'priority_id' => 'nullable|exists:item_priorities,id',
            'assigned_to' => 'nullable|exists:users,id',
            'label_ids' => 'nullable|array',
            'label_ids.*' => 'exists:labels,id',
            'parent_id' => [
                'nullable',
                'exists:items,id',
                function ($attribute, $value, $fail) use ($board, $subtaskTypeId, $request) {
                    if ($value === null) {
                        return;
                    }
                    $parent = Item::find($value);
                    if (! $parent || $parent->board_id !== $board->id) {
                        $fail('Parent task must be on the same board.');
                        return;
                    }
                    if ($parent->parent_id !== null) {
                        $fail('Parent task must be a root task.');
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

        $item = $board->items()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'column_id' => $validated['column_id'],
            'parent_id' => $validated['parent_id'] ?? null,
            'item_type_id' => $itemTypeId,
            'priority_id' => $validated['priority_id'] ?? ItemPriority::where('is_default', true)->first()?->id,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'key' => $project->getNextItemKey(),
            'created_by' => $request->user()->id,
            'reporter_id' => $request->user()->id,
            'position' => $maxPosition + 1,
        ]);

        // Sync labels if provided
        if (!empty($validated['label_ids'])) {
            $validLabels = $project->labels()->whereIn('id', $validated['label_ids'])->pluck('id');
            $item->labels()->sync($validLabels);
        }

        return back()->with('success', 'Item created successfully.');
    }

    /**
     * Return root (non-subtask) items for a board as JSON (used by global create modal).
     */
    public function rootItemsJson(Request $request, Workspace $workspace, Project $project, Board $board): JsonResponse
    {
        $this->authorize('view', $board);

        $items = $board->items()
            ->whereNull('parent_id')
            ->select('id', 'key', 'title', 'column_id')
            ->orderBy('position')
            ->get();

        return response()->json($items);
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
            $attachment->url = asset('storage/' . $attachment->path);
        }

        $itemTypes = ItemType::forProject($project)->get();
        $priorities = ItemPriority::forProject($project)->orderBy('order')->get();
        $projectUsers = $project->workspace->users()->get(['users.id', 'name', 'email', 'avatar']);
        $columns = $board->columns()->orderBy('position')->get();
        $projectLabels = $project->labels()->orderBy('name')->get();

        // Get all items in the project for linking (excluding current item)
        $projectItems = Item::whereHas('board', function($q) use ($project) {
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
            'column_id' => 'required|exists:board_columns,id',
            'position'  => 'required|integer|min:0',
        ]);

        $oldColumn = $item->column;
        $newColumn = BoardColumn::findOrFail($validated['column_id']);

        $item->moveToColumn($newColumn, $validated['position']);

        // Notify all watchers when the column (status) actually changed
        if ($oldColumn && $oldColumn->id !== $newColumn->id) {
            $item->load('watchers');
            $actorId = $request->user()->id;

            foreach ($item->watchers as $watcher) {
                // Don't notify the person who performed the move
                if ($watcher->id === $actorId) continue;

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
            'due_date' => 'nullable|date',
            'story_points' => 'nullable|integer|min:0',
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
                    if ($parent->parent_id !== null) {
                        $fail('Parent task must be a root task.');
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

        $item->update($validated);

        return back()->with('success', 'Item updated successfully.');
    }

    /**
     * Delete an item.
     */
    public function destroyItem(Workspace $workspace, Project $project, Board $board, Item $item)
    {
        $this->authorize('delete', $item);

        $item->delete();

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
            'parent_id' => 'nullable|exists:comments,id',
        ]);

        $item->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

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
        $item->labels()->sync($validLabels);

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

        ItemLink::create([
            'source_item_id' => $item->id,
            'target_item_id' => $validated['target_item_id'],
            'link_type' => $validated['link_type'],
            'created_by' => $request->user()->id,
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

        // Check if user has access to target board
        $this->authorize('view', $targetBoard);

        // Get target column - either specified or first column of target board
        if (!empty($validated['target_column_id'])) {
            $targetColumn = BoardColumn::where('id', $validated['target_column_id'])
                ->where('board_id', $targetBoard->id)
                ->firstOrFail();
        } else {
            $targetColumn = $targetBoard->columns()->orderBy('position')->first();
            if (!$targetColumn) {
                return back()->withErrors(['target_board_id' => 'Target board has no columns.']);
            }
        }

        // Update item to new board and column
        $item->update([
            'board_id' => $targetBoard->id,
            'column_id' => $targetColumn->id,
            'position' => 0,
        ]);

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
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('attachments/' . $item->id, $filename, 'public');

        $attachment = $item->attachments()->create([
            'user_id' => $request->user()->id,
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        // Add URL for response
        $attachment->url = asset('storage/' . $path);

        return back()->with('success', 'Attachment uploaded successfully.');
    }

    /**
     * Delete an attachment.
     */
    public function destroyAttachment(Request $request, Workspace $workspace, Project $project, Board $board, Item $item, \App\Models\Attachment $attachment)
    {
        $this->authorize('update', $item);

        // Ensure attachment belongs to the item
        if ($attachment->item_id !== $item->id) {
            abort(404);
        }

        // Delete file from storage
        \Storage::disk('public')->delete($attachment->path);

        $attachment->delete();

        return back()->with('success', 'Attachment deleted successfully.');
    }
}
