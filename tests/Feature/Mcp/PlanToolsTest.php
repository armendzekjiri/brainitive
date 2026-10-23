<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\GetPlanTool;
use App\Mcp\Tools\ListPlansTool;
use App\Mcp\Tools\UpdatePlanTool;
use App\Models\ActivityLog;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

class PlanToolsTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->plan = Plan::factory()->shared()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id, 'slug' => 'launch']);
    }

    private function args(array $extra = []): array
    {
        return ['workspace' => $this->workspace->slug, 'project' => $this->project->slug, 'plan' => 'launch'] + $extra;
    }

    private function update(array $ops, string $requestId, ?int $revision = null)
    {
        return BrainitiveServer::actingAs($this->editor)->tool(UpdatePlanTool::class, $this->args([
            'expected_revision' => $revision ?? (int) $this->plan->fresh()->edit_revision,
            'request_id' => $requestId,
            'operations' => $ops,
        ]));
    }

    public function test_read_plans_without_share_tokens(): void
    {
        BrainitiveServer::actingAs($this->viewer)->tool(ListPlansTool::class, ['workspace' => $this->workspace->slug, 'project' => $this->project->slug])
            ->assertOk()->assertSee('launch')->assertDontSee($this->plan->share_token);
        BrainitiveServer::actingAs($this->viewer)->tool(GetPlanTool::class, $this->args())
            ->assertOk()->assertSee(['Design', 'b1', 'd1'])->assertDontSee($this->plan->share_token);
        BrainitiveServer::actingAs($this->outsider)->tool(GetPlanTool::class, $this->args())->assertHasErrors();
    }

    public function test_operations_apply_all_or_nothing(): void
    {
        $before = $this->plan->data;
        $revision = (int) $this->plan->edit_revision;

        $this->update([
            ['op' => 'add_row', 'row_id' => 'r5', 'after_row_id' => 'r3', 'name' => 'QA', 'level' => 1],
            ['op' => 'add_bar', 'row_id' => 'r5', 'bar_id' => 'b9', 'start' => '2026-12-01', 'end' => '2026-12-04'],
            ['op' => 'add_dependency', 'from_bar_id' => 'b2', 'to_bar_id' => 'b9'],
            ['op' => 'update_row', 'row_id' => 'r2', 'status' => 'done'],
        ], 'ok-1')->assertOk()->assertStructuredContent(fn (AssertableJson $j) => $j->where('applied', true)->where('edit_revision', $revision + 1)->etc());

        $data = $this->plan->fresh()->data;
        $this->assertSame(['r1', 'r2', 'r3', 'r5', 'r4'], array_column($data['rows'], 'id'));
        $this->assertContains(['id' => $data['dependencies'][1]['id'], 'from' => 'b2', 'to' => 'b9'], $data['dependencies']);

        // A cycle (b9 → b1 while b1 → b2 → b9) cancels the whole batch, including the valid rename.
        $this->update([
            ['op' => 'update_row', 'row_id' => 'r1', 'name' => 'Renamed'],
            ['op' => 'add_dependency', 'from_bar_id' => 'b9', 'to_bar_id' => 'b1'],
        ], 'cycle')->assertHasErrors(['Operation #2', 'cycle']);
        $this->assertSame('Phase 1', $this->plan->fresh()->data['rows'][0]['name']);

        $this->update([['op' => 'remove_bar', 'bar_id' => 'nope']], 'bad')->assertHasErrors(['no bar with id']);
        $this->update([['op' => 'add_bar', 'row_id' => 'r1', 'start' => '2026-11-10', 'end' => '2026-11-01']], 'dates')->assertOk();
        $bar = collect($this->plan->fresh()->data['rows'][0]['bars'])->first();
        $this->assertSame(['2026-11-01', '2026-11-10'], [$bar['start'], $bar['end']], 'end before start is repaired like in the editor');
        $this->assertNotSame($before, $this->plan->fresh()->data);
    }

    public function test_stale_revisions_change_nothing_and_retries_apply_once(): void
    {
        $stale = (int) $this->plan->edit_revision;
        // Someone saves in the browser meanwhile.
        $this->plan->saveDocument($this->editor, $this->plan->data, $this->plan->settings ?? []);
        $data = $this->plan->fresh()->data;

        $this->update([['op' => 'update_row', 'row_id' => 'r1', 'name' => 'Stale write']], 'stale', $stale)->assertHasErrors(['Conflict']);
        $this->assertSame($data, $this->plan->fresh()->data);

        $ops = [['op' => 'update_row', 'row_id' => 'r1', 'name' => 'Kickoff']];
        $revision = (int) $this->plan->fresh()->edit_revision;
        $this->update($ops, 'once', $revision)->assertStructuredContent(fn (AssertableJson $j) => $j->where('applied', true)->etc());
        $this->update($ops, 'once', $revision)->assertStructuredContent(fn (AssertableJson $j) => $j->where('applied', false)->etc());
        $this->assertSame($revision + 1, (int) $this->plan->fresh()->edit_revision);

        $this->update([['op' => 'update_row', 'row_id' => 'r1', 'name' => 'Other']], 'once', $revision)->assertHasErrors(['already used']);
    }

    public function test_bar_dates_write_through_to_linked_issues_once(): void
    {
        $item = $this->item(['title' => 'Design']);
        $data = $this->plan->data;
        $data['rows'][1]['itemId'] = $item->id;
        $this->plan->update(['data' => $data]);

        $this->update([['op' => 'update_bar', 'bar_id' => 'b1', 'start' => '2026-11-09', 'end' => '2026-11-20']], 'dates')
            ->assertStructuredContent(fn (AssertableJson $j) => $j->where('changed_linked_issues', 1)->etc());

        $this->assertSame('2026-11-20', $item->fresh()->due_date->toDateString());
        $this->assertSame('2026-11-09', $item->fresh()->start_date->toDateString());
        $this->assertSame(1, ActivityLog::where('loggable_id', $item->id)->where('loggable_type', $item->getMorphClass())->where('action', '!=', 'created')->count());

        // Viewers can read but not edit.
        BrainitiveServer::actingAs($this->viewer)->tool(UpdatePlanTool::class, $this->args(['expected_revision' => 0, 'request_id' => 'v', 'operations' => [['op' => 'remove_row', 'row_id' => 'r1']]]))->assertHasErrors();
    }
}
