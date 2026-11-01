<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\Entitlements\LimitReached;
use App\Support\Mcp\RequestIdConflict;
use App\Support\Mcp\ToolError;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Shared helpers for MCP tools: resolve a workspace / project by slug while
 * enforcing that the authenticated user may access it, and run policy checks.
 *
 * Authorization reuses the app's existing access helpers
 * (User::hasWorkspaceAccess / hasProjectAccess) and Gate policies, so the AI
 * can never touch anything the signed-in user couldn't touch in the UI.
 *
 * The `…For(Project, string)` resolvers (v1.47.0) turn the names an AI naturally uses —
 * a column, priority, type or label by name or slug, a select option by label — into ids,
 * and throw a `ToolError` that lists the valid values when they don't match.
 */
trait InteractsWithBrainitive
{
    /**
     * Resolve a workspace by slug, ensuring the user can access it.
     *
     * @throws AuthorizationException
     */
    protected function workspaceForUser(Authenticatable $user, string $slug): Workspace
    {
        $workspace = Workspace::where('slug', $slug)->first();

        if (! $workspace || ! $user->hasWorkspaceAccess($workspace)) {
            throw new AuthorizationException("You do not have access to a workspace named \"{$slug}\".");
        }

        return $workspace;
    }

    /**
     * Resolve a project (by slug) within a workspace, ensuring access.
     *
     * @throws AuthorizationException
     */
    protected function projectForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug): Project
    {
        $workspace = $this->workspaceForUser($user, $workspaceSlug);

        $project = $workspace->projects()->where('slug', $projectSlug)->first();

        if (! $project || ! $user->hasProjectAccess($project)) {
            throw new AuthorizationException("You do not have access to a project named \"{$projectSlug}\".");
        }

        return $project;
    }

    /**
     * Resolve a board (by slug) inside a project the user can open.
     *
     * @throws AuthorizationException
     */
    protected function boardForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug, string $boardSlug): Board
    {
        $project = $this->projectForUser($user, $workspaceSlug, $projectSlug);
        $board = $project->boards()->where('slug', $boardSlug)->first();

        if (! $board) {
            throw new AuthorizationException("No board named \"{$boardSlug}\" in this project.");
        }

        return $board;
    }

    /**
     * Resolve an item by id that must sit on that board (no foreign ids).
     *
     * @throws AuthorizationException
     */
    protected function boardItemForUser(Authenticatable $user, string $workspaceSlug, string $projectSlug, string $boardSlug, int $itemId): Item
    {
        $board = $this->boardForUser($user, $workspaceSlug, $projectSlug, $boardSlug);
        $item = $board->items()->find($itemId);

        if (! $item) {
            throw new AuthorizationException('No item with that id on this board. Call get_board to see item ids.');
        }

        return $item;
    }

    /**
     * Authorize a policy ability for the user, throwing on failure.
     *
     * @throws AuthorizationException
     */
    protected function authorizeFor(Authenticatable $user, string $ability, mixed $arguments): void
    {
        $response = Gate::forUser($user)->inspect($ability, $arguments);

        if ($response->denied()) {
            throw new AuthorizationException($response->message() ?: 'This action is not allowed for your account.');
        }
    }

    /**
     * Resolve an assignee reference to a user id. Accepts the literal "me"
     * (the signed-in user) or the email of a member of the project's workspace.
     * Returns null for an empty reference.
     *
     * @throws AuthorizationException
     */
    protected function resolveAssigneeId(Authenticatable $user, Project $project, ?string $assignee): ?int
    {
        $assignee = $assignee !== null ? trim($assignee) : null;
        if ($assignee === null || $assignee === '') {
            return null;
        }

        if (strtolower($assignee) === 'me') {
            return $user->id;
        }

        $member = $project->workspace->users()->where('email', $assignee)->first();
        if (! $member || ! $member->hasProjectAccess($project)) {
            throw new AuthorizationException("No workspace member found with email \"{$assignee}\". Use list_project_members to see who can be assigned, or pass \"me\".");
        }

        return $member->id;
    }

    /**
     * Run a tool body and turn the exceptions a tool can expect into tool errors, so the
     * AI gets the message instead of a JSON-RPC failure.
     *
     * @param  Closure(): (Response|ResponseFactory)  $body
     */
    protected function guard(Closure $body): Response|ResponseFactory
    {
        try {
            return $body();
        } catch (AuthorizationException|ToolError|RequestIdConflict|LimitReached|\DomainException $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            return Response::error(implode(' ', Arr::flatten($e->errors())));
        }
    }

    // ─── Name → id resolvers (v1.47.0) ──────────────────────────────────────

    /**
     * A column of the board by id, slug or name (case-insensitive); null → the first column.
     *
     * @throws ToolError
     */
    protected function columnFor(Board $board, int|string|null $column): BoardColumn
    {
        $columns = $board->columns()->orderBy('position')->get();
        if ($column === null || $column === '') {
            $first = $columns->first();
            if (! $first) {
                throw new ToolError('This board has no columns yet. Call create_board_column first.');
            }

            return $first;
        }

        $found = is_int($column) || ctype_digit((string) $column)
            ? $columns->firstWhere('id', (int) $column)
            : $columns->first(fn (BoardColumn $c) => $this->sameName($c->name, (string) $column) || $c->slug === mb_strtolower((string) $column));

        if (! $found) {
            throw new ToolError("No column \"{$column}\" on this board. Columns: ".$columns->map(fn ($c) => "{$c->name} (id {$c->id})")->join(', ').'.');
        }

        return $found;
    }

    /**
     * A priority of the project by slug or name, or null when none given.
     *
     * @throws ToolError
     */
    protected function priorityIdFor(Project $project, ?string $priority): ?int
    {
        if ($priority === null || trim($priority) === '') {
            return null;
        }
        $all = ItemPriority::forProject($project)->orderBy('order')->get();
        $found = $all->first(fn (ItemPriority $p) => $this->sameName($p->name, $priority) || $p->slug === mb_strtolower(trim($priority)));
        if (! $found) {
            throw new ToolError("No priority \"{$priority}\" in this project. Priorities: ".$all->pluck('name')->join(', ').'. Add one with create_priority.');
        }

        return $found->id;
    }

    /**
     * An item type enabled for the project by slug or name, or null when none given. `$keep` (an
     * issue's current type) is accepted even when the project switched it off.
     *
     * @throws ToolError
     */
    protected function itemTypeIdFor(Project $project, ?string $type, ?ItemType $keep = null): ?int
    {
        if ($type === null || trim($type) === '') {
            return null;
        }
        $matches = fn (ItemType $t) => $this->sameName($t->name, $type) || $t->slug === mb_strtolower(trim($type));
        if ($keep && $matches($keep)) {
            return $keep->id;
        }
        $all = ItemType::forProject($project)->get();
        $found = $all->first($matches);
        if (! $found) {
            throw new ToolError("No item type \"{$type}\" in this project. Types: ".$all->pluck('name')->join(', ').'. Add one (or switch a switched-off one back on) with create_item_type.');
        }

        return $found->id;
    }

    /**
     * Label ids for a list of label names / slugs; every name must exist.
     *
     * @param  list<string>|null  $labels
     * @return list<int>|null
     *
     * @throws ToolError
     */
    protected function labelIdsFor(Project $project, ?array $labels): ?array
    {
        if ($labels === null) {
            return null;
        }
        $all = $project->labels()->orderBy('name')->get();
        $ids = [];
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $found = $all->first(fn ($l) => $this->sameName($l->name, $label) || $l->slug === mb_strtolower($label));
            if (! $found) {
                throw new ToolError("No label \"{$label}\" in this project. Labels: ".($all->pluck('name')->join(', ') ?: 'none').'. Add one with create_label.');
            }
            $ids[] = $found->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Custom field values keyed by field id or name → `[fieldId => value]`, with select
     * options accepted by label as well as id. Null clears a value.
     *
     * @param  array<int|string, mixed>|null  $fields
     * @return array<int, mixed>|null
     *
     * @throws ToolError
     */
    protected function fieldValuesFor(Board $board, ?array $fields): ?array
    {
        if ($fields === null) {
            return null;
        }
        $all = ItemField::forBoard($board);
        $values = [];
        foreach ($fields as $key => $raw) {
            $field = ctype_digit((string) $key)
                ? $all->firstWhere('id', (int) $key)
                : $all->first(fn (ItemField $f) => $this->sameName($f->name, (string) $key));
            if (! $field) {
                throw new ToolError("No custom field \"{$key}\" on this board. Fields: ".($all->map(fn ($f) => "{$f->name} (id {$f->id})")->join(', ') ?: 'none').'. See get_project_config, or add one with create_item_field.');
            }
            if ($field->type === 'select' && is_string($raw) && $raw !== '' && ! ctype_digit($raw)) {
                $option = $field->options->first(fn ($o) => $o->archived_at === null && $this->sameName($o->label, $raw));
                if (! $option) {
                    throw new ToolError("No option \"{$raw}\" for the field \"{$field->name}\". Options: ".$field->options->whereNull('archived_at')->pluck('label')->join(', ').'.');
                }
                $raw = $option->id;
            }
            $values[$field->id] = $raw;
        }

        return $values;
    }

    protected function sameName(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    // ─── Shared output shapes ────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    protected function columnSummary(BoardColumn $column): array
    {
        return [
            'column_id' => $column->id,
            'name' => $column->name,
            'slug' => $column->slug ?? '',
            'color' => $column->color ?? '',
            'wip_limit' => $column->wip_limit,
            'is_done' => (bool) $column->is_done_column,
            'position' => (int) $column->position,
        ];
    }

    protected function columnSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'column_id' => $schema->integer(),
            'name' => $schema->string(),
            'slug' => $schema->string(),
            'color' => $schema->string(),
            'wip_limit' => $schema->integer()->nullable(),
            'is_done' => $schema->boolean()->description('Cards here count as done.'),
            'position' => $schema->integer(),
        ]);
    }
}
