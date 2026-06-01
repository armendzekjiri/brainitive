<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Read a board: its columns and the items in each column. Returns column ids needed by create_board_item and move_board_item, plus item ids/keys/titles. Identify the board by slug.')]
class GetBoardTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
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

        $items = $board->items()->whereNull('parent_id')
            ->orderBy('position')
            ->get(['id', 'key', 'title', 'column_id', 'assigned_to', 'due_date']);

        $columns = $board->columns()->orderBy('position')->get(['id', 'name', 'slug'])
            ->map(fn ($col) => [
                'column_id' => $col->id,
                'name' => $col->name,
                'items' => $items->where('column_id', $col->id)->map(fn ($i) => [
                    'id' => $i->id,
                    'key' => $i->key ?? '',
                    'title' => $i->title,
                    'due_date' => $i->due_date?->format('Y-m-d') ?? '',
                ])->values()->all(),
            ])
            ->all();

        return Response::structured([
            'board' => $board->name,
            'columns' => $columns,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'board' => $schema->string(),
            'columns' => $schema->array()->items($schema->object([
                'column_id' => $schema->integer(),
                'name' => $schema->string(),
                'items' => $schema->array()->items($schema->object([
                    'id' => $schema->integer(),
                    'key' => $schema->string(),
                    'title' => $schema->string(),
                    'due_date' => $schema->string(),
                ])),
            ])),
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
        ];
    }
}
