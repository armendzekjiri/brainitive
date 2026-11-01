<?php

namespace App\Support\Navigation;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\User;

/**
 * "Recent" destinations (the web's command palette, the apps' Recent menu / jump list). The
 * clients keep them as bare {type, id} pairs (the browser in local storage, the apps on the device);
 * this turns them into titles and links — only for what the user may still open, so a revoked
 * project or a private page never shows up (not even as a count). Shared by
 * CommandPaletteController and `GET /api/v1/me/navigation` (BRAI-391).
 */
final class RecentDestinations
{
    public const TYPES = ['project', 'board', 'page', 'item', 'plan'];

    public const MAX = 12;

    /**
     * @param  iterable<array{type: string, id: int}>  $entries  most recent first
     * @return list<array{type: string, id: int, title: string, subtitle: string, url: string}>
     */
    public static function resolve(User $user, iterable $entries): array
    {
        $entries = collect($entries)->map(fn (array $e) => ['type' => (string) $e['type'], 'id' => (int) $e['id']]);
        $ids = fn (string $type) => $entries->where('type', $type)->pluck('id')->unique()->all();

        $resolved = collect()
            ->merge(Project::with('workspace:id,slug,name')->whereIn('id', $ids('project'))->get()
                ->map(fn (Project $p) => self::entry('project', $p->id, $p->name, $p->workspace?->name.' · Project', route('projects.show', [$p->workspace->slug, $p->slug]), $p)))
            ->merge(Board::with('project.workspace:id,slug,name')->whereIn('id', $ids('board'))->get()
                ->map(fn (Board $b) => self::entry('board', $b->id, $b->name, $b->project->name.' · Board', route('boards.show', [$b->project->workspace->slug, $b->project->slug, $b->slug]), $b)))
            ->merge(Page::with('project.workspace:id,slug,name')->whereIn('id', $ids('page'))->get()
                ->map(fn (Page $p) => self::entry('page', $p->id, $p->name, $p->project->name.' · '.($p->is_whiteboard ? 'Whiteboard' : 'Document'), route('pages.show', [$p->project->workspace->slug, $p->project->slug, $p->uuid]), $p)))
            ->merge(Item::with('board.project.workspace:id,slug,name')->whereIn('id', $ids('item'))->get()
                ->map(fn (Item $i) => self::entry('item', $i->id, $i->title, $i->key.' · '.$i->board->name, route('boards.show', [$i->board->project->workspace->slug, $i->board->project->slug, $i->board->slug]).'#item-'.$i->id, $i)))
            ->merge(Plan::with('project.workspace:id,slug,name')->whereIn('id', $ids('plan'))->get()
                ->map(fn (Plan $p) => self::entry('plan', $p->id, $p->name, $p->project->name.' · Plan', route('plans.show', [$p->project->workspace->slug, $p->project->slug, $p->slug]), $p)))
            ->filter(fn (array $e) => $user->can('view', $e['model']))
            ->keyBy(fn (array $e) => $e['type'].':'.$e['id']);

        // Keep the client's order (most recent first); drop what's gone or no longer allowed.
        return $entries
            ->map(fn (array $e) => $resolved->get($e['type'].':'.$e['id']))
            ->filter()
            ->unique(fn (array $e) => $e['type'].':'.$e['id'])
            ->map(fn (array $e) => collect($e)->except('model')->all())
            ->values()
            ->all();
    }

    /** @return array{type: string, id: int, title: string, subtitle: string, url: string, model: mixed} */
    private static function entry(string $type, int $id, ?string $title, string $subtitle, string $url, mixed $model): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title ?: 'Untitled', 'subtitle' => $subtitle, 'url' => $url, 'model' => $model];
    }
}
