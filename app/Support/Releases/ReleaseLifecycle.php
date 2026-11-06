<?php

namespace App\Support\Releases;

use App\Models\BoardColumn;
use App\Models\Item;
use App\Models\Project;
use App\Models\Release;
use DomainException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The rules for planning and shipping releases, shared by the web's `ReleaseController` and the MCP release tools
 * (`create_release`, `update_release`, `set_items_release`), so both stay the same. Throws DomainException with a
 * user-facing message when a change isn't allowed.
 */
class ReleaseLifecycle
{
    /**
     * Validation rules for a release's own fields (create + edit); names are unique per project.
     *
     * @return array<string, mixed>
     */
    public static function rules(Project $project, ?Release $release = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('releases', 'name')->where('project_id', $project->id)->ignore($release?->id)],
            'description' => 'nullable|string|max:5000',
            'start_date' => 'nullable|date',
            'release_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    /** A project's releases in the web's order: unreleased (by release date), released, archived. */
    public static function ordered(Project $project): HasMany
    {
        return $project->releases()->orderByRaw("case status when 'unreleased' then 0 when 'released' then 1 else 2 end")
            ->orderByRaw('release_date is null')->orderBy('release_date')->orderByDesc('released_at')->orderBy('id');
    }

    /**
     * Ship an unreleased release: its unfinished issues go to another unreleased release of the project, or lose
     * their fix version with null. One by one, so each issue's history records the change.
     *
     * @return int how many unfinished issues were moved
     *
     * @throws DomainException already released, or the target is the release itself / not an unreleased release of the project
     */
    public static function ship(Release $release, string $releaseDate, ?int $moveUnfinishedTo): int
    {
        if (! $release->isUnreleased()) {
            throw new DomainException('This release was already released.');
        }
        if ($moveUnfinishedTo !== null) {
            if ($moveUnfinishedTo === $release->id) {
                throw new DomainException('Pick another release for the unfinished issues.');
            }
            $target = Release::whereKey($moveUnfinishedTo)->where('project_id', $release->project_id)->where('status', Release::STATUS_UNRELEASED)->exists();
            if (! $target) {
                throw new DomainException('Unfinished issues can only move to another unreleased release of this project.');
            }
        }

        return DB::transaction(function () use ($release, $releaseDate, $moveUnfinishedTo) {
            $moved = 0;
            $release->items()->whereNull('completed_at')->get()->each(function (Item $item) use ($moveUnfinishedTo, &$moved) {
                $item->update(['release_id' => $moveUnfinishedTo]);
                $moved++;
            });
            $release->update(['status' => Release::STATUS_RELEASED, 'release_date' => $releaseDate, 'released_at' => now()]);

            return $moved;
        });
    }

    /**
     * Reopen (unreleased), archive, or restore an archived release (released). Shipping an unreleased one goes
     * through ship().
     *
     * @throws DomainException unreleased → released
     */
    public static function setStatus(Release $release, string $status): void
    {
        if ($status === Release::STATUS_RELEASED && $release->status !== Release::STATUS_ARCHIVED) {
            throw new DomainException('Use “Release” to ship an unreleased version.');
        }
        $release->update(['status' => $status, 'released_at' => $status === Release::STATUS_UNRELEASED ? null : ($release->released_at ?? now())]);
    }

    /**
     * Put issues of the project into a release (moving them out of their old one), or clear their fix version with
     * null. Ids of other projects are ignored. One by one, so each issue's history records the change.
     *
     * @param  list<int>  $itemIds
     * @return Collection<int, array{item: Item, previous_release_id: int|null}> the project's issues among the ids
     *
     * @throws DomainException the release is archived or belongs to another project
     */
    public static function assignItems(Project $project, ?Release $release, array $itemIds): Collection
    {
        if ($release && $release->project_id !== $project->id) {
            throw new DomainException('That release belongs to another project.');
        }
        if ($release && $release->status === Release::STATUS_ARCHIVED) {
            throw new DomainException('Archived releases can’t get new issues.');
        }

        $items = $project->items()->whereIn('id', $itemIds)->get();

        return DB::transaction(fn () => $items->map(function (Item $item) use ($release) {
            $previous = $item->release_id;
            if ($previous !== $release?->id) {
                $item->update(['release_id' => $release?->id]);
            }

            return ['item' => $item, 'previous_release_id' => $previous];
        }));
    }

    /**
     * A release's issues (up to 500, by key) with their state: done = completed, todo = still in its board's first
     * column, anything else in progress.
     *
     * @return Collection<int, array{item: Item, state: string}>
     */
    public static function itemsWithState(Release $release, array $with = []): Collection
    {
        $items = $release->items()
            ->with(array_merge(['column:id,board_id,name,position', 'board:id,slug,name'], $with))
            ->orderBy('key')->limit(500)
            ->get(['id', 'key', 'title', 'board_id', 'column_id', 'item_type_id', 'assigned_to', 'completed_at', 'parent_id']);
        $firstColumns = BoardColumn::whereIn('board_id', $items->pluck('board_id')->unique())->orderBy('position')
            ->get(['id', 'board_id'])->unique('board_id')->pluck('id', 'board_id');

        return $items->map(fn (Item $i) => [
            'item' => $i,
            'state' => $i->completed_at ? 'done' : ($firstColumns->get($i->board_id) === $i->column_id ? 'todo' : 'in_progress'),
        ]);
    }
}
