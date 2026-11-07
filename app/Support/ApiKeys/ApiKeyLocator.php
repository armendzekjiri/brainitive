<?php

namespace App\Support\ApiKeys;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\Bookmark;
use App\Models\BookmarkItem;
use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Models\Comment;
use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\Helpdesk;
use App\Models\Item;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\PageReview;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\Sprint;
use App\Models\TaskList;
use App\Models\TaskListItem;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TimeEntry;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where an App API resource lives (workspace / project / board) and which area it belongs to, for the checks of
 * a personal API key (Developer API, v1.52.0): from a raw route parameter (AuthorizeApiKey, before route-model
 * binding) or from a model (ApiKeyGate). A thing that doesn't exist resolves to null: the controller answers its
 * usual 404 then.
 */
final class ApiKeyLocator
{
    /**
     * Model class → area. Workspace / Project have none (the route's area decides).
     *
     * @var array<class-string<Model>, string>
     */
    public const MODEL_AREAS = [
        Board::class => 'issues',
        Item::class => 'issues',
        Sprint::class => 'issues',
        Comment::class => 'issues',
        Attachment::class => 'issues',
        Page::class => 'docs',
        PageReview::class => 'docs',
        Plan::class => 'plans',
        Release::class => 'plans',
        TaskList::class => 'work',
        TaskListItem::class => 'work',
        TimeEntry::class => 'time',
        Meeting::class => 'calendar',
        Helpdesk::class => 'helpdesk',
        Ticket::class => 'helpdesk',
        TicketReply::class => 'helpdesk',
        LeadForm::class => 'helpdesk',
        Lead::class => 'helpdesk',
        FeedbackPortal::class => 'helpdesk',
        FeedbackPost::class => 'helpdesk',
        ClientPortal::class => 'helpdesk',
        ClientDeliverable::class => 'helpdesk',
        SnippetCollection::class => 'library',
        Snippet::class => 'library',
        Bookmark::class => 'library',
        BookmarkItem::class => 'library',
    ];

    /** @var array<int, int> */
    private static array $projectWorkspace = [];

    /** @var array<int, int> */
    private static array $boardProject = [];

    // ── From a model ─────────────────────────────────────────────────────────────────────────────

    public static function areaOf(Model|string $model): ?string
    {
        $class = is_string($model) ? $model : $model::class;

        return self::MODEL_AREAS[$class] ?? null;
    }

    /** Is this model class one the key checks know (scope and / or area)? */
    public static function knows(string $class): bool
    {
        return isset(self::MODEL_AREAS[$class]) || in_array($class, [Workspace::class, Project::class], true);
    }

    public static function ofModel(Model $model): ?ApiKeyLocation
    {
        $id = fn (string $attribute): ?int => ($v = $model->getAttribute($attribute)) === null ? null : (int) $v;

        return match (true) {
            $model instanceof Workspace => new ApiKeyLocation((int) $model->getKey()),
            $model instanceof Project => self::project((int) $model->getKey(), $id('workspace_id')),
            $model instanceof Board => self::board((int) $model->getKey(), $id('project_id')),
            $model instanceof Item => self::board($id('board_id')),
            $model instanceof Sprint => self::board($id('board_id')),
            $model instanceof Comment, $model instanceof Attachment => self::item($id('item_id')),
            $model instanceof Page, $model instanceof Plan, $model instanceof Release, $model instanceof TaskList,
            $model instanceof Helpdesk, $model instanceof LeadForm, $model instanceof FeedbackPortal,
            $model instanceof ClientPortal, $model instanceof Meeting => self::project($id('project_id')),
            $model instanceof PageReview => self::row('pages', 'id', $id('page_id')),
            $model instanceof TaskListItem => self::row('task_lists', 'id', $id('task_list_id')),
            $model instanceof Ticket => self::row('helpdesks', 'id', $id('helpdesk_id')),
            $model instanceof TicketReply => self::ticket($id('ticket_id')),
            $model instanceof Lead => self::row('lead_forms', 'id', $id('lead_form_id')),
            $model instanceof FeedbackPost => self::row('feedback_portals', 'id', $id('feedback_portal_id')),
            $model instanceof ClientDeliverable => self::row('client_portals', 'id', $id('client_portal_id')),
            $model instanceof TimeEntry => self::timeEntry($id('workspace_id'), $id('project_id'), $id('item_id')),
            $model instanceof SnippetCollection, $model instanceof Bookmark => ($ws = $id('workspace_id')) === null ? null : new ApiKeyLocation($ws, workspaceWide: true),
            $model instanceof Snippet => self::wide('snippet_collections', $id('collection_id')),
            $model instanceof BookmarkItem => self::wide('bookmarks', $id('bookmark_id')),
            default => null,
        };
    }

    // ── From a raw route parameter ───────────────────────────────────────────────────────────────

    /** @param  string  $type  a resolver type of ApiKeyRoutes::PARAMS */
    public static function ofParam(string $type, mixed $value, ?string $extra = null): ?ApiKeyLocation
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;
        $int = self::int($value);

        return match ($type) {
            'workspace' => ($ws = DB::table('workspaces')->where('slug', $value)->whereNull('deleted_at')->value('id')) === null ? null : new ApiKeyLocation((int) $ws),
            'project' => $int === null ? null : self::project($int),
            'board' => $int === null ? null : self::board($int),
            'item' => $int === null ? null : self::item($int),
            'item_key' => self::itemByKey($value),
            'sprint' => $int === null ? null : self::board(self::column('sprints', $int, 'board_id')),
            'task_list' => $int === null ? null : self::row('task_lists', 'id', $int),
            'page' => Str::isUuid($value) ? self::project(self::int((string) DB::table('pages')->where('uuid', $value)->value('project_id'))) : null,
            'plan' => $int === null ? null : self::row('plans', 'id', $int),
            'release' => $int === null ? null : self::row('releases', 'id', $int),
            'snippet_collection' => $int === null ? null : self::wide('snippet_collections', $int),
            'snippet' => $int === null ? null : self::wide('snippet_collections', self::column('snippets', $int, 'collection_id')),
            'bookmark' => $int === null ? null : self::wide('bookmarks', $int),
            'helpdesk' => $int === null ? null : self::row('helpdesks', 'id', $int),
            'ticket' => $int === null ? null : self::ticket($int),
            'lead_form' => $int === null ? null : self::row('lead_forms', 'id', $int),
            'lead' => $int === null ? null : self::row('lead_forms', 'id', self::column('leads', $int, 'lead_form_id')),
            'feedback_portal' => $int === null ? null : self::row('feedback_portals', 'id', $int),
            'feedback_post' => $int === null ? null : self::row('feedback_portals', 'id', self::column('feedback_posts', $int, 'feedback_portal_id')),
            'client_portal' => $int === null ? null : self::row('client_portals', 'id', $int),
            'client_deliverable' => $int === null ? null : self::row('client_portals', 'id', self::column('client_deliverables', $int, 'client_portal_id')),
            'meeting' => $int === null ? null : self::row('meetings', 'id', $int),
            'work_log' => $int === null ? null : (($row = DB::table('time_entries')->where('id', $int)->first(['workspace_id', 'project_id', 'item_id'])) === null ? null
                : self::timeEntry(self::int((string) $row->workspace_id), self::int((string) $row->project_id), self::int((string) $row->item_id))),
            'poll' => match ($extra) {
                'item' => $int === null ? null : self::item($int),
                'page' => self::ofParam('page', $value),
                default => null,
            },
            default => null,
        };
    }

    /** "project ToolExpert" / "board Sprint board" / "workspace Acme" for a 403 message (callers check the user may see it). */
    public static function describe(ApiKeyLocation $location): string
    {
        if ($location->boardId !== null && ($name = DB::table('boards')->where('id', $location->boardId)->value('name')) !== null) {
            return 'board '.$name;
        }
        if ($location->projectId !== null && ($name = DB::table('projects')->where('id', $location->projectId)->value('name')) !== null) {
            return 'project '.$name;
        }

        return 'workspace '.(string) DB::table('workspaces')->where('id', $location->workspaceId)->value('name');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    public static function project(?int $projectId, ?int $workspaceId = null): ?ApiKeyLocation
    {
        if ($projectId === null) {
            return null;
        }
        $workspaceId ??= self::$projectWorkspace[$projectId] ??= (int) DB::table('projects')->where('id', $projectId)->value('workspace_id');

        return $workspaceId > 0 ? new ApiKeyLocation($workspaceId, $projectId) : null;
    }

    public static function board(?int $boardId, ?int $projectId = null): ?ApiKeyLocation
    {
        if ($boardId === null) {
            return null;
        }
        $projectId ??= self::$boardProject[$boardId] ??= (int) DB::table('boards')->where('id', $boardId)->value('project_id');
        $project = $projectId > 0 ? self::project($projectId) : null;

        return $project === null ? null : new ApiKeyLocation($project->workspaceId, $projectId, $boardId);
    }

    private static function item(?int $itemId): ?ApiKeyLocation
    {
        return $itemId === null ? null : self::board(self::column('items', $itemId, 'board_id'));
    }

    private static function itemByKey(string $key): ?ApiKeyLocation
    {
        $boardId = DB::table('items')->whereRaw('upper(key) = ?', [Str::upper($key)])->whereNull('deleted_at')->value('board_id');

        return $boardId === null ? null : self::board((int) $boardId);
    }

    private static function ticket(?int $ticketId): ?ApiKeyLocation
    {
        return $ticketId === null ? null : self::row('helpdesks', 'id', self::column('tickets', $ticketId, 'helpdesk_id'));
    }

    private static function timeEntry(?int $workspaceId, ?int $projectId, ?int $itemId): ?ApiKeyLocation
    {
        if ($itemId !== null && ($location = self::item($itemId)) !== null) {
            return $location;
        }
        if ($projectId !== null && ($location = self::project($projectId)) !== null) {
            return $location;
        }

        return $workspaceId === null ? null : new ApiKeyLocation($workspaceId);
    }

    /** A row with a project_id column → its project. */
    private static function row(string $table, string $column, ?int $id): ?ApiKeyLocation
    {
        return $id === null ? null : self::project(self::int((string) DB::table($table)->where($column, $id)->value('project_id')));
    }

    /** A workspace-level row (workspace_id column) → workspace-wide location. */
    private static function wide(string $table, ?int $id): ?ApiKeyLocation
    {
        if ($id === null) {
            return null;
        }
        $workspaceId = self::int((string) DB::table($table)->where('id', $id)->value('workspace_id'));

        return $workspaceId === null ? null : new ApiKeyLocation($workspaceId, workspaceWide: true);
    }

    private static function column(string $table, int $id, string $column): ?int
    {
        return self::int((string) DB::table($table)->where('id', $id)->value($column));
    }

    private static function int(string $value): ?int
    {
        return preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : null;
    }

    /** Tests: the memo of project → workspace / board → project. */
    public static function flush(): void
    {
        self::$projectWorkspace = [];
        self::$boardProject = [];
    }
}
