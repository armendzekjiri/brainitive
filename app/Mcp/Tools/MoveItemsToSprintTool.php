<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSprints;
use App\Support\Boards\SprintLifecycle;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Move top-level issues (with all their subtasks) into a planned or active sprint, or back to the backlog with sprint_id 0. All ids must belong to the board; nothing moves if one is invalid. Pass a unique request_id.')]
class MoveItemsToSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + [
            'sprint_id' => 'required|integer|min:0',
            'item_ids' => 'required|array|min:1|max:200',
            'item_ids.*' => 'integer',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        try {
            $board = $this->scrumBoard($user, $v, 'update');
            $sprint = $v['sprint_id'] > 0 ? $this->sprintOn($board, (int) $v['sprint_id']) : null;
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $ids = array_values(array_unique(array_map('intval', $v['item_ids'])));

        try {
            $run = IdempotentRequest::run($user, 'move_items_to_sprint', $v['request_id'], ['board' => $board->id, 'sprint' => $sprint?->id, 'items' => $ids],
                fn () => ['moved' => SprintLifecycle::moveIssues($board, $sprint, $ids)]);
        } catch (RequestIdConflict|DomainException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'moved' => $run['result']['moved'],
            'replayed' => $run['replayed'],
            'destination' => $sprint?->name ?? 'Backlog',
            'backlog_url' => $this->backlogUrl($board),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['moved' => $schema->integer(), 'replayed' => $schema->boolean(), 'destination' => $schema->string(), 'backlog_url' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'sprint_id' => $schema->integer()->description('Target sprint id, or 0 for the backlog.')->required(),
            'item_ids' => $schema->array()->items($schema->integer())->description('Top-level issue ids (subtasks follow their parent).')->required(),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
