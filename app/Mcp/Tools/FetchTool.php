<?php

namespace App\Mcp\Tools;

use App\Models\Bookmark;
use App\Models\Item;
use App\Models\Page;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\Workspace;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Named exactly `fetch` for ChatGPT deep-research connectors. Resolves a
 * "type:id" handle returned by `search` into the full document text.
 */
#[Name('fetch')]
#[Description('Fetch the full content of a single search result. Pass the "type:id" handle from the search tool. Returns the title, full text and URL.')]
class FetchTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'id' => 'required|string',
        ]);

        $user = $request->user();
        [$type, $id] = array_pad(explode(':', $validated['id'], 2), 2, null);

        if (! $id) {
            return Response::error('Invalid id. Expected the "type:id" handle returned by search.');
        }

        $workspaceIds = $user->isInstanceAdmin()
            ? Workspace::query()->pluck('id')->all()
            : $user->workspaces()->pluck('workspaces.id')->all();

        $model = match ($type) {
            'page' => Page::with('project.workspace')->find($id),
            'item' => Item::with('board.project.workspace')->find($id),
            'project' => Project::with('workspace')->find($id),
            'workspace' => Workspace::find($id),
            'snippet' => SnippetCollection::with(['workspace', 'snippets'])->find($id),
            'bookmark' => Bookmark::with(['workspace', 'items'])->find($id),
            default => null,
        };

        if (! $model) {
            return Response::error('No content found for that id.');
        }

        if (! $this->userCanAccess($model, $type, $workspaceIds)) {
            return Response::error('You do not have access to that content.');
        }

        [$title, $text] = $this->extract($model, $type);

        return Response::structured([
            'id' => $validated['id'],
            'title' => $title,
            'text' => $text,
            'url' => ResourceLocator::locate($model)['url'] ?? null,
            'metadata' => ['type' => $type],
        ]);
    }

    private function userCanAccess(mixed $model, string $type, array $workspaceIds): bool
    {
        $workspaceId = match ($type) {
            'page' => $model->project?->workspace_id,
            'item' => $model->board?->project?->workspace_id ?? $model->project?->workspace_id,
            'project' => $model->workspace_id,
            'workspace' => $model->id,
            'snippet', 'bookmark' => $model->workspace_id,
            default => null,
        };

        return $workspaceId !== null && in_array($workspaceId, $workspaceIds, false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extract(mixed $model, string $type): array
    {
        return match ($type) {
            'page' => [$model->name, PageContentConverter::htmlToMarkdown(PageContentConverter::pageContentToHtml($model->content))],
            'item' => [$model->title ?: $model->key, trim(($model->key ? $model->key.' — ' : '').strip_tags((string) $model->description))],
            'project' => [$model->name, (string) $model->description],
            'workspace' => [$model->name, (string) $model->description],
            'snippet' => [$model->name, $model->snippets->map(fn ($s) => "## {$s->title} ({$s->language})\n\n{$s->content}")->implode("\n\n")],
            'bookmark' => [$model->name, $model->items->map(fn ($i) => '- '.trim(($i->title ?: '').' '.($i->url ?: '').' '.($i->content ?: '')))->implode("\n")],
            default => ['Unknown', ''],
        };
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string(),
            'title' => $schema->string(),
            'text' => $schema->string()->description('Full content of the result.'),
            'url' => $schema->string(),
            'metadata' => $schema->object(['type' => $schema->string()]),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The "type:id" handle from a search result.')->required(),
        ];
    }
}
