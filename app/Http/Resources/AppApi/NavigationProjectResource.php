<?php

namespace App\Http\Resources\AppApi;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Navigation\PinnedContent;
use App\Support\Sidebar\SidebarPreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One project in the sidebar (`GET /me/navigation`): its content groups and the user's layout of
 * it — hidden groups, pins, "pinned only". Expects boards, taskLists, plans, helpdesks, leadForms
 * and workspace loaded.
 *
 * @mixin Project
 */
class NavigationProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->resource;
        $layout = SidebarPreferences::normalize($user->sidebar_preferences)['projects'][(string) $project->id] ?? SidebarPreferences::normalizeProject([]);
        $params = [$project->workspace->slug, $project->slug];

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'key' => $this->key,
            'name' => $this->name,
            'icon' => $this->icon_display,
            'color' => $this->color,
            'url' => route('projects.show', $params),
            // false = the user hid this group of the project in the sidebar (SidebarPreferences::GROUPS)
            'groups' => [
                'health' => (bool) ($layout['groups']['health'] ?? true),
                'workload' => (bool) ($layout['groups']['workload'] ?? true),
                'releases' => (bool) ($layout['groups']['releases'] ?? true),
                'boards' => (bool) ($layout['groups']['boards'] ?? true),
                'lists' => (bool) ($layout['groups']['lists'] ?? true),
                'plans' => (bool) ($layout['groups']['plans'] ?? true),
                'helpdesks' => (bool) ($layout['groups']['helpdesks'] ?? true),
                'lead_forms' => (bool) ($layout['groups']['lead_forms'] ?? true),
                'pages' => (bool) ($layout['groups']['pages'] ?? true),
                'whiteboards' => (bool) ($layout['groups']['whiteboards'] ?? true),
                'folders' => (bool) ($layout['groups']['folders'] ?? true),
            ],
            'pinned_only' => (bool) $layout['pinned_only'],
            'pins' => PinResource::collection($layout['pins'] === [] ? [] : PinnedContent::resolve($user, $project, $layout['pins'])),
            'boards' => $project->boards->map(fn (Board $board) => [
                'id' => $board->id,
                'name' => $board->name,
                'type' => $board->type,
                'url' => route('boards.show', [...$params, $board->slug]),
            ])->values()->all(),
            'task_lists' => $project->taskLists->map(fn (TaskList $list) => [
                'id' => $list->id,
                'name' => $list->name,
                'url' => route('task-lists.show', [...$params, $list->slug]),
            ])->values()->all(),
            'plans' => $project->plans->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'url' => route('plans.show', [...$params, $plan->slug]),
            ])->values()->all(),
            'helpdesks' => $project->helpdesks->map(fn (Helpdesk $helpdesk) => [
                'id' => $helpdesk->id,
                'name' => $helpdesk->name,
                'url' => route('helpdesks.show', [...$params, $helpdesk->slug]),
            ])->values()->all(),
            'lead_forms' => $project->leadForms->map(fn (LeadForm $form) => [
                'id' => $form->id,
                'name' => $form->name,
                'url' => route('lead-forms.show', [...$params, $form->slug]),
            ])->values()->all(),
        ];
    }
}
