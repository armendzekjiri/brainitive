<?php

namespace App\Support\Captures;

use App\Models\Board;
use App\Models\Page;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Everything a user can save into, grouped by workspace — the browser extension's destination
 * picker and the capture inbox's convert dialog. Targets the user can only read (viewer roles,
 * others' private bookmark groups) are left out; the create actions check again
 * (`ContentCapture::authorize`).
 */
class CaptureDestinations
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function for(User $user): array
    {
        $gate = Gate::forUser($user);
        $projectIds = $user->accessibleProjectIds();

        $workspaces = $user->workspaces()
            ->with([
                'projects' => fn ($q) => $q->whereIn('id', $projectIds)->orderBy('name'),
                'projects.workspace',
                'projects.boards' => fn ($q) => $q->orderBy('name'),
                'projects.boards.project.workspace',
                'projects.boards.columns:id,board_id,name,position',
                'projects.taskLists' => fn ($q) => $q->orderBy('name'),
                'projects.taskLists.project.workspace',
                'bookmarks' => fn ($q) => $q->orderBy('name'),
                'bookmarks.workspace',
                'snippetCollections' => fn ($q) => $q->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        return $workspaces->map(function (Workspace $workspace) use ($gate) {
            $projects = $workspace->projects->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'key' => $project->key,
                'icon' => $project->icon,
                'color' => $project->color,
                'can_create_pages' => $gate->allows('create', [Page::class, $project]),
                'boards' => $project->boards
                    ->filter(fn (Board $board) => $gate->allows('createItems', $board))
                    ->map(fn (Board $board) => [
                        'id' => $board->id,
                        'name' => $board->name,
                        'type' => $board->type,
                        'columns' => $board->columns->map->only(['id', 'name'])->values(),
                    ])->values(),
                'task_lists' => $project->taskLists
                    ->filter(fn ($list) => $gate->allows('manageItems', $list))
                    ->map->only(['id', 'name'])->values(),
            ])->filter(fn (array $p) => $p['can_create_pages'] || $p['boards']->isNotEmpty() || $p['task_lists']->isNotEmpty());

            return [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'projects' => $projects->values(),
                'bookmarks' => $workspace->bookmarks
                    ->filter(fn ($bookmark) => $gate->allows('update', $bookmark))
                    ->map(fn ($bookmark) => [
                        'id' => $bookmark->id,
                        'name' => $bookmark->name,
                        'icon' => $bookmark->icon,
                    ])->values(),
                'snippet_collections' => $gate->allows('create', [SnippetCollection::class, $workspace])
                    ? $workspace->snippetCollections->map->only(['id', 'name'])->values()
                    : collect(),
            ];
        })->filter(fn (array $w) => $w['projects']->isNotEmpty() || $w['bookmarks']->isNotEmpty() || $w['snippet_collections']->isNotEmpty())
            ->values()
            ->all();
    }
}
