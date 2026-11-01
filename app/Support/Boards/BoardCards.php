<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cards a board page shows, as one query (App API `GET /boards/{id}` + `/items`, BRAI-403):
 * issues (subtasks too) in the board's columns; a Scrum board shows only the running sprint (none
 * running → no cards), like `BoardController@show`; a Kanban board leaves out done cards finished
 * before its done retention (BRAI-346, `Board::doneCutoff()`, as resources/js/lib/doneRetention.ts).
 */
final class BoardCards
{
    /** @return Builder<Item> */
    public static function query(Board $board, bool $includeOldDone = false): Builder
    {
        $query = Item::query()
            ->where('items.board_id', $board->id)
            ->whereIn('items.column_id', $board->columns()->select('id'));

        if ($board->isScrum()) {
            $query->where('items.sprint_id', $board->activeSprint()->value('id') ?? 0);
        }

        $cutoff = $includeOldDone ? null : $board->doneCutoff();
        if ($cutoff !== null) {
            // no completion date = never hidden (as the board page)
            $query->where(fn (Builder $q) => $q
                ->whereNull('items.completed_at')
                ->orWhere('items.completed_at', '>=', $cutoff)
                ->orWhereNotIn('items.column_id', $board->columns()->where('is_done_column', true)->select('id')));
        }

        return $query;
    }
}
