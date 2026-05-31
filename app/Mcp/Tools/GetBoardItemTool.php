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

#[Description('Read a single Kanban card in full: title, description (as Markdown), due date/time, column, assignee, type, priority and status. Identify it by board slug + item_id (from get_board).')]
class GetBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
        ]);

        $user = $request->user();

        try {
            $project = $this->projectForUser($user, $validated['workspace'], $validated['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $board = $project->boards()->where('slug', $validated['board'])->first();
        if (! $board) {
            return Response::error('No board found with that slug in this project.');
        }

        $item = $board->items()->with(['column', 'assignee:id,name,email', 'itemType', 'priority'])
            ->find($validated['item_id']);
        if (! $item) {
            return Response::error('No item found with that id on this board.');
        }

        return Response::structured([
            'id' => $item->id,
            'key' => $item->key,
            'title' => $item->title,
            'description' => PageContentConverter::htmlToMarkdown($item->description),
            'column' => $item->column?->name,
            'start_date' => $item->start_date?->format('Y-m-d'),
            'start_time' => $item->start_time ? substr((string) $item->start_time, 0, 5) : null,
            'due_date' => $item->due_date?->format('Y-m-d'),
            'due_time' => $item->due_time ? substr((string) $item->due_time, 0, 5) : null,
            'assignee' => $item->assignee?->name,
            'type' => $item->itemType?->name,
            'priority' => $item->priority?->name,
            'completed' => $item->completed_at !== null,
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer(),
            'key' => $schema->string(),
            'title' => $schema->string(),
            'description' => $schema->string()->description('Card body as Markdown.'),
            'column' => $schema->string(),
            'start_date' => $schema->string(),
            'start_time' => $schema->string(),
            'due_date' => $schema->string(),
            'due_time' => $schema->string(),
            'assignee' => $schema->string(),
            'type' => $schema->string(),
            'priority' => $schema->string(),
            'completed' => $schema->boolean(),
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
            'board' => $schema->string()->description('The board slug.')->required(),
            'item_id' => $schema->integer()->description('The item id (from get_board).')->required(),
        ];
    }
}
