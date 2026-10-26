<?php

namespace App\Support\Github;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemDevLink;

/**
 * "When the primary linked PR is merged → move the card to column X" (BRAI-61), per board, off by
 * default (`boards.settings.github.merge_column_id`).
 *
 * - Primary PR = the PR link marked `primary`, else the first linked PR. Other PRs are informational.
 * - Each merged PR is evaluated once (`meta.merge_transition`), so a redelivery, a retry or a later
 *   change of the setting never moves the card again.
 * - Closed without merge does nothing. A card in a done column is never moved (never reopens).
 * - The move is a normal model save (bottom of the column, completed_at like any move), so the
 *   history (ItemObserver, marked "via GitHub"), outgoing webhooks and automation rules see it.
 */
final class MergeTransitions
{
    public const SETTING = 'github.merge_column_id';

    public static function column(Board $board): ?BoardColumn
    {
        $id = (int) data_get($board->settings, self::SETTING, 0);

        return $id > 0 ? BoardColumn::where('board_id', $board->id)->find($id) : null;
    }

    public static function primaryId(int $itemId): ?int
    {
        $id = ItemDevLink::where('item_id', $itemId)->where('kind', 'pull_request')
            ->orderByDesc('primary')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /** After DevelopmentSync saved a pull request link. */
    public static function pullRequestSaved(ItemDevLink $link): void
    {
        if ($link->kind !== 'pull_request' || $link->state !== 'merged' || isset($link->meta['merge_transition'])) {
            return;
        }

        $result = self::primaryId($link->item_id) === $link->id ? self::move($link) : 'secondary';

        $meta = $link->meta ?? [];
        $meta['merge_transition'] = ['at' => now()->toIso8601String(), 'result' => $result];
        $link->forceFill(['meta' => $meta])->save();
    }

    private static function move(ItemDevLink $link): string
    {
        $item = Item::with(['board', 'column'])->find($link->item_id);
        if (! $item || ! $item->board) {
            return 'missing';
        }
        $column = self::column($item->board);
        if (! $column) {
            return 'off';
        }
        if ((int) $item->column_id === $column->id) {
            return 'already';
        }
        if ($item->column?->is_done_column) {
            return 'done'; // never move a finished card (never reopens)
        }

        ActivityLog::via('github', function () use ($item, $column) {
            $item->column_id = $column->id;
            $item->position = (int) (Item::where('column_id', $column->id)->max('position') ?? 0) + 1;
            $item->completed_at = $column->is_done_column ? now() : null;
            $item->save();
        });

        return 'moved';
    }
}
