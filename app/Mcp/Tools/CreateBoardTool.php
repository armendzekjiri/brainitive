<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Mcp\Tools\Concerns\SetsUpBoards;
use App\Models\Board;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create another board in a project (kanban or scrum). Pass `columns` to define its statuses in order (otherwise To Do / In Progress / Done). Returns the board slug and column ids.')]
class CreateBoardTool extends Tool
{
    use InteractsWithBrainitive;
    use SetsUpBoards;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'type' => 'nullable|in:kanban,scrum',
            'request_id' => 'required|string|max:100',
        ] + $this->columnRules());
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'create', [Board::class, $project]);

            $run = IdempotentRequest::run($user, 'create_board', $v['request_id'], collect($v)->except('request_id')->all(), function () use ($project, $user, $v) {
                $board = $this->createBoard($project, $user, $v['name'], $v['description'] ?? null, $v['type'] ?? 'kanban', $v['columns'] ?? null);

                return [
                    'board_slug' => $board->slug,
                    'columns' => $board->columns()->orderBy('position')->get()->map(fn ($c) => $this->columnSummary($c))->all(),
                    'location' => ResourceLocator::locate($board),
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
            'board_slug' => $schema->string(),
            'columns' => $schema->array()->items($this->columnSchema($schema)),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'name' => $schema->string()->description('The board name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'type' => $schema->string()->description('"kanban" (default) or "scrum".'),
            'columns' => $this->columnsArg($schema),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
