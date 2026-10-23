<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithPlans;
use App\Models\Plan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the Gantt plans of a project with their current revision and date range.')]
class ListPlansTool extends Tool
{
    use InteractsWithPlans;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate(['workspace' => 'required|string', 'project' => 'required|string']);

        try {
            $project = $this->projectForUser($request->user(), $v['workspace'], $v['project']);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        }

        $plans = $project->plans()->orderBy('position')->get()->filter(fn (Plan $p) => $request->user()->can('view', $p));

        return Response::structured([
            'plans' => $plans->map(fn (Plan $p) => [
                'slug' => $p->slug,
                'name' => $p->name,
                'edit_revision' => (int) $p->edit_revision,
                'rows' => count($p->data['rows'] ?? []),
                ...$this->planRange($p),
                'url' => $this->planUrl($p),
            ])->values()->all(),
        ]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return ['plans' => $schema->array()->items($schema->object([
            'slug' => $schema->string(), 'name' => $schema->string(), 'edit_revision' => $schema->integer(),
            'rows' => $schema->integer(), 'start' => $schema->string(), 'end' => $schema->string(), 'url' => $schema->string(),
        ]))];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
        ];
    }
}
