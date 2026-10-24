<?php

namespace App\Support\Projects;

use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\TaskListItem;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Recently updated" on the project page (BRAI-106): the latest content across types. A board,
 * list or helpdesk counts as updated when one of its issues, tasks or tickets changed.
 * Expects boards, taskLists, plans, helpdesks and leadForms loaded on the project (as
 * ProjectController@show does); runs four bounded queries.
 */
class RecentContent
{
    /**
     * @return list<array{type: string, id: int, name: string, url: string, updated_at: string, is_whiteboard?: bool, icon?: ?string}>
     */
    public static function for(Project $project, User $user, int $limit = 5): array
    {
        $workspace = $project->workspace;
        $params = [$workspace->slug, $project->slug];

        $latest = fn (string $model, string $key, Collection $ids) => $ids->isEmpty()
            ? collect()
            : $model::query()->whereIn($key, $ids)->groupBy($key)->selectRaw("{$key}, max(updated_at) as last")->pluck('last', $key);

        $itemTimes = $latest(Item::class, 'board_id', $project->boards->pluck('id'));
        $taskTimes = $latest(TaskListItem::class, 'task_list_id', $project->taskLists->pluck('id'));
        $ticketTimes = $latest(Ticket::class, 'helpdesk_id', $project->helpdesks->pluck('id'));

        $newest = fn ($own, $child) => collect([$own, $child])->filter()->map(fn ($t) => Carbon::parse($t))->max();

        $entries = collect();
        foreach ($project->boards as $board) {
            $entries->push(['type' => 'board', 'id' => $board->id, 'name' => $board->name, 'url' => route('boards.show', [...$params, $board->slug]), 'at' => $newest($board->updated_at, $itemTimes[$board->id] ?? null)]);
        }
        foreach ($project->taskLists as $list) {
            $entries->push(['type' => 'list', 'id' => $list->id, 'name' => $list->name, 'url' => route('task-lists.show', [...$params, $list->slug]), 'at' => $newest($list->updated_at, $taskTimes[$list->id] ?? null)]);
        }
        foreach ($project->plans as $plan) {
            $entries->push(['type' => 'plan', 'id' => $plan->id, 'name' => $plan->name, 'url' => route('plans.show', [...$params, $plan->slug]), 'at' => $newest($plan->updated_at, null)]);
        }
        foreach ($project->helpdesks as $helpdesk) {
            $entries->push(['type' => 'helpdesk', 'id' => $helpdesk->id, 'name' => $helpdesk->name, 'url' => route('helpdesks.show', [...$params, $helpdesk->slug]), 'at' => $newest($helpdesk->updated_at, $ticketTimes[$helpdesk->id] ?? null)]);
        }
        foreach ($project->leadForms as $form) {
            $entries->push(['type' => 'lead_form', 'id' => $form->id, 'name' => $form->name, 'url' => route('lead-forms.show', [...$params, $form->slug]), 'at' => $newest($form->updated_at, null)]);
        }

        Page::query()
            ->where('pages.project_id', $project->id)
            ->where('pages.type', 'page')
            ->visibleTo($user)
            ->orderByDesc('pages.updated_at')
            ->limit($limit)
            ->get(['pages.id', 'pages.uuid', 'pages.name', 'pages.icon', 'pages.content', 'pages.updated_at'])
            ->each(fn (Page $page) => $entries->push([
                'type' => 'page', 'id' => $page->id, 'name' => $page->name, 'url' => route('pages.show', [...$params, $page->uuid]),
                'at' => $page->updated_at, 'is_whiteboard' => (bool) $page->is_whiteboard, 'icon' => $page->icon,
            ]));

        return $entries
            ->filter(fn ($entry) => $entry['at'] !== null)
            ->sortByDesc(fn ($entry) => $entry['at']->getTimestamp())
            ->take($limit)
            ->map(function ($entry) {
                $entry['updated_at'] = $entry['at']->toIso8601String();
                unset($entry['at']);

                return $entry;
            })
            ->values()
            ->all();
    }
}
