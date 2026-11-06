<?php

namespace App\Support\Boards;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardColumnRule;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Support\Imports\ImportScope;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use WeakMap;

/**
 * Enforces a board's workflow rules (v1.52.0) — the one place that decides whether an issue may enter a column.
 *
 * Two layers:
 *  - explicit `ensure()` where a whole action must be refused up front with a clear message (drag & drop with
 *    "also complete the subtasks", bulk edit, move to another board, automations, recurring issues);
 *  - the backstop `onSaving()`, called by `Item::saving` for every create and every column change, as the
 *    signed-in user — so a write path nobody thought of can't skip a rule. Skipped without a signed-in user
 *    (webhooks), during imports (`ImportScope`) and inside `bypass()` (system writes made for someone else).
 *
 * Creating an issue counts as entering its column from nowhere: the who-rule and the conditions apply, and a
 * column that can only be entered from certain columns can't be created into. No role bypasses a rule.
 *
 * One instance per request (rules and roles are cached; reset after each request / job).
 */
final class WorkflowGuard
{
    private static int $bypass = 0;

    /** @var array<int, array{project: Project|null, rules: Collection<int, BoardColumnRule>}> board id => setup */
    private array $boards = [];

    /** @var array<string, list<string>> "user:project" => role keys */
    private array $roles = [];

    /** @var WeakMap<Item, int> issue instance => column it was explicitly cleared for */
    private WeakMap $cleared;

    public function __construct()
    {
        $this->cleared = new WeakMap;
    }

    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * Run `$work` without workflow checks: system writes made on someone's behalf (booking cards for the host).
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function bypass(Closure $work): mixed
    {
        self::$bypass++;
        try {
            return $work();
        } finally {
            self::$bypass--;
        }
    }

    /** @return Collection<int, BoardColumnRule> column id => rule */
    public function rulesFor(int $boardId): Collection
    {
        return $this->setup($boardId)['rules'];
    }

    public function ruleFor(BoardColumn $column): ?BoardColumnRule
    {
        return $this->rulesFor((int) $column->board_id)->get((int) $column->id);
    }

    /** Rules changed (settings saved, a column deleted): read them again. */
    public function forget(int $boardId): void
    {
        unset($this->boards[$boardId]);
    }

    /**
     * Why `$item` may not enter `$to` as `$actor` (null = it may). `$item` may be unsaved (a new issue).
     * `$completingIds`: issues the same action moves into a done column too (the subtask cascade, a bulk edit) —
     * they count as done for "all subtasks done".
     *
     * @param  list<int>  $completingIds
     */
    public function violation(Item $item, BoardColumn $to, User $actor, array $completingIds = []): ?WorkflowRuleViolation
    {
        $rule = $this->ruleFor($to);
        if (! $rule) {
            return null;
        }

        // "from": where the issue is on this board right now; a new issue or one from another board = nowhere
        $fromId = $item->exists && (int) $item->getOriginal('board_id') === (int) $to->board_id
            ? (int) $item->getOriginal('column_id')
            : null;
        if ($fromId === (int) $to->id) {
            return null; // not entering: a reorder inside the column
        }

        $project = $this->setup((int) $to->board_id)['project'];
        if ($project && ! $this->actorAllowed($rule, $actor, $project)) {
            return WorkflowRuleViolation::make('actor', 'Only '.WorkflowRules::whoList($rule)." can move cards to {$to->name}.", $to, $item);
        }

        $from = $rule->fromColumns;
        if ($from->isNotEmpty() && ($fromId === null || ! $from->contains('id', $fromId))) {
            $names = WorkflowRules::orList($from->sortBy('position')->pluck('name')->all());

            return WorkflowRuleViolation::make('from', $fromId === null
                ? "Cards can't be created in {$to->name} — they can only move there from {$names}."
                : "Cards can only move to {$to->name} from {$names}.", $to, $item);
        }

        if ($rule->require_assignee && empty($item->assigned_to)) {
            return WorkflowRuleViolation::make('assignee', "Cards need an assignee before they can move to {$to->name}.", $to, $item);
        }
        if ($rule->require_due_date && empty($item->due_date)) {
            return WorkflowRuleViolation::make('due_date', "Cards need a due date before they can move to {$to->name}.", $to, $item);
        }
        if ($rule->require_subtasks_done && $item->exists && $this->hasOpenSubtasks($item, $completingIds)) {
            return WorkflowRuleViolation::make('subtasks', "All subtasks must be done before a card can move to {$to->name}.", $to, $item);
        }

        return null;
    }

    /**
     * Refuse the move (422) unless the rules allow it; an allowed issue instance is not checked again when it is
     * saved into that column.
     *
     * @param  list<int>  $completingIds
     *
     * @throws WorkflowRuleViolation
     */
    public function ensure(Item $item, BoardColumn $to, User $actor, array $completingIds = [], string $prefix = ''): void
    {
        if ($this->skipped()) {
            return;
        }
        if ($violation = $this->violation($item, $to, $actor, $completingIds)) {
            throw $prefix === '' ? $violation : $violation->about($prefix);
        }
        $this->cleared[$item] = (int) $to->id;
    }

    /**
     * The backstop (`Item::saving`): a new issue or a column change, as the signed-in user.
     *
     * @throws WorkflowRuleViolation
     */
    public function onSaving(Item $item): void
    {
        if ($item->exists && ! $item->isDirty('column_id')) {
            return;
        }
        if (! $item->column_id || ! $item->board_id || $this->skipped()) {
            return;
        }
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return; // no person acts (webhooks, console): see KNOWLEDGE → Workflow rules
        }
        if (($this->cleared[$item] ?? null) === (int) $item->column_id) {
            unset($this->cleared[$item]);

            return;
        }

        $rule = $this->rulesFor((int) $item->board_id)->get((int) $item->column_id);
        if ($rule && $rule->column && ($violation = $this->violation($item, $rule->column, $actor))) {
            throw $violation;
        }
    }

    /** Whether `$actor` is one of the rule's members or holds one of its roles (no one listed = anyone who may edit). */
    public function actorAllowed(BoardColumnRule $rule, User $actor, Project $project): bool
    {
        $roles = $rule->roles();
        if ($rule->users->isEmpty() && $roles === []) {
            return true;
        }
        if ($rule->users->contains('id', $actor->id)) {
            return true;
        }

        return array_intersect($roles, $this->rolesOf($actor, $project)) !== [];
    }

    /**
     * The rule role keys `$user` holds in the project (WorkflowRules::ROLES).
     *
     * @return list<string>
     */
    public function rolesOf(User $user, Project $project): array
    {
        return $this->roles["{$user->id}:{$project->id}"] ??= (function () use ($user, $project) {
            $workspace = $project->workspace;
            $roles = [];
            if ($workspace && (int) $workspace->owner_id === (int) $user->id) {
                $roles[] = 'workspace_owner';
            }
            if ($workspace && $user->workspaceRole($workspace) === 'admin') {
                $roles[] = 'workspace_admin';
            }
            $projectRole = $user->projectRole($project);
            if (in_array($projectRole, ['admin', 'editor'], true)) {
                $roles[] = 'project_'.$projectRole;
            }

            return $roles;
        })();
    }

    private function skipped(): bool
    {
        return self::$bypass > 0 || ImportScope::active();
    }

    /** @param list<int> $completingIds */
    private function hasOpenSubtasks(Item $item, array $completingIds): bool
    {
        $ids = array_values(array_diff($item->descendantIds(), $completingIds));

        return $ids !== [] && Item::whereIn('id', $ids)
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            ->exists();
    }

    /** @return array{project: Project|null, rules: Collection<int, BoardColumnRule>} */
    private function setup(int $boardId): array
    {
        if (! isset($this->boards[$boardId])) {
            $rules = BoardColumnRule::where('board_id', $boardId)
                ->with(['users:id,name', 'fromColumns:id,name,position', 'column'])
                ->get()
                ->keyBy('board_column_id');
            $this->boards[$boardId] = [
                'project' => $rules->isEmpty() ? null : Board::withTrashed()->with('project.workspace')->find($boardId)?->project,
                'rules' => $rules,
            ];
        }

        return $this->boards[$boardId];
    }
}
