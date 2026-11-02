<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\CreatesOnce;
use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListPagesRequest;
use App\Http\Requests\AppApi\MovePageRequest;
use App\Http\Requests\AppApi\PublishPageRequest;
use App\Http\Requests\AppApi\StorePageRequest;
use App\Http\Requests\AppApi\UpdatePageRequest;
use App\Http\Resources\AppApi\PageCollection;
use App\Http\Resources\AppApi\PageDetailResource;
use App\Models\Page;
use App\Models\Project;
use App\Support\Boards\IssueMarkdown;
use App\Support\Entitlements\LimitGate;
use App\Support\Entitlements\LimitReached;
use App\Support\Mcp\PageContentConverter;
use App\Support\PageReviews\ReviewGate;
use App\Support\Pages\PageMover;
use App\Support\Pages\PageWrites;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — docs: the page tree, read, create, edit, publish, move (BRAI-411). The web editor's code and
 * rules: PagePolicy (unpublished pages are their author's and the admins'), Support\Pages\PageWrites (create,
 * draft save with the edit-revision check, publish through the review gate + watcher notifications; shared
 * with PageController and the MCP) and PageMover. Text is Markdown both ways (IssueMarkdown).
 *
 * Docs are edited live in the browser (Yjs). An app's text change is a save from outside the live document,
 * as the web's single-user editor makes: it needs the `revision` the app read (409 otherwise) and starts a new
 * live document, so open browsers reload on it. Live editing from the app: `POST /pages/{uuid}/collab-token`.
 */
#[Group('Docs', weight: 230)]
class PageController extends Controller
{
    use CreatesOnce;
    use ResolvesVisible;

    /**
     * A project's page tree: docs, whiteboards and folders, flat.
     *
     * Build the tree with `parent_id` / `parent_uuid`; inside a parent the web's order is docs and whiteboards
     * before folders, then `position` (this list's order). `?parent=root` or `?parent=<uuid>` loads one level.
     * Unpublished pages are listed only for their author and the project / workspace admins, as on the web.
     *
     * @throws NotFoundHttpException the project (or `parent`) doesn't exist or the user can't open it (404)
     */
    public function index(ListPagesRequest $request, string $project): PageCollection
    {
        $project = $this->visibleProject($request, $project);
        $user = $request->user();

        $parentId = null;
        if (($parent = $request->parent()) !== null && $parent !== 'root') {
            $parentId = $project->allPages()->visibleTo($user)->where('uuid', $parent)->value('id');
            abort_if($parentId === null, 404);
        }

        $page = $project->allPages()->visibleTo($user)
            // the tree never loads the content (whiteboard scenes are large): kind and a short icon in SQL
            ->select(['pages.id', 'pages.uuid', 'pages.project_id', 'pages.parent_id', 'pages.name', 'pages.type', 'pages.status', 'pages.position', 'pages.created_at', 'pages.updated_at'])
            ->selectRaw('substr(pages.icon, 1, 64) as icon')
            ->selectRaw("CASE WHEN pages.type = 'folder' THEN 'folder' WHEN pages.content IS NOT NULL AND ((pages.content::jsonb ->> 'type') = 'excalidraw' OR (jsonb_exists(pages.content::jsonb, 'elements') AND jsonb_exists(pages.content::jsonb, 'appState'))) THEN 'whiteboard' ELSE 'doc' END as kind")
            ->selectRaw('(SELECT p.uuid FROM pages p WHERE p.id = pages.parent_id) as parent_uuid')
            ->when($parent === 'root', fn ($q) => $q->whereNull('pages.parent_id'))
            ->when($parentId !== null, fn ($q) => $q->where('pages.parent_id', $parentId))
            ->when($request->updatedSince(), fn ($q, $since) => $q->where('pages.updated_at', '>=', $since))
            ->orderByDesc('pages.type')
            ->orderBy('pages.position')
            ->orderBy('pages.id')
            ->cursorPaginate($request->limit(), ['*'], 'cursor', $request->cursor());
        $page->getCollection()->each(fn (Page $p) => $p->setRelation('project', $project));

        return new PageCollection($page);
    }

    /**
     * One doc, whiteboard or folder: the text as Markdown (and sanitized HTML), `revision`, its place in the tree
     * and what the user may do.
     *
     * Editors read the working copy (a published doc's unpublished changes included), readers the published
     * version. A whiteboard's scene: `GET /whiteboards/{uuid}`.
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function show(Request $request, string $uuid): PageDetailResource
    {
        return new PageDetailResource(self::detail($this->visiblePage($request, $uuid)));
    }

    /**
     * Create a doc, whiteboard or folder.
     *
     * `markdown` is the doc's text (mentions `@[Name](user:<id>)` notify project members). New docs and
     * whiteboards are drafts only their author (and admins) see, until published (`publish: true` or
     * `POST /pages/{uuid}/publish`). Needs `can.create` of the project (403). With `request_id` a retry answers
     * 200 with the page the first call created.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     * @throws LimitReached the workspace is at its storage limit (402 limit_reached)
     */
    public function store(StorePageRequest $request): JsonResponse
    {
        $project = $request->project();
        app(LimitGate::class)->ensureContentRoom($project->workspace);
        $user = $request->user();
        $kind = $request->kind();
        $parent = $request->parentPage();

        [$page, $replayed] = $this->createOnce($request, 'pages.store', Page::class, ['project' => $project->id] + $request->safe()->except(['request_id']),
            function () use ($request, $project, $user, $kind, $parent) {
                $page = PageWrites::create($project, $user, [
                    'name' => (string) $request->validated('name'),
                    'type' => $kind === 'folder' ? 'folder' : 'page',
                    'content_type' => $kind === 'whiteboard' ? 'excalidraw' : 'doc',
                    'parent_id' => $parent?->id,
                    'icon' => $request->validated('icon'),
                    'html' => $kind === 'doc' ? (IssueMarkdown::toHtml($request->validated('markdown'), $project) ?? '') : null,
                ]);
                if ($kind !== 'folder' && $request->boolean('publish')) {
                    PageWrites::publish($page, $user, null);
                }

                return $page;
            });

        $page = self::detail($page);
        if ($replayed) {
            return (new PageDetailResource($page))->response();
        }

        return (new PageDetailResource($page))->response()->setStatusCode(201);
    }

    /**
     * Change a page; only the sent fields change.
     *
     * A doc's `name`, `icon` and `markdown` are saved as the editor saves them: on a published doc they become
     * unpublished changes (readers see them after `POST /pages/{uuid}/publish`), a draft changes directly.
     * `markdown` replaces the whole text and needs the `revision` you last read: 409 `conflict` when someone
     * (or a live editor) saved since — load the page again. Editor formatting Markdown lacks is lost: send the
     * text only when the user changed it. Whiteboards and folders: `name` and `icon`. Needs `can.update` (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     * @throws ConflictHttpException the page changed since `revision` (409)
     */
    public function update(UpdatePageRequest $request): PageDetailResource
    {
        $page = $request->page();
        $user = $request->user();
        $revision = $request->revision();
        $changes = $request->safe()->only(['name', 'icon']);

        if ($page->isPage() && ! $page->is_whiteboard) {
            if ($request->has('markdown')) {
                $changes['content'] = PageContentConverter::htmlToPageContent(IssueMarkdown::toHtml($request->validated('markdown'), $page->project) ?? '');
            }
            if ($changes !== [] && ! PageWrites::saveDraft($page, $user, $changes, $revision)) {
                abort(409, 'This page was changed since you loaded it. Load it again and retry.');
            }
        } elseif ($changes !== []) {
            // whiteboards and folders have no draft: the name / icon change directly, as in the sidebar
            abort_if(PageWrites::isStale($page, $revision), 409, 'This page was changed since you loaded it. Load it again and retry.');
            $page->update($changes + ['last_edited_by' => $user->id]);
        }

        return new PageDetailResource(self::detail($page));
    }

    /**
     * Publish a doc or whiteboard, or make a published doc's unpublished changes live.
     *
     * A draft becomes visible to the project (version 1); on a published doc the changes become a new version and
     * its watchers are notified (`notify: false` to skip). Nothing to publish → 200 with the page as it is. A
     * "review required" doc publishes only an approved review of the current draft (422 `review`). Send
     * `revision` to get 409 instead of publishing changes you haven't seen. Needs `can.publish` (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     * @throws ConflictHttpException the page changed since `revision` (409)
     */
    public function publish(PublishPageRequest $request): PageDetailResource
    {
        $page = $request->page();
        if ($page->isFolder()) {
            throw ValidationException::withMessages(['page' => 'Folders are not published.']);
        }
        $user = $request->user();
        $revision = $request->validated('revision');
        $revision = $revision === null ? null : (int) $revision;
        $message = $request->validated('message');

        if ($page->isPublished()) {
            $result = PageWrites::publishChanges($page, $user, [], $revision, $message, $request->boolean('notify', true));
        } else {
            abort_if(PageWrites::isStale($page, $revision), 409, 'This page was changed since you loaded it. Load it again and retry.');
            $result = PageWrites::publish($page, $user, $message);
        }

        abort_if($result === PageWrites::STALE, 409, 'This page was changed since you loaded it. Load it again and retry.');
        if ($result === ReviewGate::REFUSAL) {
            throw ValidationException::withMessages(['review' => ReviewGate::REFUSAL]);
        }

        return new PageDetailResource(self::detail($page));
    }

    /**
     * Move a page, whiteboard or folder: into a folder or page, to the top level, or next to a sibling.
     *
     * `parent_uuid: null` = the top level. `before_uuid` / `after_uuid` (one at most) place it next to a sibling
     * in the new parent; without them it goes to the end. Into itself or its own subpages → 422. Its subpages
     * move with it. Needs `can.update` (403).
     *
     * @throws NotFoundHttpException the page doesn't exist or the user can't see it (404)
     */
    public function move(MovePageRequest $request): PageDetailResource
    {
        $page = $request->page();
        $project = $page->project;
        $parent = $this->pageOf($request, $project, 'parent_uuid');
        $before = $this->pageOf($request, $project, 'before_uuid');
        $after = $this->pageOf($request, $project, 'after_uuid');

        try {
            $moved = PageMover::move($page, $parent, $before?->id, $after?->id);
        } catch (ValidationException $e) {
            // PageMover names the web's fields (parent_id, before_id, …)
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[str_replace('_id', '_uuid', $field)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        return new PageDetailResource(self::detail($moved));
    }

    /** A page of the project the user can see, named by a uuid field of the request; 422 when there is none. */
    private function pageOf(Request $request, Project $project, string $field): ?Page
    {
        $uuid = $request->input($field);
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }
        $page = $project->allPages()->visibleTo($request->user())->where('uuid', $uuid)->first();
        if ($page === null) {
            throw ValidationException::withMessages([$field => 'That folder or page doesn\'t exist in this project.']);
        }

        return $page;
    }

    /** A fresh page with what PageDetailResource needs. */
    public static function detail(Page $page): Page
    {
        return Page::with(['project.workspace', 'creator', 'lastEditor', 'parent'])->findOrFail($page->id);
    }
}
