<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Live\BoardLive;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Put a board\'s columns in a new order: pass every column id in the order wanted (left to right).')]
class ReorderBoardColumnsTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'column_ids' => 'required|array|min:1|max:100',
            'column_ids.*' => 'integer',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $this->authorizeFor($user, 'update', $board);

            $existing = $board->columns()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $given = collect($v['column_ids'])->map(fn ($id) => (int) $id)->unique()->values();
            if ($given->sort()->values()->all() !== $existing) {
                throw new ToolError('column_ids must contain every column of this board exactly once. Call get_board to see the column ids.');
            }

            foreach ($given as $position => $id) {
                $board->columns()->whereKey($id)->update(['position' => $position]);
            }
            BoardLive::columnsReordered($board->id, $given->all());

            return Response::structured(['reordered' => true, 'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all()]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['reordered' => $schema->boolean(), 'columns' => $schema->array()->items($this->columnSchema($schema))];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'column_ids' => $schema->array()->items($schema->integer())->description('All column ids, in the new order.')->required(),
        ];
    }
}
