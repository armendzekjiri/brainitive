<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Collab\PageCollab;
use App\Support\Mcp\ContentLinkFormatter;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read a single page/document. Returns its title, the published content as Markdown, its links (related content, referenced by) and edit_revision — pass that as expected_revision to update_page. When has_unpublished_changes is true, draft_markdown is what editors are working on (base a replace on it); being_edited says someone has it open in edit mode right now. Identify the page by its uuid (from list_pages or search).')]
class GetPageTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'page_uuid' => 'required|string',
        ]);

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

        $links = ContentLinkFormatter::references($project->workspace, $project, $user, $page);
        $linkFields = ['related_content' => $links['related'], 'referenced_by' => $links['referenced_by']];
        // what update_page needs to write safely (BRAI-411 rules: revision check, drafts, live editors)
        $editFields = ['status' => $page->status, 'edit_revision' => (int) $page->edit_revision, 'has_unpublished_changes' => $page->hasUnpublishedChanges()];

        if ($page->is_whiteboard) {
            return Response::structured([
                'title' => $page->name,
                'type' => 'whiteboard',
                'markdown' => '',
                'note' => 'This is an Excalidraw whiteboard page; its visual content cannot be represented as Markdown.',
                ...$editFields,
                ...$linkFields,
                'location' => ResourceLocator::locate($page),
            ]);
        }

        return Response::structured([
            'title' => $page->name,
            'type' => $page->type,
            'markdown' => PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($page->content)) ?? '',
            ...$editFields,
            // the working copy of a published page is the editors' business (readers see the published version)
            'draft_markdown' => $page->hasUnpublishedChanges() && $user->can('update', $page)
                ? (PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($page->draft_content)) ?? '')
                : null,
            'being_edited' => PageCollab::editingNow($page),
            ...$linkFields,
            'location' => ResourceLocator::locate($page),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
            'type' => $schema->string(),
            'markdown' => $schema->string()->description('Published page body as Markdown (for a never-published page: its current text; empty for whiteboards).'),
            'status' => $schema->string()->enum(['draft', 'published', 'archived']),
            'edit_revision' => $schema->integer()->description('Pass as expected_revision to update_page.'),
            'has_unpublished_changes' => $schema->boolean()->description('A published page with a draft readers do not see yet.'),
            'draft_markdown' => $schema->string()->nullable()->description('The unpublished draft as Markdown (editors only, when has_unpublished_changes).'),
            'being_edited' => $schema->boolean()->description('Someone has the doc open in edit mode right now: update_page then saves into the draft.'),
            'related_content' => ContentLinkFormatter::referenceSchema($schema)->description('Related content: issues, docs, tickets and plans this page links to.'),
            'referenced_by' => ContentLinkFormatter::referenceSchema($schema)->description('Referenced by: content that links to this page (incl. smart links).'),
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
            'page_uuid' => $schema->string()->description('The page uuid (from list_pages or search).')->required(),
        ];
    }
}
