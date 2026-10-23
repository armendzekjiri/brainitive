<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The command palette keeps "recent" destinations in the browser as bare {type, id} pairs.
 * This turns them into titles and links — only for what the user may still open, so a
 * revoked project or a private page never shows up (not even as a count).
 */
class CommandPaletteController extends Controller
{
    public const TYPES = ['project', 'board', 'page', 'item', 'plan'];

    public const MAX_RECENT = 12;

    public function recent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entries' => 'present|array|max:'.self::MAX_RECENT,
            'entries.*.type' => 'required|in:'.implode(',', self::TYPES),
            'entries.*.id' => 'required|integer|min:1',
        ]);
        $user = $request->user();
        $entries = collect($validated['entries']);
        $ids = fn (string $type) => $entries->where('type', $type)->pluck('id')->unique()->all();

        $resolved = collect()
            ->merge(Project::with('workspace:id,slug,name')->whereIn('id', $ids('project'))->get()
                ->map(fn (Project $p) => $this->entry('project', $p->id, $p->name, $p->workspace?->name.' · Project', route('projects.show', [$p->workspace->slug, $p->slug]), $p)))
            ->merge(Board::with('project.workspace:id,slug,name')->whereIn('id', $ids('board'))->get()
                ->map(fn (Board $b) => $this->entry('board', $b->id, $b->name, $b->project->name.' · Board', route('boards.show', [$b->project->workspace->slug, $b->project->slug, $b->slug]), $b)))
            ->merge(Page::with('project.workspace:id,slug,name')->whereIn('id', $ids('page'))->get()
                ->map(fn (Page $p) => $this->entry('page', $p->id, $p->name, $p->project->name.' · '.($p->is_whiteboard ? 'Whiteboard' : 'Document'), route('pages.show', [$p->project->workspace->slug, $p->project->slug, $p->uuid]), $p)))
            ->merge(Item::with('board.project.workspace:id,slug,name')->whereIn('id', $ids('item'))->get()
                ->map(fn (Item $i) => $this->entry('item', $i->id, $i->title, $i->key.' · '.$i->board->name, route('boards.show', [$i->board->project->workspace->slug, $i->board->project->slug, $i->board->slug]).'#item-'.$i->id, $i)))
            ->merge(Plan::with('project.workspace:id,slug,name')->whereIn('id', $ids('plan'))->get()
                ->map(fn (Plan $p) => $this->entry('plan', $p->id, $p->name, $p->project->name.' · Plan', route('plans.show', [$p->project->workspace->slug, $p->project->slug, $p->slug]), $p)))
            ->filter(fn (array $e) => $user->can('view', $e['model']))
            ->keyBy(fn (array $e) => $e['type'].':'.$e['id']);

        // Keep the browser's order (most recent first); drop what's gone or no longer allowed.
        return response()->json([
            'entries' => $entries
                ->map(fn (array $e) => $resolved->get($e['type'].':'.$e['id']))
                ->filter()
                ->unique(fn (array $e) => $e['type'].':'.$e['id'])
                ->map(fn (array $e) => collect($e)->except('model')->all())
                ->values(),
        ]);
    }

    private function entry(string $type, int $id, ?string $title, string $subtitle, string $url, mixed $model): array
    {
        return ['type' => $type, 'id' => $id, 'title' => $title ?: 'Untitled', 'subtitle' => $subtitle, 'url' => $url, 'model' => $model];
    }
}
