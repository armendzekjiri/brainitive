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

#[Description('Read a board: its columns (statuses, with id, slug, color, done flag, WIP limit) and the top-level items in each column. Returns column ids needed by create_board_item and move_board_item, plus item ids/keys/titles. Like the board itself, done columns of a Kanban board with done retention list only cards completed in the last `done_retention_days` days (`hidden_done_count` = the older ones); pass include_old_done to list all. Identify the board by slug.')]
class GetBoardTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'include_old_done' => 'sometimes|boolean',
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
            ->get(['id', 'key', 'title', 'column_id', 'assigned_to', 'due_date', 'completed_at']);

        // Done retention (BRAI-346): the same rule as the Kanban board (no date = never hidden)
        $cutoff = ($validated['include_old_done'] ?? false) ? null : $board->doneCutoff();
        $isOld = fn ($item) => $cutoff !== null && $item->completed_at !== null && $item->completed_at->lessThan($cutoff);

        $columns = $board->columns()->orderBy('position')->get()
            ->map(function ($col) use ($items, $isOld) {
                $inColumn = $items->where('column_id', $col->id);
                $hidden = $col->is_done_column ? $inColumn->filter($isOld) : collect();

                return [
                    'column_id' => $col->id,
                    'name' => $col->name,
                    'slug' => $col->slug ?? '',
                    'color' => $col->color ?? '',
                    'is_done' => (bool) $col->is_done_column,
                    'wip_limit' => $col->wip_limit,
                    'hidden_done_count' => $hidden->count(),
                    'items' => $inColumn->diffKeys($hidden)->map(fn ($i) => [
                        'id' => $i->id,
                        'key' => $i->key ?? '',
                        'title' => $i->title,
                        'due_date' => $i->due_date?->format('Y-m-d') ?? '',
                    ])->values()->all(),
                ];
            })
            ->all();

        return Response::structured([
            'board' => $board->name,
            'done_retention_days' => $board->doneRetentionDays(),
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
            'done_retention_days' => $schema->integer()->description('Done cards older than this many days are left out of done columns; 0 = all are listed.'),
            'columns' => $schema->array()->items($schema->object([
                'column_id' => $schema->integer(),
                'name' => $schema->string(),
                'slug' => $schema->string(),
                'color' => $schema->string(),
                'is_done' => $schema->boolean()->description('Cards here count as done.'),
                'wip_limit' => $schema->integer()->nullable(),
                'hidden_done_count' => $schema->integer()->description('Older done cards not listed here (see done_retention_days).'),
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
            'include_old_done' => $schema->boolean()->description('Also list done cards older than the board\'s done retention. Default false.'),
        ];
    }
}
