<?php

namespace App\Support\ApiKeys;

/**
 * Which App API routes a personal API key may call, and what each needs (Developer API, v1.52.0). The one
 * place every /api/v1 route is declared, by route name without `app.v1.` (ApiKeyRoutesTest fails for a route
 * that is missing here; a missing route is refused for keys anyway).
 *
 * An entry is:
 *  - `false`: never with an API key (403 `key_endpoint_not_allowed`): account, devices, sessions, connected
 *    apps, data export, notifications, realtime / live co-editing tokens, offline sync …;
 *  - `self::PUBLIC`: a public route (no auth at all, so the key plays no role);
 *  - an area key of ApiKeyAreas (`'issues'`), `'issues|docs'` (any of them), or `null` (no area, read only);
 *  - or an array: `area`, plus optional
 *      `level` — read | write: overrides the method's level (GET/HEAD = read, POST/PUT/PATCH = write,
 *                DELETE = write + the key's delete flag); e.g. unwatching or unlinking is a write, not a delete;
 *      `scope` — `workspace`: needs the whole workspace (workspace-wide content); `project`: needs the whole
 *                project (not a key limited to some of its boards); `boardless`: any scope except boards (results
 *                filtered to workspaces / projects, but not to boards: search);
 *      `param` — the route / query / body parameter to check the scope on, as `name:type` (type: a key of
 *                ApiKeyLocator::ofParam), when it isn't the first known route parameter (PARAMS);
 *      `areas` — `all`: needs read on every content area (search).
 *
 * Everything in a key's scope is still checked by the policies as the user (a key never exceeds its user), and
 * ApiKeyGate checks every model a policy is asked about (ids in bodies too).
 *
 * @phpstan-type Entry false|string|null|array{area?: ?string, level?: string, scope?: string, param?: string, areas?: string}
 */
final class ApiKeyRoutes
{
    public const PUBLIC = 'public';

    /**
     * Route parameter → ApiKeyLocator::ofParam type. The first of these in the route's URI is checked.
     *
     * @var array<string, string>
     */
    public const PARAMS = [
        'workspace' => 'workspace',
        'project' => 'project',
        'board' => 'board',
        'item' => 'item',
        'key' => 'item_key',
        'sprint' => 'sprint',
        'list' => 'task_list',
        'uuid' => 'page',
        'plan' => 'plan',
        'release' => 'release',
        'collection' => 'snippet_collection',
        'snippet' => 'snippet',
        'group' => 'bookmark',
        'helpdesk' => 'helpdesk',
        'ticket' => 'ticket',
        'form' => 'lead_form',
        'lead' => 'lead',
        'post' => 'feedback_post',
        'deliverable' => 'client_deliverable',
        'meeting' => 'meeting',
        'log' => 'work_log',
        'scopeId' => 'poll',
    ];

    /** @var array<string, false|string|null|array<string, string|null>> */
    public const MAP = [
        // ── Instance + sign-in (public routes) ──
        'instance' => self::PUBLIC,
        'auth.exchange' => self::PUBLIC,
        'auth.refresh' => self::PUBLIC,
        'auth.device' => self::PUBLIC,
        'auth.device.token' => self::PUBLIC,

        // ── Never with a key: sessions, account, devices, invitations, creating workspaces / projects ──
        'auth.logout' => false,
        'auth.web-session' => false,
        'me.show' => null, // who the key acts as; workspaces limited to the key's scope
        'me.update' => false,
        'me.avatar.store' => false,
        'me.avatar.destroy' => false,
        'devices.index' => false,
        'devices.current.update' => false,
        'devices.current.test-push' => false,
        'devices.destroy' => false,
        'invitations.show' => false,
        'invitations.accept' => false,
        'workspaces.store' => false,

        // ── Workspaces + projects: no area, limited to the scope ──
        'workspaces.index' => null,
        'workspaces.show' => null,
        'projects.index' => null,
        'projects.store' => false,
        'projects.templates' => false,
        'projects.show' => null, // sections of areas the key can't read are left out

        // ── Members (read only) ──
        'members.workspace' => ['area' => 'members', 'scope' => 'workspace'],
        'members.project' => 'members',
        'members.invite' => false,

        // ── Navigation, notifications: the user's own web chrome, across every workspace ──
        'navigation.show' => false,
        'navigation.pins' => false,
        'search' => ['area' => null, 'areas' => 'all', 'scope' => 'boardless'],
        'notifications.index' => false,
        'notifications.unread-count' => false,
        'notifications.read-all' => false,
        'notifications.read' => false,
        'notifications.archive' => false,
        'notifications.preferences' => false,
        'notifications.preferences.update' => false,

        // ── My Work, My Day, today ──
        'work.index' => 'work',
        'work.update' => 'work',
        'my-day.show' => 'work',
        'my-day.items.store' => 'work',
        'my-day.items.update' => 'work',
        'my-day.items.destroy' => ['area' => 'work', 'level' => 'write'], // takes it off My Day, deletes nothing
        'my-day.carry-over' => 'work',
        'today' => 'work',

        // ── Calendar, meetings, bookings (personal; the ICS feed link is a credential: never) ──
        'calendar.events' => 'calendar',
        'calendar.connections' => 'calendar',
        'calendar.events.store' => 'calendar',
        'calendar.events.show' => 'calendar',
        'calendar.events.update' => 'calendar',
        'calendar.events.destroy' => 'calendar',
        'calendar.meeting-calendars' => 'calendar',
        'meetings.index' => 'calendar',
        'meetings.store' => 'calendar',
        'meetings.destroy' => 'calendar',
        'meetings.notes' => 'calendar',
        'calendar-feed.show' => false,
        'calendar-feed.update' => false,
        'calendar-feed.reset' => false,
        'calendar-feed.destroy' => false,
        'booking-pages.index' => 'calendar',
        'bookings.index' => 'calendar',
        'bookings.show' => 'calendar',
        'bookings.cancel' => 'calendar',

        // ── Helpdesk, leads, portals ──
        'helpdesks.index' => 'helpdesk',
        'helpdesks.show' => 'helpdesk',
        'helpdesks.tickets' => 'helpdesk',
        'tickets.show' => 'helpdesk',
        'tickets.update' => 'helpdesk',
        'tickets.replies.store' => 'helpdesk',
        'lead-forms.index' => 'helpdesk',
        'lead-forms.leads' => 'helpdesk',
        'lead-forms.analytics' => 'helpdesk',
        'leads.show' => 'helpdesk',
        'leads.update' => 'helpdesk',
        'feedback-portal.show' => 'helpdesk',
        'feedback-portals.posts' => ['area' => 'helpdesk', 'param' => 'portal:feedback_portal'],
        'feedback-posts.update' => 'helpdesk',
        'feedback-posts.moderate' => 'helpdesk',
        'client-portal.show' => 'helpdesk',
        'client-portals.deliverables' => ['area' => 'helpdesk', 'param' => 'portal:client_portal'],
        'client-deliverables.show' => 'helpdesk',

        // ── Insights: summaries of issues (workspace-wide / whole project) ──
        'insights.show' => ['area' => 'issues', 'scope' => 'workspace'],
        'projects.health' => ['area' => 'issues', 'scope' => 'project'],
        'projects.workload' => ['area' => 'issues', 'scope' => 'project'],

        // ── Task lists ──
        'task-lists.index' => 'work',
        'task-lists.store' => 'work',
        'task-lists.show' => 'work',
        'task-lists.items.index' => 'work',
        'task-lists.items.store' => 'work',
        'task-lists.items.reorder' => 'work',
        'task-lists.items.update' => 'work',
        'task-lists.items.destroy' => 'work',

        // ── Captures (the user's private inbox) ──
        'captures.index' => 'work',
        'captures.counts' => 'work',
        'captures.store' => 'work',
        'captures.show' => 'work',
        'captures.update' => 'work',
        'captures.destroy' => 'work',
        'captures.archive' => 'work',
        'captures.restore' => 'work',
        'captures.preview' => 'work',
        'captures.convert' => 'work',
        'captures.destinations' => 'work',

        // ── Snippets + bookmarks (workspace-wide) ──
        'snippet-collections.index' => ['area' => 'library', 'scope' => 'workspace'],
        'snippet-collections.store' => ['area' => 'library', 'scope' => 'workspace'],
        'snippet-collections.show' => 'library',
        'snippet-collections.update' => 'library',
        'snippet-collections.destroy' => 'library',
        'snippets.index' => 'library',
        'snippets.store' => 'library',
        'snippets.show' => 'library',
        'snippets.update' => 'library',
        'snippets.destroy' => 'library',
        'bookmarks.index' => ['area' => 'library', 'scope' => 'workspace'],
        'bookmarks.store' => ['area' => 'library', 'scope' => 'workspace'],
        'bookmarks.show' => 'library',
        'bookmarks.update' => 'library',
        'bookmarks.destroy' => 'library',
        'bookmarks.items.index' => 'library',
        'bookmarks.items.store' => 'library',
        'bookmarks.items.update' => 'library',
        'bookmarks.items.destroy' => 'library',

        // ── Time tracking ──
        'timer.show' => 'time',
        'timer.start' => 'time',
        'timer.stop' => 'time',
        'timer.discard' => 'time',
        'timesheet.show' => 'time',
        'work-logs.index' => 'time',
        'work-logs.store' => 'time',
        'work-logs.show' => 'time',
        'work-logs.update' => 'time',
        'work-logs.destroy' => 'time',

        // ── Boards, issues, sprints ──
        'boards.index' => 'issues', // filtered to the key's boards
        'boards.show' => 'issues',
        'boards.items.index' => 'issues',
        'items.move' => 'issues',
        'projects.config' => 'issues',
        'boards.views' => 'issues',
        'items.store' => 'issues',
        'items.dated' => 'issues', // filtered to the key's boards
        'items.by-key' => 'issues',
        'items.show' => 'issues',
        'items.update' => 'issues',
        'items.destroy' => 'issues',
        'items.comments.index' => 'issues',
        'items.comments.store' => 'issues',
        'items.watchers' => 'issues',
        'items.watch' => 'issues',
        'items.unwatch' => ['area' => 'issues', 'level' => 'write'], // stops watching, deletes nothing
        'items.attachments.index' => 'issues',
        'items.attachments.store' => 'issues',
        'items.attachments.destroy' => 'issues',
        'items.activity' => 'issues',
        'items.children' => 'issues',
        'items.links.index' => 'issues',
        'items.links.store' => 'issues',
        'items.links.destroy' => ['area' => 'issues', 'level' => 'write'], // unlinks two issues, deletes neither
        'sprints.index' => 'issues',
        'sprints.backlog' => 'issues',
        'sprints.backlog.move' => 'issues',
        'sprints.velocity' => 'issues',
        'sprints.show' => 'issues',
        'sprints.items' => 'issues',
        'sprints.move' => 'issues',
        'sprints.burndown' => 'issues',

        // ── Docs + whiteboards (live co-editing tokens: never — a socket outlives the key) ──
        'pages.index' => 'docs',
        'pages.store' => 'docs',
        'pages.show' => 'docs',
        'pages.update' => 'docs',
        'pages.publish' => 'docs',
        'pages.move' => 'docs',
        'pages.versions.index' => 'docs',
        'pages.versions.show' => 'docs',
        'pages.reviews.index' => 'docs',
        'pages.reviews.show' => 'docs',
        'pages.reviews.decide' => 'docs',
        'pages.reviews.comments.store' => 'docs',
        'pages.reviews.threads.update' => 'docs',
        'pages.links' => 'docs',
        'links.resolve' => ['area' => 'docs', 'param' => 'workspace:workspace'],
        'pages.collab.token' => false,
        'pages.collab.bootstrap' => false,
        'pages.collab.save' => false,
        'whiteboards.show' => 'docs',
        'whiteboards.collab.token' => false,
        'whiteboards.collab.bootstrap' => false,
        'whiteboards.collab.scene' => false,
        'polls.show' => 'issues|docs', // ApiKeyGate checks the doc / issue's own area
        'polls.vote' => 'issues|docs',

        // ── Plans + releases ──
        'plans.index' => 'plans',
        'plans.show' => 'plans',
        'releases.index' => 'plans',
        'releases.show' => 'plans',

        // ── Files: uploads for docs / issue descriptions / comments ──
        'uploads.store' => ['area' => 'issues|docs', 'param' => 'workspace:workspace'],

        // ── Connected apps, data export, realtime, offline sync: never ──
        'connected-apps.index' => false,
        'connected-apps.destroy' => false,
        'data-export.show' => false,
        'data-export.store' => false,
        'realtime.show' => false,
        'realtime.token' => false,
        'sync.changes' => false,
        'sync.store' => false,
    ];

    /**
     * The normalized entry of a route (null: not declared → refused).
     *
     * @return array{allowed: bool, public: bool, area: ?string, level: ?string, scope: ?string, param: ?string, areas: ?string}|null
     */
    public static function entry(?string $route): ?array
    {
        if ($route === null || ! array_key_exists($route, self::MAP)) {
            return null;
        }
        $entry = self::MAP[$route];
        $base = ['allowed' => true, 'public' => false, 'area' => null, 'level' => null, 'scope' => null, 'param' => null, 'areas' => null];

        return match (true) {
            $entry === false => ['allowed' => false] + $base,
            $entry === self::PUBLIC => ['public' => true] + $base,
            is_array($entry) => array_merge($base, array_intersect_key($entry, $base)),
            default => ['area' => $entry] + $base,
        };
    }

    /**
     * What a key needs for an operation, for the OpenAPI extension `x-brainitive-key-ability`: `{area, level,
     * delete}` / `{excluded: true}` / null for public routes.
     *
     * @return array<string, mixed>|null
     */
    public static function describe(string $route, string $method): ?array
    {
        $entry = self::entry($route);
        if ($entry === null || ! $entry['allowed']) {
            return ['available' => false];
        }
        if ($entry['public']) {
            return null;
        }

        $method = strtoupper($method);
        $level = $entry['level'] ?? (in_array($method, ['GET', 'HEAD'], true) ? ApiKeyAreas::READ : ApiKeyAreas::WRITE);
        $areas = $entry['areas'] === 'all' ? ApiKeyAreas::contentAreas() : ($entry['area'] === null ? [] : explode('|', $entry['area']));

        return array_filter([
            'available' => true,
            'areas' => $areas,
            'any_area' => count($areas) > 1 && $entry['areas'] !== 'all' ? true : null,
            'level' => $areas === [] ? null : $level,
            'delete' => $method === 'DELETE' && $entry['level'] === null ? true : null,
            'scope' => $entry['scope'],
        ], fn ($v) => $v !== null);
    }
}
