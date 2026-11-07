<?php

namespace App\Support\ApiKeys;

use App\Models\ApiKey;
use App\Models\Board;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the personal API key of the current request may reach (Developer API, v1.52.0): the "where" (scope:
 * all / workspaces / projects / boards, always within the user's own workspaces whose admins allow API keys)
 * and the "what" (areas × none / read / write, plus the delete flag). One per request, on the request
 * attributes (ApiKeys::userFromRequest / AuthorizeApiKey); never grants anything the user can't do — it only
 * narrows (AuthorizeApiKey, ApiKeyGate, the key-aware access helpers on User).
 *
 * Scope semantics: `projects` doesn't include workspace-wide content (snippets, bookmarks, workspace members,
 * workspace insights); `boards` limits the Issues & boards area to the boards, while other granted areas see
 * the whole projects those boards belong to.
 */
final class ApiKeyAccess
{
    public const ATTRIBUTE = 'api_key_access';

    /** @var list<int>|null */
    private ?array $workspaceIds = null;

    /** @var list<int>|null */
    private ?array $disabledWorkspaceIds = null;

    /** @var list<int>|null projects of a `projects` / `boards` key, in allowed workspaces */
    private ?array $projectIds = null;

    /** @var list<int>|null boards of a `boards` key, in allowed projects */
    private ?array $boardIds = null;

    public function __construct(public readonly ApiKey $key, public readonly User $user) {}

    /** The key access of the request being handled, if it was authenticated with a personal API key. */
    public static function current(): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }
        $access = request()->attributes->get(self::ATTRIBUTE);

        return $access instanceof self ? $access : null;
    }

    /** The current key access, only when it belongs to this user (other users loaded in the request are untouched). */
    public static function forUser(User $user): ?self
    {
        $access = self::current();

        return $access !== null && (int) $access->user->id === (int) $user->id && $user->exists ? $access : null;
    }

    // ── What ─────────────────────────────────────────────────────────────────────────────────────

    public function level(string $area): string
    {
        return $this->key->level($area);
    }

    public function can(string $area, string $level): bool
    {
        return ApiKeyAreas::covers($this->level($area), $level);
    }

    public function canDelete(): bool
    {
        return (bool) $this->key->can_delete;
    }

    // ── Where ────────────────────────────────────────────────────────────────────────────────────

    public function scopeType(): string
    {
        return $this->key->scope_type;
    }

    public function isLimitedToBoards(): bool
    {
        return $this->key->scope_type === ApiKey::SCOPE_BOARDS;
    }

    /** Does the scope cover whole workspaces (all / chosen workspaces)? */
    public function coversWholeWorkspaces(): bool
    {
        return in_array($this->key->scope_type, [ApiKey::SCOPE_ALL, ApiKey::SCOPE_WORKSPACES], true);
    }

    /**
     * Workspaces the key reaches at all (whole, or some of their projects / boards): the user's, with API keys
     * allowed, and in the scope.
     *
     * @return list<int>
     */
    public function workspaceIds(): array
    {
        if ($this->workspaceIds !== null) {
            return $this->workspaceIds;
        }

        $mine = DB::table('workspace_user')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->where('workspace_user.user_id', $this->user->id)
            ->whereNull('workspaces.deleted_at')
            ->select('workspaces.id', 'workspaces.api_keys_enabled')
            ->get();
        $this->disabledWorkspaceIds = $mine->reject(fn ($w) => (bool) $w->api_keys_enabled)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $allowed = $mine->filter(fn ($w) => (bool) $w->api_keys_enabled)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $inScope = match ($this->key->scope_type) {
            ApiKey::SCOPE_ALL => $allowed,
            ApiKey::SCOPE_WORKSPACES => array_intersect($allowed, $this->key->scopeIds()),
            ApiKey::SCOPE_PROJECTS => array_intersect($allowed, Project::query()->whereIn('id', $this->key->scopeIds())->pluck('workspace_id')->map(fn ($id) => (int) $id)->all()),
            ApiKey::SCOPE_BOARDS => array_intersect($allowed, Project::query()
                ->whereIn('id', Board::query()->whereIn('id', $this->key->scopeIds())->select('project_id'))
                ->pluck('workspace_id')->map(fn ($id) => (int) $id)->all()),
            default => [],
        };

        return $this->workspaceIds = array_values(array_unique(array_map('intval', $inScope)));
    }

    /** The user's workspaces whose admins turned API keys off (for the 403 code). */
    public function workspaceDisabled(int $workspaceId): bool
    {
        $this->workspaceIds();

        return in_array($workspaceId, $this->disabledWorkspaceIds ?? [], true);
    }

    /**
     * Projects of a `projects` / `boards` key (null: every project of the reached workspaces).
     *
     * @return list<int>|null
     */
    public function projectIds(): ?array
    {
        if ($this->coversWholeWorkspaces()) {
            return null;
        }
        if ($this->projectIds !== null) {
            return $this->projectIds;
        }

        $ids = $this->key->scope_type === ApiKey::SCOPE_PROJECTS
            ? $this->key->scopeIds()
            : Board::query()->whereIn('id', $this->key->scopeIds())->pluck('project_id')->map(fn ($id) => (int) $id)->all();

        return $this->projectIds = array_values(array_map('intval', Project::query()
            ->whereIn('id', $ids)
            ->whereIn('workspace_id', $this->workspaceIds())
            ->pluck('id')->all()));
    }

    /**
     * Boards of a `boards` key (null: every board of the reached projects).
     *
     * @return list<int>|null
     */
    public function boardIds(): ?array
    {
        if (! $this->isLimitedToBoards()) {
            return null;
        }
        if ($this->boardIds !== null) {
            return $this->boardIds;
        }

        return $this->boardIds = array_values(array_map('intval', Board::query()
            ->whereIn('id', $this->key->scopeIds())
            ->whereIn('project_id', $this->projectIds() ?? [])
            ->pluck('id')->all()));
    }

    public function allowsWorkspace(int $workspaceId): bool
    {
        return in_array($workspaceId, $this->workspaceIds(), true);
    }

    /** The whole workspace (its workspace-wide content: snippets, bookmarks, members, insights). */
    public function allowsWholeWorkspace(int $workspaceId): bool
    {
        return $this->coversWholeWorkspaces() && $this->allowsWorkspace($workspaceId);
    }

    public function allowsProject(int $projectId, int $workspaceId): bool
    {
        if (! $this->allowsWorkspace($workspaceId)) {
            return false;
        }
        $projects = $this->projectIds();

        return $projects === null || in_array($projectId, $projects, true);
    }

    /** The whole project, not only some of its boards (project-wide issue aggregates). */
    public function allowsWholeProject(int $projectId, int $workspaceId): bool
    {
        return ! $this->isLimitedToBoards() && $this->allowsProject($projectId, $workspaceId);
    }

    public function allowsBoard(int $boardId, int $projectId, int $workspaceId): bool
    {
        if (! $this->allowsProject($projectId, $workspaceId)) {
            return false;
        }
        $boards = $this->boardIds();

        return $boards === null || in_array($boardId, $boards, true);
    }

    public function allows(ApiKeyLocation $location): bool
    {
        return match (true) {
            $location->boardId !== null => $this->allowsBoard($location->boardId, (int) $location->projectId, $location->workspaceId),
            $location->projectId !== null => $this->allowsProject($location->projectId, $location->workspaceId),
            default => $location->workspaceWide ? $this->allowsWholeWorkspace($location->workspaceId) : $this->allowsWorkspace($location->workspaceId),
        };
    }

    /** Without a key: true. With one: does it read this area? */
    public static function readable(User $user, string $area): bool
    {
        return self::forUser($user)?->can($area, ApiKeyAreas::READ) ?? true;
    }

    /**
     * Issue queries of lists that span boards (My Work, My Day, the calendar agenda): nothing without read on
     * Issues & boards, and only the key's boards for a key limited to boards. Untouched without a key.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $items
     * @return TQuery
     */
    public static function limitIssues(User $user, $items, string $boardColumn = 'items.board_id')
    {
        $access = self::forUser($user);
        if ($access === null) {
            return $items;
        }
        if (! $access->can('issues', ApiKeyAreas::READ)) {
            return $items->whereRaw('1 = 0');
        }
        $boards = $access->boardIds();

        return $boards === null ? $items : $items->whereIn($boardColumn, $boards);
    }

    /**
     * Of these issue ids, the ones a key may list (see limitIssues); all of them without a key.
     *
     * @param  list<int>  $itemIds
     * @return list<int>
     */
    public static function visibleIssueIds(User $user, array $itemIds): array
    {
        if ($itemIds === [] || self::forUser($user) === null) {
            return $itemIds;
        }

        return array_map('intval', self::limitIssues($user, DB::table('items')->whereIn('items.id', $itemIds))->pluck('items.id')->all());
    }

    /**
     * Of these project ids, the ones in the key's scope (lists built on User::accessibleProjectIds()).
     *
     * @param  array<int, int|string>  $projectIds
     * @return array<int, int>
     */
    public function filterProjectIds(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }
        $workspaces = $this->workspaceIds();
        if ($workspaces === []) {
            return [];
        }
        $scoped = $this->projectIds();

        return Project::query()
            ->whereIn('id', $scoped === null ? $projectIds : array_values(array_intersect(array_map('intval', $projectIds), $scoped)))
            ->whereIn('workspace_id', $workspaces)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
