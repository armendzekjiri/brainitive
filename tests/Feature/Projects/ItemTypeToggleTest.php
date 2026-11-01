<?php

namespace Tests\Feature\Projects;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateBoardItemTool;
use App\Mcp\Tools\CreateItemTypeTool;
use App\Mcp\Tools\GetProjectConfigTool;
use App\Mcp\Tools\UpdateBoardItemTool;
use App\Mcp\Tools\UpdateItemTypeTool;
use App\Models\ItemType;
use App\Models\User;
use App\Support\Boards\ProjectIssues;
use Database\Seeders\ItemTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * BRAI-430 / GitHub #133: any number of item types can be switched off per project, they stay off,
 * and nothing that picks a type for a new or edited issue offers them. Cards that already have a
 * switched-off type keep it.
 */
class ItemTypeToggleTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ItemTypeSeeder::class);
        $this->setUpBoard();
        $this->admin = User::find($this->workspace->owner_id);
    }

    private function type(string $slug): ItemType
    {
        return ItemType::whereNull('project_id')->where('slug', $slug)->firstOrFail();
    }

    /** The settings page's on/off button (an Inertia PUT with only is_active). */
    private function toggle(ItemType $type, bool $on)
    {
        $edit = route('projects.edit', [$this->workspace->slug, $this->project->slug]);

        return $this->actingAs($this->admin)->from($edit)->put(
            route('projects.item-types.update', [$this->workspace->slug, $this->project->slug, $type->id]),
            ['is_active' => $on],
            ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())],
        )->assertRedirect($edit);
    }

    /** @return array<string, bool> slug => enabled, as the settings page shows it */
    private function settingsPage(): array
    {
        $types = [];
        $this->actingAs($this->admin)->get(route('projects.edit', [$this->workspace->slug, $this->project->slug]))
            ->assertInertia(function (AssertableInertia $page) use (&$types) {
                $types = collect($page->toArray()['props']['projectItemTypes'])->mapWithKeys(fn ($t) => [$t['slug'] => $t['is_active']])->all();
            });

        return $types;
    }

    /** @return list<string> slugs the board offers */
    private function boardTypes(): array
    {
        $slugs = [];
        $this->actingAs($this->admin)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(function (AssertableInertia $page) use (&$slugs) {
                $slugs = collect($page->toArray()['props']['boardItemTypes'])->pluck('slug')->all();
            });

        return $slugs;
    }

    public function test_two_types_can_be_switched_off_and_the_settings_page_shows_both_off(): void
    {
        // The second one was the bug: the settings page stopped marking types at the first one
        // that was off (Collection::each stops on false), so Story looked on and couldn't go off.
        $this->toggle($this->type('bug'), false);
        $this->toggle($this->type('story'), false);

        $this->assertEqualsCanonicalizing([$this->type('bug')->id, $this->type('story')->id], $this->project->fresh()->disabledItemTypeIds());
        $this->assertSame(['task' => true, 'bug' => false, 'story' => false, 'epic' => true, 'subtask' => true], $this->settingsPage());

        // the shared rows are never touched
        $this->assertTrue($this->type('bug')->is_active);

        $this->toggle($this->type('bug'), true);
        $this->assertSame([$this->type('story')->id], $this->project->fresh()->disabledItemTypeIds());
        $this->assertSame(['task' => true, 'bug' => true, 'story' => false, 'epic' => true, 'subtask' => true], $this->settingsPage());
    }

    public function test_a_project_type_can_be_switched_off_and_on_and_still_edited(): void
    {
        $spike = $this->project->itemTypes()->create(['name' => 'Spike', 'slug' => 'spike']);

        $this->toggle($spike, false);
        $this->assertFalse($spike->fresh()->is_active);
        $this->assertFalse($this->settingsPage()['spike']);
        $this->assertNotContains('spike', $this->boardTypes());

        // the edit dialog (name, color…) doesn't switch it back on
        $this->actingAs($this->admin)->put(route('projects.item-types.update', [$this->workspace->slug, $this->project->slug, $spike->id]), ['name' => 'Research spike', 'color' => '#ff0000'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Research spike', $spike->fresh()->name);
        $this->assertFalse($spike->fresh()->is_active);

        $this->toggle($spike, true);
        $this->assertContains('spike', $this->boardTypes());
    }

    public function test_pickers_offer_only_enabled_types(): void
    {
        $this->toggle($this->type('bug'), false);
        $this->toggle($this->type('story'), false);
        $other = $this->project->replicate(['uuid', 'slug', 'key'])->fill(['name' => 'Other', 'slug' => 'other-project', 'key' => 'OTH']);
        $other->save();
        $foreign = $other->itemTypes()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        // board: quick add, create dialog, issue panel, list view / bulk edit
        $this->assertSame(['task', 'epic', 'subtask'], $this->boardTypes());

        // full-page issue view
        $item = $this->item(['item_type_id' => $this->type('bug')->id]);
        $this->actingAs($this->admin)->get($this->itemRoute('boards.items.show', $item))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('itemTypes', fn ($types) => collect($types)->pluck('slug')->sort()->values()->all() === ['epic', 'subtask', 'task'])
                ->where('item.item_type.slug', 'bug'));

        // the global "Create" dialog loads the project's types (no more instance-wide shared prop)
        $json = $this->actingAs($this->admin)->getJson(route('projects.item-types.index', [$this->workspace->slug, $this->project->slug]))->assertOk()->json();
        $this->assertSame(['task', 'epic', 'subtask'], array_column($json, 'slug'));
        $this->assertNotContains($foreign->slug, array_column($json, 'slug'));
        $this->actingAs($this->outsider)->getJson(route('projects.item-types.index', [$this->workspace->slug, $this->project->slug]))->assertForbidden();
        $this->actingAs($this->admin)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(fn (AssertableInertia $page) => $page->missing('itemTypes'));

        // recurring issues
        $options = $this->actingAs($this->admin)->getJson(route('recurring-issues.index', [$this->workspace->slug, $this->project->slug, $this->board->slug]))->assertOk()->json('options.types');
        $this->assertSame(['Task', 'Epic'], array_column($options, 'name'));
    }

    public function test_new_and_edited_issues_cannot_get_a_switched_off_type_but_cards_keep_theirs(): void
    {
        $bug = $this->type('bug');
        $existing = $this->item(['item_type_id' => $bug->id, 'title' => 'Old bug']);
        $this->toggle($bug, false);
        $store = route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]);

        $this->actingAs($this->editor)->post($store, ['title' => 'New', 'column_id' => $this->column('to-do'), 'item_type_id' => $bug->id])
            ->assertSessionHasErrors('item_type_id');
        $this->actingAs($this->editor)->post($store, ['title' => 'New epic', 'column_id' => $this->column('to-do'), 'item_type_id' => $this->type('epic')->id])
            ->assertSessionHasNoErrors();

        // another project's own type was accepted before (exists:item_types,id)
        $otherProject = $this->project->replicate(['uuid', 'slug', 'key'])->fill(['slug' => 'p2', 'key' => 'PTWO']);
        $otherProject->save();
        $foreign = $otherProject->itemTypes()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->actingAs($this->editor)->post($store, ['title' => 'New', 'column_id' => $this->column('to-do'), 'item_type_id' => $foreign->id])
            ->assertSessionHasErrors('item_type_id');

        $other = $this->item(['item_type_id' => $this->type('task')->id]);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $other), ['item_type_id' => $bug->id])
            ->assertSessionHasErrors('item_type_id');
        $this->assertSame($this->type('task')->id, $other->fresh()->item_type_id);

        // the old card keeps its type, also when it's saved with it (the issue panel sends it)
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $existing), ['title' => 'Old bug, renamed', 'item_type_id' => $bug->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($bug->id, $existing->fresh()->item_type_id);
        $this->actingAs($this->admin)->get(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->assertInertia(function (AssertableInertia $page) use ($existing) {
                $card = collect($page->toArray()['props']['board']['columns'])->flatMap(fn ($c) => $c['items'])->firstWhere('id', $existing->id);
                $this->assertSame('bug', $card['item_type']['slug']);
            });

        // bulk edit
        $this->actingAs($this->editor)->patch(route('boards.items.bulk.update', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['item_ids' => [$other->id], 'changes' => ['item_type_id' => $bug->id]])
            ->assertSessionHasErrors('changes.item_type_id');
    }

    public function test_new_issues_fall_back_to_an_enabled_type_when_the_default_is_switched_off(): void
    {
        $this->toggle($this->type('task'), false);
        $store = route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]);

        $this->actingAs($this->editor)->post($store, ['title' => 'No type chosen', 'column_id' => $this->column('to-do')])->assertSessionHasNoErrors();
        $this->assertSame('bug', $this->board->items()->where('title', 'No type chosen')->first()->itemType->slug);

        $created = ProjectIssues::create($this->board, $this->project->fresh(), $this->editor, [['title' => 'From a helper']])->first();
        $this->assertSame('bug', $created->itemType->slug);

        foreach (['task', 'bug', 'story', 'epic'] as $slug) {
            $this->toggle($this->type($slug), false);
        }
        $this->assertNull(ItemType::defaultIdFor($this->project->fresh()), 'Subtask is never the default: subtasks need a parent');
    }

    public function test_mcp_tools_use_only_enabled_types(): void
    {
        $args = ['workspace' => $this->workspace->slug, 'project' => $this->project->slug];
        $board = $args + ['board' => $this->board->slug];
        $mcp = BrainitiveServer::actingAs($this->admin);

        $mcp->tool(UpdateItemTypeTool::class, $args + ['type_id' => $this->type('bug')->id, 'is_active' => false])->assertOk();
        $mcp->tool(UpdateItemTypeTool::class, $args + ['type_id' => $this->type('story')->id, 'is_active' => false])->assertOk();
        $spike = $this->project->itemTypes()->create(['name' => 'Spike', 'slug' => 'spike']);
        $mcp->tool(UpdateItemTypeTool::class, $args + ['type_id' => $spike->id, 'is_active' => false])->assertOk();
        $this->assertFalse($spike->fresh()->is_active);

        $config = null;
        $mcp->tool(GetProjectConfigTool::class, $args)->assertStructuredContent(function (AssertableJson $j) use (&$config) {
            $config = $j->toArray();
            $j->etc();
        });
        $this->assertSame(['Task', 'Epic', 'Subtask'], array_column($config['item_types'], 'name'));

        $mcp->tool(CreateBoardItemTool::class, $board + ['title' => 'x', 'type' => 'Bug', 'request_id' => 'r1'])->assertHasErrors(['No item type "Bug"']);
        $mcp->tool(CreateBoardItemTool::class, $board + ['title' => 'x', 'type' => 'Spike', 'request_id' => 'r2'])->assertHasErrors(['No item type "Spike"']);

        // a card keeps its switched-off type when the AI passes it back unchanged
        $card = $this->item(['item_type_id' => $this->type('bug')->id]);
        $mcp->tool(UpdateBoardItemTool::class, $board + ['item_id' => $card->id, 'title' => 'Renamed', 'type' => 'Bug'])->assertOk();
        $this->assertSame($this->type('bug')->id, $card->fresh()->item_type_id);
        $other = $this->item(['item_type_id' => $this->type('task')->id]);
        $mcp->tool(UpdateBoardItemTool::class, $board + ['item_id' => $other->id, 'type' => 'Bug'])->assertHasErrors(['No item type "Bug"']);

        // create_item_type with the name of a switched-off project type switches it back on
        $mcp->tool(CreateItemTypeTool::class, $args + ['name' => 'Spike'])->assertOk();
        $this->assertTrue($spike->fresh()->is_active);
        $this->assertSame(1, ItemType::where('project_id', $this->project->id)->count());
    }
}
