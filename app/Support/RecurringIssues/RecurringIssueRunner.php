<?php

namespace App\Support\RecurringIssues;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\RecurringIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the due occurrence of a recurring issue (BRAI-123), for `app:create-recurring-issues` and
 * the e2e-only trigger route.
 *
 * - **Due:** an occurrence date is due once it is today (or earlier) in the template's time zone.
 * - **Idempotent** per (template, occurrence date): the template row is locked, only dates after
 *   `last_occurrence_date` count, and `recurring_issue_occurrences` is unique on (template, date).
 * - **Catch-up:** after downtime only the LATEST missed date is created; the earlier ones are skipped
 *   (never a flood). Nothing before `active_from` (save / schedule change / resume day) is created.
 * - **Actor:** the issue is created as the template's creator (`created_by`, reporter, history,
 *   events), so ItemObserver → Assignments::item() tells the assignee like any other new issue. A
 *   creator who can no longer create issues on the board (or a deleted board / no columns) pauses
 *   the template with `last_error`; whoever resumes it becomes its creator.
 * - **The issue:** `{{date}}` in the title = the occurrence date (Y-m-d); the type, priority, labels
 *   and assignee fall back to the defaults / nobody when they no longer belong to the project; target
 *   column or the first; due date = occurrence + `due_in_days`; no sprint (backlog). Mentions in the
 *   description are not re-notified on every occurrence.
 */
class RecurringIssueRunner
{
    /** Create the due occurrence (if any) at $now; returns the new issue or null. */
    public static function run(RecurringIssue $template, ?CarbonInterface $now = null): ?Item
    {
        $now ??= now();

        return DB::transaction(function () use ($template, $now) {
            $template = RecurringIssue::whereKey($template->getKey())->lockForUpdate()->first();
            if (! $template || $template->paused_at !== null) {
                return null;
            }
            if ($template->isFinished()) {
                $template->update(['next_occurrence_date' => null]);

                return null;
            }

            $date = $template->schedule()->latestBetween($template->earliestNext(), $template->localToday($now));
            if (! $date) {
                $template->refreshNextOccurrence()->save();

                return null;
            }
            if ($template->occurrences()->where('occurrence_date', $date->toDateString())->exists()) {
                $template->fill(['last_occurrence_date' => $date->toDateString()])->refreshNextOccurrence()->save();

                return null;
            }

            [$board, $creator, $problem] = self::check($template);
            if ($problem !== null) {
                $template->update(['paused_at' => $now, 'last_error' => $problem]);

                return null;
            }

            $item = self::actingAs($creator, fn () => self::createIssue($template, $board, $creator, $date));
            $template->occurrences()->create(['occurrence_date' => $date->toDateString(), 'item_id' => $item->id]);

            $template->fill([
                'last_occurrence_date' => $date->toDateString(),
                'occurrences_count' => $template->occurrences_count + 1,
                'last_error' => null,
            ])->refreshNextOccurrence()->save();

            return $item;
        });
    }

    /**
     * @return array{0: Board|null, 1: User|null, 2: string|null} board, creator, the reason it can't run
     */
    private static function check(RecurringIssue $template): array
    {
        $board = Board::with('project.workspace')->find($template->board_id); // soft-deleted boards are gone
        if (! $board || ! $board->project) {
            return [null, null, 'Paused: the board was deleted.'];
        }
        if (! $board->columns()->exists()) {
            return [$board, null, 'Paused: the board has no columns.'];
        }

        $creator = $template->created_by ? User::find($template->created_by) : null;
        if (! $creator || ! $creator->can('createItems', $board)) {
            $name = $creator?->name ?? 'Its creator';

            return [$board, null, "Paused: {$name} can no longer create issues on this board. Resume it to run it as you."];
        }

        return [$board, $creator, null];
    }

    private static function createIssue(RecurringIssue $template, Board $board, User $creator, CarbonImmutable $date): Item
    {
        $project = $board->project;

        $column = $template->column_id ? $board->columns()->whereKey($template->column_id)->first() : null;
        if (! $column instanceof BoardColumn) {
            $column = $board->columns()->first();
        }

        $typeId = $template->item_type_id && ItemType::forProject($project)->whereKey($template->item_type_id)->exists()
            ? $template->item_type_id
            : ItemType::where('is_default', true)->value('id');
        $priorityId = $template->priority_id && ItemPriority::forProject($project)->whereKey($template->priority_id)->exists()
            ? $template->priority_id
            : ItemPriority::where('is_default', true)->value('id');

        $assignee = $template->assigned_to ? User::find($template->assigned_to) : null;
        $assigneeId = $assignee && $assignee->hasProjectAccess($project) ? $assignee->id : null;

        $item = $board->items()->create([
            'title' => Str::limit(str_replace('{{date}}', $date->toDateString(), $template->title), 255, ''),
            'description' => $template->description,
            'project_id' => $project->id,
            'column_id' => $column->id,
            'item_type_id' => $typeId,
            'priority_id' => $priorityId,
            'key' => $project->getNextItemKey(),
            'due_date' => $template->due_in_days !== null ? $date->addDays($template->due_in_days)->toDateString() : null,
            'assigned_to' => $assigneeId,
            'created_by' => $creator->id,
            'reporter_id' => $creator->id,
            'position' => (Item::where('column_id', $column->id)->max('position') ?? 0) + 1,
            'sprint_id' => null,
            'backlog_position' => (Item::where('board_id', $board->id)->whereNull('sprint_id')->max('backlog_position') ?? 0) + 1,
        ]);

        $labelIds = $project->labels()->whereIn('id', array_map('intval', $template->label_ids ?? []))->pluck('id');
        if ($labelIds->isNotEmpty()) {
            $item->labels()->sync($labelIds);
        }

        return $item;
    }

    /**
     * Run $callback signed in as $user (the console has no user; the e2e route's user is put back).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function actingAs(User $user, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->hasUser() ? $guard->user() : null;
        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
