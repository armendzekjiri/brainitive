<?php

namespace Tests\Feature\Boards;

use App\Models\Board;
use App\Models\Item;
use App\Models\ItemPriority;
use App\Models\ItemType;
use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\ItemStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BRAI-108: multi-select + bulk edit in the board's list view.
 */
class BulkItemEditTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        Notification::fake();
    }

    private function bulkUrl(?Board $board = null): string
    {
        return route('boards.items.bulk.update', [$this->workspace->slug, $this->project->slug, ($board ?? $this->board)->slug]);
    }

    /**
     * @param  list<Item>  $items
     * @param  array<string, mixed>  $changes
     */
    private function bulk(array $items, array $changes, $user = null)
    {
        return $this->actingAs($user ?? $this->editor)
            ->from(route('boards.show', [$this->workspace->slug, $this->project->slug, $this->board->slug]))
            ->patch($this->bulkUrl(), ['item_ids' => array_map(fn (Item $i) => $i->id, $items), 'changes' => $changes]);
    }

    private function label(string $name, ?Project $project = null): Label
    {
        return Label::create(['project_id' => ($project ?? $this->project)->id, 'name' => $name, 'slug' => $name, 'color' => '#22c55e']);
    }

    /** @return list<string> */
    private function actions(Item $item): array
    {
        return $item->activities()->reorder('id')->pluck('action')->all();
    }

    public function test_move_to_done_sets_completed_at_and_logs_one_history_entry_per_issue(): void
    {
        [$a, $b] = [$this->item(), $this->item()];

        $this->bulk([$a, $b], ['column_id' => $this->column('done')])
            ->assertRedirect()
            ->assertSessionHas('success', 'Updated 2 issues.');

        foreach ([$a, $b] as $item) {
            $item->refresh();
            $this->assertSame($this->column('done'), $item->column_id);
            $this->assertNotNull($item->completed_at);
            $this->assertSame(['created', 'moved'], $this->actions($item));
        }

        // back out of done clears the date
        $this->bulk([$a], ['column_id' => $this->column('to-do')])->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->completed_at);
    }

    public function test_assignee_priority_type_and_due_date_in_one_save(): void
    {
        $item = $this->item(['due_time' => '09:00']);
        $priority = ItemPriority::create(['name' => 'Highest', 'slug' => 'highest', 'color' => '#f00', 'order' => 1]);
        $type = ItemType::create(['name' => 'Bug', 'slug' => 'bug-bulk', 'is_active' => true]);

        $this->bulk([$item], [
            'assigned_to' => $this->editor->id,
            'priority_id' => $priority->id,
            'item_type_id' => $type->id,
            'due_date' => '2026-12-01',
        ])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($this->editor->id, $item->assigned_to);
        $this->assertSame($priority->id, $item->priority_id);
        $this->assertSame($type->id, $item->item_type_id);
        $this->assertSame('2026-12-01', $item->due_date->toDateString());
        $this->assertSame(['created', 'updated'], $this->actions($item));

        // clear the due date (and its time) + unassign
        $this->bulk([$item], ['due_date' => null, 'assigned_to' => null])->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertNull($item->due_date);
        $this->assertNull($item->due_time);
        $this->assertNull($item->assigned_to);
    }

    public function test_assignment_notifies_each_new_assignee_once_per_issue_but_not_the_actor(): void
    {
        $member = $this->projectMember('editor');
        [$a, $b] = [$this->item(), $this->item(['assigned_to' => $member->id])];

        $this->bulk([$a, $b], ['assigned_to' => $member->id, 'priority_id' => ItemPriority::create(['name' => 'Low', 'slug' => 'low', 'color' => '#0f0', 'order' => 4])->id])
            ->assertSessionHasNoErrors();

        // $b was already assigned to them: only $a is new
        Notification::assertSentToTimes($member, ItemAssignedNotification::class, 1);
        Notification::assertSentTo($member, ItemAssignedNotification::class, fn ($n) => $n->item->is($a));

        $this->bulk([$a], ['assigned_to' => $this->editor->id]);
        Notification::assertNotSentTo($this->editor, ItemAssignedNotification::class);
    }

    public function test_status_change_notifies_watchers_except_the_actor(): void
    {
        $watcher = $this->projectMember('viewer');
        $item = $this->item();
        $item->addWatcher($watcher);
        $item->addWatcher($this->editor);

        $this->bulk([$item], ['column_id' => $this->column('in-progress')])->assertSessionHasNoErrors();

        Notification::assertSentToTimes($watcher, ItemStatusChangedNotification::class, 1);
        Notification::assertNotSentTo($this->editor, ItemStatusChangedNotification::class);
    }

    public function test_labels_are_added_and_removed_with_history(): void
    {
        $ui = $this->label('ui');
        $bug = $this->label('bug');
        [$a, $b] = [$this->item(), $this->item()];
        $b->labels()->attach($bug->id);

        $this->bulk([$a, $b], ['add_label_ids' => [$ui->id], 'remove_label_ids' => [$bug->id]])->assertSessionHasNoErrors();

        $this->assertSame([$ui->id], $a->labels()->pluck('labels.id')->all());
        $this->assertSame([$ui->id], $b->labels()->pluck('labels.id')->all());
        $entry = $b->activities()->where('action', 'labels_changed')->firstOrFail();
        $this->assertSame(['labels' => ['added' => ['ui'], 'removed' => ['bug']]], $entry->changes);

        // adding a label an issue already has changes nothing
        $this->bulk([$a], ['add_label_ids' => [$ui->id]])->assertSessionHas('success', 'Nothing to change.');
        $this->assertSame(1, $a->activities()->where('action', 'labels_changed')->count());
    }

    public function test_labels_of_another_project_are_rejected(): void
    {
        $foreign = $this->label('x', Project::factory()->create(['workspace_id' => $this->workspace->id]));
        $item = $this->item();

        $this->bulk([$item], ['add_label_ids' => [$foreign->id]])->assertSessionHasErrors('changes.add_label_ids.0');
        $this->assertSame(0, $item->labels()->count());
    }

    public function test_sprint_moves_issues_and_their_subtasks_and_backlog_clears_it(): void
    {
        $this->board->update(['type' => 'scrum']);
        $sprint = Sprint::factory()->create(['board_id' => $this->board->id]);
        $parent = $this->item();
        $child = $this->item(['parent_id' => $parent->id]);
        $lone = $this->item();

        $this->bulk([$parent, $child, $lone], ['sprint_id' => $sprint->id])
            ->assertSessionHas('success', 'Updated 2 issues. 1 subtask follow their parent’s sprint.');

        $this->assertSame($sprint->id, $parent->fresh()->sprint_id);
        $this->assertSame($sprint->id, $child->fresh()->sprint_id);
        $this->assertSame($sprint->id, $lone->fresh()->sprint_id);

        $this->bulk([$parent, $lone], ['sprint_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($parent->fresh()->sprint_id);
        $this->assertNull($child->fresh()->sprint_id);
    }

    public function test_sprint_is_refused_on_a_kanban_board_and_for_another_boards_sprint(): void
    {
        $item = $this->item();
        $other = Board::factory()->create(['project_id' => $this->project->id, 'type' => 'scrum']);
        $foreignSprint = Sprint::factory()->create(['board_id' => $other->id]);

        $this->bulk([$item], ['sprint_id' => null])->assertSessionHasErrors('changes.sprint_id');

        $this->board->update(['type' => 'scrum']);
        $this->bulk([$item], ['sprint_id' => $foreignSprint->id])->assertSessionHasErrors('changes.sprint_id');
    }

    public function test_ids_of_another_board_are_rejected_and_nothing_changes(): void
    {
        $mine = $this->item();
        $other = Board::factory()->create(['project_id' => $this->project->id]);
        $foreign = Item::factory()->create(['board_id' => $other->id, 'created_by' => $this->editor->id]);

        $this->bulk([$mine, $foreign], ['column_id' => $this->column('done')])->assertSessionHasErrors('item_ids.1');

        $this->assertNotSame($this->column('done'), $mine->fresh()->column_id);
    }

    public function test_a_column_assignee_or_type_from_elsewhere_is_rejected(): void
    {
        $item = $this->item();
        $otherBoard = Board::factory()->create(['project_id' => $this->project->id]);
        $foreignType = ItemType::create(['project_id' => Project::factory()->create()->id, 'name' => 'Foreign']);

        $this->bulk([$item], ['column_id' => $otherBoard->columns()->value('id')])->assertSessionHasErrors('changes.column_id');
        $this->bulk([$item], ['assigned_to' => $this->outsider->id])->assertSessionHasErrors('changes.assigned_to');
        $this->bulk([$item], ['item_type_id' => $foreignType->id])->assertSessionHasErrors('changes.item_type_id');
        $this->bulk([$item], ['title' => 'nope'])->assertSessionHasErrors('changes');
        $this->bulk([$item], [])->assertSessionHasErrors('changes');
        $this->bulk([$item], ['add_label_ids' => []])->assertSessionHasErrors('changes');
    }

    public function test_the_subtask_type_needs_a_parent_on_every_issue(): void
    {
        // the global "Subtask" type comes with the migrations
        $subtask = ItemType::whereNull('project_id')->where('slug', 'subtask')->first()
            ?? ItemType::create(['name' => 'Subtask', 'slug' => 'subtask', 'is_active' => true]);
        $parent = $this->item();
        $child = $this->item(['parent_id' => $parent->id]);

        $this->bulk([$parent, $child], ['item_type_id' => $subtask->id])->assertSessionHasErrors('changes.item_type_id');
        $this->bulk([$child], ['item_type_id' => $subtask->id])->assertSessionHasNoErrors();
        $this->assertSame($subtask->id, $child->fresh()->item_type_id);
    }

    public function test_at_most_200_issues(): void
    {
        $item = $this->item();
        $ids = array_merge([$item->id], range(1_000_000, 1_000_199));

        $this->actingAs($this->editor)
            ->patch($this->bulkUrl(), ['item_ids' => $ids, 'changes' => ['due_date' => null]])
            ->assertSessionHasErrors('item_ids');
    }

    public function test_viewer_and_outsider_get_403(): void
    {
        $item = $this->item();

        $this->bulk([$item], ['column_id' => $this->column('done')], $this->viewer)->assertForbidden();
        $this->bulk([$item], ['column_id' => $this->column('done')], $this->outsider)->assertForbidden();
        $this->assertNotSame($this->column('done'), $item->fresh()->column_id);

        $this->actingAs($this->viewer)
            ->delete(route('boards.items.bulk.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['item_ids' => [$item->id]])
            ->assertForbidden();
        $this->assertNotNull($item->fresh());
    }

    public function test_delete_removes_the_issues_with_their_subtasks(): void
    {
        $parent = $this->item();
        $child = $this->item(['parent_id' => $parent->id]);
        $keep = $this->item();

        $this->actingAs($this->editor)
            ->delete(route('boards.items.bulk.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['item_ids' => [$parent->id]])
            ->assertRedirect()
            ->assertSessionHas('success', 'Deleted 2 issues.');

        $this->assertSoftDeleted($parent);
        $this->assertSoftDeleted($child);
        $this->assertNotSoftDeleted($keep);
    }

    public function test_delete_needs_delete_rights_on_every_issue(): void
    {
        $own = $this->item();
        $someoneElses = $this->item(['created_by' => $this->viewer->id]);

        // an editor may delete only what they created (ItemPolicy::delete)
        $this->actingAs($this->editor)
            ->delete(route('boards.items.bulk.destroy', [$this->workspace->slug, $this->project->slug, $this->board->slug]), ['item_ids' => [$own->id, $someoneElses->id]])
            ->assertForbidden();

        $this->assertNotSoftDeleted($own);
    }
}
