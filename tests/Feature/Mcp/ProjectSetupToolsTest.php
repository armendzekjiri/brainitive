<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\AddProjectMemberTool;
use App\Mcp\Tools\CaptureToInboxTool;
use App\Mcp\Tools\CreateBoardColumnTool;
use App\Mcp\Tools\CreateBoardItemsTool;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\CreateBoardTool;
use App\Mcp\Tools\CreateItemFieldTool;
use App\Mcp\Tools\CreateItemTypeTool;
use App\Mcp\Tools\CreateLabelTool;
use App\Mcp\Tools\CreatePriorityTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateTaskListTool;
use App\Mcp\Tools\CreateWorkspaceTool;
use App\Mcp\Tools\DeleteBoardColumnTool;
use App\Mcp\Tools\DeleteLabelTool;
use App\Mcp\Tools\GetProjectConfigTool;
use App\Mcp\Tools\ReorderBoardColumnsTool;
use App\Mcp\Tools\UpdateBoardColumnTool;
use App\Mcp\Tools\UpdateBoardItemTool;
use App\Mcp\Tools\UpdateBoardTool;
use App\Mcp\Tools\UpdateItemFieldTool;
use App\Mcp\Tools\UpdateItemTypeTool;
use App\Mcp\Tools\UpdateLabelTool;
use App\Mcp\Tools\UpdatePriorityTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Models\Capture;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * The "set up a project from an export" flow of v1.47.0: structure first (project, columns,
 * labels, types, priorities, fields, members), then cards in bulk with everything resolved by
 * name, and get_project_config describing the result.
 */
class ProjectSetupToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->admin = User::factory()->create();
        $this->workspace->addUser($this->admin, 'admin');
    }

    private function tool(string $tool, array $args, ?User $as = null)
    {
        return BrainitiveServer::actingAs($as ?? $this->admin)->tool($tool, $args);
    }

    private function structured($response): array
    {
        $data = null;
        $response->assertStructuredContent(function (AssertableJson $j) use (&$data) {
            $data = $j->toArray();
            $j->etc();
        });

        return $data;
    }

    public function test_setting_up_a_project_from_scratch_and_importing_cards(): void
    {
        // 1. Workspace + project with its own statuses. A retry with the same request_id is a no-op.
        $ws = $this->structured($this->tool(CreateWorkspaceTool::class, ['name' => 'Acme Migration', 'request_id' => 'w1']));
        $this->assertTrue($ws['created']);
        $wsSlug = $ws['workspace_slug'];
        $this->assertTrue(Workspace::where('slug', $wsSlug)->first()->users()->where('users.id', $this->admin->id)->exists());

        $projectArgs = [
            'workspace' => $wsSlug, 'name' => 'Shop', 'key' => 'shop', 'board_type' => 'scrum', 'request_id' => 'p1',
            'columns' => [['name' => 'Backlog'], ['name' => 'Selected'], ['name' => 'In Progress', 'wip_limit' => 3], ['name' => 'Review'], ['name' => 'Done', 'is_done' => true]],
        ];
        $p = $this->structured($this->tool(CreateProjectTool::class, $projectArgs));
        $this->assertTrue($p['created']);
        $this->assertSame('SHOP', $p['key']);
        $this->assertSame(['Backlog', 'Selected', 'In Progress', 'Review', 'Done'], array_column($p['columns'], 'name'));
        $this->assertTrue(end($p['columns'])['is_done']);
        $this->assertSame(3, $p['columns'][2]['wip_limit']);
        $replay = $this->structured($this->tool(CreateProjectTool::class, $projectArgs));
        $this->assertFalse($replay['created']);
        $this->assertSame(1, Project::where('key', 'SHOP')->count());
        $this->tool(CreateProjectTool::class, ['request_id' => 'p2'] + $projectArgs)->assertHasErrors(['already exists']);

        $args = ['workspace' => $wsSlug, 'project' => $p['project_slug']];
        $board = $args + ['board' => $p['board_slug']];

        // 2. Labels, types, priorities, fields, members — each safe to repeat by name.
        $this->assertTrue($this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'checkout', 'color' => '#ef4444']))['created']);
        $this->assertFalse($this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'Checkout']))['created']);
        $this->tool(CreateLabelTool::class, $args + ['name' => 'payments']);
        $epic = $this->structured($this->tool(CreateItemTypeTool::class, $args + ['name' => 'Spike', 'color' => '#8b5cf6']));
        $this->assertTrue($epic['created']);
        $this->assertFalse($this->structured($this->tool(CreateItemTypeTool::class, $args + ['name' => 'spike']))['created']);
        $this->assertSame('instance', $this->structured($this->tool(CreateItemTypeTool::class, $args + ['name' => 'Epic']))['scope'], 'Epic is a built-in type');
        $this->tool(CreateItemTypeTool::class, $args + ['name' => 'Bug']);
        $this->assertTrue($this->structured($this->tool(CreatePriorityTool::class, $args + ['name' => 'Blocker', 'order' => 0]))['created']);
        $this->assertFalse($this->structured($this->tool(CreatePriorityTool::class, $args + ['name' => 'blocker']))['created']);

        $env = $this->structured($this->tool(CreateItemFieldTool::class, $args + ['name' => 'Environment', 'type' => 'select', 'options' => [['label' => 'Prod'], ['label' => 'Staging']]]));
        $this->assertTrue($env['created']);
        $this->assertSame(['Prod', 'Staging'], array_column($env['options'], 'label'));
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Customer', 'type' => 'text']);
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Go-live', 'type' => 'date']);
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Budget', 'type' => 'number']);
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Needs QA', 'type' => 'checkbox']);
        $this->assertFalse($this->structured($this->tool(CreateItemFieldTool::class, $args + ['name' => 'customer', 'type' => 'text']))['created']);
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Empty select', 'type' => 'select'])->assertHasErrors(['at least one option']);

        $this->tool(AddProjectMemberTool::class, $args + ['email' => $this->editor->email, 'role' => 'editor'])->assertHasErrors(['No workspace member']);
        Workspace::where('slug', $wsSlug)->first()->addUser($this->editor, 'member');
        $member = $this->structured($this->tool(AddProjectMemberTool::class, $args + ['email' => $this->editor->email, 'role' => 'editor']));
        $this->assertTrue($member['added']);
        $this->assertFalse($this->structured($this->tool(AddProjectMemberTool::class, $args + ['email' => $this->editor->email, 'role' => 'editor']))['added']);
        $this->tool(AddProjectMemberTool::class, $args + ['email' => 'nobody@example.com', 'role' => 'viewer'])->assertHasErrors(['No workspace member']);

        $list = $this->structured($this->tool(CreateTaskListTool::class, $args + ['name' => 'Launch checklist', 'request_id' => 'l1']));
        $this->assertSame('launch-checklist', $list['task_list_slug']);

        // 3. Cards in bulk: statuses, types, priorities, labels, fields, assignees and parents by name.
        $items = [
            ['title' => 'Checkout redesign', 'type' => 'Epic', 'column' => 'Selected', 'external_key' => 'ACME-1', 'labels' => ['checkout'], 'fields' => ['Customer' => 'ACME', 'Budget' => 12000.5, 'Go-live' => '2026-12-01']],
            ['title' => 'New cart page', 'column' => 'In Progress', 'parent_external_key' => 'ACME-1', 'external_key' => 'ACME-2', 'assignee' => $this->editor->email, 'story_points' => 5, 'description' => 'Uses **Stripe**.'],
            ['title' => 'Apple Pay fails on Safari', 'type' => 'Bug', 'priority' => 'Blocker', 'column' => 'review', 'labels' => ['checkout', 'payments'], 'fields' => ['Environment' => 'Prod', 'Needs QA' => true], 'due_date' => '2026-11-15', 'external_key' => 'ACME-3'],
        ];
        $bulk = $this->structured($this->tool(CreateBoardItemsTool::class, $board + ['items' => $items, 'request_id' => 'b1']));
        $this->assertTrue($bulk['created']);
        $this->assertSame(3, $bulk['count']);
        $this->assertSame(['ACME-1', 'ACME-2', 'ACME-3'], array_column($bulk['items'], 'external_key'));
        $this->assertSame(['SHOP-1', 'SHOP-2', 'SHOP-3'], array_column($bulk['items'], 'item_key'));

        $project = Project::where('key', 'SHOP')->first();
        $epicItem = Item::where('key', 'SHOP-1')->first();
        $cart = Item::where('key', 'SHOP-2')->first();
        $bug = Item::where('key', 'SHOP-3')->first();
        $this->assertSame('Selected', $epicItem->column->name);
        $this->assertSame('Epic', $epicItem->itemType->name);
        $this->assertSame($epicItem->id, $cart->parent_id);
        $this->assertSame($this->editor->id, $cart->assigned_to);
        $this->assertSame(5, $cart->story_points);
        $this->assertStringContainsString('<strong>Stripe</strong>', $cart->description);
        $this->assertSame('Review', $bug->column->name);
        $this->assertSame('Blocker', $bug->priority->name);
        $this->assertEqualsCanonicalizing(['checkout', 'payments'], $bug->labels->pluck('name')->all());
        $this->assertSame('2026-11-15', $bug->due_date->toDateString());

        $values = fn (Item $item) => $item->fieldValues()->with('field')->get()->mapWithKeys(fn ($v) => [$v->field->name => $v]);
        $this->assertSame('ACME', $values($epicItem)['Customer']->value_text);
        $this->assertEquals(12000.5, (float) $values($epicItem)['Budget']->value_number);
        $this->assertSame('2026-12-01', $values($epicItem)['Go-live']->value_date->toDateString());
        $this->assertSame('Prod', $values($bug)['Environment']->option->label);
        $this->assertTrue($values($bug)['Needs QA']->value_bool);

        // A timeout retry returns the stored result; nothing is duplicated.
        $again = $this->structured($this->tool(CreateBoardItemsTool::class, $board + ['items' => $items, 'request_id' => 'b1']));
        $this->assertFalse($again['created']);
        $this->assertSame(3, $project->boards()->first()->items()->count());

        // One bad name fails the whole batch with the valid values, and nothing is created.
        $bad = [['title' => 'ok'], ['title' => 'bad label', 'labels' => ['nope']]];
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => $bad, 'request_id' => 'b2'])->assertHasErrors(['Card 2', 'No label "nope"', 'checkout, payments', 'Nothing was created']);
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'x', 'fields' => ['Environment' => 'QA']]], 'request_id' => 'b3'])->assertHasErrors(['No option "QA"', 'Prod, Staging']);
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'x', 'column' => 'Nowhere']], 'request_id' => 'b4'])->assertHasErrors(['No column "Nowhere"']);
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'x', 'parent_external_key' => 'missing']], 'request_id' => 'b5'])->assertHasErrors(['external_key "missing"']);
        $this->assertSame(3, $project->boards()->first()->items()->count());

        // A sub-subtask via parent_key; a fourth level is refused.
        $sub = $this->structured($this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'Cart tests', 'parent_key' => 'shop-2']], 'request_id' => 'b6']));
        $this->assertSame($cart->id, Item::find($sub['items'][0]['item_id'])->parent_id);
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'Too deep', 'parent_key' => $sub['items'][0]['item_key']]], 'request_id' => 'b7'])->assertHasErrors(['deepest level']);

        // 4. get_project_config describes the whole setup.
        $config = $this->structured($this->tool(GetProjectConfigTool::class, $args));
        $this->assertSame('SHOP', $config['project']['key']);
        $this->assertSame('scrum', $config['boards'][0]['type']);
        $this->assertSame(['Backlog', 'Selected', 'In Progress', 'Review', 'Done'], array_column($config['boards'][0]['columns'], 'name'));
        $this->assertEqualsCanonicalizing(['checkout', 'payments'], array_column($config['labels'], 'name'));
        $this->assertContains('Epic', array_column($config['item_types'], 'name'));
        $this->assertSame('project', collect($config['item_types'])->firstWhere('name', 'Spike')['scope']);
        $this->assertContains('Blocker', array_column($config['priorities'], 'name'));
        $this->assertEqualsCanonicalizing(['Environment', 'Customer', 'Go-live', 'Budget', 'Needs QA'], array_column($config['fields'], 'name'));
        $this->assertSame(['Prod', 'Staging'], array_column(collect($config['fields'])->firstWhere('name', 'Environment')['options'], 'label'));
        $this->assertSame('editor', collect($config['members'])->firstWhere('email', $this->editor->email)['role']);

        // The editor (not a project admin) can create cards but not change the setup.
        $this->tool(CreateLabelTool::class, $args + ['name' => 'mine'], $this->editor)->assertHasErrors();
        $this->tool(CreateItemFieldTool::class, $args + ['name' => 'Mine', 'type' => 'text'], $this->editor)->assertHasErrors();
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'by editor']], 'request_id' => 'e1'], $this->editor)->assertOk();
        $this->tool(GetProjectConfigTool::class, $args, $this->outsider)->assertHasErrors(['do not have access']);
    }

    public function test_single_card_tools_resolve_names_and_update_everything(): void
    {
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug];
        $board = $args + ['board' => $this->board->slug];
        $this->tool(CreateLabelTool::class, $args + ['name' => 'backend']);
        $this->tool(CreateLabelTool::class, $args + ['name' => 'urgent']);
        $this->tool(CreateItemTypeTool::class, $args + ['name' => 'Bug']);
        $this->tool(CreatePriorityTool::class, $args + ['name' => 'P0']);
        $field = $this->structured($this->tool(CreateItemFieldTool::class, $args + ['name' => 'Customer', 'type' => 'text']));

        $created = $this->structured($this->tool(CreateBoardItemTool::class, $board + [
            'title' => 'Crash on save', 'column' => 'In Progress', 'type' => 'bug', 'priority' => 'p0', 'labels' => ['backend'],
            'fields' => [$field['field_id'] => 'Initech'], 'story_points' => 3, 'assignee' => 'me',
        ], $this->editor));
        $item = Item::find($created['item_id']);
        $this->assertSame('In Progress', $created['column']);
        $this->assertSame('Bug', $item->itemType->name);
        $this->assertSame('P0', $item->priority->name);
        $this->assertSame(['backend'], $item->labels->pluck('name')->all());
        $this->assertSame('Initech', $item->fieldValues()->first()->value_text);
        $this->assertSame($this->editor->id, $item->assigned_to);

        $this->tool(CreateBoardItemTool::class, $board + ['title' => 'x', 'type' => 'Feature request'], $this->editor)->assertHasErrors(['No item type "Feature request"']);

        $child = $this->structured($this->tool(CreateBoardItemTool::class, $board + ['title' => 'Repro', 'parent_id' => $item->id], $this->editor));
        $this->assertSame($item->id, Item::find($child['item_id'])->parent_id);

        $this->tool(UpdateBoardItemTool::class, $board + [
            'item_id' => $item->id, 'labels' => ['urgent'], 'priority' => '', 'fields' => ['Customer' => null], 'story_points' => null, 'title' => 'Crash on save (Safari)',
        ], $this->editor)->assertOk();
        $item->refresh();
        $this->assertSame(['urgent'], $item->labels->pluck('name')->all());
        $this->assertSame('P0', $item->priority->name, 'an empty priority keeps the current one');
        $this->assertSame(0, $item->fieldValues()->count());
        $this->assertNull($item->story_points);
        $this->assertSame('Crash on save (Safari)', $item->title);

        // Parent rules: no self, no cycle.
        $this->tool(UpdateBoardItemTool::class, $board + ['item_id' => $item->id, 'parent_id' => $item->id], $this->editor)->assertHasErrors(['own parent']);
        $this->tool(UpdateBoardItemTool::class, $board + ['item_id' => $item->id, 'parent_id' => $child['item_id']], $this->editor)->assertHasErrors();
        $this->tool(UpdateBoardItemTool::class, $board + ['item_id' => $child['item_id'], 'parent_id' => null], $this->editor)->assertOk();
        $this->assertNull(Item::find($child['item_id'])->parent_id);

        // Viewers can read the config but not write.
        $this->tool(UpdateBoardItemTool::class, $board + ['item_id' => $item->id, 'title' => 'nope'], $this->viewer)->assertHasErrors();
        $this->tool(UpdateProjectTool::class, $args + ['name' => 'Renamed'], $this->editor)->assertHasErrors();
        $this->tool(UpdateProjectTool::class, $args + ['name' => 'Renamed', 'description' => 'New'])->assertOk();
        $this->assertSame('Renamed', $this->project->fresh()->name);
    }

    public function test_columns_can_be_added_changed_ordered_and_removed(): void
    {
        $board = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug];

        $first = $this->structured($this->tool(CreateBoardColumnTool::class, $board + ['name' => 'Triage', 'after_column_id' => 0, 'request_id' => 'c1']));
        $this->assertSame('Triage', $first['columns'][0]['name']);
        $this->assertSame(['Triage', 'To Do', 'In Progress', 'Done'], array_column($first['columns'], 'name'));
        $this->assertFalse($this->structured($this->tool(CreateBoardColumnTool::class, $board + ['name' => 'Triage', 'after_column_id' => 0, 'request_id' => 'c1']))['created']);
        $this->tool(CreateBoardColumnTool::class, $board + ['name' => 'triage', 'request_id' => 'c2'])->assertHasErrors(['already has a column']);

        $review = $this->structured($this->tool(CreateBoardColumnTool::class, $board + ['name' => 'Review', 'after_column_id' => $this->column('in-progress'), 'wip_limit' => 2, 'request_id' => 'c3']));
        $this->assertSame(['Triage', 'To Do', 'In Progress', 'Review', 'Done'], array_column($review['columns'], 'name'));

        $updated = $this->structured($this->tool(UpdateBoardColumnTool::class, $board + ['column_id' => $review['column_id'], 'name' => 'QA', 'is_done' => false, 'wip_limit' => 0, 'color' => '#111111']));
        $this->assertSame('QA', $updated['column']['name']);
        $this->assertNull($updated['column']['wip_limit']);
        $this->assertSame('#111111', $updated['column']['color']);

        $ids = array_column($review['columns'], 'column_id');
        $reordered = $this->structured($this->tool(ReorderBoardColumnsTool::class, $board + ['column_ids' => array_reverse($ids)]));
        $this->assertSame(['Done', 'QA', 'In Progress', 'To Do', 'Triage'], array_column($reordered['columns'], 'name'));
        $this->tool(ReorderBoardColumnsTool::class, $board + ['column_ids' => [$ids[0]]])->assertHasErrors(['every column']);

        $this->item(['column_id' => $review['column_id']]);
        $this->tool(DeleteBoardColumnTool::class, $board + ['column_id' => $review['column_id']])->assertHasErrors(['still has cards']);
        $this->assertTrue($this->structured($this->tool(DeleteBoardColumnTool::class, $board + ['column_id' => $first['column_id']]))['deleted']);
        $this->assertFalse($this->structured($this->tool(DeleteBoardColumnTool::class, $board + ['column_id' => $first['column_id']]))['deleted']);

        // Viewers can't touch columns; a second board with named columns starts without the defaults.
        $this->tool(CreateBoardColumnTool::class, $board + ['name' => 'Nope', 'request_id' => 'c9'], $this->viewer)->assertHasErrors();
        $second = $this->structured($this->tool(CreateBoardTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'name' => 'Support', 'columns' => [['name' => 'New'], ['name' => 'Closed', 'is_done' => true]], 'request_id' => 'nb1']));
        $this->assertSame('support', $second['board_slug']);
        $this->assertSame(['New', 'Closed'], array_column($second['columns'], 'name'));
        $this->assertTrue($second['columns'][1]['is_done']);
    }

    public function test_fields_and_labels_lifecycle(): void
    {
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug];
        $board = $args + ['board' => $this->board->slug];
        $field = $this->structured($this->tool(CreateItemFieldTool::class, $args + ['name' => 'Env', 'type' => 'select', 'options' => [['label' => 'Prod'], ['label' => 'Dev']]]));
        $prod = $field['options'][0]['option_id'];

        $this->tool(CreateBoardItemTool::class, $board + ['title' => 'x', 'fields' => ['Env' => 'Prod']], $this->editor)->assertOk();

        // Rename an option (kept by id), drop the other (archived), add one.
        $updated = $this->structured($this->tool(UpdateItemFieldTool::class, $args + ['field_id' => $field['field_id'], 'options' => [['option_id' => $prod, 'label' => 'Production'], ['label' => 'Staging']]]));
        $this->assertSame(['Production', 'Staging'], array_column($updated['options'], 'label'));
        $this->assertSame('Production', Item::first()->fieldValues()->first()->option->label);

        $this->tool(UpdateItemFieldTool::class, $args + ['field_id' => $field['field_id'], 'archived' => true])->assertOk();
        $this->tool(CreateBoardItemTool::class, $board + ['title' => 'y', 'fields' => ['Env' => 'Staging']], $this->editor)->assertHasErrors(['No custom field "Env"']);
        $this->tool(UpdateItemFieldTool::class, $args + ['field_id' => $field['field_id'], 'archived' => false, 'name' => 'Environment'])->assertOk();
        $this->tool(UpdateItemFieldTool::class, $args + ['field_id' => 999999, 'name' => 'x'])->assertHasErrors(['No custom field']);

        $label = $this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'temp']));
        $this->assertTrue($this->structured($this->tool(DeleteLabelTool::class, $args + ['label_id' => $label['label_id']]))['deleted']);
        $this->assertFalse($this->structured($this->tool(DeleteLabelTool::class, $args + ['label_id' => $label['label_id']]))['deleted']);
    }

    public function test_review_rules_sprints_soft_deletes_slugs_and_denials(): void
    {
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug];
        $board = $args + ['board' => $this->board->slug];

        // A deleted project keeps its name / key / slug: the tool says so instead of crashing on the unique index.
        $gone = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->admin->id, 'name' => 'Old shop', 'key' => 'OLD']);
        $gone->delete();
        $this->tool(CreateProjectTool::class, ['workspace' => $this->workspace->slug, 'name' => 'Old shop', 'key' => 'NEW', 'request_id' => 'sd1'])->assertHasErrors(['was deleted']);
        $this->tool(CreateProjectTool::class, ['workspace' => $this->workspace->slug, 'name' => 'Newer shop', 'key' => 'old', 'request_id' => 'sd2'])->assertHasErrors(['OLD is already used']);

        // Subtasks join their parent's sprint, in bulk (parent_key and parent_external_key) and one by one.
        $this->board->update(['type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id, 'status' => Sprint::STATUS_ACTIVE]);
        $epic = $this->item(['title' => 'Epic', 'sprint_id' => $sprint->id]);
        $bulk = $this->structured($this->tool(CreateBoardItemsTool::class, $board + ['items' => [
            ['title' => 'Child by key', 'parent_key' => $epic->key],
            ['title' => 'Other epic', 'external_key' => 'E2'],
            ['title' => 'Child by external', 'parent_external_key' => 'E2'],
        ], 'request_id' => 'sp1'], $this->editor));
        $this->assertSame($sprint->id, Item::find($bulk['items'][0]['item_id'])->sprint_id);
        $this->assertNull(Item::find($bulk['items'][1]['item_id'])->sprint_id);
        $this->assertSame(Item::find($bulk['items'][1]['item_id'])->id, Item::find($bulk['items'][2]['item_id'])->parent_id);
        $single = $this->structured($this->tool(CreateBoardItemTool::class, $board + ['title' => 'Single child', 'parent_id' => $epic->id, 'start_time' => '09:00'], $this->editor));
        $this->assertSame($sprint->id, Item::find($single['item_id'])->sprint_id);
        $this->assertSame('09:00', substr((string) Item::find($single['item_id'])->start_time, 0, 5));
        // A parent named by external_key must come earlier in the batch.
        $this->tool(CreateBoardItemsTool::class, $board + ['items' => [['title' => 'c', 'parent_external_key' => 'LATER'], ['title' => 'p', 'external_key' => 'LATER']], 'request_id' => 'sp2'], $this->editor)
            ->assertHasErrors(['parents must come before']);

        // No kanban while a sprint runs (same guard as the board settings).
        $this->tool(UpdateBoardTool::class, $board + ['type' => 'kanban'])->assertHasErrors(['Complete the active sprint']);
        $this->tool(UpdateBoardTool::class, $board + ['name' => 'Renamed board'])->assertOk();

        // Labels: a slug twin counts as existing; a rename moves the slug and refuses a taken name.
        $this->tool(CreateLabelTool::class, $args + ['name' => 'check-out']);
        $this->assertFalse($this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'Check out']))['created']);
        $ops = $this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'temp']));
        $this->tool(UpdateLabelTool::class, $args + ['label_id' => $ops['label_id'], 'name' => 'ops'])->assertOk();
        $this->assertSame('ops', $this->project->labels()->find($ops['label_id'])->slug);
        $this->tool(UpdateLabelTool::class, $args + ['label_id' => $ops['label_id'], 'name' => 'Check-Out'])->assertHasErrors(['already called']);
        $this->assertTrue($this->structured($this->tool(CreateLabelTool::class, $args + ['name' => 'temp']))['created'], 'the old slug is free again');

        // A disabled instance type is switched back on instead of shadowed.
        $bugId = ItemType::whereNull('project_id')->where('slug', 'bug')->value('id');
        $this->project->update(['settings' => ['disabled_item_types' => [$bugId]]]);
        $back = $this->structured($this->tool(CreateItemTypeTool::class, $args + ['name' => 'Bug']));
        $this->assertSame($bugId, $back['type_id']);
        $this->assertSame([], $this->project->fresh()->disabledItemTypeIds());
        $this->tool(UpdateItemTypeTool::class, $args + ['type_id' => $bugId, 'is_active' => false])->assertOk();
        $this->assertSame([$bugId], $this->project->fresh()->disabledItemTypeIds());
        $prio = $this->structured($this->tool(CreatePriorityTool::class, $args + ['name' => 'P1']));
        $this->tool(UpdatePriorityTool::class, $args + ['priority_id' => $prio['priority_id'], 'name' => 'P1 hot', 'order' => 0])->assertOk();
        $this->assertSame('P1 hot', ItemPriority::find($prio['priority_id'])->name);

        // Setup tools are for project admins: an editor and a viewer are refused everywhere.
        $denied = [
            [UpdateProjectTool::class, $args + ['name' => 'x']],
            [AddProjectMemberTool::class, $args + ['email' => $this->viewer->email, 'role' => 'admin']],
            [CreateLabelTool::class, $args + ['name' => 'x']],
            [UpdateLabelTool::class, $args + ['label_id' => $ops['label_id'], 'name' => 'y']],
            [DeleteLabelTool::class, $args + ['label_id' => $ops['label_id']]],
            [CreateItemTypeTool::class, $args + ['name' => 'x']],
            [CreatePriorityTool::class, $args + ['name' => 'x']],
            [CreateItemFieldTool::class, $args + ['name' => 'x', 'type' => 'text']],
        ];
        foreach ($denied as [$tool, $payload]) {
            $this->tool($tool, $payload, $this->editor)->assertHasErrors();
            $this->tool($tool, $payload, $this->viewer)->assertHasErrors();
        }
        // Boards and their columns are editor-level (BoardPolicy::create / update, like the UI); viewers may not.
        foreach ([
            [CreateBoardTool::class, $args + ['name' => 'x', 'request_id' => 'd1']],
            [UpdateBoardTool::class, $board + ['name' => 'x']],
            [DeleteBoardColumnTool::class, $board + ['column_id' => $this->column('done')]],
            [ReorderBoardColumnsTool::class, $board + ['column_ids' => [1]]],
        ] as [$tool, $payload]) {
            $this->tool($tool, $payload, $this->viewer)->assertHasErrors();
        }
        $this->tool(CreateTaskListTool::class, $args + ['name' => 'x', 'request_id' => 'd2'], $this->viewer)->assertHasErrors();
    }

    public function test_capture_to_inbox_is_personal_and_idempotent(): void
    {
        $args = ['text' => "Call the bank\nabout the invoice", 'url' => 'https://example.com/invoice', 'intended_type' => 'task', 'request_id' => 'cap-1'];
        $first = $this->structured($this->tool(CaptureToInboxTool::class, $args, $this->editor));
        $this->assertTrue($first['created']);
        $this->assertSame('Call the bank', $first['location']['title']);
        $this->assertFalse($this->structured($this->tool(CaptureToInboxTool::class, $args, $this->editor))['created']);
        $this->assertSame(1, Capture::count());
        $this->assertSame($this->editor->id, Capture::first()->user_id);
        $this->assertSame('MCP', Capture::first()->source);
        $this->tool(CaptureToInboxTool::class, ['text' => 'other', 'request_id' => 'cap-1'], $this->editor)->assertHasErrors(['already used']);
    }
}
