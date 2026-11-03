<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/** App API v1 Gantt plans and releases, read-only (BRAI-415): PlanReader (shared with the MCP), the web's release list. */
class PlansReleasesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->viewer));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    public function test_plans_list_and_one_plan_with_rows_bars_dependencies_and_visible_issues(): void
    {
        $item = $this->item(['title' => 'Build login']);
        $otherWorkspace = Workspace::factory()->create();
        $foreignBoard = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => $otherWorkspace->id])->id]);
        $foreign = Item::factory()->create(['board_id' => $foreignBoard->id, 'title' => 'Secret']);
        $plan = Plan::factory()->create(['project_id' => $this->project->id, 'name' => 'Roadmap', 'position' => 1, 'data' => [
            'rows' => [
                ['id' => 'r1', 'name' => 'Phase 1', 'level' => 0, 'who' => 'Ann', 'status' => 'on_track', 'bars' => [
                    ['id' => 'b1', 'start' => '2026-11-02', 'end' => '2026-11-10', 'type' => 'task', 'color' => '#3B82F6', 'progress' => 40],
                ]],
                ['id' => 'r2', 'name' => 'Login', 'level' => 1, 'itemId' => $item->id, 'bars' => [
                    ['id' => 'b2', 'start' => '2026-11-12', 'end' => '2026-11-12', 'type' => 'milestone', 'color' => '#10B981', 'label' => 'Go live'],
                ]],
                ['id' => 'r3', 'name' => 'Leak?', 'level' => 1, 'itemId' => $foreign->id, 'bars' => []],
            ],
            'dependencies' => [['id' => 'd1', 'from' => 'b1', 'to' => 'b2']],
        ]]);
        Plan::factory()->create(['project_id' => $this->project->id, 'name' => 'Second', 'position' => 2]);

        $list = $this->getJson("/api/v1/projects/{$this->project->id}/plans?limit=1", $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.rows_count', 3)
            ->assertJsonPath('data.0.start', '2026-11-02')
            ->assertJsonPath('data.0.end', '2026-11-12');
        $this->getJson("/api/v1/projects/{$this->project->id}/plans?limit=1&cursor=".urlencode($list->json('next_cursor')), $this->headers())
            ->assertOk()->assertJsonPath('data.0.name', 'Second')->assertJsonPath('next_cursor', null);

        $this->getJson("/api/v1/plans/{$plan->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.rows.0.who', 'Ann')
            ->assertJsonPath('data.rows.0.bars.0.progress', 40)
            ->assertJsonPath('data.rows.1.level', 1)
            ->assertJsonPath('data.rows.1.issue.key', $item->key)
            ->assertJsonPath('data.rows.1.bars.0.type', 'milestone')
            ->assertJsonPath('data.rows.1.bars.0.label', 'Go live')
            ->assertJsonPath('data.rows.2.issue', null) // an issue the user can't open is never named
            ->assertJsonPath('data.dependencies.0', ['id' => 'd1', 'from' => 'b1', 'to' => 'b2'])
            ->assertJsonPath('data.can.update', false);
        $this->getJson("/api/v1/plans/{$plan->id}", $this->headers($this->editor))->assertJsonPath('data.can.update', true);

        $this->getJson("/api/v1/plans/{$plan->id}", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/plans", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/plans/{$plan->id}")->assertUnauthorized();
        $this->getJson("/api/v1/plans/{$plan->id}", $this->appHeaders($this->passportToken($this->viewer)))->assertUnauthorized();
        $this->getJson('/api/v1/plans/999999', $this->headers())->assertNotFound();
    }

    public function test_releases_in_the_web_order_with_progress_notes_and_issues(): void
    {
        $shipped = Release::create(['project_id' => $this->project->id, 'name' => '1.0', 'status' => Release::STATUS_RELEASED, 'released_at' => now()]);
        $next = Release::create(['project_id' => $this->project->id, 'name' => '1.1', 'status' => Release::STATUS_UNRELEASED, 'release_date' => '2026-12-01',
            'notes' => '<h2>New</h2><p>Faster <strong>login</strong></p>', 'description' => 'Small things']);
        Release::create(['project_id' => $this->project->id, 'name' => '0.1', 'status' => Release::STATUS_ARCHIVED]);
        $done = $this->item(['release_id' => $next->id, 'title' => 'Done one', 'column_id' => $this->column('done'), 'completed_at' => now()]);
        $this->item(['release_id' => $next->id, 'title' => 'Open one', 'column_id' => $this->column('to-do')]);

        $this->getJson("/api/v1/projects/{$this->project->id}/releases", $this->headers())->assertOk()
            ->assertJsonPath('data.*.name', ['1.1', '1.0', '0.1'])
            ->assertJsonPath('data.0.progress', ['total' => 2, 'done' => 1, 'in_progress' => 0, 'todo' => 1])
            ->assertJsonPath('data.1.status', 'released');
        $this->getJson("/api/v1/projects/{$this->project->id}/releases?status=unreleased,archived", $this->headers())->assertOk()
            ->assertJsonPath('data.*.name', ['1.1', '0.1']);
        $this->getJson("/api/v1/projects/{$this->project->id}/releases?status=shipped", $this->headers())->assertUnprocessable();

        $this->getJson("/api/v1/releases/{$next->id}", $this->headers())->assertOk()
            ->assertJsonPath('data.description', 'Small things')
            ->assertJsonPath('data.notes_markdown', "## New\n\nFaster **login**")
            ->assertJsonPath('data.published_notes_markdown', null)
            ->assertJsonPath('data.public_url', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $done->id)
            ->assertJsonPath('data.items.0.state', 'done')
            ->assertJsonPath('data.can.manage', false);
        $this->getJson("/api/v1/releases/{$shipped->id}", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/releases", $this->headers($this->outsider))->assertNotFound();
    }
}
