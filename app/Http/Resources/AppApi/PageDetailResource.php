<?php

namespace App\Http\Resources\AppApi;

use App\Models\Page;
use App\Support\Boards\IssueMarkdown;
use App\Support\Collab\CollabConfig;
use App\Support\Collab\PageCollab;
use App\Support\Collab\WhiteboardCollab;
use App\Support\Mcp\PageContentConverter;
use App\Support\SafeHtml;
use Illuminate\Http\Request;

/**
 * One doc, whiteboard or folder (`GET /pages/{uuid}`, BRAI-411). The text is what the user's web view shows:
 * editors get the working copy (a published doc's unpublished changes included), readers the published
 * version. `markdown` (mentions `@[Name](user:<id>)`) is for editing; `html` (sanitized) for rendering.
 * Send `revision` back when changing the text: the page's edit revision, bumped by every save (live
 * editors too).
 *
 * @mixin Page
 */
class PageDetailResource extends PageResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $page = $this->resource;
        $canUpdate = $user->can('update', $page);
        $kind = self::kind($page);
        $state = $canUpdate ? $page->draftState() : ['name' => (string) $page->name, 'icon' => $page->icon, 'content' => $page->content];
        $html = $kind === 'doc' ? PageContentConverter::pageContentToHtml($state['content']) : null;
        $icon = (string) $state['icon'];
        $image = self::isImageIcon($icon);

        return [
            ...parent::toArray($request),
            'name' => $state['name'],
            'icon' => $image || $icon === '' ? null : $icon,
            'has_image_icon' => $image,
            // an uploaded icon: a data: URL or the image's address
            'icon_image' => $image ? (str_starts_with($icon, '/') ? url($icon) : $icon) : null,
            // docs only (null for whiteboards and folders)
            'markdown' => $html === null ? null : (IssueMarkdown::toMarkdown($html) ?? ''),
            'html' => $html === null ? null : (string) SafeHtml::clean($html),
            'revision' => (int) $page->edit_revision,
            // the number of the latest published version (0 = never published)
            'version' => (int) $page->current_version,
            'published_at' => $page->published_at?->toIso8601ZuluString(),
            'has_unpublished_changes' => $canUpdate && $page->hasUnpublishedChanges(),
            'review_required' => (bool) $page->review_required,
            // the ancestors, top first (the web's breadcrumbs)
            'breadcrumbs' => PageCrumbResource::collection(array_slice($page->pathFor($user), 0, -1)),
            'creator' => $page->creator ? new PersonResource($page->creator) : null,
            'last_edited_by' => $page->lastEditor ? new PersonResource($page->lastEditor) : null,
            'watching' => $page->watchers()->whereKey($user->id)->exists(),
            /**
             * live editing offered to this user: edit, view (watch a never-published doc; any reader of a whiteboard), or null.
             * Docs: `POST /pages/{uuid}/collab-token`; whiteboards: `POST /whiteboards/{uuid}/collab-token`
             *
             * @var 'edit'|'view'|null
             */
            'live' => CollabConfig::enabled($request)
                ? ($kind === 'whiteboard' ? WhiteboardCollab::access($page, $user, $canUpdate) : PageCollab::access($page, $user, $canUpdate))
                : null,
            'can' => [
                // rename, change the text (docs), move
                'update' => $canUpdate,
                'delete' => $user->can('delete', $page),
                'publish' => $canUpdate && $kind !== 'folder',
                // new pages in this project
                'create' => $user->can('create', [Page::class, $page->project]),
            ],
        ];
    }
}
