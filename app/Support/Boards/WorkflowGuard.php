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
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * Enforces a board's workflow rules (v1.52.0) — the one place that decides whether an issue may enter a column.
 *
 * Two layers:
 *  - explicit `ensure()` where a whole action must be refused up front with a clear message (drag & drop with
 *    "also complete the subtasks", bulk edit, move to another board, automations, recurring issues, GitHub merges);
 *  - the backstop `onSaving()`, called by `Item::saving` for every create and every column change — so a write path
 *    nobody thought of can't skip a rule. With a signed-in user every part is checked; without one (webhooks,
 *    console) the who-part is skipped but "allowed from" and the conditions still apply. Skipped entirely during
 *    imports (`ImportScope`) and inside `bypass()` (system writes made for someone else).
 *
 * Creating an issue counts as entering its column from nowhere: the who-rule and the conditions apply, and a
 * column that can only be entered from certain columns can't be created into. No role bypasses a rule.
 *
 * The who-part: listed members who can still open the project OR listed roles. A rule that listed people of whom
 * none is left (deleted / left the project) and no roles is `locked`: NOBODY may move cards in (like a Jira transition
 * condition nobody meets) — no admin fallback. Project / workspace admins fix it by editing the rule.
 *
 * One instance per request (rules, members and roles are cached; forgotten on every routed request).
 */
final class WorkflowGuard
{
    private static int $bypass = 0;

    /** @var array<int, array{project: Project|null, rules: Collection<int, BoardColumnRule>, members: array<int, true>}> board id => setup */
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

    /** Rules or memberships changed: read them again. */
    public function forget(int $boardId): void
    {
        unset($this->boards[$boardId]);
    }

    /**
     * The who-part as it applies now: listed members who can still open the project, the listed roles, and whether
     * it restricts at all. A restricting rule with nobody left is `locked`: nobody passes it.
     *
     * @return array{restricts: bool, users: Collection<int, User>, roles: list<string>, locked: bool}
     */
    public function who(BoardColumnRule $rule): array
    {
        $members = $this->setup((int) $rule->board_id)['members'];
        $users = $rule->users->filter(fn (User $u) => isset($members[$u->id]))->values();
        $roles = $rule->roles();
        $restricts = (bool) $rule->restrict_who || $users->isNotEmpty() || $roles !== [];
        $locked = $restricts && $users->isEmpty() && $roles === [];

        return ['restricts' => $restricts, 'users' => $users, 'roles' => $roles, 'locked' => $locked];
    }

    /**
     * Why `$item` may not enter `$to` (null = it may). `$actor` null = no person acts (the who-part is skipped).
     * `$item` may be unsaved (a new issue). `$completingIds`: issues the same action moves into a done column too
     * (the subtask cascade, a bulk edit, a subtree moving along) — they count as done for "all subtasks done".
     * `$openSubtasks`: this issue's open descendants, precomputed by `openSubtasks()` for a batch (null = ask).
     *
     * @param  list<int>  $completingIds
     * @param  list<int>|null  $openSubtasks
     */
    public function violation(Item $item, BoardColumn $to, ?User $actor, array $completingIds = [], ?array $openSubtasks = null): ?WorkflowRuleViolation
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
        if ($actor && $project && ! $this->actorAllowed($rule, $actor, $project)) {
            return WorkflowRuleViolation::make('actor', WorkflowRules::actorReason($rule), $to, $item);
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
        if ($rule->require_subtasks_done && $item->exists) {
            $open = $openSubtasks ?? ($this->openSubtasks([(int) $item->id])[(int) $item->id] ?? []);
            if (array_diff($open, $completingIds) !== []) {
                return WorkflowRuleViolation::make('subtasks', "All subtasks must be done before a card can move to {$to->name}.", $to, $item);
            }
        }

        return null;
    }

    /**
     * Refuse the move (422) unless the rules allow it; an allowed issue instance is not checked again when it is
     * saved into that column.
     *
     * @param  list<int>  $completingIds
     * @param  list<int>|null  $openSubtasks
     *
     * @throws WorkflowRuleViolation
     */
    public function ensure(Item $item, BoardColumn $to, ?User $actor, array $completingIds = [], string $prefix = '', ?array $openSubtasks = null): void
    {
        if ($this->skipped()) {
            return;
        }
        if ($violation = $this->violation($item, $to, $actor, $completingIds, $openSubtasks)) {
            throw $prefix === '' ? $violation : $violation->about($prefix);
        }
        $this->cleared[$item] = (int) $to->id;
    }

    /**
     * The backstop (`Item::saving`): a new issue or a column change, as the signed-in user (or nobody).
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
        if (($this->cleared[$item] ?? null) === (int) $item->column_id) {
            unset($this->cleared[$item]);

            return;
        }

        $rule = $this->rulesFor((int) $item->board_id)->get((int) $item->column_id);
        $actor = Auth::user();
        if ($rule && $rule->column && ($violation = $this->violation($item, $rule->column, $actor instanceof User ? $actor : null))) {
            throw $violation;
        }
    }

    /** Whether `$actor` passes the who-part of the rule (nobody listed and not restricted = anyone who may edit). */
    public function actorAllowed(BoardColumnRule $rule, User $actor, Project $project): bool
    {
        $who = $this->who($rule);
        if (! $who['restricts']) {
            return true;
        }
        if ($who['locked']) {
            return false; // the rule lists nobody: nobody, admins included
        }
        if ($who['users']->contains('id', $actor->id)) {
            return true;
        }

        return array_intersect($who['roles'], $this->rolesOf($actor, $project)) !== [];
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

    /**
     * Open descendants (subtasks in a column that isn't done, all levels) of each issue, in one query per level
     * for the whole batch. An issue that is a descendant of another one in the batch appears under both.
     *
     * @param  list<int>  $itemIds
     * @return array<int, list<int>> issue id => open descendant ids
     */
    public function openSubtasks(array $itemIds): array
    {
        $result = array_fill_keys($itemIds, []);
        $rootsOf = [];
        foreach ($itemIds as $id) {
            $rootsOf[$id][] = $id;
        }
        $frontier = $itemIds;
        for ($level = 0; $level <= Item::MAX_DEPTH && $frontier !== []; $level++) {
            $children = DB::table('items')
                ->join('board_columns', 'board_columns.id', '=', 'items.column_id')
                ->whereIn('items.parent_id', $frontier)
                ->whereNull('items.deleted_at')
                ->get(['items.id', 'items.parent_id', 'board_columns.is_done_column']);
            $frontier = [];
            foreach ($children as $child) {
                $roots = $rootsOf[(int) $child->parent_id] ?? [];
                foreach ($roots as $root) {
                    if (! $child->is_done_column) {
                        $result[$root][] = (int) $child->id;
                    }
                    $rootsOf[(int) $child->id][] = $root;
                }
                $frontier[] = (int) $child->id;
            }
        }

        return $result;
    }

    /** Whether any rule of the board requires done subtasks (only then is `openSubtasks()` worth its queries). */
    public function needsSubtasks(int $boardId): bool
    {
        return $this->rulesFor($boardId)->contains(fn (BoardColumnRule $rule) => $rule->require_subtasks_done);
    }

    private function skipped(): bool
    {
        return self::$bypass > 0 || ImportScope::active();
    }

    /** @return array{project: Project|null, rules: Collection<int, BoardColumnRule>, members: array<int, true>} */
    private function setup(int $boardId): array
    {
        if (! isset($this->boards[$boardId])) {
            $rules = BoardColumnRule::where('board_id', $boardId)
                ->with(['users:id,name', 'fromColumns:id,name,position', 'column'])
                ->get()
                ->keyBy('board_column_id');
            $project = $rules->isEmpty() ? null : Board::withTrashed()->with('project.workspace')->find($boardId)?->project;
            $listed = $rules->flatMap(fn (BoardColumnRule $r) => $r->users->pluck('id'))->unique()->values()->all();
            $this->boards[$boardId] = [
                'project' => $project,
                'rules' => $rules,
                'members' => $project && $listed !== [] ? array_fill_keys(self::membersAmong($project, $listed), true) : [],
            ];
        }

        return $this->boards[$boardId];
    }

    /**
     * Which of these users can still open the project: project members of a workspace they belong to, or workspace
     * admins (User::hasProjectAccess, for a list at once).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private static function membersAmong(Project $project, array $userIds): array
    {
        $inWorkspace = DB::table('workspace_user')->where('workspace_id', $project->workspace_id)->whereIn('user_id', $userIds);
        $admins = (clone $inWorkspace)->where('role', 'admin')->pluck('user_id')->all();
        $members = DB::table('project_user')->where('project_id', $project->id)
            ->whereIn('user_id', (clone $inWorkspace)->select('user_id'))
            ->pluck('user_id')->all();

        return array_values(array_unique(array_map('intval', [...$admins, ...$members])));
    }
}
