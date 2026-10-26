<?php

namespace App\Support\Automation;

use App\Jobs\RunAutomation;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Item;
use App\Models\Project;
use App\Models\Ticket;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * How things that happen reach the automation engine (v1.40.0, BRAI-63).
 *
 *  1. seen(): called by DomainEvents::emit() for every event that carries a subject model. One cheap
 *     query ("an enabled rule for this project and trigger?") and nothing else when there is none.
 *     Ignored while an automation run is executing (origin flag): automation changes never trigger rules.
 *  2. DB::afterCommit: a rolled-back change starts nothing.
 *  3. Inside an HTTP request (DeferAutomations middleware) the event waits until the controller has
 *     finished, so a rule sees the issue with its labels / fields and the redirect shows the result;
 *     anywhere else (console, queue, tests without HTTP) it starts right after the commit.
 *  4. start(): per matching rule, conditions are pre-checked (a non-matching event leaves no run), a
 *     run is created idempotently (unique rule + version + event id) and RunAutomation is dispatched
 *     (queued; inline on the sync queue).
 */
final class AutomationEvents
{
    /** @var list<array<string, mixed>> */
    private static array $pending = [];

    private static int $deferDepth = 0;

    /**
     * @param  array<string, mixed>  $context  e.g. ['from_column_id' => 1, 'to_column_id' => 2] for moves
     */
    public static function seen(string $event, Model $subject, array $context = [], ?string $eventId = null): void
    {
        $trigger = AutomationCatalog::EVENT_TRIGGERS[$event] ?? null;
        if ($trigger === null || AutomationRunner::running() || ! ($subject instanceof Item || $subject instanceof Ticket)) {
            return;
        }

        $projectId = AutomationSubject::projectId($subject);
        if (! $projectId || ! AutomationRule::enabledFor($projectId, $trigger)->exists()) {
            return;
        }

        $pending = [
            'trigger' => $trigger,
            'project_id' => $projectId,
            'subject_type' => AutomationSubject::type($subject),
            'subject_id' => (int) $subject->getKey(),
            'event_id' => $eventId ?? self::eventId($trigger, $subject, $context),
            'context' => $context,
        ];

        DB::afterCommit(fn () => self::push($pending));
    }

    /**
     * Deterministic where the event can only happen once (creation, a comment, a due date), so a
     * duplicate delivery maps to the same run; a fresh id for repeatable events (moves, assignments).
     */
    private static function eventId(string $trigger, Model $subject, array $context): string
    {
        return match ($trigger) {
            'item.created', 'ticket.created' => "{$trigger}:{$subject->getKey()}",
            'item.commented' => isset($context['comment_id']) ? "{$trigger}:{$context['comment_id']}" : "{$trigger}:".Str::uuid(),
            default => "{$trigger}:{$subject->getKey()}:".Str::uuid(),
        };
    }

    /** Run $callback; events seen meanwhile start when it returns (the HTTP middleware wraps each request). */
    public static function defer(Closure $callback): mixed
    {
        self::$deferDepth++;
        try {
            return $callback();
        } finally {
            if (--self::$deferDepth === 0) {
                self::flush();
            }
        }
    }

    public static function flush(): void
    {
        while (self::$pending !== []) {
            self::start(array_shift(self::$pending));
        }
    }

    /** @param  array<string, mixed>  $event */
    private static function push(array $event): void
    {
        if (self::$deferDepth > 0) {
            self::$pending[] = $event;

            return;
        }
        self::start($event);
    }

    /**
     * Create the runs for one event and dispatch them. Never throws into the caller (reported),
     * except in unit tests.
     *
     * @param  array{trigger: string, project_id: int, subject_type: string, subject_id: int, event_id: string, context: array}  $event
     * @param  int|null  $ruleId  only this rule (the scheduler walks rule by rule)
     * @return list<AutomationRun> the runs created now (a duplicate event creates none)
     */
    public static function start(array $event, ?int $ruleId = null): array
    {
        $created = [];

        try {
            $rules = AutomationRule::enabledFor($event['project_id'], $event['trigger'])->with('actor:id,timezone')
                ->when($ruleId, fn ($q) => $q->whereKey($ruleId))->orderBy('id')->get();
            $project = $rules->isEmpty() ? null : Project::find($event['project_id']);
            $subject = $project ? AutomationSubject::find($event['subject_type'], $event['subject_id'], $project->id) : null;
            if (! $subject) {
                return [];
            }
            $options = new AutomationOptions($project);

            foreach ($rules as $rule) {
                if (! self::triggerMatches($rule, $event)) {
                    continue;
                }
                $checked = AutomationConditions::check($rule->conditions ?? [], $subject, $options, AutomationConditions::timezoneOf($rule->actor));
                if (! AutomationConditions::matches($checked, $rule->conditionMatch())) {
                    continue; // conditions that don't match leave no run behind
                }

                $run = AutomationRun::createOrFirst([
                    'automation_rule_id' => $rule->id,
                    'rule_version' => $rule->version,
                    'event_id' => Str::limit($event['event_id'], 120, ''),
                ], [
                    'project_id' => $project->id,
                    'trigger' => $event['trigger'],
                    'subject_type' => $event['subject_type'],
                    'subject_id' => $event['subject_id'],
                    'subject_label' => AutomationSubject::label($subject),
                    'context' => $event['context'] ?: null,
                    'conditions' => AutomationConditions::summary($checked, $rule->conditionMatch()),
                    'status' => AutomationRun::QUEUED,
                ]);
                if (! $run->wasRecentlyCreated) {
                    continue; // the same event again: idempotent
                }

                $created[] = $run;
                RunAutomation::dispatch($run->id);
            }
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }
            report($e);
        }

        return $created;
    }

    private static function triggerMatches(AutomationRule $rule, array $event): bool
    {
        $column = $rule->trigger_config['column_id'] ?? null;
        if ($rule->trigger === 'item.moved' && $column !== null) {
            return (int) ($event['context']['to_column_id'] ?? 0) === (int) $column;
        }

        return true;
    }
}
