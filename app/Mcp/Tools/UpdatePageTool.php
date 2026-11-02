<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use App\Support\Collab\PageCollab;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use App\Support\Pages\PageMover;
use App\Support\Pages\PageWrites;
use App\Support\Validation\ContentLimits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an existing page: rename it, change its body and/or move it. Body is given as Markdown: mode "replace" (default) sets the whole body, "append" adds to the end. Pass expected_revision = edit_revision from get_page: if the page changed since, nothing changes and you get the current revision — call get_page again, never retry blindly. Body changes are saved like the web editor: a never-published page is updated; a published page without unpublished changes goes live as a new version (watchers notified); a published page with unpublished changes (a draft), someone editing it right now, or "review required" only gets the change in its draft (result "saved_as_draft": readers keep the published version until someone publishes it in the app). Replacing an existing draft needs expected_revision (base it on draft_markdown from get_page). To move the page (or a whiteboard / folder) pass parent_uuid: the uuid of a folder or page in the same project, or null for the top level. Identify the page by uuid.')]
class UpdatePageTool extends Tool
{
    use InteractsWithBrainitive;

    /** Without expected_revision a save landing between read and write is re-read this often, never overwritten. */
    private const ATTEMPTS = 3;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'page_uuid' => 'required|string',
            'title' => 'nullable|string|max:255',
            'markdown' => 'nullable|string|max:'.ContentLimits::PAGE_BODY,
            'mode' => 'nullable|in:replace,append',
            'parent_uuid' => 'nullable|uuid',
            'expected_revision' => 'nullable|integer|min:0',
        ]);

        // BRAI-431: parent_uuid present (null / "" = top level) means "move"
        $moving = array_key_exists('parent_uuid', $request->all());
        $editing = ! empty($validated['title']) || isset($validated['markdown']);

        if (! $editing && ! $moving) {
            return Response::error('Provide a new title, markdown and/or parent_uuid to update.');
        }

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $page = $project->allPages()->visibleTo($user)->where('uuid', $validated['page_uuid'])->first();
        if (! $page) {
            return Response::error('No page found with that uuid in this project.');
        }
        if ($page->is_whiteboard && $editing) {
            return Response::error('Whiteboard pages cannot be edited via Markdown.');
        }

        try {
            $this->authorizeFor($user, 'update', $page);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $expected = isset($validated['expected_revision']) ? (int) $validated['expected_revision'] : null;
        if (PageWrites::isStale($page, $expected)) {
            return $this->conflict($page);
        }

        if ($moving) {
            $parent = null;
            if (! empty($validated['parent_uuid'])) {
                $parent = $project->allPages()->visibleTo($user)->where('uuid', $validated['parent_uuid'])->first();
                if (! $parent) {
                    return Response::error('The parent_uuid does not match a page or folder in this project.');
                }
            }

            try {
                $page = PageMover::move($page, $parent);
            } catch (ValidationException $e) {
                return Response::error(collect($e->errors())->flatten()->first() ?? 'The page could not be moved.');
            }
        }

        if (! $editing) {
            return $this->result($page, 'moved');
        }

        if (! isset($validated['markdown'])) {
            return $this->rename($page, $user, $validated['title'], $expected);
        }

        $html = PageContentConverter::markdownToHtml($validated['markdown']);
        $append = ($validated['mode'] ?? 'replace') === 'append';

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $page = $page->fresh() ?? $page;
            if (PageWrites::isStale($page, $expected)) {
                return $this->conflict($page);
            }
            // the revision this write builds on: the caller's, else the one just read — a save in between is never overwritten
            $base = $expected ?? (int) $page->edit_revision;

            $changes = ['content' => PageContentConverter::htmlToPageContent(
                $append ? PageContentConverter::pageContentToHtml($page->draftState()['content']).$html : $html
            )];
            if (! empty($validated['title'])) {
                $changes['name'] = $validated['title'];
            }

            // never published: the page is its own draft (the web editor's save)
            if (! $page->isPublished()) {
                if (PageWrites::saveDraft($page, $user, $changes, $base)) {
                    return $this->result($page, 'saved');
                }

                continue;
            }

            $why = match (true) {
                $page->review_required => 'this page needs an approved review before changes go live',
                $page->hasUnpublishedChanges() => 'this page has unpublished changes',
                PageCollab::editingNow($page) => 'someone is editing this page right now',
                default => null,
            };
            if ($why === null) {
                // the web's "publish changes": new version, watchers notified, review gate on the locked row
                $outcome = PageWrites::publishChanges($page, $user, $changes, $base, 'Edited via MCP', true);
                if ($outcome === PageWrites::STALE) {
                    continue;
                }

                return match (true) {
                    $outcome instanceof PageVersion => $this->result($page, 'published', $outcome->version),
                    $outcome === PageWrites::UNCHANGED => $this->result($page, 'unchanged'),
                    // "review required" switched on meanwhile: the change was saved into the draft, not published
                    default => $this->result($page, 'saved_as_draft', note: 'This page needs an approved review before changes go live. The change was saved as a draft: request a review and publish it in Brainitive.'),
                };
            }

            // someone's unpublished changes: replacing them needs proof the agent read them
            if (! $append && $expected === null && $page->hasUnpublishedChanges()) {
                $by = $page->draftEditor?->name;

                return Response::error('This published page has unpublished changes'.($by ? " by {$by}" : '').' that a replace would overwrite. Nothing was changed. '
                    ."Call get_page, base your text on draft_markdown and pass expected_revision ({$page->edit_revision}), or use mode \"append\".");
            }

            if (PageWrites::saveDraft($page, $user, $changes, $base)) {
                return $this->result($page, 'saved_as_draft', note: "Saved as draft because {$why}; readers still see the published version. Publish it in Brainitive to make it live.");
            }
        }

        return $expected === null
            ? Response::error('The page is being changed right now, so nothing was changed. Call get_page and try again with expected_revision.')
            : $this->conflict($page->fresh() ?? $page);
    }

    /**
     * Title only: renamed in place as before (a published page records a version); an unpublished draft is kept and
     * follows the new title, so publishing it doesn't bring the old one back. "Review required": waits in the draft.
     */
    private function rename(Page $page, User $user, string $title, ?int $expected): Response|ResponseFactory
    {
        if ($page->isPublished() && $page->review_required) {
            if (! PageWrites::saveDraft($page, $user, ['name' => $title], $expected)) {
                return $this->conflict($page->fresh() ?? $page);
            }

            return $this->result($page, 'saved_as_draft', note: 'This page needs an approved review before changes go live. The new title was saved as a draft: request a review and publish it in Brainitive.');
        }

        $page->update(['name' => $title, 'last_edited_by' => $user->id] + ($page->hasUnpublishedChanges() ? ['draft_name' => $title] : []));
        if ($page->isPublished()) {
            $page->recordVersion($user, 'Edited via MCP');
        }

        return $this->result($page, 'renamed');
    }

    private function conflict(Page $page): Response|ResponseFactory
    {
        return Response::error("Conflict: the page changed since you read it (it is now at edit_revision {$page->edit_revision}). Nothing was changed. Call get_page to read it again, then update with the new expected_revision.");
    }

    /**
     * @param  'moved'|'renamed'|'saved'|'published'|'saved_as_draft'|'unchanged'  $result
     */
    private function result(Page $page, string $result, ?int $version = null, ?string $note = null): Response|ResponseFactory
    {
        $page = $page->fresh() ?? $page;

        return Response::structured(array_filter([
            'updated' => true,
            'page_uuid' => $page->uuid,
            'result' => $result,
            'status' => $page->status,
            'edit_revision' => (int) $page->edit_revision,
            'has_unpublished_changes' => $page->hasUnpublishedChanges(),
            'version' => $version,
            'note' => $note,
            'location' => ResourceLocator::locate($page),
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'page_uuid' => $schema->string(),
            'result' => $schema->string()->enum(['moved', 'renamed', 'saved', 'published', 'saved_as_draft', 'unchanged'])
                ->description('saved = never-published page updated; published = live as a new version; saved_as_draft = only in the unpublished draft (publish it in the app).'),
            'status' => $schema->string()->enum(['draft', 'published', 'archived']),
            'edit_revision' => $schema->integer()->description('The new revision: pass it as expected_revision to the next update.'),
            'has_unpublished_changes' => $schema->boolean(),
            'version' => $schema->integer()->description('The new version number (result published).'),
            'note' => $schema->string()->description('Why the change only went into the draft.'),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'page_uuid' => $schema->string()->description('The page uuid to update.')->required(),
            'title' => $schema->string()->description('Optional new title.'),
            'markdown' => $schema->string()->description('Optional new body as Markdown.'),
            'mode' => $schema->string()->enum(['replace', 'append'])->description('How to apply markdown: replace the whole body (default) or append to the end (of the draft, when the change goes into the draft).')->default('replace'),
            'parent_uuid' => $schema->string()->nullable()->description('Optional: move the page. The uuid of a folder or page in the same project to move it into, or null to move it to the top level. Leave it out to keep the page where it is.'),
            'expected_revision' => $schema->integer()->description('edit_revision from get_page (recommended). If the page changed since, nothing is changed and you get the current revision. Required to replace a published page\'s unpublished draft.'),
        ];
    }
}
