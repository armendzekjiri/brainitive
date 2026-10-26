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

#[Description('Update an existing page: rename it and/or change its body. Body is given as Markdown. Use mode "replace" to overwrite the whole document or "append" to add the Markdown to the end. Identify the page by uuid.')]
class UpdatePageTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'page_uuid' => 'required|string',
            'title' => 'nullable|string|max:255',
            'markdown' => 'nullable|string',
            'mode' => 'nullable|in:replace,append',
        ]);

        if (empty($validated['title']) && ! isset($validated['markdown'])) {
            return Response::error('Provide a new title and/or markdown to update.');
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
        if ($page->is_whiteboard) {
            return Response::error('Whiteboard pages cannot be edited via Markdown.');
        }

        try {
            $this->authorizeFor($user, 'update', $page);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        // BRAI-80: a published "review required" page only changes through an approved review
        if ($page->isPublished() && $page->review_required) {
            return Response::error('This page needs an approved review before changes go live. Edit it in Brainitive and request a review.');
        }

        $changes = ['last_edited_by' => $user->id];

        if (! empty($validated['title'])) {
            $changes['name'] = $validated['title'];
        }

        if (isset($validated['markdown'])) {
            $html = PageContentConverter::markdownToHtml($validated['markdown']);

            if (($validated['mode'] ?? 'replace') === 'append') {
                $existing = PageContentConverter::pageContentToHtml($page->content);
                $html = $existing.$html;
            }

            $changes['content'] = PageContentConverter::htmlToPageContent($html);
        }

        // Published pages change for readers here, so keep the edit in the version history
        if ($page->isPublished()) {
            $page->discardDraft();
            $page->update($changes);
            $page->recordVersion($user, 'Edited via MCP');
        } else {
            $page->update($changes);
        }

        return Response::structured([
            'updated' => true,
            'page_uuid' => $page->uuid,
            'location' => ResourceLocator::locate($page->fresh()),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'page_uuid' => $schema->string(),
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
            'mode' => $schema->string()->enum(['replace', 'append'])->description('How to apply markdown: replace the whole body (default) or append to the end.')->default('replace'),
        ];
    }
}
