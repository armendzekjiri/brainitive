<?php

namespace App\Support\Offline\Appliers;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\User;
use App\Notifications\ItemStatusChangedNotification;
use App\Rules\ProjectMember;
use App\Support\Boards\IssueMarkdown;
use App\Support\Boards\Issues;
use App\Support\Boards\ItemComments;
use App\Support\Offline\FieldMerge;
use App\Support\Offline\MutationContext;
use App\Support\Offline\SyncAbort;
use App\Support\Offline\SyncOutcome;
use App\Support\Validation\ContentLimits;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        $data = $ctx->validate(['body' => ['required', ...ContentLimits::comment()]]);
        $item = self::item($ctx);
        Gate::forUser($ctx->user)->authorize('update', $item); // as BoardController::storeComment

        $html = '<p>'.nl2br(e(trim($data['body'])), false).'</p>';
        // Last step before the commit (only the outcome row is written after it), like storeComment:
        // mention / comment notifications go out from ItemComments, as for any other comment.
        $comment = ItemComments::add($item, $ctx->user, $html);

        return SyncOutcome::applied('comment', [
            'id' => $comment->id,
            'item_id' => $item->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], $comment->id);
    }

    /**
     * A new issue: recreating one that was deleted while the device was offline (the web's conflicts panel), or
     * one created offline in an app (App API sync). The same as the online create (`Issues::create`, the issue
     * dialog and `POST /boards/{id}/items`): the dialog's rules for the fields it takes, the project's default
     * type / priority, a Scrum board's running sprint, the reporter watching, mentions notified. `description`
     * is Markdown (mentions as `@[Name](user:<id>)`) like the REST create; `item_type_id` must be a type of the
     * board's project. data: `{board_id, title, column_id?, priority_id?, assigned_to?, due_date?, description?,
     * item_type_id?}`.
     */
    public static function create(MutationContext $ctx): SyncOutcome
    {
        $data = $ctx->validate(['board_id' => 'required|integer']);
        $board = Board::with('project.workspace')->find($data['board_id']);
        if (! $board?->project?->workspace || ! $ctx->user->hasProjectAccess($board->project)) {
            throw new SyncAbort(SyncOutcome::deleted('board', 'This board was deleted while you were offline.'));
        }
        Gate::forUser($ctx->user)->authorize('createItems', $board);
        $project = $board->project;

        $rules = Arr::only(Issues::rules($project, $board), ['title', 'priority_id', 'assigned_to', 'due_date', 'item_type_id']);
        $data = $ctx->validate($rules + [
            // the board's first column when not given
            'column_id' => ['nullable', 'integer', Rule::exists('board_columns', 'id')->where('board_id', $board->id)],
            'description' => ['nullable', ...ContentLimits::body()],
        ]);

        $data['column_id'] ??= $board->columns()->value('id');
        if ($data['column_id'] === null) {
            throw ValidationException::withMessages(['board_id' => 'This board has no columns yet.']);
        }
        if (isset($data['description'])) {
            $data['description'] = IssueMarkdown::toHtml($data['description'], $project);
        }
        $item = Issues::create($board, $ctx->user, $data);

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
        // a trashed board / project / workspace counts as deleted (and must never reach a policy with null)
        if (! $item?->board?->project?->workspace || ! $ctx->user->can('view', $item)) {
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
        // at the end of the column, so it never ties with the card on top
        $item->moveToColumn($column, (int) Item::where('column_id', $column->id)->max('position') + 1);

        if (! $old || $old->id === $column->id) {
            return;
        }
        // after commit: a rolled-back sync (or its retry) never tells anyone about a move twice
        DB::afterCommit(function () use ($item, $old, $column, $actor) {
            $project = $item->board->project;
            $item->load('watchers');
            foreach ($item->watchers as $watcher) {
                if ($watcher->id !== $actor->id && $watcher->hasProjectAccess($project)) {
                    $watcher->notify(new ItemStatusChangedNotification(item: $item, oldColumn: $old, newColumn: $column, actor: $actor));
                }
            }
        });
    }
}
