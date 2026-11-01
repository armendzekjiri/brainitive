<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use App\Support\Pages\PageMover;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an existing page: rename it, change its body and/or move it. Body is given as Markdown. Use mode "replace" to overwrite the whole document or "append" to add the Markdown to the end. To move the page (or a whiteboard / folder) pass parent_uuid: the uuid of a folder or page in the same project, or null for the top level. Identify the page by uuid.')]
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
            'parent_uuid' => 'nullable|uuid',
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
            return Response::structured([
                'updated' => true,
                'page_uuid' => $page->uuid,
                'location' => ResourceLocator::locate($page->fresh()),
            ]);
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
            'parent_uuid' => $schema->string()->nullable()->description('Optional: move the page. The uuid of a folder or page in the same project to move it into, or null to move it to the top level. Leave it out to keep the page where it is.'),
        ];
    }
}
