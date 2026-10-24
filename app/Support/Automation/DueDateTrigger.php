<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Board;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The scheduled "Due date passed" trigger (v1.40.0, BRAI-63), run by app:run-due-date-automations.
 *
 * An open issue's due date has passed when the due day is over, or — with a due time — that time has
 * passed, both in the rule actor's time zone (due dates are wall-clock values). The event id is
 * `item.due:<id>:<due date>`, so each issue starts a rule once per due date however often this runs;
 * a new due date starts it again. Only due dates of the last LOOKBACK_DAYS count, so switching a rule
 * on never floods old issues (and a scheduler that was down catches up within that window).
 */
final class DueDateTrigger
{
    public const LOOKBACK_DAYS = 2;

    /** Most new events one rule handles per run (the rest follow on the next run). */
    public const BATCH = 200;

    /** @return int the number of runs created */
    public static function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        AutomationRule::where('trigger', 'item.due')->where('enabled', true)->orderBy('id')
            ->each(function (AutomationRule $rule) use ($now, &$created) {
                $actor = $rule->actor_id ? User::find($rule->actor_id) : null;
                $local = $now->setTimezone($actor?->localTimezone() ?? config('app.timezone'));
                $today = $local->toDateString();

                $events = [];
                Item::query()
                    ->whereIn('board_id', Board::where('project_id', $rule->project_id)->select('id'))
                    ->whereNull('completed_at')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '>=', $local->subDays(self::LOOKBACK_DAYS)->toDateString())
                    ->whereDate('due_date', '<=', $today)
                    ->orderBy('due_date')->orderBy('id')
                    ->limit(1000)
                    ->get(['id', 'due_date', 'due_time'])
                    ->each(function (Item $item) use ($today, $local, &$events) {
                        $due = $item->due_date->toDateString();
                        if ($due < $today || ($item->due_time && substr((string) $item->due_time, 0, 5) <= $local->format('H:i'))) {
                            $events["item.due:{$item->id}:{$due}"] = [$item->id, $due];
                        }
                    });

                // events this rule version already handled don't count towards the batch
                $done = AutomationRun::where('automation_rule_id', $rule->id)->where('rule_version', $rule->version)
                    ->whereIn('event_id', array_keys($events))->pluck('event_id')->all();
                $events = array_slice(array_diff_key($events, array_flip($done)), 0, self::BATCH, true);

                foreach ($events as $eventId => [$itemId, $due]) {
                    $created += count(AutomationEvents::start([
                        'trigger' => 'item.due',
                        'project_id' => (int) $rule->project_id,
                        'subject_type' => AutomationCatalog::SUBJECT_ITEM,
                        'subject_id' => $itemId,
                        'event_id' => $eventId,
                        'context' => ['due_date' => $due],
                    ], $rule->id));
                }
            });

        return $created;
    }
}
