<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Live\BoardLive;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a column (status) to a board. It goes at the end unless you pass `after_column_id` (0 = first). Board editors only. Returns the column id and the board\'s columns in order.')]
class CreateBoardColumnTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'name' => 'required|string|max:255',
            'color' => 'nullable|string|max:20',
            'wip_limit' => 'nullable|integer|min:0',
            'is_done' => 'nullable|boolean',
            'after_column_id' => 'nullable|integer|min:0',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $this->authorizeFor($user, 'update', $board);

            $run = IdempotentRequest::run($user, 'create_board_column', $v['request_id'], ['board' => $board->id] + collect($v)->except(['request_id', 'workspace', 'project', 'board'])->all(), function () use ($board, $v) {
                $columns = $board->columns()->orderBy('position')->get();
                if ($columns->first(fn ($c) => $this->sameName($c->name, $v['name']))) {
                    throw new ToolError("This board already has a column named \"{$v['name']}\".");
                }

                // Slugs are unique per board; two "Review" columns or emoji-only names must not 500.
                $base = Str::slug($v['name']) ?: 'column';
                $slug = $base;
                for ($n = 2; $columns->contains('slug', $slug); $n++) {
                    $slug = "{$base}-{$n}";
                }

                $after = $v['after_column_id'] ?? null;
                $ordered = $columns->pluck('id')->all();
                $column = $board->columns()->create([
                    'name' => trim($v['name']),
                    'slug' => $slug,
                    'color' => $v['color'] ?? null,
                    'wip_limit' => $v['wip_limit'] ?? null,
                    'is_done_column' => (bool) ($v['is_done'] ?? false),
                    'position' => (int) ($columns->max('position') ?? -1) + 1,
                ]);

                if ($after !== null) {
                    $index = $after === 0 ? 0 : array_search($after, $ordered, true);
                    if ($index === false) {
                        throw new ToolError('after_column_id is not a column of this board. Call get_board to see column ids.');
                    }
                    array_splice($ordered, $after === 0 ? 0 : $index + 1, 0, [$column->id]);
                    foreach ($ordered as $position => $id) {
                        $board->columns()->whereKey($id)->update(['position' => $position]);
                    }
                    BoardLive::columnsReordered($board->id, $ordered);
                }

                return [
                    'column_id' => $column->id,
                    'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all(),
                ];
            });

            return Response::structured(['created' => ! $run['replayed']] + $run['result']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'column_id' => $schema->integer(),
            'columns' => $schema->array()->items($this->columnSchema($schema)),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'name' => $schema->string()->description('The column (status) name.')->required(),
            'color' => $schema->string()->description('Optional hex color.'),
            'wip_limit' => $schema->integer()->description('Optional work-in-progress limit.'),
            'is_done' => $schema->boolean()->description('Cards in this column count as done.'),
            'after_column_id' => $schema->integer()->description('Place it after this column id; 0 = first. Default: last.'),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
