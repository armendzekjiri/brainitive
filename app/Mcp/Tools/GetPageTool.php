<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read a single page/document. Returns its title and full content as Markdown. Identify the page by its uuid (from list_pages or search).')]
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

        $page = $project->allPages()->where('uuid', $validated['page_uuid'])->first();

        if (! $page) {
            return Response::error('No page found with that uuid in this project.');
        }

        if ($page->is_whiteboard) {
            return Response::structured([
                'title' => $page->name,
                'type' => 'whiteboard',
                'markdown' => null,
                'note' => 'This is an Excalidraw whiteboard page; its visual content cannot be represented as Markdown.',
                'location' => ResourceLocator::locate($page),
            ]);
        }

        return Response::structured([
            'title' => $page->name,
            'type' => $page->type,
            'markdown' => PageContentConverter::docToMarkdown($page->content),
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
            'markdown' => $schema->string()->description('Page body as Markdown (null for whiteboards).'),
            'location' => \App\Support\Mcp\ResourceLocator::outputSchema($schema),
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
