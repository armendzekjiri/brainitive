<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithSprints;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename a sprint or change its goal / dates. Completed sprints can\'t be edited. Pass expected_status (planned or active) so a sprint that changed meanwhile isn\'t edited by mistake.')]
class UpdateSprintTool extends Tool
{
    use InteractsWithSprints;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate($this->boardRules() + [
            'sprint_id' => 'required|integer',
            'expected_status' => 'required|in:planned,active',
            'name' => 'nullable|string|max:255',
            'goal' => 'nullable|string|max:2000',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        try {
            $board = $this->scrumBoard($request->user(), $v, 'update');
            $sprint = $this->sprintOn($board, (int) $v['sprint_id']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        if ($sprint->status !== $v['expected_status']) {
            return Response::error("Conflict: the sprint is now {$sprint->status}, not {$v['expected_status']}. Nothing was changed; read it again with get_sprint.");
        }

        $changes = array_filter(array_intersect_key($v, array_flip(['name', 'goal', 'start_date', 'end_date'])), fn ($value) => $value !== null);
        $end = $changes['end_date'] ?? $sprint->end_date?->toDateString();
        $start = $changes['start_date'] ?? $sprint->start_date?->toDateString();
        if ($start && $end && $end < $start) {
            return Response::error('end_date must be on or after start_date.');
        }
        $sprint->update($changes);

        return Response::structured(['updated' => true, 'sprint' => $this->sprintSummary($sprint->fresh()), 'backlog_url' => $this->backlogUrl($board)]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['updated' => $schema->boolean(), 'sprint' => $this->sprintSchema($schema), 'backlog_url' => $schema->string()];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return $this->boardArgs($schema) + [
            'sprint_id' => $schema->integer()->required(),
            'expected_status' => $schema->string()->enum(['planned', 'active'])->description('The status you read; the edit is refused if it changed.')->required(),
            'name' => $schema->string(),
            'goal' => $schema->string(),
            'start_date' => $schema->string()->description('YYYY-MM-DD'),
            'end_date' => $schema->string()->description('YYYY-MM-DD, not before start_date'),
        ];
    }
}
