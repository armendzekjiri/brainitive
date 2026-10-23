<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\CompleteSprintTool;
use App\Mcp\Tools\CreateSprintTool;
use App\Mcp\Tools\GetSprintTool;
use App\Mcp\Tools\ListSprintsTool;
use App\Mcp\Tools\MoveItemsToSprintTool;
use App\Mcp\Tools\StartSprintTool;
use App\Mcp\Tools\UpdateSprintTool;
use App\Models\Board;
use App\Models\Sprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class SprintToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->board->update(['type' => 'scrum']);
    }

    private function args(array $extra = []): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'board' => $this->board->slug] + $extra;
    }

    public function test_plan_start_and_complete_a_sprint(): void
    {
        $issue = $this->item(['title' => 'Login', 'story_points' => 3]);
        $child = $this->item(['title' => 'Form', 'parent_id' => $issue->id]);
        $other = $this->item(['title' => 'Signup', 'story_points' => 5]);

        BrainitiveServer::actingAs($this->editor)->tool(CreateSprintTool::class, $this->args(['name' => 'Sprint A', 'request_id' => 'c1']))->assertOk();
        BrainitiveServer::actingAs($this->editor)->tool(CreateSprintTool::class, $this->args(['name' => 'Sprint A', 'request_id' => 'c1']))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('created', false)->etc());
        $sprint = Sprint::sole();

        // Moving the parent takes its subtask along; a subtask id alone is refused.
        BrainitiveServer::actingAs($this->editor)->tool(MoveItemsToSprintTool::class, $this->args(['sprint_id' => $sprint->id, 'item_ids' => [$child->id], 'request_id' => 'm0']))
            ->assertHasErrors(['Not top-level issues']);
        BrainitiveServer::actingAs($this->editor)->tool(MoveItemsToSprintTool::class, $this->args(['sprint_id' => $sprint->id, 'item_ids' => [$issue->id, $other->id], 'request_id' => 'm1']))
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('moved', 2)->etc());
        $this->assertSame($sprint->id, $child->fresh()->sprint_id);

        BrainitiveServer::actingAs($this->editor)->tool(GetSprintTool::class, $this->args(['sprint_id' => $sprint->id]))->assertOk()->assertSee(['Login', 'Signup']);

        // Start twice with the same request: applied once. A second start (new request) is refused.
        $start = $this->args(['sprint_id' => $sprint->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-16', 'request_id' => 's1']);
        BrainitiveServer::actingAs($this->editor)->tool(StartSprintTool::class, $start)->assertStructuredContent(fn (AssertableJson $j) => $j->where('started', true)->etc());
        BrainitiveServer::actingAs($this->editor)->tool(StartSprintTool::class, $start)->assertStructuredContent(fn (AssertableJson $j) => $j->where('started', false)->etc());
        BrainitiveServer::actingAs($this->editor)->tool(StartSprintTool::class, ['request_id' => 's2'] + $start)->assertHasErrors(['already started']);
        $this->assertSame(8, $sprint->fresh()->committed_points);

        // Complete: unfinished work goes where we say; snapshots stay.
        $next = Sprint::factory()->create(['board_id' => $this->board->id]);
        $other->update(['column_id' => $this->column('done'), 'completed_at' => now()]);
        $complete = $this->args(['sprint_id' => $sprint->id, 'move_open_to' => $next->id, 'request_id' => 'x1']);
        BrainitiveServer::actingAs($this->editor)->tool(CompleteSprintTool::class, $complete)
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('moved_open', 1)->where('completed_issues', 1)->where('completed_points', 5)->where('completed', true)->etc());
        BrainitiveServer::actingAs($this->editor)->tool(CompleteSprintTool::class, $complete)->assertStructuredContent(fn (AssertableJson $j) => $j->where('completed', false)->etc());

        $this->assertSame($next->id, $issue->fresh()->sprint_id);
        $this->assertSame($next->id, $child->fresh()->sprint_id);
        $this->assertSame(Sprint::STATUS_COMPLETED, $sprint->fresh()->status);
        $this->assertSame(5, $sprint->fresh()->completed_points);

        // Completed sprints can't be edited.
        BrainitiveServer::actingAs($this->editor)->tool(UpdateSprintTool::class, $this->args(['sprint_id' => $sprint->id, 'expected_status' => 'active', 'name' => 'x']))->assertHasErrors(['Conflict']);
    }

    public function test_rules_and_permissions(): void
    {
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id]);

        // Empty sprint can't start; invalid dates refused.
        BrainitiveServer::actingAs($this->editor)->tool(StartSprintTool::class, $this->args(['sprint_id' => $sprint->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-16', 'request_id' => 'a']))->assertHasErrors(['at least one issue']);
        BrainitiveServer::actingAs($this->editor)->tool(StartSprintTool::class, $this->args(['sprint_id' => $sprint->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-02', 'request_id' => 'b']))->assertHasErrors();

        // Viewers read but can't plan; outsiders see nothing.
        BrainitiveServer::actingAs($this->viewer)->tool(ListSprintsTool::class, $this->args())->assertOk();
        BrainitiveServer::actingAs($this->viewer)->tool(CreateSprintTool::class, $this->args(['request_id' => 'v']))->assertHasErrors();
        BrainitiveServer::actingAs($this->outsider)->tool(ListSprintsTool::class, $this->args())->assertHasErrors();

        // Foreign sprint ids and Kanban boards are refused.
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'type' => 'scrum']);
        $foreign = Sprint::factory()->create(['board_id' => $otherBoard->id]);
        BrainitiveServer::actingAs($this->editor)->tool(GetSprintTool::class, $this->args(['sprint_id' => $foreign->id]))->assertHasErrors(['No sprint with that id']);
        $this->board->update(['type' => 'kanban']);
        BrainitiveServer::actingAs($this->editor)->tool(ListSprintsTool::class, $this->args())->assertHasErrors(['Kanban']);

        $this->assertSame(1, Sprint::where('board_id', $this->board->id)->count());
    }
}
