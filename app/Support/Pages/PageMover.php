<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one way to move a page, whiteboard or folder in its project's tree (BRAI-431): the sidebar
 * drag & drop, the "Move…" dialog, `pages.update` with a parent and the MCP `update_page` tool
 * all end up here. Authorization (PagePolicy `update` on the page, `view` on the parent) is the
 * caller's job; this class keeps the tree valid:
 *
 * - the parent is in the same project and is neither the page nor one of its descendants;
 * - `before` / `after` name a sibling in the target parent (neither = append);
 * - positions are renumbered 0..n in the new parent and, after a parent change, in the old one;
 * - the slug stays unique per parent (the `(project_id, parent_id, slug)` key).
 *
 * Moves in one project run one at a time (the project row is locked), so two concurrent moves
 * can't build a cycle between them.
 */
class PageMover
{
    /**
     * @throws ValidationException
     */
    public static function move(Page $page, ?Page $parent, ?int $beforeId = null, ?int $afterId = null): Page
    {
        if ($beforeId !== null && $afterId !== null) {
            throw ValidationException::withMessages(['before_id' => 'Give either before_id or after_id, not both.']);
        }

        return DB::transaction(function () use ($page, $parent, $beforeId, $afterId) {
            Project::whereKey($page->project_id)->lockForUpdate()->first();

            /** @var Page $page */
            $page = Page::whereKey($page->id)->firstOrFail();
            $parentId = $parent?->id;

            if ($parent) {
                $parent = Page::whereKey($parent->id)->first();
                if (! $parent || $parent->project_id !== $page->project_id) {
                    throw ValidationException::withMessages(['parent_id' => 'The destination must be in the same project.']);
                }
                if (self::isSelfOrDescendant($parent, $page)) {
                    throw ValidationException::withMessages([
                        'parent_id' => $page->isFolder()
                            ? 'A folder can\'t be moved into itself or one of its subfolders.'
                            : 'A page can\'t be moved into itself or one of its subpages.',
                    ]);
                }
            }

            $oldParentId = $page->parent_id;
            $siblings = self::children($page->project_id, $parentId)
                ->reject(fn (Page $sibling) => $sibling->id === $page->id)
                ->values();

            $index = $siblings->count();
            if ($beforeId !== null || $afterId !== null) {
                $anchorId = $beforeId ?? $afterId;
                $anchorIndex = $siblings->search(fn (Page $sibling) => $sibling->id === $anchorId);
                if ($anchorIndex === false) {
                    throw ValidationException::withMessages([
                        $beforeId !== null ? 'before_id' : 'after_id' => 'That item is not in the destination.',
                    ]);
                }
                $index = $beforeId !== null ? $anchorIndex : $anchorIndex + 1;
            }

            $ordered = $siblings->all();
            array_splice($ordered, $index, 0, [$page]);

            foreach ($ordered as $position => $sibling) {
                if ($sibling->id !== $page->id && (int) $sibling->position !== $position) {
                    Page::whereKey($sibling->id)->update(['position' => $position]);
                }
            }

            $page->position = $index;
            if ($oldParentId !== $parentId) {
                $page->parent_id = $parentId;
                $page->slug = self::uniqueSlug($page, $parentId);
            }

            if ($page->isDirty()) {
                // a move isn't an edit: keep "last updated" as it was
                $page->timestamps = false;
                $page->save();
                $page->timestamps = true;
            }

            if ($oldParentId !== $parentId) {
                foreach (self::children($page->project_id, $oldParentId)->values() as $position => $sibling) {
                    if ((int) $sibling->position !== $position) {
                        Page::whereKey($sibling->id)->update(['position' => $position]);
                    }
                }
            }

            return $page;
        });
    }

    /**
     * Whether `$candidate` is `$page` itself or somewhere below it.
     */
    public static function isSelfOrDescendant(Page $candidate, Page $page): bool
    {
        $seen = [];
        $current = $candidate;
        while ($current) {
            if ($current->id === $page->id) {
                return true;
            }
            if (isset($seen[$current->id]) || $current->parent_id === null) {
                return false;
            }
            $seen[$current->id] = true;
            $current = Page::whereKey($current->parent_id)->first(['id', 'parent_id']);
        }

        return false;
    }

    /**
     * "Website / Meetings / New Folder" for a destination (null = the project's top level).
     */
    public static function path(Project $project, ?Page $parent): string
    {
        $names = [];
        $seen = [];
        $current = $parent;
        while ($current && ! isset($seen[$current->id])) {
            $seen[$current->id] = true;
            array_unshift($names, $current->name);
            $current = $current->parent_id ? Page::whereKey($current->parent_id)->first(['id', 'parent_id', 'name']) : null;
        }

        return implode(' / ', [$project->name, ...$names]);
    }

    /**
     * The children of a parent in the order the tree shows them (folders after pages).
     *
     * @return Collection<int, Page>
     */
    private static function children(int $projectId, ?int $parentId)
    {
        return Page::query()
            ->where('project_id', $projectId)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('type', 'desc')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'type', 'position']);
    }

    private static function uniqueSlug(Page $page, ?int $parentId): string
    {
        $base = $page->slug ?: 'page';
        $slug = $base;
        $count = 2;
        while (Page::query()
            ->where('project_id', $page->project_id)
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->where('slug', $slug)
            ->whereKeyNot($page->id)
            ->exists()) {
            $slug = $base.'-'.$count++;
        }

        return $slug;
    }
}
