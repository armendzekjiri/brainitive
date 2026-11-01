<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\ActivityLog;
use App\Models\Item;
use App\Support\Boards\ItemFieldValues;
use App\Support\Live\BoardLive;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a card/item on a board: title, description (Markdown), dates, assignee, type, priority, labels (the full list — replaces the current ones), story points, parent and custom field values. Pass only the fields to change. To move it to another column use move_board_item.')]
class UpdateBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'due_time' => 'sometimes|nullable|date_format:H:i',
            'assignee' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|nullable|string|max:100',
            'priority' => 'sometimes|nullable|string|max:50',
            'labels' => 'sometimes|array|max:50',
            'labels.*' => 'string|max:50',
            'story_points' => 'sometimes|nullable|integer|min:0|max:1000',
            'parent_id' => 'sometimes|nullable|integer',
            'fields' => 'sometimes|array|max:50',
        ]);

        $user = $request->user();

        return $this->guard(function () use ($user, $validated) {
            $item = $this->boardItemForUser($user, $validated['workspace'], $validated['project'], $validated['board'], (int) $validated['item_id']);
            $board = $item->board;
            $project = $board->project;
            $this->authorizeFor($user, 'update', $item);

            $changes = [];
            foreach (['title', 'start_date', 'start_time', 'due_date', 'due_time', 'story_points'] as $key) {
                if (array_key_exists($key, $validated)) {
                    $changes[$key] = $validated[$key];
                }
            }
            if (array_key_exists('description', $validated)) {
                $changes['description'] = ! empty($validated['description'])
                    ? PageContentConverter::markdownToHtml($validated['description'])
                    : null;
            }
            if (array_key_exists('assignee', $validated)) {
                $changes['assigned_to'] = $this->resolveAssigneeId($user, $project, $validated['assignee']);
            }
            if (array_key_exists('type', $validated)) {
                $changes['item_type_id'] = $this->itemTypeIdFor($project, $validated['type'], $item->itemType) ?? $item->item_type_id;
            }
            if (array_key_exists('priority', $validated)) {
                $changes['priority_id'] = $this->priorityIdFor($project, $validated['priority']) ?? $item->priority_id;
            }
            if (array_key_exists('parent_id', $validated)) {
                $changes['parent_id'] = $this->parentIdFor($item, $validated['parent_id']);
            }

            $labelIds = $this->labelIdsFor($project, $validated['labels'] ?? null);
            $fields = $this->fieldValuesFor($board, $validated['fields'] ?? null);

            if ($changes === [] && $labelIds === null && $fields === null) {
                throw new ToolError('Provide at least one field to update.');
            }

            if ($changes !== []) {
                $item->update($changes);
            }
            if ($labelIds !== null) {
                $result = $item->labels()->sync($labelIds);
                if ($result['attached'] !== [] || $result['detached'] !== []) {
                    BoardLive::itemIds([$item->id], ['labels'], $item->board_id);
                    $names = $project->labels()->whereIn('id', array_merge($result['attached'], $result['detached']))->pluck('name', 'id');
                    ActivityLog::log($item, 'labels_changed', ['labels' => [
                        'added' => collect($result['attached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                        'removed' => collect($result['detached'])->map(fn ($id) => $names[$id] ?? "#{$id}")->values()->all(),
                    ]]);
                }
            }
            if ($fields !== null) {
                ItemFieldValues::apply($item, $fields);
            }

            return Response::structured([
                'updated' => true,
                'item_key' => $item->key ?? '',
                'location' => ResourceLocator::locate($item->fresh()),
            ]);
        });
    }

    /**
     * Same rules as the issue dialog: same board, 3 levels at most, no cycles.
     *
     * @throws ToolError
     */
    private function parentIdFor(Item $item, ?int $parentId): ?int
    {
        if ($parentId === null) {
            return null;
        }
        if ($parentId === $item->id) {
            throw new ToolError('A card cannot be its own parent.');
        }
        $parent = $item->board->items()->find($parentId);
        if (! $parent) {
            throw new ToolError('No card with that parent_id on this board. Call get_board to see item ids.');
        }
        if ($parent->depth() + 1 + $item->subtreeHeight() > Item::MAX_DEPTH) {
            throw new ToolError('Cards nest 3 levels at most (issue → subtask → sub-subtask).');
        }
        for ($current = $parent; $current && $current->parent_id !== null; $current = Item::find($current->parent_id)) {
            if ($current->parent_id === $item->id) {
                throw new ToolError('That would make a cycle: the parent is already below this card.');
            }
        }

        return $parent->id;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'item_key' => $schema->string(),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'item_id' => $schema->integer()->description('The item id (from get_board).')->required(),
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New card body as Markdown.'),
            'start_date' => $schema->string()->description('New start date YYYY-MM-DD (empty to clear).'),
            'start_time' => $schema->string()->description('New start time HH:MM (24h).'),
            'due_date' => $schema->string()->description('New due date YYYY-MM-DD (empty to clear).'),
            'due_time' => $schema->string()->description('New due time HH:MM (24h).'),
            'assignee' => $schema->string()->description('Reassign: "me", a member email, or empty to unassign.'),
            'type' => $schema->string()->description('Item type name or slug.'),
            'priority' => $schema->string()->description('Priority name or slug.'),
            'labels' => $schema->array()->items($schema->string())->description('The full list of label names the card should have (empty list removes all).'),
            'story_points' => $schema->integer()->description('Story points (null clears).'),
            'parent_id' => $schema->integer()->description('Parent card id, or null to make it top-level.'),
            'fields' => $schema->object([])->description('Custom field values keyed by field name or id; null clears a value.'),
        ];
    }
}
