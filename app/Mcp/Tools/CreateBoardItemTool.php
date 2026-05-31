<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
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

        $maxPosition = Item::where('column_id', $column->id)->max('position') ?? 0;

        $item = $board->items()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'project_id' => $project->id,
            'column_id' => $column->id,
            'item_type_id' => ItemType::where('is_default', true)->first()?->id,
            'priority_id' => ItemPriority::where('is_default', true)->first()?->id,
            'key' => $project->getNextItemKey(),
            'created_by' => $user->id,
            'reporter_id' => $user->id,
            'position' => $maxPosition + 1,
        ]);

        return Response::structured([
            'created' => true,
            'item_id' => $item->id,
            'item_key' => $item->key,
            'column' => $column->name,
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
            'description' => $schema->string()->description('Optional description.'),
            'column_id' => $schema->integer()->description('Optional target column id (from get_board). Defaults to the first column.'),
        ];
    }
}
