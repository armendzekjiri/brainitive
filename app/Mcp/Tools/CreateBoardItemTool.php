<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a card/item on a board. If you omit column_id the item goes into the first column. Get column ids from get_board. Returns the new item key (e.g. PROJ-12) and its location.')]
class CreateBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'column_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date_format:Y-m-d',
            'due_time' => 'nullable|date_format:H:i',
            'assignee' => 'nullable|string|max:255',
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

        try {
            $this->authorizeFor($user, 'createItems', $board);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        // Resolve the target column — explicit id (must belong to the board) or the first column.
        $column = $validated['column_id'] ?? null
            ? $board->columns()->whereKey($validated['column_id'])->first()
            : $board->columns()->orderBy('position')->first();

        if (! $column) {
            return Response::error('No valid column found on this board. Call get_board to see column ids.');
        }

        try {
            $assigneeId = $this->resolveAssigneeId($user, $project, $validated['assignee'] ?? null);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $maxPosition = Item::where('column_id', $column->id)->max('position') ?? 0;

        $item = $board->items()->create([
            'title' => $validated['title'],
            // Board descriptions render through the Lexical editor as HTML, so
            // convert the assistant's Markdown to HTML (plain text would not
            // display as the card body).
            'description' => ! empty($validated['description'])
                ? PageContentConverter::markdownToHtml($validated['description'])
                : null,
            'project_id' => $project->id,
            'column_id' => $column->id,
            'item_type_id' => ItemType::where('is_default', true)->first()?->id,
            'priority_id' => ItemPriority::where('is_default', true)->first()?->id,
            'key' => $project->getNextItemKey(),
            'assigned_to' => $assigneeId,
            'start_date' => $validated['start_date'] ?? null,
            'start_time' => $validated['start_time'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'due_time' => $validated['due_time'] ?? null,
            'created_by' => $user->id,
            'reporter_id' => $user->id,
            'position' => $maxPosition + 1,
        ]);

        return Response::structured([
            'created' => true,
            'item_id' => $item->id,
            'item_key' => $item->key,
            'column' => $column->name,
            'assignee' => $item->assignee?->name,
            'due_date' => $item->due_date?->format('Y-m-d'),
            'location' => ResourceLocator::locate($item),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'item_id' => $schema->integer(),
            'item_key' => $schema->string()->description('Human key, e.g. PROJ-12.'),
            'column' => $schema->string(),
            'assignee' => $schema->string(),
            'due_date' => $schema->string(),
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
            'title' => $schema->string()->description('The card title.')->required(),
            'description' => $schema->string()->description('Optional card body as Markdown (converted to the editor format automatically).'),
            'column_id' => $schema->integer()->description('Optional target column id (from get_board). Defaults to the first column.'),
            'start_date' => $schema->string()->description('Optional start date YYYY-MM-DD.'),
            'start_time' => $schema->string()->description('Optional start time HH:MM (24h).'),
            'due_date' => $schema->string()->description('Optional due date YYYY-MM-DD.'),
            'due_time' => $schema->string()->description('Optional due time HH:MM (24h).'),
            'assignee' => $schema->string()->description('Assign the card: pass "me" for yourself, or a workspace member\'s email (see list_project_members).'),
        ];
    }
}
