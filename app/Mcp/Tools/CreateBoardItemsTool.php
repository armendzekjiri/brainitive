<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Item;
use App\Support\Boards\ProjectIssues;
use App\Support\Entitlements\LimitGate;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ToolError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create many cards on a board in one call (up to 100) — the tool for imports and migrations. Each card can set its column (status), type, priority, labels, assignee, dates, story points, custom fields and a parent (by Brainitive key, or by the external_key of an earlier card in the same batch). Names are resolved against get_project_config; an unknown name fails the whole batch with the valid values, nothing is created. external_key is echoed back so you can map source ids to the new keys. Confirm the mapping with the user before importing.')]
class CreateBoardItemsTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'items' => 'required|array|min:1|max:100',
            'items.*.title' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:50000',
            'items.*.column' => 'nullable|string|max:255',
            'items.*.type' => 'nullable|string|max:100',
            'items.*.priority' => 'nullable|string|max:50',
            'items.*.labels' => 'nullable|array|max:50',
            'items.*.labels.*' => 'string|max:50',
            'items.*.assignee' => 'nullable|string|max:255',
            'items.*.start_date' => 'nullable|date_format:Y-m-d',
            'items.*.due_date' => 'nullable|date_format:Y-m-d',
            'items.*.story_points' => 'nullable|integer|min:0|max:1000',
            'items.*.parent_key' => 'nullable|string|max:50',
            'items.*.parent_external_key' => 'nullable|string|max:255',
            'items.*.external_key' => 'nullable|string|max:255',
            'items.*.fields' => 'nullable|array|max:50',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $board = $this->boardForUser($user, $v['workspace'], $v['project'], $v['board']);
            $project = $board->project;
            $this->authorizeFor($user, 'createItems', $board);
            app(LimitGate::class)->ensureContentRoom($project->workspace);

            $run = IdempotentRequest::run($user, 'create_board_items', $v['request_id'], ['board' => $board->id, 'items' => $v['items']], function () use ($user, $board, $project, $v) {
                // Resolve every name first so an invalid card means nothing gets created.
                $issues = [];
                $seenExternal = [];
                foreach (array_values($v['items']) as $i => $item) {
                    $n = $i + 1;
                    try {
                        // A parent named by external_key must come earlier in the batch, so its own
                        // depth is final when the child is attached.
                        $ref = (string) ($item['parent_external_key'] ?? '');
                        if ($ref !== '' && ! isset($seenExternal[$ref])) {
                            throw new ToolError("no earlier card in this batch has external_key \"{$ref}\" (parents must come before their subtasks).");
                        }
                        if (($item['external_key'] ?? '') !== '') {
                            $seenExternal[(string) $item['external_key']] = true;
                        }

                        $parentId = null;
                        if (! empty($item['parent_key'])) {
                            $parent = $board->items()->where('key', strtoupper(trim($item['parent_key'])))->first();
                            if (! $parent) {
                                throw new ToolError("No card {$item['parent_key']} on this board.");
                            }
                            if ($parent->depth() >= Item::MAX_DEPTH) {
                                throw new ToolError("{$parent->key} is already at the deepest level; cards nest 3 levels at most.");
                            }
                            $parentId = $parent->id;
                        }

                        $issues[] = [
                            'title' => $item['title'],
                            'description' => ! empty($item['description']) ? PageContentConverter::markdownToHtml($item['description']) : null,
                            'column_id' => $this->columnFor($board, $item['column'] ?? null)->id,
                            'item_type_id' => $this->itemTypeIdFor($project, $item['type'] ?? null),
                            'priority_id' => $this->priorityIdFor($project, $item['priority'] ?? null),
                            'label_ids' => $this->labelIdsFor($project, $item['labels'] ?? null),
                            'assigned_to' => $this->resolveAssigneeId($user, $project, $item['assignee'] ?? null),
                            'start_date' => $item['start_date'] ?? null,
                            'due_date' => $item['due_date'] ?? null,
                            'story_points' => $item['story_points'] ?? null,
                            'parent_id' => $parentId,
                            'fields' => $this->fieldValuesFor($board, $item['fields'] ?? null),
                            'external_key' => (string) ($item['external_key'] ?? ''),
                            'parent_external_key' => (string) ($item['parent_external_key'] ?? ''),
                        ];
                    } catch (ToolError|AuthorizationException $e) {
                        throw new ToolError("Card {$n} (\"{$item['title']}\"): ".$e->getMessage().' Nothing was created.');
                    }
                }

                $created = ProjectIssues::create($board, $project, $user, $issues);

                // Parents named by the external key of a card in this batch: a second pass, now that ids exist.
                $byExternal = [];
                foreach ($created as $i => $item) {
                    if ($issues[$i]['external_key'] !== '') {
                        $byExternal[$issues[$i]['external_key']] = $item;
                    }
                }
                foreach ($created as $i => $item) {
                    $ref = $issues[$i]['parent_external_key'];
                    if ($ref === '' || $issues[$i]['parent_id'] !== null) {
                        continue;
                    }
                    $parent = $byExternal[$ref] ?? null;
                    if (! $parent || $parent->id === $item->id) {
                        throw new ToolError('Card '.($i + 1)." (\"{$item->title}\"): no card in this batch has external_key \"{$ref}\". Nothing was created.");
                    }
                    $parent = $parent->fresh();
                    if ($parent->depth() >= Item::MAX_DEPTH) {
                        throw new ToolError('Card '.($i + 1)." (\"{$item->title}\"): \"{$ref}\" is already at the deepest level; cards nest 3 levels at most. Nothing was created.");
                    }
                    // Brand-new row: set parent + sprint without a history line per subtask.
                    Item::whereKey($item->id)->update(['parent_id' => $parent->id, 'sprint_id' => $parent->sprint_id]);
                }

                return [
                    'count' => $created->count(),
                    'items' => $created->values()->map(fn (Item $item, int $i) => [
                        'external_key' => $issues[$i]['external_key'],
                        'item_id' => $item->id,
                        'item_key' => $item->key ?? '',
                        'title' => $item->title,
                        'url' => route('boards.items.show', [$project->workspace->slug, $project->slug, $board->slug, $item->id]),
                    ])->all(),
                    'board_url' => route('boards.show', [$project->workspace->slug, $project->slug, $board->slug]),
                ];
            });

            return Response::structured(['created' => ! $run['replayed']] + $run['result']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean()->description('false when this request_id was already applied (the stored result is returned).'),
            'count' => $schema->integer(),
            'items' => $schema->array()->items($schema->object([
                'external_key' => $schema->string(),
                'item_id' => $schema->integer(),
                'item_key' => $schema->string(),
                'title' => $schema->string(),
                'url' => $schema->string(),
            ])),
            'board_url' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'items' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'description' => $schema->string()->description('Markdown body.'),
                'column' => $schema->string()->description('Column (status) name, slug or id. Default: the first column.'),
                'type' => $schema->string()->description('Item type name or slug (see get_project_config).'),
                'priority' => $schema->string()->description('Priority name or slug.'),
                'labels' => $schema->array()->items($schema->string())->description('Label names; create them first with create_label.'),
                'assignee' => $schema->string()->description('"me" or a member email.'),
                'start_date' => $schema->string()->description('YYYY-MM-DD.'),
                'due_date' => $schema->string()->description('YYYY-MM-DD.'),
                'story_points' => $schema->integer(),
                'parent_key' => $schema->string()->description('Make it a subtask of an existing card, by its key (e.g. SHOP-12).'),
                'parent_external_key' => $schema->string()->description('Make it a subtask of a card earlier in this batch, by that card\'s external_key.'),
                'external_key' => $schema->string()->description('Your own id for the card (e.g. the Jira key); echoed back in the result.'),
                'fields' => $schema->object([])->description('Custom field values keyed by field name or id: {"Customer": "ACME", "Go-live": "2026-12-01", "Environment": "Prod"}. Select options by label.'),
            ]))->description('Up to 100 cards, in order.')->required(),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
