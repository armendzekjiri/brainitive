<?php

namespace App\Support\Captures;

use App\Models\Bookmark;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Which converted targets a capture's owner can still open — without a policy query per row.
 * The access sets are loaded once per request (the same sets search, My Day and the
 * destinations use). A target whose parent chain is gone (soft-deleted board, project,
 * workspace …) is not visible.
 */
class CaptureAccess
{
    /** @var array<int, true>|null */
    private ?array $projectIds = null;

    /** @var array<int, true>|null */
    private ?array $workspaceIds = null;

    public function __construct(private readonly User $user) {}

    /**
     * @param  Collection<int|string, Model>  $targets  loaded with CaptureConversion's locator relations
     * @return array<int|string, true> keys of the visible targets
     */
    public function visible(string $type, Collection $targets): array
    {
        if ($targets->isEmpty()) {
            return [];
        }

        $visible = match ($type) {
            'issue' => $targets->filter(fn ($item) => $item->board?->project?->workspace && $this->inProject($item->board->project_id)),
            'task' => $targets->filter(fn ($task) => $task->taskList?->project?->workspace && $this->inProject($task->taskList->project_id)),
            'page' => $this->pages($targets),
            'bookmark' => $this->bookmarks($targets),
            'snippet' => $targets->filter(fn ($snippet) => $snippet->collection?->workspace && $this->inWorkspace($snippet->collection->workspace_id)),
            default => collect(),
        };

        return $visible->mapWithKeys(fn (Model $m) => [$m->getKey() => true])->all();
    }

    private function inProject(?int $projectId): bool
    {
        $this->projectIds ??= array_fill_keys($this->user->accessibleProjectIds(), true);

        return $projectId !== null && isset($this->projectIds[$projectId]);
    }

    private function inWorkspace(?int $workspaceId): bool
    {
        $this->workspaceIds ??= array_fill_keys($this->user->workspaces()->pluck('workspaces.id')->all(), true);

        return $workspaceId !== null && isset($this->workspaceIds[$workspaceId]);
    }

    /** Project access + the page workflow's visibility (unpublished = author + admins), one query. */
    private function pages(Collection $pages): Collection
    {
        $candidates = $pages->filter(fn (Page $page) => $page->project?->workspace && $this->inProject($page->project_id));
        if ($candidates->isEmpty()) {
            return collect();
        }
        $visibleIds = Page::visibleTo($this->user)->whereKey($candidates->keys()->all())->pluck('id')->flip();

        return $candidates->filter(fn (Page $page) => $visibleIds->has($page->id));
    }

    /** Bookmark groups have per-group visibility: one policy check per group, not per item. */
    private function bookmarks(Collection $items): Collection
    {
        $gate = Gate::forUser($this->user);
        $allowed = [];

        return $items->filter(function ($item) use ($gate, &$allowed) {
            $bookmark = $item->bookmark;
            if (! $bookmark instanceof Bookmark || ! $bookmark->workspace || ! $this->inWorkspace($bookmark->workspace_id)) {
                return false;
            }

            return $allowed[$bookmark->id] ??= $gate->allows('view', $bookmark);
        });
    }
}
