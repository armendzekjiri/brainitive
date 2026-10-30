<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Item;
use App\Support\Boards\ProjectIssues;
use App\Support\Mcp\PageContentConverter;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a card/item on a board. Pass `column` (name, slug or id) to choose its status, else it goes into the first column. Type, priority and labels are given by name (see get_project_config), custom fields as {name: value}. Returns the new item key (e.g. PROJ-12) and its location. For many cards at once use create_board_items.')]
class CreateBoardItemTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'board' => 'required|string',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'column_id' => 'nullable|integer',
            'column' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:100',
            'priority' => 'nullable|string|max:50',
            'labels' => 'nullable|array|max:50',
            'labels.*' => 'string|max:50',
            'start_date' => 'nullable|date_format:Y-m-d',
            'start_time' => 'nullable|date_format:H:i',
            'due_date' => 'nullable|date_format:Y-m-d',
            'due_time' => 'nullable|date_format:H:i',
            'assignee' => 'nullable|string|max:255',
            'story_points' => 'nullable|integer|min:0|max:1000',
            'parent_id' => 'nullable|integer',
            'fields' => 'nullable|array|max:50',
        ]);

        $user = $request->user();

        return $this->guard(function () use ($user, $validated) {
            $board = $this->boardForUser($user, $validated['workspace'], $validated['project'], $validated['board']);
            $project = $board->project;
            $this->authorizeFor($user, 'createItems', $board);

            $column = $this->columnFor($board, $validated['column_id'] ?? $validated['column'] ?? null);

            $parentId = null;
            if (! empty($validated['parent_id'])) {
                $parent = $board->items()->find($validated['parent_id']);
                if (! $parent) {
                    throw new ToolError('No card with that parent_id on this board. Call get_board to see item ids.');
                }
                if ($parent->depth() >= Item::MAX_DEPTH) {
                    throw new ToolError("{$parent->key} is already at the deepest level; cards nest 3 levels at most.");
                }
                $parentId = $parent->id;
            }

            $item = ProjectIssues::create($board, $project, $user, [[
                'title' => $validated['title'],
                // Board descriptions render through the Lexical editor as HTML, so
                // convert the assistant's Markdown to HTML (plain text would not
                // display as the card body).
                'description' => ! empty($validated['description']) ? PageContentConverter::markdownToHtml($validated['description']) : null,
                'item_type_id' => $this->itemTypeIdFor($project, $validated['type'] ?? null),
                'priority_id' => $this->priorityIdFor($project, $validated['priority'] ?? null),
                'label_ids' => $this->labelIdsFor($project, $validated['labels'] ?? null),
                'assigned_to' => $this->resolveAssigneeId($user, $project, $validated['assignee'] ?? null),
                'start_date' => $validated['start_date'] ?? null,
                'start_time' => $validated['start_time'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'due_time' => $validated['due_time'] ?? null,
                'story_points' => $validated['story_points'] ?? null,
                'parent_id' => $parentId,
                'fields' => $this->fieldValuesFor($board, $validated['fields'] ?? null),
            ]], $column)->first();

            $item = Item::with('assignee')->find($item->id);

            return Response::structured([
                'created' => true,
                'item_id' => $item->id,
                'item_key' => $item->key ?? '',
                'column' => $column->name,
                'assignee' => $item->assignee?->name ?? '',
                'due_date' => $item->due_date?->format('Y-m-d') ?? '',
                'location' => ResourceLocator::locate($item),
            ]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'item_id' => $schema->integer(),
            'item_key' => $schema->string()->description('Human key, e.g. PROJ-12.'),
            'column' => $schema->string(),
            'assignee' => $schema->string(),
            'due_date' => $schema->string(),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'board' => $schema->string()->description('The board slug.')->required(),
            'title' => $schema->string()->description('The card title.')->required(),
            'description' => $schema->string()->description('Optional card body as Markdown (converted to the editor format automatically).'),
            'column' => $schema->string()->description('Column (status) name, slug or id. Defaults to the first column.'),
            'column_id' => $schema->integer()->description('Column id (from get_board); same as passing the id in `column`.'),
            'type' => $schema->string()->description('Item type name or slug, e.g. "Bug" (see get_project_config).'),
            'priority' => $schema->string()->description('Priority name or slug, e.g. "High".'),
            'labels' => $schema->array()->items($schema->string())->description('Label names (must exist; see create_label).'),
            'start_date' => $schema->string()->description('Optional start date YYYY-MM-DD.'),
            'start_time' => $schema->string()->description('Optional start time HH:MM (24h).'),
            'due_date' => $schema->string()->description('Optional due date YYYY-MM-DD.'),
            'due_time' => $schema->string()->description('Optional due time HH:MM (24h).'),
            'assignee' => $schema->string()->description('Assign the card: pass "me" for yourself, or a workspace member\'s email (see list_project_members).'),
            'story_points' => $schema->integer()->description('Optional story points.'),
            'parent_id' => $schema->integer()->description('Make it a subtask of this card (id from get_board).'),
            'fields' => $schema->object([])->description('Custom field values keyed by field name or id; select options by label; null clears.'),
        ];
    }
}
