<?php

namespace Tests\Feature\AppApi;

use App\Models\Board;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AppApi\Concerns\ActsAsAppClient;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * App API v1 timeline / calendar issues (BRAI-409): a project's dated issues whose start … due touch a range,
 * across its boards, sorted by their first day, cursor-paginated; rescheduling through PATCH /items/{id}.
 */
class DatedIssuesTest extends TestCase
{
    use ActsAsAppClient;
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
    }

    private function headers(?User $user = null): array
    {
        return $this->appHeaders($this->appToken($user ?? $this->editor));
    }

    /** @return list<int> */
    private function ids(string $query, ?User $user = null): array
    {
        return array_column($this->getJson("/api/v1/projects/{$this->project->id}/items?{$query}", $this->headers($user))->assertOk()->json('data'), 'id');
    }

    public function test_issues_whose_dates_touch_the_range_sorted_by_first_day(): void
    {
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $span = $this->item(['start_date' => '2026-11-01', 'due_date' => '2026-11-20']);         // covers the range
        $due = Item::factory()->create(['board_id' => $other->id, 'due_date' => '2026-11-12']);  // another board
        $start = $this->item(['start_date' => '2026-11-14']);                                    // start only = that day
        $this->item(['due_date' => '2026-11-30']);                                               // after
        $this->item(['start_date' => '2026-10-01', 'due_date' => '2026-10-09']);                 // before
        $this->item();                                                                           // undated
        $done = $this->item(['due_date' => '2026-11-11', 'column_id' => $this->column('done')]);

        $this->assertSame([$span->id, $done->id, $due->id, $start->id], $this->ids('from=2026-11-10&to=2026-11-15', $this->viewer));
        $this->assertSame([$span->id, $start->id], $this->ids("from=2026-11-10&to=2026-11-15&board={$this->board->id}&status=open"));
        $this->assertSame([$done->id], $this->ids('from=2026-11-10&to=2026-11-15&status=done'));

        // cursor pages keep the order
        $page = $this->getJson("/api/v1/projects/{$this->project->id}/items?from=2026-11-10&to=2026-11-15&limit=3", $this->headers())->assertOk();
        $this->assertSame([$span->id, $done->id, $due->id], array_column($page->json('data'), 'id'));
        $rest = $this->getJson("/api/v1/projects/{$this->project->id}/items?from=2026-11-10&to=2026-11-15&limit=3&cursor=".urlencode($page->json('next_cursor')), $this->headers())->assertOk();
        $this->assertSame([$start->id], array_column($rest->json('data'), 'id'));
    }

    public function test_reschedule_with_patch_and_the_rules(): void
    {
        $item = $this->item(['due_date' => '2026-11-12']);
        $this->patchJson("/api/v1/items/{$item->id}", ['start_date' => '2026-11-20', 'due_date' => '2026-11-22', 'due_time' => '15:00'], $this->headers())->assertOk()
            ->assertJsonPath('data.start_date', '2026-11-20')->assertJsonPath('data.due_time', '15:00');
        $this->assertSame([], $this->ids('from=2026-11-10&to=2026-11-15'));
        $this->assertSame([$item->id], $this->ids('from=2026-11-21&to=2026-11-21'));

        $foreignBoard = Board::factory()->create(['project_id' => Project::factory()->create(['workspace_id' => Workspace::factory()->create()->id])->id]);
        $this->getJson("/api/v1/projects/{$this->project->id}/items?board={$foreignBoard->id}", $this->headers())->assertUnprocessable()->assertJsonValidationErrors('board');
        $this->getJson("/api/v1/projects/{$this->project->id}/items?from=2026-11-10&to=2026-11-01", $this->headers())->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/v1/projects/{$this->project->id}/items?from=2026-01-01&to=2027-06-01", $this->headers())->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/v1/projects/{$foreignBoard->project_id}/items", $this->headers())->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/items", $this->headers($this->outsider))->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->project->id}/items", $this->appHeaders($this->passportToken($this->editor)))->assertUnauthorized();
    }
}
