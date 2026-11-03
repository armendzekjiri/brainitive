<?php

namespace App\Support\Navigation;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Sidebar\SidebarPreferences;

/**
 * A project's sidebar pins (SidebarPreferences, BRAI-106) with names and web links, in the user's
 * order — only what still exists in the project and what the user may open (a draft of somebody
 * else is skipped, as SidebarPreferences::visiblePins does). One query per pinned type. App API
 * `GET /me/navigation` and `PUT /me/pins` (BRAI-391).
 */
final class PinnedContent
{
    /**
     * @param  list<array{type: string, id: int}>  $pins
     * @return list<array{type: string, id: int, name: string, url: string, is_whiteboard: bool}>
     */
    public static function resolve(User $user, Project $project, array $pins): array
    {
        $ids = [];
        foreach ($pins as $pin) {
            $ids[$pin['type']][] = $pin['id'];
        }

        $params = [$project->workspace->slug, $project->slug];
        $found = [];
        foreach ($ids as $type => $typeIds) {
            $rows = match ($type) {
                'board' => Board::query()->where('project_id', $project->id)->whereIn('id', $typeIds)->get(['id', 'name', 'slug']),
                'list' => TaskList::query()->where('project_id', $project->id)->whereIn('id', $typeIds)->get(['id', 'name', 'slug']),
                'plan' => Plan::query()->where('project_id', $project->id)->whereIn('id', $typeIds)->get(['id', 'name', 'slug']),
                'helpdesk' => Helpdesk::query()->where('project_id', $project->id)->whereIn('id', $typeIds)->get(['id', 'name', 'slug']),
                'lead_form' => LeadForm::query()->where('project_id', $project->id)->whereIn('id', $typeIds)->get(['id', 'name', 'slug']),
                'page' => Page::query()->where('pages.project_id', $project->id)->whereIn('pages.id', $typeIds)->visibleTo($user)
                    ->get(['pages.id', 'pages.name', 'pages.uuid', 'pages.content']),
                default => collect(),
            };
            foreach ($rows as $row) {
                $found[$type.':'.$row->id] = [
                    'type' => $type,
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'url' => match ($type) {
                        'board' => route('boards.show', [...$params, $row->slug]),
                        'list' => route('task-lists.show', [...$params, $row->slug]),
                        'plan' => route('plans.show', [...$params, $row->slug]),
                        'helpdesk' => route('helpdesks.show', [...$params, $row->slug]),
                        'lead_form' => route('lead-forms.show', [...$params, $row->slug]),
                        'page' => route('pages.show', [...$params, $row->uuid]),
                    },
                    'is_whiteboard' => $type === 'page' && (bool) $row->is_whiteboard,
                ];
            }
        }

        $resolved = [];
        foreach ($pins as $pin) {
            if (isset($found[$pin['type'].':'.$pin['id']])) {
                $resolved[] = $found[$pin['type'].':'.$pin['id']];
            }
        }

        return $resolved;
    }

    /**
     * The stored pins of a project for this user, resolved.
     *
     * @return list<array{type: string, id: int, name: string, url: string, is_whiteboard: bool}>
     */
    public static function of(User $user, Project $project): array
    {
        $prefs = SidebarPreferences::normalize($user->sidebar_preferences);

        return self::resolve($user, $project, $prefs['projects'][(string) $project->id]['pins'] ?? []);
    }
}
