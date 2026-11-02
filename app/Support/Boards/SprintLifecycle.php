<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\Sprint;
use App\Support\Events\DomainEvents;
use App\Support\Events\EventPayloads;
use App\Support\Notifications\SprintNotifications;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Starting / completing sprints with the board row locked, so two concurrent starts can't
 * leave two active sprints and a retried completion can't run twice. Used by the backlog UI
 * and the MCP sprint tools. Throws DomainException with a user-facing message on conflicts.
 */
class SprintLifecycle
{
    /**
     * Move top-level issues of the board into a planned / active sprint, or the backlog with null (the MCP's
     * move_items_to_sprint, the App API). Subtasks follow their parent; each lands at the end of its new
     * list; issues already there stay where they are.
     *
     * @param  list<int>  $itemIds
     * @return int how many issues changed their sprint
     *
     * @throws DomainException a completed sprint / another board's sprint, or ids that aren't top-level issues of the board (nothing moves)
     */
    public static function moveIssues(Board $board, ?Sprint $sprint, array $itemIds): int
    {
        if ($sprint && ($sprint->board_id !== $board->id || $sprint->isCompleted())) {
            throw new DomainException('Issues can only move into a planned or active sprint, or the backlog.');
        }

        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        $items = $board->items()->whereIn('id', $ids)->whereNull('parent_id')->get()->keyBy('id');
        $invalid = array_values(array_diff($ids, $items->keys()->all()));
        if ($invalid !== []) {
            throw new DomainException('Not top-level issues of this board: '.implode(', ', $invalid).'. Nothing was moved.');
        }

        return DB::transaction(function () use ($ids, $items, $sprint) {
            $moved = 0;
            foreach ($ids as $id) {
                $item = $items[$id];
                if ($item->sprint_id !== $sprint?->id) {
                    $item->moveToSprint($sprint);
                    $moved++;
                }
            }

            return $moved;
        });
    }

    public static function start(Board $board, Sprint $sprint, ?string $startDate, ?string $endDate, ?string $goal = null, ?string $name = null): Sprint
    {
        return DB::transaction(function () use ($board, $sprint, $startDate, $endDate, $goal, $name) {
            Board::whereKey($board->id)->lockForUpdate()->first();
            $sprint = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if (! $sprint->isPlanned()) {
                throw new DomainException("This sprint was already started (it is {$sprint->status}).");
            }
            if ($board->sprints()->where('status', Sprint::STATUS_ACTIVE)->exists()) {
                throw new DomainException('Complete the active sprint before starting another one.');
            }
            if (! $sprint->items()->exists()) {
                throw new DomainException('Add at least one issue to the sprint before starting it.');
            }

            if ($name !== null) {
                $sprint->name = $name;
            }
            $sprint->start($startDate, $endDate, $goal);
            DomainEvents::emit(EventPayloads::projectWorkspaceId($board->project_id), 'sprint.started', fn () => EventPayloads::sprint($sprint, $board)); // webhooks (BRAI-104)
            SprintNotifications::started($sprint); // BRAI-103: assignees of its issues, after commit

            return $sprint;
        });
    }

    /**
     * @return int open top-level issues moved to $moveOpenTo (or the backlog)
     */
    public static function complete(Board $board, Sprint $sprint, ?Sprint $moveOpenTo): int
    {
        return DB::transaction(function () use ($board, $sprint, $moveOpenTo) {
            Board::whereKey($board->id)->lockForUpdate()->first();
            $sprint = Sprint::whereKey($sprint->id)->lockForUpdate()->firstOrFail();

            if (! $sprint->isActive()) {
                throw new DomainException("Only the active sprint can be completed (this one is {$sprint->status}).");
            }
            if ($moveOpenTo && ($moveOpenTo->board_id !== $board->id || ! $moveOpenTo->isPlanned())) {
                throw new DomainException('Unfinished issues can only move to the backlog or a planned sprint of this board.');
            }

            $recipients = SprintNotifications::recipientIds($sprint); // BRAI-103: before open issues move out
            $moved = $sprint->complete($moveOpenTo);
            SprintNotifications::completed($sprint, $recipients, $moved);
            DomainEvents::emit(EventPayloads::projectWorkspaceId($board->project_id), 'sprint.completed', fn () => EventPayloads::sprint($sprint, $board, [ // webhooks (BRAI-104)
                'open_issues_moved' => $moved,
                'open_issues_moved_to' => $moveOpenTo ? ['id' => $moveOpenTo->id, 'name' => $moveOpenTo->name] : 'backlog',
            ]));

            return $moved;
        });
    }
}
