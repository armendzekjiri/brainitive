<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Page;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new page/document in a project. Provide the body as Markdown (headings, lists, bold, links, code blocks are supported) — it is converted to the editor format automatically. Optionally nest it under a parent page/folder by uuid. New pages are drafts visible only to their author (and admins) until published — pass publish=true to make it visible to the project right away. Returns the new page uuid, its status and its location (breadcrumb + URL).')]
class CreatePageTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'title' => 'required|string|max:255',
            'markdown' => 'nullable|string',
            'parent_uuid' => 'nullable|string',
            'publish' => 'nullable|boolean',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
            $this->authorizeFor($user, 'create', [Page::class, $project]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $parentId = null;
        if (! empty($validated['parent_uuid'])) {
            $parent = $project->allPages()->visibleTo($user)->where('uuid', $validated['parent_uuid'])->first();
            if (! $parent) {
                return Response::error('The parent_uuid does not match a page in this project.');
            }
            $parentId = $parent->id;
        }

        $content = ! empty($validated['markdown'])
            ? PageContentConverter::htmlToPageContent(PageContentConverter::markdownToHtml($validated['markdown']))
            : ['type' => 'lexical', 'html' => ''];

        $page = $project->allPages()->create([
            'name' => $validated['title'],
            'type' => 'page',
            'parent_id' => $parentId,
            'icon' => '📄',
            'created_by' => $user->id,
            'content' => $content,
        ]);

        if ($validated['publish'] ?? false) {
            $page->publish($user);
        }

        return Response::structured([
            'created' => true,
            'page_uuid' => $page->uuid,
            'status' => $page->status,
            'location' => ResourceLocator::locate($page),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'page_uuid' => $schema->string(),
            'status' => $schema->string(),
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
            'title' => $schema->string()->description('The page title.')->required(),
            'markdown' => $schema->string()->description('Page body as Markdown. Optional — omit for an empty page.'),
            'parent_uuid' => $schema->string()->description('Optional uuid of a parent page or folder to nest this page under.'),
            'publish' => $schema->boolean()->description('Publish immediately so the whole project can read it. Default false (author-only draft).'),
        ];
    }
}
