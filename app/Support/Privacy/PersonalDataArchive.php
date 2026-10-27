<?php

namespace App\Support\Privacy;

use App\Models\Item;
use App\Models\Page;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * "Download my data" (GDPR Art. 15 access + Art. 20 portability, BRAI-156 follow-up): writes one ZIP
 * with the data ABOUT one person — JSON files, a README.txt that explains each of them and, within a
 * size cap, copies of the files they uploaded.
 *
 * Rules (they are what the tests check):
 * - Every query names its columns. No `SELECT *`, so a new secret column can never slip in. Never
 *   exported: password hashes, remember / reset / login tokens, OAuth access + refresh tokens, app
 *   passwords, calendar credentials and ICS URLs, calendar-feed tokens, push endpoints and keys, API
 *   token ids or secrets, OAuth client secrets, session ids and payloads, collab / live tokens.
 * - Only the person's own content. Issues they created / are assigned / reported (the fields, not
 *   other people's comments), their own comments, the pages and whiteboards they authored, their own
 *   snippets, bookmarks, captures, task-list items, time entries … Other people appear at most as
 *   their name inside the person's own text (mentions), never as records.
 * - Workspace content only from workspaces they still belong to and projects they can still open
 *   (the workspace is responsible for the rest — README says so). Account data (profile, devices,
 *   calendars, tokens, notifications, captures, guest identities) always.
 * - Trashed content of their own is included and flagged (`in_trash`, `deleted_at`).
 * - Guest-portal identities with the same address only when the account's email is verified.
 */
final class PersonalDataArchive
{
    public const FORMAT = 'brainitive-personal-data';

    public const VERSION = 1;

    /** Uploaded files are copied into the ZIP while they add up to at most this; else only listed. */
    public const MAX_UPLOAD_BYTES = 500 * 1024 * 1024;

    /** Notifications received in this many days. */
    public const NOTIFICATION_DAYS = 365;

    /** History entries of the person's own actions: this many days, at most ACTIVITY_LIMIT rows. */
    public const ACTIVITY_DAYS = 730;

    public const ACTIVITY_LIMIT = 10_000;

    private const JSON = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    private ZipArchive $zip;

    /** @var array<string, int> file => number of records */
    private array $counts = [];

    /** @var list<int> */
    private array $workspaceIds = [];

    /** @var list<int> */
    private array $projectIds = [];

    private function __construct(private readonly User $user, private readonly string $workDir) {}

    /**
     * Write the ZIP to $zipPath (an absolute path; its folder is used for work files).
     *
     * @return array<string, int> records per file
     */
    public static function write(User $user, string $zipPath): array
    {
        $work = dirname($zipPath).'/work';
        File::ensureDirectoryExists($work);

        $archive = new self($user, $work);
        try {
            return $archive->build($zipPath);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** @return array<string, int> */
    private function build(string $zipPath): array
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }

        $this->scope();

        $this->account();
        $this->memberships();
        $this->issues();
        $this->comments();
        $this->pages();
        $this->taskListItems();
        $this->captures();
        $this->snippets();
        $this->bookmarks();
        $this->timeEntries();
        $this->myDay();
        $this->stickyNotes();
        $this->notifications();
        $this->devices();
        $this->calendars();
        $this->connectedApps();
        $this->activity();
        $this->adminAudit();
        $this->guestPortals();
        $this->uploads();

        $this->zip->addFromString('README.txt', self::readme());
        $this->json('manifest.json', [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601ZuluString(),
            'account' => ['name' => $this->user->name, 'email' => $this->user->email],
            'counts' => $this->counts,
            'not_included' => self::NOT_INCLUDED,
        ], count: false);

        if ($this->zip->close() !== true) {
            throw new RuntimeException('The ZIP file could not be written.');
        }

        return $this->counts;
    }

    // ── what the person may still see ───────────────────────────────────────────────────────

    private function scope(): void
    {
        $uid = $this->user->id;
        $this->workspaceIds = DB::table('workspace_user')->where('user_id', $uid)->pluck('workspace_id')->map(fn ($id) => (int) $id)->all();

        $adminWorkspaces = DB::table('workspace_user')->where('user_id', $uid)->whereIn('role', ['owner', 'admin'])->pluck('workspace_id')
            ->merge(DB::table('workspaces')->where('owner_id', $uid)->whereIn('id', $this->workspaceIds)->pluck('id'))
            ->all();

        $this->projectIds = DB::table('projects')
            ->whereIn('workspace_id', $this->workspaceIds)
            ->where(fn (Builder $q) => $q->whereIn('workspace_id', $adminWorkspaces)
                ->orWhereIn('id', DB::table('project_user')->select('project_id')->where('user_id', $uid)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ── files ───────────────────────────────────────────────────────────────────────────────

    private function account(): void
    {
        $u = DB::table('users')->where('id', $this->user->id)->first([
            'name', 'email', 'email_verified_at', 'avatar', 'bio', 'phone', 'timezone', 'calendar_view', 'task_digest',
            'onboarding_state', 'sidebar_preferences', 'google_id', 'github_id', 'microsoft_id', 'terms_accepted_at',
            'terms_version', 'last_login_at', 'suspended_at', 'created_at', 'updated_at',
        ]);

        $avatar = null;
        if (is_string($u->avatar) && $u->avatar !== '' && Storage::disk('public')->exists($u->avatar)) {
            $avatar = 'files/avatar'.self::extension($u->avatar);
            $this->zip->addFile(Storage::disk('public')->path($u->avatar), $avatar);
        }

        $this->json('account.json', [
            'profile' => [
                'name' => $u->name,
                'email' => $u->email,
                'email_verified_at' => self::ts($u->email_verified_at),
                'photo' => $avatar,
                'bio' => $u->bio,
                'phone' => $u->phone,
                'timezone' => $u->timezone,
                'created_at' => self::ts($u->created_at),
                'updated_at' => self::ts($u->updated_at),
                'last_login_at' => self::ts($u->last_login_at),
                'suspended_at' => self::ts($u->suspended_at),
            ],
            'settings' => [
                'calendar_view' => $u->calendar_view,
                'daily_task_digest' => (bool) $u->task_digest,
                'sidebar' => self::decode($u->sidebar_preferences),
                'onboarding' => self::decode($u->onboarding_state),
            ],
            'notification_preferences' => array_map(fn (array $c) => ['category' => $c['label'], 'channels' => $c['channels']], NotificationCatalog::forUser($this->user)),
            // provider names only — never the provider's ids or tokens
            'sign_in_methods' => array_values(array_filter([
                'email_and_password',
                $u->google_id ? 'google' : null,
                $u->github_id ? 'github' : null,
                $u->microsoft_id ? 'microsoft' : null,
            ])),
            'terms' => ['accepted_at' => self::ts($u->terms_accepted_at), 'version' => $u->terms_version],
            'instance_admin' => $this->user->isInstanceAdmin(),
        ], count: false);
    }

    private function memberships(): void
    {
        $workspaces = DB::table('workspace_user as wu')->join('workspaces as w', 'w.id', '=', 'wu.workspace_id')
            ->where('wu.user_id', $this->user->id)->orderBy('w.name')
            ->get(['w.name', 'w.slug', 'w.owner_id', 'w.deleted_at', 'wu.role', 'wu.invited_at', 'wu.accepted_at', 'wu.created_at'])
            ->map(fn ($r) => [
                'workspace' => $r->name,
                'slug' => $r->slug,
                'role' => $r->role,
                'owner' => (int) $r->owner_id === $this->user->id,
                'invited_at' => self::ts($r->invited_at),
                'joined_at' => self::ts($r->accepted_at ?? $r->created_at),
                'in_trash' => $r->deleted_at !== null,
            ])->all();

        $projects = DB::table('project_user as pu')->join('projects as p', 'p.id', '=', 'pu.project_id')->join('workspaces as w', 'w.id', '=', 'p.workspace_id')
            ->where('pu.user_id', $this->user->id)->whereIn('p.workspace_id', $this->workspaceIds)->orderBy('w.name')->orderBy('p.name')
            ->get(['p.name', 'p.key', 'w.name as workspace', 'pu.role', 'pu.created_at', 'p.deleted_at'])
            ->map(fn ($r) => [
                'project' => $r->name,
                'key' => $r->key,
                'workspace' => $r->workspace,
                'role' => $r->role,
                'added_at' => self::ts($r->created_at),
                'in_trash' => $r->deleted_at !== null,
            ])->all();

        $this->json('memberships.json', ['workspaces' => $workspaces, 'projects' => $projects], count: count($workspaces) + count($projects));
    }

    private function issues(): void
    {
        $uid = $this->user->id;
        $rows = DB::table('items as i')
            ->join('boards as b', 'b.id', '=', 'i.board_id')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoin('board_columns as c', 'c.id', '=', 'i.column_id')
            ->leftJoin('item_types as t', 't.id', '=', 'i.item_type_id')
            ->leftJoin('item_priorities as pr', 'pr.id', '=', 'i.priority_id')
            ->whereIn('b.project_id', $this->projectIds)
            ->where(fn (Builder $q) => $q->where('i.created_by', $uid)->orWhere('i.assigned_to', $uid)->orWhere('i.reporter_id', $uid))
            ->select([
                'i.id', 'i.key', 'i.title', 'i.description', 'i.created_by', 'i.assigned_to', 'i.reporter_id', 'i.story_points',
                'i.original_estimate', 'i.time_spent', 'i.start_date', 'i.start_time', 'i.due_date', 'i.due_time', 'i.completed_at',
                'i.created_at', 'i.updated_at', 'i.deleted_at', 'b.name as board', 'b.deleted_at as board_deleted_at',
                'p.name as project', 'p.deleted_at as project_deleted_at', 'c.name as status', 't.name as type', 'pr.name as priority',
            ]);

        $this->jsonRows('issues.json', $rows->lazyById(500, 'i.id', 'id'), fn ($r) => [
            'key' => $r->key,
            'title' => $r->title,
            'your_roles' => $this->roles($r, ['created_by' => 'creator', 'assigned_to' => 'assignee', 'reporter_id' => 'reporter']),
            'project' => $r->project,
            'board' => $r->board,
            'status' => $r->status,
            'type' => $r->type,
            'priority' => $r->priority,
            'story_points' => $r->story_points,
            'original_estimate_minutes' => $r->original_estimate,
            'time_spent_minutes' => $r->time_spent,
            'start' => self::dateTime($r->start_date, $r->start_time),
            'due' => self::dateTime($r->due_date, $r->due_time),
            'completed_at' => self::ts($r->completed_at),
            'description_html' => $r->description,
            'description_text' => self::text($r->description),
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
            'in_trash' => self::trashed($r->deleted_at, $r->board_deleted_at, $r->project_deleted_at),
            'deleted_at' => self::ts($r->deleted_at),
        ]);
    }

    private function comments(): void
    {
        $uid = $this->user->id;

        $issueComments = DB::table('comments as cm')
            ->join('items as i', 'i.id', '=', 'cm.item_id')
            ->join('boards as b', 'b.id', '=', 'i.board_id')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->where('cm.user_id', $uid)->whereIn('b.project_id', $this->projectIds)
            ->select(['cm.id', 'cm.body', 'cm.parent_id', 'cm.created_at', 'cm.updated_at', 'cm.deleted_at', 'i.key', 'i.title', 'i.deleted_at as item_deleted_at', 'b.deleted_at as board_deleted_at', 'p.name as project', 'p.deleted_at as project_deleted_at']);

        $this->jsonRows('comments.json', $issueComments->lazyById(500, 'cm.id', 'id'), fn ($r) => [
            'issue' => $r->key,
            'issue_title' => $r->title,
            'project' => $r->project,
            'reply' => $r->parent_id !== null,
            'body_html' => $r->body,
            'body_text' => self::text($r->body),
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
            'in_trash' => self::trashed($r->deleted_at, $r->item_deleted_at, $r->board_deleted_at, $r->project_deleted_at),
            'deleted_at' => self::ts($r->deleted_at),
        ]);

        $pageComments = DB::table('page_comments as pc')
            ->join('pages as pg', 'pg.id', '=', 'pc.page_id')
            ->join('projects as p', 'p.id', '=', 'pg.project_id')
            ->where('pc.user_id', $uid)->whereIn('pg.project_id', $this->projectIds)
            ->select(['pc.id', 'pc.body', 'pc.quote', 'pc.parent_id', 'pc.page_review_id', 'pc.resolved_at', 'pc.created_at', 'pc.updated_at', 'pg.name as page', 'p.name as project', 'p.deleted_at as project_deleted_at']);

        $this->jsonRows('page_comments.json', $pageComments->lazyById(500, 'pc.id', 'id'), fn ($r) => [
            'page' => $r->page,
            'project' => $r->project,
            'reply' => $r->parent_id !== null,
            'review_comment' => $r->page_review_id !== null,
            'quoted_text' => $r->quote,
            'body' => $r->body,
            'resolved' => $r->resolved_at !== null,
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
            'in_trash' => self::trashed($r->project_deleted_at),
        ]);

        // replies the person wrote to clients in a client portal (as a team member)
        $portalComments = DB::table('client_deliverable_comments as cc')
            ->join('client_deliverables as d', 'd.id', '=', 'cc.client_deliverable_id')
            ->join('client_portals as cp', 'cp.id', '=', 'd.client_portal_id')
            ->where('cc.user_id', $uid)->whereIn('cp.project_id', $this->projectIds)
            ->select(['cc.id', 'cc.body', 'cc.visibility', 'cc.created_at', 'd.title as deliverable', 'cp.title as portal']);

        $this->jsonRows('client_portal_comments.json', $portalComments->lazyById(500, 'cc.id', 'id'), fn ($r) => [
            'portal' => $r->portal,
            'deliverable' => $r->deliverable,
            'visibility' => $r->visibility,
            'body' => $r->body,
            'created_at' => self::ts($r->created_at),
        ]);
    }

    private function pages(): void
    {
        $uid = $this->user->id;
        $rows = DB::table('pages as pg')
            ->join('projects as p', 'p.id', '=', 'pg.project_id')
            ->where('pg.created_by', $uid)->whereIn('pg.project_id', $this->projectIds)
            ->select([
                'pg.id', 'pg.uuid', 'pg.name', 'pg.slug', 'pg.type', 'pg.status', 'pg.content', 'pg.draft_name', 'pg.draft_content',
                'pg.draft_updated_by', 'pg.draft_updated_at', 'pg.published_at', 'pg.created_at', 'pg.updated_at', 'p.name as project',
                'p.deleted_at as project_deleted_at',
            ]);

        $whiteboards = 0;
        $this->jsonRows('pages.json', $rows->lazyById(25, 'pg.id', 'id'), function ($r) use ($uid, &$whiteboards) {
            $content = self::decode($r->content);
            $isWhiteboard = is_array($content) && ($content['type'] ?? null) === 'excalidraw';
            $entry = [
                'name' => $r->name,
                'kind' => $isWhiteboard ? 'whiteboard' : $r->type,
                'project' => $r->project,
                'status' => $r->status,
                'published_at' => self::ts($r->published_at),
                'created_at' => self::ts($r->created_at),
                'updated_at' => self::ts($r->updated_at),
                'in_trash' => self::trashed($r->project_deleted_at),
            ];

            if ($isWhiteboard) {
                unset($content['previewImageUrl']);
                $file = 'whiteboards/'.(Str::slug((string) $r->name) ?: 'whiteboard').'-'.$r->uuid.'.excalidraw';
                $this->zip->addFromString($file, (string) json_encode(['type' => 'excalidraw', 'version' => 2, 'source' => 'brainitive'] + $content, self::JSON));
                $entry['scene_file'] = $file;
                $whiteboards++;

                return $entry;
            }

            $html = is_array($content) ? ($content['html'] ?? null) : null;
            $entry['content_html'] = is_string($html) ? $html : null;
            $entry['content_text'] = self::text($html);
            // unpublished changes only when the person wrote them (a co-author's draft is theirs)
            $draftBy = $r->draft_updated_by === null ? null : (int) $r->draft_updated_by;
            $draft = self::decode($r->draft_content);
            if (is_array($draft) && ($draftBy === null || $draftBy === $uid)) {
                $draftHtml = $draft['html'] ?? null;
                $entry['unpublished_draft'] = [
                    'name' => $r->draft_name,
                    'content_html' => is_string($draftHtml) ? $draftHtml : null,
                    'content_text' => self::text($draftHtml),
                    'updated_at' => self::ts($r->draft_updated_at),
                ];
            }

            return $entry;
        });
        $this->counts['whiteboards'] = $whiteboards;
    }

    private function taskListItems(): void
    {
        $uid = $this->user->id;
        $rows = DB::table('task_list_items as ti')
            ->join('task_lists as tl', 'tl.id', '=', 'ti.task_list_id')
            ->join('projects as p', 'p.id', '=', 'tl.project_id')
            ->whereIn('tl.project_id', $this->projectIds)
            ->where(fn (Builder $q) => $q->where('ti.created_by', $uid)->orWhere('ti.assignee_id', $uid))
            ->select([
                'ti.id', 'ti.title', 'ti.description', 'ti.status', 'ti.created_by', 'ti.assignee_id', 'ti.start_date', 'ti.start_time',
                'ti.due_date', 'ti.due_time', 'ti.recurrence', 'ti.reminder_minutes', 'ti.created_at', 'ti.updated_at', 'ti.deleted_at',
                'tl.name as task_list', 'tl.deleted_at as list_deleted_at', 'p.name as project', 'p.deleted_at as project_deleted_at',
            ]);

        $this->jsonRows('task_list_items.json', $rows->lazyById(500, 'ti.id', 'id'), fn ($r) => [
            'title' => $r->title,
            'your_roles' => $this->roles($r, ['created_by' => 'creator', 'assignee_id' => 'assignee']),
            'task_list' => $r->task_list,
            'project' => $r->project,
            'status' => $r->status,
            'start' => self::dateTime($r->start_date, $r->start_time),
            'due' => self::dateTime($r->due_date, $r->due_time),
            'recurrence' => $r->recurrence,
            'reminder_minutes' => $r->reminder_minutes,
            'description' => self::decode($r->description),
            'description_text' => self::text(self::decode($r->description)),
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
            'in_trash' => self::trashed($r->deleted_at, $r->list_deleted_at, $r->project_deleted_at),
            'deleted_at' => self::ts($r->deleted_at),
        ]);
    }

    private function captures(): void
    {
        $rows = DB::table('captures')->where('user_id', $this->user->id)
            ->select(['id', 'text', 'url', 'intended_type', 'source', 'captured_at', 'archived_at', 'processed_at', 'target_type', 'target_location', 'created_at']);

        $this->jsonRows('captures.json', $rows->lazyById(500, 'id'), fn ($r) => [
            'text' => $r->text,
            'url' => $r->url,
            'intended_as' => $r->intended_type,
            'source' => $r->source,
            'captured_at' => self::ts($r->captured_at ?? $r->created_at),
            'filed_as' => $r->target_type,
            'filed_in' => self::decode($r->target_location),
            'processed_at' => self::ts($r->processed_at),
            'archived_at' => self::ts($r->archived_at),
        ]);
    }

    private function snippets(): void
    {
        $uid = $this->user->id;
        $collections = DB::table('snippet_collections as sc')->join('workspaces as w', 'w.id', '=', 'sc.workspace_id')
            ->where('sc.created_by', $uid)->whereIn('sc.workspace_id', $this->workspaceIds)->orderBy('sc.id')
            ->get(['sc.name', 'sc.description', 'sc.visibility', 'sc.created_at', 'sc.updated_at', 'w.name as workspace'])
            ->map(fn ($r) => [
                'name' => $r->name,
                'workspace' => $r->workspace,
                'description' => $r->description,
                'visibility' => $r->visibility,
                'created_at' => self::ts($r->created_at),
                'updated_at' => self::ts($r->updated_at),
            ])->all();
        $this->json('snippet_collections.json', $collections);

        $snippets = DB::table('snippets as s')->join('snippet_collections as sc', 'sc.id', '=', 's.collection_id')
            ->where('s.created_by', $uid)->whereIn('sc.workspace_id', $this->workspaceIds)
            ->select(['s.id', 's.title', 's.description', 's.language', 's.content', 's.visibility', 's.created_at', 's.updated_at', 'sc.name as collection']);

        $this->jsonRows('snippets.json', $snippets->lazyById(200, 's.id', 'id'), fn ($r) => [
            'title' => $r->title,
            'collection' => $r->collection,
            'language' => $r->language,
            'description' => $r->description,
            'content' => $r->content,
            'visibility' => $r->visibility,
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
        ]);
    }

    private function bookmarks(): void
    {
        $uid = $this->user->id;
        // bookmark collections the person can open (own, shared with them, public; admins see all)
        $visible = DB::table('bookmarks as bk')->whereIn('bk.workspace_id', $this->workspaceIds)
            ->where(fn (Builder $q) => $q->where('bk.created_by', $uid)->orWhere('bk.visibility', 'public')
                ->orWhereIn('bk.id', DB::table('bookmark_collaborators')->select('bookmark_id')->where('user_id', $uid))
                ->orWhereIn('bk.workspace_id', DB::table('workspace_user')->select('workspace_id')->where('user_id', $uid)->where('role', 'admin')))
            ->pluck('bk.id');

        $collections = DB::table('bookmarks as bk')->join('workspaces as w', 'w.id', '=', 'bk.workspace_id')
            ->where('bk.created_by', $uid)->whereIn('bk.workspace_id', $this->workspaceIds)->orderBy('bk.id')
            ->get(['bk.name', 'bk.description', 'bk.visibility', 'bk.created_at', 'bk.updated_at', 'bk.deleted_at', 'w.name as workspace'])
            ->map(fn ($r) => [
                'name' => $r->name,
                'workspace' => $r->workspace,
                'description' => $r->description,
                'visibility' => $r->visibility,
                'created_at' => self::ts($r->created_at),
                'updated_at' => self::ts($r->updated_at),
                'in_trash' => $r->deleted_at !== null,
                'deleted_at' => self::ts($r->deleted_at),
            ])->all();

        $this->json('bookmarks.json', $collections);

        $items = DB::table('bookmark_items as bi')->join('bookmarks as bk', 'bk.id', '=', 'bi.bookmark_id')
            ->where('bi.created_by', $uid)->whereIn('bi.bookmark_id', $visible)
            ->select(['bi.id', 'bi.type', 'bi.title', 'bi.url', 'bi.content', 'bi.is_completed', 'bi.created_at', 'bi.updated_at', 'bk.name as collection']);

        $this->jsonRows('bookmark_items.json', $items->lazyById(500, 'bi.id', 'id'), fn ($r) => [
            'collection' => $r->collection,
            'type' => $r->type,
            'title' => $r->title,
            'url' => $r->url,
            'content' => $r->content,
            'completed' => (bool) $r->is_completed,
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
        ]);
    }

    private function timeEntries(): void
    {
        $rows = DB::table('time_entries as te')
            ->join('workspaces as w', 'w.id', '=', 'te.workspace_id')
            ->leftJoin('projects as p', 'p.id', '=', 'te.project_id')
            ->leftJoin('billing_activities as ba', 'ba.id', '=', 'te.billing_activity_id')
            ->where('te.user_id', $this->user->id)->whereIn('te.project_id', $this->projectIds)
            ->select(['te.id', 'te.spent_on', 'te.minutes', 'te.note', 'te.billable', 'te.source', 'te.item_key', 'te.item_title', 'te.created_at', 'te.updated_at', 'w.name as workspace', 'p.name as project', 'ba.name as activity']);

        $this->jsonRows('time_entries.json', $rows->lazyById(1000, 'te.id', 'id'), fn ($r) => [
            'date' => $r->spent_on,
            'minutes' => (int) $r->minutes,
            'workspace' => $r->workspace,
            'project' => $r->project,
            'issue' => $r->item_key,
            'issue_title' => $r->item_title,
            'activity' => $r->activity,
            'billable' => (bool) $r->billable,
            'note' => $r->note,
            'source' => $r->source,
            'created_at' => self::ts($r->created_at),
            'updated_at' => self::ts($r->updated_at),
        ]);
    }

    private function myDay(): void
    {
        $entries = DB::table('my_day_entries')->where('user_id', $this->user->id)->orderBy('plan_date')->orderBy('position')
            ->get(['work_type', 'work_id', 'plan_date', 'position', 'created_at']);

        // titles only for work the person can still open
        $issues = DB::table('items as i')->join('boards as b', 'b.id', '=', 'i.board_id')
            ->whereIn('i.id', $entries->where('work_type', 'board')->pluck('work_id'))->whereIn('b.project_id', $this->projectIds)
            ->get(['i.id', 'i.key', 'i.title'])->keyBy('id');
        $tasks = DB::table('task_list_items as ti')->join('task_lists as tl', 'tl.id', '=', 'ti.task_list_id')
            ->whereIn('ti.id', $entries->where('work_type', 'list')->pluck('work_id'))->whereIn('tl.project_id', $this->projectIds)
            ->get(['ti.id', 'ti.title'])->keyBy('id');

        $rows = $entries->map(function ($r) use ($issues, $tasks) {
            $issue = $r->work_type === 'board' ? $issues->get($r->work_id) : null;
            $task = $r->work_type === 'list' ? $tasks->get($r->work_id) : null;

            return [
                'date' => $r->plan_date,
                'kind' => $r->work_type === 'board' ? 'issue' : 'task',
                'issue' => $issue?->key,
                'title' => $issue?->title ?? $task?->title,
                'position' => (int) $r->position,
                'added_at' => self::ts($r->created_at),
            ];
        })->all();

        $this->json('my_day.json', $rows);
    }

    private function stickyNotes(): void
    {
        $rows = DB::table('page_sticky_notes')->where('user_id', $this->user->id)->orderBy('id')
            ->get(['url', 'color', 'content', 'created_at', 'updated_at'])
            ->map(fn ($r) => [
                'on_page' => $r->url,
                'color' => $r->color,
                'content' => $r->content,
                'created_at' => self::ts($r->created_at),
                'updated_at' => self::ts($r->updated_at),
            ])->all();

        $this->json('sticky_notes.json', $rows);
    }

    private function notifications(): void
    {
        $rows = DB::table('notifications')
            ->where('notifiable_type', $this->user->getMorphClass())->where('notifiable_id', $this->user->id)
            ->where('created_at', '>=', now()->subDays(self::NOTIFICATION_DAYS))
            ->orderBy('created_at')->orderBy('id')
            ->select(['id', 'data', 'read_at', 'archived_at', 'created_at']);

        // the message as the person saw it in the bell: no excerpts of other people's text (body)
        $this->jsonRows('notifications.json', $rows->lazy(500), function ($r) {
            $data = self::decode($r->data);
            $data = is_array($data) ? $data : [];

            return [
                'type' => $data['type'] ?? null,
                'title' => $data['title'] ?? null,
                'project' => $data['project_name'] ?? null,
                'link' => self::withoutQuery($data['url'] ?? null),
                'received_at' => self::ts($r->created_at),
                'read_at' => self::ts($r->read_at),
                'archived_at' => self::ts($r->archived_at),
            ];
        });
    }

    private function devices(): void
    {
        $push = DB::table('push_subscriptions')
            ->where('subscribable_type', $this->user->getMorphClass())->where('subscribable_id', $this->user->id)
            ->orderBy('id')
            ->get(['device_label', 'user_agent', 'created_at', 'last_used_at', 'last_success_at', 'last_error_at', 'expired_at'])
            ->map(fn ($r) => [
                'device' => $r->device_label,
                'browser' => $r->user_agent,
                'added_at' => self::ts($r->created_at),
                'last_used_at' => self::ts($r->last_used_at),
                'last_delivered_at' => self::ts($r->last_success_at),
                'last_error_at' => self::ts($r->last_error_at),
                'expired_at' => self::ts($r->expired_at),
            ])->all();

        $sessions = [];
        if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $this->user->id)
                ->orderByDesc('last_activity')
                ->get(['ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($r) => [
                    'ip_address' => $r->ip_address,
                    'browser' => $r->user_agent,
                    'last_activity' => Carbon::createFromTimestampUTC((int) $r->last_activity)->toIso8601ZuluString(),
                ])->all();
        }

        $this->json('devices.json', ['push_notifications' => $push, 'signed_in_sessions' => $sessions], count: count($push) + count($sessions));
    }

    private function calendars(): void
    {
        $connections = DB::table('calendar_connections')->where('user_id', $this->user->id)->orderBy('id')
            ->get(['provider', 'kind', 'account_email', 'account_name', 'external_calendar_name', 'server_url', 'is_active', 'last_synced_at', 'created_at', 'deleted_at'])
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'kind' => $r->kind,
                'account_email' => $r->account_email,
                'account_name' => $r->account_name,
                'calendar' => $r->external_calendar_name,
                // the host only: a full URL can carry a private link
                'server' => is_string($r->server_url) ? parse_url($r->server_url, PHP_URL_HOST) : null,
                'active' => (bool) $r->is_active,
                'last_synced_at' => self::ts($r->last_synced_at),
                'connected_at' => self::ts($r->created_at),
                'disconnected_at' => self::ts($r->deleted_at),
            ])->all();

        $feed = DB::table('calendar_feeds')->where('user_id', $this->user->id)->orderBy('id')
            ->get(['options', 'last_fetched_at', 'fetch_count', 'revoked_at', 'created_at'])
            ->map(fn ($r) => [
                'options' => self::decode($r->options),
                'created_at' => self::ts($r->created_at),
                'last_fetched_at' => self::ts($r->last_fetched_at),
                'times_fetched' => (int) $r->fetch_count,
                'revoked_at' => self::ts($r->revoked_at),
            ])->all();

        $this->json('calendars.json', ['connections' => $connections, 'calendar_feed' => $feed], count: count($connections) + count($feed));
    }

    private function connectedApps(): void
    {
        $morph = $this->user->getMorphClass();

        $oauth = DB::table('oauth_access_tokens as t')->leftJoin('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->where('t.user_id', $this->user->id)->orderByDesc('t.created_at')->limit(1000)
            ->get(['c.name as app', 't.name', 't.scopes', 't.revoked', 't.created_at', 't.expires_at'])
            ->map(fn ($r) => [
                'app' => $r->app,
                'name' => $r->name,
                'scopes' => self::decode($r->scopes),
                'revoked' => (bool) $r->revoked,
                'issued_at' => self::ts($r->created_at),
                'expires_at' => self::ts($r->expires_at),
            ])->all();

        $personal = Schema::hasTable('personal_access_tokens')
            ? DB::table('personal_access_tokens')->where('tokenable_type', $morph)->where('tokenable_id', $this->user->id)->orderBy('id')
                ->get(['name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
                ->map(fn ($r) => [
                    'name' => $r->name,
                    'abilities' => self::decode($r->abilities),
                    'created_at' => self::ts($r->created_at),
                    'last_used_at' => self::ts($r->last_used_at),
                    'expires_at' => self::ts($r->expires_at),
                ])->all()
            : [];

        $clients = DB::table('oauth_clients')->where('owner_type', $morph)->where('owner_id', $this->user->id)->orderBy('created_at')
            ->get(['name', 'redirect_uris', 'grant_types', 'revoked', 'created_at'])
            ->map(fn ($r) => [
                'name' => $r->name,
                'redirect_uris' => self::decode($r->redirect_uris),
                'grant_types' => self::decode($r->grant_types),
                'revoked' => (bool) $r->revoked,
                'created_at' => self::ts($r->created_at),
            ])->all();

        $this->json('connected_apps.json', ['app_access_tokens' => $oauth, 'personal_api_tokens' => $personal, 'registered_apps' => $clients], count: count($oauth) + count($personal) + count($clients));
    }

    private function activity(): void
    {
        $issueKeys = fn (array $ids) => DB::table('items as i')->join('boards as b', 'b.id', '=', 'i.board_id')
            ->whereIn('i.id', $ids)->whereIn('b.project_id', $this->projectIds)->pluck('i.key', 'i.id');

        $rows = DB::table('activity_logs')->where('user_id', $this->user->id)
            ->where('created_at', '>=', now()->subDays(self::ACTIVITY_DAYS))
            ->orderByDesc('id')->limit(self::ACTIVITY_LIMIT)
            ->get(['loggable_type', 'loggable_id', 'action', 'changes', 'created_at']);
        $keys = $issueKeys($rows->where('loggable_type', Item::class)->pluck('loggable_id')->all());

        // what you did and when — the changed fields' names, not their values (they can be other people's text)
        $entries = $rows->reverse()->map(function ($r) use ($keys) {
            $changes = self::decode($r->changes);

            return [
                'at' => self::ts($r->created_at),
                'action' => $r->action,
                'on' => Str::snake(class_basename((string) $r->loggable_type)),
                'issue' => $r->loggable_type === Item::class ? $keys->get($r->loggable_id) : null,
                'fields' => is_array($changes) ? array_values(array_filter(array_keys($changes), 'is_string')) : [],
            ];
        })->values()->all();

        $this->json('activity.json', $entries);
    }

    private function adminAudit(): void
    {
        // admin actions on this account: what and when — not the admin's name or address
        $about = DB::table('admin_audit_logs')->where('target_type', 'user')->where('target_id', $this->user->id)->orderBy('id')
            ->get(['action', 'created_at'])
            ->map(fn ($r) => ['action' => $r->action, 'at' => self::ts($r->created_at)])->all();

        // the person's own actions as an instance admin (with their own IP address)
        $own = DB::table('admin_audit_logs')->where('actor_id', $this->user->id)->orderBy('id')
            ->get(['action', 'target_type', 'ip_address', 'created_at'])
            ->map(fn ($r) => ['action' => $r->action, 'target' => $r->target_type, 'ip_address' => $r->ip_address, 'at' => self::ts($r->created_at)])->all();

        $this->json('admin_audit.json', ['actions_on_your_account' => $about, 'your_admin_actions' => $own], count: count($about) + count($own));
    }

    private function guestPortals(): void
    {
        $user = $this->user;
        // only a verified account (the raw column — User::hasVerifiedEmail() is relaxed when the instance
        // doesn't require verification): the guest identity is matched or linked by the email address
        $identities = $user->email_verified_at === null ? collect() : DB::table('portal_identities')
            ->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhereRaw('lower(email) = ?', [Str::lower($user->email)]))
            ->orderBy('id')
            ->get(['id', 'email', 'name', 'email_verified_at', 'last_seen_at', 'created_at']);
        $ids = $identities->pluck('id')->all();

        $memberships = DB::table('client_portal_members as m')->join('client_portals as cp', 'cp.id', '=', 'm.client_portal_id')
            ->whereIn('m.portal_identity_id', $ids)->orderBy('m.id')
            ->get(['cp.title', 'm.invited_at', 'm.expires_at', 'm.revoked_at', 'm.last_seen_at'])
            ->map(fn ($r) => ['portal' => $r->title, 'invited_at' => self::ts($r->invited_at), 'access_until' => self::ts($r->expires_at), 'revoked_at' => self::ts($r->revoked_at), 'last_seen_at' => self::ts($r->last_seen_at)])->all();

        $decisions = DB::table('client_deliverable_decisions as dd')->join('client_deliverables as d', 'd.id', '=', 'dd.client_deliverable_id')
            ->join('client_portals as cp', 'cp.id', '=', 'd.client_portal_id')
            ->whereIn('dd.portal_identity_id', $ids)->orderBy('dd.id')
            ->get(['cp.title as portal', 'd.title as deliverable', 'dd.decision', 'dd.note', 'dd.version', 'dd.identity_name', 'dd.identity_email', 'dd.created_at'])
            ->map(fn ($r) => ['portal' => $r->portal, 'deliverable' => $r->deliverable, 'version' => $r->version, 'decision' => $r->decision, 'note' => $r->note, 'signed_as' => trim($r->identity_name.' <'.$r->identity_email.'>'), 'at' => self::ts($r->created_at)])->all();

        $comments = DB::table('client_deliverable_comments as cc')->join('client_deliverables as d', 'd.id', '=', 'cc.client_deliverable_id')
            ->join('client_portals as cp', 'cp.id', '=', 'd.client_portal_id')
            ->whereIn('cc.portal_identity_id', $ids)->orderBy('cc.id')
            ->get(['cp.title as portal', 'd.title as deliverable', 'cc.body', 'cc.created_at'])
            ->map(fn ($r) => ['portal' => $r->portal, 'deliverable' => $r->deliverable, 'body' => $r->body, 'at' => self::ts($r->created_at)])->all();

        $posts = DB::table('feedback_posts as fp')->join('feedback_portals as f', 'f.id', '=', 'fp.feedback_portal_id')
            ->whereIn('fp.author_identity_id', $ids)->orderBy('fp.id')
            ->get(['f.title as portal', 'fp.title', 'fp.body', 'fp.status', 'fp.moderation', 'fp.votes_count', 'fp.created_at'])
            ->map(fn ($r) => ['portal' => $r->portal, 'title' => $r->title, 'body' => $r->body, 'status' => $r->status, 'moderation' => $r->moderation, 'votes' => (int) $r->votes_count, 'at' => self::ts($r->created_at)])->all();

        $votes = DB::table('feedback_votes as v')->join('feedback_posts as fp', 'fp.id', '=', 'v.feedback_post_id')->join('feedback_portals as f', 'f.id', '=', 'fp.feedback_portal_id')
            ->whereIn('v.portal_identity_id', $ids)->orderBy('v.id')
            ->get(['f.title as portal', 'fp.title', 'v.created_at'])
            ->map(fn ($r) => ['portal' => $r->portal, 'idea' => $r->title, 'at' => self::ts($r->created_at)])->all();

        $follows = DB::table('feedback_subscriptions as s')->join('feedback_posts as fp', 'fp.id', '=', 's.feedback_post_id')->join('feedback_portals as f', 'f.id', '=', 'fp.feedback_portal_id')
            ->whereIn('s.portal_identity_id', $ids)->orderBy('s.id')
            ->get(['f.title as portal', 'fp.title', 's.created_at'])
            ->map(fn ($r) => ['portal' => $r->portal, 'idea' => $r->title, 'at' => self::ts($r->created_at)])->all();

        $this->json('guest_portals.json', [
            'identities' => $identities->map(fn ($r) => ['email' => $r->email, 'name' => $r->name, 'email_verified_at' => self::ts($r->email_verified_at), 'last_seen_at' => self::ts($r->last_seen_at), 'created_at' => self::ts($r->created_at)])->all(),
            'client_portal_access' => $memberships,
            'client_portal_decisions' => $decisions,
            'client_portal_comments' => $comments,
            'feedback_ideas' => $posts,
            'feedback_votes' => $votes,
            'feedback_follows' => $follows,
        ], count: count($ids) + count($memberships) + count($decisions) + count($comments) + count($posts) + count($votes) + count($follows));
    }

    /** Files the person attached to issues: copied while they fit MAX_UPLOAD_BYTES, else listed. */
    private function uploads(): void
    {
        $rows = DB::table('attachments as a')
            ->join('items as i', 'i.id', '=', 'a.item_id')
            ->join('boards as b', 'b.id', '=', 'i.board_id')
            ->where('a.user_id', $this->user->id)->whereIn('b.project_id', $this->projectIds)
            ->orderBy('a.id')
            ->get(['a.id', 'a.original_filename', 'a.path', 'a.mime_type', 'a.size', 'a.created_at', 'i.key']);

        $disk = Storage::disk('public');
        $total = (int) $rows->sum('size');
        $copy = $total <= self::MAX_UPLOAD_BYTES;
        $bytes = 0;

        $entries = $rows->map(function ($r) use ($disk, $copy, &$bytes) {
            $file = null;
            $path = (string) $r->path;
            if ($copy && $path !== '' && $disk->exists($path)) {
                $size = (int) $disk->size($path);
                if ($bytes + $size <= self::MAX_UPLOAD_BYTES) {
                    $file = 'files/attachments/'.$r->id.'-'.self::safeName((string) $r->original_filename, $path);
                    $this->zip->addFile($disk->path($path), $file);
                    $bytes += $size;
                }
            }

            return [
                'name' => $r->original_filename,
                'issue' => $r->key,
                'type' => $r->mime_type,
                'size_bytes' => (int) $r->size,
                'uploaded_at' => self::ts($r->created_at),
                'file' => $file,
            ];
        })->all();

        $this->json('uploads.json', [
            'files_included' => $copy,
            'note' => $copy ? null : 'Your files add up to more than '.(self::MAX_UPLOAD_BYTES / 1024 / 1024).' MB, so they are listed here but not copied. Download them from the issues, or ask us for a copy.',
            'attachments' => $entries,
        ], count: count($entries));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /** @param bool|int $count true = count($data), false = don't count, int = this number */
    private function json(string $name, array $data, bool|int $count = true): void
    {
        $this->zip->addFromString($name, (string) json_encode($data, self::JSON));
        if ($count !== false) {
            $this->counts[$name] = $count === true ? count($data) : $count;
        }
    }

    /** Stream a JSON array to a work file (big tables), then add it to the ZIP. */
    private function jsonRows(string $name, iterable $rows, callable $map): void
    {
        $path = $this->workDir.'/'.$name;
        $handle = fopen($path, 'wb');
        fwrite($handle, '[');
        $n = 0;
        foreach ($rows as $row) {
            $encoded = (string) json_encode($map($row), self::JSON);
            fwrite($handle, ($n === 0 ? "\n" : ",\n").'    '.str_replace("\n", "\n    ", $encoded));
            $n++;
        }
        fwrite($handle, $n === 0 ? "]\n" : "\n]\n");
        fclose($handle);

        $this->zip->addFile($path, $name);
        $this->counts[$name] = $n;
    }

    /**
     * The person's roles on a record, e.g. ['creator', 'assignee'] — never who else holds the others.
     *
     * @param  array<string, string>  $columns  column => role
     * @return list<string>
     */
    private function roles(object $row, array $columns): array
    {
        $roles = [];
        foreach ($columns as $column => $role) {
            if ($row->{$column} !== null && (int) $row->{$column} === $this->user->id) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** Whether the record or anything it lives in is in the trash. */
    private static function trashed(mixed ...$deletedAt): bool
    {
        return collect($deletedAt)->contains(fn ($v) => $v !== null);
    }

    private static function ts(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value, 'UTC')->toIso8601ZuluString();
    }

    private static function dateTime(mixed $date, mixed $time): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return $time ? $date.' '.substr((string) $time, 0, 5) : (string) $date;
    }

    private static function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** Readable text from HTML or editor JSON. */
    private static function text(mixed $content): ?string
    {
        if (is_array($content)) {
            $parts = [];
            array_walk_recursive($content, function ($value, $key) use (&$parts) {
                if (in_array($key, ['text', 'html'], true) && is_string($value)) {
                    $parts[] = $value;
                }
            });
            $content = implode("\n", $parts);
        }
        if (! is_string($content) || trim($content) === '') {
            return null;
        }
        $content = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/blockquote|/pre)\b[^>]*>#i', "\n", $content);
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }

    /** A URL without its query (old signed links) — the page it points to stays readable. */
    private static function withoutQuery(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        return explode('?', $url, 2)[0];
    }

    private static function extension(string $path): string
    {
        $ext = Str::lower(pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $ext) ? '.'.$ext : '';
    }

    /** A file name that is safe to unpack anywhere (no folders, no odd characters). */
    private static function safeName(string $original, string $storedPath): string
    {
        $base = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';

        return Str::limit($base, 80, '').(self::extension($original) ?: self::extension($storedPath));
    }

    /** What the archive deliberately leaves out (manifest + README). */
    private const NOT_INCLUDED = [
        'Secrets: password hash, sign-in, reset and remember-me tokens, OAuth and API tokens, app passwords, calendar credentials and private calendar links, calendar-feed token, push-service keys',
        'Other people’s data: their comments, pages, drafts and private content, and who else is assigned to or watches an issue',
        'Content in workspaces you no longer belong to or projects you can no longer open — the workspace is responsible for it; ask its owner',
        'Helpdesk tickets, leads and client records of a workspace (the workspace’s own records)',
        'Page version history, board views and other screen settings',
        'Web server logs and backups (kept for a limited time, see the privacy policy)',
    ];

    private static function readme(): string
    {
        $notIncluded = implode("\n", array_map(fn ($line) => '  - '.$line, self::NOT_INCLUDED));

        return <<<TXT
        Your Brainitive data
        ====================

        This archive contains the personal data Brainitive holds about your account — your right of
        access (Art. 15 GDPR) and data portability (Art. 20 GDPR). Every file is UTF-8 JSON; times are UTC
        (ISO 8601). Content you moved to the trash is included with "in_trash": true.

        Files
        -----
        manifest.json          Format, when this archive was made, and how many records each file holds.
        account.json           Profile (name, email, photo, bio, phone, time zone), settings, notification
                               choices, sign-in methods (provider names only), terms you accepted.
        memberships.json       Workspaces and projects you belong to, with your role.
        issues.json            Issues you created, are assigned to or reported: their fields and description.
        comments.json          Comments you wrote on issues.
        page_comments.json     Comments you wrote on pages (incl. review comments).
        client_portal_comments.json  Your replies to clients in client portals.
        pages.json             Documents and whiteboards you created (with your own unpublished draft).
        whiteboards/           Your whiteboards as .excalidraw scenes (open them at excalidraw.com).
        task_list_items.json   Tasks you created or that are assigned to you.
        captures.json          Quick captures (browser extension, quick capture).
        snippet_collections.json, snippets.json   Snippet collections and snippets you created.
        bookmarks.json, bookmark_items.json        Bookmark collections and bookmarks you created.
        time_entries.json      Time you logged.
        my_day.json            What you planned in My Day.
        sticky_notes.json      Your sticky notes.
        notifications.json     Notifications you received in the last 365 days (title and link).
        devices.json           Devices with push notifications (name, browser, dates) and signed-in sessions.
        calendars.json         Connected calendars (provider, account, calendar name) and your calendar feed
                               options.
        connected_apps.json    Apps with access to your account (AI connectors, CLI, extension): name, scopes,
                               dates.
        activity.json          What you did in issues and other content (last 2 years, at most 10,000 entries):
                               action, date and which fields changed.
        admin_audit.json       Actions instance administrators took on your account (and your own, if you are
                               one).
        guest_portals.json     Your guest identity on client and feedback portals: access, decisions, comments,
                               ideas, votes, follows.
        uploads.json           Files you attached to issues; copies are in files/attachments/ (when they add up
                               to 500 MB or less).
        files/                 Your profile photo and your uploaded files.

        Not included
        ------------
        {$notIncluded}

        Questions or something missing? Use the contact in the privacy policy.
        TXT;
    }
}
