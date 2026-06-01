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

#[Description('Edit a Kanban card: change its title, description (Markdown), or due date/time. Identify it by board slug + item_id (from get_board). To move it between columns use move_board_item instead.')]
class UpdateBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'start_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'due_time' => 'sometimes|nullable|date_format:H:i',
            'assignee' => 'sometimes|nullable|string|max:255',
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

        $item = $board->items()->find($validated['item_id']);
        if (! $item) {
            return Response::error('No item found with that id on this board.');
        }

        try {
            $this->authorizeFor($user, 'update', $item);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $changes = [];
        if (array_key_exists('title', $validated)) {
            $changes['title'] = $validated['title'];
        }
        if (array_key_exists('description', $validated)) {
            $changes['description'] = ! empty($validated['description'])
                ? PageContentConverter::markdownToHtml($validated['description'])
                : null;
        }
        if (array_key_exists('start_date', $validated)) {
            $changes['start_date'] = $validated['start_date'];
        }
        if (array_key_exists('start_time', $validated)) {
            $changes['start_time'] = $validated['start_time'];
        }
        if (array_key_exists('due_date', $validated)) {
            $changes['due_date'] = $validated['due_date'];
        }
        if (array_key_exists('due_time', $validated)) {
            $changes['due_time'] = $validated['due_time'];
        }
        if (array_key_exists('assignee', $validated)) {
            try {
                $changes['assigned_to'] = $this->resolveAssigneeId($user, $project, $validated['assignee']);
            } catch (AuthorizationException $e) {
                return Response::error($e->getMessage());
            }
        }

        if (empty($changes)) {
            return Response::error('Provide at least one field to update (title, description, start/due dates or assignee).');
        }

        $item->update($changes);

        return Response::structured([
            'updated' => true,
            'item_key' => $item->key ?? '',
            'location' => ResourceLocator::locate($item->fresh()),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'updated' => $schema->boolean(),
            'item_key' => $schema->string(),
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
            'title' => $schema->string()->description('New title.'),
            'description' => $schema->string()->description('New card body as Markdown.'),
            'start_date' => $schema->string()->description('New start date YYYY-MM-DD (empty to clear).'),
            'start_time' => $schema->string()->description('New start time HH:MM (24h).'),
            'due_date' => $schema->string()->description('New due date YYYY-MM-DD (empty to clear).'),
            'due_time' => $schema->string()->description('New due time HH:MM (24h).'),
            'assignee' => $schema->string()->description('Reassign: "me", a member email, or empty to unassign.'),
        ];
    }
}
