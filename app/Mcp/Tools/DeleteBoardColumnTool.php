<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Delete an empty board column. A column that still has cards is refused: move them with move_board_item first. Confirm with the user before deleting.')]
class DeleteBoardColumnTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'column_id' => 'required|integer',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $this->authorizeFor($user, 'update', $board);
            $column = $board->columns()->find($v['column_id']);
            if (! $column) {
                // Already gone (a retry) — report that instead of failing.
                return Response::structured(['deleted' => false, 'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all()]);
            }
            if ($column->items()->exists()) {
                throw new ToolError("The column \"{$column->name}\" still has cards. Move or delete them first.");
            }
            $column->delete();

            return Response::structured(['deleted' => true, 'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all()]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['deleted' => $schema->boolean(), 'columns' => $schema->array()->items($this->columnSchema($schema))];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'column_id' => $schema->integer()->description('The column id (from get_board).')->required(),
        ];
    }
}
