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
#[Description('Change a board column: rename it, set its color, a WIP limit (0 or empty = none) or whether it counts as done. Pass only what changes.')]
class UpdateBoardColumnTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'column_id' => 'required|integer',
            'name' => 'sometimes|string|max:255',
            'color' => 'sometimes|nullable|string|max:20',
            'wip_limit' => 'sometimes|nullable|integer|min:0',
            'is_done' => 'sometimes|boolean',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $this->authorizeFor($user, 'update', $board);
            $column = $this->columnFor($board, (int) $v['column_id']);

            $changes = [];
            if (array_key_exists('name', $v)) {
                $changes['name'] = trim($v['name']);
            }
            if (array_key_exists('color', $v)) {
                $changes['color'] = $v['color'];
            }
            if (array_key_exists('wip_limit', $v)) {
                $changes['wip_limit'] = $v['wip_limit'] ?: null;
            }
            if (array_key_exists('is_done', $v)) {
                $changes['is_done_column'] = $v['is_done'];
            }
            if ($changes === []) {
                throw new ToolError('Provide a name, color, wip_limit or is_done to change.');
            }
            $column->update($changes);

            return Response::structured(['updated' => true, 'column' => $this->columnSummary($column->fresh())]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'column' => $this->columnSchema($schema)];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'column_id' => $schema->integer()->description('The column id (from get_board).')->required(),
            'name' => $schema->string()->description('New name.'),
            'color' => $schema->string()->description('New hex color (empty to clear).'),
            'wip_limit' => $schema->integer()->description('Work-in-progress limit; 0 removes it.'),
            'is_done' => $schema->boolean()->description('Whether cards here count as done.'),
        ];
    }
}
