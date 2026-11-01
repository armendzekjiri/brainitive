<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
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

#[Description('Read a single page/document. Returns its title, full content as Markdown and its links (related content, referenced by). Identify the page by its uuid (from list_pages or search).')]
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

        if ($page->is_whiteboard) {
            return Response::structured([
                'title' => $page->name,
                'type' => 'whiteboard',
                'markdown' => '',
                'note' => 'This is an Excalidraw whiteboard page; its visual content cannot be represented as Markdown.',
                ...$linkFields,
                'location' => ResourceLocator::locate($page),
            ]);
        }

        return Response::structured([
            'title' => $page->name,
            'type' => $page->type,
            'markdown' => PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($page->content)) ?? '',
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
            'markdown' => $schema->string()->description('Page body as Markdown (empty for whiteboards).'),
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
