<?php

namespace App\Support\Offline\Appliers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\User;
use App\Notifications\ItemStatusChangedNotification;
use App\Rules\ProjectMember;
use App\Support\Boards\ItemComments;
use App\Support\Boards\ProjectIssues;
use App\Support\Offline\FieldMerge;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Board issues changed offline (BRAI-82): field edits (title, status, priority, assignee, due
 * date), comments, and "recreate" after a delete. Same policies as the board
 * (`ItemPolicy::update`, `BoardPolicy::createItems`); history and notifications come from the
 * model observers and `ItemComments`, as for any other edit.
 */
final class IssueMutations
{
    public const ENTITY = 'issue';

    private const KINDS = [
        'title' => FieldMerge::STRING,
        'column_id' => FieldMerge::INT,
        'priority_id' => FieldMerge::INT,
        'assigned_to' => FieldMerge::INT,
        'due_date' => FieldMerge::DATE,
    ];

    public static function update(MutationContext $ctx): SyncOutcome
    {
        $item = self::item($ctx, lock: true);
        Gate::forUser($ctx->user)->authorize('update', $item);
        $project = $item->board->project;

        $changes = $ctx->validate([
            'title' => 'sometimes|required|string|max:255',
            'column_id' => ['sometimes', 'required', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $item->board_id)],
            'priority_id' => ['sometimes', 'nullable', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->all())],
            'assigned_to' => ['sometimes', 'nullable', 'integer', new ProjectMember($project)],
            'due_date' => 'sometimes|nullable|date',
        ]);
        $base = $ctx->baseFor($changes);

        $merge = FieldMerge::merge(self::fields($item), $base, $changes, self::KINDS);
        $apply = $merge['apply'];

        if (array_key_exists('column_id', $apply)) {
            self::moveTo($item, BoardColumn::findOrFail($apply['column_id']), $ctx->user);
            unset($apply['column_id']);
        }
        if ($apply !== []) {
            // A new assignee hears about it from ItemObserver, history too.
            $item->update($apply);
        }

        $snapshot = self::snapshot($item->refresh());

        return $merge['conflicts'] === []
            ? SyncOutcome::applied(self::ENTITY, $snapshot, $item->id)
            : SyncOutcome::conflict(self::ENTITY, $snapshot, $item->id, $merge['conflicts'], array_keys($merge['apply']));
    }

    /** A plain-text comment written offline (also "keep both" for a conflicting title). */
    public static function comment(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['body' => 'required|string|max:10000']);
        $item = self::item($ctx);
        Gate::forUser($ctx->user)->authorize('update', $item); // as BoardController::storeComment

        $html = '<p>'.nl2br(e(trim($data['body'])), false).'</p>';
        $comment = ItemComments::add($item, $ctx->user, $html);

        return SyncOutcome::applied('comment', [
            'id' => $comment->id,
            'item_id' => $item->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], $comment->id);
    }

    /** Recreate an issue that was deleted while the device was offline (offered by the conflicts panel). */
    public static function create(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['board_id' => 'required|integer']);
        $board = Board::with('project')->find($data['board_id']);
        if (! $board || ! $board->project || ! $ctx->user->hasProjectAccess($board->project)) {
            throw new SyncAbort(SyncOutcome::deleted('board', 'This board was deleted while you were offline.'));
        }
        Gate::forUser($ctx->user)->authorize('createItems', $board);
        $project = $board->project;

        $data = $ctx->validate([
            'title' => 'required|string|max:255',
            'column_id' => ['nullable', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'priority_id' => ['nullable', 'integer', Rule::in(ItemPriority::forProject($project)->pluck('id')->all())],
            'assigned_to' => ['nullable', 'integer', new ProjectMember($project)],
            'due_date' => 'nullable|date',
        ]);

        try {
            $item = ProjectIssues::create($board, $project, $ctx->user, [[
                'title' => $data['title'],
                'due_date' => $data['due_date'] ?? null,
                'assigned_to' => $data['assigned_to'] ?? null,
            ]], isset($data['column_id']) ? BoardColumn::find($data['column_id']) : null)->first();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['board_id' => $e->getMessage()]);
        }
        if (! empty($data['priority_id'])) {
            $item->update(['priority_id' => $data['priority_id']]);
        }

        return SyncOutcome::applied(self::ENTITY, self::snapshot($item->refresh()), $item->id);
    }

    /** @return array<string, mixed> */
    public static function snapshot(Item $item): array
    {
        return [
            'id' => $item->id,
            'key' => $item->key,
            'board_id' => $item->board_id,
            'title' => $item->title,
            'column_id' => $item->column_id,
            'priority_id' => $item->priority_id,
            'assigned_to' => $item->assigned_to,
            'due_date' => $item->due_date?->format('Y-m-d'),
            'completed_at' => $item->completed_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /** Deleted and "no longer visible to you" get the same answer, so nothing leaks. */
    private static function item(MutationContext $ctx, bool $lock = false): Item
    {
        $item = Item::with('board.project.workspace')->when($lock, fn ($q) => $q->lockForUpdate())->find($ctx->targetId(self::ENTITY));
        if (! $item || ! $item->board || ! $item->board->project || ! $ctx->user->can('view', $item)) {
            throw new SyncAbort(SyncOutcome::deleted(self::ENTITY, 'This issue was deleted while you were offline.'));
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private static function fields(Item $item): array
    {
        return [
            'title' => $item->title,
            'column_id' => $item->column_id,
            'priority_id' => $item->priority_id,
            'assigned_to' => $item->assigned_to,
            'due_date' => $item->due_date,
        ];
    }

    /** Same as BoardController::moveItem: move, then tell the watchers about the new status. */
    private static function moveTo(Item $item, BoardColumn $column, User $actor): void
    {
        $old = $item->column;
        $item->moveToColumn($column, 0);

        if (! $old || $old->id === $column->id) {
            return;
        }
        $project = $item->board->project;
        $item->load('watchers');
        foreach ($item->watchers as $watcher) {
            if ($watcher->id !== $actor->id && $watcher->hasProjectAccess($project)) {
                $watcher->notify(new ItemStatusChangedNotification(item: $item, oldColumn: $old, newColumn: $column, actor: $actor));
            }
        }
    }
}
