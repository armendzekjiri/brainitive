<?php

namespace App\Support\Automation;

use App\Models\AutomationRun;
use App\Models\Item;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes one AutomationRun (v1.40.0, BRAI-63), and the "Test on an issue" dry run.
 *
 * - The rule must still be enabled and at the run's version; its actor must exist, not be suspended
 *   and still open the project — otherwise the run is "skipped" with the reason.
 * - Conditions are checked again against the subject's current state (all or any, dates in the
 *   actor's time zone); the result is kept on the run for the history.
 * - Each action runs in its own transaction together with saving its result, as the actor
 *   (Auth::setUser, so history and "assigned you" name them). A retried run (worker crash) skips the
 *   actions that already finished; nothing is done twice.
 * - While a run executes, running() is true: events raised by its changes start no rules (no loops).
 */
final class AutomationRunner
{
    /** Seconds a run may hold its lock; the job's timeout is below it. */
    public const LOCK_SECONDS = 180;

    private static int $running = 0;

    private static ?string $ruleName = null;

    public static function running(): bool
    {
        return self::$running > 0;
    }

    /** The rule whose actions are executing now ("Automation “X” assigned you"), else null. */
    public static function ruleName(): ?string
    {
        return self::running() ? self::$ruleName : null;
    }

    /** @return bool false when another worker holds the run (the job retries later) */
    public static function run(int $runId): bool
    {
        $lock = Cache::lock("automation-run:{$runId}", self::LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }

        try {
            $run = AutomationRun::with('rule')->find($runId);
            if ($run && ! $run->isFinished()) {
                self::execute($run);
            }
        } finally {
            $lock->release();
        }

        return true;
    }

    private static function execute(AutomationRun $run): void
    {
        $rule = $run->rule;
        if (! $rule->enabled) {
            self::finish($run, AutomationRun::SKIPPED, 'The rule was switched off before it ran.');

            return;
        }
        if ($rule->version !== $run->rule_version) {
            self::finish($run, AutomationRun::SKIPPED, 'The rule was changed before it ran.');

            return;
        }

        $project = Project::find($rule->project_id);
        $actor = $rule->actor;
        $reason = match (true) {
            ! $project => 'The project was deleted.',
            ! $actor => 'The person this rule runs as no longer exists. Save the rule to run it as you.',
            $actor->isSuspended() => "{$actor->name} is suspended, so the rule can't run as them. Save the rule to run it as you.",
            ! $actor->hasProjectAccess($project) => "{$actor->name} can no longer open this project. Save the rule to run it as you.",
            default => null,
        };
        if ($reason) {
            self::finish($run, AutomationRun::SKIPPED, $reason);

            return;
        }

        $subject = AutomationSubject::find($run->subject_type, $run->subject_id, $project->id);
        if (! $subject) {
            self::finish($run, AutomationRun::SKIPPED, $run->subject_type === AutomationCatalog::SUBJECT_TICKET
                ? 'The ticket was deleted.'
                : 'The issue was deleted or moved to another project.');

            return;
        }

        $options = new AutomationOptions($project);
        $match = $rule->conditionMatch();
        $checked = AutomationConditions::check($rule->conditions ?? [], $subject, $options, AutomationConditions::timezoneOf($actor));
        $run->forceFill(['conditions' => AutomationConditions::summary($checked, $match)]);
        if (! AutomationConditions::matches($checked, $match)) {
            $failed = collect($checked)->where('passed', false)->pluck('label')->implode('; ');
            self::finish($run, AutomationRun::SKIPPED, "The conditions no longer match ({$failed}).");

            return;
        }

        $run->forceFill(['status' => AutomationRun::RUNNING, 'started_at' => $run->started_at ?? now(), 'attempts' => $run->attempts + 1])->save();
        $results = $run->results ?? [];
        $subjectType = (string) AutomationSubject::type($subject);

        self::$running++;
        $previousRuleName = self::$ruleName;
        self::$ruleName = $rule->name;
        $previousUser = Auth::user();
        Auth::setUser($actor);
        try {
            foreach (array_slice($rule->actions ?? [], 0, AutomationCatalog::MAX_ACTIONS) as $i => $action) {
                if (in_array($results[$i]['status'] ?? null, [AutomationActions::OK, AutomationActions::SKIPPED], true)) {
                    continue; // finished in an earlier attempt
                }
                $label = AutomationActions::describe($action, $options, $subjectType);
                $subject->refresh();

                try {
                    DB::transaction(function () use ($action, $subject, $actor, $project, $rule, $label, $i, $run, &$results) {
                        $plan = AutomationActions::plan($action, $subject, $actor, $project, $rule->name);
                        $message = $plan['message'];
                        if ($plan['apply']) {
                            $returned = ($plan['apply'])();
                            $message = is_string($returned) ? $returned : $message;
                        }
                        $results[$i] = ['action' => $action['type'], 'label' => $label, 'status' => $plan['status'], 'message' => $message];
                        $run->forceFill(['results' => $results])->save();
                    });
                } catch (Throwable $e) {
                    report($e);
                    $results[$i] = ['action' => $action['type'], 'label' => $label, 'status' => AutomationActions::ERROR, 'message' => 'Unexpected error: nothing of this action was saved.'];
                    $run->forceFill(['results' => $results])->save();
                }
            }
        } finally {
            $previousUser instanceof User ? Auth::setUser($previousUser) : Auth::forgetUser();
            self::$ruleName = $previousRuleName;
            self::$running--;
        }

        $errors = collect($results)->where('status', AutomationActions::ERROR)->count();
        self::finish($run, $errors ? AutomationRun::ERROR : AutomationRun::SUCCESS, $errors
            ? ($errors === 1 ? '1 action failed.' : "{$errors} actions failed.")
            : null);
    }

    public static function finish(AutomationRun $run, string $status, ?string $message): void
    {
        $run->forceFill(['status' => $status, 'message' => $message, 'finished_at' => now()])->save();
        // query builder: the rule's updated_at means "definition saved", not "ran"
        DB::table('automation_rules')->where('id', $run->automation_rule_id)
            ->update(['last_run_at' => now(), 'last_run_status' => $status]);
    }

    /**
     * "Test on an issue": what the rule would do for this subject, as $actor, right now. Reads only.
     *
     * @param  array{trigger: string, trigger_config: array, condition_match?: string, conditions: list<array>, actions: list<array>}  $definition
     */
    public static function preview(array $definition, Project $project, User $actor, Item|Ticket $subject): array
    {
        $options = new AutomationOptions($project);
        $subjectType = (string) AutomationSubject::type($subject);
        $conditions = AutomationConditions::check($definition['conditions'], $subject, $options, AutomationConditions::timezoneOf($actor));
        $match = AutomationDefinition::normalizeMatch($definition['condition_match'] ?? null);

        $actions = array_map(function (array $action) use ($subject, $actor, $project, $options, $subjectType) {
            $plan = AutomationActions::plan($action, $subject, $actor, $project, 'Test');

            return [
                'label' => AutomationActions::describe($action, $options, $subjectType),
                'status' => $plan['status'],
                'message' => $plan['message'],
            ];
        }, $definition['actions']);

        return [
            'subject' => AutomationSubject::label($subject),
            'matches' => AutomationConditions::matches($conditions, $match),
            'match' => $match,
            'conditions' => $conditions,
            'actions' => $actions,
        ];
    }
}
