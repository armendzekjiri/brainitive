<?php

namespace Tests\Feature\Boards;

use App\Models\Item;
use App\Models\Sprint;
use App\Support\Boards\SprintReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SprintTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
        $this->board->update(['type' => 'scrum']);
    }

    /**
     * @param  array<int, mixed>  $extra
     */
    private function boardRoute(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, $this->board->slug, ...$extra]);
    }

    private function sprint(array $attributes = []): Sprint
    {
        return Sprint::factory()->create($attributes + ['board_id' => $this->board->id]);
    }

    public function test_sprints_are_created_with_a_numbered_default_name(): void
    {
        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.store'))->assertRedirect();
        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.store'), ['name' => 'Launch', 'goal' => 'Ship it'])->assertRedirect();

        $this->assertSame(['BRAI Sprint 1', 'Launch'], $this->board->sprints()->pluck('name')->all());
        $this->assertDatabaseHas('sprints', ['name' => 'Launch', 'goal' => 'Ship it', 'status' => 'planned']);
    }

    public function test_ranking_moves_issues_into_a_sprint_in_order_and_logs_the_sprint_change(): void
    {
        $sprint = $this->sprint(['name' => 'Sprint A']);
        $a = $this->item(['title' => 'A']);
        $b = $this->item(['title' => 'B']);
        $subtask = $this->item(['title' => 'B.1', 'parent_id' => $b->id]);

        $this->actingAs($this->editor)
            ->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => $sprint->id, 'item_ids' => [$b->id, $a->id]])
            ->assertRedirect();

        $this->assertSame([$b->id, $a->id], $sprint->items()->whereNull('parent_id')->orderBy('backlog_position')->pluck('id')->all());
        $this->assertSame($sprint->id, $subtask->fresh()->sprint_id);
        $entry = $a->activities()->first();
        $this->assertSame(['from' => null, 'to' => 'Sprint A'], $entry->changes['sprint_id']);

        // Re-ranking inside the same list doesn't add history
        $before = $a->activities()->count();
        $this->actingAs($this->editor)->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => $sprint->id, 'item_ids' => [$a->id, $b->id]]);
        $this->assertSame($before, $a->activities()->count());
        $this->assertSame(1, $a->fresh()->backlog_position);

        // Back to the backlog
        $this->actingAs($this->editor)->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => null, 'item_ids' => [$a->id]]);
        $this->assertNull($a->fresh()->sprint_id);
    }

    public function test_ranking_rejects_items_and_sprints_of_other_boards(): void
    {
        $otherBoard = \App\Models\Board::factory()->create(['project_id' => $this->project->id, 'created_by' => $this->editor->id]);
        $foreignItem = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id]);
        $foreignSprint = Sprint::factory()->create(['board_id' => $otherBoard->id]);

        $this->actingAs($this->editor)
            ->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => $foreignSprint->id, 'item_ids' => [$foreignItem->id]])
            ->assertSessionHasErrors(['sprint_id', 'item_ids.0']);
    }

    public function test_starting_a_sprint_snapshots_the_commitment(): void
    {
        $sprint = $this->sprint();
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 3]);
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 5]);

        $this->actingAs($this->editor)
            ->post($this->boardRoute('boards.sprints.start', [$sprint->id]), ['goal' => 'Checkout v2', 'start_date' => '2026-10-19', 'end_date' => '2026-11-02'])
            ->assertRedirect($this->boardRoute('boards.show'));

        $sprint->refresh();
        $this->assertTrue($sprint->isActive());
        $this->assertSame('Checkout v2', $sprint->goal);
        $this->assertSame('2026-11-02', $sprint->end_date->toDateString());
        $this->assertSame(8, $sprint->committed_points);
        $this->assertSame(2, $sprint->committed_issues);
    }

    public function test_only_one_sprint_can_run_and_empty_sprints_cannot_start(): void
    {
        $running = $this->sprint();
        $this->item(['sprint_id' => $running->id]);
        $running->start();
        $next = $this->sprint();
        $this->item(['sprint_id' => $next->id]);
        $empty = $this->sprint();

        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.start', [$next->id]))->assertSessionHasErrors('sprint');
        $running->complete();
        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.start', [$empty->id]))->assertSessionHasErrors('sprint');
        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.start', [$next->id]))->assertRedirect();

        $this->assertTrue($next->fresh()->isActive());
    }

    public function test_completing_moves_open_issues_with_subtasks_to_the_next_sprint(): void
    {
        $sprint = $this->sprint();
        $next = $this->sprint(['name' => 'Next']);
        $done = $this->item(['sprint_id' => $sprint->id, 'story_points' => 5, 'column_id' => $this->column('done'), 'completed_at' => now()]);
        $open = $this->item(['sprint_id' => $sprint->id, 'story_points' => 3]);
        $openSubtask = $this->item(['sprint_id' => $sprint->id, 'parent_id' => $open->id]);
        $sprint->start();

        $this->actingAs($this->editor)
            ->post($this->boardRoute('boards.sprints.complete', [$sprint->id]), ['move_open_to' => $next->id])
            ->assertRedirect($this->boardRoute('boards.reports', ['sprint' => $sprint->id]))
            ->assertSessionHas('success', "{$sprint->name} completed — 1 open issue(s) moved to Next.");

        $sprint->refresh();
        $this->assertTrue($sprint->isCompleted());
        $this->assertSame(5, $sprint->completed_points);
        $this->assertSame(1, $sprint->completed_issues);
        $this->assertSame(8, $sprint->committed_points);
        $this->assertIsArray($sprint->burndown);
        $this->assertSame($sprint->id, $done->fresh()->sprint_id);
        $this->assertSame($next->id, $open->fresh()->sprint_id);
        $this->assertSame($next->id, $openSubtask->fresh()->sprint_id);
    }

    public function test_completing_to_the_backlog_and_lifecycle_guards(): void
    {
        $sprint = $this->sprint();
        $open = $this->item(['sprint_id' => $sprint->id]);

        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.complete', [$sprint->id]))->assertSessionHasErrors('sprint');

        $sprint->start();
        $this->actingAs($this->editor)->delete($this->boardRoute('boards.sprints.destroy', [$sprint->id]))->assertSessionHasErrors('sprint');
        $this->actingAs($this->editor)->post($this->boardRoute('boards.sprints.complete', [$sprint->id]))->assertRedirect();

        $this->assertNull($open->fresh()->sprint_id);
        $this->actingAs($this->editor)->patch($this->boardRoute('boards.sprints.update', [$sprint->id]), ['name' => 'Rewrite'])->assertSessionHasErrors('sprint');
    }

    public function test_deleting_a_planned_sprint_returns_its_issues_to_the_backlog(): void
    {
        $sprint = $this->sprint();
        $item = $this->item(['sprint_id' => $sprint->id]);

        $this->actingAs($this->editor)->delete($this->boardRoute('boards.sprints.destroy', [$sprint->id]))->assertRedirect();

        $this->assertDatabaseMissing('sprints', ['id' => $sprint->id]);
        $this->assertNull($item->fresh()->sprint_id);
    }

    public function test_scrum_board_shows_only_the_active_sprint_and_kanban_boards_show_everything(): void
    {
        $sprint = $this->sprint();
        $inSprint = $this->item(['title' => 'In sprint', 'sprint_id' => $sprint->id]);
        $this->item(['title' => 'In backlog']);

        $this->actingAs($this->viewer)->get($this->boardRoute('boards.show'))
            ->assertInertia(fn (Assert $page) => $page->has('board.columns.0.items', 0)->where('activeSprint', null));

        $sprint->start();

        $this->actingAs($this->viewer)->get($this->boardRoute('boards.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('board.columns.0.items', 1)
                ->where('board.columns.0.items.0.id', $inSprint->id)
                ->where('activeSprint.id', $sprint->id));

        $this->board->update(['type' => 'kanban']);
        $this->actingAs($this->viewer)->get($this->boardRoute('boards.show'))
            ->assertInertia(fn (Assert $page) => $page->has('board.columns.0.items', 2));
    }

    public function test_new_cards_join_the_active_sprint_unless_told_otherwise(): void
    {
        $sprint = $this->sprint();
        $this->item(['sprint_id' => $sprint->id]);
        $sprint->start();
        $store = $this->boardRoute('boards.items.store');

        $this->actingAs($this->editor)->post($store, ['title' => 'From board', 'column_id' => $this->column('to-do')]);
        $this->actingAs($this->editor)->post($store, ['title' => 'From backlog', 'column_id' => $this->column('to-do'), 'sprint_id' => null]);

        $this->assertSame($sprint->id, Item::where('title', 'From board')->value('sprint_id'));
        $this->assertNull(Item::where('title', 'From backlog')->value('sprint_id'));
    }

    public function test_sprints_cannot_be_switched_off_while_one_is_running(): void
    {
        $sprint = $this->sprint();
        $this->item(['sprint_id' => $sprint->id]);
        $sprint->start();

        $this->actingAs($this->editor)
            ->put(route('boards.update', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['name' => $this->board->name, 'type' => 'kanban'])
            ->assertSessionHasErrors('type');

        $this->assertTrue($this->board->fresh()->isScrum());
    }

    public function test_viewers_can_see_backlog_and_reports_but_not_plan(): void
    {
        $sprint = $this->sprint();
        $item = $this->item();

        $this->actingAs($this->viewer)->get($this->boardRoute('boards.backlog'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Boards/Backlog')
                ->has('sprints', 1)
                ->has('backlogItems', 1)
                ->where('canManage', false));
        $this->actingAs($this->viewer)->get($this->boardRoute('boards.reports'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Boards/Reports'));

        $this->actingAs($this->viewer)->post($this->boardRoute('boards.sprints.store'))->assertForbidden();
        $this->actingAs($this->viewer)->post($this->boardRoute('boards.backlog.rank'), ['sprint_id' => $sprint->id, 'item_ids' => [$item->id]])->assertForbidden();
        $this->actingAs($this->viewer)->post($this->boardRoute('boards.sprints.start', [$sprint->id]))->assertForbidden();
        $this->actingAs($this->outsider)->get($this->boardRoute('boards.backlog'))->assertForbidden();
    }

    public function test_burndown_counts_remaining_points_per_day(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $sprint = $this->sprint();
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 5, 'completed_at' => '2026-10-12 15:00:00']);
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 3, 'completed_at' => '2026-10-14 09:00:00']);
        $this->item(['sprint_id' => $sprint->id, 'story_points' => 2]);
        $sprint->start('2026-10-10', '2026-10-20');

        $report = SprintReport::burndown($sprint->fresh());

        $this->assertSame('points', $report['unit']);
        $this->assertSame(10, $report['total']);
        $this->assertCount(11, $report['days']);
        $byDate = collect($report['days'])->keyBy('date');
        $this->assertSame(10, $byDate['2026-10-11']['remaining']);
        $this->assertSame(5, $byDate['2026-10-12']['remaining']);
        $this->assertSame(2, $byDate['2026-10-14']['remaining']);
        $this->assertNull($byDate['2026-10-15']['remaining']);
        $this->assertSame(10.0, (float) $byDate['2026-10-10']['ideal']);
        $this->assertSame(0.0, (float) $byDate['2026-10-20']['ideal']);
        Carbon::setTestNow();
    }

    public function test_burndown_falls_back_to_issue_count_and_velocity_lists_completed_sprints(): void
    {
        $first = $this->sprint(['name' => 'S1']);
        $this->item(['sprint_id' => $first->id, 'completed_at' => now()]);
        $this->item(['sprint_id' => $first->id]);
        $first->start();
        $this->assertSame(['unit' => 'issues', 'total' => 2], array_intersect_key(SprintReport::burndown($first), ['unit' => 1, 'total' => 1]));
        $first->complete();

        $second = $this->sprint(['name' => 'S2']);
        $this->item(['sprint_id' => $second->id, 'story_points' => 8, 'completed_at' => now()]);
        $second->start();
        $second->complete();

        $velocity = SprintReport::velocity($this->board);
        $this->assertSame(['S1', 'S2'], array_column($velocity, 'name'));
        $this->assertSame([1, 1], array_column($velocity, 'completed_issues'));
        $this->assertSame([0, 8], array_column($velocity, 'completed_points'));

        $this->actingAs($this->viewer)->get($this->boardRoute('boards.reports', ['sprint' => $first->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedSprint.name', 'S1')
                ->where('burndown.unit', 'issues')
                ->has('velocity', 2));
    }
}
