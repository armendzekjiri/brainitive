<?php

namespace App\Support\Sidebar;

use App\Models\Board;
use App\Models\Helpdesk;
use App\Models\LeadForm;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The user's sidebar layout (BRAI-106), stored in `users.sidebar_preferences`:
 *
 *   {
 *     hidden_nav: string[],                       // keys of NAV the user hid
 *     projects: {
 *       "<project_id>": {
 *         groups: {boards: bool, lists: bool, …},  // false = group hidden in the sidebar
 *         pins: [{type, id}],                      // pinned items, in the user's order
 *         pinned_only: bool                        // show only the Pinned group
 *       }
 *     }
 *   }
 *
 * Null or missing keys mean the defaults: everything visible, nothing pinned.
 */
class SidebarPreferences
{
    /** Top-level entries a user may hide. Home and Inbox are always shown. */
    public const NAV = ['my_work', 'captures', 'timesheet', 'billing', 'insights', 'calendar', 'booking_pages', 'bookmarks', 'snippets'];

    /** Project groups in the sidebar, in their sidebar order. */
    public const GROUPS = ['boards', 'lists', 'plans', 'helpdesks', 'lead_forms', 'pages', 'whiteboards', 'folders'];

    /** What can be pinned. "page" covers pages, whiteboards and folders (one table). */
    public const PIN_TYPES = ['board', 'list', 'plan', 'helpdesk', 'lead_form', 'page'];

    public const MAX_PINS = 50;

    /** Upper bound of remembered projects, so the JSON stays small. */
    public const MAX_PROJECTS = 200;

    /**
     * The stored value with every key present and every unknown key dropped.
     *
     * @return array{hidden_nav: list<string>, projects: array<string, array{groups: array<string, bool>, pins: list<array{type: string, id: int}>, pinned_only: bool}>}
     */
    public static function normalize(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];

        $hidden = array_values(array_unique(array_filter(
            is_array($raw['hidden_nav'] ?? null) ? $raw['hidden_nav'] : [],
            fn ($key) => is_string($key) && in_array($key, self::NAV, true),
        )));

        $projects = [];
        foreach (is_array($raw['projects'] ?? null) ? $raw['projects'] : [] as $id => $project) {
            if (! is_numeric($id) || (int) $id <= 0 || ! is_array($project)) {
                continue;
            }
            $projects[(string) (int) $id] = self::normalizeProject($project);
        }

        return ['hidden_nav' => $hidden, 'projects' => $projects];
    }

    /**
     * @return array{groups: array<string, bool>, pins: list<array{type: string, id: int}>, pinned_only: bool}
     */
    public static function normalizeProject(array $project): array
    {
        $groups = [];
        foreach (self::GROUPS as $group) {
            $groups[$group] = (bool) ($project['groups'][$group] ?? true);
        }

        $pins = [];
        $seen = [];
        foreach (is_array($project['pins'] ?? null) ? $project['pins'] : [] as $pin) {
            $type = is_array($pin) ? ($pin['type'] ?? null) : null;
            $id = is_array($pin) ? ($pin['id'] ?? null) : null;
            if (! in_array($type, self::PIN_TYPES, true) || ! is_numeric($id) || (int) $id <= 0) {
                continue;
            }
            $key = $type.':'.(int) $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pins[] = ['type' => $type, 'id' => (int) $id];
        }

        return [
            'groups' => $groups,
            'pins' => array_slice($pins, 0, self::MAX_PINS),
            'pinned_only' => (bool) ($project['pinned_only'] ?? false),
        ];
    }

    /** The normalised preferences for the frontend (projects as a JSON object, never a list). */
    public static function forFrontend(User $user): array
    {
        $prefs = self::normalize($user->sidebar_preferences);

        return ['hidden_nav' => $prefs['hidden_nav'], 'projects' => (object) $prefs['projects']];
    }

    /**
     * Apply one validated update and save it. `$update` holds `hidden_nav` and/or `project`
     * (with `id` and any of `groups`, `pins`, `pinned_only`); the caller checked project access.
     */
    public static function apply(User $user, array $update, ?Project $project = null): array
    {
        $changes = $project && isset($update['project']) ? $update['project'] : null;
        if ($changes !== null && array_key_exists('pins', $changes)) {
            $changes['pins'] = self::visiblePins($user, $project, self::normalizeProject(['pins' => $changes['pins']])['pins']);
        }
        $accessible = array_flip($user->accessibleProjectIds());

        // read-modify-write on the locked row: two tabs saving at once must not drop each other's change
        DB::transaction(function () use ($user, $update, $project, $changes, $accessible) {
            $stored = User::whereKey($user->id)->lockForUpdate()->value('sidebar_preferences');
            $prefs = self::normalize(is_string($stored) ? json_decode($stored, true) : $stored);

            if (array_key_exists('hidden_nav', $update)) {
                $prefs['hidden_nav'] = self::normalize(['hidden_nav' => $update['hidden_nav']])['hidden_nav'];
            }

            if ($changes !== null) {
                $key = (string) $project->id;
                $current = $prefs['projects'][$key] ?? self::normalizeProject([]);

                if (isset($changes['groups'])) {
                    $current['groups'] = array_merge($current['groups'], array_map('boolval', $changes['groups']));
                }
                if (array_key_exists('pinned_only', $changes)) {
                    $current['pinned_only'] = (bool) $changes['pinned_only'];
                }
                if (array_key_exists('pins', $changes)) {
                    $current['pins'] = $changes['pins'];
                }

                unset($prefs['projects'][$key]);
                if ($current != self::normalizeProject([])) {
                    // the most recently changed project goes last, so trimming drops the stalest
                    $prefs['projects'][$key] = $current;
                }
            }

            // drop projects the user lost access to (or that were deleted) and cap the count
            $prefs['projects'] = array_slice(
                array_filter($prefs['projects'], fn ($_, $id) => isset($accessible[(int) $id]), ARRAY_FILTER_USE_BOTH),
                -self::MAX_PROJECTS, null, true,
            );

            $user->forceFill(['sidebar_preferences' => self::isDefault($prefs) ? null : $prefs])->save();
        });

        return self::forFrontend($user);
    }

    public static function reset(User $user): array
    {
        $user->forceFill(['sidebar_preferences' => null])->save();

        return self::forFrontend($user);
    }

    /**
     * Keep only pins that exist in this project and that the user may open, in the given order.
     *
     * @param  list<array{type: string, id: int}>  $pins
     * @return list<array{type: string, id: int}>
     */
    public static function visiblePins(User $user, Project $project, array $pins): array
    {
        $ids = [];
        foreach ($pins as $pin) {
            $ids[$pin['type']][] = $pin['id'];
        }

        $existing = [];
        foreach ($ids as $type => $typeIds) {
            $query = match ($type) {
                'board' => Board::query(),
                'list' => TaskList::query(),
                'plan' => Plan::query(),
                'helpdesk' => Helpdesk::query(),
                'lead_form' => LeadForm::query(),
                'page' => Page::query()->visibleTo($user),
            };
            $table = $query->getModel()->getTable();
            foreach ($query->where("{$table}.project_id", $project->id)->whereIn("{$table}.id", $typeIds)->pluck("{$table}.id") as $id) {
                $existing[$type.':'.$id] = true;
            }
        }

        return array_values(array_filter($pins, fn ($pin) => isset($existing[$pin['type'].':'.$pin['id']])));
    }

    private static function isDefault(array $prefs): bool
    {
        return $prefs['hidden_nav'] === [] && $prefs['projects'] === [];
    }
}
