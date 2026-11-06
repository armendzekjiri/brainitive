<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ResourceLocator;
use App\Support\Work\WorkCompletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Move a board item to a different column (e.g. to mark it In Progress or Done). Provide the item id and the target column_id (both from get_board). Moving into a "done" column marks it complete (with_subtasks: its open subtasks too); watchers are notified of the new status, as on the board. The board\'s workflow rules apply (see workflow_rule in get_board): a move they refuse returns the reason and changes nothing.')]
class MoveBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'item_id' => 'required|integer',
            'column_id' => 'required|integer',
            'position' => 'nullable|integer|min:0',
            'with_subtasks' => 'nullable|boolean',
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

        $column = $board->columns()->whereKey($validated['column_id'])->first();
        if (! $column) {
            return Response::error('No column found with that id on this board.');
        }

        try {
            $this->authorizeFor($user, 'update', $item);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        // the board's drag & drop: move (+ the open subtasks into a done column) and notify the watchers;
        // a board workflow rule that refuses it answers with its reason (guard → ValidationException)
        return $this->guard(function () use ($item, $column, $validated, $user) {
            WorkCompletion::moveIssue($item, $column, (int) ($validated['position'] ?? 0), $user, (bool) ($validated['with_subtasks'] ?? false));

            return Response::structured([
                'moved' => true,
                'item_key' => $item->key,
                'column' => $column->name,
                'location' => ResourceLocator::locate($item->fresh()),
            ]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'moved' => $schema->boolean(),
            'item_key' => $schema->string(),
            'column' => $schema->string(),
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
            'board' => $schema->string()->description('The board slug.')->required(),
            'item_id' => $schema->integer()->description('The item id to move (from get_board).')->required(),
            'column_id' => $schema->integer()->description('The destination column id (from get_board).')->required(),
            'position' => $schema->integer()->description('Optional 0-based position within the column. Defaults to the top.')->default(0),
            'with_subtasks' => $schema->boolean()->description('Moving into a done column: complete the open subtasks (all levels) too, like the board\'s "complete subtasks" choice. Default false.')->default(false),
        ];
    }
}
