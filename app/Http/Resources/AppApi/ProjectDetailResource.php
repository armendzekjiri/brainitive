<?php

namespace App\Http\Resources\AppApi;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAccess;
use App\Support\ApiKeys\ApiKeyAreas;
use App\Support\Projects\RecentContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * `GET /api/v1/projects/{id}` (BRAI-389): the project home — what the web's project page and
 * sidebar show: boards, docs (the folder tree as a flat list with `parent_id`; unpublished drafts
 * only for their author and project admins, Page::visibleTo), task lists, plans, helpdesks, lead
 * forms, "Recently updated", and what the user may do. Ids + names + web links, no content.
 *
 * Expects the controller to have loaded boards, taskLists, plans, helpdesks, leadForms and workspace.
 *
 * @mixin Project
 */
class ProjectDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $project = $this->resource;
        $params = [$project->workspace->slug, $project->slug];
        $firstBoard = $project->boards->first();

        return [
            ...(new ProjectResource($project))->toArray($request),
            // the user's project role; null for workspace admins who aren't project members
            'role' => self::roleOf($user, $project),
            'member_count' => ApiKeyAccess::reads('members') ? $project->users()->count() : null,
            'can' => [
                'update' => $user->can('update', $project),
                'manage_members' => $user->can('manageMembers', $project),
                'create_boards' => $user->can('createBoards', $project),
                'create_pages' => $user->can('create', [Page::class, $project]),
                // one board answers for all: createItems depends only on the project role
                'create_issues' => $firstBoard !== null && $user->can('createItems', (clone $firstBoard)->setRelation('project', $project)),
            ],
            'boards' => $project->boards->map(fn (Board $board) => [
                'id' => $board->id,
                'slug' => $board->slug,
                'name' => $board->name,
                'type' => $board->type,
                'url' => route('boards.show', [...$params, $board->slug]),
            ])->values()->all(),
            'docs' => $this->docs($project, $request, $params),
            'task_lists' => $project->taskLists->map(fn (TaskList $list) => [
                'id' => $list->id,
                'slug' => $list->slug,
                'name' => $list->name,
                'url' => route('task-lists.show', [...$params, $list->slug]),
            ])->values()->all(),
            'plans' => $project->plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'slug' => $plan->slug,
                'name' => $plan->name,
                'url' => route('plans.show', [...$params, $plan->slug]),
            ])->values()->all(),
            'helpdesks' => $project->helpdesks->map(fn (Helpdesk $helpdesk) => [
                'id' => $helpdesk->id,
                'slug' => $helpdesk->slug,
                'name' => $helpdesk->name,
                'url' => route('helpdesks.show', [...$params, $helpdesk->slug]),
            ])->values()->all(),
            'lead_forms' => $project->leadForms->map(fn (LeadForm $form) => [
                'id' => $form->id,
                'slug' => $form->slug,
                'name' => $form->name,
                'status' => $form->status,
                'url' => route('lead-forms.show', [...$params, $form->slug]),
            ])->values()->all(),
            // the project page's "Recently updated" (a board / list / helpdesk counts when its content changed)
            'recently_updated' => self::recentlyUpdated($project, $user),
        ];
    }

    /**
     * Folders, docs and whiteboards the user can see, folders first then by position. A page under a
     * parent the user can't see is left out, as the web's tree never reaches it.
     *
     * @param  list<string>  $params
     * @return list<array{id: int, uuid: string, parent_id: int|null, type: string, name: string, icon: string|null, is_whiteboard: bool, is_draft: bool, url: string, updated_at: string|null}>
     */
    private function docs(Project $project, Request $request, array $params): array
    {
        if (! ApiKeyAccess::readable($request->user(), 'docs')) {
            return [];
        }
        /** @var Collection<int, Page> $pages */
        $pages = Page::query()
            ->where('pages.project_id', $project->id)
            ->visibleTo($request->user())
            ->orderByRaw("CASE pages.type WHEN 'folder' THEN 0 ELSE 1 END")
            ->orderBy('pages.position')
            ->orderBy('pages.id')
            ->get(['pages.id', 'pages.uuid', 'pages.parent_id', 'pages.type', 'pages.name', 'pages.icon', 'pages.status', 'pages.content', 'pages.updated_at']);

        // keep a page only when its whole parent chain is visible
        $byId = $pages->keyBy('id');
        $reachable = [];
        $isReachable = function (Page $page) use (&$isReachable, &$reachable, $byId): bool {
            if (isset($reachable[$page->id])) {
                return $reachable[$page->id];
            }
            $reachable[$page->id] = false; // guards against a parent cycle
            $parent = $page->parent_id === null ? null : $byId->get($page->parent_id);

            return $reachable[$page->id] = $page->parent_id === null || ($parent !== null && $isReachable($parent));
        };

        return $pages->filter($isReachable)->map(fn (Page $page) => [
            'id' => $page->id,
            'uuid' => $page->uuid,
            'parent_id' => $page->parent_id,
            // page | folder
            'type' => $page->type,
            'name' => $page->name,
            'icon' => $page->icon,
            'is_whiteboard' => (bool) $page->is_whiteboard,
            'is_draft' => $page->status === 'draft',
            'url' => route('pages.show', [...$params, $page->uuid]),
            'updated_at' => $page->updated_at?->toIso8601ZuluString(),
        ])->values()->all();
    }

    /** admin | editor | viewer, or null (a workspace admin who isn't a project member). */
    private static function roleOf(User $user, Project $project): ?string
    {
        $role = $user->projectRole($project);

        return $role === null ? null : (string) $role;
    }

    /**
     * The project page's "Recently updated": a board / list / helpdesk counts when its content changed.
     *
     * @return list<array{type: string, id: int, name: string, url: string, updated_at: string}>
     */
    private static function recentlyUpdated(Project $project, User $user): array
    {
        $boards = ($key = ApiKeyAccess::forUser($user))?->boardIds();
        $areas = ['board' => 'issues', 'list' => 'work', 'plan' => 'plans', 'helpdesk' => 'helpdesk', 'lead_form' => 'helpdesk', 'page' => 'docs'];

        return collect(RecentContent::for($project, $user))
            // a personal API key: only the areas it can read (and its boards)
            ->filter(fn (array $entry) => $key === null || ($key->can($areas[$entry['type']] ?? 'none', ApiKeyAreas::READ)
                && ($entry['type'] !== 'board' || $boards === null || in_array((int) $entry['id'], $boards, true))))
            ->map(fn (array $entry) => [
                // board | list | plan | helpdesk | lead_form | page
                'type' => (string) $entry['type'],
                'id' => (int) $entry['id'],
                'name' => (string) $entry['name'],
                'url' => (string) $entry['url'],
                'updated_at' => CarbonImmutable::parse($entry['updated_at'])->utc()->toIso8601ZuluString(),
            ])->values()->all();
    }
}
