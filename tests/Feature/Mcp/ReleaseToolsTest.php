<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CreateReleaseTool;
use App\Mcp\Tools\GetReleaseTool;
use App\Mcp\Tools\ListReleasesTool;
use App\Mcp\Tools\SetItemsReleaseTool;
use App\Mcp\Tools\UpdateReleaseTool;
use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class ReleaseToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function args(array $extra = []): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug] + $extra;
    }

    private function release(array $attributes = []): Release
    {
        return Release::factory()->create($attributes + ['project_id' => $this->project->id]);
    }

    public function test_create_is_repeatable_by_name_and_records_past_releases(): void
    {
        BrainitiveServer::actingAs($this->editor)->tool(CreateReleaseTool::class, $this->args([
            'name' => 'v1.0', 'description' => 'First', 'start_date' => '2026-11-01', 'release_date' => '2026-11-15', 'notes' => "## Features\n\n- **Login**",
        ]))->assertOk()->assertStructuredContent(fn (AssertableJson $j) => $j
            ->where('created', true)->where('release.name', 'v1.0')->where('release.status', 'unreleased')
            ->where('release.release_date', '2026-11-15')->where('location.type', 'release')->etc());

        $release = Release::sole();
        $this->assertStringContainsString('<strong>Login</strong>', $release->notes);
        $this->assertSame($this->editor->id, $release->created_by);

        // Same name (any case): the existing release, unchanged.
        BrainitiveServer::actingAs($this->editor)->tool(CreateReleaseTool::class, $this->args(['name' => 'V1.0', 'description' => 'Other']))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('created', false)->where('release.id', $release->id)->where('release.description', 'First')->etc());
        $this->assertSame(1, Release::count());

        // A version shipped in the past.
        BrainitiveServer::actingAs($this->editor)->tool(CreateReleaseTool::class, $this->args(['name' => 'v0.9', 'status' => 'released', 'release_date' => '2026-01-10']))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('created', true)->where('release.status', 'released')->etc());
        $old = Release::where('name', 'v0.9')->sole();
        $this->assertSame('2026-01-10', $old->released_at->toDateString());

        // The web's rules: dates in order.
        BrainitiveServer::actingAs($this->editor)->tool(CreateReleaseTool::class, $this->args(['name' => 'v2', 'start_date' => '2026-11-15', 'release_date' => '2026-11-01']))->assertHasErrors();
        $this->assertSame(2, Release::count());
    }

    public function test_list_and_get(): void
    {
        $release = $this->release(['name' => 'v1.0', 'release_date' => '2026-12-01', 'notes' => '<p>Hello <em>world</em></p>']);
        $this->release(['name' => 'old', 'status' => Release::STATUS_ARCHIVED]);
        $todo = $this->item(['title' => 'Todo', 'release_id' => $release->id]);
        $this->item(['title' => 'Doing', 'release_id' => $release->id, 'column_id' => $this->column('in-progress')]);
        $this->item(['title' => 'Done', 'release_id' => $release->id, 'column_id' => $this->column('done'), 'completed_at' => now()]);

        BrainitiveServer::actingAs($this->viewer)->tool(ListReleasesTool::class, $this->args())->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->has('releases', 2)->where('releases.0.name', 'v1.0')->where('releases.1.name', 'old')
                ->where('releases.0.progress', ['total' => 3, 'done' => 1, 'in_progress' => 1, 'todo' => 1])
                ->where('can_manage', false)->etc());

        BrainitiveServer::actingAs($this->viewer)->tool(GetReleaseTool::class, $this->args(['release_id' => $release->id]))->assertOk()
            ->assertStructuredContent(fn (AssertableJson $j) => $j
                ->where('notes', 'Hello *world*')->has('issues', 3)
                ->where('issues.0.key', $todo->key)->where('issues.0.state', 'todo')->where('issues.0.column', 'To Do')->etc());
    }

    public function test_update_fields_status_and_shipping(): void
    {
        $release = $this->release(['name' => 'v1.0']);
        $next = $this->release(['name' => 'v1.1']);
        $open = $this->item(['release_id' => $release->id]);
        $done = $this->item(['release_id' => $release->id, 'column_id' => $this->column('done'), 'completed_at' => now()]);

        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'name' => 'v1.0.0', 'notes' => '- one']))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('release.name', 'v1.0.0')->etc());
        $this->assertStringContainsString('<li', $release->fresh()->notes);
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'name' => 'v1.1']))->assertHasErrors();

        // Shipping with unfinished work needs an explicit destination; nothing changes without it.
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'status' => 'released', 'name' => 'x']))
            ->assertHasErrors(['move_unfinished_to']);
        $this->assertSame('v1.0.0', $release->fresh()->name);
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'status' => 'released', 'release_date' => '2026-11-05', 'move_unfinished_to' => $next->id]))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('moved_unfinished', 1)->where('release.status', 'released')->where('release.release_date', '2026-11-05')->etc());
        $this->assertSame($next->id, $open->fresh()->release_id);
        $this->assertSame($release->id, $done->fresh()->release_id);

        // Archive, restore, reopen.
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'status' => 'archived']))->assertOk();
        $this->assertSame(Release::STATUS_ARCHIVED, $release->fresh()->status);
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'status' => 'released']))->assertOk();
        $this->assertSame(Release::STATUS_RELEASED, $release->fresh()->status);
        BrainitiveServer::actingAs($this->editor)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'status' => 'unreleased']))->assertOk();
        $this->assertNull($release->fresh()->released_at);
    }

    public function test_set_items_release_moves_clears_and_rejects_foreign_keys(): void
    {
        $old = $this->release(['name' => 'v0.9']);
        $release = $this->release(['name' => 'v1.0']);
        $a = $this->item(['release_id' => $old->id]);
        $b = $this->item();
        $sub = $this->item(['parent_id' => $b->id]);

        $otherProject = Project::factory()->create(['workspace_id' => $this->workspace->id, 'created_by' => $this->editor->id, 'key' => 'OTH']);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->editor->id]);
        $foreign = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id]);

        // One key of another project (or unknown): nothing changes.
        BrainitiveServer::actingAs($this->editor)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => $release->id, 'item_keys' => [$a->key, $foreign->key, 'BRAI-999']]))
            ->assertHasErrors([$foreign->key, 'BRAI-999', 'Nothing was changed']);
        $this->assertSame($old->id, $a->fresh()->release_id);

        $keys = [strtolower($a->key), $b->key, $sub->key];
        BrainitiveServer::actingAs($this->editor)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => $release->id, 'item_keys' => $keys]))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('release', 'v1.0')->where('changed', 3)
                ->where('items.0.key', $a->key)->where('items.0.previous_release', 'v0.9')->etc());
        $this->assertSame([$release->id, $release->id, $release->id], [$a->fresh()->release_id, $b->fresh()->release_id, $sub->fresh()->release_id]);

        // Repeating it changes nothing more.
        BrainitiveServer::actingAs($this->editor)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => $release->id, 'item_keys' => $keys]))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('changed', 0)->etc());

        // Clear.
        BrainitiveServer::actingAs($this->editor)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => 0, 'item_keys' => [$b->key]]))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('release', '')->where('changed', 1)->etc());
        $this->assertNull($b->fresh()->release_id);

        // Archived releases take no issues.
        $archived = $this->release(['name' => 'old', 'status' => Release::STATUS_ARCHIVED]);
        BrainitiveServer::actingAs($this->editor)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => $archived->id, 'item_keys' => [$b->key]]))->assertHasErrors(['Archived']);
    }

    public function test_permissions(): void
    {
        $release = $this->release(['name' => 'v1.0']);
        $item = $this->item();
        $foreign = Release::factory()->create();

        // Viewers read but can't plan.
        BrainitiveServer::actingAs($this->viewer)->tool(ListReleasesTool::class, $this->args())->assertOk();
        BrainitiveServer::actingAs($this->viewer)->tool(GetReleaseTool::class, $this->args(['release_id' => $release->id]))->assertOk();
        BrainitiveServer::actingAs($this->viewer)->tool(CreateReleaseTool::class, $this->args(['name' => 'v2']))->assertHasErrors();
        BrainitiveServer::actingAs($this->viewer)->tool(UpdateReleaseTool::class, $this->args(['release_id' => $release->id, 'name' => 'x']))->assertHasErrors();
        BrainitiveServer::actingAs($this->viewer)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => $release->id, 'item_keys' => [$item->key]]))->assertHasErrors();
        BrainitiveServer::actingAs($this->viewer)->tool(SetItemsReleaseTool::class, $this->args(['release_id' => 0, 'item_keys' => [$item->key]]))->assertHasErrors();

        // Outsiders see nothing.
        BrainitiveServer::actingAs($this->outsider)->tool(ListReleasesTool::class, $this->args())->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(GetReleaseTool::class, $this->args(['release_id' => $release->id]))->assertHasErrors();

        // A release of another project isn't reachable through this one.
        BrainitiveServer::actingAs($this->editor)->tool(GetReleaseTool::class, $this->args(['release_id' => $foreign->id]))->assertHasErrors(['No release with that id']);

        $this->assertSame('v1.0', $release->fresh()->name);
        $this->assertNull($item->fresh()->release_id);
        $this->assertSame(1, $this->project->releases()->count());
    }
}
