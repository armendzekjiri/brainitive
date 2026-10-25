<?php

namespace App\Support\Events;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\User;

/**
 * Item model changes → webhook events (BRAI-104), called from ItemObserver so the board UI, MCP,
 * imports and bulk actions are all covered. One save can raise several events:
 * column/board change → item.moved; completed_at set → item.completed; a new assignee →
 * item.assigned; any other tracked field → item.updated (with the changed field names).
 * created / moved / assigned pass the item as subject, so automation rules (BRAI-63) see them.
 */
final class ItemEvents
{
    /** Fields that raise item.updated (moves, completion and assignment have their own events). */
    private const UPDATE_FIELDS = [
        'title', 'description', 'sprint_id', 'release_id', 'priority_id', 'item_type_id', 'parent_id',
        'story_points', 'original_estimate', 'start_date', 'start_time', 'due_date', 'due_time',
    ];

    public static function created(Item $item): void
    {
        DomainEvents::emit(EventPayloads::itemWorkspaceId($item), 'item.created', fn () => ['item' => EventPayloads::item($item)], subject: $item);
    }

    public static function updated(Item $item): void
    {
        $moved = $item->wasChanged(['column_id', 'board_id']);
        $completed = $item->wasChanged('completed_at') && $item->completed_at !== null;
        $assigned = $item->wasChanged('assigned_to') && $item->assigned_to !== null;
        $fields = array_values(array_filter(self::UPDATE_FIELDS, fn (string $field) => $item->wasChanged($field)));
        if (! $moved && ! $completed && ! $assigned && $fields === []) {
            return;
        }

        $workspaceId = EventPayloads::itemWorkspaceId($item);

        if ($moved) {
            $from = $item->getOriginal('column_id');
            DomainEvents::emit($workspaceId, 'item.moved', fn () => [
                'item' => EventPayloads::item($item),
                'from' => ['column' => $from ? BoardColumn::whereKey($from)->value('name') : null, 'board_id' => $item->getOriginal('board_id')],
                'to' => ['column' => $item->column_id ? BoardColumn::whereKey($item->column_id)->value('name') : null, 'board_id' => $item->board_id],
            ], subject: $item, context: ['from_column_id' => $from ? (int) $from : null, 'to_column_id' => $item->column_id ? (int) $item->column_id : null]);
        }
        if ($completed) {
            DomainEvents::emit($workspaceId, 'item.completed', fn () => ['item' => EventPayloads::item($item)]);
        }
        if ($assigned) {
            $previous = $item->getOriginal('assigned_to');
            DomainEvents::emit($workspaceId, 'item.assigned', fn () => [
                'item' => EventPayloads::item($item),
                'previous_assignee' => $previous ? EventPayloads::person(User::find($previous)) : null,
            ], subject: $item, context: ['assignee_id' => (int) $item->assigned_to]);
        }
        if ($fields !== []) {
            DomainEvents::emit($workspaceId, 'item.updated', fn () => ['item' => EventPayloads::item($item), 'changed' => $fields]);
        }
    }

    public static function deleted(Item $item): void
    {
        DomainEvents::emit(EventPayloads::itemWorkspaceId($item), 'item.deleted', fn () => [
            'item' => EventPayloads::item($item),
            'permanent' => $item->isForceDeleting(),
        ]);
    }
}
