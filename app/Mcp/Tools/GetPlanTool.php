<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithPlans;
use App\Models\Item;
use App\Support\Plans\PlanIssueSync;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read a Gantt plan: rows (outline level, owner, status), their bars (dates, milestones, progress), dependencies between bars and linked board issues. Keep edit_revision — update_plan needs it.')]
class GetPlanTool extends Tool
{
    use InteractsWithPlans;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate(['workspace' => 'required|string', 'project' => 'required|string', 'plan' => 'required|string']);

        try {
            $plan = $this->planForUser($request->user(), $v['workspace'], $v['project'], $v['plan'], 'view');
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $data = $plan->data ?? ['rows' => [], 'dependencies' => []];
        $items = Item::whereIn('id', PlanIssueSync::linkedIds($data))->get(['id', 'key', 'title', 'board_id'])
            ->filter(fn (Item $i) => $request->user()->can('view', $i))->keyBy('id');

        return Response::structured([
            'name' => $plan->name,
            'description' => $plan->description ?? '',
            'edit_revision' => (int) $plan->edit_revision,
            'rows' => collect($data['rows'] ?? [])->map(fn (array $row) => [
                'id' => $row['id'],
                'name' => $row['name'] ?? '',
                'level' => (int) ($row['level'] ?? 0),
                'who' => $row['who'] ?? '',
                'status' => $row['status'] ?? '',
                'notes' => $row['notes'] ?? '',
                'linked_issue' => isset($row['itemId'], $items[$row['itemId']]) ? $items[$row['itemId']]->key.' '.$items[$row['itemId']]->title : '',
                'bars' => collect($row['bars'] ?? [])->map(fn (array $bar) => [
                    'id' => $bar['id'],
                    'start' => $bar['start'],
                    'end' => $bar['end'],
                    'type' => $bar['type'],
                    'label' => $bar['label'] ?? '',
                    'progress' => (int) ($bar['progress'] ?? 0),
                ])->values()->all(),
            ])->values()->all(),
            'dependencies' => array_values($data['dependencies'] ?? []),
            'url' => $this->planUrl($plan),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string(),
            'description' => $schema->string(),
            'edit_revision' => $schema->integer(),
            'rows' => $schema->array()->items($schema->object([
                'id' => $schema->string(), 'name' => $schema->string(), 'level' => $schema->integer(), 'who' => $schema->string(),
                'status' => $schema->string(), 'notes' => $schema->string(), 'linked_issue' => $schema->string(),
                'bars' => $schema->array()->items($schema->object([
                    'id' => $schema->string(), 'start' => $schema->string(), 'end' => $schema->string(),
                    'type' => $schema->string(), 'label' => $schema->string(), 'progress' => $schema->integer(),
                ])),
            ])),
            'dependencies' => $schema->array()->items($schema->object(['id' => $schema->string(), 'from' => $schema->string(), 'to' => $schema->string()])),
            'url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'plan' => $schema->string()->description('The plan slug (from list_plans).')->required(),
        ];
    }
}
