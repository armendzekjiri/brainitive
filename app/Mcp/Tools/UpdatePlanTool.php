<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithPlans;
use App\Models\Plan;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\RequestIdConflict;
use App\Support\Plans\PlanDocument;
use App\Support\Plans\PlanIssueSync;
use App\Support\Plans\PlanOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Change a Gantt plan with a list of operations (add/update/remove row or bar, add/remove dependency), all or nothing. Pass expected_revision from get_plan: if someone edited the plan since, nothing changes and you get the current revision — read again, never retry blindly. Date edits on rows linked to issues update those issues. Pass a unique request_id.')]
class UpdatePlanTool extends Tool
{
    use InteractsWithPlans;

    private const OPS = ['add_row', 'update_row', 'remove_row', 'add_bar', 'update_bar', 'remove_bar', 'add_dependency', 'remove_dependency'];

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'plan' => 'required|string',
            'expected_revision' => 'required|integer|min:0',
            'request_id' => 'required|string|max:100',
            'operations' => 'required|array|min:1|max:'.PlanOperations::MAX_OPERATIONS,
            'operations.*.op' => 'required|in:'.implode(',', self::OPS),
            'operations.*.row_id' => 'nullable|string|max:64',
            'operations.*.after_row_id' => 'nullable|string|max:64',
            'operations.*.bar_id' => 'nullable|string|max:64',
            'operations.*.from_bar_id' => 'nullable|string|max:64',
            'operations.*.to_bar_id' => 'nullable|string|max:64',
            'operations.*.dependency_id' => 'nullable|string|max:64',
            'operations.*.name' => 'nullable|string|max:500',
            'operations.*.level' => 'nullable|integer|min:0|max:'.PlanDocument::MAX_LEVEL,
            'operations.*.who' => 'nullable|string|max:255',
            'operations.*.status' => 'nullable|in:'.implode(',', PlanDocument::STATUSES),
            'operations.*.notes' => 'nullable|string|max:5000',
            'operations.*.start' => 'nullable|date_format:Y-m-d',
            'operations.*.end' => 'nullable|date_format:Y-m-d',
            'operations.*.type' => 'nullable|in:bar,milestone',
            'operations.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'operations.*.label' => 'nullable|string|max:255',
            'operations.*.progress' => 'nullable|integer|min:0|max:100',
        ]);
        $user = $request->user();

        try {
            $plan = $this->planForUser($user, $v['workspace'], $v['project'], $v['plan'], 'update');
            $run = IdempotentRequest::run($user, 'update_plan', $v['request_id'], ['plan' => $plan->id, 'revision' => (int) $v['expected_revision'], 'ops' => $v['operations']], function () use ($plan, $v, $user) {
                $plan = Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                if ((int) $plan->edit_revision !== (int) $v['expected_revision']) {
                    throw new InvalidArgumentException("Conflict: the plan is at revision {$plan->edit_revision}, not {$v['expected_revision']} — someone edited it. Nothing was changed; call get_plan and try again on the new revision.");
                }

                $applied = PlanOperations::apply($plan->data ?? Plan::emptyData(), $v['operations']);

                $check = Validator::make(['data' => $applied['data']], array_intersect_key(PlanDocument::rules(), array_flip(array_filter(array_keys(PlanDocument::rules()), fn ($k) => str_starts_with($k, 'data')))));
                if ($check->fails()) {
                    throw new InvalidArgumentException('The resulting plan is invalid: '.$check->errors()->first());
                }

                $data = PlanIssueSync::restrictToProject($plan->project, PlanDocument::normalize($applied['data']));
                $linked = PlanIssueSync::writeThrough($plan->data, $data, $user);
                $plan->saveDocument($user, $data, PlanDocument::normalizeSettings($plan->settings));

                return ['edit_revision' => (int) $plan->edit_revision, 'touched_rows' => $applied['touched_rows'], 'changed_linked_issues' => $linked];
            });
        } catch (AuthorizationException|RequestIdConflict|InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured($run['result'] + ['applied' => ! $run['replayed'], 'url' => $this->planUrl($plan)]);
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'applied' => $schema->boolean()->description('False when this request_id was already applied.'),
            'edit_revision' => $schema->integer(),
            'touched_rows' => $schema->array()->items($schema->string()),
            'changed_linked_issues' => $schema->integer(),
            'url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'plan' => $schema->string()->description('The plan slug.')->required(),
            'expected_revision' => $schema->integer()->description('edit_revision from get_plan.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
            'operations' => $schema->array()->items($schema->object([
                'op' => $schema->string()->enum(self::OPS)->required(),
                'row_id' => $schema->string()->description('Row to update/remove/add bars to; optional id for add_row.'),
                'after_row_id' => $schema->string()->description('add_row: insert after this row (default: at the end).'),
                'bar_id' => $schema->string()->description('Bar to update/remove; optional id for add_bar.'),
                'from_bar_id' => $schema->string()->description('Dependency: this bar must finish…'),
                'to_bar_id' => $schema->string()->description('…before this bar.'),
                'dependency_id' => $schema->string(),
                'name' => $schema->string(),
                'level' => $schema->integer()->description('Outline indent (0 = top).'),
                'who' => $schema->string(),
                'status' => $schema->string()->enum(PlanDocument::STATUSES),
                'notes' => $schema->string(),
                'start' => $schema->string()->description('YYYY-MM-DD'),
                'end' => $schema->string()->description('YYYY-MM-DD'),
                'type' => $schema->string()->enum(['bar', 'milestone']),
                'color' => $schema->string()->description('#RRGGBB'),
                'label' => $schema->string(),
                'progress' => $schema->integer()->description('0–100'),
            ]))->description('Applied in order; any invalid operation cancels all of them.')->required(),
        ];
    }
}
