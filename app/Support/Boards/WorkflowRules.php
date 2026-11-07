<?php

namespace App\Support\Boards;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\BoardColumnRule;
use App\Models\Project;
use App\Models\User;
use App\Rules\ProjectMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A board's workflow rules (v1.52.0): saving them from the settings page, and describing them for people (the
 * settings summary, the board's column hints, MCP `get_board`, the App API). Enforcement: WorkflowGuard.
 */
final class WorkflowRules
{
    /** Role keys a rule can list → label in sentences ("Only … can move cards to Done"). */
    public const ROLES = [
        'workspace_owner' => 'the workspace owner',
        'workspace_admin' => 'workspace admins',
        'project_admin' => 'project admins',
        'project_editor' => 'project editors',
    ];

    /** Most members one rule may list. */
    public const MAX_USERS = 50;

    /**
     * Validation for `PUT boards/{board}/columns/{column}/rule`. `restrict_who` (optional): keep the who-part
     * restricted although nobody is listed (a rule whose listed people are gone stays locked: nobody).
     *
     * @return array<string, mixed>
     */
    public static function rules(Board $board): array
    {
        return [
            'allowed_user_ids' => ['present', 'array', 'max:'.self::MAX_USERS],
            'allowed_user_ids.*' => ['integer', 'distinct', new ProjectMember($board->project)],
            'allowed_roles' => ['present', 'array', 'max:'.count(self::ROLES)],
            'allowed_roles.*' => ['string', 'distinct', Rule::in(array_keys(self::ROLES))],
            'allowed_from_column_ids' => ['present', 'array', 'max:'.max(1, $board->columns()->count())],
            'allowed_from_column_ids.*' => ['integer', 'distinct'],
            'restrict_who' => ['sometimes', 'boolean'],
            'require_assignee' => ['required', 'boolean'],
            'require_subtasks_done' => ['required', 'boolean'],
            'require_due_date' => ['required', 'boolean'],
        ];
    }

    /**
     * Save the column's rule from validated input; an empty rule is deleted (null returned). "Allowed from" ids are
     * intersected with the board's other columns (foreign ids are dropped). Logged on the board's history.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(BoardColumn $column, array $data): ?BoardColumnRule
    {
        $roles = array_values(array_intersect(array_keys(self::ROLES), (array) $data['allowed_roles']));
        $userIds = array_values(array_unique(array_map('intval', (array) $data['allowed_user_ids'])));
        $boardColumnIds = BoardColumn::where('board_id', $column->board_id)->whereKeyNot($column->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        // only other columns of this board (a column is always entered from another one)
        $fromIds = array_values(array_intersect(array_unique(array_map('intval', (array) $data['allowed_from_column_ids'])), $boardColumnIds));
        $restrictWho = $roles !== [] || $userIds !== [] || (bool) ($data['restrict_who'] ?? false);
        $flags = [
            'require_assignee' => (bool) $data['require_assignee'],
            'require_subtasks_done' => (bool) $data['require_subtasks_done'],
            'require_due_date' => (bool) $data['require_due_date'],
        ];
        $before = self::sentence(WorkflowGuard::instance()->ruleFor($column));

        $rule = DB::transaction(function () use ($column, $roles, $userIds, $fromIds, $restrictWho, $flags) {
            if (! $restrictWho && $fromIds === [] && ! in_array(true, $flags, true)) {
                BoardColumnRule::where('board_column_id', $column->id)->delete();

                return null;
            }

            $rule = BoardColumnRule::updateOrCreate(
                ['board_column_id' => $column->id],
                ['board_id' => $column->board_id, 'allowed_roles' => $roles, 'restrict_who' => $restrictWho, ...$flags],
            );
            $rule->users()->sync($userIds);
            $rule->fromColumns()->sync($fromIds);

            return $rule;
        });

        $guard = WorkflowGuard::instance();
        $guard->forget((int) $column->board_id);
        $after = self::sentence($guard->ruleFor($column));
        if ($before !== $after) {
            self::log($column, $before === '' ? 'workflow_rule_created' : ($after === '' ? 'workflow_rule_deleted' : 'workflow_rule_updated'), $before, $after);
        }
        $column->board?->touch(); // open boards refetch (live `board.updated`)

        return $rule;
    }

    public static function delete(BoardColumn $column): void
    {
        $before = self::sentence(WorkflowGuard::instance()->ruleFor($column));
        BoardColumnRule::where('board_column_id', $column->id)->delete();
        WorkflowGuard::instance()->forget((int) $column->board_id);
        if ($before !== '') {
            self::log($column, 'workflow_rule_deleted', $before, '');
        }
        $column->board?->touch();
    }

    /**
     * Why `$user` may not delete `$column` because of workflow rules (null = no rule involved, or they may manage
     * rules): deleting a column removes its own rule and empties other rules' "allowed from" — a rule change, so it
     * needs the same right as editing rules (ProjectPolicy::update).
     */
    public static function columnDeletionBlocker(BoardColumn $column, User $user): ?string
    {
        $board = $column->board;
        if (! $board || $user->can('update', $board->project)) {
            return null;
        }
        $rules = WorkflowGuard::instance()->rulesFor((int) $board->id);
        if ($rules->has((int) $column->id)) {
            return "{$column->name} has a workflow rule. Only project admins can delete it.";
        }
        $using = $rules->filter(fn (BoardColumnRule $r) => $r->fromColumns->contains('id', $column->id))
            ->map(fn (BoardColumnRule $r) => (string) $r->column?->name)->values()->all();

        return $using === [] ? null
            : 'The workflow rule of '.self::andList($using)." allows cards from {$column->name}. Only project admins can delete it.";
    }

    /**
     * Delete rules that restrict nothing any more (their "allowed from" columns were deleted and nothing else is
     * set). A who-restricted rule never counts as empty: its people being gone means nobody may move cards in.
     */
    public static function pruneEmpty(?int $boardId = null): int
    {
        $count = BoardColumnRule::query()
            ->when($boardId, fn ($q) => $q->where('board_id', $boardId))
            ->where('restrict_who', false)->where('require_assignee', false)->where('require_subtasks_done', false)->where('require_due_date', false)
            ->whereDoesntHave('fromColumns')
            ->delete();
        if ($boardId) {
            WorkflowGuard::instance()->forget($boardId);
        }

        return $count;
    }

    /** History entry on the board: who changed which column's rule, the rule before and after (sentences). */
    private static function log(BoardColumn $column, string $action, string $before, string $after): void
    {
        if ($column->board) {
            ActivityLog::log($column->board, $action, ['rule' => ['old' => $before, 'new' => $after]], ['column' => $column->name, 'column_id' => $column->id]);
        }
    }

    /**
     * The board's rule history for the settings page (newest first).
     *
     * @return list<array{id: int, action: string, column: string, before: string, after: string, user: string|null, at: string}>
     */
    public static function history(Board $board, int $limit = 20): array
    {
        return ActivityLog::where('loggable_type', Board::class)->where('loggable_id', $board->id)
            ->whereIn('action', ['workflow_rule_created', 'workflow_rule_updated', 'workflow_rule_deleted'])
            ->with('user:id,name')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ActivityLog $log) => [
                'id' => (int) $log->id,
                'action' => (string) $log->action,
                'column' => (string) ($log->metadata['column'] ?? ''),
                'before' => (string) ($log->changes['rule']['old'] ?? ''),
                'after' => (string) ($log->changes['rule']['new'] ?? ''),
                'user' => $log->user?->name,
                'at' => $log->created_at?->toIso8601String() ?? '',
            ])->all();
    }

    /**
     * The rule as sentences, e.g. ["Cards can enter Done only from Testing.", "Only Armend Zekjiri or project
     * admins can move cards here.", "Requires an assignee and all subtasks done."]. People who can no longer open
     * the project are left out; a who-rule with nobody left reads "Nobody can move cards here right now …". Needs `users`,
     * `fromColumns` and `column` loaded.
     *
     * @return list<string>
     */
    public static function summary(BoardColumnRule $rule): array
    {
        $name = (string) $rule->column?->name;
        $lines = [];
        if ($rule->fromColumns->isNotEmpty()) {
            $lines[] = "Cards can enter {$name} only from ".self::orList($rule->fromColumns->sortBy('position')->pluck('name')->all()).'.';
        }
        $who = WorkflowGuard::instance()->who($rule);
        if ($who['locked']) {
            $lines[] = 'Nobody can move cards here right now (the rule lists nobody).';
        } elseif ($who['restricts']) {
            $lines[] = 'Only '.self::whoList($rule).' can move cards here.';
        }
        $requires = array_keys(array_filter([
            'an assignee' => $rule->require_assignee,
            'all subtasks done' => $rule->require_subtasks_done,
            'a due date' => $rule->require_due_date,
        ]));
        if ($requires !== []) {
            $lines[] = 'Requires '.self::andList($requires).'.';
        }

        return $lines;
    }

    /** One line for AI clients (MCP), '' without a rule. */
    public static function sentence(?BoardColumnRule $rule): string
    {
        return $rule ? implode(' ', self::summary($rule)) : '';
    }

    /** Why someone the who-part doesn't allow can't move cards into the rule's column (the 422 / hint text). */
    public static function actorReason(BoardColumnRule $rule): string
    {
        $name = (string) $rule->column?->name;

        return WorkflowGuard::instance()->who($rule)['locked']
            ? "Nobody can move cards to {$name} right now (the rule lists nobody)."
            : 'Only '.self::whoList($rule)." can move cards to {$name}.";
    }

    /** "Armend Zekjiri, Jane Doe or project admins" (current members first, by name; then roles). */
    public static function whoList(BoardColumnRule $rule): string
    {
        $who = WorkflowGuard::instance()->who($rule);
        $names = $who['users']->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->pluck('name')->all();
        foreach (array_keys(self::ROLES) as $role) {
            if (in_array($role, $who['roles'], true)) {
                $names[] = self::ROLES[$role];
            }
        }

        return self::orList($names);
    }

    /** @param list<string> $parts */
    public static function orList(array $parts): string
    {
        return self::join($parts, 'or');
    }

    /** @param list<string> $parts */
    public static function andList(array $parts): string
    {
        return self::join($parts, 'and');
    }

    /**
     * Per column of the board, what the board page / App API show `$user` (null = no rule):
     * `summary` (sentences), `allowed_from` (column ids; [] = anywhere), `requires`, `actor_allowed`
     * (the who-part allows this user) and `actor_reason` (why not). Hints only — the server decides.
     *
     * @return array<int, array{summary: list<string>, allowed_from: list<int>, requires: array{assignee: bool, subtasks_done: bool, due_date: bool}, actor_allowed: bool, actor_reason: string|null}>
     */
    public static function clientPayload(Board $board, User $user): array
    {
        $guard = WorkflowGuard::instance();
        $rules = $guard->rulesFor((int) $board->id);
        if ($rules->isEmpty()) {
            return [];
        }

        $payload = [];
        foreach ($rules as $columnId => $rule) {
            $allowed = $guard->actorAllowed($rule, $user, $board->project);
            $payload[(int) $columnId] = [
                'summary' => self::summary($rule),
                'allowed_from' => $rule->fromColumns->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                'requires' => [
                    'assignee' => (bool) $rule->require_assignee,
                    'subtasks_done' => (bool) $rule->require_subtasks_done,
                    'due_date' => (bool) $rule->require_due_date,
                ],
                'actor_allowed' => $allowed,
                'actor_reason' => $allowed ? null : self::actorReason($rule),
            ];
        }

        return $payload;
    }

    /**
     * What the settings page edits, per column id. `allowed_user_ids` = people who can still open the project
     * (others are in `former_user_ids`, dropped on the next save); `restrict_who` keeps the who-part restricted;
     * `locked` = restricted with nobody left (nobody may move cards in).
     *
     * @return array<int, array{allowed_user_ids: list<int>, former_user_ids: list<int>, allowed_roles: list<string>, allowed_from_column_ids: list<int>, restrict_who: bool, locked: bool, require_assignee: bool, require_subtasks_done: bool, require_due_date: bool}>
     */
    public static function forSettings(Board $board): array
    {
        $guard = WorkflowGuard::instance();
        $out = [];
        foreach ($guard->rulesFor((int) $board->id) as $columnId => $rule) {
            $who = $guard->who($rule);
            $current = $who['users']->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $out[(int) $columnId] = [
                'allowed_user_ids' => $current,
                'former_user_ids' => $rule->users->pluck('id')->map(fn ($id) => (int) $id)->diff($current)->sort()->values()->all(),
                'allowed_roles' => $rule->roles(),
                'allowed_from_column_ids' => $rule->fromColumns->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                'restrict_who' => $who['restricts'],
                'locked' => $who['locked'],
                'require_assignee' => (bool) $rule->require_assignee,
                'require_subtasks_done' => (bool) $rule->require_subtasks_done,
                'require_due_date' => (bool) $rule->require_due_date,
            ];
        }

        return $out;
    }

    /**
     * The people a rule can list: workspace admins and project members (of a workspace they still belong to), with
     * their role; `can_move` = may edit the board's cards at all (project viewers can't). Members listed in a rule
     * who lost access are added with `former: true` so the editor can show and remove them.
     *
     * @return list<array{id: int, name: string, role: string, can_move: bool, former: bool}>
     */
    public static function members(Project $project, Board $board): array
    {
        $workspace = $project->workspace;
        $admins = $workspace->users()->wherePivot('role', 'admin')->get(['users.id', 'users.name']);
        $members = $project->users()
            ->whereIn('users.id', DB::table('workspace_user')->select('user_id')->where('workspace_id', $workspace->id))
            ->get(['users.id', 'users.name']);

        $people = [];
        foreach ($members as $member) {
            $role = (string) $member->pivot->role;
            $people[$member->id] = ['id' => (int) $member->id, 'name' => (string) $member->name, 'role' => 'Project '.$role, 'can_move' => in_array($role, ['admin', 'editor'], true), 'former' => false];
        }
        foreach ($admins as $admin) {
            $label = (int) $workspace->owner_id === (int) $admin->id ? 'Workspace owner' : 'Workspace admin';
            $people[$admin->id] = ['id' => (int) $admin->id, 'name' => (string) $admin->name, 'role' => $label, 'can_move' => true, 'former' => false];
        }
        foreach (WorkflowGuard::instance()->rulesFor((int) $board->id) as $rule) {
            foreach ($rule->users as $user) {
                $people[$user->id] ??= ['id' => (int) $user->id, 'name' => (string) $user->name, 'role' => 'No longer in the project', 'can_move' => false, 'former' => true];
            }
        }

        return collect($people)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** @param list<string> $parts */
    private static function join(array $parts, string $word): string
    {
        $parts = array_values($parts);
        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }
        $last = array_pop($parts);

        return implode(', ', $parts)." {$word} {$last}";
    }
}
