<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 Scrum (BRAI-408): sprints in planning order with counts / points, the backlog and a sprint's
 * issues in backlog order, moves through SprintLifecycle::moveIssues (subtasks follow; editors only; a
 * completed sprint or foreign ids → 422), burndown and velocity from SprintReport.
 */
class SprintsTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    private Board $scrum;

    private Sprint $active;

    private Sprint $planned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        $this->scrum = Board::factory()->create(['project_id' => $this->project->id, 'type' => 'scrum', 'name' => 'Scrum']);
        $this->active = Sprint::factory()->create(['board_id' => $this->scrum->id, 'name' => 'Sprint 1', 'status' => Sprint::STATUS_ACTIVE, 'position' => 1, 'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->addDays(11)->toDateString(), 'started_at' => now()->subDays(2)]);
        $this->planned = Sprint::factory()->create(['board_id' => $this->scrum->id, 'name' => 'Sprint 2', 'status' => Sprint::STATUS_PLANNED, 'position' => 2]);
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    private function issue(array $attributes = []): Item
    {
        return Item::factory()->create($attributes + ['board_id' => $this->scrum->id, 'created_by' => $this->editor->id]);
    }

    public function test_sprints_with_counts_and_points(): void
    {
        $this->issue(['sprint_id' => $this->active->id, 'story_points' => 3]);
        $done = $this->issue(['sprint_id' => $this->active->id, 'story_points' => 5, 'column_id' => $this->scrum->columns()->where('is_done_column', true)->value('id')]);
        $this->issue(['sprint_id' => $this->active->id, 'parent_id' => $done->id, 'story_points' => 8]); // a subtask: not counted
        $completed = Sprint::factory()->create(['board_id' => $this->scrum->id, 'name' => 'Sprint 0', 'status' => Sprint::STATUS_COMPLETED, 'position' => 0, 'completed_at' => now()->subDays(3), 'committed_points' => 10, 'completed_points' => 7, 'committed_issues' => 4, 'completed_issues' => 3]);

        $response = $this->getJson("/api/v1/boards/{$this->scrum->id}/sprints", $this->headers($this->viewer))->assertOk();
        $this->assertSame([$this->active->id, $this->planned->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.counts', ['issues' => 2, 'done' => 1])
            ->assertJsonPath('data.0.points', ['total' => 8, 'done' => 5])
            ->assertJsonPath('data.1.counts.issues', 0);

        $this->getJson("/api/v1/boards/{$this->scrum->id}/sprints?status=completed", $this->headers())->assertOk()
            ->assertJsonPath('data.0.id', $completed->id)->assertJsonPath('data.0.committed_points', 10);
        $this->getJson("/api/v1/sprints/{$this->active->id}", $this->headers())->assertOk()->assertJsonPath('data.name', 'Sprint 1');
        $this->getJson("/api/v1/boards/{$this->scrum->id}/sprints?status=soon", $this->headers())->assertUnprocessable();

        $velocity = $this->getJson("/api/v1/boards/{$this->scrum->id}/velocity", $this->headers())->assertOk();
        $this->assertSame([['sprint_id' => $completed->id, 'name' => 'Sprint 0', 'committed_points' => 10, 'completed_points' => 7, 'committed_issues' => 4, 'completed_issues' => 3]], $velocity->json('data'));
    }

    public function test_backlog_and_sprint_issues_in_backlog_order_and_burndown(): void
    {
        $b = $this->issue(['backlog_position' => 2, 'title' => 'B']);
        $a = $this->issue(['backlog_position' => 1, 'title' => 'A']);
        $this->issue(['backlog_position' => 3, 'parent_id' => $a->id]); // subtasks aren't listed
        $inSprint = $this->issue(['sprint_id' => $this->active->id, 'story_points' => 4]);

        $this->assertSame([$a->id, $b->id], array_column($this->getJson("/api/v1/boards/{$this->scrum->id}/backlog", $this->headers($this->viewer))->json('data'), 'id'));
        $this->assertSame([$inSprint->id], array_column($this->getJson("/api/v1/sprints/{$this->active->id}/items", $this->headers())->json('data'), 'id'));

        $burndown = $this->getJson("/api/v1/sprints/{$this->active->id}/burndown", $this->headers($this->viewer))->assertOk();
        $burndown->assertJsonPath('data.sprint_id', $this->active->id)->assertJsonPath('data.unit', 'points')->assertJsonPath('data.total', 4);
        $this->assertSame(4, $burndown->json('data.days.0.remaining'));
        $this->assertNull($burndown->json('data.days.12.remaining')); // a day still to come
        $this->assertCount(14, $burndown->json('data.days'));
    }

    public function test_editors_move_issues_between_sprints_and_the_backlog(): void
    {
        $issue = $this->issue();
        $sub = $this->issue(['parent_id' => $issue->id]);

        $this->postJson("/api/v1/sprints/{$this->planned->id}/items", ['item_ids' => [$issue->id]], $this->headers())
            ->assertOk()->assertJsonPath('data', ['moved' => 1, 'sprint_id' => $this->planned->id]);
        $this->assertSame($this->planned->id, $issue->fresh()->sprint_id);
        $this->assertSame($this->planned->id, $sub->fresh()->sprint_id); // the subtask follows

        // again: nothing to move; a retry with request_id answers the first result
        $requestId = '9b2d7c3e-1f4a-4e5b-8c6d-7e8f9a0b1c2d';
        $this->postJson("/api/v1/boards/{$this->scrum->id}/backlog/items", ['item_ids' => [$issue->id], 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data', ['moved' => 1, 'sprint_id' => null]);
        $this->postJson("/api/v1/boards/{$this->scrum->id}/backlog/items", ['item_ids' => [$issue->id], 'request_id' => $requestId], $this->headers())
            ->assertOk()->assertJsonPath('data.moved', 1);
        $this->assertNull($issue->fresh()->sprint_id);
        $this->assertNull($sub->fresh()->sprint_id);

        // a subtask, another board's issue, a completed sprint → 422, nothing moves
        $other = Item::factory()->create(['board_id' => $this->board->id]);
        $this->postJson("/api/v1/sprints/{$this->active->id}/items", ['item_ids' => [$issue->id, $sub->id]], $this->headers())->assertUnprocessable()->assertJsonValidationErrors('item_ids');
        $this->postJson("/api/v1/sprints/{$this->active->id}/items", ['item_ids' => [$other->id]], $this->headers())->assertUnprocessable();
        $this->assertNull($issue->fresh()->sprint_id);
        $closed = Sprint::factory()->create(['board_id' => $this->scrum->id, 'status' => Sprint::STATUS_COMPLETED]);
        $this->postJson("/api/v1/sprints/{$closed->id}/items", ['item_ids' => [$issue->id]], $this->headers())->assertUnprocessable();
        $this->postJson("/api/v1/boards/{$this->board->id}/backlog/items", ['item_ids' => [$other->id]], $this->headers())->assertUnprocessable(); // Kanban

        // viewers read only
        $this->postJson("/api/v1/sprints/{$this->active->id}/items", ['item_ids' => [$issue->id]], $this->headers($this->viewer))->assertForbidden();
    }

    public function test_foreign_boards_and_sprints_are_not_found(): void
    {
        $foreignBoard = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id, 'type' => 'scrum']);
        $foreignSprint = Sprint::factory()->create(['board_id' => $foreignBoard->id, 'status' => Sprint::STATUS_ACTIVE]);

        $this->getJson("/api/v1/boards/{$foreignBoard->id}/sprints", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/boards/{$foreignBoard->id}/backlog", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/boards/{$foreignBoard->id}/velocity", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/sprints/{$foreignSprint->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/sprints/{$foreignSprint->id}/items", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/sprints/{$foreignSprint->id}/burndown", $this->headers())->assertNotFound();
        $this->postJson("/api/v1/sprints/{$foreignSprint->id}/items", [], $this->headers())->assertNotFound();
        $this->postJson("/api/v1/boards/{$foreignBoard->id}/backlog/items", [], $this->headers())->assertNotFound();
        $this->postJson('/api/v1/sprints/abc/items', [], $this->headers())->assertNotFound();
        $this->getJson("/api/v1/sprints/{$this->active->id}", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/sprints/{$this->active->id}", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
