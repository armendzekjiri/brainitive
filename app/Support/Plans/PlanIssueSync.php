<?php

namespace App\Support\Plans;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;

/**
 * Plan rows linked to board issues (`itemId`). The issue is the source of truth; edits made
 * in the plan are written through to the issue on save.
 */
class PlanIssueSync
{
    /**
     * @param  array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}  $data
     * @return list<int>
     */
    public static function linkedIds(array $data): array
    {
        return array_values(array_unique(array_filter(array_map(fn (array $row) => $row['itemId'] ?? null, $data['rows'] ?? []))));
    }

    /**
     * Drop links to issues outside the project (or deleted ones).
     *
     * @param  array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}  $data
     * @return array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}
     */
    public static function restrictToProject(Project $project, array $data): array
    {
        $ids = self::linkedIds($data);
        if ($ids === []) {
            return $data;
        }
        $valid = array_flip(Item::whereIn('id', $ids)
            ->whereIn('board_id', Board::where('project_id', $project->id)->select('id'))
            ->pluck('id')
            ->all());

        $data['rows'] = array_map(function (array $row) use ($valid) {
            if (isset($row['itemId']) && ! isset($valid[$row['itemId']])) {
                unset($row['itemId']);
            }

            return $row;
        }, $data['rows']);

        return $data;
    }

    /**
     * Write name / date changes of linked rows to their issues. Only fields that differ from
     * the stored plan are written, so saving an old copy never reverts a newer board change.
     *
     * @param  array<string, mixed>|null  $stored
     * @param  array{rows: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}  $next
     * @return int number of issues updated
     */
    public static function writeThrough(?array $stored, array $next, User $user): int
    {
        $before = collect($stored['rows'] ?? [])->keyBy('id');
        $changes = [];

        foreach ($next['rows'] as $row) {
            $old = $before->get($row['id']);
            if (! isset($row['itemId']) || ! $old || ($old['itemId'] ?? null) !== $row['itemId']) {
                continue;
            }
            $patch = [];
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && $name !== trim((string) ($old['name'] ?? ''))) {
                $patch['title'] = mb_substr($name, 0, 255);
            }
            [$oldStart, $oldDue] = self::dates($old);
            [$start, $due] = self::dates($row);
            if ($start !== $oldStart) {
                $patch['start_date'] = $start;
            }
            if ($due !== $oldDue) {
                $patch['due_date'] = $due;
            }
            if ($patch !== []) {
                $changes[$row['itemId']] = $patch;
            }
        }

        if ($changes === []) {
            return 0;
        }

        $updated = 0;
        Item::with('board.project.workspace')->whereIn('id', array_keys($changes))->get()
            ->each(function (Item $item) use ($changes, $user, &$updated) {
                if (! $user->can('update', $item)) {
                    return;
                }
                $item->fill($changes[$item->id]);
                if ($item->isDirty()) {
                    $item->save();
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * Start and due date a row stands for: the span of its bars; a lone milestone is a due date.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string|null, 1: string|null}
     */
    public static function dates(array $row): array
    {
        $bars = $row['bars'] ?? [];
        if ($bars === []) {
            return [null, null];
        }
        if (count($bars) === 1 && ($bars[0]['type'] ?? 'bar') === 'milestone') {
            return [null, $bars[0]['start']];
        }

        return [min(array_column($bars, 'start')), max(array_column($bars, 'end'))];
    }
}
