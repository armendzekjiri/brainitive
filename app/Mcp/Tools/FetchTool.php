<?php

namespace App\Mcp\Tools;

use App\Models\Bookmark;
use App\Models\Capture;
use App\Models\Item;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Project;
use App\Models\SnippetCollection;
use App\Models\TaskListItem;
use App\Models\Ticket;
use App\Models\Workspace;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use App\Support\Search\SearchText;
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

        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();

        $model = match ($type) {
            'page' => Page::with('project.workspace')->find($id),
            'item' => Item::with('board.project.workspace')->find($id),
            'project' => Project::with('workspace')->find($id),
            'workspace' => Workspace::find($id),
            'snippet' => SnippetCollection::with(['workspace', 'snippets'])->find($id),
            'bookmark' => Bookmark::with(['workspace', 'items'])->find($id),
            // v1.43.0: every handle the search tool returns (BRAI-55)
            'ticket' => Ticket::with(['helpdesk.project.workspace', 'replies'])->find($id),
            'plan' => Plan::with('project.workspace')->find($id),
            'task' => TaskListItem::with('taskList.project.workspace')->find($id),
            'capture' => Capture::find($id),
            default => null,
        };

        if (! $model) {
            return Response::error('No content found for that id.');
        }

        // Workspace membership first, then the same policy the app uses (project membership,
        // private bookmark groups, unpublished pages).
        $policyChecked = in_array($type, ['page', 'item', 'project', 'bookmark', 'ticket', 'plan'], true);
        $denied = match ($type) {
            'capture' => $model->user_id !== $user->id, // private to its owner (CapturePolicy)
            'task' => ! $this->userCanAccess($model, $type, $workspaceIds) || $user->cannot('view', $model->taskList),
            default => ! $this->userCanAccess($model, $type, $workspaceIds) || ($policyChecked && $user->cannot('view', $model)),
        };
        if ($denied) {
            return Response::error('You do not have access to that content.');
        }

        [$title, $text] = $this->extract($model, $type);

        return Response::structured([
            'id' => $validated['id'],
            'title' => $title ?? '',
            'text' => $text ?? '',
            'url' => $type === 'capture' ? route('captures.index') : (ResourceLocator::locate($model)['url'] ?? ''),
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
            'ticket' => $model->helpdesk?->project?->workspace_id,
            'plan' => $model->project?->workspace_id,
            'task' => $model->taskList?->project?->workspace_id,
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
            'ticket' => [$model->subject ?: $model->key, trim($model->key.' — '.strip_tags((string) $model->description)."\n\n".$model->replies->map(fn ($r) => strip_tags((string) $r->body))->implode("\n\n"))],
            'plan' => [$model->name, trim(((string) $model->description)."\n".collect($model->data['rows'] ?? [])->map(fn ($r) => '- '.($r['name'] ?? ''))->implode("\n"))],
            'task' => [$model->title, SearchText::fromJson($model->description)],
            'capture' => [mb_substr((string) strtok((string) $model->text, "\n"), 0, 160), trim($model->text."\n".($model->url ?? ''))],
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
