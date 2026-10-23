<?php

namespace Tests\Feature\Boards;

use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ItemHistoryTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    /**
     * @return array<int, array{action: string, changes: mixed, metadata: mixed, user_id: int|null}>
     */
    private function history(Item $item): array
    {
        return $item->activities()->reorder('id')->get(['action', 'changes', 'metadata', 'user_id'])->toArray();
    }

    public function test_creating_an_item_through_the_board_is_logged(): void
    {
        $this->actingAs($this->editor)
            ->post(route('boards.items.store', [$this->workspace->slug, $this->project->slug, $this->board->slug]), [
                'title' => 'Fix login',
                'column_id' => $this->column('to-do'),
            ])
            ->assertRedirect();

        $item = Item::where('title', 'Fix login')->firstOrFail();
        $this->assertSame([['action' => 'created', 'changes' => null, 'metadata' => ['key' => $item->key], 'user_id' => $this->editor->id]], $this->history($item));
    }

    public function test_field_changes_are_logged_with_readable_values(): void
    {
        $item = $this->item(['title' => 'Old title']);
        $priority = ItemPriority::create(['name' => 'Highest', 'slug' => 'highest', 'color' => '#f00', 'order' => 1]);

        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.update', $item), [
                'title' => 'New title',
                'priority_id' => $priority->id,
                'due_date' => '2026-11-01',
                'description' => '<p>Long text</p>',
                'original_estimate' => '1d 2h',
            ])
            ->assertRedirect();

        $entry = collect($this->history($item))->last();
        $this->assertSame('updated', $entry['action']);
        $this->assertSame(['from' => 'Old title', 'to' => 'New title'], $entry['changes']['title']);
        $this->assertSame(['from' => null, 'to' => 'Highest'], $entry['changes']['priority_id']);
        $this->assertSame(['from' => null, 'to' => '2026-11-01'], $entry['changes']['due_date']);
        $this->assertSame(['changed' => true], $entry['changes']['description']);
        $this->assertSame(['from' => null, 'to' => '1d 2h'], $entry['changes']['original_estimate']);
        $this->assertSame(600, $item->fresh()->original_estimate);
    }

    public function test_saving_without_changes_logs_nothing(): void
    {
        $item = $this->item(['title' => 'Same']);

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['title' => 'Same']);

        $this->assertSame(['created'], array_column($this->history($item), 'action'));
    }

    public function test_moving_between_columns_is_logged_as_a_status_change(): void
    {
        $item = $this->item();

        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('in-progress'), 'position' => 0])
            ->assertRedirect();

        $entry = collect($this->history($item))->last();
        $this->assertSame('moved', $entry['action']);
        $this->assertSame(['from' => 'To Do', 'to' => 'In Progress'], $entry['changes']['column_id']);
    }

    public function test_reordering_inside_a_column_is_not_logged(): void
    {
        $item = $this->item();

        $this->actingAs($this->editor)
            ->patch($this->itemRoute('boards.items.move', $item), ['column_id' => $this->column('to-do'), 'position' => 3]);

        $this->assertSame(['created'], array_column($this->history($item), 'action'));
    }

    public function test_assigning_and_unassigning_are_logged(): void
    {
        $item = $this->item();

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['assigned_to' => $this->viewer->id]);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.update', $item), ['assigned_to' => null]);

        $history = collect($this->history($item));
        $this->assertSame(['created', 'assigned', 'unassigned'], $history->pluck('action')->all());
        $this->assertSame(['from' => null, 'to' => $this->viewer->name], $history[1]['changes']['assigned_to']);
        $this->assertSame(['from' => $this->viewer->name, 'to' => null], $history[2]['changes']['assigned_to']);
    }

    public function test_label_comment_link_and_attachment_changes_are_logged(): void
    {
        $item = $this->item();
        $other = $this->item(['title' => 'Other']);
        $bug = $this->project->labels()->create(['name' => 'bug', 'color' => '#f00']);

        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.labels', $item), ['labels' => [$bug->id]]);
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.comments.store', $item), ['body' => '<p>Looks <b>good</b></p>']);
        $this->actingAs($this->editor)->post($this->itemRoute('boards.items.links.store', $item), ['target_item_id' => $other->id, 'link_type' => 'blocks']);
        $this->actingAs($this->editor)->patch($this->itemRoute('boards.items.labels', $item), ['labels' => []]);

        $history = collect($this->history($item));
        $this->assertSame(['created', 'labels_changed', 'commented', 'linked', 'labels_changed'], $history->pluck('action')->all());
        $this->assertSame(['labels' => ['added' => ['bug'], 'removed' => []]], $history[1]['changes']);
        $this->assertSame('Looks good', $history[2]['metadata']['excerpt']);
        $this->assertSame(['link_type' => 'blocks', 'item' => $other->key], $history[3]['metadata']);
        $this->assertSame(['labels' => ['added' => [], 'removed' => ['bug']]], $history[4]['changes']);
    }

    public function test_logging_work_adds_to_time_spent_and_history(): void
    {
        $item = $this->item();

        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1h 30m', 'note' => 'Debugging'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Work logged: 1h 30m.');
        $this->actingAs($this->editor)
            ->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1d'])
            ->assertRedirect();

        $this->assertSame(90 + 480, $item->fresh()->time_spent);
        $entry = collect($this->history($item))->last();
        $this->assertSame('work_logged', $entry['action']);
        $this->assertSame(['minutes' => 480, 'total_minutes' => 570, 'note' => null], $entry['metadata']);
    }

    public function test_invalid_work_logs_are_rejected(): void
    {
        $item = $this->item();

        foreach (['', 'soon', '0m', '2w'] as $time) {
            $this->actingAs($this->editor)
                ->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => $time])
                ->assertSessionHasErrors('time');
        }

        $this->actingAs($this->viewer)
            ->post($this->itemRoute('boards.items.work-logs.store', $item), ['time' => '1h'])
            ->assertForbidden();

        $this->assertSame(0, $item->fresh()->time_spent);
    }

    public function test_durations_are_parsed_and_formatted_like_jira(): void
    {
        $this->assertSame(90, Item::parseDuration('1h 30m'));
        $this->assertSame(90, Item::parseDuration('1.5h'));
        $this->assertSame(90, Item::parseDuration('1,5h'));
        $this->assertSame(45, Item::parseDuration('45'));
        $this->assertSame(2400 + 480 + 60, Item::parseDuration('1w 1d 1h'));
        $this->assertNull(Item::parseDuration('tomorrow'));
        $this->assertNull(Item::parseDuration('1x'));

        $this->assertSame('1w 1d 1h', Item::formatDuration(2400 + 480 + 60));
        $this->assertSame('2h 5m', Item::formatDuration(125));
        $this->assertSame('0m', Item::formatDuration(0));
    }

    public function test_history_is_written_for_changes_outside_controllers_too(): void
    {
        $item = $this->item(['title' => 'Before']);
        $this->actingAs($this->editor);

        $item->update(['title' => 'After']);

        $this->assertSame(ActivityLog::count(), 2);
        $this->assertSame(['from' => 'Before', 'to' => 'After'], collect($this->history($item))->last()['changes']['title']);
    }

    public function test_member_profile_only_shows_activity_from_its_own_workspace(): void
    {
        $item = $this->item();
        $this->actingAs($this->editor);
        $item->update(['title' => 'Visible here']);

        $otherWorkspace = Workspace::factory()->create();
        $otherWorkspace->addUser($this->editor, 'admin');
        $otherProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_by' => $this->editor->id]);
        $otherBoard = Board::factory()->create(['project_id' => $otherProject->id, 'created_by' => $this->editor->id]);
        $secret = Item::factory()->create(['board_id' => $otherBoard->id, 'created_by' => $this->editor->id]);
        $secret->update(['title' => 'Secret elsewhere']);

        $this->actingAs($this->viewer)
            ->get(route('workspaces.members.profile', [$this->workspace->slug, $this->editor->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activityLogs', 1)
                ->where('activityLogs.0.loggable_id', $item->id));
    }
}
